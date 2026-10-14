<?php
namespace GemCloudStorage\Items;

use GemCloudStorage\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the items table. A per-request cache keeps a gallery of
 * 50 images to one query per attachment.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, cached per request below.
 */
final class ItemRepository {

	/** @var array<string, Item[]> source_type:source_id => items */
	private static array $cache = [];

	public static function table(): string {
		return Installer::table();
	}

	public static function find( int $id ): ?Item {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		return $row ? Item::from_row( $row ) : null;
	}

	/**
	 * Every variant of one source, keyed by variant.
	 *
	 * @return array<string, Item>
	 */
	public static function for_source( string $source_type, int $source_id, bool $fresh = false ): array {
		$cache_key = $source_type . ':' . $source_id;

		if ( $fresh || ! isset( self::$cache[ $cache_key ] ) ) {
			global $wpdb;
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE source_type = %s AND source_id = %d', $source_type, $source_id ) );
			$items = [];
			foreach ( (array) $rows as $row ) {
				$item                    = Item::from_row( $row );
				$items[ $item->variant ] = $item;
			}
			self::$cache[ $cache_key ] = $items;
		}

		return self::$cache[ $cache_key ];
	}

	/**
	 * The live row (not one waiting for deletion) stored at this key.
	 */
	public static function find_by_key( string $object_key ): ?Item {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE object_key = %s AND source_type NOT LIKE '~%%' ORDER BY id DESC LIMIT 1", $object_key ) );
		return $row ? Item::from_row( $row ) : null;
	}

	public static function find_variant( string $source_type, int $source_id, string $variant = 'original' ): ?Item {
		$items = self::for_source( $source_type, $source_id );
		return $items[ $variant ] ?? null;
	}

	/**
	 * Load many sources in one query, for list screens and galleries.
	 *
	 * @param int[] $source_ids
	 */
	public static function prime( string $source_type, array $source_ids ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $source_ids ), function ( $id ) use ( $source_type ) {
			return $id > 0 && ! isset( self::$cache[ $source_type . ':' . $id ] );
		} ) );
		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			self::$cache[ $source_type . ':' . $id ] = [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE source_type = %s AND source_id IN ({$placeholders})", array_merge( [ $source_type ], $ids ) ) );
		foreach ( (array) $rows as $row ) {
			$item = Item::from_row( $row );
			self::$cache[ $source_type . ':' . $item->source_id ][ $item->variant ] = $item;
		}
	}

	/**
	 * Insert or update by (source_type, source_id, variant). Returns the item.
	 */
	public static function upsert( array $data ): Item {
		global $wpdb;

		$existing = self::find_variant( $data['source_type'], (int) $data['source_id'], $data['variant'] ?? 'original' );
		$now      = current_time( 'mysql', true );
		$row      = self::to_row( $data );

		if ( $existing ) {
			$row['updated_at'] = $now;
			$wpdb->update( self::table(), $row, [ 'id' => $existing->id ] );
			$id = $existing->id;
		} else {
			$row['created_at'] = $now;
			$row['updated_at'] = $now;
			$wpdb->insert( self::table(), $row );
			$id = (int) $wpdb->insert_id;
		}

		self::forget( $data['source_type'], (int) $data['source_id'] );

		return self::find( $id );
	}

	public static function update( int $id, array $fields ): void {
		global $wpdb;
		$row               = self::to_row( $fields );
		$row['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $row, [ 'id' => $id ] );
		self::$cache = [];
	}

	/**
	 * Atomically take a row for work. Two workers can't upload the same item.
	 *
	 * @param string[] $from Statuses the row may be in.
	 */
	public static function claim( int $id, array $from = [ Item::PENDING, Item::FAILED ] ): bool {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $from ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = %s, updated_at = %s WHERE id = %d AND status IN ({$placeholders})", array_merge( [ Item::UPLOADING, current_time( 'mysql', true ), $id ], $from ) ) );
		self::$cache = [];
		return 1 === (int) $changed;
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'id' => $id ] );
		self::$cache = [];
	}

	/**
	 * @return Item[]
	 */
	public static function by_status( string $status, int $limit = 100, int $after_id = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE status = %s AND id > %d ORDER BY id ASC LIMIT %d', $status, $after_id, $limit ) );
		return array_map( [ Item::class, 'from_row' ], (array) $rows );
	}

	/**
	 * @return Item[]
	 */
	public static function where_local_removed( int $limit = 20, int $after_id = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE local_state = 'removed' AND source_type = 'attachment' AND status = %s AND id > %d ORDER BY id ASC LIMIT %d", Item::SYNCED, $after_id, $limit ) );
		return array_map( [ Item::class, 'from_row' ], (array) $rows );
	}

	public static function set_status_for( array $ids, string $status, array $extra = [] ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$set  = array_merge( [ 'status' => $status ], self::to_row( $extra ) );
		$sets = [];
		$args = [];
		foreach ( $set as $column => $value ) {
			$sets[] = "`{$column}` = %s";
			$args[] = $value;
		}
		$sets[] = 'updated_at = %s';
		$args[] = current_time( 'mysql', true );

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
		$count       = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET ' . implode( ', ', $sets ) . " WHERE id IN ({$placeholders})", array_merge( $args, $ids ) ) );
		self::$cache = [];
		return $count;
	}

	/**
	 * Counts per status plus totals, for the dashboard.
	 */
	public static function stats(): array {
		global $wpdb;
		$table = self::table();

		$by_status = [];
		foreach ( Item::STATUSES as $status ) {
			$by_status[ $status ] = 0;
		}
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status" ) as $row ) {
			$by_status[ $row->status ] = (int) $row->c;
		}

		$totals = $wpdb->get_row( "SELECT COUNT(*) AS objects, COALESCE(SUM(CASE WHEN status = 'synced' THEN size_bytes END), 0) AS bytes, SUM(local_state = 'removed') AS removed, SUM(visibility = 'private') AS private_objects FROM {$table}" );

		$oldest_pending = $wpdb->get_var( "SELECT MIN(updated_at) FROM {$table} WHERE status IN ('pending','uploading')" );

		$by_context = [];
		foreach ( (array) $wpdb->get_results( "SELECT source_type, COUNT(*) AS c, COALESCE(SUM(CASE WHEN status = 'synced' THEN size_bytes END), 0) AS bytes FROM {$table} GROUP BY source_type" ) as $row ) {
			$by_context[] = [
				'source_type' => $row->source_type,
				'objects'     => (int) $row->c,
				'bytes'       => (int) $row->bytes,
			];
		}

		return [
			'by_status'      => $by_status,
			'objects'        => (int) ( $totals->objects ?? 0 ),
			'bytes'          => (int) ( $totals->bytes ?? 0 ),
			'local_removed'  => (int) ( $totals->removed ?? 0 ),
			'private'        => (int) ( $totals->private_objects ?? 0 ),
			'oldest_pending' => $oldest_pending ? $oldest_pending . 'Z' : null,
			'by_context'     => $by_context,
		];
	}

	/**
	 * Paged list for the Files screen.
	 *
	 * @return array{items:array,total:int}
	 */
	public static function paginate( array $args ): array {
		global $wpdb;
		$table = self::table();

		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['status'] ) && in_array( $args['status'], Item::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['visibility'] ) && in_array( $args['visibility'], [ 'public', 'private' ], true ) ) {
			$where[]  = 'visibility = %s';
			$params[] = $args['visibility'];
		}
		if ( ! empty( $args['source_type'] ) ) {
			$where[]  = 'source_type = %s';
			$params[] = $args['source_type'];
		}
		// A product: files stored under its own contexts, and media library
		// files it made private.
		if ( ! empty( $args['contexts'] ) || ! empty( $args['private_by'] ) ) {
			$any = [];
			foreach ( (array) ( $args['contexts'] ?? [] ) as $context ) {
				$any[]    = 'source_type = %s';
				$params[] = (string) $context;
			}
			if ( ! empty( $args['private_by'] ) ) {
				$any[]    = "( source_type = 'attachment' AND source_id IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_gemcloudstorage_private_by' AND meta_value LIKE %s ) )";
				$params[] = '%' . $wpdb->esc_like( '"' . $args['private_by'] . '"' ) . '%';
			}
			$where[] = '( ' . implode( ' OR ', $any ) . ' )';
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'object_key LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$sql_where = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$list_sql  = "SELECT * FROM {$table} WHERE {$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sql_where only holds placeholders.
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, [ $per_page, $offset ] ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return [
			'items' => array_map( [ Item::class, 'from_row' ], (array) $rows ),
			'total' => $total,
		];
	}

	public static function ids_with_status( string $status, int $limit = 500, int $after_id = 0 ): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE status = %s AND id > %d ORDER BY id ASC LIMIT %d', $status, $after_id, $limit ) ) );
	}

	public static function forget( string $source_type, int $source_id ): void {
		unset( self::$cache[ $source_type . ':' . $source_id ] );
	}

	private static function to_row( array $data ): array {
		$columns = [ 'source_type', 'source_id', 'variant', 'provider', 'bucket', 'object_key', 'visibility', 'mime_type', 'size_bytes', 'etag', 'status', 'local_state', 'local_path', 'attempts', 'last_error', 'meta' ];
		$row     = [];
		foreach ( $columns as $column ) {
			if ( ! array_key_exists( $column, $data ) ) {
				continue;
			}
			$value = $data[ $column ];
			if ( 'meta' === $column ) {
				$value = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
			}
			$row[ $column ] = $value;
		}
		return $row;
	}
}

<?php
namespace GemCloud\Jobs;

use GemCloud\Http\StorageException;
use GemCloud\Items\Item;
use GemCloud\Items\ItemRepository;
use GemCloud\Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes rows marked "deleting" from their buckets: one DeleteObjects call
 * per 1,000 keys, then the rows go.
 */
final class DeleteJob {

	const BATCH = 1000;

	public static function run(): void {
		if ( Queue::is_paused() || ! Manager::available() ) {
			return;
		}

		$items = ItemRepository::by_status( Item::DELETING, self::BATCH );
		if ( empty( $items ) ) {
			return;
		}

		$by_bucket = [];
		foreach ( $items as $item ) {
			// Another live row now points at the same object: keep the object.
			if ( self::key_in_use( $item ) ) {
				ItemRepository::delete( $item->id );
				continue;
			}
			$by_bucket[ $item->bucket ][ $item->object_key ][] = $item;
		}

		foreach ( $by_bucket as $bucket => $keys ) {
			try {
				$result = Manager::driver()->delete_many( $bucket, array_keys( $keys ) );
			} catch ( StorageException $e ) {
				foreach ( $keys as $group ) {
					foreach ( $group as $item ) {
						ItemRepository::update( $item->id, [
							'attempts'   => min( 255, $item->attempts + 1 ),
							'last_error' => $e->error_code() . ': ' . $e->getMessage(),
						] );
					}
				}
				if ( StorageException::KIND_CONFIGURATION === $e->kind() ) {
					Queue::record_config_error( $e->getMessage() );
				}
				Queue::enqueue( Queue::DELETE, [], 300 );
				return;
			}

			foreach ( $result['deleted'] as $key ) {
				foreach ( $keys[ $key ] as $item ) {
					ItemRepository::delete( $item->id );
				}
			}
			foreach ( $result['errors'] as $key => $error ) {
				foreach ( $keys[ $key ] ?? [] as $item ) {
					if ( $item->attempts + 1 >= Queue::MAX_ATTEMPTS ) {
						// Give up on the row; the object stays in the bucket.
						ItemRepository::delete( $item->id );
						continue;
					}
					ItemRepository::update( $item->id, [
						'attempts'   => min( 255, $item->attempts + 1 ),
						'last_error' => $error,
					] );
					$retry = true;
				}
			}
		}

		if ( ! empty( $retry ) ) {
			Queue::enqueue( Queue::DELETE, [], 300 );
		} elseif ( count( $items ) >= self::BATCH ) {
			Queue::enqueue( Queue::DELETE );
		}
	}

	private static function key_in_use( Item $item ): bool {
		global $wpdb;
		$table = ItemRepository::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE bucket = %s AND object_key = %s AND status <> %s AND id <> %d LIMIT 1", $table, $item->bucket, $item->object_key, Item::DELETING, $item->id ) );
	}
}

<?php
namespace GemCloudStorage\Jobs;

use GemCloudStorage\Http\StorageException;
use GemCloudStorage\Items\Item;
use GemCloudStorage\Items\ItemRepository;
use GemCloudStorage\Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Bring files back": downloads every removed local copy to disk again, for
 * a site leaving the cloud or switching Offload mode off.
 */
final class DownloadBackJob {

	const OPTION = 'gemcloudstorage_download_back';
	const BATCH  = 20;

	public static function state(): array {
		$state = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $state ) ? $state : [], [
			'status'      => 'idle',
			'cursor'      => 0,
			'processed'   => 0,
			'failed'      => 0,
			'total'       => 0,
			'started_at'  => null,
			'finished_at' => null,
		] );
	}

	public static function start(): array {
		global $wpdb;
		$table = ItemRepository::table();

		$state = [
			'status'      => 'running',
			'cursor'      => 0,
			'processed'   => 0,
			'failed'      => 0,
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table count.
			'total'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE local_state = 'removed' AND source_type = 'attachment' AND status = 'synced'" ),
			'started_at'  => gmdate( 'c' ),
			'finished_at' => null,
		];
		update_option( self::OPTION, $state, false );

		Queue::enqueue( Queue::DOWNLOAD_BACK );
		return $state;
	}

	public static function stop(): array {
		$state           = self::state();
		$state['status'] = 'stopped';
		update_option( self::OPTION, $state, false );
		return $state;
	}

	public static function run(): void {
		$state = self::state();
		if ( 'running' !== $state['status'] || ! Manager::available() ) {
			return;
		}

		$items = ItemRepository::where_local_removed( self::BATCH, (int) $state['cursor'] );

		foreach ( $items as $item ) {
			$state['cursor'] = $item->id;
			if ( self::restore( $item ) ) {
				$state['processed']++;
			} else {
				$state['failed']++;
			}
		}

		if ( count( $items ) < self::BATCH ) {
			$state['status']      = 'done';
			$state['finished_at'] = gmdate( 'c' );
		}

		update_option( self::OPTION, $state, false );

		if ( 'running' === $state['status'] ) {
			Queue::enqueue( Queue::DOWNLOAD_BACK );
		}
	}

	/**
	 * Download one object back to its local path.
	 */
	public static function restore( Item $item, bool $temporary = false ): bool {
		if ( '' === $item->local_path ) {
			return false;
		}

		if ( ! file_exists( $item->local_path ) ) {
			try {
				Manager::driver()->get( $item->bucket, $item->object_key, $item->local_path );
			} catch ( StorageException $e ) {
				ItemRepository::update( $item->id, [ 'last_error' => $e->error_code() . ': ' . $e->getMessage() ] );
				return false;
			}
		}

		$meta = $item->meta;
		if ( $temporary ) {
			// Brought back on demand; the daily cleanup removes it again.
			$meta['restored_at'] = time();
		} else {
			unset( $meta['restored_at'] );
		}

		ItemRepository::update( $item->id, [
			'local_state' => 'present',
			'meta'        => $meta,
		] );

		return true;
	}
}

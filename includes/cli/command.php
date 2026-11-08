<?php
namespace GemCloud\Cli;

use GemCloud\Http\StorageException;
use GemCloud\Items\Item;
use GemCloud\Items\ItemRepository;
use GemCloud\Jobs\DownloadBackJob;
use GemCloud\Jobs\Queue;
use GemCloud\Jobs\UploadJob;
use GemCloud\Manager;
use GemCloud\Media\MediaHooks;
use GemCloud\Settings;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage cloud storage from the command line, for sites where cron is unreliable.
 */
final class Command {

	/**
	 * Show connection and queue status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gemcloud status
	 */
	public function status(): void {
		$stats = ItemRepository::stats();
		$queue = Queue::state();

		WP_CLI::log( sprintf( 'Provider:   %s', Settings::value( 'provider' ) ) );
		WP_CLI::log( sprintf( 'Available:  %s', Manager::available() ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( 'Queue:      %s', $queue['paused'] ? 'paused (' . $queue['reason'] . ') ' . $queue['message'] : 'running' ) );
		WP_CLI::log( sprintf( 'Objects:    %d (%s synced)', $stats['objects'], size_format( $stats['bytes'], 1 ) ) );
		foreach ( $stats['by_status'] as $status => $count ) {
			WP_CLI::log( sprintf( '  %-10s %d', $status, $count ) );
		}
	}

	/**
	 * Test the saved connection: put, head and delete a probe object.
	 */
	public function test(): void {
		foreach ( [ 'public', 'private' ] as $role ) {
			$bucket = Settings::value( "buckets.{$role}.name", '' );
			if ( ! $bucket ) {
				continue;
			}
			$report = Manager::driver()->test( $bucket );
			if ( $report['ok'] ) {
				WP_CLI::success( sprintf( '%s bucket "%s" works.', ucfirst( $role ), $bucket ) );
			} else {
				WP_CLI::warning( sprintf( '%s bucket "%s": %s %s', ucfirst( $role ), $bucket, $report['error']['message'] ?? '', $report['error']['hint'] ?? '' ) );
			}
		}
	}

	/**
	 * Offload existing media library files.
	 *
	 * ## OPTIONS
	 *
	 * [--sync]
	 * : Upload inside this command instead of queueing jobs.
	 *
	 * [--limit=<number>]
	 * : Stop after this many attachments.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gemcloud migrate --sync
	 */
	public function migrate( array $args, array $assoc ): void {
		$this->require_available();

		$sync  = ! empty( $assoc['sync'] );
		$limit = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 0;

		global $wpdb;
		$cursor = 0;
		$done   = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cursor walk.
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT 100", $cursor ) ) );
			foreach ( $ids as $id ) {
				MediaHooks::sync_attachment( $id );
				if ( $sync ) {
					foreach ( ItemRepository::for_source( 'attachment', $id, true ) as $item ) {
						if ( Item::PENDING === $item->status ) {
							UploadJob::run( $item->id );
						}
					}
				}
				$cursor = $id;
				$done++;
				if ( $limit && $done >= $limit ) {
					break 2;
				}
			}
			WP_CLI::log( sprintf( '%d attachments processed…', $done ) );
		} while ( count( $ids ) === 100 );

		WP_CLI::success( sprintf( '%d attachments %s.', $done, $sync ? 'uploaded' : 'queued' ) );
	}

	/**
	 * Run waiting uploads now, without waiting for cron.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum uploads to run. Default 500.
	 */
	public function run( array $args, array $assoc ): void {
		$this->require_available();
		$limit = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 500;
		$ok    = 0;

		foreach ( ItemRepository::ids_with_status( Item::PENDING, $limit ) as $id ) {
			UploadJob::run( $id );
			$item = ItemRepository::find( $id );
			if ( $item && $item->is_synced() ) {
				$ok++;
			} elseif ( $item ) {
				WP_CLI::warning( sprintf( '#%d %s: %s', $id, $item->object_key, $item->last_error ) );
			}
		}

		WP_CLI::success( sprintf( '%d uploads finished.', $ok ) );
	}

	/**
	 * Retry failed uploads.
	 *
	 * ## OPTIONS
	 *
	 * [--failed]
	 * : Retry every failed upload (the default).
	 */
	public function retry(): void {
		$this->require_available();
		Queue::resume();
		Queue::enqueue( Queue::REQUEUE, [ 'failed', 0 ] );
		WP_CLI::success( 'Failed uploads queued again.' );
	}

	/**
	 * Check that every synced object is in the bucket with the right size.
	 *
	 * ## OPTIONS
	 *
	 * [--fix]
	 * : Queue missing or wrong-sized objects for upload again.
	 */
	public function verify( array $args, array $assoc ): void {
		$this->require_available();

		$after   = 0;
		$checked = 0;
		$bad     = 0;

		do {
			$items = ItemRepository::by_status( Item::SYNCED, 200, $after );
			foreach ( $items as $item ) {
				$after = $item->id;
				$checked++;
				try {
					$head = Manager::driver()->head( $item->bucket, $item->object_key );
				} catch ( StorageException $e ) {
					WP_CLI::warning( sprintf( '#%d %s: %s', $item->id, $item->object_key, $e->getMessage() ) );
					continue;
				}
				if ( ! $head || ( $item->size_bytes && $head->size !== $item->size_bytes ) ) {
					$bad++;
					WP_CLI::warning( sprintf( '#%d %s is %s.', $item->id, $item->object_key, $head ? 'the wrong size' : 'missing' ) );
					if ( ! empty( $assoc['fix'] ) && 'present' === $item->local_state ) {
						ItemRepository::update( $item->id, [ 'status' => Item::PENDING ] );
						Queue::enqueue( Queue::UPLOAD, [ $item->id ] );
					}
				}
			}
		} while ( count( $items ) === 200 );

		$bad ? WP_CLI::warning( sprintf( '%d of %d objects need attention.', $bad, $checked ) ) : WP_CLI::success( sprintf( 'All %d objects verified.', $checked ) );
	}

	/**
	 * Download every removed local file back to this server.
	 */
	public function download_back(): void {
		$this->require_available();
		$after = 0;
		$ok    = 0;
		$fail  = 0;
		do {
			$items = ItemRepository::where_local_removed( 50, $after );
			foreach ( $items as $item ) {
				$after = $item->id;
				DownloadBackJob::restore( $item ) ? $ok++ : $fail++;
			}
		} while ( count( $items ) === 50 );

		WP_CLI::success( sprintf( '%d files restored, %d failed.', $ok, $fail ) );
	}

	/**
	 * Make every file Academy LMS and StoreEngine use private, now.
	 *
	 * Uploads still run in the queue; follow with `wp gemcloud run`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gemcloud reconcile
	 */
	public function reconcile(): void {
		$this->require_available();

		$active = \GemCloud\Integrations\Integrations::switched_on();
		if ( ! $active ) {
			WP_CLI::warning( 'No product integration is switched on.' );
			return;
		}

		$offset = 0;
		do {
			$checked = \GemCloud\Jobs\ReconcileJob::run( $offset );
			$offset += $checked;
		} while ( \GemCloud\Jobs\ReconcileJob::BATCH === $checked );

		// The last batch queued nothing, but an earlier one may have.
		as_unschedule_all_actions( Queue::RECONCILE, null, Queue::GROUP );

		WP_CLI::success( sprintf( '%d product files checked (%s).', $offset, implode( ', ', array_map( function ( $i ) {
			return $i->label();
		}, $active ) ) ) );
	}

	private function require_available(): void {
		if ( ! Manager::available() ) {
			WP_CLI::error( 'Cloud storage is not configured or switched off.' );
		}
	}
}

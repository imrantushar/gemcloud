<?php
namespace GemCloudStorage\Jobs;

use GemCloudStorage\Items\Item;
use GemCloudStorage\Items\ItemRepository;
use GemCloudStorage\Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily: abort multipart uploads older than 24 hours, unstick rows a dead
 * worker left in "uploading", and remove files that were brought back on
 * demand when Offload mode says they shouldn't be local.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
 */
final class CleanupJob {

	public static function run(): void {
		if ( ! Manager::available() ) {
			return;
		}

		global $wpdb;
		$table = ItemRepository::table();

		// Rows stuck in "uploading" for over an hour: the worker died.
		$stuck = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s AND updated_at < %s LIMIT 500", Item::UPLOADING, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		foreach ( array_map( 'intval', (array) $stuck ) as $id ) {
			$item = ItemRepository::find( $id );
			if ( ! $item ) {
				continue;
			}
			$started = (int) ( $item->meta['multipart_started'] ?? 0 );
			if ( $started && $started < time() - DAY_IN_SECONDS ) {
				self::abort_multipart( $item );
			}
			ItemRepository::update( $id, [ 'status' => Item::PENDING ] );
			Queue::enqueue( Queue::UPLOAD, [ $id ] );
		}

		// Multipart uploads abandoned by failed rows.
		$abandoned = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s AND meta LIKE %s LIMIT 500", Item::FAILED, '%"upload_id"%' ) );
		foreach ( array_map( 'intval', (array) $abandoned ) as $id ) {
			$item = ItemRepository::find( $id );
			if ( $item ) {
				self::abort_multipart( $item );
			}
		}

		// Files downloaded on demand more than a day ago. RemoveLocalJob
		// decides per attachment (private ones always go).
		$restored      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_type = 'attachment' AND status = %s AND local_state = 'present' AND meta LIKE %s LIMIT 500", Item::SYNCED, '%"restored_at"%' ) );
		$by_attachment = [];
		foreach ( (array) $restored as $row ) {
			$item = Item::from_row( $row );
			if ( (int) ( $item->meta['restored_at'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
				$by_attachment[ $item->source_id ] = true;
			}
		}
		foreach ( array_keys( $by_attachment ) as $attachment_id ) {
			RemoveLocalJob::run( $attachment_id );
		}

		// Product files saved in ways no hook saw (imports, direct edits).
		\GemCloudStorage\Integrations\Integrations::schedule_reconcile();

		// Deletes that are still waiting.
		if ( ItemRepository::ids_with_status( Item::DELETING, 1 ) ) {
			Queue::enqueue( Queue::DELETE );
		}
	}

	private static function abort_multipart( Item $item ): void {
		$upload_id = $item->meta['upload_id'] ?? '';
		if ( ! $upload_id ) {
			return;
		}
		try {
			Manager::driver()->multipart_abort( $item->bucket, $item->object_key, $upload_id );
		} catch ( \Throwable $e ) {
			return;
		}
		$meta = $item->meta;
		unset( $meta['upload_id'], $meta['parts'], $meta['part_size'], $meta['multipart_started'] );
		ItemRepository::update( $item->id, [ 'meta' => $meta ] );
	}
}

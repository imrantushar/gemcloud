<?php
namespace GemCloudStorage\Jobs;

use GemCloudStorage\Items\Item;
use GemCloudStorage\Items\ItemRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts waiting rows back in the queue, 500 at a time: after a pause is
 * cleared ("pending") or for "Retry all" ("failed").
 */
final class RequeueJob {

	const BATCH = 500;

	public static function run( $which = 'pending', $after_id = 0 ): void {
		if ( Queue::is_paused() ) {
			return;
		}

		$status   = 'failed' === $which ? Item::FAILED : Item::PENDING;
		$after_id = (int) $after_id;
		$ids      = ItemRepository::ids_with_status( $status, self::BATCH, $after_id );

		if ( Item::FAILED === $status && $ids ) {
			ItemRepository::set_status_for( $ids, Item::PENDING, [
				'attempts'   => 0,
				'last_error' => '',
			] );
		}

		foreach ( $ids as $id ) {
			Queue::enqueue( Queue::UPLOAD, [ $id ] );
		}

		if ( count( $ids ) >= self::BATCH ) {
			Queue::enqueue( Queue::REQUEUE, [ $which, (int) end( $ids ) ], 5 );
		}
	}
}

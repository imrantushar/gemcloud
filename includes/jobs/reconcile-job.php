<?php
namespace GemCloudStorage\Jobs;

use GemCloudStorage\Integrations\Integrations;
use GemCloudStorage\Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brings every product file into line, in batches: each attachment a
 * product uses is made private, and files products keep outside the media
 * library are copied up. Runs daily, after the settings change, and from
 * `wp gemcloudstorage reconcile`. Hooks on saving catch most changes at
 * once; this catches the rest (imports, direct database edits).
 */
final class ReconcileJob {

	const BATCH = 50;

	/**
	 * @return int Attachments checked in this run.
	 */
	public static function run( $offset = 0 ): int {
		$offset = (int) $offset;
		if ( ! Manager::available() ) {
			return 0;
		}

		$active = Integrations::active();
		$ids    = [];
		foreach ( $active as $integration ) {
			$ids = array_merge( $ids, $integration->all_attachment_ids() );
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );

		$batch = array_slice( $ids, $offset, self::BATCH );
		foreach ( $active as $integration ) {
			$integration->protect( $batch );
		}

		if ( count( $ids ) > $offset + self::BATCH ) {
			Queue::enqueue( Queue::RECONCILE, [ $offset + self::BATCH ] );
		} else {
			foreach ( $active as $integration ) {
				$integration->reconcile_own_files();
			}
		}

		return count( $batch );
	}
}

<?php
namespace GemCloud\Jobs;

use GemCloud\Manager;
use GemCloud\Media\MediaHooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Offload existing media": walks the media library by ID, 50 attachments a
 * run, queues their uploads and queues the next batch. Resumable: the cursor
 * is stored, so a stopped run continues where it left off.
 */
final class MigrateJob {

	const OPTION = 'gemcloud_migration';
	const BATCH  = 50;

	public static function state(): array {
		$state = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $state ) ? $state : [], [
			'status'      => 'idle', // idle | running | stopped | done
			'cursor'      => 0,
			'total'       => 0,
			'processed'   => 0,
			'started_at'  => null,
			'finished_at' => null,
		] );
	}

	public static function start( bool $restart = false ): array {
		$state = self::state();

		if ( $restart || 'done' === $state['status'] || 'idle' === $state['status'] ) {
			$state['cursor']    = 0;
			$state['processed'] = 0;
		}

		$state['status']      = 'running';
		$state['total']       = self::count_attachments();
		$state['started_at']  = gmdate( 'c' );
		$state['finished_at'] = null;
		update_option( self::OPTION, $state, false );

		Queue::enqueue( Queue::MIGRATE );
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

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cursor walk over attachments.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
			(int) $state['cursor'],
			self::BATCH
		) ) );

		foreach ( $ids as $id ) {
			MediaHooks::sync_attachment( $id );
			$state['cursor'] = $id;
			$state['processed']++;
		}

		if ( count( $ids ) < self::BATCH ) {
			$state['status']      = 'done';
			$state['finished_at'] = gmdate( 'c' );
		}

		update_option( self::OPTION, $state, false );

		if ( 'running' === $state['status'] ) {
			Queue::enqueue( Queue::MIGRATE );
		}
	}

	public static function count_attachments(): int {
		$counts = wp_count_attachments();
		$total  = 0;
		foreach ( (array) $counts as $status => $count ) {
			if ( 'trash' !== $status ) {
				$total += (int) $count;
			}
		}
		return $total;
	}
}

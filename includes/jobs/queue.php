<?php
namespace GemCloud\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every cloud operation runs as an Action Scheduler job in the
 * "gemcloud" group; no admin request waits on S3 or R2. Falls back to
 * WP-Cron if Action Scheduler is somehow missing.
 */
final class Queue {

	const GROUP = 'gemcloud';

	const UPLOAD          = 'gemcloud/upload';
	const REMOVE_LOCAL    = 'gemcloud/remove_local';
	const DELETE          = 'gemcloud/delete';
	const MIGRATE         = 'gemcloud/migrate_batch';
	const DOWNLOAD_BACK   = 'gemcloud/download_back_batch';
	const REQUEUE         = 'gemcloud/requeue';
	const CLEANUP         = 'gemcloud/cleanup';
	const RECONCILE       = 'gemcloud/reconcile';
	const BACKUP          = 'gemcloud/backup';

	const STATE_OPTION = 'gemcloud_queue_state';

	/** Delays between attempts after a temporary error: 1 min, 5 min, 30 min, 2 h, 6 h. */
	const RETRY_DELAYS = [ 60, 300, 1800, 7200, 21600 ];

	const MAX_ATTEMPTS = 5;

	const CONFIG_ERRORS_BEFORE_PAUSE = 5;

	public static function init(): void {
		add_action( self::UPLOAD, [ UploadJob::class, 'run' ], 10, 2 );
		add_action( self::REMOVE_LOCAL, [ RemoveLocalJob::class, 'run' ], 10, 1 );
		add_action( self::DELETE, [ DeleteJob::class, 'run' ] );
		add_action( self::MIGRATE, [ MigrateJob::class, 'run' ] );
		add_action( self::DOWNLOAD_BACK, [ DownloadBackJob::class, 'run' ] );
		add_action( self::REQUEUE, [ RequeueJob::class, 'run' ], 10, 2 );
		add_action( self::CLEANUP, [ CleanupJob::class, 'run' ] );
		add_action( self::RECONCILE, [ ReconcileJob::class, 'run' ], 10, 1 );
		add_action( self::BACKUP, [ BackupJob::class, 'run' ] );

		add_action( 'action_scheduler_init', [ self::class, 'schedule_recurring' ] );
		add_action( 'init', [ self::class, 'schedule_recurring_fallback' ], 20 );
	}

	public static function has_action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Queue a job unless the same one is already waiting.
	 */
	public static function enqueue( string $hook, array $args = [], int $delay = 0 ): void {
		if ( self::has_action_scheduler() ) {
			if ( as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				return;
			}
			if ( $delay > 0 ) {
				as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
			} else {
				as_enqueue_async_action( $hook, $args, self::GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + max( 1, $delay ), $hook, $args );
		}
	}

	public static function schedule_recurring(): void {
		if ( self::has_action_scheduler() && ! as_has_scheduled_action( self::CLEANUP, [], self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::CLEANUP, [], self::GROUP );
		}
	}

	public static function schedule_recurring_fallback(): void {
		if ( ! self::has_action_scheduler() && ! wp_next_scheduled( self::CLEANUP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP );
		}
	}

	public static function unschedule_all(): void {
		if ( self::has_action_scheduler() ) {
			as_unschedule_all_actions( '', [], self::GROUP );
		}
		wp_clear_scheduled_hook( self::CLEANUP );
	}

	// ── Pause state ─────────────────────────────────────────────────────────

	public static function state(): array {
		$state = get_option( self::STATE_OPTION, [] );
		return wp_parse_args( is_array( $state ) ? $state : [], [
			'paused'        => false,
			'reason'        => '',
			'message'       => '',
			'config_errors' => 0,
			'paused_at'     => null,
		] );
	}

	public static function is_paused(): bool {
		return (bool) self::state()['paused'];
	}

	/**
	 * @param string $reason configuration | clock | manual
	 */
	public static function pause( string $reason, string $message = '' ): void {
		$state              = self::state();
		$state['paused']    = true;
		$state['reason']    = $reason;
		$state['message']   = $message;
		$state['paused_at'] = gmdate( 'c' );
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Clear the pause and put every waiting item back in the queue.
	 */
	public static function resume(): void {
		update_option( self::STATE_OPTION, [
			'paused'        => false,
			'reason'        => '',
			'message'       => '',
			'config_errors' => 0,
			'paused_at'     => null,
		], false );

		self::enqueue( self::REQUEUE, [ 'pending' ] );
	}

	public static function record_config_error( string $message ): void {
		$state = self::state();
		$state['config_errors']++;
		update_option( self::STATE_OPTION, $state, false );

		if ( $state['config_errors'] >= self::CONFIG_ERRORS_BEFORE_PAUSE ) {
			self::pause( 'configuration', $message );
		}
	}

	public static function record_success(): void {
		$state = self::state();
		if ( $state['config_errors'] > 0 ) {
			$state['config_errors'] = 0;
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	/**
	 * Counts of waiting and running actions in our group, for the status screen.
	 */
	public static function stats(): array {
		if ( ! self::has_action_scheduler() || ! class_exists( '\ActionScheduler_Store' ) ) {
			return [
				'engine'  => 'wp-cron',
				'pending' => null,
				'running' => null,
				'failed'  => null,
			];
		}

		// Capped at 1,000: the screen only needs to show that work is waiting.
		$count = function ( string $status ): int {
			return count( as_get_scheduled_actions( [
				'group'    => self::GROUP,
				'status'   => $status,
				'per_page' => 1000,
			], 'ids' ) );
		};

		return [
			'engine'  => 'action-scheduler',
			'pending' => $count( \ActionScheduler_Store::STATUS_PENDING ),
			'running' => $count( \ActionScheduler_Store::STATUS_RUNNING ),
			'failed'  => $count( \ActionScheduler_Store::STATUS_FAILED ),
		];
	}
}

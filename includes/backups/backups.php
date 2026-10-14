<?php
namespace GemCloudStorage\Backups;

use GemCloudStorage\Contexts;
use GemCloudStorage\Items\Item;
use GemCloudStorage\Items\ItemRepository;
use GemCloudStorage\Jobs\Queue;
use GemCloudStorage\Manager;
use GemCloudStorage\Ref;
use GemCloudStorage\Settings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database backups: a compressed SQL copy of this site's tables, written in
 * the background and kept in the private bucket only, since it holds every
 * customer, order, license key and password hash.
 *
 * One backup is one row in the items table (context "backups.database",
 * source id = the time it started), so it uses the same upload, retry and
 * multipart machinery as every other file. The local copy is deleted as
 * soon as the upload is verified.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the items table.
 */
final class Backups {

	const CONTEXT = 'backups.database';

	/** The backup being written: progress, temp file, tables. */
	const RUN_OPTION = 'gemcloudstorage_backup_run';

	/** Result of the last finished or failed backup. */
	const LAST_OPTION = 'gemcloudstorage_backup_last';

	const CHECK_HOOK = 'gemcloudstorage/backup_check';

	const FREQUENCIES = [
		'daily'  => DAY_IN_SECONDS,
		'weekly' => WEEK_IN_SECONDS,
	];

	const KEEP_DAYS = [ 7, 14, 30, 90 ];

	public static function init(): void {
		add_action( 'gemcloudstorage/loaded', [ self::class, 'register_context' ], 6 );
		add_action( 'gemcloudstorage/uploaded', [ self::class, 'on_uploaded' ] );
		add_action( 'gemcloudstorage/upload_failed', [ self::class, 'on_upload_failed' ], 10, 2 );
		add_action( self::CHECK_HOOK, [ self::class, 'maybe_start' ] );
		add_action( 'action_scheduler_init', [ self::class, 'schedule_check' ] );
	}

	public static function register_context(): void {
		Contexts::register( self::CONTEXT, [
			'label'       => __( 'Database backups', 'gemcloudstorage' ),
			'description' => __( 'Compressed copies of this site\'s database.', 'gemcloudstorage' ),
			'visibility'  => 'private',
			'path'        => 'backups/database/{Y}/{m}/{uuid}/{filename}',
			'source'      => 'GemCloudStorage',
			'locked'      => [ 'visibility' ],
			// Switched on and off from its own settings tab, not Contexts.
			'internal'    => true,
		] );
	}

	/**
	 * An hourly check; it starts a backup only when one is due.
	 */
	public static function schedule_check(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::CHECK_HOOK, [], Queue::GROUP ) ) {
			as_schedule_recurring_action( time() + 10 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, self::CHECK_HOOK, [], Queue::GROUP );
		}
	}

	// ── Settings ────────────────────────────────────────────────────────────

	public static function enabled(): bool {
		return (bool) Settings::value( 'backups.enabled', false );
	}

	/**
	 * Why a backup can't run right now, or '' when it can.
	 */
	public static function blocker(): string {
		if ( ! Manager::available() ) {
			return __( 'Connect a bucket and switch cloud storage on first.', 'gemcloudstorage' );
		}
		if ( '' === (string) Settings::value( 'buckets.private.name', '' ) ) {
			return __( 'Backups hold every customer, order and password hash, so they are only kept in a private bucket. Add one under Connection.', 'gemcloudstorage' );
		}
		if ( ! function_exists( 'gzopen' ) ) {
			return __( 'This server\'s PHP has no zlib extension, which backups need for compression.', 'gemcloudstorage' );
		}
		return '';
	}

	// ── Running ─────────────────────────────────────────────────────────────

	/**
	 * Hourly: start a scheduled backup when the last one is older than the
	 * chosen interval (an hour of slack so a daily one doesn't drift).
	 */
	public static function maybe_start(): void {
		if ( ! self::enabled() || '' !== self::blocker() || self::running() ) {
			return;
		}
		$interval = self::FREQUENCIES[ Settings::value( 'backups.frequency', 'daily' ) ] ?? DAY_IN_SECONDS;
		$last     = (int) ( get_option( self::LAST_OPTION, [] )['started_at'] ?? 0 );
		if ( time() - $last >= $interval - HOUR_IN_SECONDS ) {
			self::start( 'scheduled' );
		}
	}

	/**
	 * @return array|WP_Error The new run.
	 */
	public static function start( string $trigger = 'manual' ) {
		$blocker = self::blocker();
		if ( '' !== $blocker ) {
			return new WP_Error( 'gemcloudstorage_backup_blocked', $blocker );
		}
		if ( self::running() ) {
			return new WP_Error( 'gemcloudstorage_backup_running', __( 'A backup is already running.', 'gemcloudstorage' ) );
		}

		global $wpdb;
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ) );

		$run = [
			'id'          => time(),
			'trigger'     => $trigger,
			'status'      => 'exporting',
			'started_at'  => time(),
			'file'        => self::temp_file(),
			'tables'      => array_values( $tables ),
			'table_index' => 0,
			'offset'      => 0,
			'rows'        => 0,
		];
		update_option( self::RUN_OPTION, $run, false );

		Queue::enqueue( Queue::BACKUP );

		return $run;
	}

	public static function running(): ?array {
		$run = get_option( self::RUN_OPTION, null );
		if ( ! is_array( $run ) ) {
			return null;
		}
		// Exporting saves progress every ~20 seconds: an hour of silence
		// means the worker died.
		if ( 'exporting' === $run['status'] && time() - (int) ( $run['touched_at'] ?? $run['started_at'] ) > HOUR_IN_SECONDS ) {
			self::finish( $run, 'failed', __( 'The backup stopped responding and was abandoned.', 'gemcloudstorage' ) );
			return null;
		}

		// Uploading can wait out retries for hours; go by the stored file.
		if ( 'uploading' === $run['status'] ) {
			$item = self::find( (int) $run['id'] );
			if ( ! $item || Item::FAILED === $item->status ) {
				self::finish( $run, 'failed', $item ? (string) $item->last_error : __( 'The upload was lost.', 'gemcloudstorage' ) );
				return null;
			}
			if ( $item->is_synced() ) {
				self::finish( $run, 'ok', '', (int) $item->size_bytes );
				return null;
			}
		}
		return $run;
	}

	/**
	 * Record the result and clean up the local file.
	 */
	public static function finish( array $run, string $status, string $message = '', int $size = 0 ): void {
		if ( ! empty( $run['file'] ) && file_exists( $run['file'] ) ) {
			wp_delete_file( $run['file'] );
		}
		delete_option( self::RUN_OPTION );
		update_option( self::LAST_OPTION, [
			'id'          => (int) $run['id'],
			'status'      => $status,
			'message'     => $message,
			'size'        => $size,
			'tables'      => count( (array) ( $run['tables'] ?? [] ) ),
			'rows'        => (int) ( $run['rows'] ?? 0 ),
			'started_at'  => (int) $run['started_at'],
			'finished_at' => time(),
		], false );
	}

	public static function on_uploaded( Item $item ): void {
		if ( self::CONTEXT !== $item->source_type ) {
			return;
		}
		if ( file_exists( $item->local_path ) ) {
			wp_delete_file( $item->local_path );
		}
		ItemRepository::update( $item->id, [ 'local_state' => 'removed' ] );

		$run = get_option( self::RUN_OPTION, null );
		if ( is_array( $run ) && (int) $run['id'] === $item->source_id ) {
			self::finish( $run, 'ok', '', (int) $item->size_bytes );
		}
		self::prune();
	}

	public static function on_upload_failed( $item, $error ): void {
		if ( ! $item instanceof Item || self::CONTEXT !== $item->source_type ) {
			return;
		}
		$run = get_option( self::RUN_OPTION, null );
		if ( is_array( $run ) && (int) $run['id'] === $item->source_id ) {
			self::finish( $run, 'failed', is_object( $error ) && method_exists( $error, 'getMessage' ) ? $error->getMessage() : __( 'The upload failed.', 'gemcloudstorage' ) );
		}
		Manager::delete( Ref::of( self::CONTEXT, $item->source_id ) );
	}

	// ── Stored backups ──────────────────────────────────────────────────────

	/**
	 * @return Item[] newest first
	 */
	public static function all( int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ItemRepository::table() . ' WHERE source_type = %s ORDER BY source_id DESC LIMIT %d', self::CONTEXT, $limit ) );
		return array_map( [ Item::class, 'from_row' ], (array) $rows );
	}

	public static function find( int $id ): ?Item {
		return ItemRepository::find_variant( self::CONTEXT, $id );
	}

	/**
	 * Delete backups older than the retention period. The newest finished
	 * backup is always kept, however old, so there is never none.
	 */
	public static function prune(): void {
		$days   = (int) Settings::value( 'backups.keep_days', 30 );
		$cutoff = time() - $days * DAY_IN_SECONDS;
		$kept   = false;

		foreach ( self::all( 500 ) as $item ) {
			if ( ! $item->is_synced() ) {
				continue;
			}
			if ( ! $kept ) {
				$kept = true;
				continue;
			}
			if ( $item->source_id < $cutoff ) {
				Manager::delete( Ref::of( self::CONTEXT, $item->source_id ) );
			}
		}
	}

	/**
	 * A download name people recognise: site-db-2026-10-14-0300.sql.gz
	 */
	public static function filename( int $id ): string {
		$host = sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return ( $host ? $host : 'site' ) . '-db-' . gmdate( 'Y-m-d-Hi', $id ) . '.sql.gz';
	}

	/**
	 * The system temp folder, outside the web root. Uploads only as a last
	 * resort, in a folder the web server is told not to serve.
	 */
	private static function temp_file(): string {
		$name = 'gcs-backup-' . wp_generate_password( 24, false ) . '.sql.gz';
		$dir  = untrailingslashit( get_temp_dir() );

		if ( ! wp_is_writable( $dir ) ) {
			$dir = wp_get_upload_dir()['basedir'] . '/gemcloudstorage-tmp';
			wp_mkdir_p( $dir );
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- guard files.
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
				file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
			}
			// phpcs:enable
		}

		return $dir . '/' . $name;
	}
}

<?php
namespace GemCloud\Cli;

use GemCloud\Backups\Backups;
use GemCloud\Jobs\BackupJob;
use GemCloud\Jobs\UploadJob;
use GemCloud\Manager;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database backups kept in the private bucket.
 */
final class BackupCommand {

	/**
	 * Back up the database now.
	 *
	 * ## OPTIONS
	 *
	 * [--sync]
	 * : Export and upload inside this command instead of the background queue.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gemcloud backup create --sync
	 */
	public function create( array $args, array $assoc ): void {
		$run = Backups::start( 'cli' );
		if ( is_wp_error( $run ) ) {
			WP_CLI::error( $run->get_error_message() );
		}

		if ( empty( $assoc['sync'] ) ) {
			WP_CLI::success( sprintf( 'Backup %d queued (%d tables). Check it with "wp gemcloud backup list".', $run['id'], count( $run['tables'] ) ) );
			return;
		}

		// Export every slice, then upload, without waiting for cron.
		as_unschedule_all_actions( \GemCloud\Jobs\Queue::BACKUP, [], \GemCloud\Jobs\Queue::GROUP );
		do {
			BackupJob::run();
			as_unschedule_all_actions( \GemCloud\Jobs\Queue::BACKUP, [], \GemCloud\Jobs\Queue::GROUP );
			$current = get_option( Backups::RUN_OPTION, null );
		} while ( is_array( $current ) && 'exporting' === $current['status'] );

		$item = Backups::find( (int) $run['id'] );
		if ( $item ) {
			do {
				UploadJob::run( $item->id, 'uploading' === $item->status ? 1 : 0 );
				$item = Backups::find( (int) $run['id'] );
			} while ( $item && 'uploading' === $item->status );
		}

		$last = get_option( Backups::LAST_OPTION, [] );
		if ( 'ok' === ( $last['status'] ?? '' ) && (int) $last['id'] === (int) $run['id'] ) {
			WP_CLI::success( sprintf( 'Backup %d stored: %s, %d tables, %d rows.', $run['id'], size_format( (int) $last['size'], 1 ), (int) $last['tables'], (int) $last['rows'] ) );
		} else {
			WP_CLI::error( 'Backup failed: ' . ( $last['message'] ?? 'unknown error' ) );
		}
	}

	/**
	 * List stored backups.
	 *
	 * @subcommand list
	 */
	public function list_(): void {
		$rows = [];
		foreach ( Backups::all() as $item ) {
			$rows[] = [
				'id'      => $item->source_id,
				'created' => gmdate( 'Y-m-d H:i', $item->source_id ) . ' UTC',
				'size'    => size_format( (int) $item->size_bytes, 1 ),
				'status'  => $item->status,
				'file'    => $item->filename(),
			];
		}
		if ( ! $rows ) {
			WP_CLI::log( 'No backups yet.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'created', 'size', 'status', 'file' ] );
	}

	/**
	 * Print a signed download link for a backup, valid for 15 minutes.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup ID from "backup list".
	 */
	public function url( array $args ): void {
		$item = Backups::find( (int) $args[0] );
		if ( ! $item || ! $item->is_synced() ) {
			WP_CLI::error( 'No stored backup with that ID.' );
		}
		$url = Manager::signed_url_for( $item, [ 'ttl' => 15 * MINUTE_IN_SECONDS ] );
		is_wp_error( $url ) ? WP_CLI::error( $url->get_error_message() ) : WP_CLI::log( $url );
	}

	/**
	 * Replace this site's database tables with a backup.
	 *
	 * Every table in the backup is dropped and recreated. Anything written
	 * since the backup (orders, users, posts) is lost.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup ID from "backup list".
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public function restore( array $args, array $assoc ): void {
		global $wpdb;

		$item = Backups::find( (int) $args[0] );
		if ( ! $item || ! $item->is_synced() ) {
			WP_CLI::error( 'No stored backup with that ID.' );
		}

		WP_CLI::confirm( sprintf( 'Replace every table in the %s database with backup %d from %s UTC? Changes made since then are lost.', DB_NAME, $item->source_id, gmdate( 'Y-m-d H:i', $item->source_id ) ), $assoc );

		$file = get_temp_dir() . 'gemcloud-restore-' . wp_generate_password( 24, false ) . '.sql.gz';
		WP_CLI::log( 'Downloading ' . size_format( (int) $item->size_bytes, 1 ) . '…' );
		try {
			Manager::driver()->get( $item->bucket, $item->object_key, $file );
		} catch ( \Throwable $e ) {
			WP_CLI::error( 'Download failed: ' . $e->getMessage() );
		}

		$result = self::replay( $file, $wpdb );
		wp_delete_file( $file );
		wp_cache_flush();

		if ( null === $result ) {
			WP_CLI::error( 'Could not open the downloaded backup.' );
		}
		list( $done, $errors ) = $result;
		foreach ( array_slice( $result[2], 0, 10 ) as $message ) {
			WP_CLI::warning( $message );
		}

		$errors
			? WP_CLI::warning( sprintf( 'Restored with %d errors (%d statements ran).', $errors, $done ) )
			: WP_CLI::success( sprintf( 'Database restored from backup %d (%d statements).', $item->source_id, $done ) );
	}

	/**
	 * Run every statement of a backup file against a database. A statement
	 * is one line; lines longer than a read arrive in pieces.
	 *
	 * @return array{0:int,1:int,2:string[]}|null statements run, errors, error messages
	 */
	public static function replay( string $file, \wpdb $db ): ?array {
		$gz = gzopen( $file, 'rb' );
		if ( ! $gz ) {
			return null;
		}

		$done     = 0;
		$errors   = 0;
		$messages = [];
		$line     = '';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- replaying our own SQL dump.
		while ( ! gzeof( $gz ) ) {
			$chunk = gzgets( $gz, 16 * MB_IN_BYTES );
			if ( false === $chunk ) {
				break;
			}
			$line .= $chunk;
			if ( "\n" !== substr( $line, -1 ) && ! gzeof( $gz ) ) {
				continue;
			}
			$sql  = trim( $line );
			$line = '';
			if ( '' === $sql || 0 === strpos( $sql, '--' ) ) {
				continue;
			}
			if ( false === $db->query( $sql ) ) {
				++$errors;
				$messages[] = substr( (string) $db->last_error, 0, 200 );
			} else {
				++$done;
			}
		}
		// phpcs:enable
		gzclose( $gz );

		return [ $done, $errors, $messages ];
	}
}

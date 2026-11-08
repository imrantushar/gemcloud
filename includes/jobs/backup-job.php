<?php
namespace GemCloud\Jobs;

use GemCloud\Backups\Backups;
use GemCloud\Manager;
use GemCloud\Ref;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes the database to a gzip SQL file, a slice at a time: each run works
 * for about 20 seconds, saves which table and row it reached, and queues
 * itself again, so a large database never hits a PHP time limit. When every
 * table is written the file is handed to the normal upload queue.
 *
 * The file is plain SQL any MySQL tool can import. Every statement is on a
 * single line (values escape their newlines), which is what
 * `wp gemcloud backup restore` relies on.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names come from SHOW TABLES.
 */
final class BackupJob {

	const TIME_BUDGET   = 20;
	const ROWS_PER_READ = 500;
	const ROWS_PER_LINE = 100;

	public static function run(): void {
		$run = get_option( Backups::RUN_OPTION, null );
		if ( ! is_array( $run ) || 'exporting' !== $run['status'] ) {
			return;
		}

		global $wpdb;
		$started = microtime( true );

		$gz = gzopen( $run['file'], 'ab6' );
		if ( ! $gz ) {
			Backups::finish( $run, 'failed', __( 'Could not write the backup file to the temp folder.', 'gemcloud' ) );
			return;
		}

		try {
			if ( empty( $run['header'] ) ) {
				gzwrite( $gz, self::header( $run ) );
				$run['header'] = true;
			}

			$tables = (array) $run['tables'];
			while ( $run['table_index'] < count( $tables ) ) {
				$table = $tables[ $run['table_index'] ];

				if ( 0 === (int) $run['offset'] ) {
					$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
					if ( ! $create ) {
						throw new \RuntimeException( sprintf( 'Could not read the structure of %s: %s', $table, $wpdb->last_error ) );
					}
					gzwrite( $gz, "\n-- Table {$table}\nDROP TABLE IF EXISTS `{$table}`;\n" . str_replace( [ "\r", "\n" ], ' ', $create[1] ) . ";\n" );
				}

				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d, %d", (int) $run['offset'], self::ROWS_PER_READ ), ARRAY_N );
				if ( '' !== $wpdb->last_error ) {
					throw new \RuntimeException( sprintf( 'Could not read %s: %s', $table, $wpdb->last_error ) );
				}

				foreach ( array_chunk( (array) $rows, self::ROWS_PER_LINE ) as $chunk ) {
					gzwrite( $gz, "INSERT INTO `{$table}` VALUES " . implode( ',', array_map( [ self::class, 'row' ], $chunk ) ) . ";\n" );
				}

				$count          = count( (array) $rows );
				$run['rows']   += $count;
				$run['offset'] += $count;

				if ( $count < self::ROWS_PER_READ ) {
					++$run['table_index'];
					$run['offset'] = 0;
				}

				if ( microtime( true ) - $started > self::TIME_BUDGET && $run['table_index'] < count( $tables ) ) {
					gzclose( $gz );
					$run['touched_at'] = time();
					update_option( Backups::RUN_OPTION, $run, false );
					Queue::enqueue( Queue::BACKUP );
					return;
				}
			}

			gzwrite( $gz, "\nSET foreign_key_checks = 1;\n-- End of backup\n" );
			gzclose( $gz );
		} catch ( \Throwable $e ) {
			gzclose( $gz );
			Backups::finish( $run, 'failed', $e->getMessage() );
			return;
		}

		// Every table written: hand the file to the upload queue.
		$run['status']     = 'uploading';
		$run['touched_at'] = time();
		update_option( Backups::RUN_OPTION, $run, false );

		$item = Manager::upload( $run['file'], Ref::of( Backups::CONTEXT, (int) $run['id'] ), [
			'filename'   => Backups::filename( (int) $run['id'] ),
			'visibility' => 'private',
		] );

		if ( is_wp_error( $item ) ) {
			Backups::finish( $run, 'failed', $item->get_error_message() );
		}
	}

	private static function header( array $run ): string {
		global $wpdb;
		return '-- GemCloud database backup' . "\n"
			. '-- Site: ' . home_url() . "\n"
			. '-- Created: ' . gmdate( 'Y-m-d H:i:s', (int) $run['started_at'] ) . " UTC\n"
			. '-- Tables: ' . count( (array) $run['tables'] ) . ' (prefix ' . $wpdb->base_prefix . ")\n"
			. "SET NAMES utf8mb4;\n"
			. "SET foreign_key_checks = 0;\n"
			. "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n";
	}

	/**
	 * One row as "(v1,v2,…)". Every value is quoted except NULL; MySQL
	 * converts quoted numbers on insert. Escaping turns newlines into \n,
	 * so the statement stays on one line.
	 */
	private static function row( array $values ): string {
		global $wpdb;
		$out = [];
		foreach ( $values as $value ) {
			$out[] = null === $value ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( (string) $value ) ) . "'";
		}
		return '(' . implode( ',', $out ) . ')';
	}
}

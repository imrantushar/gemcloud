<?php
namespace GemCloud;

use GemCloud\Jobs\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installer {

	const DB_VERSION_OPTION = 'gemcloud_db_version';

	public static function activate(): void {
		self::migrate_legacy_name();
		self::create_tables();
		Settings::ensure_defaults();
		update_option( self::DB_VERSION_OPTION, GEMCLOUD_DB_VERSION, false );
	}

	public static function deactivate(): void {
		Queue::unschedule_all();
	}

	public static function maybe_upgrade(): void {
		self::migrate_legacy_name();
		if ( get_option( self::DB_VERSION_OPTION ) !== GEMCLOUD_DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Carry data over from the beta, when the plugin was named GemCloudStorage:
	 * options, the items table and the per-file "private" post meta.
	 */
	public static function migrate_legacy_name(): void {
		global $wpdb;
		if ( false === get_option( 'gemcloudstorage_db_version' ) ) {
			return;
		}
		foreach ( [ 'settings', 'credentials', 'queue_state', 'migration', 'download_back', 'backup_last', 'backup_run', 'backup_blocked' ] as $name ) {
			$old = get_option( 'gemcloudstorage_' . $name, null );
			if ( null !== $old && false === get_option( 'gemcloud_' . $name ) ) {
				update_option( 'gemcloud_' . $name, $old, false );
			}
			delete_option( 'gemcloudstorage_' . $name );
		}
		$old_table = $wpdb->prefix . 'gemcloudstorage_items';
		$new_table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time rename of this plugin's own table.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) !== $new_table ) {
			$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
		}
		$wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_key = REPLACE(meta_key, '_gemcloudstorage_', '_gemcloud_') WHERE meta_key LIKE '\\_gemcloudstorage\\_%'" );
		// phpcs:enable
		delete_option( 'gemcloudstorage_db_version' );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'gemcloud_items';
	}

	/**
	 * One row per stored object, including each image size.
	 *
	 * `local_path` is the file the row uploads from; `meta` holds the multipart
	 * upload ID and finished part ETags so a job can resume after a timeout.
	 */
	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_type VARCHAR(48) NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  variant VARCHAR(64) NOT NULL DEFAULT 'original',
  provider VARCHAR(16) NOT NULL,
  bucket VARCHAR(63) NOT NULL,
  object_key VARCHAR(1024) NOT NULL,
  visibility VARCHAR(8) NOT NULL DEFAULT 'public',
  mime_type VARCHAR(100) NULL,
  size_bytes BIGINT UNSIGNED NULL,
  etag VARCHAR(80) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  local_state VARCHAR(8) NOT NULL DEFAULT 'present',
  local_path TEXT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  meta LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_variant (source_type,source_id,variant),
  KEY status (status,updated_at),
  KEY object_key (object_key(191))
) {$charset};";

		dbDelta( $sql );
	}
}

<?php
namespace GemCloud;

use GemCloud\Jobs\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installer {

	const DB_VERSION_OPTION = 'gemcloud_db_version';

	public static function activate(): void {
		self::create_tables();
		Settings::ensure_defaults();
		update_option( self::DB_VERSION_OPTION, GEMCLOUD_DB_VERSION, false );
	}

	public static function deactivate(): void {
		Queue::unschedule_all();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== GEMCLOUD_DB_VERSION ) {
			self::activate();
		}
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

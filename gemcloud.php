<?php
/*
 * Plugin Name:		GemCloud
 * Plugin URI:		https://github.com/imrantushar/gemcloud
 * Description:		Offload the media library and protected files to Amazon S3, Cloudflare R2 or any S3-compatible storage — public files from your CDN, private files through short-lived signed URLs.
 * Version:			1.0.0
 * Requires at least:	6.4
 * Requires PHP:		7.4
 * Author:			Kodezen
 * Author URI:		https://kodezen.com/
 * License:			GPL-3.0+
 * License URI:		http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:		gemcloud
 * Domain Path:		/languages/
 */

use GemCloud\Autoload;
use GemCloud\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GEMCLOUD_VERSION', '1.0.0' );
define( 'GEMCLOUD_DB_VERSION', '1.0.0' );
define( 'GEMCLOUD_PLUGIN_SLUG', 'gemcloud' );
define( 'GEMCLOUD_PLUGIN_FILE', __FILE__ );
define( 'GEMCLOUD_ROOT_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'GEMCLOUD_PLUGIN_ROOT_URI', plugin_dir_url( __FILE__ ) );
define( 'GEMCLOUD_INCLUDES_DIR_PATH', GEMCLOUD_ROOT_DIR_PATH . 'includes/' );
define( 'GEMCLOUD_ASSETS_DIR_PATH', GEMCLOUD_ROOT_DIR_PATH . 'assets/' );
define( 'GEMCLOUD_ASSETS_URI', GEMCLOUD_PLUGIN_ROOT_URI . 'assets/' );

// Action Scheduler registers itself and the newest bundled copy on the site
// wins, so it must be included before plugins_loaded.
if ( file_exists( GEMCLOUD_ROOT_DIR_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once GEMCLOUD_ROOT_DIR_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

require_once GEMCLOUD_INCLUDES_DIR_PATH . 'autoload.php';

Autoload::get_instance()->add_namespace_directory( 'GemCloud', GEMCLOUD_INCLUDES_DIR_PATH );
Autoload::get_instance()->register();

register_activation_hook( __FILE__, [ \GemCloud\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \GemCloud\Installer::class, 'deactivate' ] );

Plugin::instance();

<?php
/**
 * Plugin Name:       GemCloudStorage - Offload Media to Amazon S3 & Cloudflare R2
 * Plugin URI:        https://kodezen.com/
 * Description:       Offload the media library and protected files to Amazon S3 or Cloudflare R2, serve public files from your CDN and private files through short-lived signed URLs.
 * Version:           1.0.0.beta-01
 * Author:            kodezen
 * Author URI:        https://kodezen.com
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       gemcloudstorage
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Requires PHP:      7.4
 */

use GemCloudStorage\Autoload;
use GemCloudStorage\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GEMCLOUDSTORAGE_VERSION', '1.0.0.beta-01' );
define( 'GEMCLOUDSTORAGE_DB_VERSION', '1.0.0' );
define( 'GEMCLOUDSTORAGE_PLUGIN_SLUG', 'gemcloudstorage' );
define( 'GEMCLOUDSTORAGE_PLUGIN_FILE', __FILE__ );
define( 'GEMCLOUDSTORAGE_ROOT_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'GEMCLOUDSTORAGE_PLUGIN_ROOT_URI', plugin_dir_url( __FILE__ ) );
define( 'GEMCLOUDSTORAGE_INCLUDES_DIR_PATH', GEMCLOUDSTORAGE_ROOT_DIR_PATH . 'includes/' );
define( 'GEMCLOUDSTORAGE_ASSETS_DIR_PATH', GEMCLOUDSTORAGE_ROOT_DIR_PATH . 'assets/' );
define( 'GEMCLOUDSTORAGE_ASSETS_URI', GEMCLOUDSTORAGE_PLUGIN_ROOT_URI . 'assets/' );

// Action Scheduler registers itself and the newest bundled copy on the site
// wins, so it must be included before plugins_loaded.
if ( file_exists( GEMCLOUDSTORAGE_ROOT_DIR_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once GEMCLOUDSTORAGE_ROOT_DIR_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

require_once GEMCLOUDSTORAGE_INCLUDES_DIR_PATH . 'autoload.php';

Autoload::get_instance()->add_namespace_directory( 'GemCloudStorage', GEMCLOUDSTORAGE_INCLUDES_DIR_PATH );
Autoload::get_instance()->register();

register_activation_hook( __FILE__, [ \GemCloudStorage\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \GemCloudStorage\Installer::class, 'deactivate' ] );

Plugin::instance();

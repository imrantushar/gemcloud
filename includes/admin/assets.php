<?php
namespace GemCloudStorage\Admin;

use GemCloudStorage\Settings;
use GemCloudStorage\Utils\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_app_assets' ] );
	}

	public function enqueue_app_assets( $hook ): void {
		if ( false === strpos( (string) $hook, '_page_' . GEMCLOUDSTORAGE_PLUGIN_SLUG ) ) {
			return;
		}

		$asset_file = GEMCLOUDSTORAGE_ASSETS_DIR_PATH . 'build/app.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		// The app takes over the screen; other plugins' notices overlap its chrome.
		add_action( 'admin_head', static function () {
			echo '<style>#wpbody-content > *:not(#gemcloudstorage-app):not(#screen-meta):not(#screen-meta-links){display:none !important;}</style>';
		} );

		$asset = include $asset_file;

		wp_enqueue_style( 'gemcloudstorage-app-style', GEMCLOUDSTORAGE_ASSETS_URI . 'build/app.css', [], $asset['version'] );
		wp_enqueue_script( 'gemcloudstorage-app-scripts', GEMCLOUDSTORAGE_ASSETS_URI . 'build/app.js', $asset['dependencies'], $asset['version'], true );

		wp_localize_script( 'gemcloudstorage-app-scripts', 'GemCloudStorageGlobal', [
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'namespace'          => GEMCLOUDSTORAGE_PLUGIN_SLUG . '/v1/',
			'rest_url'           => rest_url(),
			'is_plain_permalink' => '' === (string) get_option( 'permalink_structure' ),
			'site_url'           => site_url(),
			'home_url'           => home_url(),
			'admin_url'          => admin_url(),
			'route_path'         => wp_parse_url( admin_url(), PHP_URL_PATH ),
			'plugin_root_url'    => GEMCLOUDSTORAGE_PLUGIN_ROOT_URI,
			'version'            => GEMCLOUDSTORAGE_VERSION,
			'menu'               => wp_json_encode( Helper::get_admin_menu_list() ),
			'theme_mode'         => Settings::value( 'theme.default_mode', 'light' ),
		] );

		wp_set_script_translations( 'gemcloudstorage-app-scripts', 'gemcloudstorage', GEMCLOUDSTORAGE_ROOT_DIR_PATH . 'languages/' );
	}
}

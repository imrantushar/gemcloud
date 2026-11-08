<?php
namespace GemCloud\Admin;

use GemCloud\Settings;
use GemCloud\Utils\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_app_assets' ] );
	}

	public function enqueue_app_assets( $hook ): void {
		if ( false === strpos( (string) $hook, '_page_' . GEMCLOUD_PLUGIN_SLUG ) ) {
			return;
		}

		$asset_file = GEMCLOUD_ASSETS_DIR_PATH . sprintf( 'build/app.%s.asset.php', GEMCLOUD_VERSION );
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		// The app takes over the screen; other plugins' notices overlap its chrome.
		add_action( 'admin_head', static function () {
			echo '<style>#wpbody-content > *:not(#gemcloud-app):not(#screen-meta):not(#screen-meta-links){display:none !important;}</style>';
		} );

		$asset = include $asset_file;

		wp_enqueue_style( 'gemcloud-app-style', GEMCLOUD_ASSETS_URI . 'build/app.css', [], $asset['version'] );
		wp_enqueue_script( 'gemcloud-app-scripts', GEMCLOUD_ASSETS_URI . sprintf( 'build/app.%s.js', GEMCLOUD_VERSION ), $asset['dependencies'], $asset['version'], true );

		wp_localize_script( 'gemcloud-app-scripts', 'GemCloudGlobal', [
			'nonce'              => wp_create_nonce( 'wp_rest' ),
			'namespace'          => GEMCLOUD_PLUGIN_SLUG . '/v1/',
			'rest_url'           => rest_url(),
			'is_plain_permalink' => '' === (string) get_option( 'permalink_structure' ),
			'site_url'           => site_url(),
			'home_url'           => home_url(),
			'admin_url'          => admin_url(),
			'route_path'         => wp_parse_url( admin_url(), PHP_URL_PATH ),
			'plugin_root_url'    => GEMCLOUD_PLUGIN_ROOT_URI,
			'version'            => GEMCLOUD_VERSION,
			'menu'               => wp_json_encode( Helper::get_admin_menu_list() ),
			'theme_mode'         => Settings::value( 'theme.default_mode', 'light' ),
		] );

		wp_set_script_translations( 'gemcloud-app-scripts', 'gemcloud', GEMCLOUD_ROOT_DIR_PATH . 'languages/' );
	}
}

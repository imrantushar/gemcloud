<?php
namespace GemCloudStorage\Admin;

use GemCloudStorage\Utils\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
	}

	public function admin_menu(): void {
		$title = apply_filters( 'gemcloudstorage/admin/toplevel_menu_title', __( 'GemCloudStorage', 'gemcloudstorage' ) );

		add_menu_page( $title, $title, 'manage_options', GEMCLOUDSTORAGE_PLUGIN_SLUG, [ $this, 'load_main_template' ], self::icon(), 81 );

		foreach ( Helper::get_admin_menu_list() as $key => $item ) {
			add_submenu_page( $item['parent_slug'], $item['title'], $item['title'], $item['capability'], $key, [ $this, 'load_main_template' ] );
		}
	}

	public function load_main_template(): void {
		echo '<div id="gemcloudstorage-app" class="gemcloudstorage-app">' . esc_html__( 'Loading…', 'gemcloudstorage' ) . '</div>';
	}

	/**
	 * One shape with a single fill, so WordPress can repaint it for the admin colour scheme.
	 */
	private static function icon(): string {
		$path = GEMCLOUDSTORAGE_ASSETS_DIR_PATH . 'images/menu-icon.svg';
		if ( ! is_readable( $path ) ) {
			return 'dashicons-cloud';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a file this plugin ships, as a data URI.
		return 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $path ) );
	}
}

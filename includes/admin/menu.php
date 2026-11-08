<?php
namespace GemCloud\Admin;

use GemCloud\Utils\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'menu_css' ] );
	}

	/**
	 * Sections in the sidebar, as in Academy LMS and ZenCommunity:
	 * Dashboard / Files, Tools / Settings. Printed on every admin screen,
	 * since the sidebar is on every screen.
	 */
	public function menu_css(): void {
		$menu  = '#adminmenu #toplevel_page_' . GEMCLOUD_PLUGIN_SLUG . ' > .wp-submenu > li';
		$after = [
			$menu . ':has(> a[href$="page=' . GEMCLOUD_PLUGIN_SLUG . '"])',
			$menu . ':has(+ li > a[href$="page=' . GEMCLOUD_PLUGIN_SLUG . '-settings"])',
		];
		wp_register_style( 'gemcloud-menu', false, [], GEMCLOUD_VERSION );
		wp_enqueue_style( 'gemcloud-menu' );
		wp_add_inline_style( 'gemcloud-menu', implode( ',', $after ) . '{margin-bottom:6px;padding-bottom:7px;border-bottom:1px solid rgba(255,255,255,.14)}' );
	}

	public function admin_menu(): void {
		$title = apply_filters( 'gemcloud/admin/toplevel_menu_title', __( 'GemCloud', 'gemcloud' ) );

		$menu_title = esc_html( $title );

		add_menu_page( $title, $menu_title, 'manage_options', GEMCLOUD_PLUGIN_SLUG, [ $this, 'load_main_template' ], self::icon(), 81 );

		foreach ( Helper::get_admin_menu_list() as $key => $item ) {
			add_submenu_page( $item['parent_slug'], $item['title'], $item['title'], $item['capability'], $key, [ $this, 'load_main_template' ] );
		}
	}

	public function load_main_template(): void {
		echo '<div id="gemcloud-app" class="gemcloud-app">' . esc_html__( 'Loading…', 'gemcloud' ) . '</div>';
	}

	/**
	 * One shape with a single fill, so WordPress can repaint it for the admin colour scheme.
	 */
	private static function icon(): string {
		$path = GEMCLOUD_ASSETS_DIR_PATH . 'images/menu-icon.svg';
		if ( ! is_readable( $path ) ) {
			return 'dashicons-cloud';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a file this plugin ships, as a data URI.
		return 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $path ) );
	}
}

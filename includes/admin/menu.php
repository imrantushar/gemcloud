<?php
namespace GemCloudStorage\Admin;

use GemCloudStorage\Utils\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_head', [ $this, 'menu_css' ] );
	}

	/**
	 * Sections in the sidebar, as in Academy LMS and ZenCommunity:
	 * Dashboard / Files, Tools / Settings. Printed on every admin screen,
	 * since the sidebar is on every screen.
	 */
	public function menu_css(): void {
		$menu  = '#adminmenu #toplevel_page_' . GEMCLOUDSTORAGE_PLUGIN_SLUG . ' > .wp-submenu > li';
		$after = [
			$menu . ':has(> a[href$="page=' . GEMCLOUDSTORAGE_PLUGIN_SLUG . '"])',
			$menu . ':has(+ li > a[href$="page=' . GEMCLOUDSTORAGE_PLUGIN_SLUG . '-settings"])',
		];
		printf(
			'<style id="gemcloudstorage-menu">%s{margin-bottom:6px;padding-bottom:7px;border-bottom:1px solid rgba(255,255,255,.14)}</style>',
			implode( ',', $after ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed selectors.
		);
	}

	public function admin_menu(): void {
		$title = apply_filters( 'gemcloudstorage/admin/toplevel_menu_title', __( 'GemCloudStorage', 'gemcloudstorage' ) );

		// The sidebar is too narrow for the name on one line; let it wrap
		// between words instead of before its last letter.
		$menu_title = str_replace( 'GemCloudStorage', 'GemCloud<wbr>Storage', esc_html( $title ) );

		add_menu_page( $title, $menu_title, 'manage_options', GEMCLOUDSTORAGE_PLUGIN_SLUG, [ $this, 'load_main_template' ], self::icon(), 81 );

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

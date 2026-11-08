<?php
namespace GemCloud\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Helper {

	/**
	 * The admin pages. The React app renders the matching page for ?page=.
	 */
	public static function get_admin_menu_list(): array {
		$menu = [
			GEMCLOUD_PLUGIN_SLUG               => [
				'parent_slug' => GEMCLOUD_PLUGIN_SLUG,
				'title'       => __( 'Dashboard', 'gemcloud' ),
				'capability'  => 'manage_options',
			],
			GEMCLOUD_PLUGIN_SLUG . '-files'    => [
				'parent_slug' => GEMCLOUD_PLUGIN_SLUG,
				'title'       => __( 'Files', 'gemcloud' ),
				'capability'  => 'manage_options',
			],
			GEMCLOUD_PLUGIN_SLUG . '-tools'    => [
				'parent_slug' => GEMCLOUD_PLUGIN_SLUG,
				'title'       => __( 'Tools', 'gemcloud' ),
				'capability'  => 'manage_options',
			],
			GEMCLOUD_PLUGIN_SLUG . '-settings' => [
				'parent_slug' => GEMCLOUD_PLUGIN_SLUG,
				'title'       => __( 'Settings', 'gemcloud' ),
				'capability'  => 'manage_options',
			],
		];

		return apply_filters( 'gemcloud/admin/menu_list', $menu );
	}

	public static function format_bytes( int $bytes ): string {
		return size_format( $bytes, 1 );
	}
}

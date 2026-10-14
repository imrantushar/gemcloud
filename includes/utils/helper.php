<?php
namespace GemCloudStorage\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Helper {

	/**
	 * The admin pages. The React app renders the matching page for ?page=.
	 */
	public static function get_admin_menu_list(): array {
		$menu = [
			GEMCLOUDSTORAGE_PLUGIN_SLUG               => [
				'parent_slug' => GEMCLOUDSTORAGE_PLUGIN_SLUG,
				'title'       => __( 'Dashboard', 'gemcloudstorage' ),
				'capability'  => 'manage_options',
			],
			GEMCLOUDSTORAGE_PLUGIN_SLUG . '-files'    => [
				'parent_slug' => GEMCLOUDSTORAGE_PLUGIN_SLUG,
				'title'       => __( 'Files', 'gemcloudstorage' ),
				'capability'  => 'manage_options',
			],
			GEMCLOUDSTORAGE_PLUGIN_SLUG . '-tools'    => [
				'parent_slug' => GEMCLOUDSTORAGE_PLUGIN_SLUG,
				'title'       => __( 'Tools', 'gemcloudstorage' ),
				'capability'  => 'manage_options',
			],
			GEMCLOUDSTORAGE_PLUGIN_SLUG . '-settings' => [
				'parent_slug' => GEMCLOUDSTORAGE_PLUGIN_SLUG,
				'title'       => __( 'Settings', 'gemcloudstorage' ),
				'capability'  => 'manage_options',
			],
		];

		return apply_filters( 'gemcloudstorage/admin/menu_list', $menu );
	}

	public static function format_bytes( int $bytes ): string {
		return size_format( $bytes, 1 );
	}
}

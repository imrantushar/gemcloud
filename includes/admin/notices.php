<?php
namespace GemCloud\Admin;

use GemCloud\Jobs\Queue;
use GemCloud\Security\Credentials;
use GemCloud\Settings;
use GemCloud\Utils\Conflicts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notices on other admin screens. Our own screens show these inside the app.
 */
class Notices {

	public static function init(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && false !== strpos( (string) $screen->id, GEMCLOUD_PLUGIN_SLUG ) ) {
			return;
		}

		$link = admin_url( 'admin.php?page=' . GEMCLOUD_PLUGIN_SLUG );
		$name = __( 'GemCloud', 'gemcloud' );

		if ( Settings::value( 'enabled' ) && Queue::is_paused() ) {
			$state = Queue::state();
			printf(
				'<div class="notice notice-error"><p><strong>%1$s:</strong> %2$s %3$s <a href="%4$s">%5$s</a></p></div>',
				esc_html( $name ),
				esc_html( 'clock' === $state['reason'] ? __( 'Uploads are paused because this server\'s clock is wrong.', 'gemcloud' ) : __( 'Uploads are paused after repeated connection errors.', 'gemcloud' ) ),
				esc_html( (string) $state['message'] ),
				esc_url( $link ),
				esc_html__( 'Fix the connection', 'gemcloud' )
			);
		}

		if ( Credentials::needs_reentry() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s:</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html( $name ),
				esc_html__( 'The stored secret key can\'t be read since the WordPress salts changed.', 'gemcloud' ),
				esc_url( admin_url( 'admin.php?page=' . GEMCLOUD_PLUGIN_SLUG . '-settings' ) ),
				esc_html__( 'Re-enter your secret key', 'gemcloud' )
			);
		}

		$waiting = \GemCloud\Integrations\Integrations::waiting();
		if ( $waiting && Settings::value( 'enabled' ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s:</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html( $name ),
				esc_html( sprintf(
					/* translators: %s: product names */
					__( '%s files are not protected yet: add a private bucket.', 'gemcloud' ),
					\GemCloud\Integrations\Integrations::labels( $waiting )
				) ),
				esc_url( admin_url( 'admin.php?page=' . GEMCLOUD_PLUGIN_SLUG . '-settings' ) ),
				esc_html__( 'Open settings', 'gemcloud' )
			);
		}

		$conflicts = Conflicts::active();
		if ( $conflicts && Settings::value( 'enabled' ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s:</strong> %2$s</p></div>',
				esc_html( $name ),
				esc_html( sprintf(
					/* translators: %s: plugin names */
					__( 'Media library offload is off because %s also rewrites media URLs. Deactivate it to use ours; plugin file contexts keep working.', 'gemcloud' ),
					implode( ', ', $conflicts )
				) )
			);
		}
	}
}

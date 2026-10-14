<?php
namespace GemCloudStorage\Admin;

use GemCloudStorage\Jobs\Queue;
use GemCloudStorage\Security\Credentials;
use GemCloudStorage\Settings;
use GemCloudStorage\Utils\Conflicts;

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
		if ( $screen && false !== strpos( (string) $screen->id, GEMCLOUDSTORAGE_PLUGIN_SLUG ) ) {
			return;
		}

		$link = esc_url( admin_url( 'admin.php?page=' . GEMCLOUDSTORAGE_PLUGIN_SLUG ) );
		$name = esc_html__( 'GemCloudStorage', 'gemcloudstorage' );

		if ( Settings::value( 'enabled' ) && Queue::is_paused() ) {
			$state = Queue::state();
			printf(
				'<div class="notice notice-error"><p><strong>%1$s:</strong> %2$s %3$s <a href="%4$s">%5$s</a></p></div>',
				$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( 'clock' === $state['reason'] ? __( 'Uploads are paused because this server\'s clock is wrong.', 'gemcloudstorage' ) : __( 'Uploads are paused after repeated connection errors.', 'gemcloudstorage' ) ),
				esc_html( (string) $state['message'] ),
				$link, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html__( 'Fix the connection', 'gemcloudstorage' )
			);
		}

		if ( Credentials::needs_reentry() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s:</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html__( 'The stored secret key can\'t be read since the WordPress salts changed.', 'gemcloudstorage' ),
				esc_url( admin_url( 'admin.php?page=' . GEMCLOUDSTORAGE_PLUGIN_SLUG . '-settings' ) ),
				esc_html__( 'Re-enter your secret key', 'gemcloudstorage' )
			);
		}

		$waiting = \GemCloudStorage\Integrations\Integrations::waiting();
		if ( $waiting && Settings::value( 'enabled' ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s:</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( sprintf(
					/* translators: %s: product names */
					__( '%s files are not protected yet: add a private bucket.', 'gemcloudstorage' ),
					\GemCloudStorage\Integrations\Integrations::labels( $waiting )
				) ),
				esc_url( admin_url( 'admin.php?page=' . GEMCLOUDSTORAGE_PLUGIN_SLUG . '-settings' ) ),
				esc_html__( 'Open settings', 'gemcloudstorage' )
			);
		}

		$conflicts = Conflicts::active();
		if ( $conflicts && Settings::value( 'enabled' ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s:</strong> %2$s</p></div>',
				$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html( sprintf(
					/* translators: %s: plugin names */
					__( 'Media library offload is off because %s also rewrites media URLs. Deactivate it to use ours; plugin file contexts keep working.', 'gemcloudstorage' ),
					implode( ', ', $conflicts )
				) )
			);
		}
	}
}

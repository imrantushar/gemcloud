<?php
namespace GemCloud\Media;

use GemCloud\Integrations\Integrations;
use GemCloud\Items\Item;
use GemCloud\Items\ItemRepository;
use GemCloud\Manager;
use GemCloud\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Private files are never linked directly. Pages show /?gemcloud_file=<id>; the
 * click lands here, the access check runs, and only then is a short-lived
 * signed URL created and the browser redirected to it. A signed URL printed
 * into HTML would survive in caches, crawlers and screenshots.
 */
final class PrivateDelivery {

	const QUERY_VAR = 'gemcloud_file';

	/** Minimum signed-link lifetime for video and audio: 4 hours. */
	const MEDIA_TTL = 4 * HOUR_IN_SECONDS;

	public static function init(): void {
		add_action( 'init', [ self::class, 'handle' ], 99 );
	}

	public static function url( Item $item ): string {
		return add_query_arg( self::QUERY_VAR, $item->id, home_url( '/' ) );
	}

	public static function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a download link; access is checked below.
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$item = ItemRepository::find( absint( $_GET[ self::QUERY_VAR ] ) );

		// A file a product just made private may still sit in the public
		// bucket while it moves; it is already served as private.
		$private = $item && ( $item->is_private() || ( 'attachment' === $item->source_type && MediaHooks::is_private( $item->source_id ) ) );

		if ( ! $item || ! $private || ! $item->is_synced() ) {
			self::deny( 404 );
		}

		if ( ! self::can_access( $item ) ) {
			if ( ! is_user_logged_in() ) {
				auth_redirect();
			}
			self::deny( 403 );
		}

		self::serve( $item );
	}

	/**
	 * Media library files, first rule that applies:
	 *  1. people who can edit the file (admins, editors, its uploader);
	 *  2. a product that uses it decides (Academy: enrolled in the lesson…);
	 *  3. a product made it private but no longer uses it: nobody else;
	 *  4. ticked "Private" by hand: logged-in users who may read it.
	 * Files of other contexts: nobody, unless a plugin allows it here (most
	 * plugins check access themselves and call Manager::temporary_url()).
	 */
	public static function can_access( Item $item ): bool {
		$allowed = false;
		if ( 'attachment' === $item->source_type ) {
			$id = $item->source_id;
			if ( current_user_can( 'edit_post', $id ) ) {
				$allowed = true;
			} else {
				$granted = Integrations::grants( $id );
				if ( null !== $granted ) {
					$allowed = $granted;
				} elseif ( MediaHooks::private_by( $id ) ) {
					$allowed = false;
				} else {
					$allowed = is_user_logged_in() && current_user_can( 'read_post', $id );
				}
			}
		}

		/**
		 * Filters whether the current visitor may open a private file.
		 *
		 * @param bool $allowed
		 * @param Item $item
		 */
		return (bool) apply_filters( 'gemcloud/can_access', $allowed, $item );
	}

	public static function serve( Item $item ): void {
		$inline = (bool) preg_match( '#^(image|video|audio|text/vtt)|^application/pdf$#', $item->mime_type );
		$opts   = [ 'disposition' => $inline ? 'inline' : 'attachment' ];

		// The player keeps using the link it was redirected to while the
		// video plays, so a lesson must not outlive it.
		if ( preg_match( '#^(video|audio)/#', $item->mime_type ) ) {
			$opts['ttl'] = max( (int) Settings::value( 'signed_url_ttl', 300 ), self::MEDIA_TTL );
		}

		$mode = Settings::value( 'private_delivery', 'redirect' );

		// Players fetch captions with fetch(), which a redirect to another
		// domain would turn into a CORS request. They're small: stream them.
		if ( 'text/vtt' === $item->mime_type || '.vtt' === strtolower( substr( $item->object_key, -4 ) ) ) {
			$mode = 'stream';
		}

		/**
		 * Filters how a private file is delivered: redirect or stream.
		 *
		 * @param string $mode
		 * @param Item   $item
		 */
		$mode = apply_filters( 'gemcloud/private_delivery_mode', $mode, $item );

		if ( 'stream' !== $mode ) {
			$url = Manager::signed_url_for( $item, $opts );
			if ( ! is_wp_error( $url ) ) {
				nocache_headers();
				header( 'Referrer-Policy: no-referrer' );
				wp_redirect( $url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- signed provider URL.
				exit;
			}
		}

		self::stream_item( $item, $opts );
	}

	/**
	 * Proxy through PHP with Range support. Fallback for hosts where a
	 * redirect to the provider is blocked, and for captions. Only the bytes
	 * the browser asked for are fetched, so seeking in a long video doesn't
	 * pull the whole file through the server each time.
	 */
	public static function stream_item( Item $item, array $opts = [] ): void {
		$url = Manager::signed_url_for( $item, [ 'ttl' => 300 ] );
		if ( is_wp_error( $url ) ) {
			self::deny( 502 );
		}

		// Never let a proxy compress the body: it is written to disk as is.
		$headers = [ 'Accept-Encoding' => 'identity' ];
		$range   = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( preg_match( '/^bytes=\d*-\d*$/', $range ) && 'bytes=-' !== $range ) {
			$headers['Range'] = $range;
		}

		$tmp      = wp_tempnam( 'gemcloud' );
		$response = wp_remote_get( $url, [
			'timeout'  => 600,
			'stream'   => true,
			'filename' => $tmp,
			'headers'  => $headers,
		] );
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( 416 === $status ) {
			wp_delete_file( $tmp );
			status_header( 416 );
			header( 'Content-Range: ' . wp_remote_retrieve_header( $response, 'content-range' ) );
			exit;
		}
		if ( 200 !== $status && 206 !== $status ) {
			wp_delete_file( $tmp );
			self::deny( 502 );
		}

		status_header( $status );
		if ( 206 === $status ) {
			header( 'Content-Range: ' . wp_remote_retrieve_header( $response, 'content-range' ) );
		}

		$filename    = $opts['filename'] ?? $item->filename();
		$disposition = 'inline' === ( $opts['disposition'] ?? 'attachment' ) ? 'inline' : 'attachment';

		// Anything already buffered (a stray BOM or blank line in some
		// plugin file) would land in front of the file and corrupt it.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: ' . ( $item->mime_type ? $item->mime_type : 'application/octet-stream' ) );
		header( 'Content-Length: ' . (int) filesize( $tmp ) );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . str_replace( '"', '', $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming a large file in chunks.
		$handle = fopen( $tmp, 'rb' );
		if ( $handle ) {
			while ( ! feof( $handle ) && ! connection_aborted() ) {
				echo fread( $handle, MB_IN_BYTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file body.
				flush();
			}
			fclose( $handle );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions

		wp_delete_file( $tmp );
		exit;
	}

	private static function deny( int $status ): void {
		nocache_headers();
		status_header( $status );
		wp_die(
			esc_html( 404 === $status ? __( 'File not found.', 'gemcloud' ) : ( 403 === $status ? __( 'You do not have access to this file.', 'gemcloud' ) : __( 'The file could not be fetched from storage.', 'gemcloud' ) ) ),
			'',
			[ 'response' => (int) $status ]
		);
	}
}

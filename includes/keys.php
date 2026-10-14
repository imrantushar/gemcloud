<?php
namespace GemCloudStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds object keys from a context's path template.
 *
 * Public media mirrors the uploads layout ({prefix}/2026/10/photo.jpg), so a
 * CDN URL reads like the local one. Private files get a random segment: the
 * signature protects them, the random part just stops names leaking through
 * logs and referrers.
 */
final class Keys {

	public static function build( string $template, array $vars ): string {
		$filename = sanitize_file_name( $vars['filename'] ?? '' );
		$time     = isset( $vars['time'] ) ? (int) $vars['time'] : time();

		$replacements = [
			'{prefix}'   => trim( (string) Settings::value( 'path_prefix', '' ), '/' ),
			'{file}'     => ltrim( (string) ( $vars['file'] ?? $filename ), '/' ),
			'{Y}'        => gmdate( 'Y', $time ),
			'{m}'        => gmdate( 'm', $time ),
			'{uuid}'     => $vars['uuid'] ?? str_replace( '-', '', wp_generate_uuid4() ),
			'{filename}' => $filename,
			'{context}'  => str_replace( '.', '-', (string) ( $vars['context'] ?? '' ) ),
			'{id}'       => (string) ( $vars['id'] ?? '' ),
		];

		$key = strtr( $template, $replacements );
		$key = preg_replace( '#/+#', '/', $key );

		/**
		 * Filters an object key before it is stored.
		 *
		 * @param string $key  The key.
		 * @param array  $vars Template variables (context, id, filename, file).
		 */
		return ltrim( (string) apply_filters( 'gemcloudstorage/object_key', $key, $vars ), '/' );
	}

	/**
	 * The path of a file relative to the uploads base directory, or '' when it
	 * lives outside uploads.
	 */
	public static function relative_to_uploads( string $path ): string {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$path    = wp_normalize_path( $path );

		return 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : '';
	}
}

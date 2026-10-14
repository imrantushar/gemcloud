<?php
namespace GemCloudStorage\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper on wp_remote_request: timeouts, and provider XML errors turned
 * into StorageException.
 */
final class Client {

	/**
	 * @param array<string,string> $headers
	 * @param array                $args    Extra wp_remote_request args (timeout, stream, filename).
	 * @return array{status:int,headers:array<string,string>,body:string}
	 * @throws StorageException On network failure or an error status.
	 */
	public static function request( string $method, string $url, array $headers = [], string $body = '', array $args = [] ): array {
		// WordPress's curl transport asks for gzip by default. Cloudflare then
		// compresses small text objects on the fly, which drops Content-Length
		// and weakens the ETag, so size and checksum checks would fail.
		if ( ! array_key_exists( 'accept-encoding', array_change_key_case( $headers, CASE_LOWER ) ) ) {
			$headers['Accept-Encoding'] = 'identity';
		}

		$response = wp_remote_request(
			$url,
			array_merge(
				[
					'method'      => $method,
					'headers'     => $headers,
					'body'        => '' === $body ? null : $body,
					'timeout'     => 60,
					'redirection' => 0,
					'sslverify'   => true,
					'user-agent'  => 'GemCloudStorage/' . GEMCLOUDSTORAGE_VERSION . '; ' . home_url( '/' ),
				],
				$args
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new StorageException( 'network', $response->get_error_message() );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		// The headers are a case-insensitive dictionary object; iterate it rather
		// than casting, which would expose its internals instead of the headers.
		$headers = [];
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		$response_body = (string) wp_remote_retrieve_body( $response );

		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) && $status >= 300 && is_readable( $args['filename'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the error body was streamed to disk.
			$response_body = (string) file_get_contents( $args['filename'] );
			wp_delete_file( $args['filename'] );
		}

		if ( $status >= 300 ) {
			throw self::error_from( $status, $response_body, $headers );
		}

		return [
			'status'  => $status,
			'headers' => $headers,
			'body'    => $response_body,
		];
	}

	private static function error_from( int $status, string $body, array $headers ): StorageException {
		$code    = '';
		$message = '';

		if ( '' !== $body && false !== strpos( $body, '<' ) ) {
			$previous = libxml_use_internal_errors( true );
			$xml      = simplexml_load_string( $body );
			libxml_use_internal_errors( $previous );
			if ( false !== $xml ) {
				$code    = (string) ( $xml->Code ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$message = (string) ( $xml->Message ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		// HEAD responses have no body.
		if ( '' === $code ) {
			$map  = [
				400 => 'BadRequest',
				401 => 'Unauthorized',
				403 => 'AccessDenied',
				404 => 'NotFound',
				429 => 'TooManyRequests',
				503 => 'ServiceUnavailable',
			];
			$code = $map[ $status ] ?? ( $status >= 500 ? 'InternalError' : 'HttpError' );
		}

		if ( '' === $message ) {
			/* translators: 1: HTTP status, 2: error code */
			$message = sprintf( __( 'The storage provider answered HTTP %1$d (%2$s).', 'gemcloudstorage' ), $status, $code );
		}

		return new StorageException( $code, $message, $status );
	}
}

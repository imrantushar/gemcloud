<?php
namespace GemCloud\Signing;

use GemCloud\Security\Credentials;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AWS Signature Version 4 for server-side calls (Authorization header).
 */
final class SigV4 {

	const ALGORITHM = 'AWS4-HMAC-SHA256';

	private string $region;
	private Credentials $creds;

	public function __construct( string $region, Credentials $creds ) {
		$this->region = $region;
		$this->creds  = $creds;
	}

	/**
	 * Returns the headers to send, including Authorization.
	 *
	 * @param string               $method       HTTP method.
	 * @param string               $host         Host header value.
	 * @param string               $path         Canonical (already encoded) path.
	 * @param array<string,string> $query        Query parameters, unencoded.
	 * @param array<string,string> $headers      Extra headers to send and sign.
	 * @param string               $payload_hash Hex SHA-256 of the body.
	 * @param int|null             $now          Unix time, for tests.
	 */
	public function sign( string $method, string $host, string $path, array $query, array $headers, string $payload_hash, ?int $now = null ): array {
		$now   = $now ?? time();
		$stamp = gmdate( 'Ymd\THis\Z', $now );
		$day   = gmdate( 'Ymd', $now );
		$scope = "{$day}/{$this->region}/s3/aws4_request";

		$headers['host']                 = $host;
		$headers['x-amz-date']           = $stamp;
		$headers['x-amz-content-sha256'] = $payload_hash;

		$canonical = [];
		foreach ( $headers as $name => $value ) {
			$canonical[ strtolower( $name ) ] = preg_replace( '/\s+/', ' ', trim( (string) $value ) );
		}
		ksort( $canonical, SORT_STRING );

		$canonical_headers = '';
		foreach ( $canonical as $name => $value ) {
			$canonical_headers .= $name . ':' . $value . "\n";
		}
		$signed_headers = implode( ';', array_keys( $canonical ) );

		$canonical_request = implode( "\n", [
			strtoupper( $method ),
			$path,
			self::canonical_query( $query ),
			$canonical_headers,
			$signed_headers,
			$payload_hash,
		] );

		$string_to_sign = self::ALGORITHM . "\n{$stamp}\n{$scope}\n" . hash( 'sha256', $canonical_request );
		$signature      = hash_hmac( 'sha256', $string_to_sign, self::signing_key( $this->creds->secret_access_key, $day, $this->region ) );

		$canonical['authorization'] = self::ALGORITHM
			. " Credential={$this->creds->access_key_id}/{$scope}, SignedHeaders={$signed_headers}, Signature={$signature}";

		// WordPress sets Host itself; sending it twice confuses some proxies.
		unset( $canonical['host'] );

		return $canonical;
	}

	public static function canonical_query( array $query ): string {
		ksort( $query, SORT_STRING );
		$pairs = [];
		foreach ( $query as $key => $value ) {
			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
		return implode( '&', $pairs );
	}

	public static function signing_key( string $secret, string $day, string $region ): string {
		$k = hash_hmac( 'sha256', $day, 'AWS4' . $secret, true );
		$k = hash_hmac( 'sha256', $region, $k, true );
		$k = hash_hmac( 'sha256', 's3', $k, true );
		return hash_hmac( 'sha256', 'aws4_request', $k, true );
	}
}

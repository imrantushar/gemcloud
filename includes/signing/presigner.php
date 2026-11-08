<?php
namespace GemCloud\Signing;

use GemCloud\Providers\Endpoint;
use GemCloud\Security\Credentials;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query-string SigV4: a GET URL valid for $ttl seconds (1 to 604,800).
 *
 * Reproduces the signature in AWS's documented presigned-URL example
 * (examplebucket/test.txt, 2013-05-24, 86,400 s).
 */
final class Presigner {

	private Endpoint $endpoint;
	private Credentials $creds;

	public function __construct( Endpoint $endpoint, Credentials $creds ) {
		$this->endpoint = $endpoint;
		$this->creds    = $creds;
	}

	/**
	 * @param array<string,string> $response e.g. response-content-disposition.
	 */
	public function get( string $bucket, string $key, int $ttl, array $response = [], ?int $now = null ): string {
		$ttl   = max( 1, min( 604800, $ttl ) );
		$now   = $now ?? time();
		$stamp = gmdate( 'Ymd\THis\Z', $now );
		$day   = gmdate( 'Ymd', $now );
		$scope = "{$day}/{$this->endpoint->region}/s3/aws4_request";

		list( $host, $path ) = $this->endpoint->locate( $bucket, Endpoint::encode_key( $key ) );

		$query = $response + [
			'X-Amz-Algorithm'     => SigV4::ALGORITHM,
			'X-Amz-Credential'    => "{$this->creds->access_key_id}/{$scope}",
			'X-Amz-Date'          => $stamp,
			'X-Amz-Expires'       => (string) $ttl,
			'X-Amz-SignedHeaders' => 'host',
		];

		$canonical_query   = SigV4::canonical_query( $query );
		$canonical_request = "GET\n{$path}\n{$canonical_query}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
		$string_to_sign    = SigV4::ALGORITHM . "\n{$stamp}\n{$scope}\n" . hash( 'sha256', $canonical_request );
		$signature         = hash_hmac( 'sha256', $string_to_sign, SigV4::signing_key( $this->creds->secret_access_key, $day, $this->endpoint->region ) );

		return $this->endpoint->scheme() . "://{$host}{$path}?{$canonical_query}&X-Amz-Signature={$signature}";
	}
}

<?php
namespace GemCloud\Providers;

use GemCloud\Contracts\StorageProvider;
use GemCloud\Http\Client;
use GemCloud\Http\StorageException;
use GemCloud\Security\Credentials;
use GemCloud\Settings;
use GemCloud\Signing\Presigner;
use GemCloud\Signing\SigV4;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One driver for every S3-compatible service. About ten S3 operations, signed
 * with SigV4; no AWS SDK.
 */
final class S3CompatibleDriver implements StorageProvider {

	private Endpoint $endpoint;
	private Credentials $creds;
	private SigV4 $signer;

	public function __construct( Endpoint $endpoint, Credentials $creds ) {
		$this->endpoint = $endpoint;
		$this->creds    = $creds;
		$this->signer   = new SigV4( $endpoint->region, $creds );
	}

	public static function from_settings( ?array $settings = null, ?Credentials $creds = null ): self {
		$settings = $settings ?? Settings::get();
		return new self( Presets::from_settings( $settings ), $creds ?? Credentials::current() );
	}

	public function endpoint(): Endpoint {
		return $this->endpoint;
	}

	// ── Objects ─────────────────────────────────────────────────────────────

	public function put( string $bucket, string $key, string $local_path, array $headers = [] ): ObjectInfo {
		if ( ! is_readable( $local_path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, caught and stored; never echoed as HTML.
			throw new StorageException( 'LocalFileMissing', sprintf( /* translators: %s: file path */ __( 'The local file %s is missing.', 'gemcloud' ), $local_path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read for upload.
		$body = (string) file_get_contents( $local_path );

		return $this->put_string( $bucket, $key, $body, $headers );
	}

	public function put_string( string $bucket, string $key, string $body, array $headers = [] ): ObjectInfo {
		$headers['content-md5'] = self::md5_header( $body );
		$response               = $this->call( 'PUT', $bucket, $key, [], $headers, $body, [ 'timeout' => 300 ] );

		return new ObjectInfo( $key, strlen( $body ), $response['headers']['etag'] ?? '', $headers['content-type'] ?? '' );
	}

	public function head( string $bucket, string $key ): ?ObjectInfo {
		try {
			$response = $this->call( 'HEAD', $bucket, $key );
		} catch ( StorageException $e ) {
			if ( StorageException::KIND_NOT_FOUND === $e->kind() ) {
				return null;
			}
			throw $e;
		}

		$h = $response['headers'];
		return new ObjectInfo( $key, $this->size_from( $bucket, $key, $h ), $h['etag'] ?? '', $h['content-type'] ?? '', $h['last-modified'] ?? '' );
	}

	/**
	 * Object size from a HEAD response. A proxy that re-encodes the body can
	 * drop Content-Length; then ask for the first byte and read the total
	 * from Content-Range instead.
	 */
	private function size_from( string $bucket, string $key, array $headers ): int {
		if ( isset( $headers['content-length'] ) && empty( $headers['content-encoding'] ) ) {
			return (int) $headers['content-length'];
		}

		try {
			$response = $this->call( 'GET', $bucket, $key, [], [ 'range' => 'bytes=0-0' ] );
		} catch ( StorageException $e ) {
			if ( 416 === $e->http_status() ) {
				return 0; // An empty object has no first byte.
			}
			throw $e;
		}

		if ( preg_match( '#/(\d+)\s*$#', $response['headers']['content-range'] ?? '', $m ) ) {
			return (int) $m[1];
		}
		return (int) ( $response['headers']['content-length'] ?? 0 );
	}

	public function get( string $bucket, string $key, string $save_to ): void {
		wp_mkdir_p( dirname( $save_to ) );
		$tmp = $save_to . '.gemcloud-' . wp_generate_password( 6, false ) . '.part';

		$this->call( 'GET', $bucket, $key, [], [], '', [
			'timeout'  => 600,
			'stream'   => true,
			'filename' => $tmp,
		] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap of a completed download.
		if ( ! @rename( $tmp, $save_to ) ) {
			wp_delete_file( $tmp );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, caught and stored; never echoed as HTML.
			throw new StorageException( 'LocalWriteFailed', sprintf( /* translators: %s: file path */ __( 'Could not write %s.', 'gemcloud' ), $save_to ) );
		}
	}

	public function delete_many( string $bucket, array $keys ): array {
		$keys = array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) );
		if ( empty( $keys ) ) {
			return [
				'deleted' => [],
				'errors'  => [],
			];
		}

		$xml = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>';
		foreach ( array_slice( $keys, 0, 1000 ) as $key ) {
			$xml .= '<Object><Key>' . self::xml( $key ) . '</Key></Object>';
		}
		$xml .= '</Delete>';

		$response = $this->call( 'POST', $bucket, '', [ 'delete' => '' ], [
			'content-type' => 'application/xml',
			'content-md5'  => self::md5_header( $xml ),
		], $xml );

		$errors = [];
		$parsed = self::parse_xml( $response['body'] );
		if ( $parsed && isset( $parsed->Error ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			foreach ( $parsed->Error as $error ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$errors[ (string) $error->Key ] = (string) $error->Code . ': ' . (string) $error->Message; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		return [
			'deleted' => array_values( array_diff( $keys, array_keys( $errors ) ) ),
			'errors'  => $errors,
		];
	}

	public function list( string $bucket, string $prefix = '', ?string $token = null, int $max = 1000 ): array {
		$query = [
			'list-type' => '2',
			'max-keys'  => (string) max( 1, min( 1000, $max ) ),
		];
		if ( '' !== $prefix ) {
			$query['prefix'] = $prefix;
		}
		if ( $token ) {
			$query['continuation-token'] = $token;
		}

		$response = $this->call( 'GET', $bucket, '', $query );
		$xml      = self::parse_xml( $response['body'] );
		$objects  = [];

		if ( $xml && isset( $xml->Contents ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			foreach ( $xml->Contents as $item ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$objects[] = new ObjectInfo( (string) $item->Key, (int) $item->Size, (string) $item->ETag, '', (string) $item->LastModified ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		$truncated = $xml && 'true' === (string) $xml->IsTruncated; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		return [
			'objects'    => $objects,
			'next_token' => $truncated ? (string) $xml->NextContinuationToken : null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		];
	}

	// ── Multipart ───────────────────────────────────────────────────────────

	public function multipart_start( string $bucket, string $key, array $headers = [] ): string {
		$response = $this->call( 'POST', $bucket, $key, [ 'uploads' => '' ], $headers );
		$xml      = self::parse_xml( $response['body'] );
		$id       = $xml ? (string) $xml->UploadId : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		if ( '' === $id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, caught and stored; never echoed as HTML.
			throw new StorageException( 'MultipartStartFailed', __( 'The provider did not return a multipart upload ID.', 'gemcloud' ) );
		}
		return $id;
	}

	public function multipart_part( string $bucket, string $key, string $upload_id, int $number, string $chunk ): string {
		$response = $this->call( 'PUT', $bucket, $key, [
			'partNumber' => (string) $number,
			'uploadId'   => $upload_id,
		], [ 'content-md5' => self::md5_header( $chunk ) ], $chunk, [ 'timeout' => 300 ] );

		$etag = trim( $response['headers']['etag'] ?? '', '"' );
		if ( '' === $etag ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, caught and stored; never echoed as HTML.
			throw new StorageException( 'MultipartPartFailed', __( 'The provider did not return an ETag for the part.', 'gemcloud' ) );
		}
		return $etag;
	}

	public function multipart_finish( string $bucket, string $key, string $upload_id, array $etags ): ObjectInfo {
		ksort( $etags, SORT_NUMERIC );

		$xml = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload>';
		foreach ( $etags as $number => $etag ) {
			$xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>"' . self::xml( trim( $etag, '"' ) ) . '"</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';

		$response = $this->call( 'POST', $bucket, $key, [ 'uploadId' => $upload_id ], [ 'content-type' => 'application/xml' ], $xml, [ 'timeout' => 300 ] );
		$parsed   = self::parse_xml( $response['body'] );

		// S3 can answer 200 with an error document when completion fails late.
		if ( $parsed && 'Error' === $parsed->getName() ) {
			throw new StorageException( (string) $parsed->Code, (string) $parsed->Message, 200 ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- S3 XML fields; internal exception, never echoed as HTML.
		}

		return new ObjectInfo( $key, 0, $parsed ? (string) $parsed->ETag : '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	public function multipart_abort( string $bucket, string $key, string $upload_id ): void {
		try {
			$this->call( 'DELETE', $bucket, $key, [ 'uploadId' => $upload_id ] );
		} catch ( StorageException $e ) {
			if ( StorageException::KIND_NOT_FOUND !== $e->kind() ) {
				throw $e;
			}
		}
	}

	// ── URLs, CORS, test ────────────────────────────────────────────────────

	public function presign_get( string $bucket, string $key, int $ttl, array $response = [] ): string {
		return ( new Presigner( $this->endpoint, $this->creds ) )->get( $bucket, $key, $ttl, $response );
	}

	/**
	 * Allow the site origin to GET, HEAD and PUT, needed for subtitles, web
	 * fonts and direct browser uploads.
	 *
	 * @param string[] $origins
	 */
	public function put_cors( string $bucket, array $origins ): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><CORSConfiguration><CORSRule>';
		foreach ( $origins as $origin ) {
			$xml .= '<AllowedOrigin>' . self::xml( $origin ) . '</AllowedOrigin>';
		}
		$xml .= '<AllowedMethod>GET</AllowedMethod><AllowedMethod>HEAD</AllowedMethod><AllowedMethod>PUT</AllowedMethod>'
			. '<AllowedHeader>*</AllowedHeader><ExposeHeader>ETag</ExposeHeader><MaxAgeSeconds>3600</MaxAgeSeconds>'
			. '</CORSRule></CORSConfiguration>';

		$this->call( 'PUT', $bucket, '', [ 'cors' => '' ], [
			'content-type' => 'application/xml',
			'content-md5'  => self::md5_header( $xml ),
		], $xml );
	}

	public function test( string $bucket ): array {
		$key    = 'gemcloud-probe/' . wp_generate_password( 12, false ) . '.txt';
		$body   = 'GemCloud connection test ' . gmdate( 'c' );
		$steps  = [];
		$report = [
			'ok'         => false,
			'bucket'     => $bucket,
			'steps'      => [],
			'clock_skew' => null,
			'error'      => null,
		];

		$run = function ( string $name, callable $fn ) use ( &$steps ) {
			$start = microtime( true );
			try {
				$result  = $fn();
				$steps[] = [
					'name' => $name,
					'ok'   => true,
					'ms'   => (int) round( ( microtime( true ) - $start ) * 1000 ),
				];
				return $result;
			} catch ( StorageException $e ) {
				$steps[] = [
					'name'  => $name,
					'ok'    => false,
					'ms'    => (int) round( ( microtime( true ) - $start ) * 1000 ),
					'error' => $e->to_array(),
				];
				throw $e;
			}
		};

		try {
			$run( 'put', function () use ( $bucket, $key, $body ) {
				return $this->put_string( $bucket, $key, $body, [ 'content-type' => 'text/plain' ] );
			} );

			$info = $run( 'head', function () use ( $bucket, $key, &$report ) {
				$response = $this->call( 'HEAD', $bucket, $key );
				$date     = $response['headers']['date'] ?? '';
				if ( $date ) {
					$report['clock_skew'] = time() - (int) strtotime( $date );
				}
				return $this->size_from( $bucket, $key, $response['headers'] );
			} );

			if ( strlen( $body ) !== $info ) {
				throw new StorageException( 'SizeMismatch', sprintf( /* translators: 1: bytes sent, 2: bytes reported */ __( 'The probe object came back with a different size: sent %1$d bytes, the bucket reports %2$d.', 'gemcloud' ), strlen( $body ), $info ) );
			}

			$run( 'delete', function () use ( $bucket, $key ) {
				$result = $this->delete_many( $bucket, [ $key ] );
				if ( ! empty( $result['errors'] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, caught and stored; never echoed as HTML.
					throw new StorageException( 'DeleteFailed', (string) reset( $result['errors'] ) );
				}
				return true;
			} );

			$report['ok'] = true;
		} catch ( StorageException $e ) {
			$report['error'] = $e->to_array();

			// Don't leave the probe behind when a later step failed.
			if ( ! empty( $steps[0]['ok'] ) && ( $steps[2]['name'] ?? '' ) !== 'delete' ) {
				try {
					$this->delete_many( $bucket, [ $key ] );
				} catch ( StorageException $ignored ) {
					unset( $ignored );
				}
			}
		}

		$report['steps'] = $steps;

		if ( null !== $report['clock_skew'] && abs( $report['clock_skew'] ) > 300 ) {
			$report['clock_warning'] = sprintf(
				/* translators: %d: seconds */
				__( 'This server\'s clock is %d seconds off the provider\'s. Signed requests fail beyond 15 minutes.', 'gemcloud' ),
				abs( $report['clock_skew'] )
			);
		}

		return $report;
	}

	// ── Internals ───────────────────────────────────────────────────────────

	/**
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	private function call( string $method, string $bucket, string $key, array $query = [], array $headers = [], string $body = '', array $args = [] ): array {
		list( $host, $path ) = $this->endpoint->locate( $bucket, '' === $key ? '' : Endpoint::encode_key( $key ) );

		$signed = $this->signer->sign( $method, $host, $path, $query, $headers, hash( 'sha256', $body ) );
		$qs     = SigV4::canonical_query( $query );
		$url    = $this->endpoint->scheme() . '://' . $host . $path . ( '' !== $qs ? '?' . $qs : '' );

		return Client::request( $method, $url, $signed, $body, $args );
	}

	private static function md5_header( string $body ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Content-MD5 is base64 by definition.
		return base64_encode( md5( $body, true ) );
	}

	private static function xml( string $value ): string {
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	private static function parse_xml( string $body ): ?\SimpleXMLElement {
		if ( '' === trim( $body ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body );
		libxml_use_internal_errors( $previous );
		return false === $xml ? null : $xml;
	}
}

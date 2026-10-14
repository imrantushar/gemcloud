<?php
namespace GemCloudStorage\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A provider error, classified so jobs know whether to retry.
 */
class StorageException extends \RuntimeException {

	const KIND_TEMPORARY     = 'temporary';
	const KIND_CONFIGURATION = 'configuration';
	const KIND_CLOCK         = 'clock';
	const KIND_NOT_FOUND     = 'not_found';
	const KIND_OTHER         = 'other';

	const TEMPORARY_CODES = [ 'SlowDown', 'RequestTimeout', 'InternalError', 'ServiceUnavailable', 'network', 'TooManyRequests' ];

	const CONFIGURATION_CODES = [
		'AccessDenied',
		'InvalidAccessKeyId',
		'SignatureDoesNotMatch',
		'NoSuchBucket',
		'AuthorizationHeaderMalformed',
		'PermanentRedirect',
		'InvalidBucketName',
		'Unauthorized',
		'InvalidArgument',
	];

	private string $error_code;
	private int $http_status;

	public function __construct( string $error_code, string $message, int $http_status = 0 ) {
		parent::__construct( $message );
		$this->error_code  = $error_code;
		$this->http_status = $http_status;
	}

	public function error_code(): string {
		return $this->error_code;
	}

	public function http_status(): int {
		return $this->http_status;
	}

	public function kind(): string {
		if ( 'RequestTimeTooSkewed' === $this->error_code ) {
			return self::KIND_CLOCK;
		}
		if ( in_array( $this->error_code, self::TEMPORARY_CODES, true ) || $this->http_status >= 500 || 429 === $this->http_status ) {
			return self::KIND_TEMPORARY;
		}
		if ( in_array( $this->error_code, [ 'NoSuchKey', 'NotFound' ], true ) || ( 404 === $this->http_status && 'NoSuchBucket' !== $this->error_code ) ) {
			return self::KIND_NOT_FOUND;
		}
		if ( in_array( $this->error_code, self::CONFIGURATION_CODES, true ) || in_array( $this->http_status, [ 401, 403 ], true ) ) {
			return self::KIND_CONFIGURATION;
		}
		return self::KIND_OTHER;
	}

	/**
	 * Plain advice for the settings screen.
	 */
	public function hint(): string {
		switch ( $this->error_code ) {
			case 'AccessDenied':
			case 'Unauthorized':
				return __( 'Check the bucket name first: a scoped R2 token answers "access denied" for a bucket it doesn\'t know, never "not found". Then check that the key may read and write that bucket.', 'gemcloudstorage' );
			case 'InvalidAccessKeyId':
				return __( 'The access key ID is wrong or was deleted.', 'gemcloudstorage' );
			case 'SignatureDoesNotMatch':
				return __( 'The secret access key is wrong. On Amazon S3 a wrong region causes this too.', 'gemcloudstorage' );
			case 'NoSuchBucket':
				return __( 'The bucket doesn\'t exist. Create it first, or check the spelling.', 'gemcloudstorage' );
			case 'AuthorizationHeaderMalformed':
			case 'PermanentRedirect':
				return __( 'The region is wrong for this bucket.', 'gemcloudstorage' );
			case 'RequestTimeTooSkewed':
				return __( 'This server\'s clock is more than 15 minutes off. Ask your host to enable time sync (NTP).', 'gemcloudstorage' );
			case 'network':
				return __( 'The endpoint can\'t be reached. Check the account ID or endpoint URL, and that the server can make outgoing HTTPS requests.', 'gemcloudstorage' );
			default:
				return '';
		}
	}

	public function to_array(): array {
		return [
			'code'    => $this->error_code,
			'status'  => $this->http_status,
			'message' => $this->getMessage(),
			'kind'    => $this->kind(),
			'hint'    => $this->hint(),
		];
	}
}

<?php
namespace GemCloud;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names an object by context and the owning plugin's own ID, so plugins never
 * store bucket keys. Serialises as gcs://<context>/<id>.
 */
final class Ref {

	const SCHEME = 'gemcloud://';

	/** Scheme used by the beta, still read so stored references keep working. */
	const LEGACY_SCHEME = 'gcs://';

	public string $context;
	public int $id;

	private function __construct( string $context, int $id ) {
		$this->context = $context;
		$this->id      = $id;
	}

	public static function of( string $context, int $id ): self {
		return new self( $context, $id );
	}

	public static function attachment( int $attachment_id ): self {
		return new self( 'attachment', $attachment_id );
	}

	public static function is_uri( string $value ): bool {
		return 0 === strpos( $value, self::SCHEME ) || 0 === strpos( $value, self::LEGACY_SCHEME );
	}

	public static function from_uri( string $uri ): ?self {
		if ( ! preg_match( '#^(?:gemcloud|gcs)://([a-z0-9._-]+)/(\d+)$#', $uri, $m ) ) {
			return null;
		}
		return new self( $m[1], (int) $m[2] );
	}

	public function to_uri(): string {
		return self::SCHEME . $this->context . '/' . $this->id;
	}
}

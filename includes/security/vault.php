<?php
namespace GemCloud\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seals secrets with libsodium secretbox. WordPress ships sodium_compat, so
 * this works even where the PHP extension is missing.
 *
 * The key is GEMCLOUD_KEY from wp-config.php when defined; otherwise it
 * is derived from AUTH_KEY and AUTH_SALT. Rotating the salts makes stored
 * secrets unreadable, which decrypt() reports instead of returning garbage.
 */
final class Vault {

	const PREFIX = 'enc:v1:';

	public static function encrypt( string $plain ): string {
		$key   = self::key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, $key );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext to text.
		return self::PREFIX . base64_encode( $nonce . $box );
	}

	/**
	 * @return string|null Null when the value can't be opened (salts changed).
	 */
	public static function decrypt( string $sealed ): ?string {
		if ( 0 !== strpos( $sealed, self::PREFIX ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reverse of encrypt().
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plain = sodium_crypto_secretbox_open( $box, $nonce, self::key() );
		} catch ( \Throwable $e ) {
			return null;
		}

		return false === $plain ? null : $plain;
	}

	/**
	 * Identifies the key without revealing it, so a changed key is detected
	 * before any API call.
	 */
	public static function fingerprint(): string {
		return 'sha256:' . substr( hash( 'sha256', 'gemcloud-fp' . self::key() ), 0, 16 );
	}

	private static function key(): string {
		if ( defined( 'GEMCLOUD_KEY' ) && '' !== (string) GEMCLOUD_KEY ) {
			return hash( 'sha256', (string) GEMCLOUD_KEY, true );
		}

		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
		if ( '' === $material ) {
			$material = wp_salt( 'auth' );
		}

		return hash_hkdf( 'sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'gemcloud-vault' );
	}
}

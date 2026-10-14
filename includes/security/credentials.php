<?php
namespace GemCloudStorage\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access keys. Stored in a non-autoloaded option with the secret sealed by
 * Vault; wp-config.php constants override the stored values.
 */
final class Credentials {

	const OPTION = 'gemcloudstorage_credentials';

	const MASK = '••••';

	public string $access_key_id;
	public string $secret_access_key;

	public function __construct( string $access_key_id, string $secret_access_key ) {
		$this->access_key_id     = $access_key_id;
		$this->secret_access_key = $secret_access_key;
	}

	public static function current(): self {
		return new self( self::access_key_id(), (string) self::secret() );
	}

	public static function has_keys(): bool {
		return '' !== self::access_key_id() && '' !== (string) self::secret();
	}

	public static function access_key_id(): string {
		if ( defined( 'GEMCLOUDSTORAGE_ACCESS_KEY_ID' ) ) {
			return (string) GEMCLOUDSTORAGE_ACCESS_KEY_ID;
		}
		return (string) ( self::stored()['access_key_id'] ?? '' );
	}

	/**
	 * @return string|null Null when a secret is stored but can't be decrypted.
	 */
	public static function secret(): ?string {
		if ( defined( 'GEMCLOUDSTORAGE_SECRET_ACCESS_KEY' ) ) {
			return (string) GEMCLOUDSTORAGE_SECRET_ACCESS_KEY;
		}

		$sealed = (string) ( self::stored()['secret_access_key'] ?? '' );
		if ( '' === $sealed ) {
			return '';
		}

		return Vault::decrypt( $sealed );
	}

	/**
	 * True when a secret is stored but the encryption key changed since.
	 */
	public static function needs_reentry(): bool {
		if ( defined( 'GEMCLOUDSTORAGE_SECRET_ACCESS_KEY' ) ) {
			return false;
		}
		$stored = self::stored();
		if ( empty( $stored['secret_access_key'] ) ) {
			return false;
		}
		return ( $stored['key_fingerprint'] ?? '' ) !== Vault::fingerprint() || null === self::secret();
	}

	/**
	 * Saving the masked value back keeps the stored secret.
	 */
	public static function save( string $access_key_id, string $secret ): void {
		$stored = self::stored();

		$access_key_id = preg_replace( '/[^A-Za-z0-9]/', '', $access_key_id );
		$secret        = trim( $secret );

		$sealed = $stored['secret_access_key'] ?? '';
		if ( '' !== $secret && ! self::is_mask( $secret ) ) {
			$sealed = Vault::encrypt( $secret );
		} elseif ( '' === $secret ) {
			$sealed = '';
		}

		update_option(
			self::OPTION,
			[
				'access_key_id'     => $access_key_id,
				'secret_access_key' => $sealed,
				'key_fingerprint'   => Vault::fingerprint(),
			],
			false
		);
	}

	/**
	 * What the settings screen may see. The secret is never echoed.
	 */
	public static function for_display(): array {
		$secret = self::secret();
		return [
			'access_key_id'     => self::access_key_id(),
			'secret_access_key' => $secret ? self::MASK . substr( $secret, -4 ) : '',
			'has_secret'        => (bool) $secret,
			'needs_reentry'     => self::needs_reentry(),
			'locked'            => [
				'provider'          => defined( 'GEMCLOUDSTORAGE_PROVIDER' ),
				'account_id'        => defined( 'GEMCLOUDSTORAGE_ACCOUNT_ID' ),
				'access_key_id'     => defined( 'GEMCLOUDSTORAGE_ACCESS_KEY_ID' ),
				'secret_access_key' => defined( 'GEMCLOUDSTORAGE_SECRET_ACCESS_KEY' ),
			],
		];
	}

	public static function is_mask( string $value ): bool {
		return 0 === strpos( $value, self::MASK );
	}

	private static function stored(): array {
		$value = get_option( self::OPTION, [] );
		return is_array( $value ) ? $value : [];
	}
}

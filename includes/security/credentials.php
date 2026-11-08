<?php
namespace GemCloud\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access keys. Stored in a non-autoloaded option with the secret sealed by
 * Vault; wp-config.php constants override the stored values.
 */
final class Credentials {

	const OPTION = 'gemcloud_credentials';

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
		if ( defined( 'GEMCLOUD_ACCESS_KEY_ID' ) ) {
			return (string) GEMCLOUD_ACCESS_KEY_ID;
		}
		return (string) ( self::stored()['access_key_id'] ?? '' );
	}

	/**
	 * @return string|null Null when a secret is stored but can't be decrypted.
	 */
	public static function secret(): ?string {
		if ( defined( 'GEMCLOUD_SECRET_ACCESS_KEY' ) ) {
			return (string) GEMCLOUD_SECRET_ACCESS_KEY;
		}

		$sealed = (string) ( self::stored()['secret_access_key'] ?? '' );
		if ( '' === $sealed ) {
			return '';
		}

		return Vault::decrypt( $sealed );
	}

	/**
	 * The stored secret of one provider's keys ('' when none).
	 */
	public static function secret_for( string $provider ): string {
		$stored = self::stored();
		if ( $provider === self::owner( $stored ) ) {
			return (string) self::secret();
		}
		$sealed = (string) ( self::profiles_of( $stored )[ $provider ]['secret_access_key'] ?? '' );
		return '' !== $sealed ? (string) Vault::decrypt( $sealed ) : '';
	}

	/**
	 * True when a secret is stored but the encryption key changed since.
	 */
	public static function needs_reentry(): bool {
		if ( defined( 'GEMCLOUD_SECRET_ACCESS_KEY' ) ) {
			return false;
		}
		$stored = self::stored();
		if ( empty( $stored['secret_access_key'] ) ) {
			return false;
		}
		return ( $stored['key_fingerprint'] ?? '' ) !== Vault::fingerprint() || null === self::secret();
	}

	/**
	 * Keys for the active provider. Each provider also keeps its own pair,
	 * so switching provider on the Connection screen never mixes keys.
	 * Saving the masked value back keeps that provider's stored secret.
	 */
	public static function save( string $access_key_id, string $secret, string $provider = '' ): void {
		$stored   = self::stored();
		$provider = '' !== $provider ? $provider : self::owner( $stored );
		$profiles = self::profiles_of( $stored );
		$sealed   = self::seal( $secret, $profiles[ $provider ]['secret_access_key'] ?? '' );
		$access   = preg_replace( '/[^A-Za-z0-9]/', '', $access_key_id );

		$profiles[ $provider ] = [
			'access_key_id'     => $access,
			'secret_access_key' => $sealed,
		];

		update_option(
			self::OPTION,
			[
				'access_key_id'     => $access,
				'secret_access_key' => $sealed,
				'key_fingerprint'   => Vault::fingerprint(),
				'provider'          => $provider,
				'profiles'          => $profiles,
			],
			false
		);
	}

	/**
	 * Keys for a provider that isn't the active one.
	 */
	public static function save_profile( string $provider, string $access_key_id, string $secret ): void {
		$stored   = self::stored();
		$profiles = self::profiles_of( $stored );

		$profiles[ $provider ] = [
			'access_key_id'     => preg_replace( '/[^A-Za-z0-9]/', '', $access_key_id ),
			'secret_access_key' => self::seal( $secret, $profiles[ $provider ]['secret_access_key'] ?? '' ),
		];

		$stored['profiles']        = $profiles;
		$stored['provider']        = self::owner( $stored );
		$stored['key_fingerprint'] = Vault::fingerprint();
		update_option( self::OPTION, $stored, false );
	}

	/** A new secret sealed, the mask keeps $previous, empty clears it. */
	private static function seal( string $secret, string $previous ): string {
		$secret = trim( $secret );
		if ( '' === $secret ) {
			return '';
		}
		return self::is_mask( $secret ) ? $previous : Vault::encrypt( $secret );
	}

	/** The provider the top-level keys belong to. */
	private static function owner( array $stored ): string {
		return (string) ( $stored['provider'] ?? \GemCloud\Settings::get()['provider'] );
	}

	/**
	 * Saved per-provider keys, with keys saved before profiles existed
	 * filed under the provider they were used with.
	 */
	private static function profiles_of( array $stored ): array {
		$profiles = isset( $stored['profiles'] ) && is_array( $stored['profiles'] ) ? $stored['profiles'] : [];
		$owner    = self::owner( $stored );
		if ( ! isset( $profiles[ $owner ] ) && ( ! empty( $stored['access_key_id'] ) || ! empty( $stored['secret_access_key'] ) ) ) {
			$profiles[ $owner ] = [
				'access_key_id'     => (string) ( $stored['access_key_id'] ?? '' ),
				'secret_access_key' => (string) ( $stored['secret_access_key'] ?? '' ),
			];
		}
		return $profiles;
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
			'profiles'          => self::profiles_for_display(),
			'locked'            => [
				'provider'          => defined( 'GEMCLOUD_PROVIDER' ),
				'account_id'        => defined( 'GEMCLOUD_ACCOUNT_ID' ),
				'access_key_id'     => defined( 'GEMCLOUD_ACCESS_KEY_ID' ),
				'secret_access_key' => defined( 'GEMCLOUD_SECRET_ACCESS_KEY' ),
			],
		];
	}

	/**
	 * Every provider's keys, secrets masked.
	 *
	 * @return array<string,array{access_key_id:string,secret_access_key:string}>
	 */
	private static function profiles_for_display(): array {
		$out = [];
		foreach ( self::profiles_of( self::stored() ) as $provider => $keys ) {
			$sealed = (string) ( $keys['secret_access_key'] ?? '' );
			$secret = '' !== $sealed ? Vault::decrypt( $sealed ) : '';
			$out[ $provider ] = [
				'access_key_id'     => (string) ( $keys['access_key_id'] ?? '' ),
				'secret_access_key' => $secret ? self::MASK . substr( $secret, -4 ) : '',
			];
		}
		return $out;
	}

	public static function is_mask( string $value ): bool {
		return 0 === strpos( $value, self::MASK );
	}

	private static function stored(): array {
		$value = get_option( self::OPTION, [] );
		return is_array( $value ) ? $value : [];
	}
}

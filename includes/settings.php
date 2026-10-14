<?php
namespace GemCloudStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-wide settings, one autoloaded option with no secrets in it. Secrets
 * live in Security\Credentials.
 */
class Settings {

	const OPTION = 'gemcloudstorage_settings';

	const PROVIDERS = [ 'r2', 'aws', 'custom' ];

	const LOCAL_FILE_LEVELS = [ 'keep', 'remove_sizes', 'remove_all' ];

	public static function defaults(): array {
		return [
			'schema_version'   => 1,
			'enabled'          => false,
			'provider'         => 'r2',
			'endpoint'         => [
				'account_id'   => '',
				'jurisdiction' => '',
				'region'       => 'us-east-1',
				'url'          => '',
				'path_style'   => true,
			],
			'buckets'          => [
				'public'  => [
					'name'       => '',
					'public_url' => '',
				],
				'private' => [
					'name' => '',
				],
			],
			// Each provider's own endpoint and buckets, so switching provider
			// on the Connection screen doesn't carry R2 values over to S3.
			'profiles'         => [],
			'path_prefix'      => 'wp-content/uploads',
			'media'            => [
				'offload'     => true,
				'local_files' => 'keep',
			],
			'signed_url_ttl'   => 300,
			'private_delivery' => 'redirect',
			'contexts'         => [],
			'backups'          => [
				'enabled'   => false,
				'frequency' => 'daily',
				'keep_days' => 30,
			],
			'theme'            => [
				'default_mode' => 'light',
			],
		];
	}

	public static function ensure_defaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', true );
		}
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		$settings = self::merge( self::defaults(), $saved );

		// Not a choice: always where WordPress keeps uploads (see path_prefix()).
		$settings['path_prefix'] = self::path_prefix();

		// Constants win over stored values.
		if ( defined( 'GEMCLOUDSTORAGE_PROVIDER' ) && in_array( GEMCLOUDSTORAGE_PROVIDER, self::PROVIDERS, true ) ) {
			$settings['provider'] = GEMCLOUDSTORAGE_PROVIDER;
		}
		if ( defined( 'GEMCLOUDSTORAGE_ACCOUNT_ID' ) ) {
			$settings['endpoint']['account_id'] = (string) GEMCLOUDSTORAGE_ACCOUNT_ID;
		}

		return $settings;
	}

	public static function value( string $path, $default = null ) {
		$node = self::get();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return $default;
			}
			$node = $node[ $segment ];
		}
		return $node;
	}

	/**
	 * Sanitize and store. Unknown keys are dropped.
	 */
	public static function save( array $data ): array {
		$current = self::get();
		$merged  = self::merge( $current, $data );

		$provider = in_array( $merged['provider'], self::PROVIDERS, true ) ? $merged['provider'] : 'r2';

		// Every provider keeps its own connection; the active one is also
		// the top-level endpoint and buckets the rest of the plugin reads.
		$profiles = [];
		foreach ( self::PROVIDERS as $key ) {
			if ( $key === $provider ) {
				$profiles[ $key ] = self::sanitize_connection( $key, (array) $merged['endpoint'], (array) $merged['buckets'] );
			} elseif ( isset( $merged['profiles'][ $key ] ) && is_array( $merged['profiles'][ $key ] ) ) {
				$profiles[ $key ] = self::sanitize_connection( $key, (array) ( $merged['profiles'][ $key ]['endpoint'] ?? [] ), (array) ( $merged['profiles'][ $key ]['buckets'] ?? [] ) );
			}
		}

		$clean = [
			'schema_version'   => 1,
			'enabled'          => ! empty( $merged['enabled'] ),
			'provider'         => $provider,
			'endpoint'         => $profiles[ $provider ]['endpoint'],
			'buckets'          => $profiles[ $provider ]['buckets'],
			'profiles'         => $profiles,
			'path_prefix'      => self::path_prefix(),
			'media'            => [
				'offload'     => ! empty( $merged['media']['offload'] ),
				'local_files' => in_array( $merged['media']['local_files'], self::LOCAL_FILE_LEVELS, true ) ? $merged['media']['local_files'] : 'keep',
			],
			'signed_url_ttl'   => max( 1, min( 604800, (int) $merged['signed_url_ttl'] ) ),
			'private_delivery' => in_array( $merged['private_delivery'], [ 'redirect', 'stream' ], true ) ? $merged['private_delivery'] : 'redirect',
			'contexts'         => self::sanitize_contexts( is_array( $merged['contexts'] ) ? $merged['contexts'] : [] ),
			'backups'          => [
				'enabled'   => ! empty( $merged['backups']['enabled'] ),
				'frequency' => in_array( $merged['backups']['frequency'] ?? '', [ 'daily', 'weekly' ], true ) ? $merged['backups']['frequency'] : 'daily',
				'keep_days' => in_array( (int) ( $merged['backups']['keep_days'] ?? 0 ), [ 7, 14, 30, 90 ], true ) ? (int) $merged['backups']['keep_days'] : 30,
			],
			'theme'            => [
				'default_mode' => 'dark' === ( $merged['theme']['default_mode'] ?? '' ) ? 'dark' : 'light',
			],
		];

		update_option( self::OPTION, $clean, true );

		/**
		 * Fires after the settings are saved.
		 *
		 * @param array $clean    New settings.
		 * @param array $current  Settings before the save.
		 */
		do_action( 'gemcloudstorage/settings_saved', $clean, $current );

		return self::get();
	}

	/**
	 * The folder public media keys start with: the uploads folder's path on
	 * this site, so a file's cloud address mirrors its local one
	 * (wp-content/uploads/2026/10/photo.jpg). On a multisite subsite it
	 * includes sites/<id>, so subsites never overwrite each other's files.
	 */
	public static function path_prefix(): string {
		$uploads = wp_get_upload_dir();
		$path    = (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH );
		$home    = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		// A site installed in a subfolder (example.com/blog/) keeps its
		// uploads path without the subfolder.
		if ( '' !== trim( $home, '/' ) && 0 === strpos( $path, untrailingslashit( $home ) . '/' ) ) {
			$path = substr( $path, strlen( untrailingslashit( $home ) ) );
		}

		$prefix = trim( (string) preg_replace( '#[^A-Za-z0-9/_.-]#', '', $path ), '/' );

		/**
		 * Filters the folder public media keys start with. Changing it only
		 * affects files uploaded afterwards; stored files keep their keys.
		 *
		 * @param string $prefix For example "wp-content/uploads".
		 */
		return trim( (string) apply_filters( 'gemcloudstorage/path_prefix', '' !== $prefix ? $prefix : 'wp-content/uploads' ), '/' );
	}

	/**
	 * One provider's endpoint and buckets, cleaned.
	 *
	 * @return array{endpoint:array,buckets:array}
	 */
	private static function sanitize_connection( string $provider, array $endpoint, array $buckets ): array {
		$endpoint = self::merge( self::defaults()['endpoint'], $endpoint );
		$region   = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $endpoint['region'] ) );

		return [
			'endpoint' => [
				'account_id'   => preg_replace( '/[^a-f0-9]/i', '', (string) $endpoint['account_id'] ),
				'jurisdiction' => in_array( $endpoint['jurisdiction'], [ '', 'eu', 'fedramp' ], true ) ? $endpoint['jurisdiction'] : '',
				'region'       => '' !== $region ? $region : ( 'r2' === $provider ? 'auto' : 'us-east-1' ),
				'url'          => esc_url_raw( untrailingslashit( (string) $endpoint['url'] ), [ 'https', 'http' ] ),
				'path_style'   => ! empty( $endpoint['path_style'] ),
			],
			'buckets'  => [
				'public'  => [
					'name'       => self::bucket_name( $buckets['public']['name'] ?? '' ),
					'public_url' => esc_url_raw( untrailingslashit( (string) ( $buckets['public']['public_url'] ?? '' ) ), [ 'https', 'http' ] ),
				],
				'private' => [
					'name' => self::bucket_name( $buckets['private']['name'] ?? '' ),
				],
			],
		];
	}

	/**
	 * A configured connection: provider, a bucket and both keys.
	 */
	public static function is_configured(): bool {
		$s = self::get();

		if ( '' === $s['buckets']['public']['name'] && '' === $s['buckets']['private']['name'] ) {
			return false;
		}
		if ( 'r2' === $s['provider'] && '' === $s['endpoint']['account_id'] ) {
			return false;
		}
		if ( 'custom' === $s['provider'] && '' === $s['endpoint']['url'] ) {
			return false;
		}

		return Security\Credentials::has_keys();
	}

	public static function bucket_for( string $visibility ): string {
		$s    = self::get();
		$name = 'private' === $visibility ? $s['buckets']['private']['name'] : $s['buckets']['public']['name'];

		// One bucket configured: it serves both kinds. Private files then still
		// get signed URLs, but the admin is warned that the bucket must not be public.
		if ( '' === $name ) {
			$name = 'private' === $visibility ? $s['buckets']['public']['name'] : $s['buckets']['private']['name'];
		}

		return $name;
	}

	private static function bucket_name( $name ): string {
		return substr( preg_replace( '/[^a-z0-9.-]/', '', strtolower( (string) $name ) ), 0, 63 );
	}

	private static function sanitize_contexts( array $contexts ): array {
		$clean = [];
		foreach ( $contexts as $key => $context ) {
			$key = preg_replace( '/[^a-z0-9._-]/', '', strtolower( (string) $key ) );
			if ( '' === $key || ! is_array( $context ) ) {
				continue;
			}
			$kinds = [];
			foreach ( (array) ( $context['kinds'] ?? [] ) as $kind => $on ) {
				$kind = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $kind ) );
				if ( '' !== $kind ) {
					$kinds[ $kind ] = (bool) $on;
				}
			}
			$clean[ $key ] = [
				'enabled'    => ! empty( $context['enabled'] ),
				'visibility' => 'private' === ( $context['visibility'] ?? '' ) ? 'private' : 'public',
				'kinds'      => $kinds,
			];
		}
		return $clean;
	}

	/**
	 * Recursive merge where associative arrays merge and lists replace.
	 */
	private static function merge( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && self::is_assoc( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	private static function is_assoc( array $arr ): bool {
		return [] === $arr || array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}
}

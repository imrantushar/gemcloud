<?php
namespace GemCloudStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A context is a kind of file a plugin stores: the media library, paid
 * downloads, course materials, chat attachments. It sets the default bucket
 * (public or private) and the object key layout. The admin can switch a
 * context off, or change its visibility, in Settings → Contexts.
 */
final class Contexts {

	const MEDIA         = 'media';
	const MEDIA_PRIVATE = 'media.private';

	/** @var array<string,array> */
	private static array $contexts = [];

	public static function register_defaults(): void {
		self::register( self::MEDIA, [
			'label'       => __( 'Media library', 'gemcloudstorage' ),
			'description' => __( 'Images and every generated size, video, audio and documents uploaded to the media library.', 'gemcloudstorage' ),
			'visibility'  => 'public',
			'path'        => '{prefix}/{file}',
			'source'      => 'GemCloudStorage',
			'locked'      => [ 'visibility' ],
		] );

		self::register( self::MEDIA_PRIVATE, [
			'label'       => __( 'Private media files', 'gemcloudstorage' ),
			'description' => __( 'Media library files marked "Private". Served through a signed URL after an access check.', 'gemcloudstorage' ),
			'visibility'  => 'private',
			'path'        => 'private/media/{Y}/{m}/{uuid}/{filename}',
			'source'      => 'GemCloudStorage',
			'locked'      => [ 'visibility' ],
		] );
	}

	/**
	 * @param array $args label, description, visibility (public|private), path
	 *                    template ({prefix} {file} {Y} {m} {uuid} {filename}
	 *                    {context} {id}), max_size in bytes, source.
	 */
	public static function register( string $key, array $args ): void {
		$key = strtolower( preg_replace( '/[^A-Za-z0-9._-]/', '', $key ) );
		if ( '' === $key ) {
			return;
		}

		self::$contexts[ $key ] = wp_parse_args( $args, [
			'key'         => $key,
			'label'       => $key,
			'description' => '',
			'visibility'  => 'public',
			'path'        => 'public' === ( $args['visibility'] ?? 'public' ) ? '{prefix}/{context}/{Y}/{m}/{filename}' : 'private/{context}/{Y}/{m}/{uuid}/{filename}',
			'max_size'    => 0,
			'source'      => '',
			'locked'      => [],
			'kinds'       => [],
		] );
		self::$contexts[ $key ]['key'] = $key;
	}

	public static function exists( string $key ): bool {
		return isset( self::$contexts[ $key ] );
	}

	/**
	 * The registered context with the admin's overrides applied.
	 */
	public static function get( string $key ): ?array {
		if ( ! isset( self::$contexts[ $key ] ) ) {
			return null;
		}

		$context   = self::$contexts[ $key ];
		// Keys contain dots ("media.private"), so not Settings::value()'s dotted path.
		$overrides = Settings::get()['contexts'][ $key ] ?? [];
		$overrides = is_array( $overrides ) ? $overrides : [];

		$context['enabled'] = ! isset( $overrides['enabled'] ) || (bool) $overrides['enabled'];

		// Kinds of file inside the context, each on unless switched off.
		foreach ( (array) $context['kinds'] as $kind => $info ) {
			$context['kinds'][ $kind ]['enabled'] = ! isset( $overrides['kinds'][ $kind ] ) || (bool) $overrides['kinds'][ $kind ];
		}
		if ( isset( $overrides['visibility'] ) && ! in_array( 'visibility', $context['locked'], true ) ) {
			$context['visibility'] = 'private' === $overrides['visibility'] ? 'private' : 'public';
		}

		return $context;
	}

	public static function enabled( string $key ): bool {
		$context = self::get( $key );
		return $context ? $context['enabled'] : false;
	}

	/**
	 * Whether one kind of file in a context is switched on (and the context).
	 */
	public static function kind_enabled( string $key, string $kind ): bool {
		$context = self::get( $key );
		return $context && $context['enabled'] && ! empty( $context['kinds'][ $kind ]['enabled'] );
	}

	/**
	 * @param bool $internal Include contexts managed on their own screen
	 *                       (database backups).
	 */
	public static function all( bool $internal = false ): array {
		$out = [];
		foreach ( array_keys( self::$contexts ) as $key ) {
			$context = self::get( $key );
			if ( $internal || empty( $context['internal'] ) ) {
				$out[] = $context;
			}
		}
		return $out;
	}
}

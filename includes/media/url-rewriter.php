<?php
namespace GemCloud\Media;

use GemCloud\Items\Item;
use GemCloud\Items\ItemRepository;
use GemCloud\Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Swaps local media URLs for cloud ones at render time, per variant and
 * only once that variant is synced. A failed or pending upload therefore
 * never produces a broken image: the local file keeps serving.
 */
final class UrlRewriter {

	public static function init(): void {
		add_filter( 'wp_get_attachment_url', [ self::class, 'attachment_url' ], 99, 2 );
		add_filter( 'wp_get_attachment_image_src', [ self::class, 'image_src' ], 99, 2 );
		add_filter( 'wp_calculate_image_srcset', [ self::class, 'srcset' ], 99, 5 );
		add_filter( 'wp_content_img_tag', [ self::class, 'content_img_tag' ], 99, 3 );
		add_filter( 'wp_prepare_attachment_for_js', [ self::class, 'prepare_for_js' ], 99, 2 );
		add_action( 'the_posts', [ self::class, 'prime_posts' ], 10, 1 );
	}

	public static function attachment_url( $url, $attachment_id ) {
		if ( ! $url || ! Manager::available() ) {
			return $url;
		}
		$item = ItemRepository::find_variant( 'attachment', (int) $attachment_id );
		return $item ? self::url_for( $item, (string) $url ) : $url;
	}

	public static function image_src( $image, $attachment_id ) {
		if ( is_array( $image ) && ! empty( $image[0] ) && Manager::available() ) {
			$image[0] = self::rewrite( (string) $image[0], (int) $attachment_id );
		}
		return $image;
	}

	public static function srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! is_array( $sources ) || ! Manager::available() ) {
			return $sources;
		}
		foreach ( $sources as $width => $source ) {
			if ( ! empty( $source['url'] ) ) {
				$sources[ $width ]['url'] = self::rewrite( (string) $source['url'], (int) $attachment_id );
			}
		}
		return $sources;
	}

	/**
	 * Post content stores local URLs; swap each synced variant's URL.
	 */
	public static function content_img_tag( $html, $context, $attachment_id ) {
		if ( ! $attachment_id || ! Manager::available() ) {
			return $html;
		}

		$replace = [];
		foreach ( ItemRepository::for_source( 'attachment', (int) $attachment_id ) as $item ) {
			if ( ! $item->is_synced() ) {
				continue;
			}
			$local = self::local_url( (int) $attachment_id, wp_basename( $item->local_path ) );
			$cloud = self::cloud_url( $item );
			if ( $local && $cloud ) {
				$replace[ $local ] = $cloud;
			}
		}

		return $replace ? strtr( (string) $html, $replace ) : $html;
	}

	public static function prepare_for_js( $response, $attachment ) {
		if ( ! is_array( $response ) || empty( $response['sizes'] ) || ! Manager::available() ) {
			return $response;
		}
		foreach ( $response['sizes'] as $size => $data ) {
			if ( ! empty( $data['url'] ) ) {
				$response['sizes'][ $size ]['url'] = self::rewrite( (string) $data['url'], (int) $attachment->ID );
			}
		}
		return $response;
	}

	/**
	 * Load the items of every attachment in a query at once.
	 */
	public static function prime_posts( $posts ) {
		if ( is_array( $posts ) && Manager::available() ) {
			$ids = [];
			foreach ( $posts as $post ) {
				if ( $post instanceof \WP_Post ) {
					if ( 'attachment' === $post->post_type ) {
						$ids[] = $post->ID;
					}
					$thumb = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
					if ( $thumb ) {
						$ids[] = $thumb;
					}
				}
			}
			ItemRepository::prime( 'attachment', $ids );
		}
		return $posts;
	}

	/**
	 * The right URL for whichever variant $url points at: cloud when synced,
	 * local otherwise.
	 */
	public static function rewrite( string $url, int $attachment_id ): string {
		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$basename = rawurldecode( wp_basename( $path ) );

		foreach ( ItemRepository::for_source( 'attachment', $attachment_id ) as $item ) {
			if ( wp_basename( $item->local_path ) === $basename ) {
				$local = self::local_url( $attachment_id, $basename );
				return self::url_for( $item, $local ? $local : $url );
			}
		}

		// Not a stored variant: make sure it still points at the local file,
		// since WordPress builds size URLs from the (rewritten) original URL.
		$local = self::local_url( $attachment_id, $basename );
		return $local ? $local : $url;
	}

	public static function cloud_url( Item $item ): ?string {
		// Also while a newly private file is still moving out of the public bucket.
		if ( $item->is_private() || ( 'attachment' === $item->source_type && MediaHooks::is_private( $item->source_id ) ) ) {
			return PrivateDelivery::url( $item );
		}
		return Manager::public_url( $item );
	}

	private static function url_for( Item $item, string $fallback ): string {
		if ( ! $item->is_synced() ) {
			return $fallback;
		}
		$cloud = self::cloud_url( $item );
		return $cloud ? $cloud : $fallback;
	}

	/**
	 * The attachment a URL points at, whichever form it is in: the local
	 * uploads URL, a public cloud URL, or a private /?gemcloud_file= link. Pages
	 * and the media modal hand out the cloud forms, so plugins that store a
	 * picked file's URL may have saved one of them.
	 */
	public static function attachment_for_url( string $url ): int {
		$url = trim( $url );
		if ( '' === $url ) {
			return 0;
		}
		if ( '/' === $url[0] && '/' !== ( $url[1] ?? '' ) ) {
			$url = home_url( $url );
		}

		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( $query ) {
			parse_str( $query, $args );
			if ( ! empty( $args[ PrivateDelivery::QUERY_VAR ] ) ) {
				$item = ItemRepository::find( absint( $args[ PrivateDelivery::QUERY_VAR ] ) );
				return $item && 'attachment' === $item->source_type ? $item->source_id : 0;
			}
		}

		$item = self::item_for_public_url( $url );
		if ( $item ) {
			return 'attachment' === $item->source_type ? $item->source_id : 0;
		}

		// attachment_url_to_postid() only knows originals; try the size's
		// original too ("photo-300x200.jpg" → "photo.jpg").
		$id = (int) attachment_url_to_postid( $url );
		if ( ! $id ) {
			$id = (int) attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', strtok( $url, '?' ) ) );
		}
		return $id;
	}

	/**
	 * A cloud URL turned back into the local uploads URL it stands for, so
	 * stored data never depends on the plugin. Other URLs are returned as is.
	 */
	public static function canonical_url( string $url ): string {
		$item = self::item_for_url( $url );
		if ( ! $item ) {
			return $url;
		}

		if ( 'attachment' === $item->source_type ) {
			$local = self::local_url( $item->source_id, wp_basename( $item->local_path ) );
			return $local ? $local : $url;
		}

		// A file a product keeps outside the media library, inside uploads.
		$relative = \GemCloud\Keys::relative_to_uploads( $item->local_path );
		if ( '' === $relative ) {
			return $url;
		}
		return trailingslashit( wp_get_upload_dir()['baseurl'] ) . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
	}

	/**
	 * The stored object a cloud URL (public address or /?gemcloud_file= link)
	 * points at, or null for any other URL.
	 */
	public static function item_for_url( string $url ): ?Item {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( $query ) {
			parse_str( $query, $args );
			if ( ! empty( $args[ PrivateDelivery::QUERY_VAR ] ) ) {
				return ItemRepository::find( absint( $args[ PrivateDelivery::QUERY_VAR ] ) );
			}
		}
		return self::item_for_public_url( $url );
	}

	private static function item_for_public_url( string $url ): ?Item {
		if ( ! Manager::available() ) {
			return null;
		}
		$path = strtok( $url, '?' );
		foreach ( array_unique( array_filter( [ \GemCloud\Settings::value( 'buckets.public.name', '' ), \GemCloud\Settings::value( 'buckets.private.name', '' ) ] ) ) as $bucket ) {
			$base = Manager::public_base_url( $bucket );
			if ( $base && 0 === strpos( $path, $base . '/' ) ) {
				return ItemRepository::find_by_key( rawurldecode( substr( $path, strlen( $base ) + 1 ) ) );
			}
		}
		return null;
	}

	private static function local_url( int $attachment_id, string $basename ): ?string {
		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $file || '' === $basename ) {
			return null;
		}
		$dir = dirname( $file );
		$dir = '.' === $dir ? '' : trailingslashit( $dir );

		return trailingslashit( wp_get_upload_dir()['baseurl'] ) . $dir . rawurlencode( $basename );
	}
}

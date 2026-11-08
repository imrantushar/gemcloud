<?php
namespace GemCloud;

use GemCloud\Http\StorageException;
use GemCloud\Items\Item;
use GemCloud\Items\ItemRepository;
use GemCloud\Jobs\Queue;
use GemCloud\Media\PrivateDelivery;
use GemCloud\Providers\Endpoint;
use GemCloud\Providers\S3CompatibleDriver;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The public API other plugins call. Every method degrades to local
 * behaviour when storage isn't configured, so callers need one code path.
 *
 *   Manager::register_context( 'storeengine.download', [ 'visibility' => 'private' ] );
 *   Manager::upload( $path, Ref::of( 'storeengine.download', 42 ) );
 *   $url = Manager::temporary_url( Ref::of( 'storeengine.download', 42 ), [ 'filename' => 'Guide.pdf' ] );
 */
final class Manager {

	const FEATURES = [ 'upload', 'url', 'temporary_url', 'stream', 'delete', 'status', 'contexts', 'multipart' ];

	private static ?S3CompatibleDriver $driver = null;

	public static function version(): string {
		return GEMCLOUD_VERSION;
	}

	public static function supports( string $feature ): bool {
		return in_array( $feature, self::FEATURES, true );
	}

	/**
	 * Configured and switched on.
	 */
	public static function available(): bool {
		return (bool) Settings::value( 'enabled', false ) && Settings::is_configured();
	}

	public static function driver(): S3CompatibleDriver {
		if ( null === self::$driver ) {
			self::$driver = S3CompatibleDriver::from_settings();
		}
		return self::$driver;
	}

	public static function reset_driver(): void {
		self::$driver = null;
	}

	public static function register_context( string $key, array $args ): void {
		Contexts::register( $key, $args );
	}

	/**
	 * Hand a local file to storage. Queued by default; 'sync' => true uploads
	 * inside the request (meant for WP-CLI).
	 *
	 * @param array $opts variant, filename (download name), visibility, sync.
	 * @return Item|WP_Error
	 */
	public static function upload( string $path, Ref $ref, array $opts = [] ) {
		if ( ! self::available() ) {
			return new WP_Error( 'gemcloud_unavailable', __( 'Cloud storage is not configured.', 'gemcloud' ) );
		}

		$context = Contexts::get( $ref->context );
		if ( ! $context ) {
			return new WP_Error( 'gemcloud_unknown_context', sprintf( /* translators: %s: context key */ __( 'Unknown storage context "%s".', 'gemcloud' ), $ref->context ) );
		}
		if ( ! $context['enabled'] ) {
			return new WP_Error( 'gemcloud_context_disabled', __( 'This kind of file is switched off in the storage settings.', 'gemcloud' ) );
		}
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'gemcloud_missing_file', __( 'The file to upload does not exist.', 'gemcloud' ) );
		}

		$size = (int) filesize( $path );
		if ( $context['max_size'] > 0 && $size > $context['max_size'] ) {
			return new WP_Error( 'gemcloud_too_large', __( 'The file is larger than this context allows.', 'gemcloud' ) );
		}

		$variant    = $opts['variant'] ?? 'original';
		$visibility = $opts['visibility'] ?? $context['visibility'];
		$filename   = $opts['filename'] ?? wp_basename( $path );
		$existing   = ItemRepository::find_variant( $ref->context, $ref->id, $variant );

		// Re-uploading the same file to the same place is a no-op.
		if ( $existing && $existing->local_path === $path && $existing->is_synced() && $existing->size_bytes === $size ) {
			return $existing;
		}

		// Reuse the random segment, so re-uploading the same file replaces its
		// object instead of leaving the old one behind.
		$uuid = $existing->meta['uuid'] ?? str_replace( '-', '', wp_generate_uuid4() );

		$key = Keys::build( $context['path'], [
			'context'  => $ref->context,
			'id'       => $ref->id,
			'filename' => $filename,
			'file'     => Keys::relative_to_uploads( $path ) ?: $filename,
			'uuid'     => $uuid,
		] );

		// A row that was ever sent to the bucket may have left an object there.
		if ( $existing && $existing->object_key !== $key && ( $existing->is_synced() || $existing->attempts > 0 || Item::FAILED === $existing->status ) ) {
			self::queue_delete_object( $existing );
		}

		$item = ItemRepository::upsert( [
			'source_type' => $ref->context,
			'source_id'   => $ref->id,
			'variant'     => $variant,
			'provider'    => Settings::value( 'provider' ),
			'bucket'      => Settings::bucket_for( $visibility ),
			'object_key'  => $key,
			'visibility'  => $visibility,
			'mime_type'   => self::mime_type( $path, $filename ),
			'size_bytes'  => $size,
			'status'      => Item::PENDING,
			'local_state' => 'present',
			'local_path'  => $path,
			'attempts'    => 0,
			'last_error'  => '',
			'meta'        => [
				'context'  => $ref->context,
				'filename' => $filename,
				'uuid'     => $uuid,
			],
		] );

		if ( ! empty( $opts['sync'] ) ) {
			Jobs\UploadJob::run( $item->id );
			return ItemRepository::find( $item->id ) ?? $item;
		}

		Queue::enqueue( Queue::UPLOAD, [ $item->id ] );

		return $item;
	}

	/**
	 * Public URL once the object is synced, or null so the caller keeps its
	 * local URL. Private objects return null: use temporary_url().
	 */
	public static function url( Ref $ref, string $variant = 'original' ): ?string {
		$item = ItemRepository::find_variant( $ref->context, $ref->id, $variant );
		if ( ! $item || ! $item->is_synced() || $item->is_private() ) {
			return null;
		}
		return self::public_url( $item );
	}

	public static function public_url( Item $item ): ?string {
		$base = self::public_base_url( $item->bucket );
		if ( ! $base ) {
			return null;
		}

		/**
		 * Filters the public URL of a synced object.
		 *
		 * @param string $url  URL.
		 * @param Item   $item The object.
		 */
		return (string) apply_filters( 'gemcloud/url', $base . '/' . Endpoint::encode_key( $item->object_key ), $item );
	}

	/**
	 * Base URL public objects are served from: the custom domain or CDN when
	 * set, otherwise the provider's own public address where one exists.
	 */
	public static function public_base_url( string $bucket ): ?string {
		$settings = Settings::get();
		$custom   = $settings['buckets']['public']['public_url'];

		if ( $custom && $bucket === $settings['buckets']['public']['name'] ) {
			return untrailingslashit( $custom );
		}

		switch ( $settings['provider'] ) {
			case 'aws':
				$endpoint = self::driver()->endpoint();
				list( $host, $path ) = $endpoint->locate( $bucket );
				return 'https://' . $host . untrailingslashit( $path );
			case 'custom':
				$endpoint = self::driver()->endpoint();
				list( $host, $path ) = $endpoint->locate( $bucket );
				return $endpoint->scheme() . '://' . $host . untrailingslashit( $path );
			default:
				// R2 serves public files only through a custom domain or r2.dev.
				return null;
		}
	}

	/**
	 * A short-lived signed URL. Call it at the moment of the click, after your
	 * own access checks, and redirect to it. Never print it into a page.
	 *
	 * @param array $opts ttl (seconds), filename, disposition (attachment|inline), variant, content_type.
	 * @return string|WP_Error
	 */
	public static function temporary_url( Ref $ref, array $opts = [] ) {
		$item = ItemRepository::find_variant( $ref->context, $ref->id, $opts['variant'] ?? 'original' );
		if ( ! $item ) {
			return new WP_Error( 'gemcloud_not_found', __( 'This file is not in cloud storage.', 'gemcloud' ) );
		}
		return self::signed_url_for( $item, $opts );
	}

	/**
	 * @return string|WP_Error
	 */
	public static function signed_url_for( Item $item, array $opts = [] ) {
		if ( ! self::available() ) {
			return new WP_Error( 'gemcloud_unavailable', __( 'Cloud storage is not configured.', 'gemcloud' ) );
		}
		if ( ! $item->is_synced() ) {
			return new WP_Error( 'gemcloud_not_synced', __( 'This file has not finished uploading.', 'gemcloud' ) );
		}

		$ttl = (int) ( $opts['ttl'] ?? Settings::value( 'signed_url_ttl', 300 ) );

		/**
		 * Filters the lifetime of a signed URL, in seconds (1 to 604,800).
		 *
		 * @param int  $ttl  Seconds.
		 * @param Item $item The object.
		 */
		$ttl = (int) apply_filters( 'gemcloud/temporary_url_ttl', $ttl, $item );

		$filename    = $opts['filename'] ?? $item->filename();
		$disposition = 'inline' === ( $opts['disposition'] ?? 'attachment' ) ? 'inline' : 'attachment';
		$response    = [
			'response-content-disposition' => $disposition . '; filename="' . str_replace( '"', '', $filename ) . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ),
		];

		$content_type = $opts['content_type'] ?? $item->mime_type;
		if ( $content_type ) {
			$response['response-content-type'] = $content_type;
		}

		try {
			return self::driver()->presign_get( $item->bucket, $item->object_key, $ttl, $response );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gemcloud_sign_failed', $e->getMessage() );
		}
	}

	/**
	 * Proxy the object through PHP, with Range support, for sites that can't
	 * redirect to the provider. Costs server bandwidth.
	 */
	public static function stream( Ref $ref, array $opts = [] ): void {
		$item = ItemRepository::find_variant( $ref->context, $ref->id, $opts['variant'] ?? 'original' );
		if ( ! $item ) {
			status_header( 404 );
			exit;
		}
		PrivateDelivery::stream_item( $item, $opts );
	}

	/**
	 * Delete every variant of an object from the bucket. Queued.
	 */
	public static function delete( Ref $ref ): void {
		foreach ( ItemRepository::for_source( $ref->context, $ref->id, true ) as $item ) {
			self::queue_delete_object( $item );
		}
	}

	/**
	 * pending, uploading, synced, failed, deleting, or none.
	 */
	public static function status( Ref $ref, string $variant = 'original' ): string {
		$item = ItemRepository::find_variant( $ref->context, $ref->id, $variant );
		return $item ? $item->status : 'none';
	}

	public static function item( Ref $ref, string $variant = 'original' ): ?Item {
		return ItemRepository::find_variant( $ref->context, $ref->id, $variant );
	}

	/**
	 * Mark a row for deletion and queue the delete job. A row never uploaded
	 * is simply removed.
	 */
	public static function queue_delete_object( Item $item ): void {
		if ( '' === $item->object_key || in_array( $item->status, [ Item::PENDING, Item::FAILED ], true ) && empty( $item->meta['upload_id'] ) && 0 === $item->attempts ) {
			ItemRepository::delete( $item->id );
			return;
		}

		// Free the (source, variant) slot so a replacement can be recorded,
		// while the row keeps the bucket and key the delete job needs.
		ItemRepository::update( $item->id, [
			'status'      => Item::DELETING,
			'source_type' => '~' . substr( $item->source_type, 0, 46 ),
			'variant'     => substr( $item->variant . '#' . $item->id, 0, 64 ),
		] );

		Queue::enqueue( Queue::DELETE );
	}

	public static function mime_type( string $path, string $filename = '' ): string {
		$type = wp_check_filetype( '' !== $filename ? $filename : $path );
		if ( ! empty( $type['type'] ) ) {
			return $type['type'];
		}
		if ( function_exists( 'mime_content_type' ) && is_readable( $path ) ) {
			$detected = mime_content_type( $path );
			if ( $detected ) {
				return $detected;
			}
		}
		return 'application/octet-stream';
	}

	/**
	 * Translate a provider error into WP_Error for callers.
	 */
	public static function to_wp_error( StorageException $e ): WP_Error {
		return new WP_Error( 'gemcloud_' . $e->error_code(), $e->getMessage(), $e->to_array() );
	}
}

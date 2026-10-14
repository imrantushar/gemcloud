<?php
namespace GemCloudStorage\Media;

use GemCloudStorage\Contexts;
use GemCloudStorage\Integrations\Integrations;
use GemCloudStorage\Items\Item;
use GemCloudStorage\Items\ItemRepository;
use GemCloudStorage\Jobs\DownloadBackJob;
use GemCloudStorage\Jobs\Queue;
use GemCloudStorage\Keys;
use GemCloudStorage\Manager;
use GemCloudStorage\Ref;
use GemCloudStorage\Settings;
use GemCloudStorage\Utils\Conflicts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media library lifecycle: offload after WordPress has written every image
 * size, record each object in the items table, rewrite URLs at render time
 * (never in the database). Switching the plugin off is a full rollback.
 */
final class MediaHooks {

	/** Set by an admin ticking "Private". */
	const PRIVATE_META = '_gemcloudstorage_private';

	/**
	 * Products that made the file private, e.g. ['academy.course_files'].
	 * It stays private after the product stops using it (a deleted lesson,
	 * a removed submission) until an admin unticks "Private": a file that
	 * was once protected never becomes public by itself.
	 */
	const PRIVATE_BY_META = '_gemcloudstorage_private_by';

	public static function init(): void {
		// Objects already stored must still be cleaned up and brought back
		// when needed, even while another offload plugin owns the media URLs.
		add_action( 'delete_attachment', [ self::class, 'on_delete' ], 10, 1 );
		add_filter( 'get_attached_file', [ self::class, 'attached_file' ], 99, 2 );

		if ( Conflicts::media_blocked() ) {
			return;
		}

		add_filter( 'wp_generate_attachment_metadata', [ self::class, 'on_metadata' ], 99, 2 );
		add_filter( 'wp_update_attachment_metadata', [ self::class, 'on_metadata' ], 99, 2 );

		UrlRewriter::init();

		if ( is_admin() ) {
			add_filter( 'attachment_fields_to_edit', [ self::class, 'fields_to_edit' ], 20, 2 );
			add_filter( 'attachment_fields_to_save', [ self::class, 'fields_to_save' ], 20, 2 );
			add_filter( 'manage_media_columns', [ self::class, 'columns' ] );
			add_action( 'manage_media_custom_column', [ self::class, 'column' ], 10, 2 );
		}
	}

	public static function offload_enabled(): bool {
		return Manager::available() && (bool) Settings::value( 'media.offload', true ) && Contexts::enabled( Contexts::MEDIA );
	}

	/**
	 * Runs on both metadata filters; sync_attachment() is idempotent, so the
	 * second call finds nothing to do.
	 */
	public static function on_metadata( $metadata, $attachment_id ) {
		if ( is_array( $metadata ) && self::offload_enabled() ) {
			self::sync_attachment( (int) $attachment_id, $metadata );
		}
		return $metadata;
	}

	/**
	 * Make the items table match the attachment's files: queue new or changed
	 * variants, delete objects for variants that are gone.
	 */
	public static function sync_attachment( int $attachment_id, ?array $metadata = null ): void {
		if ( ! Manager::available() || 'attachment' !== get_post_type( $attachment_id ) ) {
			return;
		}

		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $file ) {
			return;
		}

		$metadata    = $metadata ?? wp_get_attachment_metadata( $attachment_id, true );
		$metadata    = is_array( $metadata ) ? $metadata : [];
		$variants    = self::variants( $file, $metadata );
		$private     = self::is_private( $attachment_id );
		$context_key = $private ? Contexts::MEDIA_PRIVATE : Contexts::MEDIA;
		$context     = Contexts::get( $context_key );

		if ( ! $context || ! $context['enabled'] ) {
			return;
		}

		$visibility = $context['visibility'];
		$bucket     = Settings::bucket_for( $visibility );
		$basedir    = trailingslashit( wp_get_upload_dir()['basedir'] );
		$existing   = ItemRepository::for_source( 'attachment', $attachment_id, true );
		$uuid       = $existing['original']->meta['uuid'] ?? str_replace( '-', '', wp_generate_uuid4() );
		$time       = (int) get_post_time( 'U', true, $attachment_id );

		foreach ( $variants as $variant => $relative ) {
			$path = $basedir . $relative;
			$key  = Keys::build( $context['path'], [
				'context'  => 'media',
				'id'       => $attachment_id,
				'filename' => wp_basename( $relative ),
				'file'     => $relative,
				'uuid'     => $uuid,
				'time'     => $time ? $time : time(),
			] );

			$item = $existing[ $variant ] ?? null;

			if ( $item && $item->object_key === $key && $item->bucket === $bucket ) {
				if ( $item->local_path !== $path ) {
					ItemRepository::update( $item->id, [ 'local_path' => $path ] );
				}
				if ( Item::PENDING === $item->status ) {
					Queue::enqueue( Queue::UPLOAD, [ $item->id ] );
				}
				continue;
			}

			// Moving an offloaded file (e.g. to the private bucket) needs it on disk first.
			if ( $item && ! file_exists( $path ) && $item->is_synced() && 'removed' === $item->local_state && $item->local_path === $path ) {
				DownloadBackJob::restore( $item );
			}

			if ( ! file_exists( $path ) ) {
				continue;
			}

			if ( $item ) {
				Manager::queue_delete_object( $item );
			}

			$new = ItemRepository::upsert( [
				'source_type' => 'attachment',
				'source_id'   => $attachment_id,
				'variant'     => $variant,
				'provider'    => Settings::value( 'provider' ),
				'bucket'      => $bucket,
				'object_key'  => $key,
				'visibility'  => $visibility,
				'mime_type'   => Manager::mime_type( $path ),
				'size_bytes'  => (int) filesize( $path ),
				'etag'        => '',
				'status'      => Item::PENDING,
				'local_state' => 'present',
				'local_path'  => $path,
				'attempts'    => 0,
				'last_error'  => '',
				'meta'        => [
					'context'  => $context_key,
					'filename' => wp_basename( $relative ),
					'uuid'     => $uuid,
				],
			] );

			Queue::enqueue( Queue::UPLOAD, [ $new->id ] );
		}

		foreach ( $existing as $variant => $item ) {
			if ( ! isset( $variants[ $variant ] ) ) {
				Manager::queue_delete_object( $item );
			}
		}

		ItemRepository::forget( 'attachment', $attachment_id );
	}

	// ── Privacy ─────────────────────────────────────────────────────────────

	public static function is_private( int $attachment_id ): bool {
		return (bool) get_post_meta( $attachment_id, self::PRIVATE_META, true ) || [] !== self::private_by( $attachment_id );
	}

	/**
	 * @return string[] integration keys
	 */
	public static function private_by( int $attachment_id ): array {
		$by = get_post_meta( $attachment_id, self::PRIVATE_BY_META, true );
		return is_array( $by ) ? array_values( array_filter( $by, 'is_string' ) ) : [];
	}

	/**
	 * Whether a product already made this file private and it is stored
	 * that way (or can't be stored at all right now).
	 */
	public static function is_protected( int $attachment_id, string $by ): bool {
		if ( ! in_array( $by, self::private_by( $attachment_id ), true ) ) {
			return false;
		}
		if ( ! self::offload_enabled() ) {
			return true;
		}
		$item = ItemRepository::find_variant( 'attachment', $attachment_id );
		return $item && $item->is_private();
	}

	/**
	 * A product needs this file private: record it, and move the file to the
	 * private bucket (uploaded, checked, then removed from the public one).
	 */
	public static function claim( int $attachment_id, string $by ): void {
		$list = self::private_by( $attachment_id );
		if ( ! in_array( $by, $list, true ) ) {
			$list[] = $by;
			update_post_meta( $attachment_id, self::PRIVATE_BY_META, $list );
		}

		if ( self::offload_enabled() ) {
			$item = ItemRepository::find_variant( 'attachment', $attachment_id, 'original' );
			if ( ! $item || ! $item->is_private() ) {
				self::sync_attachment( $attachment_id );
			}
		}
	}

	/**
	 * Variant name => path relative to uploads. A file used by two sizes is
	 * stored once, under the first.
	 *
	 * @return array<string,string>
	 */
	public static function variants( string $file, array $metadata ): array {
		$file = ltrim( $file, '/' );
		$dir  = dirname( $file );
		$dir  = '.' === $dir ? '' : trailingslashit( $dir );

		$variants = [ 'original' => $file ];

		if ( ! empty( $metadata['original_image'] ) ) {
			$variants['original_image'] = $dir . $metadata['original_image'];
		}

		foreach ( (array) ( $metadata['sizes'] ?? [] ) as $size => $data ) {
			if ( empty( $data['file'] ) ) {
				continue;
			}
			$relative = $dir . $data['file'];
			if ( in_array( $relative, $variants, true ) ) {
				continue;
			}
			$variants[ substr( (string) $size, 0, 64 ) ] = $relative;
		}

		return $variants;
	}

	public static function on_delete( $attachment_id ): void {
		if ( Manager::available() ) {
			Manager::delete( Ref::attachment( (int) $attachment_id ) );
		}
	}

	/**
	 * A removed original comes back when something asks for the file:
	 * image editing, thumbnail regeneration, plugins reading it from disk.
	 */
	public static function attached_file( $file, $attachment_id ) {
		if ( ! $file || file_exists( $file ) || ! self::download_on_demand() ) {
			return $file;
		}

		$wanted = wp_normalize_path( (string) $file );
		foreach ( ItemRepository::for_source( 'attachment', (int) $attachment_id ) as $item ) {
			if ( wp_normalize_path( $item->local_path ) === $wanted && $item->is_synced() && 'removed' === $item->local_state ) {
				DownloadBackJob::restore( $item, true );
				break;
			}
		}

		return $file;
	}

	private static function download_on_demand(): bool {
		$default = is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI );

		/**
		 * Whether a removed original may be downloaded when it is asked for.
		 * Off on ordinary front-end page views, which don't need the file.
		 *
		 * @param bool $default
		 */
		return (bool) apply_filters( 'gemcloudstorage/download_on_demand', $default );
	}

	// ── Media library UI ────────────────────────────────────────────────────

	public static function fields_to_edit( array $fields, \WP_Post $post ): array {
		if ( ! Manager::available() ) {
			return $fields;
		}

		$private = self::is_private( $post->ID );
		$label   = self::status_label( $post->ID );
		$uses    = Integrations::uses( $post->ID );

		$html  = '<p style="margin:0 0 6px">' . esc_html( $label ) . '</p>';
		$html .= '<label><input type="checkbox" name="attachments[' . (int) $post->ID . '][gemcloudstorage_private]" value="1" ' . checked( $private, true, false ) . disabled( (bool) $uses, true, false ) . ' /> ';
		$html .= esc_html__( 'Private: keep in the private bucket and serve through a signed URL after an access check', 'gemcloudstorage' ) . '</label>';

		if ( $uses ) {
			// Products decide while they use the file; the box can't be unticked.
			$html .= '<p class="description" style="margin:6px 0 0">' . esc_html__( 'Kept private because it is used by:', 'gemcloudstorage' ) . '</p><ul style="margin:4px 0 0 16px;list-style:disc">';
			foreach ( $uses as $key => $reasons ) {
				$integration = Integrations::get( $key );
				foreach ( array_slice( $reasons, 0, 5 ) as $reason ) {
					$html .= '<li>' . esc_html( ( $integration ? $integration->label() . ': ' : '' ) . $reason ) . '</li>';
				}
			}
			$html .= '</ul>';
		} elseif ( self::private_by( $post->ID ) ) {
			$html .= '<p class="description" style="margin:6px 0 0">' . esc_html__( 'Made private by a product that no longer uses it. It stays private until you untick the box.', 'gemcloudstorage' ) . '</p>';
		}

		$html .= '<input type="hidden" name="attachments[' . (int) $post->ID . '][gemcloudstorage_present]" value="1" />';

		$fields['gemcloudstorage'] = [
			'label' => __( 'Cloud storage', 'gemcloudstorage' ),
			'input' => 'html',
			'html'  => $html,
		];

		return $fields;
	}

	public static function fields_to_save( array $post, array $attachment ): array {
		if ( empty( $attachment['gemcloudstorage_present'] ) || empty( $post['ID'] ) ) {
			return $post;
		}

		self::set_private( (int) $post['ID'], ! empty( $attachment['gemcloudstorage_private'] ) );

		return $post;
	}

	/**
	 * An admin ticks or unticks "Private". Ignored while a product uses the
	 * file: the product decides then.
	 *
	 * @return bool Whether the choice was applied.
	 */
	public static function set_private( int $id, bool $want ): bool {
		if ( Integrations::uses( $id ) ) {
			return false;
		}

		if ( $want !== self::is_private( $id ) ) {
			if ( $want ) {
				update_post_meta( $id, self::PRIVATE_META, 1 );
			} else {
				delete_post_meta( $id, self::PRIVATE_META );
				delete_post_meta( $id, self::PRIVATE_BY_META );
			}
			if ( Manager::available() ) {
				self::sync_attachment( $id );
			}
		}
		return true;
	}

	public static function columns( array $columns ): array {
		$columns['gemcloudstorage'] = __( 'Cloud', 'gemcloudstorage' );
		return $columns;
	}

	public static function column( string $column, int $post_id ): void {
		if ( 'gemcloudstorage' !== $column ) {
			return;
		}
		$item  = ItemRepository::find_variant( 'attachment', $post_id );
		$color = [
			Item::SYNCED    => '#16A34A',
			Item::PENDING   => '#EAB308',
			Item::UPLOADING => '#006BFF',
			Item::FAILED    => '#DC2626',
		];
		$dot = $item ? ( $color[ $item->status ] ?? '#A2ADB9' ) : '#A2ADB9';
		printf(
			'<span title="%1$s" style="display:inline-flex;align-items:center;gap:6px"><span style="width:8px;height:8px;border-radius:50%%;background:%2$s"></span>%3$s</span>',
			esc_attr( $item && $item->last_error ? $item->last_error : '' ),
			esc_attr( $dot ),
			esc_html( self::status_label( $post_id ) )
		);
	}

	public static function status_label( int $attachment_id ): string {
		$item = ItemRepository::find_variant( 'attachment', $attachment_id );
		if ( ! $item ) {
			return __( 'Local only', 'gemcloudstorage' );
		}

		$labels = [
			Item::SYNCED    => __( 'In the cloud', 'gemcloudstorage' ),
			Item::PENDING   => __( 'Waiting to upload', 'gemcloudstorage' ),
			Item::UPLOADING => __( 'Uploading', 'gemcloudstorage' ),
			Item::FAILED    => __( 'Upload failed', 'gemcloudstorage' ),
			Item::DELETING  => __( 'Removing', 'gemcloudstorage' ),
		];

		$label = $labels[ $item->status ] ?? $item->status;
		if ( $item->is_private() ) {
			$label .= ' · ' . __( 'private', 'gemcloudstorage' );
		}
		if ( 'removed' === $item->local_state ) {
			$label .= ' · ' . __( 'not on this server', 'gemcloudstorage' );
		}
		return $label;
	}
}

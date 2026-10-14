<?php
namespace GemCloudStorage\Integrations;

use GemCloudStorage\Contexts;
use GemCloudStorage\Items\ItemRepository;
use GemCloudStorage\Manager;
use GemCloudStorage\Media\UrlRewriter;
use GemCloudStorage\Ref;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * StoreEngine: downloadable product files are private.
 *
 * A product's downloadable files go to the private bucket: media library
 * files are moved there, files kept outside the media library (for example
 * in storeengine_uploads) are copied there and also stay on the server.
 *
 * Buyers never get a bucket address up front. StoreEngine's own download
 * link still does every check (order paid, download permission, expiry,
 * login); only then, instead of reading the file from disk, the buyer is
 * sent to a signed link that works for a few minutes. If the file isn't in
 * the cloud yet, StoreEngine serves it from the server as before.
 *
 * Invoices aren't stored: StoreEngine builds them from the order when asked,
 * so there is nothing to put in a bucket.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery -- product meta looked up by value.
 */
final class StoreEngine extends Integration {

	const KEY      = 'storeengine.downloads';
	const META_KEY = '_storeengine_product_downloadable_files';

	/** Download name StoreEngine settled on for the current request. */
	private string $filename = '';

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return __( 'StoreEngine', 'gemcloudstorage' );
	}

	public function detected(): bool {
		return defined( 'STOREENGINE_VERSION' ) && class_exists( '\StoreEngine\Classes\DownloadHandler' );
	}

	public function kinds(): array {
		return [
			'media_downloads' => [
				'label'       => __( 'Downloads from the media library', 'gemcloudstorage' ),
				'description' => __( 'Ebooks, software, templates, music and other files picked from the media library. Moved to the private bucket.', 'gemcloudstorage' ),
			],
			'other_downloads' => [
				'label'       => __( 'Downloads stored elsewhere on this server', 'gemcloudstorage' ),
				'description' => __( 'Files outside the media library, such as storeengine_uploads. Copied to the private bucket; the server keeps its copy.', 'gemcloudstorage' ),
			],
		];
	}

	public function register_context(): void {
		Contexts::register( self::KEY, [
			'label'       => __( 'StoreEngine downloadable files', 'gemcloudstorage' ),
			'description' => __( 'Files customers buy. Kept in the private bucket and handed out only through StoreEngine\'s download link, after it checks the order, then as a signed link that expires in minutes.', 'gemcloudstorage' ),
			'visibility'  => 'private',
			'path'        => 'private/storeengine/{id}/{uuid}/{filename}',
			'source'      => 'StoreEngine',
			'locked'      => [ 'visibility' ],
			'kinds'       => $this->kinds(),
		] );
	}

	public function hooks(): void {
		add_filter( 'sanitize_post_meta_' . self::META_KEY, [ $this, 'canonical_files' ], 5 );
		add_action( 'added_post_meta', [ $this, 'on_meta' ], 10, 4 );
		add_action( 'updated_post_meta', [ $this, 'on_meta' ], 10, 4 );
		add_action( 'deleted_post_meta', [ $this, 'on_meta' ], 10, 4 );
		add_action( 'before_delete_post', [ $this, 'on_delete_product' ], 10, 1 );

		add_filter( 'storeengine/downloads/attachment_filename', [ $this, 'remember_filename' ], 99, 1 );
		add_filter( 'storeengine/downloads/file_download_method', [ $this, 'send_from_cloud' ], 99, 3 );
	}

	// ── Saving products ─────────────────────────────────────────────────────

	/**
	 * Store the local uploads URL even when the seller picked the file while
	 * the media modal was showing cloud URLs, so products never depend on
	 * this plugin to keep working.
	 *
	 * @param mixed $files
	 * @return mixed
	 */
	public function canonical_files( $files ) {
		if ( ! is_array( $files ) ) {
			return $files;
		}
		foreach ( $files as $key => $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['file'] ) && is_string( $entry['file'] ) ) {
				$files[ $key ]['file'] = UrlRewriter::canonical_url( $entry['file'] );
			}
		}
		return $files;
	}

	public function on_meta( $meta_ids, $object_id, $meta_key, $meta_value ): void {
		if ( self::META_KEY === $meta_key ) {
			$this->sync_product( (int) $object_id );
		}
	}

	public function on_delete_product( $post_id ): void {
		if ( $this->is_product( (int) $post_id ) && Manager::available() ) {
			Manager::delete( Ref::of( self::KEY, (int) $post_id ) );
		}
	}

	/**
	 * Bring one product's files into line: media library files are made
	 * private, other local files are copied to the private bucket, and copies
	 * of files the product no longer offers are deleted.
	 */
	public function sync_product( int $product_id ): void {
		if ( ! $this->enabled() || ! Manager::available() ) {
			return;
		}

		$attachments = [];
		$own         = [];

		foreach ( $this->files( $product_id ) as $entry ) {
			$file = (string) ( $entry['file'] ?? '' );
			if ( '' === $file ) {
				continue;
			}
			$attachment_id = UrlRewriter::attachment_for_url( $file );
			if ( $attachment_id ) {
				$attachments[] = $attachment_id;
				continue;
			}
			// Switched off: the cloud copy is deleted below, the server's stays.
			$path = $this->kind_on( 'other_downloads' ) ? $this->local_path( $file ) : null;
			if ( $path ) {
				$own[ self::variant( $file ) ] = $path;
			}
		}

		$this->protect( $attachments );

		foreach ( $own as $variant => $path ) {
			Manager::upload( $path, Ref::of( self::KEY, $product_id ), [ 'variant' => $variant ] );
		}

		foreach ( ItemRepository::for_source( self::KEY, $product_id, true ) as $variant => $item ) {
			if ( ! isset( $own[ $variant ] ) ) {
				Manager::queue_delete_object( $item );
			}
		}
		ItemRepository::forget( self::KEY, $product_id );
	}

	// ── Downloading ─────────────────────────────────────────────────────────

	public function remember_filename( $filename ) {
		$this->filename = (string) $filename;
		return $filename;
	}

	/**
	 * Runs inside StoreEngine's download(), after every permission check.
	 * When the file is in the cloud, send the buyer there and stop;
	 * otherwise let StoreEngine serve it from disk as usual.
	 *
	 * @param string $method
	 * @param string $file_path
	 * @param int    $product_id
	 * @return string
	 */
	public function send_from_cloud( $method, $file_path, $product_id ) {
		if ( ! $this->enabled() || ! Manager::available() ) {
			return $method;
		}

		$file          = (string) $file_path;
		$attachment_id = UrlRewriter::attachment_for_url( $file );

		if ( $attachment_id ) {
			// Products saved before the plugin was set up get protected now.
			$this->protect( [ $attachment_id ] );
			$item = ItemRepository::find_variant( 'attachment', $attachment_id );
		} else {
			$item = ItemRepository::find_variant( self::KEY, (int) $product_id, self::variant( $file ) );
			if ( ! $item && $this->local_path( $file ) ) {
				$this->sync_product( (int) $product_id );
			}
		}

		if ( ! $item || ! $item->is_synced() ) {
			return $method;
		}

		$url = Manager::signed_url_for( $item, [
			'filename'    => '' !== $this->filename ? $this->filename : $item->filename(),
			'disposition' => 'attachment',
		] );
		if ( is_wp_error( $url ) ) {
			return $method;
		}

		/**
		 * Fires before a buyer is sent to a signed download link.
		 *
		 * @param \GemCloudStorage\Items\Item $item       The stored file.
		 * @param int                         $product_id Product.
		 */
		do_action( 'gemcloudstorage/storeengine/download', $item, (int) $product_id );

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		wp_redirect( $url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- signed provider URL.
		exit;
	}

	// ── Integration ─────────────────────────────────────────────────────────

	public function uses( int $attachment_id, bool $all_kinds = false ): array {
		if ( ! $this->kind_on( 'media_downloads', $all_kinds ) ) {
			return [];
		}
		$reasons = [];
		foreach ( $this->products_using( $attachment_id ) as $product_id => $name ) {
			/* translators: 1: file name, 2: product name */
			$reasons[] = sprintf( __( 'Download "%1$s" of product "%2$s"', 'gemcloudstorage' ), $name, get_the_title( $product_id ) );
		}
		return $reasons;
	}

	/**
	 * Files are only handed out through StoreEngine's checked download link,
	 * which goes straight to a signed URL. The plain private link opens them
	 * for nobody but editors and admins.
	 */
	public function grants( int $attachment_id ): bool {
		return false;
	}

	public function all_attachment_ids(): array {
		if ( ! $this->kind_on( 'media_downloads' ) ) {
			return [];
		}
		$ids = [];
		foreach ( $this->all_products() as $product_id => $files ) {
			foreach ( $files as $entry ) {
				$id = UrlRewriter::attachment_for_url( (string) ( $entry['file'] ?? '' ) );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public function reconcile_own_files(): void {
		foreach ( array_keys( $this->all_products() ) as $product_id ) {
			$this->sync_product( (int) $product_id );
		}
	}

	// ── Lookups ─────────────────────────────────────────────────────────────

	/**
	 * @return array<int,string> product id => download name
	 */
	private function products_using( int $attachment_id ): array {
		global $wpdb;

		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $file ) {
			return [];
		}

		// Narrow by the file's name, then confirm each entry really is it.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
			self::META_KEY,
			'%' . $wpdb->esc_like( pathinfo( $file, PATHINFO_FILENAME ) ) . '%'
		) );

		$out = [];
		foreach ( (array) $rows as $row ) {
			foreach ( (array) maybe_unserialize( $row->meta_value ) as $entry ) {
				if ( is_array( $entry ) && ! empty( $entry['file'] ) && UrlRewriter::attachment_for_url( (string) $entry['file'] ) === $attachment_id ) {
					$out[ (int) $row->post_id ] = (string) ( $entry['name'] ?? wp_basename( $file ) );
				}
			}
		}
		return $out;
	}

	/**
	 * @return array<int,array> product id => downloadable files
	 */
	private function all_products(): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' AND meta_value <> 'a:0:{}'",
			self::META_KEY
		) );

		$out = [];
		foreach ( (array) $rows as $row ) {
			$files = maybe_unserialize( $row->meta_value );
			if ( is_array( $files ) && $files ) {
				$out[ (int) $row->post_id ] = $files;
			}
		}
		return $out;
	}

	private function files( int $product_id ): array {
		$files = get_post_meta( $product_id, self::META_KEY, true );
		return is_array( $files ) ? array_filter( $files, 'is_array' ) : [];
	}

	/**
	 * A file on this server that isn't in the media library, resolved the
	 * way StoreEngine resolves it, and only inside the uploads folder.
	 */
	private function local_path( string $file ): ?string {
		$parsed = \StoreEngine\Classes\DownloadHandler::parse_file_path( $file );
		if ( ! empty( $parsed['remote_file'] ) ) {
			return null;
		}

		$path    = realpath( (string) $parsed['file_path'] );
		$uploads = realpath( wp_get_upload_dir()['basedir'] );
		if ( ! $path || ! $uploads || ! is_file( $path ) || 0 !== strpos( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $uploads ) ) ) ) {
			return null;
		}
		return $path;
	}

	/** One row per file, found again from the stored file string. */
	private static function variant( string $file ): string {
		return 'file-' . md5( trim( $file ) );
	}

	private function is_product( int $post_id ): bool {
		return class_exists( '\StoreEngine\Utils\Helper' ) && \StoreEngine\Utils\Helper::PRODUCT_POST_TYPE === get_post_type( $post_id );
	}
}

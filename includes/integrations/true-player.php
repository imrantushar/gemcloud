<?php
namespace GemCloudStorage\Integrations;

use GemCloudStorage\Contexts;
use GemCloudStorage\Items\Item;
use GemCloudStorage\Items\ItemRepository;
use GemCloudStorage\Manager;
use GemCloudStorage\Media\PrivateDelivery;
use GemCloudStorage\Media\UrlRewriter;
use GemCloudStorage\Ref;
use GemCloudStorage\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TruePlayer: self-hosted videos play from the cloud, and the file of a
 * video switched to "Private" (Protection tab) goes to the private bucket.
 *
 * TruePlayer saves each file as a URL inside the video's JSON config, so the
 * swap happens when the player is rendered (`trueplayer/config`), and the
 * saved config always keeps the local URL. Files outside the media library
 * (a pasted uploads URL, ZenCommunity's uploads folder) are copied up and
 * also stay on the server.
 *
 * Private files open for the people TruePlayer lets play the video: its
 * `trueplayer/gate/access` rules (Academy enrolment through TruePlayer Pro's
 * academy-sync, and anything else hooked there) now guard the file itself,
 * not only the player screen. TruePlayer Pro's own signed stream link is
 * kept: once the file is in the cloud it redirects there instead of reading
 * the file from disk.
 *
 * Posters always stay public: they show before anyone presses play.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery -- video meta looked up by value.
 */
final class TruePlayer extends Integration {

	/** Private files, and the context the admin switches on and off. */
	const KEY = 'trueplayer.videos';

	/** Public copies of files outside the media library. */
	const FILES = 'trueplayer.files';

	const META_KEY = '_trueplayer_config';

	/** @var array<string,int> URL => attachment ID, per request */
	private array $attachments = [];

	/** @var array<int,array<int,string[]>> attachment => video => kinds, per request */
	private array $using = [];

	public function key(): string {
		return self::KEY;
	}

	public function label(): string {
		return __( 'TruePlayer', 'gemcloudstorage' );
	}

	public function detected(): bool {
		return defined( 'TRUEPLAYER_VIDEO_POST_TYPE' ) && class_exists( '\TruePlayer\PrivateVideo' );
	}

	public function kinds(): array {
		return [
			'private_videos' => [
				'label'       => __( 'Private videos', 'gemcloudstorage' ),
				'description' => __( 'The video or audio file of videos switched to "Private" in TruePlayer. Plays only for viewers TruePlayer lets in.', 'gemcloudstorage' ),
			],
			'subtitles'      => [
				'label'       => __( 'Subtitles of private videos', 'gemcloudstorage' ),
				'description' => __( 'Caption files (.vtt) of those videos.', 'gemcloudstorage' ),
			],
			'other_files'    => [
				'label'       => __( 'Files stored outside the media library', 'gemcloudstorage' ),
				'description' => __( 'Videos, posters and captions picked by URL from elsewhere in uploads, such as ZenCommunity uploads. Copied to the cloud; the server keeps its copy.', 'gemcloudstorage' ),
			],
		];
	}

	public function register_context(): void {
		Contexts::register( self::KEY, [
			'label'       => __( 'TruePlayer videos', 'gemcloudstorage' ),
			'description' => __( 'Self-hosted videos play from the cloud. Videos switched to "Private" in TruePlayer are kept in the private bucket and open only for viewers TruePlayer allows. Posters stay public.', 'gemcloudstorage' ),
			'visibility'  => 'private',
			'path'        => 'private/trueplayer/{id}/{uuid}/{filename}',
			'source'      => 'TruePlayer',
			'locked'      => [ 'visibility' ],
			'kinds'       => $this->kinds(),
		] );

		// Switched with the context above.
		Contexts::register( self::FILES, [
			'label'       => __( 'TruePlayer files outside the media library', 'gemcloudstorage' ),
			'description' => __( 'Public copies of files TruePlayer plays from elsewhere in uploads.', 'gemcloudstorage' ),
			'visibility'  => 'public',
			'path'        => '{prefix}/{file}',
			'source'      => 'TruePlayer',
			'locked'      => [ 'visibility' ],
			'internal'    => true,
		] );
	}

	public function hooks(): void {
		add_filter( 'sanitize_post_meta_' . self::META_KEY, [ $this, 'canonical_config' ], 5 );
		add_action( 'added_post_meta', [ $this, 'on_meta' ], 10, 4 );
		add_action( 'updated_post_meta', [ $this, 'on_meta' ], 10, 4 );
		add_action( 'before_delete_post', [ $this, 'on_delete_video' ], 10, 1 );

		add_filter( 'trueplayer/config', [ $this, 'play_from_cloud' ], 20, 2 );
		add_filter( 'rest_request_before_callbacks', [ $this, 'stream_from_cloud' ], 10, 3 );
		add_filter( 'gemcloudstorage/can_access', [ $this, 'own_file_access' ], 10, 2 );
	}

	/**
	 * Public files need only a connection; private ones also need a private
	 * bucket (enabled()).
	 */
	private function serving(): bool {
		return $this->switched_on() && Manager::available();
	}

	// ── Saving videos ───────────────────────────────────────────────────────

	/**
	 * Store local URLs even when the author picked a file while the media
	 * modal showed cloud URLs, so videos never depend on this plugin.
	 *
	 * @param mixed $value JSON config.
	 * @return mixed
	 */
	public function canonical_config( $value ) {
		$config = is_string( $value ) ? json_decode( $value, true ) : $value;
		if ( ! is_array( $config ) || ! is_array( $config['source'] ?? null ) ) {
			return $value;
		}

		$changed = false;
		$config  = $this->map_urls( $config, function ( string $url ) use ( &$changed ) {
			$local   = UrlRewriter::canonical_url( $url );
			$changed = $changed || $local !== $url;
			return $local;
		} );

		if ( ! $changed ) {
			return $value;
		}
		return is_string( $value ) ? wp_json_encode( $config ) : $config;
	}

	public function on_meta( $meta_ids, $object_id, $meta_key, $meta_value ): void {
		if ( self::META_KEY === $meta_key ) {
			$this->using = [];
			Integrations::forget();
			$this->sync_video( (int) $object_id );
		}
	}

	public function on_delete_video( $post_id ): void {
		if ( $this->is_video( (int) $post_id ) && Manager::available() ) {
			Manager::delete( Ref::of( self::KEY, (int) $post_id ) );
			Manager::delete( Ref::of( self::FILES, (int) $post_id ) );
		}
	}

	/**
	 * Bring one video's files into line: media library files of a private
	 * video are made private, files outside the media library are copied up
	 * (privately for a private video), and copies it no longer uses are
	 * deleted.
	 */
	public function sync_video( int $video_id ): void {
		if ( ! $this->serving() || ! $this->is_video( $video_id ) ) {
			return;
		}

		$protect = [];
		$want    = [
			self::KEY   => [],
			self::FILES => [],
		];

		foreach ( $this->files_of( $this->raw_config( $video_id ) ) as list( $url, $kind ) ) {
			$attachment_id = $this->attachment_for( $url );
			if ( $attachment_id ) {
				if ( '' !== $kind ) {
					$protect[] = $attachment_id;
				}
				continue;
			}

			$path = $this->kind_on( 'other_files' ) ? $this->local_path( $url ) : null;
			if ( ! $path ) {
				continue;
			}

			$private = '' !== $kind && $this->kind_on( $kind );
			// A private file is never copied to the public bucket.
			if ( $private && ! $this->enabled() ) {
				continue;
			}
			$want[ $private ? self::KEY : self::FILES ][ self::variant( $url ) ] = $path;
		}

		$this->protect( $protect );

		foreach ( $want as $context => $files ) {
			foreach ( $files as $variant => $path ) {
				Manager::upload( $path, Ref::of( $context, $video_id ), [ 'variant' => $variant ] );
			}
			foreach ( ItemRepository::for_source( $context, $video_id, true ) as $variant => $item ) {
				if ( ! isset( $files[ $variant ] ) ) {
					Manager::queue_delete_object( $item );
				}
			}
			ItemRepository::forget( $context, $video_id );
		}
	}

	// ── Playing ─────────────────────────────────────────────────────────────

	/**
	 * The rendered player gets cloud URLs; TruePlayer Pro then wraps a
	 * private source in its own signed stream link as usual.
	 *
	 * @param array $config
	 * @param int   $video_id
	 * @return array
	 */
	public function play_from_cloud( $config, $video_id ) {
		if ( ! is_array( $config ) || ! is_array( $config['source'] ?? null ) || ! $this->serving() ) {
			return $config;
		}
		// The editor reads the raw meta; stay out of admin screens anyway.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $config;
		}

		$private = ! empty( $config['source']['private'] );
		$self    = in_array( $config['source']['type'] ?? '', [ 'self', 'url' ], true );

		if ( $self && is_string( $config['source']['src'] ?? null ) && '' !== $config['source']['src'] ) {
			$config['source']['src'] = $this->play_url( $config['source']['src'], (int) $video_id, $private ? 'private_videos' : '' );
		}
		if ( is_string( $config['source']['poster'] ?? null ) && '' !== $config['source']['poster'] ) {
			$config['source']['poster'] = $this->play_url( $config['source']['poster'], (int) $video_id, '' );
		}
		if ( is_array( $config['source']['subtitles'] ?? null ) ) {
			foreach ( $config['source']['subtitles'] as $i => $track ) {
				if ( is_array( $track ) && is_string( $track['src'] ?? null ) && '' !== $track['src'] ) {
					$config['source']['subtitles'][ $i ]['src'] = $this->play_url( $track['src'], (int) $video_id, $private ? 'subtitles' : '' );
				}
			}
		}

		return $config;
	}

	/**
	 * Cloud URL for one file once it is stored; the URL as saved otherwise.
	 *
	 * @param string $kind Private kind, or '' for a public file.
	 */
	private function play_url( string $url, int $video_id, string $kind ): string {
		$attachment_id = $this->attachment_for( $url );
		if ( $attachment_id ) {
			// Videos saved before the plugin was set up get protected now.
			if ( '' !== $kind ) {
				$this->protect( [ $attachment_id ] );
			}
			return UrlRewriter::rewrite( $url, $attachment_id );
		}

		$item = $this->own_item( $video_id, $url );
		if ( ! $item || ! $item->is_synced() ) {
			return $url;
		}
		$cloud = UrlRewriter::cloud_url( $item );
		return $cloud ? $cloud : $url;
	}

	/**
	 * TruePlayer Pro's signed stream link (/wp-json/trueplayer/v1/stream).
	 * When the file is in the cloud and the viewer may watch, send them to
	 * a signed cloud link instead of streaming from disk. Anything else is
	 * left to TruePlayer, unchanged.
	 *
	 * @param mixed            $response
	 * @param array            $handler
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public function stream_from_cloud( $response, $handler, $request ) {
		if ( null !== $response || ! $request instanceof \WP_REST_Request || '/trueplayer/v1/stream' !== $request->get_route() ) {
			return $response;
		}
		if ( ! $this->serving() || ! class_exists( '\TruePlayer\Pro' ) || ! \TruePlayer\Pro::active() ) {
			return $response;
		}

		$video_id = (int) $request['video'];
		if ( ! \TruePlayer\PrivateVideo::verify( $video_id, (int) $request['exp'], (string) $request['sig'] ) ) {
			return $response;
		}

		$source = $this->raw_config( $video_id )['source'] ?? [];
		if ( ! is_array( $source ) || empty( $source['private'] ) || empty( $source['src'] ) || ! is_string( $source['src'] ) ) {
			return $response;
		}

		$attachment_id = ! empty( $source['attachmentId'] ) ? (int) $source['attachmentId'] : $this->attachment_for( $source['src'] );
		if ( $attachment_id ) {
			$this->protect( [ $attachment_id ] );
			$item = ItemRepository::find_variant( 'attachment', $attachment_id );
		} else {
			$item = $this->own_item( $video_id, $source['src'] );
		}
		if ( ! $item || ! $item->is_synced() ) {
			return $response;
		}

		// REST requests without a nonce run logged out; the player's video
		// request carries the login cookie, so read the viewer from it.
		if ( ! $this->viewer_allowed( $video_id, (int) wp_validate_auth_cookie( '', 'logged_in' ) ) ) {
			return new \WP_Error( 'gemcloudstorage_forbidden', __( 'You don\'t have access to this video.', 'gemcloudstorage' ), [ 'status' => 403 ] );
		}

		$url = Manager::signed_url_for( $item, [
			'ttl'         => max( (int) Settings::value( 'signed_url_ttl', 300 ), PrivateDelivery::MEDIA_TTL ),
			'disposition' => 'inline',
		] );
		if ( is_wp_error( $url ) ) {
			return $response;
		}

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		wp_redirect( $url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- signed provider URL.
		exit;
	}

	/**
	 * /?gcs_file= links of private copies of files outside the media library.
	 *
	 * @param bool $allowed
	 * @param Item $item
	 */
	public function own_file_access( $allowed, $item ) {
		if ( $allowed || ! $item instanceof Item || self::KEY !== $item->source_type ) {
			return $allowed;
		}
		return $this->viewer_allowed( $item->source_id );
	}

	/**
	 * Whether a viewer may play a video, by TruePlayer's own rules: login
	 * required, drip release, and whatever is hooked on
	 * `trueplayer/gate/access`. Without Pro, TruePlayer gates nothing.
	 *
	 * @param int|null $user_id Null for the current user, 0 for a guest.
	 */
	private function viewer_allowed( int $video_id, ?int $user_id = null ): bool {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		if ( $user_id && user_can( $user_id, 'edit_post', $video_id ) ) {
			return true;
		}
		if ( ! class_exists( '\TruePlayer\Pro' ) || ! \TruePlayer\Pro::active() ) {
			return true;
		}

		$subject = $user_id ? new \TruePlayer\Subject( 'user', $user_id ) : \TruePlayer\Subject::resolve();
		if ( 'user' === $subject->type && ! $user_id ) {
			$subject = new \TruePlayer\Subject( 'guest', '' );
		}

		$progress = '\TruePlayer\Services\ProgressService';
		if ( class_exists( $progress ) ) {
			$gating = method_exists( $progress, 'gating_config' ) ? (array) $progress::gating_config( $video_id ) : [];
			if ( ! empty( $gating['requireLoginForGate'] ) && 'guest' === $subject->type ) {
				return false;
			}
			$from = method_exists( $progress, 'available_from' ) ? (int) $progress::available_from( $video_id, $subject ) : 0;
			if ( $from && time() < $from ) {
				return false;
			}
		}

		$access = apply_filters( 'trueplayer/gate/access', [ 'allowed' => true ], $video_id, $subject );
		return ! empty( $access['allowed'] );
	}

	// ── Integration ─────────────────────────────────────────────────────────

	public function uses( int $attachment_id, bool $all_kinds = false ): array {
		$reasons = [];
		foreach ( $this->videos_using( $attachment_id ) as $video_id => $kinds ) {
			foreach ( $kinds as $kind ) {
				if ( ! $this->kind_on( $kind, $all_kinds ) ) {
					continue;
				}
				$reasons[] = 'subtitles' === $kind
					/* translators: %s: video title */
					? sprintf( __( 'Subtitles of private video "%s"', 'gemcloudstorage' ), get_the_title( $video_id ) )
					/* translators: %s: video title */
					: sprintf( __( 'Private video "%s"', 'gemcloudstorage' ), get_the_title( $video_id ) );
			}
		}
		return array_values( array_unique( $reasons ) );
	}

	public function grants( int $attachment_id ): bool {
		foreach ( array_keys( $this->videos_using( $attachment_id ) ) as $video_id ) {
			if ( $this->viewer_allowed( $video_id ) ) {
				return true;
			}
		}
		return false;
	}

	public function all_attachment_ids(): array {
		$ids = [];
		foreach ( $this->all_videos() as $config ) {
			foreach ( $this->files_of( $config ) as list( $url, $kind ) ) {
				if ( '' !== $kind && $this->kind_on( $kind ) ) {
					$id = $this->attachment_for( $url );
					if ( $id ) {
						$ids[] = $id;
					}
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public function reconcile_own_files(): void {
		foreach ( array_keys( $this->all_videos() ) as $video_id ) {
			$this->sync_video( (int) $video_id );
		}
	}

	// ── Lookups ─────────────────────────────────────────────────────────────

	/**
	 * Files a config plays: [url, private kind or ''].
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private function files_of( array $config ): array {
		$source  = is_array( $config['source'] ?? null ) ? $config['source'] : [];
		$private = ! empty( $source['private'] );
		$out     = [];

		if ( in_array( $source['type'] ?? '', [ 'self', 'url' ], true ) && is_string( $source['src'] ?? null ) && '' !== $source['src'] ) {
			$out[] = [ $source['src'], $private ? 'private_videos' : '' ];
		}
		if ( is_string( $source['poster'] ?? null ) && '' !== $source['poster'] ) {
			$out[] = [ $source['poster'], '' ];
		}
		foreach ( is_array( $source['subtitles'] ?? null ) ? $source['subtitles'] : [] as $track ) {
			if ( is_array( $track ) && is_string( $track['src'] ?? null ) && '' !== $track['src'] ) {
				$out[] = [ $track['src'], $private ? 'subtitles' : '' ];
			}
		}
		return $out;
	}

	/**
	 * Apply $fn to every file URL in a config.
	 */
	private function map_urls( array $config, callable $fn ): array {
		foreach ( [ 'src', 'poster' ] as $field ) {
			if ( is_string( $config['source'][ $field ] ?? null ) && '' !== $config['source'][ $field ] ) {
				$config['source'][ $field ] = $fn( $config['source'][ $field ] );
			}
		}
		if ( is_array( $config['source']['subtitles'] ?? null ) ) {
			foreach ( $config['source']['subtitles'] as $i => $track ) {
				if ( is_array( $track ) && is_string( $track['src'] ?? null ) && '' !== $track['src'] ) {
					$config['source']['subtitles'][ $i ]['src'] = $fn( $track['src'] );
				}
			}
		}
		return $config;
	}

	/**
	 * Videos whose config uses this attachment, with the private kinds it is
	 * used as ('' entries dropped: public uses don't make it private).
	 *
	 * @return array<int,string[]>
	 */
	private function videos_using( int $attachment_id ): array {
		if ( isset( $this->using[ $attachment_id ] ) ) {
			return $this->using[ $attachment_id ];
		}

		global $wpdb;
		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $file ) {
			return $this->using[ $attachment_id ] = [];
		}

		// Narrow by the file's name, as written in JSON too, then confirm.
		$name  = pathinfo( $file, PATHINFO_FILENAME );
		$names = array_unique( [ $name, trim( (string) wp_json_encode( $name ), '"' ) ] );
		$likes = [];
		foreach ( $names as $n ) {
			$likes[] = $wpdb->prepare( 'pm.meta_value LIKE %s', '%' . $wpdb->esc_like( $n ) . '%' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $likes are prepared above.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND p.post_type = %s AND ( " . implode( ' OR ', $likes ) . ' )',
			self::META_KEY,
			TRUEPLAYER_VIDEO_POST_TYPE
		) );

		$out = [];
		foreach ( (array) $rows as $row ) {
			$config = json_decode( (string) $row->meta_value, true );
			if ( ! is_array( $config ) ) {
				continue;
			}
			foreach ( $this->files_of( $config ) as list( $url, $kind ) ) {
				if ( '' !== $kind && $this->attachment_for( $url ) === $attachment_id ) {
					$out[ (int) $row->post_id ][] = $kind;
				}
			}
		}
		return $this->using[ $attachment_id ] = array_map( 'array_unique', $out );
	}

	/**
	 * @return array<int,array> video id => config
	 */
	private function all_videos(): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s AND p.post_type = %s AND pm.meta_value <> ''",
			self::META_KEY,
			TRUEPLAYER_VIDEO_POST_TYPE
		) );

		$out = [];
		foreach ( (array) $rows as $row ) {
			$config = json_decode( (string) $row->meta_value, true );
			if ( is_array( $config ) ) {
				$out[ (int) $row->post_id ] = $config;
			}
		}
		return $out;
	}

	/** The config as saved, without anyone's filters. */
	private function raw_config( int $video_id ): array {
		$raw    = get_post_meta( $video_id, self::META_KEY, true );
		$config = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : $raw;
		return is_array( $config ) ? $config : [];
	}

	private function attachment_for( string $url ): int {
		if ( ! isset( $this->attachments[ $url ] ) ) {
			$this->attachments[ $url ] = UrlRewriter::attachment_for_url( $url );
		}
		return $this->attachments[ $url ];
	}

	/**
	 * The stored copy of a file outside the media library: private first,
	 * the public one otherwise.
	 */
	private function own_item( int $video_id, string $url ): ?Item {
		$variant = self::variant( UrlRewriter::canonical_url( $url ) );
		$found   = null;
		foreach ( [ self::KEY, self::FILES ] as $context ) {
			$item = ItemRepository::find_variant( $context, $video_id, $variant );
			if ( $item && $item->is_synced() ) {
				return $item;
			}
			$found = $found ?? $item;
		}
		return $found;
	}

	/**
	 * A file inside the uploads folder that a URL on this site points at.
	 */
	private function local_path( string $url ): ?string {
		$url  = self::without_scheme( $url );
		$base = untrailingslashit( self::without_scheme( wp_get_upload_dir()['baseurl'] ) );
		if ( 0 !== strpos( $url, $base . '/' ) ) {
			return null;
		}

		$uploads = realpath( wp_get_upload_dir()['basedir'] );
		$path    = realpath( wp_get_upload_dir()['basedir'] . '/' . rawurldecode( substr( $url, strlen( $base ) + 1 ) ) );
		if ( ! $path || ! $uploads || ! is_file( $path ) || 0 !== strpos( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $uploads ) ) ) ) {
			return null;
		}
		return $path;
	}

	/** One row per file, found again from the saved URL (either scheme). */
	private static function variant( string $url ): string {
		return 'file-' . md5( self::without_scheme( $url ) );
	}

	private static function without_scheme( string $url ): string {
		return (string) preg_replace( '#^https?:#i', '', (string) strtok( trim( $url ), '?#' ) );
	}

	private function is_video( int $post_id ): bool {
		return defined( 'TRUEPLAYER_VIDEO_POST_TYPE' ) && TRUEPLAYER_VIDEO_POST_TYPE === get_post_type( $post_id );
	}
}

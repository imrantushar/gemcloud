<?php
namespace GemCloud\Api;

use GemCloud\Backups\Backups;
use GemCloud\Contexts;
use GemCloud\Http\StorageException;
use GemCloud\Items\Item;
use GemCloud\Integrations\Integrations;
use GemCloud\Items\ItemRepository;
use GemCloud\Jobs\DownloadBackJob;
use GemCloud\Media\MediaHooks;
use GemCloud\Jobs\MigrateJob;
use GemCloud\Jobs\Queue;
use GemCloud\Manager;
use GemCloud\Providers\Presets;
use GemCloud\Providers\S3CompatibleDriver;
use GemCloud\Ref;
use GemCloud\Security\Credentials;
use GemCloud\Settings;
use GemCloud\Utils\Conflicts;
use GemCloud\Utils\Helper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for the admin app, under gemcloud/v1. Administrators only.
 */
final class RestController {

	const NS = 'gemcloud/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		$admin = [ self::class, 'can_manage' ];

		register_rest_route( self::NS, '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_settings' ],
				'permission_callback' => $admin,
			],
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'save_settings' ],
				'permission_callback' => $admin,
			],
		] );

		$routes = [
			'/connection/test'     => [ 'POST', 'test_connection' ],
			'/connection/cors'     => [ 'POST', 'configure_cors' ],
			'/status'              => [ 'GET', 'status' ],
			'/items'               => [ 'GET', 'items' ],
			'/items/retry'         => [ 'POST', 'retry_items' ],
			'/attachment'          => [ 'GET', 'attachment' ],
			'/attachment/save'     => [ 'POST', 'attachment_save' ],
			'/migration/start'     => [ 'POST', 'migration_start' ],
			'/migration/stop'      => [ 'POST', 'migration_stop' ],
			'/download-back/start' => [ 'POST', 'download_back_start' ],
			'/download-back/stop'  => [ 'POST', 'download_back_stop' ],
			'/queue/resume'        => [ 'POST', 'queue_resume' ],
			'/queue/pause'         => [ 'POST', 'queue_pause' ],
			'/menu'                => [ 'GET', 'menu' ],
			'/backups'             => [ 'GET', 'backups' ],
			'/backups/run'         => [ 'POST', 'backups_run' ],
			'/backups/download'    => [ 'POST', 'backups_download' ],
			'/backups/delete'      => [ 'POST', 'backups_delete' ],
		];

		foreach ( $routes as $route => $def ) {
			register_rest_route( self::NS, $route, [
				'methods'             => $def[0],
				'callback'            => [ self::class, $def[1] ],
				'permission_callback' => $admin,
			] );
		}
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	// ── Settings ────────────────────────────────────────────────────────────

	public static function get_settings(): WP_REST_Response {
		return new WP_REST_Response( self::settings_payload() );
	}

	public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) $request->get_json_params();

		if ( isset( $body['credentials'] ) && is_array( $body['credentials'] ) ) {
			$provider = (string) ( $body['settings']['provider'] ?? Settings::get()['provider'] );
			$provider = in_array( $provider, Settings::PROVIDERS, true ) ? $provider : 'r2';

			Credentials::save(
				(string) ( $body['credentials']['access_key_id'] ?? '' ),
				sanitize_text_field( (string) ( $body['credentials']['secret_access_key'] ?? '' ) ),
				$provider
			);

			// Keys typed for the other providers, kept for when they're chosen.
			foreach ( (array) ( $body['credentials']['profiles'] ?? [] ) as $key => $keys ) {
				if ( $key !== $provider && in_array( $key, Settings::PROVIDERS, true ) && is_array( $keys ) ) {
					Credentials::save_profile( $key, (string) ( $keys['access_key_id'] ?? '' ), sanitize_text_field( (string) ( $keys['secret_access_key'] ?? '' ) ) );
				}
			}
		}

		if ( isset( $body['settings'] ) && is_array( $body['settings'] ) ) {
			Settings::save( $body['settings'] );
		}

		Manager::reset_driver();

		return new WP_REST_Response( self::settings_payload() );
	}

	private static function settings_payload(): array {
		return [
			'settings'    => Settings::get(),
			'credentials' => Credentials::for_display(),
			'providers'   => Presets::catalog(),
			'contexts'    => Contexts::all(),
			'conflicts'   => Conflicts::active(),
			'site_origin' => self::origin( home_url() ),
			'constants'   => [
				'encryption_key' => defined( 'GEMCLOUD_KEY' ),
			],
		];
	}

	// ── Connection ──────────────────────────────────────────────────────────

	/**
	 * Tests the form as it stands, saved or not: put, head and delete a probe
	 * object in each configured bucket.
	 */
	public static function test_connection( WP_REST_Request $request ): WP_REST_Response {
		list( $settings, $creds ) = self::draft_connection( (array) $request->get_json_params() );

		if ( '' === $creds->access_key_id || '' === $creds->secret_access_key ) {
			return new WP_REST_Response( [
				'ok'      => false,
				'results' => [],
				'message' => __( 'Enter the access key ID and secret access key first.', 'gemcloud' ),
			] );
		}

		$driver  = S3CompatibleDriver::from_settings( $settings, $creds );
		$results = [];
		$tested  = [];

		foreach ( [ 'public', 'private' ] as $role ) {
			$bucket = $settings['buckets'][ $role ]['name'] ?? '';
			if ( '' === $bucket || in_array( $bucket, $tested, true ) ) {
				continue;
			}
			$tested[]  = $bucket;
			$results[] = [
				'role'   => $role,
				'report' => $driver->test( $bucket ),
			];
		}

		if ( empty( $results ) ) {
			return new WP_REST_Response( [
				'ok'      => false,
				'results' => [],
				'message' => __( 'Enter at least one bucket name.', 'gemcloud' ),
			] );
		}

		$ok = ! in_array( false, array_map( function ( $r ) {
			return (bool) $r['report']['ok'];
		}, $results ), true );

		// A good test clears a pause caused by bad settings.
		if ( $ok && Queue::is_paused() && 'manual' !== Queue::state()['reason'] ) {
			Queue::resume();
		}

		return new WP_REST_Response( [
			'ok'      => $ok,
			'results' => $results,
		] );
	}

	public static function configure_cors( WP_REST_Request $request ) {
		$which    = sanitize_key( (string) ( $request->get_param( 'bucket' ) ?? 'both' ) );
		$settings = Settings::get();
		$origins  = array_values( array_unique( array_filter( [
			self::origin( home_url() ),
			self::origin( site_url() ),
			self::origin( admin_url() ),
		] ) ) );

		$done = [];
		try {
			foreach ( [ 'public', 'private' ] as $role ) {
				$bucket = $settings['buckets'][ $role ]['name'];
				if ( '' === $bucket || ( 'both' !== $which && $which !== $role ) || in_array( $bucket, $done, true ) ) {
					continue;
				}
				Manager::driver()->put_cors( $bucket, $origins );
				$done[] = $bucket;
			}
		} catch ( StorageException $e ) {
			return new WP_Error( 'gemcloud_cors_failed', $e->getMessage() . ' ' . $e->hint(), [ 'status' => 400 ] );
		}

		return new WP_REST_Response( [
			'ok'      => true,
			'buckets' => $done,
			'origins' => $origins,
		] );
	}

	// ── Status and files ────────────────────────────────────────────────────

	public static function status(): WP_REST_Response {
		$settings = Settings::get();
		$stats    = ItemRepository::stats();
		$warnings = [];

		if ( $stats['oldest_pending'] && strtotime( $stats['oldest_pending'] ) < time() - 15 * MINUTE_IN_SECONDS ) {
			$warnings[] = [
				'type'    => 'warning',
				'message' => __( 'Uploads have been waiting over 15 minutes. WP-Cron may be disabled or blocked on this host; run "wp gemcloud run" or set up a real cron job.', 'gemcloud' ),
			];
		}
		if ( 'r2' === $settings['provider'] && $settings['buckets']['public']['name'] && ! $settings['buckets']['public']['public_url'] ) {
			$warnings[] = [
				'type'    => 'warning',
				'message' => __( 'R2 serves public files only through a custom domain or the r2.dev subdomain. Add a Public URL, or public files keep being served from this server.', 'gemcloud' ),
			];
		}
		if ( $settings['buckets']['public']['name'] && ! $settings['buckets']['private']['name'] ) {
			$warnings[] = [
				'type'    => 'info',
				'message' => __( 'Private files share the public bucket. They still need a signed URL, but use a separate private bucket so a public bucket policy can never expose them.', 'gemcloud' ),
			];
		}
		$waiting = Integrations::waiting();
		if ( $waiting && $settings['enabled'] ) {
			$warnings[] = [
				'type'    => 'warning',
				'message' => sprintf(
					/* translators: %s: product names */
					__( 'Add a private bucket to protect %s files. Until then they stay where they are: course videos, lesson attachments and paid downloads are not moved into the public bucket.', 'gemcloud' ),
					Integrations::labels( $waiting )
				),
			];
		}
		$active = Integrations::active();
		if ( $active && $settings['enabled'] ) {
			$warnings[] = [
				'type'    => 'info',
				'message' => sprintf(
					/* translators: %s: product names */
					__( 'Protecting files for %s: course and download files go to the private bucket and open only after the product\'s own access check.', 'gemcloud' ),
					Integrations::labels( $active )
				),
			];
		}
		if ( 'aws' === $settings['provider'] ) {
			$warnings[] = [
				'type'    => 'info',
				'message' => __( 'Amazon S3 charges for every GB downloaded. Cloudflare R2 has no egress fees and suits large paid downloads and video.', 'gemcloud' ),
			];
		}

		return new WP_REST_Response( [
			'enabled'       => (bool) $settings['enabled'],
			'configured'    => Settings::is_configured(),
			'available'     => Manager::available(),
			'provider'      => $settings['provider'],
			'buckets'       => $settings['buckets'],
			'media'         => $settings['media'],
			'stats'         => $stats,
			'queue'         => array_merge( Queue::state(), Queue::stats() ),
			'migration'     => MigrateJob::state(),
			'download_back' => DownloadBackJob::state(),
			'attachments'   => MigrateJob::count_attachments(),
			'conflicts'     => Conflicts::active(),
			'needs_reentry' => Credentials::needs_reentry(),
			'warnings'      => $warnings,
			'bytes_label'   => Helper::format_bytes( $stats['bytes'] ),
		] );
	}

	public static function items( WP_REST_Request $request ): WP_REST_Response {
		$args = [
			'status'      => sanitize_key( (string) $request->get_param( 'status' ) ),
			'visibility'  => sanitize_key( (string) $request->get_param( 'visibility' ) ),
			'source_type' => sanitize_text_field( (string) $request->get_param( 'source_type' ) ),
			'search'      => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'page'        => (int) $request->get_param( 'page' ),
			'per_page'    => (int) $request->get_param( 'per_page' ),
		];

		$source = sanitize_text_field( (string) $request->get_param( 'source' ) );
		if ( 'media' === $source ) {
			$args['source_type'] = 'attachment';
		} elseif ( Backups::CONTEXT === $source ) {
			$args['source_type'] = Backups::CONTEXT;
		} elseif ( '' !== $source ) {
			$integration        = Integrations::get( $source );
			$args['contexts']   = $integration ? $integration->contexts() : [ $source ];
			$args['private_by'] = $source;
		}

		$result = ItemRepository::paginate( $args );

		$attachment_ids = [];
		foreach ( $result['items'] as $item ) {
			if ( 'attachment' === $item->source_type ) {
				$attachment_ids[] = $item->source_id;
			}
		}
		if ( $attachment_ids ) {
			_prime_post_caches( array_unique( $attachment_ids ), false, true );
		}

		$rows = array_map( function ( Item $item ) {
			$row = $item->to_array();

			$row['size_label'] = $item->size_bytes ? Helper::format_bytes( $item->size_bytes ) : '';
			$row['url']        = $item->is_synced() ? ( $item->is_private() ? null : Manager::public_url( $item ) ) : null;
			// Also private while a newly private file is still moving out of the public bucket.
			$row['private']    = $item->is_private() || ( 'attachment' === $item->source_type && MediaHooks::is_private( $item->source_id ) );
			$row['moving']     = $row['private'] && ! $item->is_private();

			if ( 'attachment' === $item->source_type ) {
				$row['title']    = get_the_title( $item->source_id );
				$row['edit_url'] = get_edit_post_link( $item->source_id, 'raw' );
				$row['thumb']    = 0 === strpos( $item->mime_type, 'image/' ) ? wp_get_attachment_image_url( $item->source_id, 'thumbnail' ) : null;
			}

			return $row;
		}, $result['items'] );

		$sources = [ [ 'value' => 'media', 'label' => __( 'Media library', 'gemcloud' ) ] ];
		foreach ( Integrations::detected() as $key => $integration ) {
			$sources[] = [ 'value' => $key, 'label' => $integration->label() ];
		}
		$sources[] = [ 'value' => Backups::CONTEXT, 'label' => __( 'Database backups', 'gemcloud' ) ];

		return new WP_REST_Response( [
			'items'   => $rows,
			'total'   => $result['total'],
			'sources' => $sources,
		] );
	}

	public static function retry_items( WP_REST_Request $request ): WP_REST_Response {
		if ( $request->get_param( 'all' ) ) {
			Queue::enqueue( Queue::REQUEUE, [ 'failed', 0 ] );
			return new WP_REST_Response( [ 'queued' => 'all' ] );
		}

		$ids = array_values( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ) );
		ItemRepository::set_status_for( $ids, Item::PENDING, [
			'attempts'   => 0,
			'last_error' => '',
		] );
		foreach ( $ids as $id ) {
			Queue::enqueue( Queue::UPLOAD, [ $id ] );
		}

		return new WP_REST_Response( [ 'queued' => count( $ids ) ] );
	}

	// ── File details ────────────────────────────────────────────────────────

	/**
	 * Everything the file details modal shows for one media library file.
	 */
	public static function attachment( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'gemcloud_not_found', __( 'That file is no longer in the media library.', 'gemcloud' ), [ 'status' => 404 ] );
		}
		return new WP_REST_Response( self::attachment_payload( $id ) );
	}

	/**
	 * Save the modal: title, alt text, caption, description and "Private".
	 */
	public static function attachment_save( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'gemcloud_not_found', __( 'That file is no longer in the media library.', 'gemcloud' ), [ 'status' => 404 ] );
		}

		$post = [ 'ID' => $id ];
		if ( null !== $request->get_param( 'title' ) ) {
			$post['post_title'] = sanitize_text_field( (string) $request->get_param( 'title' ) );
		}
		if ( null !== $request->get_param( 'caption' ) ) {
			$post['post_excerpt'] = wp_kses_post( (string) $request->get_param( 'caption' ) );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$post['post_content'] = wp_kses_post( (string) $request->get_param( 'description' ) );
		}
		if ( count( $post ) > 1 ) {
			$result = wp_update_post( wp_slash( $post ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( null !== $request->get_param( 'alt' ) && wp_attachment_is_image( $id ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $request->get_param( 'alt' ) ) );
		}

		if ( null !== $request->get_param( 'private' ) ) {
			MediaHooks::set_private( $id, (bool) $request->get_param( 'private' ) );
		}

		Integrations::forget( $id );
		ItemRepository::forget( 'attachment', $id );
		return new WP_REST_Response( self::attachment_payload( $id ) );
	}

	private static function attachment_payload( int $id ): array {
		$post     = get_post( $id );
		$meta     = wp_get_attachment_metadata( $id );
		$meta     = is_array( $meta ) ? $meta : [];
		$mime     = (string) get_post_mime_type( $id );
		$kind     = strtok( $mime, '/' );
		$file     = (string) get_attached_file( $id, true );
		$author   = get_userdata( (int) $post->post_author );
		$url      = (string) wp_get_attachment_url( $id );
		$original = ItemRepository::find_variant( 'attachment', $id );
		$size     = (int) ( $meta['filesize'] ?? 0 );
		if ( ! $size && $original ) {
			$size = (int) $original->size_bytes;
		}
		if ( ! $size && $file && file_exists( $file ) ) {
			$size = (int) filesize( $file );
		}

		$uses = [];
		foreach ( Integrations::uses( $id ) as $key => $reasons ) {
			$integration = Integrations::get( $key );
			foreach ( $reasons as $reason ) {
				$uses[] = ( $integration ? $integration->label() . ': ' : '' ) . $reason;
			}
		}

		$variants = [];
		foreach ( ItemRepository::for_source( 'attachment', $id, true ) as $variant => $item ) {
			$variants[] = [
				'variant'     => $variant,
				'bucket'      => $item->bucket,
				'object_key'  => $item->object_key,
				'status'      => $item->status,
				'local_state' => $item->local_state,
				'size_label'  => $item->size_bytes ? Helper::format_bytes( $item->size_bytes ) : '',
				'url'         => $item->is_synced() && ! $item->is_private() ? Manager::public_url( $item ) : null,
			];
		}

		return [
			'id'          => $id,
			'title'       => $post->post_title,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'mime_type'   => $mime,
			'kind'        => in_array( $kind, [ 'image', 'video', 'audio' ], true ) ? $kind : 'file',
			'file_name'   => wp_basename( $file ? $file : (string) get_post_meta( $id, '_wp_attached_file', true ) ),
			'file_type'   => strtoupper( (string) pathinfo( $file, PATHINFO_EXTENSION ) ),
			'size_label'  => $size ? Helper::format_bytes( $size ) : '',
			'width'       => (int) ( $meta['width'] ?? 0 ),
			'height'      => (int) ( $meta['height'] ?? 0 ),
			'length'      => (string) ( $meta['length_formatted'] ?? '' ),
			'uploaded_on' => get_post_time( 'c', true, $post ),
			'author'      => $author ? $author->display_name : '',
			'author_url'  => $author ? get_edit_user_link( $author->ID ) : '',
			'url'         => $url,
			'preview'     => 'image' === $kind ? (string) ( wp_get_attachment_image_url( $id, 'large' ) ?: $url ) : $url,
			'permalink'   => (string) get_permalink( $id ),
			'edit_url'    => (string) get_edit_post_link( $id, 'raw' ),
			'cloud'       => [
				'available' => Manager::available(),
				'label'     => MediaHooks::status_label( $id ),
				'private'   => MediaHooks::is_private( $id ),
				'locked'    => (bool) $uses,
				'uses'      => $uses,
				'orphaned'  => ! $uses && (bool) MediaHooks::private_by( $id ),
				'variants'  => $variants,
			],
		];
	}

	// ── Tools ───────────────────────────────────────────────────────────────

	public static function migration_start( WP_REST_Request $request ) {
		if ( ! Manager::available() ) {
			return self::unavailable();
		}
		return new WP_REST_Response( MigrateJob::start( (bool) $request->get_param( 'restart' ) ) );
	}

	public static function migration_stop(): WP_REST_Response {
		return new WP_REST_Response( MigrateJob::stop() );
	}

	public static function download_back_start() {
		if ( ! Manager::available() ) {
			return self::unavailable();
		}
		return new WP_REST_Response( DownloadBackJob::start() );
	}

	public static function download_back_stop(): WP_REST_Response {
		return new WP_REST_Response( DownloadBackJob::stop() );
	}

	public static function queue_resume(): WP_REST_Response {
		Queue::resume();
		return new WP_REST_Response( Queue::state() );
	}

	public static function queue_pause(): WP_REST_Response {
		Queue::pause( 'manual', __( 'Paused by an administrator.', 'gemcloud' ) );
		return new WP_REST_Response( Queue::state() );
	}

	// ── Database backups ────────────────────────────────────────────────────

	public static function backups(): WP_REST_Response {
		$run  = Backups::running();
		$list = [];
		foreach ( Backups::all( 50 ) as $item ) {
			$list[] = [
				'id'         => $item->source_id,
				'created_at' => gmdate( 'c', $item->source_id ),
				'size'       => (int) $item->size_bytes,
				'status'     => $item->status,
				'filename'   => $item->filename(),
				'error'      => (string) $item->last_error,
			];
		}

		$progress = null;
		if ( $run ) {
			$tables   = (array) $run['tables'];
			$progress = [
				'id'          => (int) $run['id'],
				'status'      => $run['status'],
				'table'       => $tables[ $run['table_index'] ] ?? '',
				'table_index' => min( (int) $run['table_index'] + 1, count( $tables ) ),
				'tables'      => count( $tables ),
				'rows'        => (int) $run['rows'],
				'started_at'  => gmdate( 'c', (int) $run['started_at'] ),
			];
		}

		$last = get_option( Backups::LAST_OPTION, null );
		if ( is_array( $last ) ) {
			$last['finished_at'] = gmdate( 'c', (int) $last['finished_at'] );
		}

		return new WP_REST_Response( [
			'blocker'  => Backups::blocker(),
			'handled_by' => Backups::handled_by(),
			'running'  => $progress,
			'last'     => $last,
			'backups'  => $list,
			'next_due' => Backups::enabled() && ! $run ? gmdate( 'c', isset( $last['started_at'] ) ? max( time(), (int) $last['started_at'] + ( Backups::FREQUENCIES[ Settings::value( 'backups.frequency', 'daily' ) ] ?? DAY_IN_SECONDS ) - HOUR_IN_SECONDS ) : time() ) : null,
		] );
	}

	public static function backups_run() {
		$run = Backups::start( 'manual' );
		if ( is_wp_error( $run ) ) {
			return new WP_Error( $run->get_error_code(), $run->get_error_message(), [ 'status' => 409 ] );
		}
		return self::backups();
	}

	public static function backups_download( WP_REST_Request $request ) {
		$item = Backups::find( absint( $request->get_param( 'id' ) ) );
		if ( ! $item || ! $item->is_synced() ) {
			return new WP_Error( 'gemcloud_not_found', __( 'This backup is not stored.', 'gemcloud' ), [ 'status' => 404 ] );
		}
		$url = Manager::signed_url_for( $item, [ 'ttl' => 5 * MINUTE_IN_SECONDS ] );
		if ( is_wp_error( $url ) ) {
			return new WP_Error( $url->get_error_code(), $url->get_error_message(), [ 'status' => 502 ] );
		}
		return new WP_REST_Response( [ 'url' => $url ] );
	}

	public static function backups_delete( WP_REST_Request $request ): WP_REST_Response {
		$id = absint( $request->get_param( 'id' ) );
		if ( $id && Backups::find( $id ) ) {
			Manager::delete( Ref::of( Backups::CONTEXT, $id ) );
			ItemRepository::forget( Backups::CONTEXT, $id );
		}
		return self::backups();
	}

	public static function menu(): WP_REST_Response {
		return new WP_REST_Response( Helper::get_admin_menu_list() );
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Settings and credentials from an unsaved form, falling back to what's
	 * stored (a masked secret means "keep the stored one").
	 *
	 * @return array{0:array,1:Credentials}
	 */
	private static function draft_connection( array $body ): array {
		$settings = Settings::get();
		if ( isset( $body['settings'] ) && is_array( $body['settings'] ) ) {
			$draft    = $body['settings'];
			$settings = array_replace_recursive( $settings, array_intersect_key( $draft, array_flip( [ 'provider', 'endpoint', 'buckets' ] ) ) );
			if ( 'r2' === $settings['provider'] ) {
				$settings['endpoint']['account_id'] = preg_replace( '/[^a-f0-9]/i', '', (string) $settings['endpoint']['account_id'] );
			}
		}

		$access = Credentials::access_key_id();
		// A masked secret means the stored one of the provider on screen.
		$secret = defined( 'GEMCLOUD_SECRET_ACCESS_KEY' ) ? (string) Credentials::secret() : Credentials::secret_for( (string) $settings['provider'] );

		if ( isset( $body['credentials'] ) && is_array( $body['credentials'] ) ) {
			if ( ! defined( 'GEMCLOUD_ACCESS_KEY_ID' ) && isset( $body['credentials']['access_key_id'] ) ) {
				$access = preg_replace( '/[^A-Za-z0-9]/', '', (string) $body['credentials']['access_key_id'] );
			}
			$posted = sanitize_text_field( (string) ( $body['credentials']['secret_access_key'] ?? '' ) );
			if ( ! defined( 'GEMCLOUD_SECRET_ACCESS_KEY' ) && '' !== $posted && ! Credentials::is_mask( $posted ) ) {
				$secret = $posted;
			}
		}

		return [ $settings, new Credentials( $access, $secret ) ];
	}

	private static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	private static function unavailable(): WP_Error {
		return new WP_Error( 'gemcloud_unavailable', __( 'Connect a bucket and switch cloud storage on in Settings first.', 'gemcloud' ), [ 'status' => 400 ] );
	}
}

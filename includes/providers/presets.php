<?php
namespace GemCloud\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AWS S3 and Cloudflare R2 differ only in endpoint, region and addressing,
 * so one driver plus a preset covers both, and any S3-compatible service.
 */
final class Presets {

	public static function aws( array $c ): Endpoint {
		$region = '' !== ( $c['region'] ?? '' ) ? $c['region'] : 'us-east-1';

		return new Endpoint( [
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- API endpoint of the bucket the site owner connects, not a remote asset.
			'url'          => "https://s3.{$region}.amazonaws.com",
			'region'       => $region,
			'path_style'   => 'auto', // dotted bucket names break TLS on virtual hosts
			'supports_acl' => false,  // new buckets reject ACLs; use a bucket policy or CloudFront
		] );
	}

	public static function r2( array $c ): Endpoint {
		$jurisdiction = in_array( $c['jurisdiction'] ?? '', [ 'eu', 'fedramp' ], true ) ? $c['jurisdiction'] . '.' : '';

		return new Endpoint( [
			'url'          => "https://{$c['account_id']}.{$jurisdiction}r2.cloudflarestorage.com",
			'region'       => 'auto',
			'path_style'   => true,
			'supports_acl' => false,
		] );
	}

	/**
	 * Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO and the like.
	 */
	public static function custom( array $c ): Endpoint {
		return new Endpoint( [
			'url'          => $c['url'],
			'region'       => '' !== ( $c['region'] ?? '' ) ? $c['region'] : 'us-east-1',
			'path_style'   => ! empty( $c['path_style'] ),
			'supports_acl' => false,
		] );
	}

	public static function from_settings( array $settings ): Endpoint {
		switch ( $settings['provider'] ) {
			case 'aws':
				return self::aws( $settings['endpoint'] );
			case 'custom':
				return self::custom( $settings['endpoint'] );
			default:
				return self::r2( $settings['endpoint'] );
		}
	}

	/**
	 * Labels and the fields each provider asks for, for the settings screen.
	 */
	public static function catalog(): array {
		return [
			[
				'key'         => 'r2',
				'label'       => __( 'Cloudflare R2', 'gemcloud' ),
				'description' => __( 'No egress fees. Recommended for downloads and video.', 'gemcloud' ),
				'fields'      => [ 'account_id', 'jurisdiction' ],
			],
			[
				'key'         => 'aws',
				'label'       => __( 'Amazon S3', 'gemcloud' ),
				'description' => __( 'Egress is charged per GB. Use CloudFront for public files.', 'gemcloud' ),
				'fields'      => [ 'region' ],
			],
			[
				'key'         => 'custom',
				'label'       => __( 'S3-compatible', 'gemcloud' ),
				'description' => __( 'Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO. Untested; works with any SigV4 endpoint.', 'gemcloud' ),
				'fields'      => [ 'url', 'region', 'path_style' ],
			],
		];
	}
}

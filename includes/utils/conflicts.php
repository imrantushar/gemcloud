<?php
namespace GemCloud\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Other offload plugins rewrite the same attachment URLs. When one is active
 * our media module stays off (with a notice); product contexts keep working.
 */
final class Conflicts {

	/** Plugin folder => name. */
	const KNOWN = [
		'amazon-s3-and-cloudfront'      => 'WP Offload Media',
		'amazon-s3-and-cloudfront-pro'  => 'WP Offload Media Pro',
		'advanced-media-offloader'      => 'Advanced Media Offloader',
		'media-cloud-sync'              => 'Media Cloud Sync',
		'offload-media-cloud-storage'   => 'Offload Media – Cloud Storage',
		'wp-stateless'                  => 'WP-Stateless',
		'ilab-media-tools'              => 'Media Cloud (ILAB)',
		'ilab-media-tools-premium'      => 'Media Cloud (ILAB)',
		'media-cloud'                   => 'Media Cloud',
	];

	/**
	 * @return string[] Names of active conflicting plugins.
	 */
	public static function active(): array {
		$active = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}

		$found = [];
		foreach ( $active as $plugin ) {
			$folder = strtok( (string) $plugin, '/' );
			if ( isset( self::KNOWN[ $folder ] ) ) {
				$found[] = self::KNOWN[ $folder ];
			}
		}

		/**
		 * Filters the conflicting offload plugins found.
		 *
		 * @param string[] $found Plugin names.
		 */
		return array_values( array_unique( (array) apply_filters( 'gemcloud/conflicts', $found ) ) );
	}

	public static function media_blocked(): bool {
		return ! empty( self::active() );
	}
}

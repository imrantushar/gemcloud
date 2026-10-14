<?php
namespace GemCloudStorage\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where requests go and how buckets are addressed. Presets fill it in.
 */
final class Endpoint {

	public string $url;
	public string $region;
	/** @var bool|string True, false or 'auto' (path style only for dotted bucket names). */
	public $path_style;
	public bool $supports_acl;

	public function __construct( array $args ) {
		$this->url          = untrailingslashit( (string) $args['url'] );
		$this->region       = (string) $args['region'];
		$this->path_style   = $args['path_style'] ?? true;
		$this->supports_acl = (bool) ( $args['supports_acl'] ?? false );
	}

	public function scheme(): string {
		$scheme = wp_parse_url( $this->url, PHP_URL_SCHEME );
		return $scheme ? $scheme : 'https';
	}

	public function host(): string {
		$host = (string) wp_parse_url( $this->url, PHP_URL_HOST );
		$port = wp_parse_url( $this->url, PHP_URL_PORT );
		return $port ? $host . ':' . $port : $host;
	}

	public function uses_path_style( string $bucket ): bool {
		if ( 'auto' === $this->path_style ) {
			return false !== strpos( $bucket, '.' );
		}
		return (bool) $this->path_style;
	}

	/**
	 * Host and canonical path for an object (or the bucket when $encoded_key is '').
	 *
	 * @return array{0:string,1:string}
	 */
	public function locate( string $bucket, string $encoded_key = '' ): array {
		if ( $this->uses_path_style( $bucket ) ) {
			$path = '/' . rawurlencode( $bucket ) . ( '' !== $encoded_key ? '/' . $encoded_key : '' );
			return [ $this->host(), $path ];
		}

		return [ $bucket . '.' . $this->host(), '/' . $encoded_key ];
	}

	public static function encode_key( string $key ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $key, '/' ) ) ) );
	}
}

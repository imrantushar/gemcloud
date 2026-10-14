<?php
namespace GemCloudStorage\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ObjectInfo {

	public string $key;
	public int $size;
	public string $etag;
	public string $content_type;
	public string $last_modified;

	public function __construct( string $key, int $size, string $etag = '', string $content_type = '', string $last_modified = '' ) {
		$this->key           = $key;
		$this->size          = $size;
		$this->etag          = trim( (string) preg_replace( '#^W/#i', '', $etag ), '"' );
		$this->content_type  = $content_type;
		$this->last_modified = $last_modified;
	}
}

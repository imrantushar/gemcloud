<?php
namespace GemCloud\Contracts;

use GemCloud\Providers\ObjectInfo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a storage backend must do. Methods throw Http\StorageException.
 */
interface StorageProvider {

	public function put( string $bucket, string $key, string $local_path, array $headers = [] ): ObjectInfo;

	public function head( string $bucket, string $key ): ?ObjectInfo;

	public function get( string $bucket, string $key, string $save_to ): void;

	/**
	 * @param string[] $keys Up to 1,000 per call.
	 * @return array{deleted:string[],errors:array<string,string>}
	 */
	public function delete_many( string $bucket, array $keys ): array;

	/**
	 * @return array{objects:ObjectInfo[],next_token:?string}
	 */
	public function list( string $bucket, string $prefix = '', ?string $token = null, int $max = 1000 ): array;

	public function multipart_start( string $bucket, string $key, array $headers = [] ): string;

	/** @return string ETag */
	public function multipart_part( string $bucket, string $key, string $upload_id, int $number, string $chunk ): string;

	/** @param array<int,string> $etags Part number => ETag. */
	public function multipart_finish( string $bucket, string $key, string $upload_id, array $etags ): ObjectInfo;

	public function multipart_abort( string $bucket, string $key, string $upload_id ): void;

	public function presign_get( string $bucket, string $key, int $ttl, array $response = [] ): string;

	/**
	 * Put, head and delete a probe object, plus a clock check.
	 */
	public function test( string $bucket ): array;
}

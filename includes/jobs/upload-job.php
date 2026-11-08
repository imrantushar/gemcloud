<?php
namespace GemCloud\Jobs;

use GemCloud\Http\StorageException;
use GemCloud\Items\Item;
use GemCloud\Items\ItemRepository;
use GemCloud\Manager;
use GemCloud\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uploads one object, verifies it with HEAD and marks it synced.
 *
 * Up to 64 MB goes up in one PutObject. Larger files use multipart in 16 MB
 * parts; each run uploads parts for about 20 seconds, saves the upload ID and
 * finished part ETags on the row and queues itself again, so a PHP timeout
 * loses at most one part.
 */
final class UploadJob {

	const SINGLE_PUT_LIMIT = 64 * MB_IN_BYTES;
	const PART_SIZE        = 16 * MB_IN_BYTES;
	const MAX_PARTS        = 10000;
	const TIME_BUDGET      = 20;

	public static function run( $item_id, $continuing = 0 ): void {
		$item_id = (int) $item_id;

		if ( Queue::is_paused() || ! Manager::available() ) {
			return; // The item stays pending; resume() queues it again.
		}

		$from = $continuing ? [ Item::UPLOADING ] : [ Item::PENDING, Item::FAILED ];
		if ( ! ItemRepository::claim( $item_id, $from ) ) {
			return;
		}

		$item = ItemRepository::find( $item_id );
		if ( ! $item ) {
			return;
		}

		try {
			if ( ! is_readable( $item->local_path ) ) {
				throw new StorageException( 'LocalFileMissing', sprintf( /* translators: %s: file path */ __( 'The local file %s is missing.', 'gemcloud' ), $item->local_path ) );
			}

			$size    = (int) filesize( $item->local_path );
			$headers = self::headers( $item );

			/**
			 * Filters the size above which multipart upload is used, in bytes.
			 *
			 * @param int  $limit Bytes. Default 64 MB.
			 * @param Item $item  The object.
			 */
			$limit = (int) apply_filters( 'gemcloud/multipart_threshold', self::SINGLE_PUT_LIMIT, $item );

			if ( $size <= $limit ) {
				$info = Manager::driver()->put( $item->bucket, $item->object_key, $item->local_path, $headers );
				$etag = $info->etag;
			} else {
				$etag = self::multipart( $item, $size, $headers );
				if ( null === $etag ) {
					return; // Continues in a fresh run.
				}
			}

			self::verify( $item, $size, $etag, $size <= $limit );

			$meta = $item->meta;
			unset( $meta['upload_id'], $meta['parts'], $meta['part_size'], $meta['multipart_started'] );

			ItemRepository::update( $item->id, [
				'status'     => Item::SYNCED,
				'size_bytes' => $size,
				'etag'       => trim( $etag, '"' ),
				'attempts'   => 0,
				'last_error' => '',
				'meta'       => $meta,
			] );

			Queue::record_success();

			$item = ItemRepository::find( $item->id );

			/**
			 * Fires after an object is uploaded and verified.
			 *
			 * @param Item $item The object.
			 */
			do_action( 'gemcloud/uploaded', $item );

			if ( 'attachment' === $item->source_type && ( $item->is_private() || 'keep' !== Settings::value( 'media.local_files', 'keep' ) ) ) {
				Queue::enqueue( Queue::REMOVE_LOCAL, [ $item->source_id ], 30 );
			}
		} catch ( StorageException $e ) {
			self::fail( $item, $e );
		} catch ( \Throwable $e ) {
			self::fail( $item, new StorageException( 'Unexpected', $e->getMessage() ) );
		}
	}

	/**
	 * @return string|null The final ETag, or null when the upload continues in another run.
	 */
	private static function multipart( Item $item, int $size, array $headers ): ?string {
		$driver = Manager::driver();
		$meta   = $item->meta;

		if ( empty( $meta['upload_id'] ) ) {
			// Parts are at least 16 MB; past 160 GB they grow to stay under 10,000 parts.
			$meta['part_size']         = max( self::PART_SIZE, (int) ceil( $size / self::MAX_PARTS ) );
			$meta['upload_id']         = $driver->multipart_start( $item->bucket, $item->object_key, $headers );
			$meta['parts']             = [];
			$meta['multipart_started'] = time();
			ItemRepository::update( $item->id, [ 'meta' => $meta ] );
		}

		$part_size = (int) $meta['part_size'];
		$total     = (int) ceil( $size / $part_size );
		$parts     = (array) $meta['parts'];
		$started   = microtime( true );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- chunked read of a local upload.
		$handle = fopen( $item->local_path, 'rb' );
		if ( ! $handle ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, caught and stored; never echoed as HTML.
			throw new StorageException( 'LocalFileMissing', __( 'Could not open the local file.', 'gemcloud' ) );
		}

		try {
			for ( $number = 1; $number <= $total; $number++ ) {
				if ( isset( $parts[ $number ] ) ) {
					continue;
				}

				if ( microtime( true ) - $started > self::TIME_BUDGET ) {
					$meta['parts'] = $parts;
					ItemRepository::update( $item->id, [ 'meta' => $meta ] );
					Queue::enqueue( Queue::UPLOAD, [ $item->id, 1 ] );
					return null;
				}

				fseek( $handle, ( $number - 1 ) * $part_size );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- chunked read.
				$chunk = (string) fread( $handle, $part_size );

				// R2 and S3 replace a part uploaded twice under the same number, so a retry is safe.
				$parts[ $number ] = $driver->multipart_part( $item->bucket, $item->object_key, $meta['upload_id'], $number, $chunk );
				unset( $chunk );

				$meta['parts'] = $parts;
				ItemRepository::update( $item->id, [ 'meta' => $meta ] );
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
		}

		$info = $driver->multipart_finish( $item->bucket, $item->object_key, $meta['upload_id'], $parts );

		return $info->etag;
	}

	/**
	 * Confirm the object exists with the right size; for a single PUT the
	 * ETag is the MD5 of the file.
	 */
	private static function verify( Item $item, int $size, string $etag, bool $single ): void {
		$head = Manager::driver()->head( $item->bucket, $item->object_key );

		if ( ! $head ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, caught and stored; never echoed as HTML.
			throw new StorageException( 'VerifyFailed', __( 'The upload finished but the object is not in the bucket.', 'gemcloud' ) );
		}
		if ( $head->size !== $size ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, caught and stored; never echoed as HTML.
			throw new StorageException( 'VerifyFailed', sprintf( /* translators: 1: bytes in bucket, 2: local bytes */ __( 'Size mismatch after upload: %1$d bytes in the bucket, %2$d locally.', 'gemcloud' ), $head->size, $size ) );
		}
		if ( $single && $head->etag && strtolower( $head->etag ) !== md5_file( $item->local_path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception, caught and stored; never echoed as HTML.
			throw new StorageException( 'VerifyFailed', __( 'Checksum mismatch after upload.', 'gemcloud' ) );
		}
	}

	private static function headers( Item $item ): array {
		$headers = [ 'content-type' => $item->mime_type ? $item->mime_type : 'application/octet-stream' ];

		if ( $item->is_private() ) {
			$headers['cache-control'] = 'private, max-age=0, no-store';
		} else {
			// Public keys change whenever the file changes, so they can be cached for a year.
			$headers['cache-control'] = 'public, max-age=31536000, immutable';
		}

		/**
		 * Filters the headers stored with an object.
		 *
		 * @param array $headers Lower-case header names.
		 * @param Item  $item    The object.
		 */
		return (array) apply_filters( 'gemcloud/object_headers', $headers, $item );
	}

	private static function fail( Item $item, StorageException $e ): void {
		$attempts = $item->attempts + 1;
		$fields   = [
			'attempts'   => min( 255, $attempts ),
			'last_error' => $e->error_code() . ': ' . $e->getMessage(),
		];

		switch ( $e->kind() ) {
			case StorageException::KIND_TEMPORARY:
				if ( $attempts >= Queue::MAX_ATTEMPTS ) {
					$fields['status'] = Item::FAILED;
				} else {
					$fields['status'] = Item::PENDING;
					Queue::enqueue( Queue::UPLOAD, [ $item->id ], Queue::RETRY_DELAYS[ $attempts - 1 ] ?? 21600 );
				}
				break;

			case StorageException::KIND_CLOCK:
				$fields['status'] = Item::PENDING;
				Queue::pause( 'clock', $e->hint() );
				break;

			case StorageException::KIND_CONFIGURATION:
				$fields['status'] = Item::FAILED;
				Queue::record_config_error( $e->getMessage() . ' ' . $e->hint() );
				break;

			default:
				$fields['status'] = Item::FAILED;
		}

		// A multipart upload that failed for good leaves no parts behind.
		if ( Item::FAILED === $fields['status'] && ! empty( $item->meta['upload_id'] ) ) {
			try {
				Manager::driver()->multipart_abort( $item->bucket, $item->object_key, $item->meta['upload_id'] );
			} catch ( \Throwable $ignored ) {
				// The daily cleanup aborts it later.
				unset( $ignored );
			}
			$meta = $item->meta;
			unset( $meta['upload_id'], $meta['parts'], $meta['part_size'], $meta['multipart_started'] );
			$fields['meta'] = $meta;
		}

		ItemRepository::update( $item->id, $fields );

		if ( Item::FAILED === $fields['status'] ) {
			/**
			 * Fires when an upload fails for good.
			 *
			 * @param Item             $item  The object.
			 * @param StorageException $error The last error.
			 */
			do_action( 'gemcloud/upload_failed', ItemRepository::find( $item->id ), $e );
		}
	}
}

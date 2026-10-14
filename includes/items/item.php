<?php
namespace GemCloudStorage\Items;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One stored object (one row of the items table).
 */
final class Item {

	const PENDING   = 'pending';
	const UPLOADING = 'uploading';
	const SYNCED    = 'synced';
	const FAILED    = 'failed';
	const DELETING  = 'deleting';

	const STATUSES = [ self::PENDING, self::UPLOADING, self::SYNCED, self::FAILED, self::DELETING ];

	public int $id = 0;
	public string $source_type = '';
	public int $source_id = 0;
	public string $variant = 'original';
	public string $provider = '';
	public string $bucket = '';
	public string $object_key = '';
	public string $visibility = 'public';
	public string $mime_type = '';
	public int $size_bytes = 0;
	public string $etag = '';
	public string $status = self::PENDING;
	public string $local_state = 'present';
	public string $local_path = '';
	public int $attempts = 0;
	public string $last_error = '';
	public array $meta = [];
	public string $created_at = '';
	public string $updated_at = '';

	public static function from_row( object $row ): self {
		$item = new self();
		foreach ( get_object_vars( $row ) as $key => $value ) {
			if ( ! property_exists( $item, $key ) ) {
				continue;
			}
			switch ( $key ) {
				case 'id':
				case 'source_id':
				case 'size_bytes':
				case 'attempts':
					$item->$key = (int) $value;
					break;
				case 'meta':
					$decoded    = $value ? json_decode( (string) $value, true ) : [];
					$item->meta = is_array( $decoded ) ? $decoded : [];
					break;
				default:
					$item->$key = (string) $value;
			}
		}
		return $item;
	}

	public function is_synced(): bool {
		return self::SYNCED === $this->status;
	}

	public function is_private(): bool {
		return 'private' === $this->visibility;
	}

	public function filename(): string {
		$name = $this->meta['filename'] ?? '';
		return '' !== $name ? $name : wp_basename( '' !== $this->local_path ? $this->local_path : $this->object_key );
	}

	public function to_array(): array {
		return [
			'id'          => $this->id,
			'source_type' => $this->source_type,
			'source_id'   => $this->source_id,
			'variant'     => $this->variant,
			'provider'    => $this->provider,
			'bucket'      => $this->bucket,
			'object_key'  => $this->object_key,
			'visibility'  => $this->visibility,
			'mime_type'   => $this->mime_type,
			'size_bytes'  => $this->size_bytes,
			'status'      => $this->status,
			'local_state' => $this->local_state,
			'attempts'    => $this->attempts,
			'last_error'  => $this->last_error,
			'filename'    => $this->filename(),
			'created_at'  => $this->created_at,
			'updated_at'  => $this->updated_at,
		];
	}
}

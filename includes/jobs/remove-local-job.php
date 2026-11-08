<?php
namespace GemCloud\Jobs;

use GemCloud\Items\Item;
use GemCloud\Items\ItemRepository;
use GemCloud\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offload mode: delete local copies once every variant of an attachment is
 * synced and verified. Never runs before a verified upload.
 *
 *  remove_sizes  generated sizes only; originals stay as a backup.
 *  remove_all    every file; originals come back on demand.
 */
final class RemoveLocalJob {

	public static function run( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		$items         = ItemRepository::for_source( 'attachment', $attachment_id, true );

		if ( empty( $items ) ) {
			return;
		}

		$level = self::level_for( $items );
		if ( 'keep' === $level ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( ! $item->is_synced() ) {
				return; // Wait until everything is in the bucket.
			}
		}

		foreach ( self::removable( $items, $level ) as $item ) {
			if ( 'removed' === $item->local_state ) {
				continue;
			}
			if ( '' !== $item->local_path && file_exists( $item->local_path ) ) {
				wp_delete_file( $item->local_path );
			}
			if ( ! file_exists( $item->local_path ) ) {
				$meta = $item->meta;
				unset( $meta['restored_at'] );
				ItemRepository::update( $item->id, [
					'local_state' => 'removed',
					'meta'        => $meta,
				] );
			}
		}
	}

	/**
	 * A private file left in uploads would stay publicly downloadable from
	 * this server, so private attachments are always fully offloaded.
	 *
	 * @param Item[] $items
	 */
	public static function level_for( array $items ): string {
		foreach ( $items as $item ) {
			if ( $item->is_private() ) {
				return 'remove_all';
			}
		}
		return (string) Settings::value( 'media.local_files', 'keep' );
	}

	/**
	 * @param Item[] $items
	 * @return Item[]
	 */
	public static function removable( array $items, string $level ): array {
		if ( 'remove_all' === $level ) {
			return array_values( $items );
		}

		return array_values( array_filter( $items, function ( Item $item ) {
			return ! in_array( $item->variant, [ 'original', 'original_image' ], true );
		} ) );
	}
}

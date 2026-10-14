<?php
namespace GemCloudStorage\Integrations;

use GemCloudStorage\Contexts;
use GemCloudStorage\Settings;
use GemCloudStorage\Media\MediaHooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A product that keeps some of its media library files private.
 *
 * The product's own data is the source of truth: an integration answers
 * "which of its records use this attachment?" and "may this user open it?",
 * and never stores a copy of that answer. Each integration is one context
 * on Settings → Contexts, so an admin can switch it off.
 */
abstract class Integration {

	/** Context key, also the integration's ID. */
	abstract public function key(): string;

	/** Product name shown to admins. */
	abstract public function label(): string;

	/** Whether the product is installed and active. */
	abstract public function detected(): bool;

	/** Register the context. Runs on gemcloudstorage/loaded. */
	abstract public function register_context(): void;

	/** Hook into the product. Runs once, after the context is registered. */
	abstract public function hooks(): void;

	/**
	 * The kinds of file the product keeps private, each with its own switch
	 * on Settings → Contexts.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	abstract public function kinds(): array;

	/**
	 * Why the product needs this attachment private, one line per use
	 * ("Video of lesson "Intro""), or [] when it doesn't use it.
	 *
	 * @param bool $all_kinds Count kinds that are switched off too. Access
	 *                        checks do: a file already private stays
	 *                        reachable for the people the product allows.
	 * @return string[]
	 */
	abstract public function uses( int $attachment_id, bool $all_kinds = false ): array;

	/**
	 * Whether the current user may open this attachment through the
	 * product's rules. Only asked when uses() is not empty.
	 */
	abstract public function grants( int $attachment_id ): bool;

	/**
	 * Every attachment the product currently uses, for the daily check.
	 *
	 * @return int[]
	 */
	abstract public function all_attachment_ids(): array;

	/**
	 * Contexts this product stores its own files under (Files screen filter).
	 *
	 * @return string[]
	 */
	public function contexts(): array {
		return [ $this->key() ];
	}

	/**
	 * Products may also store files outside the media library. Called by
	 * the daily check; default: nothing to do.
	 */
	public function reconcile_own_files(): void {}

	/**
	 * Switched on in Settings → Contexts, and a private bucket is set up.
	 * Without one, "private" files would land in the public bucket, so the
	 * product's files are left exactly as they are until one is added.
	 */
	public function enabled(): bool {
		return $this->switched_on() && self::has_private_bucket();
	}

	public function switched_on(): bool {
		return Contexts::enabled( $this->key() );
	}

	/**
	 * Whether files of this kind are made private (or, with $all_kinds,
	 * whether to look at them at all).
	 */
	protected function kind_on( string $kind, bool $all_kinds = false ): bool {
		return $all_kinds || Contexts::kind_enabled( $this->key(), $kind );
	}

	public static function has_private_bucket(): bool {
		return '' !== (string) Settings::value( 'buckets.private.name', '' );
	}

	/**
	 * Bring each attachment into line: mark it private for this product and
	 * move it to the private bucket when it isn't there yet.
	 *
	 * @param int[] $attachment_ids
	 */
	public function protect( array $attachment_ids ): void {
		if ( ! $this->enabled() ) {
			return;
		}
		foreach ( array_unique( array_filter( array_map( 'intval', $attachment_ids ) ) ) as $id ) {
			// Already handled: skip the lookups, this runs on page views too.
			if ( MediaHooks::is_protected( $id, $this->key() ) ) {
				continue;
			}
			if ( 'attachment' === get_post_type( $id ) && $this->uses( $id ) ) {
				MediaHooks::claim( $id, $this->key() );
			}
		}
	}
}

<?php
namespace GemCloud\Integrations;

use GemCloud\Jobs\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The products GemCloud knows how to protect files for.
 */
final class Integrations {

	/** @var Integration[] key => integration, detected products only */
	private static array $all = [];

	/** @var array<int,array<string,string[]>> per-request cache of uses() */
	private static array $uses = [];

	public static function init(): void {
		// Before plugins hooked onto gemcloud/loaded at the default priority.
		add_action( 'gemcloud/loaded', [ self::class, 'boot' ], 5 );
	}

	public static function boot(): void {
		/**
		 * Filters the built-in product integrations.
		 *
		 * @param Integration[] $integrations
		 */
		$candidates = (array) apply_filters( 'gemcloud/integrations', [ new Academy(), new StoreEngine(), new TruePlayer() ] );

		foreach ( $candidates as $integration ) {
			if ( $integration instanceof Integration && $integration->detected() ) {
				$integration->register_context();
				$integration->hooks();
				self::$all[ $integration->key() ] = $integration;
			}
		}
	}

	/**
	 * Detected and switched on in Settings → Contexts.
	 *
	 * @return Integration[]
	 */
	public static function active(): array {
		return array_filter( self::$all, function ( Integration $i ) {
			return $i->enabled();
		} );
	}

	/**
	 * Detected and switched on, private bucket or not. Some products also
	 * keep public files in the cloud, which needs no private bucket.
	 *
	 * @return Integration[]
	 */
	public static function switched_on(): array {
		return array_filter( self::$all, function ( Integration $i ) {
			return $i->switched_on();
		} );
	}

	/**
	 * Detected and switched on, but waiting for a private bucket.
	 *
	 * @return Integration[]
	 */
	public static function waiting(): array {
		return array_filter( self::$all, function ( Integration $i ) {
			return $i->switched_on() && ! $i->enabled();
		} );
	}

	/**
	 * @param Integration[] $integrations
	 */
	public static function labels( array $integrations ): string {
		return implode( ', ', array_map( function ( Integration $i ) {
			return $i->label();
		}, $integrations ) );
	}

	/**
	 * Every detected product, switched on or not.
	 *
	 * @return Integration[]
	 */
	public static function detected(): array {
		return self::$all;
	}

	public static function get( string $key ): ?Integration {
		return self::$all[ $key ] ?? null;
	}

	/**
	 * Which active products use this attachment, and how.
	 *
	 * @return array<string,string[]> integration key => reasons
	 */
	public static function uses( int $attachment_id ): array {
		if ( ! isset( self::$uses[ $attachment_id ] ) ) {
			$out = [];
			foreach ( self::active() as $key => $integration ) {
				$reasons = $integration->uses( $attachment_id );
				if ( $reasons ) {
					$out[ $key ] = $reasons;
				}
			}
			self::$uses[ $attachment_id ] = $out;
		}
		return self::$uses[ $attachment_id ];
	}

	/**
	 * Whether any product that uses the attachment lets the current user
	 * open it. Null when no active product uses it.
	 */
	public static function grants( int $attachment_id ): ?bool {
		// Every detected product, switched on or not, and every kind: turning
		// a switch off stops new files going private, it never locks students
		// out of files that already are.
		$decided = null;
		foreach ( self::$all as $integration ) {
			if ( ! $integration->uses( $attachment_id, true ) ) {
				continue;
			}
			if ( $integration->grants( $attachment_id ) ) {
				return true;
			}
			$decided = false;
		}
		return $decided;
	}

	public static function forget( int $attachment_id = 0 ): void {
		if ( $attachment_id ) {
			unset( self::$uses[ $attachment_id ] );
		} else {
			self::$uses = [];
		}
	}

	/**
	 * Queue the check that brings every product file into line.
	 */
	public static function schedule_reconcile(): void {
		if ( self::$all ) {
			Queue::enqueue( Queue::RECONCILE, [ 0 ], 10 );
		}
	}
}

<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Link tokens: 32 random bytes, URL-safe base64. Only SHA-256 hashes are stored.
 * Each email can carry a freshly issued link; earlier links stay valid (last 10 kept) until revoked.
 */
final class Tokens {
	const KEEP = 10;

	/** @return array{0: string, 1: string} [ raw token for the URL, hash for the database ] */
	public static function generate(): array {
		$raw = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		return [ $raw, self::hash( $raw ) ];
	}

	public static function hash( string $raw ): string {
		return hash( 'sha256', $raw );
	}

	/** Issue a new link token for a quotes / bookings / job_offers row and return the raw token. */
	public static function issue( string $table, int $id, bool $revoke_others = false ): string {
		[ $raw, $hash ] = self::generate();
		$data = [ 'token_hash' => $hash ];
		if ( in_array( $table, [ 'quotes', 'bookings' ], true ) ) {
			$row                  = Db::get( $table, $id );
			$previous             = $revoke_others || ! $row ? [] : array_filter( array_merge( [ $row['token_hash'] ], (array) $row['token_hashes'] ) );
			$data['token_hashes'] = array_slice( array_values( array_unique( $previous ) ), 0, self::KEEP );
		}
		Db::update( $table, $id, $data );
		return $raw;
	}

	public static function matches( string $raw, array $row ): bool {
		if ( '' === $raw ) {
			return false;
		}
		$hash = self::hash( $raw );
		foreach ( array_merge( [ $row['token_hash'] ?? '' ], (array) ( $row['token_hashes'] ?? [] ) ) as $stored ) {
			if ( is_string( $stored ) && '' !== $stored && hash_equals( $stored, $hash ) ) {
				return true;
			}
		}
		return false;
	}

	/** Short public reference with a prefix, e.g. Q-48213. Unique within the table. */
	public static function reference( string $prefix, string $table ): string {
		global $wpdb;
		for ( $i = 0; $i < 20; $i++ ) {
			$ref = $prefix . '-' . random_int( 10000, 99999 );
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( $table ) . ' WHERE ref = %s', $ref ) ) ) {
				return $ref;
			}
		}
		return $prefix . '-' . random_int( 100000, 999999 );
	}
}

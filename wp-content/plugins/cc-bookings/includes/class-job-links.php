<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Signed links for cleaners, no login needed:
 *   /job/{offer_id}/{sig}/        one job (offer, then the job itself once accepted or assigned)
 *   /job/me/{cleaner_id}/{sig}/   "My upcoming jobs"
 *
 * sig = HMAC-SHA256( "offer|{id}|{key}" ) with the site's secret auth salt, where key is a random per-row
 * value kept in the row's token_hash column. Links can be re-created for any email without storing them,
 * can't be forged from the database alone, and are revoked by rotating the key.
 * A job link also opens with the owning cleaner's own signature, so "My jobs" can link to every job.
 */
final class Job_Links {
	public static function key( string $table, int $id, bool $rotate = false ): string {
		$row = Db::get( $table, $id );
		if ( ! $row ) {
			return '';
		}
		if ( ! $rotate && preg_match( '/^[a-f0-9]{64}$/', (string) $row['token_hash'] ) ) {
			return $row['token_hash'];
		}
		$key = bin2hex( random_bytes( 32 ) );
		Db::update( $table, $id, [ 'token_hash' => $key ] );
		return $key;
	}

	public static function sign( string $kind, int $id, string $key ): string {
		return rtrim( strtr( base64_encode( hash_hmac( 'sha256', $kind . '|' . $id . '|' . $key, wp_salt( 'auth' ), true ) ), '+/', '-_' ), '=' );
	}

	public static function offer_url( int $offer_id ): string {
		$key = self::key( 'job_offers', $offer_id );
		return $key ? home_url( '/job/' . $offer_id . '/' . self::sign( 'offer', $offer_id, $key ) . '/' ) : '';
	}

	public static function cleaner_url( int $cleaner_id ): string {
		$key = self::key( 'cleaners', $cleaner_id );
		return $key ? home_url( '/job/me/' . $cleaner_id . '/' . self::sign( 'cleaner', $cleaner_id, $key ) . '/' ) : '';
	}

	/** A job page opened from "My jobs" (signed with the cleaner's key). */
	public static function offer_url_for_cleaner( int $offer_id, int $cleaner_id ): string {
		$key = self::key( 'cleaners', $cleaner_id );
		return home_url( '/job/' . $offer_id . '/' . self::sign( 'cleaner', $cleaner_id, $key ) . '/' );
	}

	public static function verify_cleaner( int $cleaner_id, string $sig ): ?array {
		$cleaner = Cleaners::get( $cleaner_id );
		if ( ! $cleaner || 'active' !== $cleaner['status'] || ! self::matches( 'cleaner', $cleaner ) ) {
			return null;
		}
		return hash_equals( self::sign( 'cleaner', $cleaner_id, $cleaner['token_hash'] ), $sig ) ? $cleaner : null;
	}

	public static function verify_offer( int $offer_id, string $sig ): ?array {
		$offer = Db::get( 'job_offers', $offer_id );
		if ( ! $offer || '' === $sig ) {
			return null;
		}
		if ( self::matches( 'offer', $offer ) && hash_equals( self::sign( 'offer', $offer_id, $offer['token_hash'] ), $sig ) ) {
			return $offer;
		}
		return self::verify_cleaner( (int) $offer['cleaner_id'], $sig ) ? $offer : null;
	}

	public static function revoke( string $table, int $id ): void {
		self::key( $table, $id, true );
	}

	private static function matches( string $kind, array $row ): bool {
		return (bool) preg_match( '/^[a-f0-9]{64}$/', (string) ( $row['token_hash'] ?? '' ) );
	}
}

<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * UK postcode parsing and coverage, using the same Areas data (postcodes field) as the area finder.
 */
final class Postcodes {
	const FULL = '/^([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})$/';

	public static function normalise( string $raw ): string {
		$compact = strtoupper( preg_replace( '/\s+/', '', $raw ) );
		return preg_match( self::FULL, $compact, $m ) ? $m[1] . ' ' . $m[2] : $compact;
	}

	public static function is_full( string $raw ): bool {
		return (bool) preg_match( self::FULL, strtoupper( preg_replace( '/\s+/', '', $raw ) ) );
	}

	/** Outward code ("HU5") from a full postcode or a district on its own. */
	public static function district( string $raw ): string {
		$compact = strtoupper( preg_replace( '/\s+/', '', $raw ) );
		if ( preg_match( self::FULL, $compact, $m ) ) {
			return $m[1];
		}
		return preg_match( '/^[A-Z]{1,2}\d[A-Z\d]?$/', $compact ) ? $compact : '';
	}

	public static function covered_districts(): array {
		static $districts = null;
		if ( null !== $districts ) {
			return $districts;
		}
		$districts = [];
		$areas     = get_posts( [ 'post_type' => 'cc_area', 'post_status' => 'publish', 'posts_per_page' => -1, 'no_found_rows' => true ] );
		foreach ( $areas as $area ) {
			$text      = (string) get_post_meta( $area->ID, 'postcodes', true );
			$districts = array_merge( $districts, function_exists( 'cc_postcode_districts' ) ? cc_postcode_districts( $text ) : self::parse_list( $text ) );
		}
		$districts = array_values( array_unique( apply_filters( 'cc_bookings_covered_districts', $districts ) ) );
		return $districts;
	}

	public static function is_covered( string $raw ): bool {
		$district = self::district( $raw );
		return $district && in_array( $district, self::covered_districts(), true );
	}

	/** Fallback for "HU1–HU9" / "YO15, YO16" if the theme helper is missing. */
	private static function parse_list( string $text ): array {
		$out = [];
		foreach ( preg_split( '/\s*,\s*/', strtoupper( trim( $text ) ) ) as $part ) {
			if ( preg_match( '/^([A-Z]{1,2})(\d{1,2})\s*[–-]\s*(?:[A-Z]{1,2})?(\d{1,2})$/u', $part, $m ) ) {
				for ( $i = (int) $m[2]; $i <= (int) $m[3]; $i++ ) {
					$out[] = $m[1] . $i;
				}
			} elseif ( preg_match( '/^[A-Z]{1,2}\d{1,2}[A-Z]?$/', $part ) ) {
				$out[] = $part;
			}
		}
		return $out;
	}
}

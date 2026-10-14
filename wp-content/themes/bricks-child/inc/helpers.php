<?php
/**
 * Helpers for reading Business Settings and formatting shared values.
 * Functions used in Bricks via {echo:...} must also be allow-listed in inc/bricks.php.
 */

defined( 'ABSPATH' ) || exit;

function cc_option( string $name, $default = '' ) {
	if ( ! function_exists( 'get_field' ) ) {
		return $default;
	}
	$value = get_field( $name, 'option' );
	return ( $value === null || $value === '' || $value === false ) ? $default : $value;
}

function cc_brand_name(): string {
	return (string) cc_option( 'brand_name', get_bloginfo( 'name' ) );
}

function cc_phone_display(): string {
	return (string) cc_option( 'phone_display', cc_option( 'phone' ) );
}

function cc_phone_href(): string {
	$raw = preg_replace( '/[^0-9+]/', '', (string) cc_option( 'phone' ) );
	return $raw ? 'tel:' . $raw : '';
}

function cc_phone_link(): string {
	$href = cc_phone_href();
	if ( ! $href ) {
		return '';
	}
	return sprintf( '<a class="cc-phone-link" href="%s">%s</a>', esc_attr( $href ), esc_html( cc_phone_display() ) );
}

function cc_whatsapp_href(): string {
	$raw = preg_replace( '/[^0-9]/', '', (string) cc_option( 'whatsapp' ) );
	return $raw ? 'https://wa.me/' . $raw : '';
}

function cc_years_trading(): string {
	$founded = (int) cc_option( 'founded_year' );
	if ( ! $founded ) {
		return '';
	}
	return (string) max( 1, (int) wp_date( 'Y' ) - $founded );
}

function cc_full_address(): string {
	$parts = array_filter( [
		cc_option( 'address_street' ),
		cc_option( 'address_locality' ),
		cc_option( 'address_region' ),
		cc_option( 'postcode' ),
	] );
	return implode( ', ', $parts );
}

/**
 * Opening hours rows normalised to [ 'days' => ['Mon', ...], 'opens' => 'H:i', 'closes' => 'H:i' ].
 */
function cc_opening_hours(): array {
	$rows = cc_option( 'opening_hours', [] );
	if ( ! is_array( $rows ) ) {
		return [];
	}
	return array_values( array_filter( array_map( function ( $row ) {
		if ( empty( $row['days'] ) || empty( $row['opens'] ) || empty( $row['closes'] ) ) {
			return null;
		}
		return [
			'days'   => (array) $row['days'],
			'opens'  => $row['opens'],
			'closes' => $row['closes'],
		];
	}, $rows ) ) );
}

function cc_opening_hours_today(): string {
	$today = wp_date( 'D' );
	foreach ( cc_opening_hours() as $row ) {
		if ( in_array( $today, $row['days'], true ) ) {
			return sprintf( 'Open today %s–%s', $row['opens'], $row['closes'] );
		}
	}
	return 'Closed today';
}

function cc_google_rating(): string {
	$rating = (float) cc_option( 'google_rating' );
	return $rating ? number_format( $rating, 1 ) : '';
}

function cc_review_count(): string {
	$count = (int) cc_option( 'review_count' );
	return $count ? number_format( $count ) : '';
}

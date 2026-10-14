<?php
/**
 * Photos and visual blocks: hero backgrounds, the "why us" collage, stats, marquee and icons.
 * Heroes use the post's featured image (or its SCF hero_image), falling back to the parent service.
 */

defined( 'ABSPATH' ) || exit;

function cc_attachment_id( $value ): int {
	if ( is_array( $value ) ) {
		return (int) ( $value['ID'] ?? $value['id'] ?? 0 );
	}
	return (int) $value;
}

function cc_post_image_id( int $post_id ): int {
	if ( ! $post_id ) {
		return 0;
	}
	foreach ( array_unique( [ $post_id, cc_parent_service_id( $post_id ) ] ) as $id ) {
		$image = cc_attachment_id( cc_get( 'hero_image', $id ) ) ?: (int) get_post_thumbnail_id( $id );
		if ( $image ) {
			return $image;
		}
	}
	$parts = cc_local_service_parts( $post_id );
	return $parts ? (int) get_post_thumbnail_id( $parts['area'] ) : 0;
}

/**
 * Full-bleed background photo for heroes and bands.
 */
function cc_cover_image_html( int $attachment, bool $priority = false ): string {
	if ( ! $attachment ) {
		return '';
	}
	return wp_get_attachment_image( $attachment, 'full', false, [
		'class'         => 'cc-cover__img',
		'sizes'         => '100vw',
		'loading'       => $priority ? 'eager' : 'lazy',
		'fetchpriority' => $priority ? 'high' : 'auto',
		'decoding'      => $priority ? 'sync' : 'async',
	] );
}

function cc_hero_media_html( int $post_id ): string {
	return cc_cover_image_html( cc_post_image_id( $post_id ), true );
}

function cc_option_image_html( string $field ): string {
	return cc_cover_image_html( cc_attachment_id( cc_option( $field ) ) );
}

function cc_card_image_html( int $attachment, string $sizes = '(max-width: 640px) 100vw, 400px' ): string {
	if ( ! $attachment ) {
		return '';
	}
	return wp_get_attachment_image( $attachment, 'cc-card', false, [ 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => $sizes ] );
}

function cc_icon( string $name ): string {
	return '<span class="cc-icon cc-icon--' . esc_attr( $name ) . '" aria-hidden="true"></span>';
}

/**
 * Overlapping photos with a guarantee badge, for the home page "why us" section.
 */
function cc_why_media_html(): string {
	$main = cc_attachment_id( cc_option( 'why_image_main' ) );
	if ( ! $main ) {
		return '';
	}
	$detail = cc_attachment_id( cc_option( 'why_image_detail' ) );
	$hours  = (int) cc_option( 'reclean_window_hours', 72 );

	$out  = '<div class="cc-collage">';
	$out .= '<div class="cc-collage__main">' . wp_get_attachment_image( $main, 'large', false, [ 'loading' => 'lazy', 'sizes' => '(max-width: 1024px) 100vw, 560px' ] ) . '</div>';
	if ( $detail ) {
		$out .= '<div class="cc-collage__detail">' . wp_get_attachment_image( $detail, 'cc-card', false, [ 'loading' => 'lazy', 'sizes' => '260px' ] ) . '</div>';
	}
	$out .= sprintf(
		'<div class="cc-collage__badge">%s<span><strong>%d-hour</strong> re-clean guarantee</span></div>',
		cc_icon( 'rotate' ),
		$hours
	);
	return $out . '</div>';
}

/**
 * Headline numbers. Only facts the site can back up: service and area counts, guarantee window, opening days, rating.
 */
function cc_stats_html(): string {
	$stats = [];
	if ( cc_google_rating() ) {
		$stats[] = [ cc_google_rating(), '★', 'Google rating', 1 ];
	}
	$stats[] = [ count( cc_services() ), '', 'Cleaning services', 0 ];
	$stats[] = [ count( cc_areas() ), '', 'Towns & areas covered', 0 ];
	$stats[] = [ (int) cc_option( 'reclean_window_hours', 72 ), 'hr', 'Re-clean guarantee', 0 ];

	$days = array_unique( array_merge( [], ...array_column( cc_opening_hours(), 'days' ) ) );
	if ( $days ) {
		$stats[] = [ count( $days ), '', 'Days a week', 0 ];
	}

	$out = '<dl class="cc-stats">';
	foreach ( $stats as [ $value, $suffix, $label, $decimals ] ) {
		$out .= sprintf(
			'<div class="cc-stat"><dt class="cc-stat__label">%s</dt><dd class="cc-stat__value"><span data-count="%s" data-decimals="%d">%s</span>%s</dd></div>',
			esc_html( $label ),
			esc_attr( $value ),
			$decimals,
			esc_html( $value ),
			$suffix ? '<span class="cc-stat__suffix">' . esc_html( $suffix ) . '</span>' : ''
		);
	}
	return $out . '</dl>';
}

/**
 * Scrolling strip of towns. The list is duplicated so the loop is seamless; the copy is hidden from screen readers.
 */
function cc_marquee_html(): string {
	$areas = cc_areas();
	if ( ! $areas ) {
		return '';
	}
	$items = implode( '', array_map( fn( $a ) => sprintf( '<li><a href="%s" tabindex="-1">%s</a></li>', esc_url( get_permalink( $a ) ), esc_html( get_the_title( $a ) ) ), $areas ) );
	return '<div class="cc-marquee"><p class="cc-marquee__label">Cleaning across</p><div class="cc-marquee__viewport"><ul class="cc-marquee__track">' . $items . '</ul><ul class="cc-marquee__track" aria-hidden="true">' . $items . '</ul></div></div>';
}

/**
 * Audience promos (landlords, businesses, holiday hosts) with photos from the linked pages.
 */
function cc_promos_html(): string {
	$promos = [
		[ 'page', 'landlords', 'Landlords & letting agents', 'Fast, reliable property turnarounds', 'End of tenancy, inventory-standard cleans, communal areas and invoicing direct to you.', 'Landlord services' ],
		[ 'page', 'business', 'Businesses', 'Office & commercial cleaning', 'Out-of-hours cleaning with a dedicated team, fixed monthly price and full compliance paperwork.', 'Business services' ],
		[ 'cc_service', 'holiday-let-airbnb-cleaning', 'Holiday hosts', 'Changeovers on the coast', 'Linen, restocking and photo reports for holiday lets in Bridlington, Hornsea and beyond.', 'Holiday let cleaning' ],
	];

	$out = '<div class="cc-promos">';
	foreach ( $promos as [ $type, $slug, $eyebrow, $title, $text, $link ] ) {
		$post = get_page_by_path( $slug, OBJECT, $type );
		if ( ! $post ) {
			continue;
		}
		$out .= sprintf(
			'<a class="cc-promo" href="%s"><span class="cc-promo__media">%s</span><span class="cc-promo__body"><span class="cc-eyebrow">%s</span><span class="cc-promo__title">%s</span><span class="cc-promo__text">%s</span><span class="cc-promo__link">%s</span></span></a>',
			esc_url( get_permalink( $post ) ),
			cc_card_image_html( cc_post_image_id( $post->ID ), '(max-width: 768px) 100vw, 420px' ),
			esc_html( $eyebrow ),
			esc_html( $title ),
			esc_html( $text ),
			esc_html( $link )
		);
	}
	return $out . '</div>';
}

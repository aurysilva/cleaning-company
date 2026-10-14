<?php
/**
 * JSON-LD structured data built from Business Settings and SCF fields.
 * If Rank Math's Local SEO module later outputs the LocalBusiness node, disable ours with:
 * add_filter( 'cc_output_business_schema', '__return_false' );
 */

defined( 'ABSPATH' ) || exit;

function cc_business_id(): string {
	return home_url( '/#business' );
}

function cc_schema_business(): array {
	$days_map = [
		'Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday',
		'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday',
	];

	$hours = array_map( function ( $row ) use ( $days_map ) {
		return [
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => array_values( array_intersect_key( $days_map, array_flip( $row['days'] ) ) ),
			'opens'     => $row['opens'],
			'closes'    => $row['closes'],
		];
	}, cc_opening_hours() );

	$logo   = cc_option( 'logo' );
	$social = array_filter( wp_list_pluck( (array) cc_option( 'social_links', [] ), 'url' ) );

	$node = [
		'@type'      => 'LocalBusiness',
		'@id'        => cc_business_id(),
		'name'       => cc_brand_name(),
		'url'        => home_url( '/' ),
		'telephone'  => cc_option( 'phone' ),
		'email'      => cc_option( 'email' ),
		'priceRange' => '££',
		'address'    => array_filter( [
			'@type'           => 'PostalAddress',
			'streetAddress'   => cc_option( 'address_street' ),
			'addressLocality' => cc_option( 'address_locality' ),
			'addressRegion'   => cc_option( 'address_region' ),
			'postalCode'      => cc_option( 'postcode' ),
			'addressCountry'  => 'GB',
		] ),
		'areaServed'                => cc_schema_areas(),
		'openingHoursSpecification' => $hours,
		'sameAs'                    => array_values( array_filter( array_merge( [ cc_option( 'google_business_url' ) ], $social ) ) ),
	];

	if ( is_array( $logo ) && ! empty( $logo['url'] ) ) {
		$node['logo'] = $logo['url'];
		$node['image'] = $logo['url'];
	}

	$lat = cc_option( 'latitude' );
	$lng = cc_option( 'longitude' );
	if ( $lat && $lng ) {
		$node['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng ];
	}

	return array_filter( $node );
}

function cc_schema_areas(): array {
	$areas = get_posts( [
		'post_type'      => 'cc_area',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	] );

	return array_map( fn( $area ) => [ '@type' => 'Place', 'name' => get_the_title( $area ) ], $areas );
}

function cc_schema_faq( int $post_id ): ?array {
	$faqs = function_exists( 'get_field' ) ? get_field( 'faqs', $post_id ) : null;
	if ( empty( $faqs ) || ! is_array( $faqs ) ) {
		return null;
	}

	$entities = [];
	foreach ( $faqs as $faq ) {
		if ( empty( $faq['question'] ) || empty( $faq['answer'] ) ) {
			continue;
		}
		$entities[] = [
			'@type'          => 'Question',
			'name'           => wp_strip_all_tags( $faq['question'] ),
			'acceptedAnswer' => [ '@type' => 'Answer', 'text' => wp_kses_post( $faq['answer'] ) ],
		];
	}

	return $entities ? [ '@type' => 'FAQPage', 'mainEntity' => $entities ] : null;
}

function cc_schema_service( int $service_id, ?WP_Post $area = null ): array {
	$name  = get_field( 'schema_service_type', $service_id ) ?: get_the_title( $service_id );
	$price = get_field( 'price_from', $service_id );

	$node = [
		'@type'       => 'Service',
		'name'        => $area ? sprintf( '%s in %s', $name, get_the_title( $area ) ) : $name,
		'serviceType' => $name,
		'description' => get_field( 'short_description', $service_id ) ?: get_the_excerpt( $service_id ),
		'url'         => get_permalink(),
		'provider'    => [ '@id' => cc_business_id() ],
		'areaServed'  => $area ? [ '@type' => 'Place', 'name' => get_the_title( $area ) ] : cc_schema_areas(),
	];

	if ( $price ) {
		$node['offers'] = [
			'@type'         => 'Offer',
			'priceCurrency' => 'GBP',
			'price'         => (string) $price,
			'description'   => trim( 'From £' . $price . ' ' . (string) get_field( 'price_unit', $service_id ) ),
		];
	}

	return array_filter( $node );
}

add_action( 'wp_head', function () {
	if ( ! function_exists( 'get_field' ) || is_admin() ) {
		return;
	}

	$graph = [];

	if ( apply_filters( 'cc_output_business_schema', true ) && ( is_front_page() || is_singular( [ 'cc_area', 'page' ] ) ) ) {
		$graph[] = cc_schema_business();
	}

	if ( is_singular( 'cc_service' ) ) {
		$graph[] = cc_schema_service( get_the_ID() );
	}

	if ( is_singular( 'cc_local_service' ) ) {
		$parts = cc_local_service_parts( get_the_ID() );
		if ( $parts ) {
			$graph[] = cc_schema_service( $parts['service']->ID, $parts['area'] );
		}
	}

	if ( is_singular( [ 'cc_service', 'cc_local_service', 'cc_area', 'page' ] ) ) {
		$faq = cc_schema_faq( get_the_ID() );
		if ( $faq ) {
			$graph[] = $faq;
		}
	}

	if ( ! $graph ) {
		return;
	}

	printf(
		"<script type=\"application/ld+json\">%s</script>\n",
		wp_json_encode( [ '@context' => 'https://schema.org', '@graph' => $graph ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);
}, 20 );

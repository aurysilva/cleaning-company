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
		'priceRange' => cc_show_prices() ? '££' : '',
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

	if ( $price && cc_show_prices() ) {
		$node['offers'] = [
			'@type'         => 'Offer',
			'priceCurrency' => 'GBP',
			'price'         => (string) $price,
			'description'   => trim( 'From £' . $price . ' ' . (string) get_field( 'price_unit', $service_id ) ),
		];
	}

	return array_filter( $node );
}

function cc_schema_article( int $post_id ): array {
	$image = get_the_post_thumbnail_url( $post_id, 'full' );
	$term  = cc_guide_category( $post_id );

	return array_filter( [
		'@type'            => 'BlogPosting',
		'@id'              => get_permalink( $post_id ) . '#article',
		'headline'         => get_the_title( $post_id ),
		'description'      => cc_seo_description( $post_id ),
		'image'            => $image ?: null,
		'datePublished'    => get_post_time( 'c', true, $post_id ),
		'dateModified'     => get_post_modified_time( 'c', true, $post_id ),
		'wordCount'        => str_word_count( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ) ),
		'articleSection'   => $term ? $term->name : null,
		'inLanguage'       => 'en-GB',
		'mainEntityOfPage' => get_permalink( $post_id ),
		'author'           => [ '@type' => 'Organization', 'name' => cc_brand_name(), 'url' => home_url( '/about/' ) ],
		'publisher'        => [ '@id' => cc_business_id() ],
		'about'            => ( $service = cc_guide_service( $post_id ) ) ? [ '@type' => 'Service', 'name' => get_the_title( $service ), 'url' => get_permalink( $service ) ] : null,
	] );
}

function cc_schema_breadcrumbs( int $post_id ): array {
	$items = [ [ 'Home', home_url( '/' ) ], [ 'Guides', home_url( '/guides/' ) ] ];
	$term  = cc_guide_category( $post_id );
	if ( $term ) {
		$items[] = [ $term->name, cc_guide_category_url( $term ) ];
	}
	$items[] = [ get_the_title( $post_id ), get_permalink( $post_id ) ];

	return [
		'@type'           => 'BreadcrumbList',
		'itemListElement' => array_map(
			fn( $item, $i ) => [ '@type' => 'ListItem', 'position' => $i + 1, 'name' => $item[0], 'item' => $item[1] ],
			$items,
			array_keys( $items )
		),
	];
}

add_action( 'wp_head', function () {
	if ( ! function_exists( 'get_field' ) || is_admin() ) {
		return;
	}

	$graph = [];

	if ( is_singular( 'post' ) ) {
		$graph[] = cc_schema_business();
		$graph[] = cc_schema_article( get_the_ID() );
		$graph[] = cc_schema_breadcrumbs( get_the_ID() );
	}

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

	if ( is_singular( [ 'cc_service', 'cc_local_service', 'cc_area', 'page', 'post' ] ) ) {
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

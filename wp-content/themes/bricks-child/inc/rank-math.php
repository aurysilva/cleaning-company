<?php
/**
 * Rank Math: keep schema off (theme outputs JSON-LD), sync Business Settings into Rank Math,
 * and mirror guide SEO fields into Rank Math's editor boxes.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'RANK_MATH_VERSION' ) ) {
	return;
}

// Theme schema is richer (services, FAQs, areas); Rank Math's schema module stays disabled
add_filter( 'rank_math/json_ld', static fn() => [], 99 );

/**
 * Push Northbank Cleaning business settings into Rank Math's Local SEO / Knowledge Graph fields.
 */
function cc_rank_math_sync_business(): void {
	$day_map = [
		'Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday', 'Thu' => 'Thursday',
		'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday',
	];

	$hours = [];
	foreach ( cc_opening_hours() as $row ) {
		$range = $row['opens'] . '-' . $row['closes'];
		foreach ( $row['days'] as $day ) {
			$hours[] = [
				'day'  => $day_map[ $day ] ?? $day,
				'time' => $range,
			];
		}
	}

	$logo = cc_option( 'logo' );
	$logo_id = is_array( $logo ) && ! empty( $logo['ID'] ) ? (int) $logo['ID'] : ( is_numeric( $logo ) ? (int) $logo : 0 );

	$titles = get_option( 'rank-math-options-titles', [] );
	$titles = array_merge( $titles, [
		'knowledgegraph_type'        => 'company',
		'knowledgegraph_name'        => cc_brand_name(),
		'website_name'               => cc_brand_name(),
		'url'                        => home_url( '/' ),
		'email'                      => (string) cc_option( 'email' ),
		'phone'                      => cc_phone_display(),
		'local_business_type'        => 'HousekeepingService',
		'organization_description'   => cc_tagline() ?: ( (string) cc_option( 'hero_subheading', '' ) ?: 'Professional cleaners in Hull and East Yorkshire.' ),
		'local_address'              => array_filter( [
			'streetAddress'   => (string) cc_option( 'address_street' ),
			'addressLocality' => (string) cc_option( 'address_locality' ),
			'addressRegion'   => (string) cc_option( 'address_region' ),
			'postalCode'      => (string) cc_option( 'postcode' ),
			'addressCountry'  => 'GB',
		] ),
		'opening_hours'              => $hours,
		'local_address_format'       => '{address} {locality}, {region} {postalcode}',
	] );

	if ( $logo_id ) {
		$titles['knowledgegraph_logo'] = $logo_id;
	}

	$og_id = (int) get_option( 'cc_brand_og_image_id', 0 );
	if ( $og_id ) {
		$titles['open_graph_image_id'] = $og_id;
		$titles['open_graph_image']    = wp_get_attachment_image_url( $og_id, 'full' ) ?: '';
	} elseif ( $logo_id && empty( $titles['open_graph_image_id'] ) ) {
		$titles['open_graph_image_id'] = $logo_id;
		$titles['open_graph_image']    = wp_get_attachment_image_url( $logo_id, 'large' );
	}

	$lat = cc_option( 'latitude' );
	$lng = cc_option( 'longitude' );
	if ( $lat && $lng ) {
		$titles['geo'] = trim( $lat ) . ',' . trim( $lng );
	}

	update_option( 'rank-math-options-titles', $titles );
}

/**
 * Mirror a post's theme SEO fields into Rank Math meta (only when Rank Math fields are empty).
 */
function cc_rank_math_sync_post_seo( int $post_id ): void {
	if ( wp_is_post_revision( $post_id ) || ! in_array( get_post_type( $post_id ), [ 'post', 'page', 'cc_service', 'cc_area', 'cc_local_service' ], true ) ) {
		return;
	}

	if ( ! get_post_meta( $post_id, 'rank_math_title', true ) ) {
		$title = cc_seo_title( $post_id );
		if ( $title ) {
			update_post_meta( $post_id, 'rank_math_title', $title );
		}
	}

	if ( ! get_post_meta( $post_id, 'rank_math_description', true ) ) {
		$desc = cc_seo_description( $post_id );
		if ( $desc ) {
			update_post_meta( $post_id, 'rank_math_description', $desc );
		}
	}

	if ( get_post_type( $post_id ) === 'post' && function_exists( 'cc_rank_math_sync_guide' ) ) {
		cc_rank_math_sync_guide( $post_id, false );
	}
}

add_action( 'acf/save_post', function ( $post_id ) {
	if ( $post_id === 'options' ) {
		cc_rank_math_sync_business();
		return;
	}
	if ( is_numeric( $post_id ) ) {
		cc_rank_math_sync_post_seo( (int) $post_id );
	}
}, 20 );

add_action( 'save_post', function ( $post_id ) {
	if ( ! wp_is_post_revision( $post_id ) ) {
		cc_rank_math_sync_post_seo( (int) $post_id );
	}
}, 20, 1 );

// GSC / GA dashboard reports need a one-time Google sign-in in wp-admin (cannot be done over SSH)
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'toplevel_page_rank-math' ], true ) ) {
		return;
	}
	if ( ! class_exists( '\RankMath\Google\Console' ) || \RankMath\Google\Console::is_console_connected() ) {
		return;
	}
	$url = admin_url( 'admin.php?page=rank-math-options-general&view=analytics' );
	printf(
		'<div class="notice notice-info is-dismissible"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
		esc_html__( 'Rank Math analytics', 'bricks-child' ),
		esc_html__( 'Connect your Google account to see Search Console and GA4 reports inside WordPress. Front-end tracking still uses the cookie banner and your GA4 measurement ID.', 'bricks-child' ),
		esc_url( $url ),
		esc_html__( 'Connect in Rank Math → Analytics', 'bricks-child' )
	);
} );

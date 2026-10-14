<?php
/**
 * Local Service URLs: /services/{service-slug}/{area-slug}/
 *
 * Each cc_local_service post stores its parent service and area IDs in the
 * `service` and `area` fields; the URL is resolved by looking those up.
 * Flush permalinks after changing these rules (Settings → Permalinks → Save).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	add_rewrite_rule(
		'^services/([^/]+)/([^/]+)/?$',
		'index.php?cc_ls_service=$matches[1]&cc_ls_area=$matches[2]',
		'top'
	);
}, 20 );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'cc_ls_service';
	$vars[] = 'cc_ls_area';
	return $vars;
} );

function cc_local_service_parts( int $post_id ): ?array {
	$service = get_post( (int) get_post_meta( $post_id, 'service', true ) );
	$area    = get_post( (int) get_post_meta( $post_id, 'area', true ) );

	if ( ! $service || ! $area || ! $service->post_name || ! $area->post_name ) {
		return null;
	}

	return [ 'service' => $service, 'area' => $area ];
}

function cc_find_local_service( string $service_slug, string $area_slug ): ?WP_Post {
	$service = get_page_by_path( $service_slug, OBJECT, 'cc_service' );
	$area    = get_page_by_path( $area_slug, OBJECT, 'cc_area' );
	if ( ! $service || ! $area ) {
		return null;
	}

	$posts = get_posts( [
		'post_type'      => 'cc_local_service',
		'post_status'    => current_user_can( 'edit_posts' ) ? [ 'publish', 'draft', 'pending', 'private', 'future' ] : 'publish',
		'posts_per_page' => 1,
		'no_found_rows'  => true,
		'meta_query'     => [
			[ 'key' => 'service', 'value' => $service->ID ],
			[ 'key' => 'area', 'value' => $area->ID ],
		],
	] );

	return $posts[0] ?? null;
}

add_filter( 'request', function ( $vars ) {
	if ( empty( $vars['cc_ls_service'] ) || empty( $vars['cc_ls_area'] ) ) {
		return $vars;
	}

	$post = cc_find_local_service( $vars['cc_ls_service'], $vars['cc_ls_area'] );
	if ( ! $post ) {
		return [ 'error' => '404' ];
	}

	return [
		'post_type'        => 'cc_local_service',
		'p'                => $post->ID,
		'cc_local_service' => $post->post_name,
	];
} );

add_filter( 'post_type_link', function ( $link, $post ) {
	if ( $post->post_type !== 'cc_local_service' ) {
		return $link;
	}

	$parts = cc_local_service_parts( $post->ID );
	if ( ! $parts ) {
		return $link;
	}

	return home_url( user_trailingslashit( "services/{$parts['service']->post_name}/{$parts['area']->post_name}" ) );
}, 10, 2 );

/**
 * Keep the admin slug readable ("end-of-tenancy-cleaning-hull") once service and area are chosen.
 */
add_action( 'acf/save_post', function ( $post_id ) {
	if ( ! is_numeric( $post_id ) || get_post_type( $post_id ) !== 'cc_local_service' ) {
		return;
	}

	$parts = cc_local_service_parts( (int) $post_id );
	if ( ! $parts ) {
		return;
	}

	$slug = sanitize_title( $parts['service']->post_name . '-' . $parts['area']->post_name );
	if ( get_post_field( 'post_name', $post_id ) !== $slug ) {
		wp_update_post( [ 'ID' => (int) $post_id, 'post_name' => $slug ] );
	}
}, 20 );

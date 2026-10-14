<?php
/**
 * While prices are hidden (Business Settings > Booking & CTAs > Show prices), the Pricing page
 * is removed from menus and sitemaps and redirects to the contact page.
 */

defined( 'ABSPATH' ) || exit;

function cc_pricing_page_id(): int {
	$page = get_page_by_path( 'pricing', OBJECT, 'page' );
	return $page ? (int) $page->ID : 0;
}

add_filter( 'wp_nav_menu_objects', function ( $items ) {
	if ( cc_show_prices() || ! ( $pricing = cc_pricing_page_id() ) ) {
		return $items;
	}
	return array_filter( $items, fn( $item ) => ! ( $item->object === 'page' && (int) $item->object_id === $pricing ) );
} );

add_action( 'template_redirect', function () {
	if ( ! cc_show_prices() && is_page( 'pricing' ) && ! current_user_can( 'edit_pages' ) ) {
		wp_safe_redirect( home_url( '/contact/' ), 302 );
		exit;
	}
} );

add_filter( 'wp_sitemaps_posts_query_args', function ( $args, $post_type ) {
	if ( $post_type === 'page' && ! cc_show_prices() && ( $pricing = cc_pricing_page_id() ) ) {
		$args['post__not_in'] = array_merge( $args['post__not_in'] ?? [], [ $pricing ] );
	}
	return $args;
}, 10, 2 );

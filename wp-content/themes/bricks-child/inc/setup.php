<?php
/**
 * Theme supports, image sizes and asset loading.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );

	add_image_size( 'cc-hero', 1920, 1080, true );
	add_image_size( 'cc-before-after', 1200, 900, true );
	add_image_size( 'cc-card', 640, 480, true );
	add_image_size( 'cc-thumb', 320, 240, true );
} );

add_filter( 'image_size_names_choose', function ( $sizes ) {
	return array_merge( $sizes, [
		'cc-hero'         => 'Hero (1920×1080)',
		'cc-before-after' => 'Before / After (1200×900)',
		'cc-card'         => 'Card (640×480)',
		'cc-thumb'        => 'Thumbnail (320×240)',
	] );
} );

add_action( 'wp_head', function () {
	printf(
		'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
		esc_url( CC_THEME_URI . '/assets/fonts/plus-jakarta-sans-latin.woff2' )
	);
}, 2 );

add_action( 'wp_enqueue_scripts', function () {
	if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) {
		return;
	}

	$css = '/assets/css/main.css';
	if ( file_exists( CC_THEME_DIR . $css ) ) {
		wp_enqueue_style( 'cc-main', CC_THEME_URI . $css, [ 'bricks-frontend' ], filemtime( CC_THEME_DIR . $css ) );
	}

	$js = '/assets/js/main.js';
	if ( file_exists( CC_THEME_DIR . $js ) ) {
		wp_enqueue_script( 'cc-main', CC_THEME_URI . $js, [], filemtime( CC_THEME_DIR . $js ), [ 'strategy' => 'defer', 'in_footer' => true ] );
	}
}, 20 );

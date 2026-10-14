<?php
/**
 * Secure Custom Fields: Local JSON location and the Business Settings options page.
 * Field groups live in /acf-json so they are versioned and can be synced between environments.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'acf/settings/save_json', function () {
	return CC_THEME_DIR . '/acf-json';
} );

add_filter( 'acf/settings/load_json', function ( $paths ) {
	return [ CC_THEME_DIR . '/acf-json' ];
} );

add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_options_page' ) ) {
		return;
	}

	acf_add_options_page( [
		'page_title' => 'Business Settings',
		'menu_title' => 'Business Settings',
		'menu_slug'  => 'cc-business-settings',
		'capability' => 'manage_options',
		'icon_url'   => 'dashicons-building',
		'position'   => 3,
		'redirect'   => false,
	] );
} );

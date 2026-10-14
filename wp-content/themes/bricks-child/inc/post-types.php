<?php
/**
 * Custom post types and taxonomies.
 * Registered in code (not the SCF UI) so the content model is versioned with the theme.
 */

defined( 'ABSPATH' ) || exit;

function cc_post_type_labels( string $singular, string $plural ): array {
	return [
		'name'               => $plural,
		'singular_name'      => $singular,
		'add_new_item'       => "Add New {$singular}",
		'edit_item'          => "Edit {$singular}",
		'new_item'           => "New {$singular}",
		'view_item'          => "View {$singular}",
		'search_items'       => "Search {$plural}",
		'not_found'          => "No {$plural} found",
		'not_found_in_trash' => "No {$plural} found in Trash",
		'all_items'          => "All {$plural}",
		'menu_name'          => $plural,
	];
}

add_action( 'init', function () {
	$common = [
		'public'       => true,
		'show_in_rest' => true,
		'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'page-attributes' ],
	];

	register_post_type( 'cc_service', array_merge( $common, [
		'labels'      => cc_post_type_labels( 'Service', 'Services' ),
		'menu_icon'   => 'dashicons-admin-tools',
		'has_archive' => false,
		'rewrite'     => [ 'slug' => 'services', 'with_front' => false ],
	] ) );

	register_post_type( 'cc_area', array_merge( $common, [
		'labels'      => cc_post_type_labels( 'Area', 'Areas' ),
		'menu_icon'   => 'dashicons-location-alt',
		'has_archive' => false,
		'rewrite'     => [ 'slug' => 'areas', 'with_front' => false ],
	] ) );

	register_post_type( 'cc_local_service', array_merge( $common, [
		'labels'       => cc_post_type_labels( 'Local Service', 'Local Services' ),
		'menu_icon'    => 'dashicons-location',
		'has_archive'  => false,
		'rewrite'      => false,
		'query_var'    => true,
		'show_in_menu' => 'edit.php?post_type=cc_service',
	] ) );

	register_post_type( 'cc_case_study', array_merge( $common, [
		'labels'      => cc_post_type_labels( 'Case Study', 'Case Studies' ),
		'menu_icon'   => 'dashicons-portfolio',
		'has_archive' => false,
		'rewrite'     => [ 'slug' => 'case-studies', 'with_front' => false ],
	] ) );

	register_post_type( 'cc_team', array_merge( $common, [
		'labels'      => cc_post_type_labels( 'Team Member', 'Team' ),
		'menu_icon'   => 'dashicons-groups',
		'has_archive' => false,
		'rewrite'     => [ 'slug' => 'our-team', 'with_front' => false ],
		'supports'    => [ 'title', 'thumbnail', 'revisions', 'page-attributes' ],
	] ) );

	register_post_type( 'cc_review', [
		'labels'              => cc_post_type_labels( 'Review', 'Reviews' ),
		'public'              => false,
		'show_ui'             => true,
		'show_in_rest'        => true,
		'exclude_from_search' => true,
		'publicly_queryable'  => false,
		'menu_icon'           => 'dashicons-star-filled',
		'supports'            => [ 'title', 'revisions' ],
	] );

	register_taxonomy( 'cc_service_group', [ 'cc_service' ], [
		'labels'            => [ 'name' => 'Service Groups', 'singular_name' => 'Service Group' ],
		'public'            => false,
		'show_ui'           => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'hierarchical'      => true,
	] );

	register_taxonomy( 'cc_customer_type', [ 'cc_service', 'cc_case_study', 'cc_review' ], [
		'labels'            => [ 'name' => 'Customer Types', 'singular_name' => 'Customer Type' ],
		'public'            => false,
		'show_ui'           => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'hierarchical'      => true,
	] );
} );

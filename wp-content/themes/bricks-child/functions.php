<?php
/**
 * Cleaning Company — Bricks child theme bootstrap.
 */

defined( 'ABSPATH' ) || exit;

define( 'CC_THEME_DIR', get_stylesheet_directory() );
define( 'CC_THEME_URI', get_stylesheet_directory_uri() );

foreach ( [
	'helpers',
	'setup',
	'post-types',
	'scf',
	'rewrites',
	'bricks',
	'components',
	'media',
	'pricing',
	'guides',
	'dynamic-tags',
	'seo',
	'schema',
	'performance',
] as $cc_file ) {
	require_once CC_THEME_DIR . "/inc/{$cc_file}.php";
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once CC_THEME_DIR . '/inc/cli/class-tree.php';
	require_once CC_THEME_DIR . '/inc/cli/class-sections.php';
	require_once CC_THEME_DIR . '/inc/cli/class-site-command.php';
	WP_CLI::add_command( 'cc', CC\Cli\Site_Command::class );
}

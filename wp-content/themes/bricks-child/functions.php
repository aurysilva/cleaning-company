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
	'schema',
	'performance',
] as $cc_file ) {
	require_once CC_THEME_DIR . "/inc/{$cc_file}.php";
}

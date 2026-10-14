<?php
/**
 * Bricks integration.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Functions callable from Bricks with {echo:function_name}.
 */
add_filter( 'bricks/code/echo_function_names', function ( $names ) {
	return array_merge( (array) $names, [
		'cc_brand_name',
		'cc_phone_display',
		'cc_phone_href',
		'cc_phone_link',
		'cc_whatsapp_href',
		'cc_years_trading',
		'cc_full_address',
		'cc_opening_hours_today',
		'cc_google_rating',
		'cc_review_count',
	] );
} );

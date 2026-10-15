<?php
/**
 * Google Analytics 4 behind a cookie banner. gtag.js only loads after the visitor accepts (UK PECR),
 * editors' own visits aren't tracked, and links to #cookie-settings reopen the banner (main.js).
 */

defined( 'ABSPATH' ) || exit;

const CC_GA4_ID = 'G-SJX281HNB3';

add_action( 'wp_footer', function () {
	if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
		return;
	}
	printf(
		'<div class="cc-consent" id="cc-consent" role="region" aria-label="Cookie preferences" data-ga="%s" hidden>'
		. '<p class="cc-consent__text">We&#8217;d like to use analytics cookies to see how people use our site so we can improve it. They&#8217;re only set if you accept. <a href="%s">Cookie policy</a></p>'
		. '<div class="cc-consent__actions">'
		. '<button type="button" class="cc-btn cc-btn--secondary" data-consent="granted">Accept</button>'
		. '<button type="button" class="cc-btn cc-btn--secondary" data-consent="denied">Reject</button>'
		. '</div></div>',
		esc_attr( current_user_can( 'edit_posts' ) ? '' : CC_GA4_ID ),
		esc_url( home_url( '/cookie-policy/' ) )
	);
} );

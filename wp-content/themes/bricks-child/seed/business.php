<?php
/**
 * Placeholder Business Settings. Replace in WP Admin > Business Settings once the brand is final.
 * Only seeded when the brand name is still empty (or with --force).
 */

defined( 'ABSPATH' ) || exit;

return [
	'brand_name'           => 'Your Cleaning Co.',
	'legal_name'           => 'Your Cleaning Co. Ltd',
	'phone'                => '+441482000000',
	'phone_display'        => '01482 000000',
	'whatsapp'             => '',
	'email'                => 'hello@example.co.uk',
	'address_street'       => '',
	'address_locality'     => 'Hull',
	'postcode'             => '',
	'address_region'       => 'East Yorkshire',
	'latitude'             => '53.7676',
	'longitude'            => '-0.3274',
	'opening_hours'        => [
		[ 'days' => [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri' ], 'opens' => '07:00:00', 'closes' => '20:00:00' ],
		[ 'days' => [ 'Sat', 'Sun' ], 'opens' => '08:00:00', 'closes' => '18:00:00' ],
	],
	'trust_points'         => [
		[ 'text' => 'Fully insured' ],
		[ 'text' => 'DBS-checked, vetted cleaners' ],
		[ 'text' => 'Re-clean guarantee' ],
		[ 'text' => 'Open 7 days a week' ],
		[ 'text' => 'Fixed prices, no hidden extras' ],
	],
	'guarantee_title'      => '72-hour re-clean guarantee',
	'reclean_window_hours' => 72,
	'guarantee_short'      => 'If anything we cleaned isn\'t right, tell us within 72 hours and we\'ll come back and re-clean it free of charge.',
	'guarantee_full'       => '<p>We want you to be completely happy with every clean. If something we cleaned isn\'t up to standard, let us know within 72 hours of the clean — with a photo if you can — and we\'ll return to re-clean the affected areas at no extra cost.</p><p>For end of tenancy cleans, the guarantee covers any cleaning items raised in the landlord or letting agent\'s check-out report, as long as the property hasn\'t been used since our clean and the issue was part of the agreed checklist.</p><p>The guarantee doesn\'t cover pre-existing damage, permanent staining, wear and tear, or items outside the agreed checklist.</p>',
	'cta_primary_label'    => 'Get a free quote',
	'cta_secondary_label'  => 'Call us',
	'quote_url'            => '/contact/',
	'announcement_enabled' => 1,
	'announcement_text'    => 'Now taking bookings across Hull & East Yorkshire — 7 days a week',
	'announcement_link'    => '/contact/',
];

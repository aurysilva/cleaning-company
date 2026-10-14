<?php
/**
 * Draft Local Service pages (service × area). Only create these where we have genuinely local content —
 * thin duplicate pages hurt rankings.
 */

defined( 'ABSPATH' ) || exit;

return [
	[
		'service' => 'end-of-tenancy-cleaning',
		'area'    => 'hull',
		'fields'  => [
			'hero_heading'     => 'End of Tenancy Cleaning in Hull',
			'local_intro'      => '<p>Hull has one of the busiest rental markets in Yorkshire, from student houses around Newland Avenue and Cottingham Road to city centre apartments and family homes in east and west Hull. Our end of tenancy cleans follow the checklists used by Hull letting agents and inventory clerks, so the property is ready for check-out.</p><p>We know how tight move-out dates can be in June and July, so we keep weekend and evening slots free for tenants, and can invoice landlords and agents directly. Every clean is backed by our 72-hour re-clean guarantee.</p>',
			'local_price_note' => 'Hull city centre: parking charges added at cost where there\'s no free parking.',
			'faqs'             => [
				[ 'question' => 'Do you clean student houses in Hull?', 'answer' => 'Yes. We clean student lets and HMOs across Hull, including the Avenues, Newland Park and Cottingham Road, and can clean several houses for the same landlord on one day.' ],
				[ 'question' => 'Can you work with my Hull letting agent?', 'answer' => 'Yes. We follow agent checklists, can collect and return keys, and invoice the agent or landlord directly if you prefer.' ],
				[ 'question' => 'How much is an end of tenancy clean in Hull?', 'answer' => 'It depends on the size and condition of the property and any extras like oven or carpet cleaning. Tell us about the property and we\'ll send a free, fixed quote — usually within the hour.' ],
			],
		],
	],
	[
		'service' => 'regular-house-cleaning',
		'area'    => 'hull',
		'fields'  => [
			'hero_heading' => 'House Cleaners in Hull',
			'local_intro'  => '<p>Our regular house cleaners live in and around Hull, so you get a local cleaner who knows the area and can reach you reliably every week or fortnight. We clean everything from Victorian terraces in the Avenues to new-builds in Kingswood and family homes in Sutton and West Hull.</p><p>You\'ll have the same DBS-checked cleaner each visit, no long contract, and cover when your cleaner is on holiday.</p>',
		],
	],
	[
		'service' => 'deep-cleaning',
		'area'    => 'hull',
		'fields'  => [
			'hero_heading' => 'Deep Cleaning in Hull',
			'local_intro'  => '<p>Hull\'s hard water leaves stubborn limescale on taps, shower screens and tiles. Our deep cleans in Hull include professional descaling as standard, along with degreasing kitchens, cleaning inside cupboards and appliances, and tackling every skirting board and door frame.</p><p>Book a one-off deep clean for a spring refresh, before guests arrive or before starting regular cleaning.</p>',
		],
	],
	[
		'service' => 'office-cleaning',
		'area'    => 'hull',
		'fields'  => [
			'hero_heading' => 'Office Cleaning in Hull',
			'local_intro'  => '<p>We clean offices across Hull city centre, the Marina, Sutton Fields, Kingswood and the business parks along the A63. Cleans take place early mornings or evenings so your team arrives to a fresh workspace.</p><p>Every contract has a dedicated cleaner, a named account manager and a fixed monthly price, with RAMS and insurance documents provided as standard.</p>',
		],
	],
	[
		'service' => 'end-of-tenancy-cleaning',
		'area'    => 'beverley',
		'fields'  => [
			'hero_heading' => 'End of Tenancy Cleaning in Beverley',
			'local_intro'  => '<p>Moving out of a rental in Beverley? Our end of tenancy cleans follow local letting agent checklists, covering every room top to bottom so you\'re ready for check-out. We clean town centre flats, period townhouses near the Minster and family homes in Molescroft and Woodhall Way.</p><p>Add an oven clean or carpet cleaning in the same visit, and you\'re covered by our 72-hour re-clean guarantee.</p>',
		],
	],
	[
		'service' => 'end-of-tenancy-cleaning',
		'area'    => 'cottingham',
		'fields'  => [
			'hero_heading' => 'End of Tenancy Cleaning in Cottingham',
			'local_intro'  => '<p>Cottingham\'s student and family rentals keep us busy every summer. We clean student houses near the university halls and family homes throughout the village, working to letting agent and inventory checklists.</p><p>Landlords with several properties can book same-day turnarounds, and we can invoice landlords or agents directly.</p>',
		],
	],
];

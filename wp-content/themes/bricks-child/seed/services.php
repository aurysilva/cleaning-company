<?php
/**
 * Draft service content. Prices are placeholders based on local competitor research (Oct 2026) — review before launch.
 * Keyed by service slug (must match existing cc_service posts or new ones are created).
 */

defined( 'ABSPATH' ) || exit;

$standard_kitchen = "Wipe worktops, splashbacks and cupboard fronts\nClean hob and outside of oven, microwave and fridge\nClean and polish sink and taps\nEmpty bins and replace liners\nVacuum and mop floor";
$standard_bathroom = "Clean and disinfect toilet, sink and bath or shower\nDescale taps and shower screen\nWipe tiles and mirrors\nPolish chrome fittings\nVacuum and mop floor";
$standard_rooms = "Dust all reachable surfaces, skirting boards and sills\nClean mirrors and glass\nVacuum carpets and rugs\nMop hard floors\nMake beds (linen change on request)\nTidy and straighten";

$deep_kitchen = "Degrease worktops, splashbacks and tiles\nClean inside and outside cupboards and drawers\nClean inside microwave and outside of all appliances\nDegrease extractor hood and filters\nDescale sink, taps and drainer\nClean skirting boards, door frames and light switches\nVacuum and mop floor including edges";
$deep_bathroom = "Descale and disinfect toilet, sink, bath and shower\nRemove limescale from screens, taps and shower heads\nScrub tiles and grout\nClean extractor fan cover\nPolish mirrors and chrome\nClean skirting boards and behind the toilet\nVacuum and mop floor";
$deep_rooms = "Dust ceilings, coving and light fittings\nWipe doors, frames, handles and switches\nClean skirting boards and radiators\nClean inside windows, sills and frames\nDust and wipe furniture, including under and behind where movable\nVacuum carpets thoroughly, including edges\nWash hard floors";

$guarantee_faq = [ 'question' => 'What if I\'m not happy with the clean?', 'answer' => 'Tell us within 72 hours and we\'ll come back and re-clean anything that isn\'t right, free of charge. That\'s our re-clean guarantee.' ];
$insured_faq   = [ 'question' => 'Are your cleaners insured and vetted?', 'answer' => 'Yes. Every cleaner is DBS-checked, trained in-house and covered by our public liability insurance.' ];
$products_faq  = [ 'question' => 'Do you bring your own equipment and products?', 'answer' => 'Yes, we bring everything we need, including professional vacuums and eco-friendly products. If you\'d prefer us to use your own products, just let us know.' ];

return [
	'regular-house-cleaning' => [
		'title'    => 'Regular House Cleaning',
		'order'    => 1,
		'group'    => [ 'home' ],
		'customer' => [ 'homeowner', 'tenant' ],
		'related'  => [ 'deep-cleaning', 'one-off-cleaning', 'oven-cleaning' ],
		'fields'   => [
			'hero_heading'      => 'Regular House Cleaning in Hull & East Yorkshire',
			'hero_subheading'   => 'Weekly or fortnightly cleans by the same trusted cleaner, so you can get your evenings and weekends back.',
			'price_from'        => 20,
			'price_unit'        => 'per hour',
			'typical_duration'  => '2–4 hours per visit',
			'short_description' => 'Weekly or fortnightly cleans from the same vetted cleaner, with a checklist tailored to your home.',
			'intro'             => '<p>A clean home without giving up your weekend. Our regular house cleaning service sends the same DBS-checked cleaner to your home every week or fortnight, working through a checklist we agree with you at the start.</p><p>You can change the focus any time — ask us to spend longer on the bathroom one week, or add an oven clean before guests arrive. If your usual cleaner is on holiday, we\'ll send a fully briefed replacement so you never miss a clean.</p>',
			'who_its_for'       => [
				[ 'label' => 'Busy households', 'description' => 'Working parents and professionals who want their evenings and weekends back.' ],
				[ 'label' => 'Older residents', 'description' => 'Reliable help with the jobs that get harder, from the same friendly face each visit.' ],
				[ 'label' => 'Tenants', 'description' => 'Keep your rental in great shape all year and make check-out day easier.' ],
			],
			'checklist'         => [
				[ 'room' => 'Kitchen', 'tasks' => $standard_kitchen ],
				[ 'room' => 'Bathrooms', 'tasks' => $standard_bathroom ],
				[ 'room' => 'Bedrooms & living areas', 'tasks' => $standard_rooms ],
			],
			'not_included'      => [
				[ 'item' => 'Inside the oven (book an oven clean add-on)' ],
				[ 'item' => 'Outside windows' ],
				[ 'item' => 'Moving heavy furniture' ],
				[ 'item' => 'Washing up large piles of dishes' ],
			],
			'local_section'     => '<p>We clean homes across Hull and the surrounding villages every day, from terraces in the Avenues to family homes in Kirk Ella, Cottingham and Beverley. Because our cleaners live locally, we can usually offer a regular slot within a week.</p>',
			'price_table'       => [
				[ 'property_size' => '1–2 bedrooms', 'price' => 40, 'note' => 'Around 2 hours per visit' ],
				[ 'property_size' => '3 bedrooms', 'price' => 60, 'note' => 'Around 3 hours per visit' ],
				[ 'property_size' => '4+ bedrooms', 'price' => 80, 'note' => 'Around 4 hours per visit' ],
			],
			'addons'            => [
				[ 'name' => 'Oven clean', 'price' => 55, 'description' => 'Full single oven clean added to any visit.' ],
				[ 'name' => 'Inside fridge', 'price' => 15, 'description' => 'Empty, wipe and sanitise shelves and drawers.' ],
				[ 'name' => 'Change bed linen', 'price' => 5, 'description' => 'Per bed, using your fresh linen.' ],
				[ 'name' => 'Inside windows', 'price' => 15, 'description' => 'Interior glass, frames and sills.' ],
			],
			'price_disclaimer'  => 'Minimum visit 2 hours. Prices are a guide — your fixed price is confirmed when you book.',
			'faqs'              => [
				[ 'question' => 'Will I have the same cleaner each time?', 'answer' => 'Yes. We match you with one regular cleaner. If they\'re ever off, we send a briefed replacement and let you know in advance.' ],
				[ 'question' => 'Do I need to be at home?', 'answer' => 'No. Many customers give us a key or a key safe code. Keys are coded and stored securely.' ],
				[ 'question' => 'Is there a contract?', 'answer' => 'No long contracts. You can pause, change or cancel your regular clean with 48 hours\' notice.' ],
				$products_faq,
				$insured_faq,
				$guarantee_faq,
			],
			'schema_service_type' => 'House cleaning',
		],
	],

	'deep-cleaning' => [
		'title'    => 'Deep Cleaning',
		'order'    => 2,
		'group'    => [ 'home' ],
		'customer' => [ 'homeowner', 'tenant', 'landlord' ],
		'related'  => [ 'regular-house-cleaning', 'oven-cleaning', 'carpet-upholstery-cleaning' ],
		'fields'   => [
			'hero_heading'      => 'Deep Cleaning in Hull & East Yorkshire',
			'hero_subheading'   => 'A top-to-bottom clean that reaches the places regular cleaning doesn\'t — ideal for spring cleans, before guests or after a busy season.',
			'price_from'        => 160,
			'price_unit'        => '',
			'typical_duration'  => '5–10 hours with a team',
			'short_description' => 'A top-to-bottom clean of every room, including inside cupboards, appliances and all the hard-to-reach places.',
			'intro'             => '<p>A deep clean resets your home. Our team works room by room, from ceilings and light fittings down to skirting boards, tackling built-up grease, limescale and dust that a regular clean can\'t reach.</p><p>It\'s the perfect start before regular cleaning, a spring clean, or a refresh before family come to stay. We bring all the equipment and products, and you get a fixed price before we start.</p>',
			'who_its_for'       => [
				[ 'label' => 'Spring cleaning', 'description' => 'A full reset of the whole home once or twice a year.' ],
				[ 'label' => 'Before regular cleaning', 'description' => 'Start from a high standard so regular visits keep it that way.' ],
				[ 'label' => 'Special occasions', 'description' => 'Get the house ready for guests, a party or a new arrival.' ],
			],
			'checklist'         => [
				[ 'room' => 'Kitchen', 'tasks' => $deep_kitchen ],
				[ 'room' => 'Bathrooms', 'tasks' => $deep_bathroom ],
				[ 'room' => 'Bedrooms & living areas', 'tasks' => $deep_rooms ],
			],
			'not_included'      => [
				[ 'item' => 'Inside the oven (add an oven clean)' ],
				[ 'item' => 'Carpet shampooing (add carpet cleaning)' ],
				[ 'item' => 'Outside windows' ],
				[ 'item' => 'Mould treatment beyond surface cleaning' ],
			],
			'local_section'     => '<p>Hull\'s hard water means limescale builds up fast on taps, showers and kettles. Our deep cleans include professional descaling as standard, so bathrooms and kitchens across Hull, Hessle and Beverley really sparkle.</p>',
			'price_table'       => [
				[ 'property_size' => 'Studio / 1 bedroom', 'price' => 160, 'note' => '' ],
				[ 'property_size' => '2 bedrooms', 'price' => 210, 'note' => '' ],
				[ 'property_size' => '3 bedrooms', 'price' => 270, 'note' => 'Most popular' ],
				[ 'property_size' => '4 bedrooms', 'price' => 360, 'note' => '' ],
				[ 'property_size' => '5+ bedrooms', 'price' => null, 'note' => 'Bespoke quote' ],
			],
			'addons'            => [
				[ 'name' => 'Oven clean', 'price' => 55, 'description' => 'Single oven, racks and door glass.' ],
				[ 'name' => 'Carpet cleaning', 'price' => 35, 'description' => 'Per room, hot water extraction.' ],
				[ 'name' => 'Inside fridge freezer', 'price' => 25, 'description' => 'Defrosted and empty, inside and seals.' ],
				[ 'name' => 'Inside windows', 'price' => 25, 'description' => 'All interior glass, frames and sills.' ],
			],
			'price_disclaimer'  => 'Prices assume a property in average condition. Heavily soiled properties may need extra time — we\'ll always confirm before starting.',
			'faqs'              => [
				[ 'question' => 'How long does a deep clean take?', 'answer' => 'Most 3-bedroom homes take a team of two around 5–6 hours. We\'ll give you an estimate when you book.' ],
				[ 'question' => 'What\'s the difference between a deep clean and a regular clean?', 'answer' => 'A regular clean maintains your home. A deep clean goes further: inside cupboards, behind and under furniture, descaling, skirting boards, doors and light fittings.' ],
				[ 'question' => 'Do I need to be at home?', 'answer' => 'No. Lots of customers leave a key. We\'ll message you when we arrive and when we finish.' ],
				$products_faq,
				$insured_faq,
				$guarantee_faq,
			],
			'schema_service_type' => 'Deep cleaning',
		],
	],

	'one-off-cleaning' => [
		'title'    => 'One-Off Cleaning',
		'order'    => 3,
		'group'    => [ 'home' ],
		'customer' => [ 'homeowner', 'tenant' ],
		'related'  => [ 'deep-cleaning', 'regular-house-cleaning', 'oven-cleaning' ],
		'fields'   => [
			'hero_heading'      => 'One-Off Cleaning in Hull & East Yorkshire',
			'hero_subheading'   => 'A single clean, booked when you need it — before visitors, after a party, or just because.',
			'price_from'        => 66,
			'price_unit'        => '',
			'typical_duration'  => '3–5 hours',
			'short_description' => 'A single clean whenever you need it, with no ongoing commitment.',
			'intro'             => '<p>Not ready for regular cleaning? Book a one-off clean whenever life gets busy. You choose how long and what we focus on — whether that\'s a full refresh of the house or just the kitchen and bathrooms.</p><p>One-off cleans are charged by the hour with a 3-hour minimum, and you\'ll know the price before we arrive.</p>',
			'who_its_for'       => [
				[ 'label' => 'Before guests arrive', 'description' => 'Get the house ready for family, friends or a special occasion.' ],
				[ 'label' => 'After a party', 'description' => 'Let us deal with the mess while you recover.' ],
				[ 'label' => 'Selling your home', 'description' => 'Make a great first impression for photos and viewings.' ],
			],
			'checklist'         => [
				[ 'room' => 'Kitchen', 'tasks' => $standard_kitchen ],
				[ 'room' => 'Bathrooms', 'tasks' => $standard_bathroom ],
				[ 'room' => 'Bedrooms & living areas', 'tasks' => $standard_rooms ],
			],
			'not_included'      => [
				[ 'item' => 'Inside the oven (add an oven clean)' ],
				[ 'item' => 'Outside windows' ],
				[ 'item' => 'Biohazard or hoarder cleans' ],
			],
			'local_section'     => '<p>We can often fit one-off cleans in Hull, Hessle, Cottingham and Beverley within a few days, including weekends.</p>',
			'price_table'       => [
				[ 'property_size' => '3 hours (minimum)', 'price' => 66, 'note' => 'Ideal for 1–2 bedrooms' ],
				[ 'property_size' => '4 hours', 'price' => 88, 'note' => 'Ideal for 3 bedrooms' ],
				[ 'property_size' => '5 hours', 'price' => 110, 'note' => 'Ideal for 4+ bedrooms' ],
			],
			'addons'            => [
				[ 'name' => 'Oven clean', 'price' => 55, 'description' => 'Single oven, racks and door glass.' ],
				[ 'name' => 'Inside fridge', 'price' => 15, 'description' => 'Empty, wipe and sanitise.' ],
				[ 'name' => 'Inside windows', 'price' => 15, 'description' => 'Interior glass, frames and sills.' ],
			],
			'price_disclaimer'  => 'Charged at £22 per cleaner per hour, minimum 3 hours.',
			'faqs'              => [
				[ 'question' => 'How quickly can you come?', 'answer' => 'We can often fit you in within 2–3 days, and sometimes the next day. Weekends are available.' ],
				[ 'question' => 'Can I choose what you clean?', 'answer' => 'Yes. Tell us your priorities when you book and we\'ll focus on those first.' ],
				$products_faq,
				$insured_faq,
				$guarantee_faq,
			],
			'schema_service_type' => 'House cleaning',
		],
	],

	'oven-cleaning' => [
		'title'    => 'Oven Cleaning',
		'order'    => 4,
		'group'    => [ 'home' ],
		'customer' => [ 'homeowner', 'tenant', 'landlord' ],
		'related'  => [ 'deep-cleaning', 'end-of-tenancy-cleaning', 'carpet-upholstery-cleaning' ],
		'fields'   => [
			'hero_heading'      => 'Professional Oven Cleaning in Hull & East Yorkshire',
			'hero_subheading'   => 'Ovens, hobs, extractors and ranges restored to a like-new shine with a fume-free, non-caustic process.',
			'price_from'        => 55,
			'price_unit'        => '',
			'typical_duration'  => '1.5–3 hours',
			'short_description' => 'Fume-free, non-caustic oven cleaning that takes ovens, ranges and AGAs back to a like-new shine.',
			'intro'             => '<p>Our oven cleaning removes years of baked-on grease and carbon. Racks, trays and removable parts go into a heated dip tank in our van, while we hand-clean the inside of the oven, door glass and seals.</p><p>We use non-caustic, fume-free products, so your oven is ready to use as soon as we leave. It\'s a popular add-on for end of tenancy cleans, too.</p>',
			'who_its_for'       => [
				[ 'label' => 'Homeowners', 'description' => 'A sparkling oven without the scrubbing or harsh chemicals.' ],
				[ 'label' => 'Tenants moving out', 'description' => 'Ovens are one of the most common reasons for deposit deductions.' ],
				[ 'label' => 'Landlords', 'description' => 'Get a property rent-ready between tenancies.' ],
			],
			'checklist'         => [
				[ 'room' => 'Oven', 'tasks' => "Remove racks, trays and fan cover for dip tank cleaning\nClean oven interior, roof and fan blades\nDismantle and clean between the door glass\nClean seals, handles and control knobs\nPolish exterior" ],
				[ 'room' => 'Hob & extractor (add-on)', 'tasks' => "Degrease hob surface, pan supports and burners\nClean extractor canopy\nDegrease or replace extractor filters" ],
			],
			'not_included'      => [
				[ 'item' => 'Repairs or replacement of parts' ],
				[ 'item' => 'Self-cleaning (pyrolytic) liners are wiped, not scrubbed' ],
			],
			'local_section'     => '<p>We clean ovens in homes and rental properties across Hull, Beverley, Hessle, Cottingham and the wider East Riding.</p>',
			'price_table'       => [
				[ 'property_size' => 'Single oven', 'price' => 55, 'note' => '' ],
				[ 'property_size' => 'Double oven', 'price' => 70, 'note' => '' ],
				[ 'property_size' => 'Range cooker (up to 110cm)', 'price' => 105, 'note' => '' ],
				[ 'property_size' => 'AGA / Rayburn', 'price' => 120, 'note' => '' ],
			],
			'addons'            => [
				[ 'name' => 'Hob', 'price' => 15, 'description' => 'Gas, ceramic or induction.' ],
				[ 'name' => 'Extractor', 'price' => 20, 'description' => 'Canopy and filters degreased.' ],
				[ 'name' => 'Microwave', 'price' => 15, 'description' => 'Inside and out.' ],
			],
			'price_disclaimer'  => 'Prices include all products and removal of waste. Replacement extractor filters charged at cost.',
			'faqs'              => [
				[ 'question' => 'How long does an oven clean take?', 'answer' => 'A single oven takes about 1.5–2 hours. Range cookers and AGAs take longer.' ],
				[ 'question' => 'Can I use my oven straight away?', 'answer' => 'Yes. Our products are non-caustic and fume-free, so your oven is ready to use when we leave.' ],
				[ 'question' => 'Do you clean AGAs?', 'answer' => 'Yes. AGAs need to be switched off the night before so they\'re cool when we arrive.' ],
				$insured_faq,
				$guarantee_faq,
			],
			'schema_service_type' => 'Oven cleaning',
		],
	],

	'carpet-upholstery-cleaning' => [
		'title'    => 'Carpet & Upholstery Cleaning',
		'order'    => 5,
		'group'    => [ 'home' ],
		'customer' => [ 'homeowner', 'tenant', 'landlord', 'business' ],
		'related'  => [ 'deep-cleaning', 'end-of-tenancy-cleaning', 'oven-cleaning' ],
		'fields'   => [
			'hero_heading'      => 'Carpet & Upholstery Cleaning in Hull & East Yorkshire',
			'hero_subheading'   => 'Professional hot water extraction that lifts deep dirt, stains and odours from carpets, rugs and sofas.',
			'price_from'        => 35,
			'price_unit'        => 'per room',
			'typical_duration'  => '30–45 minutes per room',
			'short_description' => 'Hot water extraction for carpets, rugs, sofas and stairs — fresher, brighter and dry in hours.',
			'intro'             => '<p>Vacuuming only removes surface dirt. Our professional hot water extraction reaches deep into the fibres to lift trapped dirt, allergens, pet hair and stains, leaving carpets and upholstery fresher and brighter.</p><p>We pre-treat stains, use pet-safe and child-safe solutions, and most carpets are dry within 4–6 hours.</p>',
			'who_its_for'       => [
				[ 'label' => 'Families & pet owners', 'description' => 'Tackle spills, muddy paws and odours.' ],
				[ 'label' => 'Tenants & landlords', 'description' => 'Often required at the end of a tenancy.' ],
				[ 'label' => 'Offices', 'description' => 'Keep high-traffic carpets looking professional.' ],
			],
			'checklist'         => [
				[ 'room' => 'Carpets & rugs', 'tasks' => "Pre-vacuum\nPre-treat stains and high-traffic areas\nHot water extraction\nGroom pile and speed dry" ],
				[ 'room' => 'Upholstery', 'tasks' => "Fabric test\nPre-treat stains\nLow-moisture extraction clean\nDeodorise on request" ],
			],
			'not_included'      => [
				[ 'item' => 'Guaranteed removal of permanent stains (bleach, dye, burns)' ],
				[ 'item' => 'Moving very heavy furniture' ],
				[ 'item' => 'Leather cleaning (quote on request)' ],
			],
			'local_section'     => '<p>We clean carpets and upholstery in homes, rental properties and offices throughout Hull and East Yorkshire.</p>',
			'price_table'       => [
				[ 'property_size' => 'Single room', 'price' => 35, 'note' => 'Up to 15m²' ],
				[ 'property_size' => 'Stairs & landing', 'price' => 45, 'note' => '' ],
				[ 'property_size' => '3 rooms + stairs', 'price' => 120, 'note' => 'Popular bundle' ],
				[ 'property_size' => '3-seater sofa', 'price' => 60, 'note' => '' ],
				[ 'property_size' => 'Armchair', 'price' => 30, 'note' => '' ],
			],
			'addons'            => [
				[ 'name' => 'Stain protector', 'price' => 15, 'description' => 'Per room or sofa.' ],
				[ 'name' => 'Pet odour treatment', 'price' => 15, 'description' => 'Per room.' ],
				[ 'name' => 'Rug', 'price' => 30, 'description' => 'Up to 2m × 3m.' ],
			],
			'price_disclaimer'  => 'Minimum call-out £70.',
			'faqs'              => [
				[ 'question' => 'How long do carpets take to dry?', 'answer' => 'Usually 4–6 hours, depending on the carpet and ventilation.' ],
				[ 'question' => 'Can you remove all stains?', 'answer' => 'We remove most stains, but some (like bleach, dye or burns) are permanent. We\'ll be honest about what\'s achievable before we start.' ],
				[ 'question' => 'Are your products safe for pets and children?', 'answer' => 'Yes. We use pet-safe and child-safe solutions.' ],
				$insured_faq,
				$guarantee_faq,
			],
			'schema_service_type' => 'Carpet cleaning',
		],
	],

	'end-of-tenancy-cleaning' => [
		'title'    => 'End of Tenancy Cleaning',
		'order'    => 10,
		'group'    => [ 'moving-property', 'landlords-hosts' ],
		'customer' => [ 'tenant', 'landlord', 'letting-agent' ],
		'related'  => [ 'oven-cleaning', 'carpet-upholstery-cleaning', 'move-in-cleaning' ],
		'fields'   => [
			'hero_heading'      => 'End of Tenancy Cleaning in Hull & East Yorkshire',
			'hero_subheading'   => 'Inventory-standard move-out cleaning, built around letting agent checklists and backed by our re-clean guarantee.',
			'price_from'        => 150,
			'price_unit'        => '',
			'typical_duration'  => '4–10 hours with a team',
			'short_description' => 'Move-out cleaning to inventory standard, built around letting agent checklists — with a re-clean guarantee.',
			'intro'             => '<p>Moving out is stressful enough without worrying about your deposit. Our end of tenancy cleaning follows the checklists that letting agents and inventory clerks use in Hull and East Yorkshire, so the property is ready for check-out.</p><p>We clean every room top to bottom — inside cupboards and appliances, all the limescale, skirting boards and doors — and we can add oven and carpet cleaning in the same visit. If anything cleaning-related comes up on the check-out report, we\'ll come back and fix it free of charge.</p>',
			'who_its_for'       => [
				[ 'label' => 'Tenants', 'description' => 'Give yourself the best chance of getting your full deposit back.' ],
				[ 'label' => 'Landlords', 'description' => 'Get the property rent-ready for the next tenants, fast.' ],
				[ 'label' => 'Letting agents', 'description' => 'Reliable turnarounds and invoicing for your managed portfolio.' ],
			],
			'checklist'         => [
				[ 'room' => 'Kitchen', 'tasks' => $deep_kitchen . "\nClean inside fridge, freezer, dishwasher and washing machine drawer" ],
				[ 'room' => 'Bathrooms', 'tasks' => $deep_bathroom ],
				[ 'room' => 'Bedrooms & living areas', 'tasks' => $deep_rooms . "\nClean inside wardrobes, drawers and cupboards" ],
				[ 'room' => 'Throughout', 'tasks' => "Remove cobwebs\nClean light switches and sockets\nClean inside window frames and sills\nEmpty all bins" ],
			],
			'not_included'      => [
				[ 'item' => 'Removal of furniture, belongings or rubbish' ],
				[ 'item' => 'Outside windows' ],
				[ 'item' => 'Repairing damage or redecorating' ],
				[ 'item' => 'Oven and carpets unless added' ],
			],
			'local_section'     => '<p>We work with tenants, landlords and agents across Hull — including student lets around Newland Avenue and Cottingham Road — plus Beverley, Hessle, Cottingham and the wider East Riding. Weekend and short-notice slots are often available around move-out dates.</p>',
			'price_table'       => [
				[ 'property_size' => 'Studio', 'price' => 150, 'note' => '' ],
				[ 'property_size' => '1 bedroom', 'price' => 170, 'note' => '' ],
				[ 'property_size' => '2 bedrooms', 'price' => 200, 'note' => '' ],
				[ 'property_size' => '3 bedrooms', 'price' => 270, 'note' => 'Most popular' ],
				[ 'property_size' => '4 bedrooms', 'price' => 350, 'note' => '' ],
				[ 'property_size' => '5+ bedrooms / HMO', 'price' => null, 'note' => 'Bespoke quote' ],
			],
			'addons'            => [
				[ 'name' => 'Oven clean', 'price' => 45, 'description' => 'Discounted when booked with end of tenancy.' ],
				[ 'name' => 'Carpet cleaning', 'price' => 30, 'description' => 'Per room, hot water extraction.' ],
				[ 'name' => 'Inside windows', 'price' => 20, 'description' => 'Interior glass and frames.' ],
				[ 'name' => 'Rubbish removal', 'price' => null, 'description' => 'Quote on request.' ],
			],
			'price_disclaimer'  => 'Prices are for unfurnished properties in average condition. Furnished properties add 10%.',
			'faqs'              => [
				[ 'question' => 'Do you guarantee I\'ll get my deposit back?', 'answer' => 'We can\'t control the whole deposit — it can be affected by damage or rent arrears — but we guarantee the cleaning. If any cleaning items appear on the check-out report within 72 hours, we\'ll return and fix them free.' ],
				[ 'question' => 'Should the property be empty?', 'answer' => 'Ideally, yes. An empty property lets us clean inside every cupboard and behind furniture. If items are still there, let us know when you book.' ],
				[ 'question' => 'Do you work with letting agents?', 'answer' => 'Yes. We follow agent and inventory checklists and can invoice agents or landlords directly.' ],
				[ 'question' => 'How long does it take?', 'answer' => 'A 2-bedroom flat typically takes a team of two around 4–5 hours. Larger homes take longer.' ],
				$products_faq,
				$insured_faq,
			],
			'schema_service_type' => 'End of tenancy cleaning',
		],
	],

	'move-in-cleaning' => [
		'title'    => 'Move-In Cleaning',
		'order'    => 11,
		'group'    => [ 'moving-property' ],
		'customer' => [ 'homeowner', 'tenant' ],
		'related'  => [ 'end-of-tenancy-cleaning', 'deep-cleaning', 'carpet-upholstery-cleaning' ],
		'fields'   => [
			'hero_heading'      => 'Move-In Cleaning in Hull & East Yorkshire',
			'hero_subheading'   => 'Start fresh in your new home with a top-to-bottom clean before you unpack.',
			'price_from'        => 150,
			'price_unit'        => '',
			'typical_duration'  => '4–8 hours with a team',
			'short_description' => 'A thorough clean of your new home before you unpack, so you start fresh.',
			'intro'             => '<p>The previous occupants might have cleaned — but not to your standard. Our move-in clean gets your new home hygienically clean before your belongings arrive, including inside every cupboard, wardrobe and appliance.</p><p>Book it for the day you get the keys and you can unpack straight into a clean home.</p>',
			'who_its_for'       => [
				[ 'label' => 'New homeowners', 'description' => 'Start fresh before the removal van arrives.' ],
				[ 'label' => 'New tenants', 'description' => 'Make sure the property is clean from day one.' ],
				[ 'label' => 'Families with young children', 'description' => 'Peace of mind that every surface is properly cleaned.' ],
			],
			'checklist'         => [
				[ 'room' => 'Kitchen', 'tasks' => $deep_kitchen ],
				[ 'room' => 'Bathrooms', 'tasks' => $deep_bathroom ],
				[ 'room' => 'Bedrooms & living areas', 'tasks' => $deep_rooms . "\nClean inside wardrobes, drawers and cupboards" ],
			],
			'not_included'      => [
				[ 'item' => 'Unpacking or assembling furniture' ],
				[ 'item' => 'Outside windows' ],
				[ 'item' => 'Oven and carpets unless added' ],
			],
			'local_section'     => '<p>Moving to Hull, Beverley, Hessle or Cottingham? We can usually book your move-in clean for the day you collect your keys.</p>',
			'price_table'       => [
				[ 'property_size' => 'Studio / 1 bedroom', 'price' => 150, 'note' => '' ],
				[ 'property_size' => '2 bedrooms', 'price' => 190, 'note' => '' ],
				[ 'property_size' => '3 bedrooms', 'price' => 250, 'note' => '' ],
				[ 'property_size' => '4+ bedrooms', 'price' => 330, 'note' => 'From' ],
			],
			'addons'            => [
				[ 'name' => 'Oven clean', 'price' => 45, 'description' => 'Discounted with a move-in clean.' ],
				[ 'name' => 'Carpet cleaning', 'price' => 30, 'description' => 'Per room.' ],
				[ 'name' => 'Inside windows', 'price' => 20, 'description' => 'Interior glass and frames.' ],
			],
			'price_disclaimer'  => 'Prices are for empty properties in average condition.',
			'faqs'              => [
				[ 'question' => 'Can you clean on the day I get my keys?', 'answer' => 'Usually, yes. Book as early as you can, and we can collect keys from the agent if you arrange it.' ],
				[ 'question' => 'Is it the same as an end of tenancy clean?', 'answer' => 'It\'s very similar — a full top-to-bottom clean of an empty property — but focused on getting the home ready for you rather than for an inventory check.' ],
				$products_faq,
				$insured_faq,
				$guarantee_faq,
			],
			'schema_service_type' => 'Move-in cleaning',
		],
	],

	'after-builders-cleaning' => [
		'title'    => 'After Builders Cleaning',
		'order'    => 12,
		'group'    => [ 'moving-property' ],
		'customer' => [ 'homeowner', 'landlord', 'business' ],
		'related'  => [ 'deep-cleaning', 'carpet-upholstery-cleaning', 'commercial-cleaning' ],
		'fields'   => [
			'hero_heading'      => 'After Builders Cleaning in Hull & East Yorkshire',
			'hero_subheading'   => 'We remove the dust, paint splashes and debris left after building work, so you can enjoy your new space.',
			'price_from'        => 200,
			'price_unit'        => '',
			'typical_duration'  => '1 day for most homes',
			'short_description' => 'Post-construction and renovation cleaning that removes fine dust, paint splashes and residue.',
			'intro'             => '<p>Building dust gets everywhere — and it keeps coming back if it isn\'t removed properly. Our after builders cleaning uses HEPA-filter vacuums and a careful top-down process to remove fine dust, plaster residue, paint splashes and sticker marks.</p><p>We work for homeowners after extensions and renovations, and for builders, developers and landlords who need a property handover-ready.</p>',
			'who_its_for'       => [
				[ 'label' => 'Homeowners', 'description' => 'After an extension, new kitchen or full renovation.' ],
				[ 'label' => 'Builders & developers', 'description' => 'Sparkle cleans for handovers and show homes.' ],
				[ 'label' => 'Landlords', 'description' => 'After refurbishment between tenancies.' ],
			],
			'checklist'         => [
				[ 'room' => 'Throughout', 'tasks' => "HEPA vacuum all surfaces, top to bottom\nRemove dust from walls, ceilings and coving\nRemove paint splashes and sticker residue from glass and fittings\nClean inside windows, frames and sills\nClean doors, frames, skirting boards and radiators\nClean inside cupboards and drawers\nClean and polish all sanitaryware\nWash all hard floors" ],
			],
			'not_included'      => [
				[ 'item' => 'Removal of building waste or skips' ],
				[ 'item' => 'Heavy cement or grout residue removal' ],
				[ 'item' => 'Outside windows above ground floor' ],
			],
			'local_section'     => '<p>We clean after building work in homes and commercial units across Hull and the East Riding, and work with local builders and developers on handover cleans.</p>',
			'price_table'       => [
				[ 'property_size' => 'Single room or kitchen', 'price' => 200, 'note' => '' ],
				[ 'property_size' => '1–2 bedroom home', 'price' => 280, 'note' => '' ],
				[ 'property_size' => '3 bedroom home', 'price' => 360, 'note' => '' ],
				[ 'property_size' => '4+ bedrooms / commercial', 'price' => null, 'note' => 'Site visit & quote' ],
			],
			'addons'            => [
				[ 'name' => 'Carpet cleaning', 'price' => 35, 'description' => 'Per room.' ],
				[ 'name' => 'Second visit (dust settle)', 'price' => null, 'description' => 'A follow-up clean a few days later.' ],
			],
			'price_disclaimer'  => 'Price depends on the amount of dust and residue. We\'ll confirm on photos or a site visit.',
			'faqs'              => [
				[ 'question' => 'When should I book the clean?', 'answer' => 'Once all trades have finished, including painting and flooring. Dust settles for a few days after work stops, so some customers book a second light clean.' ],
				[ 'question' => 'Do you work for builders?', 'answer' => 'Yes. We offer handover and sparkle cleans for builders and developers, with invoicing on account.' ],
				$insured_faq,
				$guarantee_faq,
			],
			'schema_service_type' => 'Post-construction cleaning',
		],
	],

	'holiday-let-airbnb-cleaning' => [
		'title'    => 'Holiday Let & Airbnb Cleaning',
		'order'    => 20,
		'group'    => [ 'landlords-hosts' ],
		'customer' => [ 'holiday-host' ],
		'related'  => [ 'deep-cleaning', 'carpet-upholstery-cleaning', 'oven-cleaning' ],
		'fields'   => [
			'hero_heading'      => 'Holiday Let & Airbnb Cleaning in East Yorkshire',
			'hero_subheading'   => 'Reliable changeover cleans, linen and restocking for holiday lets on the coast and across East Yorkshire.',
			'price_from'        => 85,
			'price_unit'        => 'per changeover',
			'typical_duration'  => '2–4 hours',
			'short_description' => 'Reliable changeover cleans, linen and restocking for holiday lets and serviced accommodation.',
			'intro'             => '<p>Five-star reviews start with a spotless changeover. We clean and reset your holiday let or Airbnb between guests, change and manage linen, restock essentials and report any damage or maintenance issues with photos.</p><p>We cover the East Yorkshire coast — Bridlington, Hornsea and Withernsea — as well as Hull, Beverley and the Wolds, including weekend changeover days.</p>',
			'who_its_for'       => [
				[ 'label' => 'Airbnb hosts', 'description' => 'Back-to-back changeovers that keep your ratings high.' ],
				[ 'label' => 'Holiday cottage owners', 'description' => 'Reliable cover for busy Saturday changeovers.' ],
				[ 'label' => 'Serviced accommodation', 'description' => 'Contract cleaning for multiple units.' ],
			],
			'checklist'         => [
				[ 'room' => 'Changeover', 'tasks' => "Strip and remake beds with fresh linen\nClean and sanitise kitchen and bathrooms\nVacuum and mop throughout\nEmpty bins and dishwasher\nRestock toiletries, tea, coffee and essentials\nStage the property to your photos\nPhoto report and damage check" ],
			],
			'not_included'      => [
				[ 'item' => 'Linen hire (available as an add-on)' ],
				[ 'item' => 'Consumables (charged at cost)' ],
				[ 'item' => 'Maintenance repairs' ],
			],
			'local_section'     => '<p>From seafront flats in Bridlington to cottages in the Wolds, we know how tight changeover windows are on the East Yorkshire coast.</p>',
			'price_table'       => [
				[ 'property_size' => '1 bedroom', 'price' => 85, 'note' => '' ],
				[ 'property_size' => '2 bedrooms', 'price' => 110, 'note' => '' ],
				[ 'property_size' => '3 bedrooms', 'price' => 150, 'note' => '' ],
				[ 'property_size' => '4+ bedrooms', 'price' => 200, 'note' => 'From' ],
			],
			'addons'            => [
				[ 'name' => 'Linen & towel hire', 'price' => 15, 'description' => 'Per bed, laundered and pressed.' ],
				[ 'name' => 'Deep clean', 'price' => null, 'description' => 'Between seasons — quote on request.' ],
			],
			'price_disclaimer'  => 'Prices per changeover. Discounts for regular contracts and multiple properties.',
			'faqs'              => [
				[ 'question' => 'Can you manage the linen?', 'answer' => 'Yes. We can supply, launder and change linen and towels, or use your own.' ],
				[ 'question' => 'Do you work Saturdays?', 'answer' => 'Yes. Saturday changeovers are our busiest day, so please book early in peak season.' ],
				[ 'question' => 'Will you tell me about damage?', 'answer' => 'Yes. You get a photo report after every changeover, including anything broken or missing.' ],
				$insured_faq,
			],
			'schema_service_type' => 'Holiday let cleaning',
		],
	],

	'communal-area-cleaning' => [
		'title'    => 'Communal Area Cleaning',
		'order'    => 21,
		'group'    => [ 'landlords-hosts', 'business' ],
		'customer' => [ 'landlord', 'letting-agent', 'business' ],
		'related'  => [ 'office-cleaning', 'commercial-cleaning', 'end-of-tenancy-cleaning' ],
		'fields'   => [
			'hero_heading'      => 'Communal Area Cleaning in Hull & East Yorkshire',
			'hero_subheading'   => 'Scheduled cleaning of stairwells, entrances and shared spaces for landlords, agents and managing agents.',
			'price_from'        => 30,
			'price_unit'        => 'per visit',
			'typical_duration'  => '1–2 hours per visit',
			'short_description' => 'Scheduled cleaning of hallways, stairwells and shared spaces in flats and HMOs.',
			'intro'             => '<p>First impressions count for residents and visitors. We keep entrances, hallways, stairwells, lifts and bin stores clean on a weekly or fortnightly schedule, with a signed log and photo reports after every visit.</p><p>We work for landlords, letting agents, managing agents and residents\' associations across Hull and East Yorkshire.</p>',
			'who_its_for'       => [
				[ 'label' => 'Landlords & HMOs', 'description' => 'Keep shared spaces clean and compliant.' ],
				[ 'label' => 'Managing agents', 'description' => 'Reliable cleaning across your blocks, with reporting.' ],
				[ 'label' => 'Residents\' associations', 'description' => 'Clear, fixed monthly pricing.' ],
			],
			'checklist'         => [
				[ 'room' => 'Each visit', 'tasks' => "Vacuum and mop stairs, landings and hallways\nClean entrance doors and glass\nWipe handrails, banisters and light switches\nClean lift interior\nRemove cobwebs\nTidy bin store\nPhoto report and maintenance log" ],
			],
			'not_included'      => [
				[ 'item' => 'Window cleaning above ground floor' ],
				[ 'item' => 'Bulky waste removal' ],
			],
			'local_section'     => '<p>We clean communal areas in apartment blocks and HMOs across Hull city centre, the Avenues, Hessle and Beverley.</p>',
			'price_table'       => [
				[ 'property_size' => 'Small block (up to 6 flats)', 'price' => 30, 'note' => 'Per visit' ],
				[ 'property_size' => 'Medium block (7–20 flats)', 'price' => 55, 'note' => 'Per visit' ],
				[ 'property_size' => 'Large block / multiple sites', 'price' => null, 'note' => 'Quote' ],
			],
			'addons'            => [
				[ 'name' => 'Carpet cleaning', 'price' => null, 'description' => 'Communal carpets, quarterly or annually.' ],
				[ 'name' => 'Bin store wash', 'price' => 25, 'description' => 'Pressure wash and deodorise.' ],
			],
			'price_disclaimer'  => 'Weekly or fortnightly contracts. Monthly invoicing available.',
			'faqs'              => [
				[ 'question' => 'How do I know the clean happened?', 'answer' => 'You get a time-stamped photo report after every visit and a signed log on site if you\'d like one.' ],
				[ 'question' => 'Can you report maintenance issues?', 'answer' => 'Yes. We flag things like broken lights, damage or fly-tipping in each report.' ],
				$insured_faq,
			],
			'schema_service_type' => 'Communal area cleaning',
		],
	],

	'office-cleaning' => [
		'title'    => 'Office Cleaning',
		'order'    => 30,
		'group'    => [ 'business' ],
		'customer' => [ 'business' ],
		'related'  => [ 'commercial-cleaning', 'communal-area-cleaning', 'carpet-upholstery-cleaning' ],
		'fields'   => [
			'hero_heading'      => 'Office Cleaning in Hull & East Yorkshire',
			'hero_subheading'   => 'Reliable daily or weekly office cleaning, early mornings or evenings, with a dedicated team and fixed monthly price.',
			'price_from'        => 18,
			'price_unit'        => 'per hour',
			'typical_duration'  => 'Scheduled to suit you',
			'short_description' => 'Daily or weekly office cleaning outside working hours, with a dedicated, vetted team.',
			'intro'             => '<p>A clean office is healthier and more productive. We clean offices of all sizes across Hull and East Yorkshire, early mornings or evenings so we never get in your way.</p><p>You get the same vetted cleaners, a tailored specification, regular quality checks and a fixed monthly invoice. We can also supply washroom consumables.</p>',
			'who_its_for'       => [
				[ 'label' => 'Small offices', 'description' => 'From a couple of hours a week.' ],
				[ 'label' => 'Multi-floor offices', 'description' => 'Daily cleaning with a dedicated team and supervisor.' ],
				[ 'label' => 'Co-working spaces', 'description' => 'Flexible schedules that fit around members.' ],
			],
			'checklist'         => [
				[ 'room' => 'Workspaces', 'tasks' => "Empty bins and recycling\nDust and wipe desks (clear desks only)\nSanitise phones, keyboards and touch points\nVacuum carpets and mop hard floors" ],
				[ 'room' => 'Kitchens', 'tasks' => "Clean worktops, sinks and appliances\nLoad or empty dishwasher\nWipe tables and chairs\nMop floor" ],
				[ 'room' => 'Washrooms', 'tasks' => "Clean and sanitise toilets, urinals and basins\nPolish mirrors and fittings\nRestock consumables\nMop floor" ],
			],
			'not_included'      => [
				[ 'item' => 'IT equipment internals' ],
				[ 'item' => 'Working at height above 2m' ],
			],
			'local_section'     => '<p>We clean offices in Hull city centre, the Marina, Sutton Fields, Melton and Beverley.</p>',
			'price_table'       => [
				[ 'property_size' => 'Regular contract', 'price' => 18, 'note' => 'Per cleaner per hour' ],
				[ 'property_size' => 'One-off office deep clean', 'price' => null, 'note' => 'Quote' ],
			],
			'addons'            => [
				[ 'name' => 'Washroom consumables', 'price' => null, 'description' => 'Supplied at cost.' ],
				[ 'name' => 'Carpet cleaning', 'price' => null, 'description' => 'Quarterly or annual.' ],
				[ 'name' => 'Window cleaning', 'price' => null, 'description' => 'Interior glass and partitions.' ],
			],
			'price_disclaimer'  => 'Free site visit and fixed monthly price. Minimum 2 hours per week.',
			'faqs'              => [
				[ 'question' => 'Do you clean outside office hours?', 'answer' => 'Yes. Most offices are cleaned early mornings or evenings, and we can hold keys and alarm codes securely.' ],
				[ 'question' => 'Is there a minimum contract?', 'answer' => 'We offer rolling monthly contracts with 30 days\' notice.' ],
				[ 'question' => 'Do you provide RAMS and insurance documents?', 'answer' => 'Yes. We provide risk assessments, method statements, COSHH data sheets and insurance certificates.' ],
				$insured_faq,
			],
			'schema_service_type' => 'Office cleaning',
		],
	],

	'commercial-cleaning' => [
		'title'    => 'Commercial Cleaning',
		'order'    => 31,
		'group'    => [ 'business' ],
		'customer' => [ 'business' ],
		'related'  => [ 'office-cleaning', 'communal-area-cleaning', 'after-builders-cleaning' ],
		'fields'   => [
			'hero_heading'      => 'Commercial Cleaning in Hull & East Yorkshire',
			'hero_subheading'   => 'Contract cleaning for shops, clinics, gyms, schools and industrial units, tailored to your site and sector.',
			'price_from'        => 18,
			'price_unit'        => 'per hour',
			'typical_duration'  => 'Scheduled to suit you',
			'short_description' => 'Contract cleaning for retail, healthcare, leisure, education and industrial sites.',
			'intro'             => '<p>Every commercial site is different. We build a cleaning specification around your premises, sector and opening hours — whether that\'s a shop, dental practice, gym, nursery or warehouse office.</p><p>You get trained, vetted cleaners, regular quality audits, a named account manager and all the compliance paperwork you need.</p>',
			'who_its_for'       => [
				[ 'label' => 'Retail & hospitality', 'description' => 'Spotless front-of-house before you open.' ],
				[ 'label' => 'Healthcare & clinics', 'description' => 'Infection-control cleaning for treatment rooms.' ],
				[ 'label' => 'Industrial & warehouse', 'description' => 'Offices, welfare areas and washrooms.' ],
			],
			'checklist'         => [
				[ 'room' => 'Typical specification', 'tasks' => "Front-of-house and customer areas\nWashrooms and changing rooms\nStaff kitchens and welfare areas\nTouch-point sanitisation\nHard floor and carpet care\nWaste and recycling management" ],
			],
			'not_included'      => [
				[ 'item' => 'Specialist industrial machinery cleaning' ],
				[ 'item' => 'Clinical waste disposal' ],
			],
			'local_section'     => '<p>We provide commercial cleaning across Hull, Hessle, Beverley, Brough, Goole and the wider East Riding.</p>',
			'price_table'       => [
				[ 'property_size' => 'Regular contract', 'price' => 18, 'note' => 'Per cleaner per hour' ],
				[ 'property_size' => 'Specialist or one-off clean', 'price' => null, 'note' => 'Quote' ],
			],
			'addons'            => [
				[ 'name' => 'Floor scrubbing & polishing', 'price' => null, 'description' => 'Periodic hard floor care.' ],
				[ 'name' => 'High-level cleaning', 'price' => null, 'description' => 'Ledges, lights and ducting.' ],
			],
			'price_disclaimer'  => 'Free site survey. Fixed monthly price agreed in advance.',
			'faqs'              => [
				[ 'question' => 'Which sectors do you clean?', 'answer' => 'Retail, healthcare, education, leisure, hospitality and light industrial sites. If you\'re not sure, ask — we\'ll tell you honestly if we\'re the right fit.' ],
				[ 'question' => 'Can you clean at night or weekends?', 'answer' => 'Yes. We schedule around your opening hours.' ],
				[ 'question' => 'Do you provide compliance documents?', 'answer' => 'Yes. RAMS, COSHH data sheets, insurance certificates and cleaning logs are all included.' ],
				$insured_faq,
			],
			'schema_service_type' => 'Commercial cleaning',
		],
	],
];

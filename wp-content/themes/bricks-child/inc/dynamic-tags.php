<?php
/**
 * {cc_*} dynamic tags for Bricks. Each tag renders a component from inc/components.php.
 * Tags accept an optional argument after a colon, e.g. {cc_service_cards:home} or {cc_field:intro}.
 */

defined( 'ABSPATH' ) || exit;

function cc_dynamic_tags(): array {
	return [
		'cc_logo'              => 'Logo',
		'cc_brand'             => 'Brand name',
		'cc_phone'             => 'Phone (display)',
		'cc_phone_href'        => 'Phone (tel: link)',
		'cc_phone_link'        => 'Phone link',
		'cc_email_link'        => 'Email link',
		'cc_quote_url'         => 'Quote URL',
		'cc_cta_primary'       => 'Primary CTA label',
		'cc_cta_secondary'     => 'Secondary CTA label',
		'cc_hours_today'       => 'Opening hours today',
		'cc_opening_hours'     => 'Opening hours list',
		'cc_contact_list'      => 'Contact details list',
		'cc_social'            => 'Social links',
		'cc_legal'             => 'Legal line',
		'cc_announcement'      => 'Announcement text',
		'cc_option'            => 'Business setting (text)',
		'cc_option_html'       => 'Business setting (rich text)',
		'cc_trust_bar'         => 'Trust bar',
		'cc_trust_list'        => 'Trust ticks list',
		'cc_guarantee'         => 'Guarantee box',
		'cc_breadcrumbs'       => 'Breadcrumbs',
		'cc_hero_heading'      => 'Hero heading',
		'cc_hero_sub'          => 'Hero subheading',
		'cc_hero_facts'        => 'Hero facts (price, time, availability)',
		'cc_price_from'        => 'Price from',
		'cc_hero_card_title'   => 'Hero card title (price or quote offer)',
		'cc_quote_promise'     => 'Quote promise (when prices are hidden)',
		'cc_field'             => 'Rich text field (with service fallback)',
		'cc_who_for'           => 'Who it\'s for',
		'cc_checklist'         => 'Cleaning checklist',
		'cc_not_included'      => 'Not included',
		'cc_price_table'       => 'Price table',
		'cc_addons'            => 'Add-ons',
		'cc_faqs'              => 'FAQs',
		'cc_before_after'      => 'Before & after gallery',
		'cc_reviews'           => 'Reviews',
		'cc_service_cards'     => 'Service cards (optional group slug)',
		'cc_service_carousel'  => 'Service cards carousel (all services)',
		'cc_service_groups'    => 'Services grouped by category',
		'cc_area_links'        => 'Area links (chips|cards)',
		'cc_area_services'     => 'Services in this area',
		'cc_neighbourhoods'    => 'Neighbourhoods',
		'cc_related_services'  => 'Related services',
		'cc_service_areas'     => 'Areas for this service',
		'cc_how_it_works'      => 'How it works steps',
		'cc_pricing_overview'  => 'Pricing overview (all services)',
		'cc_all_faqs'          => 'All service FAQs',
		'cc_latest_guides'     => 'Latest guides',
		'cc_footer_services'   => 'Footer: services list',
		'cc_footer_areas'      => 'Footer: areas list',
		'cc_company_links'     => 'Footer: company links',
		'cc_hero_media'        => 'Hero background photo (featured image)',
		'cc_option_image'      => 'Business Settings photo as background (field name)',
		'cc_why_media'         => 'Why us photo collage',
		'cc_stats'             => 'Headline stats',
		'cc_marquee'           => 'Scrolling towns strip',
		'cc_promos'            => 'Audience promos (landlords, business, hosts)',
		'cc_area_name'         => 'Area name (local service)',
		'cc_service_name'      => 'Service name (local service)',
	];
}

function cc_render_dynamic_tag( string $name, string $arg, $post ): ?string {
	$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;
	if ( ! $post_id ) {
		$post_id = (int) get_queried_object_id();
	}

	switch ( $name ) {
		case 'cc_logo':
			return cc_logo_html();
		case 'cc_brand':
			return esc_html( cc_brand_name() );
		case 'cc_phone':
			return esc_html( cc_phone_display() );
		case 'cc_phone_href':
			return cc_phone_href();
		case 'cc_phone_link':
			return cc_phone_link();
		case 'cc_email_link':
			return cc_email_link();
		case 'cc_quote_url':
			return esc_url( cc_quote_url() );
		case 'cc_cta_primary':
			return esc_html( cc_option( 'cta_primary_label', 'Get a free quote' ) );
		case 'cc_cta_secondary':
			return esc_html( cc_option( 'cta_secondary_label', 'Call us' ) );
		case 'cc_hours_today':
			return esc_html( cc_opening_hours() ? cc_opening_hours_today() : '' );
		case 'cc_opening_hours':
			return cc_opening_hours_html();
		case 'cc_contact_list':
			return cc_contact_list_html();
		case 'cc_social':
			return cc_social_links_html();
		case 'cc_legal':
			return cc_legal_line();
		case 'cc_announcement':
			return cc_announcement_html();
		case 'cc_option':
			$value = cc_option( sanitize_key( $arg ) );
			return is_scalar( $value ) ? esc_html( (string) $value ) : '';
		case 'cc_option_html':
			$value = cc_option( sanitize_key( $arg ) );
			return is_string( $value ) ? wp_kses_post( $value ) : '';
		case 'cc_trust_bar':
			return cc_trust_bar_html();
		case 'cc_trust_list':
			return cc_trust_list_html();
		case 'cc_guarantee':
			return cc_guarantee_html( $post_id );
		case 'cc_breadcrumbs':
			return cc_breadcrumbs_html( $post_id );
		case 'cc_hero_heading':
			return esc_html( cc_hero_heading( $post_id ) );
		case 'cc_hero_sub':
			return cc_hero_sub( $post_id );
		case 'cc_hero_facts':
			return cc_hero_facts_html( $post_id );
		case 'cc_price_from':
			return esc_html( cc_price_from_text( $post_id ) );
		case 'cc_hero_card_title':
			return esc_html( cc_hero_card_title( $post_id ) );
		case 'cc_quote_promise':
			return cc_quote_promise_html();
		case 'cc_field':
			return cc_rich_field_html( sanitize_key( $arg ), $post_id );
		case 'cc_who_for':
			return cc_who_for_html( $post_id );
		case 'cc_checklist':
			return cc_checklist_html( $post_id );
		case 'cc_not_included':
			return cc_not_included_html( $post_id );
		case 'cc_price_table':
			return cc_price_table_html( $post_id );
		case 'cc_addons':
			return cc_addons_html( $post_id );
		case 'cc_faqs':
			return cc_faqs_html( $post_id );
		case 'cc_before_after':
			return cc_before_after_html( $post_id );
		case 'cc_reviews':
			return cc_reviews_html( in_array( get_post_type( $post_id ), [ 'cc_service', 'cc_area', 'cc_local_service' ], true ) ? $post_id : 0 );
		case 'cc_service_cards':
			return cc_service_cards_html( sanitize_title( $arg ) );
		case 'cc_service_carousel':
			return cc_service_carousel_html();
		case 'cc_service_groups':
			return cc_service_groups_html();
		case 'cc_area_links':
			return cc_area_links_html( $arg ?: 'chips', get_post_type( $post_id ) === 'cc_area' ? $post_id : 0 );
		case 'cc_area_services':
			return cc_area_services_html( $post_id );
		case 'cc_neighbourhoods':
			return cc_neighbourhoods_html( $post_id );
		case 'cc_related_services':
			return cc_related_services_html( $post_id );
		case 'cc_service_areas':
			return cc_service_areas_html( $post_id );
		case 'cc_how_it_works':
			return cc_how_it_works_html();
		case 'cc_pricing_overview':
			return cc_pricing_overview_html();
		case 'cc_all_faqs':
			return cc_all_faqs_html();
		case 'cc_latest_guides':
			return cc_latest_guides_html( $arg ? (int) $arg : 3 );
		case 'cc_footer_services':
			return cc_footer_services_html();
		case 'cc_footer_areas':
			return cc_footer_areas_html();
		case 'cc_company_links':
			return cc_company_links_html();
		case 'cc_hero_media':
			return cc_hero_media_html( $post_id );
		case 'cc_option_image':
			return cc_option_image_html( sanitize_key( $arg ) );
		case 'cc_why_media':
			return cc_why_media_html();
		case 'cc_stats':
			return cc_stats_html();
		case 'cc_marquee':
			return cc_marquee_html();
		case 'cc_promos':
			return cc_promos_html();
		case 'cc_area_name':
		case 'cc_service_name':
			$parts = cc_local_service_parts( $post_id );
			if ( ! $parts ) {
				return esc_html( get_the_title( $post_id ) );
			}
			return esc_html( get_the_title( $parts[ $name === 'cc_area_name' ? 'area' : 'service' ] ) );
	}
	return null;
}

add_filter( 'bricks/dynamic_tags_list', function ( $tags ) {
	foreach ( cc_dynamic_tags() as $name => $label ) {
		$tags[] = [
			'name'  => '{' . $name . '}',
			'label' => $label,
			'group' => 'Cleaning Company',
		];
	}
	return $tags;
} );

add_filter( 'bricks/dynamic_data/render_tag', function ( $tag, $post, $context = 'text' ) {
	if ( ! is_string( $tag ) ) {
		return $tag;
	}
	$parts = explode( ':', trim( $tag, '{}' ), 2 );
	if ( ! isset( cc_dynamic_tags()[ $parts[0] ] ) ) {
		return $tag;
	}
	return cc_render_dynamic_tag( $parts[0], $parts[1] ?? '', $post ) ?? $tag;
}, 20, 3 );

function cc_render_dynamic_content( $content, $post = null, $context = 'text' ) {
	if ( ! is_string( $content ) || false === strpos( $content, '{cc_' ) ) {
		return $content;
	}
	return preg_replace_callback(
		'/\{(cc_[a-z_]+)(?::([^}]*))?\}/',
		static function ( $match ) use ( $post ) {
			if ( ! isset( cc_dynamic_tags()[ $match[1] ] ) ) {
				return $match[0];
			}
			return (string) cc_render_dynamic_tag( $match[1], $match[2] ?? '', $post );
		},
		$content
	);
}

add_filter( 'bricks/dynamic_data/render_content', 'cc_render_dynamic_content', 20, 3 );
add_filter( 'bricks/frontend/render_data', 'cc_render_dynamic_content', 20, 2 );

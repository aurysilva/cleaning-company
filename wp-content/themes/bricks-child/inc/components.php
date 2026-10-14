<?php
/**
 * HTML components rendered from SCF data. Exposed to Bricks as {cc_*} dynamic tags (inc/dynamic-tags.php).
 * Local Service pages fall back to their parent Service for shared content (checklist, prices, add-ons).
 */

defined( 'ABSPATH' ) || exit;

function cc_get( string $name, int $post_id ) {
	return function_exists( 'get_field' ) ? get_field( $name, $post_id ) : null;
}

function cc_parent_service_id( int $post_id ): int {
	if ( get_post_type( $post_id ) !== 'cc_local_service' ) {
		return $post_id;
	}
	return (int) get_post_meta( $post_id, 'service', true ) ?: $post_id;
}

/**
 * Field value from the post itself, or from its parent service when empty.
 */
function cc_field_with_fallback( string $name, int $post_id ) {
	$value = cc_get( $name, $post_id );
	if ( ! empty( $value ) ) {
		return $value;
	}
	$parent = cc_parent_service_id( $post_id );
	return $parent !== $post_id ? cc_get( $name, $parent ) : $value;
}

function cc_money( $amount ): string {
	$amount = (float) $amount;
	return '£' . ( floor( $amount ) == $amount ? number_format( $amount ) : number_format( $amount, 2 ) );
}

function cc_lines( string $text ): array {
	return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $text ) ) ) );
}

function cc_esc_text( string $text ): string {
	return esc_html( wp_strip_all_tags( $text ) );
}

/* -------------------------------------------------------------------------
 * Brand & contact
 * ---------------------------------------------------------------------- */

function cc_logo_html(): string {
	$logo = cc_option( 'logo' );
	$name = cc_brand_name();

	$inner = is_array( $logo ) && ! empty( $logo['url'] )
		? sprintf( '<img src="%s" alt="%s" width="%d" height="%d">', esc_url( $logo['url'] ), esc_attr( $name ), (int) ( $logo['width'] ?? 180 ), (int) ( $logo['height'] ?? 48 ) )
		: sprintf( '<span class="cc-logo__mark" aria-hidden="true">✦</span><span class="cc-logo__name">%s</span>', esc_html( $name ) );

	return sprintf( '<a class="cc-logo" href="%s" aria-label="%s — home">%s</a>', esc_url( home_url( '/' ) ), esc_attr( $name ), $inner );
}

function cc_email_link(): string {
	$email = sanitize_email( (string) cc_option( 'email' ) );
	return $email ? sprintf( '<a href="mailto:%1$s">%1$s</a>', esc_html( $email ) ) : '';
}

function cc_opening_hours_html(): string {
	$rows = cc_opening_hours();
	if ( ! $rows ) {
		return '';
	}
	$out = '<ul class="cc-hours">';
	foreach ( $rows as $row ) {
		$days = $row['days'];
		$label = count( $days ) > 2 ? reset( $days ) . '–' . end( $days ) : implode( ', ', $days );
		$out  .= sprintf( '<li><span>%s</span><span>%s–%s</span></li>', esc_html( $label ), esc_html( $row['opens'] ), esc_html( $row['closes'] ) );
	}
	return $out . '</ul>';
}

function cc_contact_list_html(): string {
	$items = [];
	if ( cc_phone_href() ) {
		$items[] = sprintf( '<li><span class="cc-contact-list__label">Call</span>%s</li>', cc_phone_link() );
	}
	if ( cc_whatsapp_href() ) {
		$items[] = sprintf( '<li><span class="cc-contact-list__label">WhatsApp</span><a href="%s" rel="noopener">Message us</a></li>', esc_url( cc_whatsapp_href() ) );
	}
	if ( cc_option( 'email' ) ) {
		$items[] = sprintf( '<li><span class="cc-contact-list__label">Email</span>%s</li>', cc_email_link() );
	}
	if ( cc_full_address() ) {
		$items[] = sprintf( '<li><span class="cc-contact-list__label">Office</span>%s</li>', esc_html( cc_full_address() ) );
	}
	return $items ? '<ul class="cc-contact-list">' . implode( '', $items ) . '</ul>' : '';
}

function cc_social_links_html(): string {
	$links = array_filter( (array) cc_option( 'social_links', [] ), fn( $row ) => ! empty( $row['url'] ) );
	if ( ! $links ) {
		return '';
	}
	$out = '<ul class="cc-social">';
	foreach ( $links as $row ) {
		$out .= sprintf( '<li><a href="%s" rel="noopener" target="_blank">%s</a></li>', esc_url( $row['url'] ), esc_html( ucfirst( $row['network'] ?? 'Link' ) ) );
	}
	return $out . '</ul>';
}

function cc_legal_line(): string {
	$parts = [ sprintf( '© %s %s', wp_date( 'Y' ), cc_option( 'legal_name', cc_brand_name() ) ) ];
	if ( cc_option( 'company_number' ) ) {
		$parts[] = 'Company no. ' . cc_option( 'company_number' );
	}
	if ( cc_option( 'vat_number' ) ) {
		$parts[] = 'VAT ' . cc_option( 'vat_number' );
	}
	return esc_html( implode( ' · ', $parts ) );
}

function cc_announcement_html(): string {
	if ( ! cc_option( 'announcement_enabled' ) || ! cc_option( 'announcement_text' ) ) {
		return '';
	}
	$text = esc_html( cc_option( 'announcement_text' ) );
	$link = cc_option( 'announcement_link' );
	return $link ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), $text ) : $text;
}

function cc_quote_url(): string {
	return (string) cc_option( 'quote_url', home_url( '/contact/' ) );
}

/* -------------------------------------------------------------------------
 * Trust
 * ---------------------------------------------------------------------- */

function cc_trust_items(): array {
	$items = [];
	if ( cc_google_rating() ) {
		$items[] = sprintf( '<span class="cc-stars" aria-hidden="true">★★★★★</span> %s on Google%s', esc_html( cc_google_rating() ), cc_review_count() ? ' (' . esc_html( cc_review_count() ) . ' reviews)' : '' );
	}
	foreach ( (array) cc_option( 'trust_points', [] ) as $row ) {
		if ( ! empty( $row['text'] ) ) {
			$items[] = esc_html( $row['text'] );
		}
	}
	return $items;
}

function cc_trust_bar_html(): string {
	$items = cc_trust_items();
	if ( ! $items ) {
		return '';
	}
	$out = '<ul class="cc-trust-bar">';
	foreach ( $items as $item ) {
		$out .= '<li class="cc-trust-bar__item">' . $item . '</li>';
	}
	return $out . '</ul>';
}

function cc_trust_list_html(): string {
	$items = cc_trust_items();
	if ( ! $items ) {
		return '';
	}
	return '<ul class="cc-ticks">' . implode( '', array_map( fn( $i ) => '<li>' . $i . '</li>', $items ) ) . '</ul>';
}

function cc_guarantee_html( int $post_id = 0 ): string {
	if ( $post_id ) {
		$service = cc_parent_service_id( $post_id );
		if ( get_post_type( $service ) === 'cc_service' && ! cc_get( 'use_global_guarantee', $service ) && cc_get( 'guarantee_override', $service ) ) {
			return '<div class="cc-guarantee">' . wp_kses_post( cc_get( 'guarantee_override', $service ) ) . '</div>';
		}
	}
	$title = cc_option( 'guarantee_title' );
	$text  = cc_option( 'guarantee_short' );
	if ( ! $title && ! $text ) {
		return '';
	}
	return sprintf(
		'<div class="cc-guarantee"><span class="cc-guarantee__badge" aria-hidden="true">✓</span><div><p class="cc-guarantee__title">%s</p><p>%s</p><a href="%s">Read our guarantee</a></div></div>',
		esc_html( $title ),
		esc_html( $text ),
		esc_url( home_url( '/our-guarantee/' ) )
	);
}

/* -------------------------------------------------------------------------
 * Hero
 * ---------------------------------------------------------------------- */

function cc_hero_heading( int $post_id ): string {
	$heading = (string) cc_get( 'hero_heading', $post_id );
	if ( $heading ) {
		return $heading;
	}

	switch ( get_post_type( $post_id ) ) {
		case 'cc_service':
			return get_the_title( $post_id ) . ' in Hull & East Yorkshire';
		case 'cc_area':
			return 'Cleaners in ' . get_the_title( $post_id );
		case 'cc_local_service':
			$parts = cc_local_service_parts( $post_id );
			return $parts ? get_the_title( $parts['service'] ) . ' in ' . get_the_title( $parts['area'] ) : get_the_title( $post_id );
	}
	return get_the_title( $post_id );
}

function cc_hero_sub( int $post_id ): string {
	$sub = (string) cc_get( 'hero_subheading', $post_id );
	if ( ! $sub ) {
		$sub = (string) cc_field_with_fallback( 'short_description', $post_id );
	}
	if ( ! $sub && has_excerpt( $post_id ) ) {
		$sub = get_the_excerpt( $post_id );
	}
	return esc_html( $sub );
}

function cc_price_from_text( int $post_id ): string {
	$service = cc_parent_service_id( $post_id );
	$price   = cc_get( 'price_from', $service );
	if ( ! $price ) {
		return '';
	}
	return trim( 'From ' . cc_money( $price ) . ' ' . cc_get( 'price_unit', $service ) );
}

function cc_hero_facts_html( int $post_id ): string {
	$service = cc_parent_service_id( $post_id );
	$facts   = [];

	if ( get_post_type( $service ) === 'cc_service' && ( $duration = cc_get( 'typical_duration', $service ) ) ) {
		$facts[] = [ 'Typical time', $duration ];
	}
	if ( get_post_type( $post_id ) === 'cc_area' && ( $postcodes = cc_get( 'postcodes', $post_id ) ) ) {
		$facts[] = [ 'Postcodes', $postcodes ];
	}
	$days = array_unique( array_merge( [], ...array_column( cc_opening_hours(), 'days' ) ) );
	if ( count( $days ) === 7 ) {
		$facts[] = [ 'Availability', '7 days a week' ];
	}

	if ( ! $facts ) {
		return '';
	}

	$out = '<dl class="cc-facts">';
	foreach ( $facts as [ $label, $value ] ) {
		$out .= sprintf( '<div><dt>%s</dt><dd>%s</dd></div>', esc_html( $label ), esc_html( $value ) );
	}
	return $out . '</dl>';
}

function cc_breadcrumbs_html( int $post_id ): string {
	$crumbs = [ [ 'Home', home_url( '/' ) ] ];
	$type   = get_post_type( $post_id );

	if ( $type === 'cc_service' ) {
		$crumbs[] = [ 'Services', home_url( '/services/' ) ];
	} elseif ( $type === 'cc_area' ) {
		$crumbs[] = [ 'Areas', home_url( '/areas/' ) ];
	} elseif ( $type === 'cc_local_service' ) {
		$crumbs[] = [ 'Services', home_url( '/services/' ) ];
		$parts    = cc_local_service_parts( $post_id );
		if ( $parts ) {
			$crumbs[] = [ get_the_title( $parts['service'] ), get_permalink( $parts['service'] ) ];
		}
	} elseif ( $type === 'post' ) {
		$crumbs[] = [ 'Guides', home_url( '/guides/' ) ];
	}

	$crumbs[] = [ get_the_title( $post_id ), '' ];

	$out = '<nav class="cc-breadcrumbs" aria-label="Breadcrumb"><ol>';
	foreach ( $crumbs as [ $label, $url ] ) {
		$out .= $url
			? sprintf( '<li><a href="%s">%s</a></li>', esc_url( $url ), esc_html( $label ) )
			: sprintf( '<li aria-current="page">%s</li>', esc_html( $label ) );
	}
	return $out . '</ol></nav>';
}

/* -------------------------------------------------------------------------
 * Service content
 * ---------------------------------------------------------------------- */

function cc_rich_field_html( string $name, int $post_id ): string {
	$value = cc_field_with_fallback( $name, $post_id );
	return $value ? '<div class="cc-prose">' . wp_kses_post( $value ) . '</div>' : '';
}

function cc_who_for_html( int $post_id ): string {
	$rows = (array) cc_field_with_fallback( 'who_its_for', $post_id );
	$rows = array_filter( $rows, fn( $r ) => ! empty( $r['label'] ) );
	if ( ! $rows ) {
		return '';
	}
	$out = '<div class="cc-audience">';
	foreach ( $rows as $row ) {
		$out .= sprintf( '<div class="cc-audience__item"><h3>%s</h3><p>%s</p></div>', esc_html( $row['label'] ), esc_html( $row['description'] ?? '' ) );
	}
	return $out . '</div>';
}

function cc_checklist_html( int $post_id ): string {
	$rows = (array) cc_field_with_fallback( 'checklist', $post_id );
	$rows = array_filter( $rows, fn( $r ) => ! empty( $r['room'] ) );
	if ( ! $rows ) {
		return '';
	}
	$out = '<div class="cc-checklist">';
	foreach ( $rows as $row ) {
		$tasks = cc_lines( (string) ( $row['tasks'] ?? '' ) );
		$out  .= '<div class="cc-checklist__room"><h3 class="cc-checklist__title">' . esc_html( $row['room'] ) . '</h3><ul class="cc-checklist__tasks">';
		foreach ( $tasks as $task ) {
			$out .= '<li>' . esc_html( $task ) . '</li>';
		}
		$out .= '</ul></div>';
	}
	return $out . '</div>';
}

function cc_not_included_html( int $post_id ): string {
	$rows = array_filter( (array) cc_field_with_fallback( 'not_included', $post_id ), fn( $r ) => ! empty( $r['item'] ) );
	if ( ! $rows ) {
		return '';
	}
	return '<ul class="cc-crosses">' . implode( '', array_map( fn( $r ) => '<li>' . esc_html( $r['item'] ) . '</li>', $rows ) ) . '</ul>';
}

function cc_price_table_html( int $post_id ): string {
	$service = cc_parent_service_id( $post_id );
	$rows    = array_filter( (array) cc_get( 'price_table', $service ), fn( $r ) => ! empty( $r['property_size'] ) );
	if ( ! $rows ) {
		return '';
	}
	$out = '<div class="cc-table-wrap"><table class="cc-price-table"><thead><tr><th scope="col">Property</th><th scope="col">Price</th><th scope="col">Notes</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$out .= sprintf(
			'<tr><td>%s</td><td class="cc-price-table__price">%s</td><td class="cc-price-table__note">%s</td></tr>',
			esc_html( $row['property_size'] ),
			$row['price'] !== '' && $row['price'] !== null ? esc_html( cc_money( $row['price'] ) ) : 'Quote',
			esc_html( $row['note'] ?? '' )
		);
	}
	$out .= '</tbody></table></div>';

	if ( $disclaimer = cc_get( 'price_disclaimer', $service ) ) {
		$out .= '<p class="cc-small">' . esc_html( $disclaimer ) . '</p>';
	}
	if ( get_post_type( $post_id ) === 'cc_local_service' && ( $note = cc_get( 'local_price_note', $post_id ) ) ) {
		$out .= '<p class="cc-small">' . esc_html( $note ) . '</p>';
	}
	return $out;
}

function cc_addons_html( int $post_id ): string {
	$service = cc_parent_service_id( $post_id );
	$rows    = array_filter( (array) cc_get( 'addons', $service ), fn( $r ) => ! empty( $r['name'] ) );
	if ( ! $rows ) {
		return '';
	}
	$out = '<ul class="cc-addons">';
	foreach ( $rows as $row ) {
		$out .= sprintf(
			'<li class="cc-addons__item"><span class="cc-addons__name">%s</span><span class="cc-addons__price">%s</span><span class="cc-addons__desc">%s</span></li>',
			esc_html( $row['name'] ),
			$row['price'] ? '+' . esc_html( cc_money( $row['price'] ) ) : 'Quote',
			esc_html( $row['description'] ?? '' )
		);
	}
	return $out . '</ul>';
}

function cc_faqs_html( int $post_id ): string {
	$faqs = array_filter( (array) cc_get( 'faqs', $post_id ), fn( $r ) => ! empty( $r['question'] ) );
	if ( ! $faqs && get_post_type( $post_id ) === 'cc_local_service' ) {
		$faqs = array_filter( (array) cc_get( 'faqs', cc_parent_service_id( $post_id ) ), fn( $r ) => ! empty( $r['question'] ) );
	}
	if ( ! $faqs ) {
		return '';
	}
	$out = '<div class="cc-faq">';
	foreach ( $faqs as $faq ) {
		$out .= sprintf(
			'<details class="cc-faq__item"><summary class="cc-faq__question">%s</summary><div class="cc-faq__answer">%s</div></details>',
			esc_html( $faq['question'] ),
			wp_kses_post( wpautop( $faq['answer'] ?? '' ) )
		);
	}
	return $out . '</div>';
}

function cc_before_after_html( int $post_id ): string {
	$rows = array_filter( (array) cc_field_with_fallback( 'before_after', $post_id ), fn( $r ) => ! empty( $r['before'] ) && ! empty( $r['after'] ) );
	if ( ! $rows ) {
		return '';
	}
	$img = function ( $image, $label ) {
		$id = is_array( $image ) ? (int) ( $image['ID'] ?? $image['id'] ?? 0 ) : (int) $image;
		return '<div class="cc-before-after__image">' . wp_get_attachment_image( $id, 'cc-card', false, [ 'loading' => 'lazy' ] ) . '<span class="cc-before-after__label">' . $label . '</span></div>';
	};
	$out = '<div class="cc-gallery">';
	foreach ( $rows as $row ) {
		$out .= '<figure class="cc-before-after">' . $img( $row['before'], 'Before' ) . $img( $row['after'], 'After' );
		if ( ! empty( $row['caption'] ) ) {
			$out .= '<figcaption class="cc-before-after__caption">' . esc_html( $row['caption'] ) . '</figcaption>';
		}
		$out .= '</figure>';
	}
	return $out . '</div>';
}

function cc_reviews_html( int $post_id = 0, int $limit = 6 ): string {
	$reviews = $post_id ? (array) cc_get( 'reviews', $post_id ) : [];
	if ( ! $reviews ) {
		$reviews = get_posts( [
			'post_type'      => 'cc_review',
			'posts_per_page' => $limit,
			'meta_key'       => 'featured',
			'meta_value'     => '1',
			'no_found_rows'  => true,
		] );
	}
	if ( ! $reviews ) {
		return '';
	}
	$out = '<div class="cc-reviews">';
	foreach ( array_slice( $reviews, 0, $limit ) as $review ) {
		$id     = $review instanceof WP_Post ? $review->ID : (int) $review;
		$rating = max( 1, min( 5, (int) cc_get( 'rating', $id ) ) );
		$area   = cc_get( 'area', $id );
		$out   .= sprintf(
			'<blockquote class="cc-review"><span class="cc-stars" aria-label="%1$d out of 5 stars">%2$s</span><p>%3$s</p><footer><strong>%4$s</strong>%5$s</footer></blockquote>',
			$rating,
			str_repeat( '★', $rating ),
			esc_html( (string) cc_get( 'review_text', $id ) ),
			esc_html( (string) cc_get( 'reviewer_name', $id ) ),
			$area ? ', ' . esc_html( get_the_title( $area ) ) : ''
		);
	}
	return $out . '</div>';
}

/* -------------------------------------------------------------------------
 * Listings
 * ---------------------------------------------------------------------- */

function cc_services( array $args = [] ): array {
	return get_posts( array_merge( [
		'post_type'      => 'cc_service',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	], $args ) );
}

function cc_areas(): array {
	return get_posts( [
		'post_type'      => 'cc_area',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	] );
}

function cc_service_card( WP_Post $service, string $url = '' ): string {
	$icon  = cc_get( 'icon', $service->ID );
	$price = cc_price_from_text( $service->ID );
	return sprintf(
		'<article class="cc-card">%s<h3 class="cc-card__title"><a href="%s">%s</a></h3><p class="cc-card__text">%s</p>%s<a class="cc-card__link" href="%2$s" aria-hidden="true" tabindex="-1">Learn more</a></article>',
		is_array( $icon ) && ! empty( $icon['url'] ) ? '<img class="cc-card__icon" src="' . esc_url( $icon['url'] ) . '" alt="" width="48" height="48">' : '',
		esc_url( $url ?: get_permalink( $service ) ),
		esc_html( get_the_title( $service ) ),
		esc_html( (string) cc_get( 'short_description', $service->ID ) ),
		$price ? '<p class="cc-card__price">' . esc_html( $price ) . '</p>' : ''
	);
}

function cc_service_cards_html( string $group = '', int $limit = -1 ): string {
	$args = [ 'posts_per_page' => $limit ];
	if ( $group ) {
		$args['tax_query'] = [ [ 'taxonomy' => 'cc_service_group', 'field' => 'slug', 'terms' => $group ] ];
	}
	$services = cc_services( $args );
	if ( ! $services ) {
		return '';
	}
	return '<div class="cc-grid cc-grid--cards">' . implode( '', array_map( 'cc_service_card', $services ) ) . '</div>';
}

function cc_service_groups_html(): string {
	$terms = get_terms( [ 'taxonomy' => 'cc_service_group', 'hide_empty' => true, 'orderby' => 'term_id' ] );
	if ( is_wp_error( $terms ) || ! $terms ) {
		return cc_service_cards_html();
	}
	$out = '';
	foreach ( $terms as $term ) {
		$cards = cc_service_cards_html( $term->slug );
		if ( ! $cards ) {
			continue;
		}
		$out .= sprintf( '<div class="cc-service-group" id="%s"><h2 class="cc-service-group__title">%s</h2>%s</div>', esc_attr( $term->slug ), esc_html( cc_service_group_label( $term ) ), $cards );
	}
	return $out;
}

function cc_service_group_label( WP_Term $term ): string {
	$labels = [
		'home'              => 'For your home',
		'moving-property'   => 'Moving & property',
		'landlords-hosts'   => 'For landlords & hosts',
		'business'          => 'For businesses',
		'exterior'          => 'Outside your property',
	];
	return $labels[ $term->slug ] ?? $term->name;
}

function cc_area_links_html( string $style = 'chips', int $exclude = 0 ): string {
	$areas = array_filter( cc_areas(), fn( $area ) => $area->ID !== $exclude );
	if ( ! $areas ) {
		return '';
	}
	if ( $style === 'cards' ) {
		$out = '<div class="cc-grid cc-grid--areas">';
		foreach ( $areas as $area ) {
			$out .= sprintf(
				'<a class="cc-area-card" href="%s"><span class="cc-area-card__name">%s</span><span class="cc-area-card__postcodes">%s</span></a>',
				esc_url( get_permalink( $area ) ),
				esc_html( get_the_title( $area ) ),
				esc_html( (string) cc_get( 'postcodes', $area->ID ) )
			);
		}
		return $out . '</div>';
	}
	$out = '<ul class="cc-chips">';
	foreach ( $areas as $area ) {
		$out .= sprintf( '<li><a href="%s">%s</a></li>', esc_url( get_permalink( $area ) ), esc_html( get_the_title( $area ) ) );
	}
	return $out . '</ul>';
}

function cc_link_list_html( array $posts ): string {
	if ( ! $posts ) {
		return '';
	}
	return '<ul class="cc-link-list">' . implode( '', array_map( fn( $p ) => sprintf( '<li><a href="%s">%s</a></li>', esc_url( get_permalink( $p ) ), esc_html( get_the_title( $p ) ) ), $posts ) ) . '</ul>';
}

/**
 * Services available in an area: links to the Local Service page when one exists, otherwise the main service page.
 */
function cc_area_services_html( int $area_id ): string {
	$local = get_posts( [
		'post_type'      => 'cc_local_service',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_key'       => 'area',
		'meta_value'     => $area_id,
		'no_found_rows'  => true,
	] );
	$local_by_service = [];
	foreach ( $local as $post ) {
		$local_by_service[ (int) get_post_meta( $post->ID, 'service', true ) ] = get_permalink( $post );
	}

	$popular = (array) cc_get( 'popular_services', $area_id );
	$ids     = array_map( fn( $p ) => $p instanceof WP_Post ? $p->ID : (int) $p, $popular );
	$services = $ids ? cc_services( [ 'post__in' => $ids, 'orderby' => 'post__in' ] ) : cc_services();

	if ( ! $services ) {
		return '';
	}
	return '<div class="cc-grid cc-grid--cards">' . implode( '', array_map( fn( $s ) => cc_service_card( $s, $local_by_service[ $s->ID ] ?? '' ), $services ) ) . '</div>';
}

function cc_neighbourhoods_html( int $area_id ): string {
	$rows = array_filter( (array) cc_get( 'neighbourhoods', $area_id ), fn( $r ) => ! empty( $r['name'] ) );
	if ( ! $rows ) {
		return '';
	}
	return '<ul class="cc-chips cc-chips--static">' . implode( '', array_map( fn( $r ) => '<li>' . esc_html( $r['name'] ) . '</li>', $rows ) ) . '</ul>';
}

function cc_related_services_html( int $post_id ): string {
	$service = cc_parent_service_id( $post_id );
	$related = (array) cc_get( 'related_services', $service );
	if ( ! $related ) {
		$related = cc_services( [ 'post__not_in' => [ $service ], 'posts_per_page' => 3 ] );
	}
	$related = array_filter( array_map( fn( $p ) => $p instanceof WP_Post ? $p : get_post( (int) $p ), $related ) );
	if ( ! $related ) {
		return '';
	}
	return '<div class="cc-grid cc-grid--cards">' . implode( '', array_map( 'cc_service_card', array_slice( $related, 0, 3 ) ) ) . '</div>';
}

/**
 * Other towns where this service is offered (for Service and Local Service pages).
 */
function cc_service_areas_html( int $post_id ): string {
	$service = cc_parent_service_id( $post_id );
	$local   = get_posts( [
		'post_type'      => 'cc_local_service',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_key'       => 'service',
		'meta_value'     => $service,
		'no_found_rows'  => true,
	] );
	$current_parts = cc_local_service_parts( $post_id );
	$links         = $current_parts ? [ $current_parts['area']->ID => '' ] : [];
	foreach ( $local as $post ) {
		if ( $post->ID === $post_id ) {
			continue;
		}
		$parts = cc_local_service_parts( $post->ID );
		if ( $parts ) {
			$links[ $parts['area']->ID ] = sprintf( '<li><a href="%s">%s in %s</a></li>', esc_url( get_permalink( $post ) ), esc_html( get_the_title( $service ) ), esc_html( get_the_title( $parts['area'] ) ) );
		}
	}
	foreach ( cc_areas() as $area ) {
		if ( ! isset( $links[ $area->ID ] ) ) {
			$links[ $area->ID ] = sprintf( '<li><a href="%s">%s</a></li>', esc_url( get_permalink( $area ) ), esc_html( get_the_title( $area ) ) );
		}
	}
	$links = array_filter( $links );
	return $links ? '<ul class="cc-chips">' . implode( '', $links ) . '</ul>' : '';
}

function cc_how_it_works_html(): string {
	$steps = [
		[ 'Get a price', 'Tell us about your property and the clean you need. You get a clear, fixed price — no surprises.' ],
		[ 'Pick a time', 'Choose a day and time that suits you, 7 days a week. You don\'t need to be home; we can collect keys.' ],
		[ 'We clean, guaranteed', 'Our insured, DBS-checked team arrives fully equipped. Not happy with anything? We\'ll come back and put it right.' ],
	];
	$out = '<ol class="cc-steps">';
	foreach ( $steps as $i => [ $title, $text ] ) {
		$out .= sprintf( '<li class="cc-steps__item"><span class="cc-steps__num">%d</span><h3>%s</h3><p>%s</p></li>', $i + 1, esc_html( $title ), esc_html( $text ) );
	}
	return $out . '</ol>';
}

function cc_pricing_overview_html(): string {
	$services = cc_services();
	if ( ! $services ) {
		return '';
	}
	$out = '<div class="cc-pricing">';
	foreach ( $services as $service ) {
		$table = cc_price_table_html( $service->ID );
		if ( ! $table ) {
			continue;
		}
		$out .= sprintf(
			'<section class="cc-pricing__service" id="%s"><div class="cc-pricing__head"><h2>%s</h2><a class="cc-btn cc-btn--outline" href="%s">View service</a></div>%s%s</section>',
			esc_attr( $service->post_name ),
			esc_html( get_the_title( $service ) ),
			esc_url( get_permalink( $service ) ),
			$table,
			cc_addons_html( $service->ID )
		);
	}
	return $out . '</div>';
}

function cc_all_faqs_html(): string {
	$out = '';
	foreach ( cc_services() as $service ) {
		$faqs = cc_faqs_html( $service->ID );
		if ( $faqs ) {
			$out .= sprintf( '<section class="cc-faq-group"><h2>%s</h2>%s</section>', esc_html( get_the_title( $service ) ), $faqs );
		}
	}
	return $out;
}

function cc_latest_guides_html( int $limit = 3 ): string {
	$posts = get_posts( [ 'post_type' => 'post', 'posts_per_page' => $limit, 'no_found_rows' => true ] );
	if ( ! $posts ) {
		return '';
	}
	$out = '<div class="cc-grid cc-grid--cards">';
	foreach ( $posts as $post ) {
		$out .= sprintf(
			'<article class="cc-card"><h3 class="cc-card__title"><a href="%s">%s</a></h3><p class="cc-card__text">%s</p><a class="cc-card__link" href="%1$s" aria-hidden="true" tabindex="-1">Read guide</a></article>',
			esc_url( get_permalink( $post ) ),
			esc_html( get_the_title( $post ) ),
			esc_html( wp_trim_words( get_the_excerpt( $post ), 22 ) )
		);
	}
	return $out . '</div>';
}

function cc_footer_services_html(): string {
	return cc_link_list_html( cc_services() );
}

function cc_footer_areas_html(): string {
	return cc_link_list_html( cc_areas() );
}

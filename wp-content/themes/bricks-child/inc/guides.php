<?php
/**
 * Guides (blog posts): topic-cluster hub, article furniture (meta line, contents list,
 * service box, related guides) and the guide lists shown on service pages.
 *
 * Each category is a topic cluster tied to a pillar service (term meta "cc_service").
 * Category, author and date archives redirect to the hub, which lists every cluster.
 */

defined( 'ABSPATH' ) || exit;

function cc_guide_category( int $post_id ): ?WP_Term {
	$terms = get_the_category( $post_id );
	return $terms ? $terms[0] : null;
}

function cc_guide_category_url( WP_Term $term ): string {
	return home_url( '/guides/#' . $term->slug );
}

/**
 * Categories in hub order, skipping empty ones and "Uncategorized".
 *
 * @return WP_Term[]
 */
function cc_guide_categories(): array {
	$terms = get_terms( [ 'taxonomy' => 'category', 'hide_empty' => true ] );
	if ( is_wp_error( $terms ) ) {
		return [];
	}
	$terms = array_filter( $terms, fn( $t ) => $t->slug !== 'uncategorized' );
	usort( $terms, fn( $a, $b ) => ( (int) get_term_meta( $a->term_id, 'cc_order', true ) ?: 99 ) <=> ( (int) get_term_meta( $b->term_id, 'cc_order', true ) ?: 99 ) );
	return $terms;
}

function cc_guide_term_service( WP_Term $term ): ?WP_Post {
	$slug = (string) get_term_meta( $term->term_id, 'cc_service', true );
	return $slug ? get_page_by_path( $slug, OBJECT, 'cc_service' ) : null;
}

function cc_guide_service( int $post_id ): ?WP_Post {
	$service = get_post( (int) cc_get( 'pillar_service', $post_id ) );
	if ( $service instanceof WP_Post && $service->post_status === 'publish' ) {
		return $service;
	}
	$term = cc_guide_category( $post_id );
	return $term ? cc_guide_term_service( $term ) : null;
}

function cc_reading_time( int $post_id ): int {
	$words = str_word_count( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ) );
	return max( 1, (int) round( $words / 220 ) );
}

/* -------------------------------------------------------------------------
 * Headings and contents list
 * ---------------------------------------------------------------------- */

/**
 * Gives every H2 an id (kept if already set) and returns the [id => text] list.
 */
function cc_heading_anchors( string $html, ?array &$toc = null ): string {
	$toc  = [];
	$used = [];
	return preg_replace_callback(
		'#<h2(\s[^>]*)?>(.*?)</h2>#is',
		function ( $m ) use ( &$toc, &$used ) {
			$attrs = $m[1] ?? '';
			$text  = trim( wp_strip_all_tags( $m[2] ) );
			if ( preg_match( '/\sid="([^"]+)"/', $attrs, $id ) ) {
				$toc[ $id[1] ] = $text;
				return $m[0];
			}
			$base = sanitize_title( $text ) ?: 'section';
			$slug = $base;
			for ( $i = 2; isset( $used[ $slug ] ); $i++ ) {
				$slug = "{$base}-{$i}";
			}
			$used[ $slug ] = true;
			$toc[ $slug ]  = $text;
			return sprintf( '<h2 id="%s"%s>%s</h2>', esc_attr( $slug ), $attrs, $m[2] );
		},
		$html
	);
}

add_filter( 'the_content', function ( $content ) {
	if ( get_post_type() !== 'post' || ! is_singular( 'post' ) ) {
		return $content;
	}
	$content = preg_replace( '#<table\b.*?</table>#is', '<div class="cc-table-wrap">$0</div>', (string) $content );
	return cc_heading_anchors( $content );
}, 20 );

function cc_toc_html( int $post_id ): string {
	cc_heading_anchors( (string) get_post_field( 'post_content', $post_id ), $toc );
	if ( count( $toc ) < 3 ) {
		return '';
	}
	$out = '<nav class="cc-toc" aria-label="On this page"><p class="cc-toc__title">On this page</p><ol>';
	foreach ( $toc as $id => $text ) {
		$out .= sprintf( '<li><a href="#%s">%s</a></li>', esc_attr( $id ), esc_html( $text ) );
	}
	return $out . '</ol></nav>';
}

/* -------------------------------------------------------------------------
 * Article furniture
 * ---------------------------------------------------------------------- */

function cc_guide_eyebrow_html( int $post_id ): string {
	$term = cc_guide_category( $post_id );
	return $term ? sprintf( '<a class="cc-eyebrow cc-eyebrow--link" href="%s">%s</a>', esc_url( cc_guide_category_url( $term ) ), esc_html( $term->name ) ) : '';
}

function cc_guide_meta_html( int $post_id ): string {
	$modified = get_post_modified_time( 'U', false, $post_id );
	$minutes  = cc_reading_time( $post_id );
	return sprintf(
		'<p class="cc-article-meta"><span>Updated <time datetime="%s">%s</time></span><span>%d min read</span><span>By the %s team</span></p>',
		esc_attr( get_post_modified_time( 'c', false, $post_id ) ),
		esc_html( wp_date( 'j F Y', (int) $modified ) ),
		$minutes,
		esc_html( cc_brand_name() )
	);
}

/**
 * "Need a hand?" box promoting the guide's pillar service.
 */
function cc_guide_service_html( int $post_id ): string {
	$service = cc_guide_service( $post_id );
	if ( ! $service ) {
		return '';
	}
	$image = cc_card_image_html( cc_post_image_id( $service->ID ), '(max-width: 1024px) 100vw, 360px' );
	return sprintf(
		'<aside class="cc-service-box">%s<div class="cc-service-box__body"><p class="cc-service-box__eyebrow">Need a hand?</p><p class="cc-service-box__title">%s in Hull &amp; East Yorkshire</p><p>%s</p><div class="cc-service-box__actions"><a class="cc-btn cc-btn--primary" href="%s">%s</a><a class="cc-service-box__link" href="%s">About our %s</a></div></div></aside>',
		$image ? '<div class="cc-service-box__media">' . $image . '</div>' : '',
		esc_html( get_the_title( $service ) ),
		esc_html( (string) cc_get( 'short_description', $service->ID ) ),
		esc_url( cc_quote_url() ),
		esc_html( cc_option( 'cta_primary_label', 'Get a free quote' ) ),
		esc_url( get_permalink( $service ) ),
		esc_html( strtolower( get_the_title( $service ) ) )
	);
}

/* -------------------------------------------------------------------------
 * Guide lists
 * ---------------------------------------------------------------------- */

/**
 * Lowercase text used for client-side search on the guides hub.
 */
function cc_guide_search_text( WP_Post $post, ?WP_Term $term = null ): string {
	$term  = $term ?? cc_guide_category( $post->ID );
	$parts = [
		get_the_title( $post ),
		get_the_excerpt( $post ),
		$term ? $term->name : '',
		$term ? $term->description : '',
	];
	$text = wp_strip_all_tags( implode( ' ', array_filter( $parts ) ) );
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
}

function cc_guide_card( WP_Post $post ): string {
	$image = cc_card_image_html( (int) get_post_thumbnail_id( $post ) );
	$term  = cc_guide_category( $post->ID );
	return sprintf(
		'<article class="cc-card cc-card--guide%s" data-cc-guide-search="%s">%s<div class="cc-card__body">%s<h3 class="cc-card__title"><a href="%s">%s</a></h3><p class="cc-card__text">%s</p><span class="cc-card__link" aria-hidden="true">Read guide · %d min</span></div></article>',
		$image ? ' cc-card--media' : '',
		esc_attr( cc_guide_search_text( $post, $term ) ),
		$image ? '<div class="cc-card__media">' . $image . '</div>' : '',
		$term ? '<p class="cc-card__tag">' . esc_html( $term->name ) . '</p>' : '',
		esc_url( get_permalink( $post ) ),
		esc_html( get_the_title( $post ) ),
		esc_html( wp_trim_words( get_the_excerpt( $post ), 22 ) ),
		cc_reading_time( $post->ID )
	);
}

function cc_guide_cards_html( array $posts ): string {
	$posts = array_filter( $posts, fn( $p ) => $p instanceof WP_Post );
	return $posts ? '<div class="cc-grid cc-grid--cards cc-grid--3">' . implode( '', array_map( 'cc_guide_card', $posts ) ) . '</div>' : '';
}

/**
 * Hand-picked related guides, topped up from the same cluster.
 */
function cc_related_guides_html( int $post_id, int $limit = 3 ): string {
	$ids   = array_map( 'intval', (array) cc_get( 'related_guides', $post_id ) );
	$posts = $ids ? get_posts( [ 'post__in' => $ids, 'orderby' => 'post__in', 'posts_per_page' => $limit, 'no_found_rows' => true ] ) : [];

	$term = cc_guide_category( $post_id );
	if ( count( $posts ) < $limit && $term ) {
		$posts = array_merge( $posts, get_posts( [
			'cat'            => $term->term_id,
			'post__not_in'   => array_merge( [ $post_id ], wp_list_pluck( $posts, 'ID' ) ),
			'posts_per_page' => $limit - count( $posts ),
			'no_found_rows'  => true,
		] ) );
	}
	return cc_guide_cards_html( $posts );
}

/**
 * Guides for a service or local service page: the service's own picks, else guides
 * whose pillar service it is, else its cluster.
 */
function cc_service_guides_html( int $post_id, int $limit = 3 ): string {
	$service_id = get_post_type( $post_id ) === 'cc_local_service' ? cc_parent_service_id( $post_id ) : $post_id;
	if ( ! $service_id ) {
		return '';
	}

	$posts = array_filter( (array) cc_get( 'related_guides', $service_id ), fn( $p ) => $p instanceof WP_Post && $p->post_status === 'publish' );
	if ( count( $posts ) < $limit ) {
		$posts = array_merge( $posts, get_posts( [
			'meta_key'       => 'pillar_service',
			'meta_value'     => $service_id,
			'post__not_in'   => wp_list_pluck( $posts, 'ID' ) ?: [ 0 ],
			'posts_per_page' => $limit - count( $posts ),
			'no_found_rows'  => true,
		] ) );
	}
	return cc_guide_cards_html( array_slice( $posts, 0, $limit ) );
}

/**
 * The guides hub: a chip nav, then one section per topic cluster.
 */
function cc_guide_hub_search_html(): string {
	return '<div class="cc-guide-search">'
		. '<label class="cc-guide-search__label" for="cc-guide-search-input">Search guides</label>'
		. '<div class="cc-guide-search__field">'
		. '<input type="search" id="cc-guide-search-input" class="cc-guide-search__input" placeholder="Search by topic, e.g. oven, deposit, office…" autocomplete="off" enterkeyhint="search" />'
		. '<button type="button" class="cc-guide-search__clear" hidden aria-label="Clear search">&times;</button>'
		. '</div>'
		. '<p class="cc-guide-search__status" aria-live="polite" hidden></p>'
		. '</div>';
}

function cc_guide_hub_html(): string {
	$terms = cc_guide_categories();
	if ( ! $terms ) {
		return '';
	}

	$total = array_sum( array_map( fn( $t ) => (int) $t->count, $terms ) );

	$out = '<div class="cc-guide-hub" data-cc-guide-count="' . (int) $total . '">';
	$out .= cc_guide_hub_search_html();
	$out .= '<nav class="cc-guide-nav" aria-label="Guide topics"><ul>';
	foreach ( $terms as $term ) {
		$out .= sprintf( '<li><a href="#%s">%s <span>%d</span></a></li>', esc_attr( $term->slug ), esc_html( $term->name ), (int) $term->count );
	}
	$out .= '</ul></nav>';

	foreach ( $terms as $term ) {
		$service = cc_guide_term_service( $term );
		$posts   = get_posts( [ 'cat' => $term->term_id, 'posts_per_page' => -1, 'no_found_rows' => true ] );
		$out    .= sprintf(
			'<section class="cc-guide-cluster" id="%s"><div class="cc-guide-cluster__head"><div><h2>%s</h2><p>%s</p></div>%s</div>%s</section>',
			esc_attr( $term->slug ),
			esc_html( $term->name ),
			esc_html( $term->description ),
			$service ? sprintf( '<a class="cc-guide-cluster__link" href="%s">%s</a>', esc_url( get_permalink( $service ) ), esc_html( get_the_title( $service ) ) ) : '',
			cc_guide_cards_html( $posts )
		);
	}
	$out .= '<p class="cc-guide-search__empty" hidden>No guides match your search. Try a shorter keyword or browse the topics above.</p>';
	$out .= '</div>';
	return $out;
}

/* -------------------------------------------------------------------------
 * Archives
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', function () {
	if ( is_category() ) {
		$term = get_queried_object();
		wp_safe_redirect( $term instanceof WP_Term ? cc_guide_category_url( $term ) : home_url( '/guides/' ), 301 );
		exit;
	}
	if ( is_tag() || is_author() || is_date() ) {
		wp_safe_redirect( home_url( '/guides/' ), 301 );
		exit;
	}
}, 1 ); // Before redirect_canonical, which would expose the author's login in the URL

add_filter( 'wp_sitemaps_add_provider', fn( $provider, $name ) => $name === 'users' ? false : $provider, 10, 2 );

add_filter( 'wp_sitemaps_taxonomies', function ( $taxonomies ) {
	unset( $taxonomies['category'], $taxonomies['post_tag'] );
	return $taxonomies;
} );

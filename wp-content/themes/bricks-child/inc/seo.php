<?php
/**
 * Basic on-page SEO: document title, meta description, canonical-friendly Open Graph and
 * Twitter tags. Stands down automatically if Rank Math, Yoast or AIOSEO is activated.
 */

defined( 'ABSPATH' ) || exit;

function cc_seo_enabled(): bool {
	return ! defined( 'RANK_MATH_VERSION' ) && ! defined( 'WPSEO_VERSION' ) && ! defined( 'AIOSEO_VERSION' );
}

function cc_seo_description( int $post_id ): string {
	foreach ( [ 'meta_description', 'hero_subheading', 'short_description', 'intro' ] as $field ) {
		$text = $field === 'short_description' ? cc_field_with_fallback( $field, $post_id ) : cc_get( $field, $post_id );
		if ( is_string( $text ) && trim( $text ) !== '' ) {
			break;
		}
		$text = '';
	}
	if ( ! $text && has_excerpt( $post_id ) ) {
		$text = get_the_excerpt( $post_id );
	}
	if ( ! $text ) {
		$text = (string) get_post_field( 'post_content', $post_id );
	}
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $text ) ) ) );
	if ( mb_strlen( $text ) > 158 ) {
		$text = rtrim( mb_substr( $text, 0, 155 ), " ,.;:-" ) . '…';
	}
	return $text;
}

/**
 * The post the head tags describe: the singular post, or the front page.
 */
function cc_seo_post_id(): int {
	if ( is_singular() ) {
		return (int) get_queried_object_id();
	}
	if ( is_front_page() ) {
		return (int) get_option( 'page_on_front' );
	}
	return 0;
}

add_filter( 'bricks/frontend/disable_opengraph', fn( $disable ) => cc_seo_enabled() ? true : $disable );
add_filter( 'bricks/frontend/disable_seo', fn( $disable ) => cc_seo_enabled() ? true : $disable );

add_filter( 'pre_get_document_title', function ( $title ) {
	if ( ! cc_seo_enabled() || ! is_singular() ) {
		return $title;
	}
	$custom = trim( (string) cc_get( 'seo_title', get_queried_object_id() ) );
	return $custom && ! is_front_page() ? $custom . ' | ' . cc_brand_name() : $title;
} );

add_action( 'wp_head', function () {
	if ( ! cc_seo_enabled() ) {
		return;
	}
	$post_id = cc_seo_post_id();
	if ( ! $post_id ) {
		return;
	}

	$description = cc_seo_description( $post_id );
	$is_article  = is_singular( 'post' );
	$image       = cc_post_image_id( $post_id ) ?: cc_attachment_id( cc_option( 'hero_image' ) );
	$image_src   = $image ? wp_get_attachment_image_src( $image, 'large' ) : null;

	$tags = [
		[ 'name', 'description', $description ],
		[ 'property', 'og:type', $is_article ? 'article' : 'website' ],
		[ 'property', 'og:site_name', cc_brand_name() ],
		[ 'property', 'og:locale', 'en_GB' ],
		[ 'property', 'og:title', wp_get_document_title() ],
		[ 'property', 'og:description', $description ],
		[ 'property', 'og:url', is_front_page() ? home_url( '/' ) : get_permalink( $post_id ) ],
		[ 'name', 'twitter:card', $image_src ? 'summary_large_image' : 'summary' ],
	];
	if ( $image_src ) {
		$tags[] = [ 'property', 'og:image', $image_src[0] ];
		$tags[] = [ 'property', 'og:image:width', $image_src[1] ];
		$tags[] = [ 'property', 'og:image:height', $image_src[2] ];
		$tags[] = [ 'property', 'og:image:alt', get_post_meta( $image, '_wp_attachment_image_alt', true ) ];
	}
	if ( $is_article ) {
		$tags[] = [ 'property', 'article:published_time', get_post_time( 'c', true, $post_id ) ];
		$tags[] = [ 'property', 'article:modified_time', get_post_modified_time( 'c', true, $post_id ) ];
		$term   = cc_guide_category( $post_id );
		if ( $term ) {
			$tags[] = [ 'property', 'article:section', $term->name ];
		}
	}

	foreach ( $tags as [ $attr, $key, $value ] ) {
		if ( (string) $value !== '' ) {
			printf( "<meta %s=\"%s\" content=\"%s\">\n", $attr, esc_attr( $key ), esc_attr( (string) $value ) );
		}
	}
}, 5 );

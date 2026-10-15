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

// Either the theme or the SEO plugin prints these tags, so Bricks' own copies would be duplicates
add_filter( 'bricks/frontend/disable_opengraph', '__return_true' );
add_filter( 'bricks/frontend/disable_seo', '__return_true' );

/**
 * The SEO title without the brand: the seo_title field, or "{Service} in Hull & East Yorkshire" for services.
 */
function cc_seo_title( int $post_id ): string {
	$title = trim( (string) cc_get( 'seo_title', $post_id ) );
	if ( ! $title && get_post_type( $post_id ) === 'cc_service' ) {
		// Filtered titles skip WordPress's escaping, and esc_html leaves stored entities such as &amp; intact
		$title = esc_html( get_post_field( 'post_title', $post_id ) . ' in Hull & East Yorkshire' );
	}
	return $title;
}

add_filter( 'pre_get_document_title', function ( $title ) {
	if ( ! cc_seo_enabled() || ! is_singular() || is_front_page() ) {
		return $title;
	}
	$custom = cc_seo_title( get_queried_object_id() );
	return $custom ? $custom . ' | ' . cc_brand_name() : $title;
} );

// Rank Math: a title or description set in its editor box wins, otherwise the theme's fields are used
add_filter( 'rank_math/frontend/title', function ( $title ) {
	$post_id = cc_seo_post_id();
	if ( ! $post_id || is_front_page() || get_post_meta( $post_id, 'rank_math_title', true ) ) {
		return $title;
	}
	$custom = cc_seo_title( $post_id );
	return $custom ? $custom . ' | ' . cc_brand_name() : $title;
} );

add_filter( 'rank_math/frontend/description', function ( $description ) {
	$post_id = cc_seo_post_id();
	if ( ! $post_id || get_post_meta( $post_id, 'rank_math_description', true ) ) {
		return $description;
	}
	return cc_seo_description( $post_id ) ?: $description;
} );

add_filter( 'rank_math/opengraph/facebook/og_locale', fn() => 'en_GB' );

// Pages without a featured image (e.g. town pages) share the image the theme shows for them
foreach ( [ 'facebook', 'twitter' ] as $network ) {
	add_action( "rank_math/opengraph/{$network}/add_additional_images", function ( $images ) {
		$post_id = cc_seo_post_id();
		if ( ! $post_id ) {
			return;
		}
		// Rank Math already picked a social image (editor box or featured image).
		if ( get_post_meta( $post_id, 'rank_math_facebook_image_id', true ) || has_post_thumbnail( $post_id ) ) {
			return;
		}
		$image = cc_post_image_id( $post_id );
		if ( $image ) {
			$images->add_image_by_id( $image );
		}
	} );
}

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

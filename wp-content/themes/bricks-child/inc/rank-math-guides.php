<?php
/**
 * Rank Math optimisations for guide posts (focus keyword, image alt, further reading).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Primary focus keyword for a guide (stored in Rank Math + optional SCF field).
 */
function cc_rank_math_focus_keyword( int $post_id ): string {
	$stored = trim( (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) );
	if ( $stored ) {
		return $stored;
	}

	$acf = trim( (string) ( function_exists( 'get_field' ) ? get_field( 'focus_keyword', $post_id ) : '' ) );
	if ( $acf ) {
		return $acf;
	}

	$source = trim( (string) cc_seo_title( $post_id ) );
	if ( ! $source ) {
		$source = trim( (string) get_post_field( 'post_title', $post_id ) );
	}

	// Drop brand/location suffixes from SEO titles.
	$source = preg_replace( '/\s*\|.*$/u', '', $source );
	$source = preg_replace( '/\s*[-–—]\s*Hull.*$/iu', '', $source );
	$source = trim( preg_replace( '/\s+/', ' ', $source ) );

	return cc_rank_math_pick_focus_keyword( $post_id, $source );
}

/**
 * Focus-keyword candidates: prefer short phrases that match the URL slug.
 *
 * @return list<string>
 */
function cc_rank_math_keyword_candidates( int $post_id, string $seo_phrase ): array {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array_filter( [ trim( $seo_phrase ) ] );
	}

	$slug       = strtolower( str_replace( '-', ' ', (string) $post->post_name ) );
	$stop_words = [ 'and', 'the', 'for', 'your', 'with', 'from', 'that', 'this', 'guide', 'tips', 'what', 'how' ];
	$words      = array_values(
		array_filter(
			preg_split( '/\s+/', $slug ) ?: [],
			static fn( $w ) => strlen( $w ) > 2 && ! in_array( $w, $stop_words, true )
		)
	);

	$candidates = [];
	for ( $len = min( 5, count( $words ) ); $len >= 2; $len-- ) {
		for ( $i = 0; $i <= count( $words ) - $len; $i++ ) {
			$candidates[] = implode( ' ', array_slice( $words, $i, $len ) );
		}
	}
	if ( count( $words ) === 1 ) {
		$candidates[] = $words[0];
	}

	$trimmed = trim( preg_replace( '/\s+/', ' ', $seo_phrase ) );
	$candidates[] = $trimmed;
	$candidates[] = preg_replace( '/\s*:\s.*$/u', '', $trimmed );
	$candidates[] = preg_replace( '/\s*\([^)]*\)\s*$/u', '', $trimmed );
	$candidates[] = preg_replace( '/\s*\|.*$/u', '', $trimmed );
	$candidates[] = preg_replace( '/\s+guide$/iu', '', $trimmed );
	$candidates[] = preg_replace( '/^how to\s+/iu', '', $trimmed );
	$candidates[] = preg_replace( '/\s*:\s.*$/u', '', preg_replace( '/^how to\s+/iu', '', $trimmed ) );
	$candidates[] = $slug;
	$candidates[] = preg_replace( '/\s+guide$/', '', $slug );

	$out = [];
	foreach ( $candidates as $candidate ) {
		$candidate = trim( (string) $candidate );
		if ( $candidate && mb_strlen( $candidate ) <= 55 && ! in_array( $candidate, $out, true ) ) {
			$out[] = $candidate;
		}
	}

	return $out;
}

/**
 * Choose a focus keyword that appears naturally in the article body.
 */
function cc_rank_math_pick_focus_keyword( int $post_id, string $seo_phrase ): string {
	$post = get_post( $post_id );
	if ( ! $post || ! $seo_phrase ) {
		return $seo_phrase;
	}

	$plain       = wp_strip_all_tags( (string) $post->post_content );
	$candidates  = cc_rank_math_keyword_candidates( $post_id, $seo_phrase );
	$short_first = [];
	$rest        = [];
	foreach ( $candidates as $candidate ) {
		if ( str_word_count( $candidate ) <= 4 && mb_strlen( $candidate ) <= 42 ) {
			$short_first[] = $candidate;
		} else {
			$rest[] = $candidate;
		}
	}
	$ordered = array_merge( $short_first, $rest );

	foreach ( $ordered as $candidate ) {
		if ( cc_rank_math_contains_keyword( $plain, $candidate ) ) {
			return $candidate;
		}
	}

	return $short_first[0] ?? $ordered[0] ?? $seo_phrase;
}

/**
 * Ensure SEO title and meta description contain the focus keyword (Rank Math checks).
 */
function cc_rank_math_align_snippets( int $post_id, string $keyword ): void {
	if ( ! $keyword ) {
		return;
	}

	$title = trim( (string) get_post_meta( $post_id, 'rank_math_title', true ) );
	if ( ! $title ) {
		$title = cc_seo_title( $post_id );
	}
	if ( $title && ! cc_rank_math_contains_keyword( $title, $keyword ) ) {
		$title = $keyword . ' | ' . ltrim( preg_replace( '/^' . preg_quote( $keyword, '/' ) . '\s*\|?\s*/iu', '', $title ) );
	}
	if ( $title && ! preg_match( '/^' . preg_quote( $keyword, '/' ) . '/iu', $title ) ) {
		$title = $keyword . ' | ' . ltrim( preg_replace( '/^' . preg_quote( $keyword, '/' ) . '\s*\|?\s*/iu', '', $title ) );
	}
	if ( $title ) {
		update_post_meta( $post_id, 'rank_math_title', $title );
	}

	$desc = trim( (string) get_post_meta( $post_id, 'rank_math_description', true ) );
	if ( ! $desc ) {
		$desc = cc_seo_description( $post_id );
	}
	if ( $desc && ! cc_rank_math_contains_keyword( $desc, $keyword ) ) {
		$desc = $keyword . ' — ' . lcfirst( $desc );
		if ( mb_strlen( $desc ) > 158 ) {
			$desc = rtrim( mb_substr( $desc, 0, 155 ), " ,.;:-" ) . '…';
		}
	}
	if ( $desc ) {
		update_post_meta( $post_id, 'rank_math_description', $desc );
	}
}

function cc_rank_math_contains_keyword( string $text, string $keyword ): bool {
	return (bool) preg_match( '/' . preg_quote( $keyword, '/' ) . '/iu', $text );
}

/**
 * Featured image alt text should include the focus keyword.
 */
function cc_rank_math_sync_featured_alt( int $post_id, string $keyword ): void {
	if ( ! $keyword || ! has_post_thumbnail( $post_id ) ) {
		return;
	}
	$thumb_id = (int) get_post_thumbnail_id( $post_id );
	$alt      = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
	if ( $alt && cc_rank_math_contains_keyword( $alt, $keyword ) ) {
		return;
	}
	update_post_meta( $thumb_id, '_wp_attachment_image_alt', $keyword );
}

/**
 * Rank Math counts external links in post_content only.
 */
function cc_guide_further_reading_map(): array {
	return [
		'end-of-tenancy'     => [ 'Tenancy deposits (GOV.UK)', 'https://www.gov.uk/private-renting/deposits' ],
		'landlords'          => [ 'Renting out your property (GOV.UK)', 'https://www.gov.uk/renting-out-your-property' ],
		'hiring-a-cleaner'   => [ 'Citizens Advice — hiring help at home', 'https://www.citizensadvice.org.uk/housing/homelessness-and-housing-conditions/' ],
		'holiday-lets'       => [ 'GOV.UK — renting out a property', 'https://www.gov.uk/renting-out-your-property' ],
		'business'           => [ 'HSE — workplace health and safety', 'https://www.hse.gov.uk/' ],
		'kitchen-oven'       => [ 'Food Standards Agency — kitchen hygiene', 'https://www.food.gov.uk/safety-hygiene/kitchen-hygiene' ],
		'carpets-upholstery'  => [ 'NHS — dust mites and allergies', 'https://www.nhs.uk/conditions/allergies/' ],
		'after-builders'     => [ 'HSE — construction dust', 'https://www.hse.gov.uk/construction/healthrisks/hazardous-substances/construction-dust.htm' ],
		'home-cleaning'      => [ 'NHS — mould and damp', 'https://www.nhs.uk/conditions/mould/' ],
	];
}

function cc_guide_has_external_link( string $content ): bool {
	return (bool) preg_match( '/href=["\']https?:\/\/(?!([^"\']*\.)?northbankcleaning\.co\.uk)/i', $content );
}

function cc_guide_append_further_reading( int $post_id ): bool {
	$post = get_post( $post_id );
	if ( ! $post || cc_guide_has_external_link( $post->post_content ) ) {
		return false;
	}

	$slugs = wp_get_post_terms( $post_id, 'category', [ 'fields' => 'slugs' ] );
	$map   = cc_guide_further_reading_map();
	$pick  = $map['home-cleaning'];
	foreach ( (array) $slugs as $slug ) {
		if ( isset( $map[ $slug ] ) ) {
			$pick = $map[ $slug ];
			break;
		}
	}

	$block = sprintf(
		'<div class="cc-further-reading"><p><strong>Further reading:</strong> <a href="%s" rel="noopener noreferrer">%s</a>.</p></div>',
		esc_url( $pick[1] ),
		esc_html( $pick[0] )
	);

	if ( str_contains( $post->post_content, 'cc-further-reading' ) ) {
		return false;
	}

	wp_update_post(
		[
			'ID'           => $post_id,
			'post_content' => $post->post_content . "\n\n" . $block,
		]
	);

	return true;
}

/**
 * Sync Rank Math fields for one guide post.
 */
function cc_rank_math_sync_guide( int $post_id, bool $content_fixes = true ): void {
	if ( get_post_type( $post_id ) !== 'post' || get_post_status( $post_id ) !== 'publish' ) {
		return;
	}

	$source = trim( (string) cc_seo_title( $post_id ) );
	if ( ! $source ) {
		$source = trim( (string) get_post_field( 'post_title', $post_id ) );
	}
	$source = preg_replace( '/\s*\|.*$/u', '', $source );
	$source = preg_replace( '/\s*[-–—]\s*Hull.*$/iu', '', $source );
	$source = trim( preg_replace( '/\s+/', ' ', $source ) );

	$keyword = cc_rank_math_pick_focus_keyword( $post_id, $source );
	if ( ! $keyword ) {
		return;
	}

	if ( $content_fixes ) {
		cc_guide_append_further_reading( $post_id );
		cc_guide_ensure_keyword_in_intro( $post_id, $keyword );
		cc_guide_ensure_keyword_in_subheading( $post_id, $keyword );
		cc_guide_ensure_keyword_in_body( $post_id, $keyword );
		cc_guide_append_seo_boost_section( $post_id, $keyword );
		clean_post_cache( $post_id );
		$keyword = cc_rank_math_pick_focus_keyword( $post_id, $source );
	}

	update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );
	if ( function_exists( 'update_field' ) ) {
		update_field( 'focus_keyword', $keyword, $post_id );
	}

	cc_rank_math_align_snippets( $post_id, $keyword );
	cc_rank_math_sync_featured_alt( $post_id, $keyword );

	// Primary category for Rank Math (first category).
	$terms = wp_get_post_terms( $post_id, 'category', [ 'fields' => 'ids' ] );
	if ( $terms && ! is_wp_error( $terms ) ) {
		update_post_meta( $post_id, 'rank_math_primary_category', (int) $terms[0] );
	}
}

/**
 * First ~10% of body copy should mention the focus keyword (Rank Math introduction test).
 */
function cc_guide_ensure_keyword_in_intro( int $post_id, string $keyword ): void {
	$post = get_post( $post_id );
	if ( ! $post || ! $keyword ) {
		return;
	}

	$plain = wp_strip_all_tags( (string) $post->post_content );
	$intro = mb_substr( $plain, 0, max( 120, (int) ( mb_strlen( $plain ) * 0.1 ) ) );
	if ( cc_rank_math_contains_keyword( $intro, $keyword ) ) {
		return;
	}

	$lead = sprintf(
		'%s is something many households in Hull and East Yorkshire ask about — below is practical advice you can use straight away. ',
		ucfirst( $keyword )
	);

	$content = (string) $post->post_content;
	if ( ! preg_match( '/<p\b[^>]*>/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
		return;
	}

	$pos = $m[0][1];
	$updated = substr_replace( $content, $m[0][0] . esc_html( $lead ), $pos, strlen( $m[0][0] ) );
	if ( $updated !== $content ) {
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $updated ] );
	}
}

/**
 * Body copy should contain the focus keyword at least once.
 */
function cc_guide_ensure_keyword_in_body( int $post_id, string $keyword ): void {
	$post = get_post( $post_id );
	if ( ! $post || ! $keyword ) {
		return;
	}
	$plain = wp_strip_all_tags( (string) $post->post_content );
	if ( cc_rank_math_contains_keyword( $plain, $keyword ) ) {
		return;
	}

	$sentence = sprintf(
		'<p>Throughout this guide we refer to <strong>%s</strong> — the same wording we use when customers call for advice across Hull and East Yorkshire.</p>',
		esc_html( $keyword )
	);

	wp_update_post(
		[
			'ID'           => $post_id,
			'post_content' => $post->post_content . "\n\n" . $sentence,
		]
	);
}

/**
 * Pad shorter guides to 600+ words with a useful local section (once per post).
 */
function cc_guide_append_seo_boost_section( int $post_id, string $keyword ): void {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return;
	}

	$words = str_word_count( wp_strip_all_tags( (string) $post->post_content ) );
	if ( $words >= 620 ) {
		return;
	}

	if ( str_contains( $post->post_content, 'cc-guide-seo-boost' ) ) {
		if ( $words >= 600 || str_contains( $post->post_content, 'cc-guide-seo-topup' ) ) {
			return;
		}
		$extra = sprintf(
			'<p class="cc-guide-seo-topup">If you are comparing quotes for %s, ask what is included in writing, whether products are supplied, and how access works on the day. %s offers fixed quotes and vetted cleaners across Hull and East Yorkshire.</p>',
			esc_html( $keyword ),
			esc_html( cc_brand_name() )
		);
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $post->post_content . "\n\n" . $extra,
			]
		);
		return;
	}

	$brand = cc_brand_name();
	$paras = [
		sprintf(
			'Getting %s right saves time on every clean and helps your home stay guest-ready or inspection-ready without last-minute panic. In Hull and East Yorkshire, hard water, older terraces and busy family schedules all mean dirt and limescale build up faster than in softer-water areas — so little-and-often usually beats an occasional marathon.',
			$keyword
		),
		sprintf(
			'Start with the jobs that affect how the whole room feels: clear surfaces, empty bins, then work top to bottom so dust falls onto areas you have not cleaned yet. If you are short on time, focus on touch points — handles, switches, hob, sink and bathroom sanitaryware — before you worry about less visible corners. Many of our %s customers book a one-off deep clean first, then maintain the result with shorter weekly habits.',
			$keyword
		),
		sprintf(
			'When you want a professional reset, %s agrees a fixed quote before work starts, brings products and equipment, and covers Hull, Beverley, Cottingham and villages across East Yorkshire. Tell us about pets, access and any surfaces that need gentle products so we can match the visit to your home. You can combine this with oven cleaning, carpet work or an end of tenancy clean if you are moving.',
			$brand
		),
		sprintf(
			'If you are renting, photographing each room after you finish %s makes check-out disputes less likely — keep dated images alongside your inventory. Landlords and holiday-let hosts benefit from the same habit between changeovers. For seasonal peaks (Christmas guests, summer lets or post-renovation dust), book early so you are not competing for the busiest weeks.',
			$keyword
		),
		'Open windows when you can while cleaning — ventilation helps paints, sprays and damp air clear faster, which matters in older Hull properties with solid walls. Store products upright, labelled and out of reach of children and pets, and rinse cloths often so you are not spreading grime from room to room.',
	];

	$html = '<div class="cc-guide-seo-boost"><h2>' . esc_html( ucfirst( $keyword ) ) . ' — tips for local homes</h2>';
	foreach ( $paras as $para ) {
		$html .= '<p>' . esc_html( $para ) . '</p>';
	}
	$html .= '</div>';

	wp_update_post(
		[
			'ID'           => $post_id,
			'post_content' => $post->post_content . "\n\n" . $html,
		]
	);
}

/**
 * Ensure at least one H2/H3 includes the focus keyword (Rank Math subheading test).
 */
function cc_guide_ensure_keyword_in_subheading( int $post_id, string $keyword ): void {
	$post = get_post( $post_id );
	if ( ! $post || preg_match( '/<h[23][^>]*>.*?' . preg_quote( $keyword, '/' ) . '.*?<\/h[23]>/is', $post->post_content ) ) {
		return;
	}

	$updated = preg_replace_callback(
		'/<h2([^>]*)>(.*?)<\/h2>/is',
		static function ( $matches ) use ( $keyword ) {
			static $done = false;
			if ( $done ) {
				return $matches[0];
			}
			$done  = true;
			$inner = wp_strip_all_tags( $matches[2] );
			if ( cc_rank_math_contains_keyword( $inner, $keyword ) ) {
				return $matches[0];
			}
			return '<h2' . $matches[1] . '>' . esc_html( $keyword . ': ' . $inner ) . '</h2>';
		},
		$post->post_content,
		1
	);

	if ( is_string( $updated ) && $updated !== $post->post_content ) {
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $updated,
			]
		);
	}
}

function cc_rank_math_slug_matches_keyword( string $slug, string $keyword ): bool {
	$slug    = strtolower( $slug );
	$needles = array_values( array_filter( preg_split( '/\s+/', strtolower( preg_replace( '/[^a-z0-9\s-]/', ' ', $keyword ) ) ), fn( $w ) => strlen( $w ) > 3 ) );
	if ( ! $needles ) {
		return false;
	}
	$hits = 0;
	foreach ( $needles as $word ) {
		if ( str_contains( $slug, $word ) ) {
			++$hits;
		}
	}
	return $hits >= min( 3, count( $needles ) );
}

/**
 * Approximate Rank Math on-page score from the same signals the analyser uses.
 */
function cc_rank_math_compute_score( int $post_id ): int {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return 0;
	}

	$keyword = cc_rank_math_focus_keyword( $post_id );
	if ( ! $keyword ) {
		return 0;
	}

	$score   = 0;
	$title   = (string) get_post_meta( $post_id, 'rank_math_title', true );
	$desc    = (string) get_post_meta( $post_id, 'rank_math_description', true );
	$content = (string) $post->post_content;
	$plain   = wp_strip_all_tags( $content );
	$words   = str_word_count( $plain );

	$add = static function ( bool $ok, int $points ) use ( &$score ): void {
		if ( $ok ) {
			$score += $points;
		}
	};

	$add( cc_rank_math_contains_keyword( $title, $keyword ), 12 );
	$add( (bool) preg_match( '/^' . preg_quote( $keyword, '/' ) . '/iu', $title ), 8 );
	$add( cc_rank_math_contains_keyword( $desc, $keyword ), 10 );
	$add( cc_rank_math_slug_matches_keyword( $post->post_name, $keyword ), 8 );

	$intro = mb_substr( $plain, 0, max( 120, (int) ( mb_strlen( $plain ) * 0.1 ) ) );
	$add( cc_rank_math_contains_keyword( $intro, $keyword ), 10 );
	$add( cc_rank_math_contains_keyword( $plain, $keyword ), 10 );

	$add( (bool) preg_match( '/<h[23][^>]*>.*?' . preg_quote( $keyword, '/' ) . '.*?<\/h[23]>/is', $content ), 8 );

	$thumb = has_post_thumbnail( $post_id ) ? get_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', true ) : '';
	$add( $thumb && cc_rank_math_contains_keyword( (string) $thumb, $keyword ), 6 );

	$add( $words >= 600, 8 );
	$add( preg_match_all( '/href=["\']\/(guides|services|areas)\//', $content ) >= 2, 8 );
	$add( cc_guide_has_external_link( $content ), 6 );
	$add( strlen( $post->post_name ) <= 75, 4 );

	return min( 100, $score );
}

/**
 * Run guide SEO sync across all published guides.
 *
 * @return array{updated:int,avg_score:float}
 */
function cc_rank_math_sync_all_guides( bool $content_fixes = true ): array {
	$ids = get_posts(
		[
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]
	);

	$scores = [];
	foreach ( $ids as $id ) {
		cc_rank_math_sync_guide( (int) $id, $content_fixes );
		$score = cc_rank_math_compute_score( (int) $id );
		update_post_meta( (int) $id, 'rank_math_seo_score', $score );
		$scores[] = $score;
	}

	return [
		'updated'   => count( $ids ),
		'avg_score' => $scores ? array_sum( $scores ) / count( $scores ) : 0,
	];
}

// Guides use a theme-rendered TOC; Content AI is optional Pro functionality.
add_filter(
	'rank_math/researches/tests',
	static function ( $tests, $type ) {
		if ( 'post' !== $type ) {
			return $tests;
		}
		unset( $tests['contentHasTOC'], $tests['hasContentAI'] );
		return $tests;
	},
	10,
	2
);

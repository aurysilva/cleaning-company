<?php
// wp eval-file .../rank-diagnose.php [slug]
$slug = $args[0] ?? 'bedroom-dust-mite-reduction';
$post = get_page_by_path( $slug, OBJECT, 'post' );
if ( ! $post ) {
	echo "Not found\n";
	return;
}
$id = $post->ID;
$keyword = cc_rank_math_focus_keyword( $id );
$title   = (string) get_post_meta( $id, 'rank_math_title', true );
$desc    = (string) get_post_meta( $id, 'rank_math_description', true );
$content = (string) $post->post_content;
$plain   = wp_strip_all_tags( $content );
$words   = str_word_count( $plain );
$intro   = mb_substr( $plain, 0, max( 120, (int) ( mb_strlen( $plain ) * 0.1 ) ) );

$checks = [
	'title has kw' => cc_rank_math_contains_keyword( $title, $keyword ),
	'title starts kw' => (bool) preg_match( '/^' . preg_quote( $keyword, '/' ) . '/iu', $title ),
	'desc has kw' => cc_rank_math_contains_keyword( $desc, $keyword ),
	'slug match' => cc_rank_math_slug_matches_keyword( $post->post_name, $keyword ),
	'intro kw' => cc_rank_math_contains_keyword( $intro, $keyword ),
	'body kw' => cc_rank_math_contains_keyword( $plain, $keyword ),
	'h2 kw' => (bool) preg_match( '/<h[23][^>]*>.*?' . preg_quote( $keyword, '/' ) . '.*?<\/h[23]>/is', $content ),
	'alt kw' => has_post_thumbnail( $id ) && cc_rank_math_contains_keyword( (string) get_post_meta( get_post_thumbnail_id( $id ), '_wp_attachment_image_alt', true ), $keyword ),
	'600+ words' => $words >= 600,
	'internal links' => preg_match_all( '/href=["\']\/(guides|services|areas)\//', $content ),
	'external' => cc_guide_has_external_link( $content ),
	'slug len' => strlen( $post->post_name ) <= 75,
];

echo "$slug (#$id) kw=$keyword words=$words score=" . cc_rank_math_compute_score( $id ) . "\n";
foreach ( $checks as $k => $v ) {
	echo "  $k: " . ( is_bool( $v ) ? ( $v ? 'yes' : 'NO' ) : $v ) . "\n";
}

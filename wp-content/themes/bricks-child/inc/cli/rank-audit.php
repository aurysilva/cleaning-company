<?php
// One-off: wp eval-file wp-content/themes/bricks-child/inc/cli/rank-audit.php
$ids = get_posts(
	[
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	]
);
$rows = [];
foreach ( $ids as $id ) {
	$id    = (int) $id;
	$stored = (int) get_post_meta( $id, 'rank_math_seo_score', true );
	$computed = function_exists( 'cc_rank_math_compute_score' ) ? cc_rank_math_compute_score( $id ) : 0;
	$rows[] = [ $stored, $computed, get_post_field( 'post_name', $id ), get_post_meta( $id, 'rank_math_focus_keyword', true ) ];
}
usort( $rows, fn( $a, $b ) => min( $a[0], $a[1] ) <=> min( $b[0], $b[1] ) );
foreach ( $rows as $r ) {
	if ( $r[0] < 90 || $r[1] < 90 ) {
		echo sprintf( "%3d/%3d  %-45s  %s\n", $r[0], $r[1], $r[2], $r[3] );
	}
}
$scores = array_map( fn( $r ) => $r[1], $rows );
echo sprintf( "\nCount: %d  Avg computed: %.1f  Min: %d\n", count( $scores ), array_sum( $scores ) / max( 1, count( $scores ) ), min( $scores ) );

echo "\nUnder 600 words:\n";
foreach ( $ids as $id ) {
	$w = str_word_count( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ) );
	if ( $w < 600 ) {
		echo "  $w  " . get_post_field( 'post_name', $id ) . "\n";
	}
}

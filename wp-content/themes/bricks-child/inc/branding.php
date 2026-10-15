<?php
/**
 * Brand assets shipped with the theme (logo SVG, favicons, email header image).
 */

defined( 'ABSPATH' ) || exit;

function cc_brand_assets_dir(): string {
	return CC_THEME_DIR . '/assets/brand';
}

function cc_brand_assets_uri( string $file = '' ): string {
	return CC_THEME_URI . '/assets/brand/' . ltrim( $file, '/' );
}

function cc_brand_logo_svg_uri(): string {
	return cc_brand_assets_uri( 'logo.svg' );
}

function cc_brand_has_logo(): bool {
	return is_readable( cc_brand_assets_dir() . '/logo.svg' );
}

function cc_brand_asset_version( string $file ): string {
	$path = cc_brand_assets_dir() . '/' . ltrim( $file, '/' );
	return is_readable( $path ) ? (string) filemtime( $path ) : '1';
}

/** Favicon and manifest links (site icon is also set in WordPress for older browsers). */
add_action( 'wp_head', function () {
	if ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) {
		return;
	}
	$dir = cc_brand_assets_dir() . '/favicon';
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$uri = cc_brand_assets_uri( 'favicon/' );
	$v   = cc_brand_asset_version( 'favicon/favicon.svg' );

	$links = [
		[ 'rel' => 'icon', 'type' => 'image/svg+xml', 'href' => $uri . 'favicon.svg?v=' . $v ],
		[ 'rel' => 'icon', 'type' => 'image/png', 'sizes' => '32x32', 'href' => $uri . 'favicon-32x32.png?v=' . $v ],
		[ 'rel' => 'icon', 'type' => 'image/png', 'sizes' => '16x16', 'href' => $uri . 'favicon-16x16.png?v=' . $v ],
		[ 'rel' => 'apple-touch-icon', 'sizes' => '180x180', 'href' => $uri . 'apple-touch-icon.png?v=' . $v ],
		[ 'rel' => 'manifest', 'href' => $uri . 'site.webmanifest?v=' . $v ],
	];
	foreach ( $links as $link ) {
		$attr = '';
		foreach ( $link as $key => $value ) {
			$attr .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}
		echo '<link' . $attr . '>' . "\n";
	}
}, 2 );

<?php
namespace CC\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Small DSL for describing Bricks element trees in PHP.
 *
 * Nodes are nested arrays (`name`, `settings`, `children`, `label`). `flatten()` converts them
 * to the flat element list Bricks stores in `_bricks_page_*_2` meta, assigning fresh IDs.
 */
final class Tree {
	public static function el( string $name, array $settings = [], array $children = [], string $label = '' ): array {
		return [
			'name'     => $name,
			'settings' => $settings,
			'children' => $children,
			'label'    => $label,
		];
	}

	private static function classes( string $classes, array $settings = [] ): array {
		if ( '' !== trim( $classes ) ) {
			$settings['_cssClasses'] = trim( $classes );
		}
		return $settings;
	}

	public static function section( string $class, array $children, string $label = '', array $settings = [] ): array {
		return self::el( 'section', self::classes( 'cc-section ' . $class, $settings ), $children, $label );
	}

	public static function container( string $class, array $children, string $label = '', array $settings = [] ): array {
		return self::el( 'container', self::classes( 'cc-container ' . $class, $settings ), $children, $label );
	}

	public static function div( string $class, array $children = [], string $label = '', array $settings = [] ): array {
		return self::el( 'div', self::classes( $class, $settings ), $children, $label );
	}

	public static function tag( string $tag, string $class, array $children = [], string $label = '', array $settings = [] ): array {
		$settings['tag'] = $tag;
		return self::div( $class, $children, $label, $settings );
	}

	public static function heading( string $text, string $tag = 'h2', string $class = '', string $label = '' ): array {
		return self::el( 'heading', self::classes( $class, [ 'text' => $text, 'tag' => $tag ] ), [], $label );
	}

	public static function text( string $text, string $class = '', string $tag = 'p', string $label = '' ): array {
		return self::el( 'text-basic', self::classes( $class, [ 'text' => $text, 'tag' => $tag ] ), [], $label );
	}

	/**
	 * Renders a {cc_*} dynamic tag that outputs a block of HTML.
	 */
	public static function block( string $tag, string $label = '', string $class = '' ): array {
		return self::el( 'text-basic', self::classes( $class, [ 'text' => $tag, 'tag' => 'div' ] ), [], $label ?: trim( $tag, '{}' ) );
	}

	public static function rich( string $html, string $class = '', string $label = '' ): array {
		return self::el( 'text', self::classes( $class, [ 'text' => $html ] ), [], $label );
	}

	public static function button( string $text, $link, string $class = 'cc-btn cc-btn--primary', string $label = '' ): array {
		return self::el( 'button', self::classes( $class, [ 'text' => $text, 'tag' => 'a', 'link' => self::link( $link ) ] ), [], $label );
	}

	public static function nav( int $menu_id, string $class = '', string $label = 'Navigation' ): array {
		return self::el( 'nav-menu', self::classes( $class, [
			'menu'               => (string) $menu_id,
			'mobileMenu'         => 'tablet_portrait',
			'mobileMenuPosition' => 'right',
			'mobileMenuFadeIn'   => true,
		] ), [], $label );
	}

	/**
	 * Accepts a URL string, a dynamic tag string ("{cc_phone_href}") or a full Bricks link array.
	 */
	public static function link( $link ): array {
		if ( is_array( $link ) ) {
			return $link;
		}
		return false !== strpos( $link, '{' )
			? [ 'type' => 'meta', 'useDynamicData' => $link ]
			: [ 'type' => 'external', 'url' => $link ];
	}

	/**
	 * Only render when a dynamic tag is not empty.
	 */
	public static function when_filled( string $tag ): array {
		return [
			'_conditions' => [
				[
					[
						'id'           => self::id(),
						'key'          => 'dynamic_data',
						'compare'      => '!=',
						'value'        => '',
						'dynamic_data' => $tag,
					],
				],
			],
		];
	}

	public static function id(): string {
		if ( class_exists( '\Bricks\Helpers' ) ) {
			return \Bricks\Helpers::generate_random_id( false );
		}
		return substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyz' ), 0, 6 );
	}

	public static function flatten( array $nodes ): array {
		$flat = [];
		foreach ( $nodes as $node ) {
			self::walk( $node, 0, $flat );
		}
		return $flat;
	}

	private static function walk( array $node, $parent, array &$flat ): string {
		$id    = self::id();
		$index = count( $flat );

		$flat[ $index ] = [
			'id'       => $id,
			'name'     => $node['name'],
			'parent'   => $parent,
			'children' => [],
			'settings' => $node['settings'],
		];

		if ( ! empty( $node['label'] ) ) {
			$flat[ $index ]['label'] = $node['label'];
		}

		foreach ( $node['children'] as $child ) {
			$flat[ $index ]['children'][] = self::walk( $child, $id, $flat );
		}

		return $id;
	}
}

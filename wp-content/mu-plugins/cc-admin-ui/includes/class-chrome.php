<?php
namespace CC\AdminUI;

defined( 'ABSPATH' ) || exit;

/**
 * Toolbar, footer and sidebar menu. Only runs inside wp-admin while the skin is on; the front-end toolbar is left alone.
 */
final class Chrome {
	public static function init(): void {
		add_action( 'admin_bar_menu', [ self::class, 'toolbar' ], 999 );
		add_filter( 'admin_footer_text', [ self::class, 'footer_text' ], 20 );
		add_filter( 'update_footer', [ self::class, 'footer_version' ], 20 );
		add_filter( 'custom_menu_order', [ self::class, 'custom_order' ] );
		add_filter( 'menu_order', [ self::class, 'menu_order' ], 20 );
		add_action( 'admin_menu', [ self::class, 'hide_for_non_admins' ], 999 );
	}

	public static function toolbar( \WP_Admin_Bar $bar ): void {
		if ( ! is_admin() || ! is_user_logged_in() ) {
			return;
		}
		// The way back is offered in both looks, so a broken screen can always be switched off.
		$bar->add_node( [
			'parent' => 'user-actions',
			'id'     => 'cc-ui-toggle',
			'title'  => Plugin::classic() ? 'Use the new admin look' : 'Use classic WordPress look',
			'href'   => Plugin::toggle_url(),
		] );

		if ( ! Plugin::active() ) {
			return;
		}
		foreach ( (array) apply_filters( 'cc_admin_ui_removed_toolbar_nodes', [ 'wp-logo', 'comments', 'new-content' ] ) as $id ) {
			$bar->remove_node( $id );
		}
		$site = $bar->get_node( 'site-name' );
		if ( $site ) {
			$bar->add_node( [
				'id'    => 'site-name',
				'title' => '<span class="cc-ui-brand">' . esc_html( Plugin::brand() ) . '</span>',
				'href'  => $site->href,
				'meta'  => [ 'title' => 'Visit site' ],
			] );
		}
	}

	public static function footer_text( $text ) {
		if ( ! Plugin::active() ) {
			return $text;
		}
		return sprintf( '<span class="cc-ui-footer">&copy; %s %s</span>', esc_html( wp_date( 'Y' ) ), esc_html( Plugin::brand() ) );
	}

	/** Version/update text on the right: kept for people who can update WordPress, hidden for everyone else. */
	public static function footer_version( $text ) {
		return ( Plugin::active() && ! current_user_can( 'update_core' ) ) ? '' : $text;
	}

	public static function custom_order( $enabled ) {
		return Plugin::active() ? true : $enabled;
	}

	/** Dashboard, then Bookings, then everything else in its usual order. */
	public static function menu_order( $order ) {
		if ( ! Plugin::active() || ! is_array( $order ) || ! in_array( 'cc-bookings', $order, true ) ) {
			return $order;
		}
		$first = [ 'index.php', 'cc-bookings', 'separator1' ];
		$rest  = array_values( array_diff( $order, $first ) );
		return array_merge( array_values( array_intersect( $first, $order ) ), $rest );
	}

	/**
	 * Tidies the menu for non-administrators only. Pages stay reachable by URL; administrators see everything.
	 * Comments is hidden only while the site has no comments at all.
	 */
	public static function hide_for_non_admins(): void {
		if ( ! Plugin::active() || current_user_can( 'manage_options' ) ) {
			return;
		}
		$hide = [ 'tools.php' ];
		if ( 0 === (int) wp_count_comments()->total_comments ) {
			$hide[] = 'edit-comments.php';
		}
		foreach ( (array) apply_filters( 'cc_admin_ui_hidden_menus', $hide ) as $slug ) {
			remove_menu_page( $slug );
		}
	}
}

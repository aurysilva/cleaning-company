<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Db;
use CC\Bookings\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu, the single nonce-checked action endpoint (admin-post.php?action=cc_bookings&do=…) and shared helpers.
 * Later phases add screens with the `cc_bookings_admin_pages` filter and actions with `cc_bookings_admin_action_{do}`.
 */
final class Admin {
	const ACTION = 'cc_bookings';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
		add_action( 'admin_notices', [ self::class, 'notices' ] );
	}

	/** @return array<string, array{0: string, 1: callable}> slug => [ title, renderer ] */
	public static function pages(): array {
		return apply_filters( 'cc_bookings_admin_pages', [
			'cc-bookings'          => [ 'Dashboard', [ Dashboard::class, 'render' ] ],
			'cc-bookings-quotes'   => [ 'Quotes', [ Quotes_Screen::class, 'render' ] ],
			'cc-bookings-bookings' => [ 'Bookings', [ Bookings_Screen::class, 'render' ] ],
			'cc-bookings-cleaners' => [ 'Cleaners', [ Cleaners_Screen::class, 'render' ] ],
			'cc-bookings-activity' => [ 'Activity', [ Activity_Screen::class, 'render' ] ],
			'cc-bookings-settings' => [ 'Settings', [ Settings_Screen::class, 'render' ] ],
		] );
	}

	public static function menu(): void {
		$cap = current_user_can( Plugin::CAP ) ? Plugin::CAP : 'manage_options';

		// Unread-notification bubbles are added by Notifications::badges().
		add_menu_page( 'Bookings', 'Bookings', $cap, 'cc-bookings', [ Dashboard::class, 'render' ], 'dashicons-calendar-alt', 26 );
		foreach ( self::pages() as $slug => [ $title, $callback ] ) {
			add_submenu_page( 'cc-bookings', $title . ' — Bookings', $title, $cap, $slug, $callback );
		}
	}

	public static function assets( string $hook ): void {
		if ( str_contains( $hook, 'cc-bookings' ) ) {
			wp_enqueue_style( 'cc-bookings-admin', CC_BOOKINGS_URL . 'assets/admin.css', [], \CC\Bookings\Frontend::asset_version( 'assets/admin.css' ) );
		}
	}

	/** Dispatches nonce-checked admin actions to the screen that owns them. */
	public static function handle(): void {
		$do = sanitize_key( (string) ( $_REQUEST['do'] ?? '' ) );
		if ( ! Plugin::can_manage() ) {
			wp_die( 'You are not allowed to do that.', 403 );
		}
		check_admin_referer( 'cc_bookings_' . $do );

		$handlers = [
			'quote_'   => Quotes_Screen::class,
			'booking_' => Bookings_Screen::class,
			'cleaner_' => Cleaners_Screen::class,
			'settings' => Settings_Screen::class,
		];
		foreach ( $handlers as $prefix => $class ) {
			if ( str_starts_with( $do, $prefix ) ) {
				$class::handle( $do );
				exit;
			}
		}
		do_action( 'cc_bookings_admin_action_' . $do );
		self::done( self::url( 'cc-bookings' ), 'Unknown action.', 'error' );
	}

	public static function url( string $page, array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => $page ], $args ), admin_url( 'admin.php' ) );
	}

	/** Opening tag of a POST form to the action endpoint, with nonce and hidden fields. */
	public static function form( string $do, array $hidden = [], string $attrs = '' ): string {
		$html = sprintf( '<form method="post" action="%s" %s>', esc_url( admin_url( 'admin-post.php' ) ), $attrs )
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
			. wp_nonce_field( 'cc_bookings_' . $do, '_wpnonce', true, false );
		foreach ( $hidden as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		return $html;
	}

	/** A one-button POST form, optionally with a confirm() prompt. */
	public static function button( string $do, array $hidden, string $label, string $class = 'button', string $confirm = '' ): string {
		$attrs = $confirm ? 'onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '';
		return self::form( $do, $hidden, 'class="cc-admin__inline" ' . $attrs ) . '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** Redirect back with a notice. */
	public static function done( string $url, string $message, string $type = 'success' ): void {
		set_transient( 'cc_bookings_notice_' . get_current_user_id(), [ 'message' => $message, 'type' => $type ], 60 );
		wp_safe_redirect( $url );
		exit;
	}

	public static function back( string $fallback ): string {
		$ref = wp_get_referer();
		return $ref ?: $fallback;
	}

	public static function notices(): void {
		$key    = 'cc_bookings_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), wp_kses( $notice['message'], [ 'a' => [ 'href' => [], 'target' => [] ], 'code' => [], 'strong' => [], 'br' => [] ] ) );
	}

	/** UTC DB datetime => site-time label. */
	public static function when( ?string $utc, string $format = 'j M Y, H:i' ): string {
		$ts = Db::timestamp( $utc );
		return $ts ? wp_date( $format, $ts ) : '—';
	}

	public static function header( string $title, string $actions = '' ): void {
		echo '<div class="wrap cc-admin"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>' . $actions . '<hr class="wp-header-end">'; // phpcs:ignore WordPress.Security.EscapeOutput -- $actions is built from escaped parts
	}

	public static function footer(): void {
		echo '</div>';
	}

	/** Simple status filter links for a list screen. */
	public static function filters( string $page, array $statuses, string $current, callable $count ): string {
		$links = [ sprintf( '<a href="%s"%s>All <span class="count">(%d)</span></a>', esc_url( self::url( $page ) ), '' === $current ? ' class="current"' : '', $count( '' ) ) ];
		foreach ( $statuses as $key => $label ) {
			$links[] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( self::url( $page, [ 'status' => $key ] ) ), $current === $key ? ' class="current"' : '', esc_html( $label ), $count( $key ) );
		}
		return '<ul class="subsubsub"><li>' . implode( ' | </li><li>', $links ) . '</li></ul>';
	}

	public static function pagination( string $page, int $total, int $per_page, int $current, array $args = [] ): string {
		$pages = (int) ceil( $total / $per_page );
		if ( $pages <= 1 ) {
			return '';
		}
		$links = paginate_links( [
			'base'    => add_query_arg( 'paged', '%#%', self::url( $page, $args ) ),
			'format'  => '',
			'current' => $current,
			'total'   => $pages,
		] );
		return '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>';
	}

	/** Timeline of events. */
	public static function timeline( array $events ): string {
		if ( ! $events ) {
			return '<p class="description">Nothing yet.</p>';
		}
		$html = '<ol class="cc-admin__timeline">';
		foreach ( $events as $event ) {
			$html .= sprintf(
				'<li><time>%s</time> <span class="cc-admin__event cc-admin__event--%s">%s</span> %s <span class="cc-admin__actor">— %s</span></li>',
				esc_html( self::when( $event['created_at'] ) ),
				esc_attr( $event['event'] ),
				esc_html( str_replace( '_', ' ', $event['event'] ) ),
				esc_html( $event['message'] ),
				esc_html( \CC\Bookings\Events::actor_label( (string) $event['actor'] ) )
			);
		}
		return $html . '</ol>';
	}

	public static function int( string $key ): int {
		return (int) ( $_REQUEST[ $key ] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function text( string $key ): string {
		return sanitize_text_field( wp_unslash( (string) ( $_REQUEST[ $key ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function textarea( string $key ): string {
		return sanitize_textarea_field( wp_unslash( (string) ( $_REQUEST[ $key ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
}

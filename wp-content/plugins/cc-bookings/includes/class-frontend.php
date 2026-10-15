<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Private pages: /quote/{ref}/{token}/ and /booking/{ref}/{token}/ (phase 2 adds /job/…).
 * They are noindex, never cached by LiteSpeed or the CDN, and send no Referer (the token is in the URL).
 */
final class Frontend {
	private static bool $private = false;

	public static function init(): void {
		add_action( 'init', [ self::class, 'add_rewrites' ] );
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ 'cc_quote', 'cc_booking', 'cc_token' ] ) );
		add_action( 'template_redirect', [ self::class, 'route' ], 1 );
		add_filter( 'wp_robots', [ self::class, 'robots' ], 99 );
		add_filter( 'pre_get_document_title', [ self::class, 'title' ], 99 );
		add_action( 'admin_post_cc_bookings_photo', [ Files::class, 'serve' ] );
	}

	public static function add_rewrites(): void {
		add_rewrite_rule( '^quote/(Q-\d+)/([A-Za-z0-9_-]{20,})/?$', 'index.php?cc_quote=$matches[1]&cc_token=$matches[2]', 'top' );
		add_rewrite_rule( '^booking/(B-\d+)/([A-Za-z0-9_-]{20,})/?$', 'index.php?cc_booking=$matches[1]&cc_token=$matches[2]', 'top' );
		do_action( 'cc_bookings_rewrites' );
	}

	public static function is_private(): bool {
		return self::$private;
	}

	/** Headers for any private, tokenised page. */
	public static function private_headers(): void {
		self::$private = true;
		do_action( 'litespeed_control_set_nocache', 'cc-bookings private page' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
	}

	public static function robots( array $robots ): array {
		return self::$private ? [ 'noindex' => true, 'nofollow' => true ] : $robots;
	}

	private static string $title = '';

	public static function title( $title ) {
		return self::$title ? self::$title . ' | ' . Settings::brand() : $title;
	}

	public static function route(): void {
		$quote_ref   = (string) get_query_var( 'cc_quote' );
		$booking_ref = (string) get_query_var( 'cc_booking' );
		if ( ! $quote_ref && ! $booking_ref ) {
			return;
		}
		self::private_headers();
		$token = (string) get_query_var( 'cc_token' );

		if ( $quote_ref ) {
			$quote = Quotes::verify( $quote_ref, $token );
			if ( ! $quote ) {
				self::render( 'invalid', [ 'kind' => 'quote' ], 'Quote not found', 404 );
			}
			if ( isset( $_GET['stop'] ) ) {
				Quotes::stop_followups( $quote );
			}
			if ( 'cancelled' === ( $_GET['checkout'] ?? '' ) && $quote['hold_expires_at'] && ! $quote['booking_id'] ) {
				Checkout::expire_hold( $quote['id'], $quote['stripe_session_id'], true );
			}
			if ( ! Quotes::is_expired( $quote ) ) {
				Quotes::mark_viewed( $quote );
			} elseif ( 'expired' !== $quote['status'] ) {
				Quotes::expire( $quote['id'] );
			}
			$quote = Quotes::get( $quote['id'] );
			self::render( 'quote', [ 'quote' => $quote, 'token' => $token ], 'Your quote ' . $quote['ref'] );
		}

		$booking = Bookings::verify( $booking_ref, $token );
		if ( ! $booking ) {
			self::render( 'invalid', [ 'kind' => 'booking' ], 'Booking not found', 404 );
		}
		self::render( 'booking', [ 'booking' => $booking, 'token' => $token ], 'Your booking ' . $booking['ref'] );
	}

	/**
	 * Render a plugin template inside the theme's header and footer. Themes can override with
	 * {theme}/cc-bookings/{template}.php.
	 */
	public static function render( string $template, array $vars, string $title, int $status = 200 ): void {
		global $wp_query;
		self::$title = $title;
		$wp_query->is_404  = false;
		$wp_query->is_home = false;
		status_header( $status );

		wp_enqueue_script( 'cc-bookings-quote', CC_BOOKINGS_URL . 'assets/quote-form.js', [], self::asset_version( 'assets/quote-form.js' ), [ 'in_footer' => true, 'strategy' => 'defer' ] );
		wp_localize_script( 'cc-bookings-quote', 'ccBookings', [ 'rest' => esc_url_raw( rest_url( Rest::NS . '/' ) ) ] );
		if ( in_array( $template, Cleaner_Chrome::TEMPLATES, true ) ) {
			Cleaner_Chrome::start( $template, $vars );
		}

		$file = locate_template( 'cc-bookings/' . $template . '.php' ) ?: CC_BOOKINGS_DIR . '/templates/' . $template . '.php';
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		include $file;
		exit;
	}

	public static function asset_version( string $path ): string {
		$file = CC_BOOKINGS_DIR . '/' . $path;
		return file_exists( $file ) ? (string) filemtime( $file ) : CC_BOOKINGS_VERSION;
	}
}

<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * The client area at /account/ (private: noindex, no-store, never cached, no Referer):
 *   /account/                         login (Password / "No password?" tabs); the Bookings tab when logged in
 *   /account/bookings/{B-ref}/        booking: status, cleaner, payments, reschedule / cancel / re-clean (Phase 3),
 *                                     the regular-plan panel (Phase 4); ?checkout=success waits for Stripe's webhook
 *   /account/quotes/ and /{Q-ref}/    quotes; the quote summary with "Accept & confirm" (Stripe Checkout)
 *   /account/quotes/{Q-ref}/edit/    change quote details before payment (stays in the portal)
 *   /account/plans/ and /{P-ref}/     regular plans (shown only to clients with a plan)
 *   /account/profile/                 details, email change (confirmed from the new inbox), password, marketing, delete request
 *   /account/set-password/            the first password (plus name and mobile); every other page waits for it
 *   /account/q/{token}/               "View my quote" link from quote emails (single use; signs in clients without a password)
 *   /account/magic/{token}/           login link; /account/email/{token}/ confirms a new address (both: GET shows a button,
 *                                     POST acts, so link scanners can't use them up)
 *   /account/forgot/, /account/reset/{user}/{key}/, /account/logout/ (POST only)
 * Every form carries a nonce (cc_client_auth when logged out, cc_client_{client_id} when logged in) and actions
 * redirect afterwards (?ccm=code or a one-time ?cc_msg) so a refresh never repeats them.
 */
final class Client_Portal {
	const VIEWS = [
		'bookings' => 'Bookings',
		'quotes'   => 'Quotes',
		'plans'    => 'Regular plans',
		'profile'  => 'Profile',
	];

	public static function init(): void {
		add_action( 'cc_bookings_rewrites', [ self::class, 'rewrites' ] );
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ 'cc_account', 'cc_aref', 'cc_aid', 'cc_atoken' ] ) );
		add_action( 'template_redirect', [ self::class, 'route' ], 1 );
	}

	public static function rewrites(): void {
		add_rewrite_rule( '^account/q/([A-Za-z0-9_-]{20,})/?$', 'index.php?cc_account=quote_link&cc_atoken=$matches[1]', 'top' );
		add_rewrite_rule( '^account/magic/([A-Za-z0-9_-]{20,})/?$', 'index.php?cc_account=magic&cc_atoken=$matches[1]', 'top' );
		add_rewrite_rule( '^account/email/([A-Za-z0-9_-]{20,})/?$', 'index.php?cc_account=email&cc_atoken=$matches[1]', 'top' );
		add_rewrite_rule( '^account/reset/(\d+)/([A-Za-z0-9]{10,})/?$', 'index.php?cc_account=reset&cc_aid=$matches[1]&cc_atoken=$matches[2]', 'top' );
		add_rewrite_rule( '^account/quotes/(Q-\d+)/edit/?$', 'index.php?cc_account=quote_edit&cc_aref=$matches[1]', 'top' );
		add_rewrite_rule( '^account/(bookings|quotes|plans)/([BQP]-\d+)/?$', 'index.php?cc_account=$matches[1]&cc_aref=$matches[2]', 'top' );
		add_rewrite_rule( '^account/(bookings|quotes|plans|profile|forgot|logout|set-password)/?$', 'index.php?cc_account=$matches[1]', 'top' );
		add_rewrite_rule( '^account/?$', 'index.php?cc_account=home', 'top' );
	}

	public static function url( string $view = '', array $args = [] ): string {
		$url = home_url( '/account/' . ( $view && 'home' !== $view ? trim( $view, '/' ) . '/' : '' ) );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/** @return array<string, array{0: string, 1: string, 2?: string}> code => [ type, message, detail ] */
	public static function messages(): array {
		$minutes = max( 1, (int) Client_Accounts::opt( 'magic_minutes' ) );
		return [
			'saved'            => [ 'success', 'Saved.' ],
			'quote_updated'    => [ 'success', 'Your quote has been updated with your new details.' ],
			'logged_out'       => [ 'success', 'You\'ve logged out.' ],
			'sent'             => [ 'success', 'Check your email', 'If that address belongs to a client account, we\'ve sent it a link to choose a new password.' ],
			'link_sent'        => [ 'success', 'Check your email', sprintf( 'If that address belongs to a client account, we\'ve sent it a login link. It works once, for %d minutes. Look in your spam folder if it isn\'t there in a minute or two.', $minutes ) ],
			'reset_done'       => [ 'success', 'Your new password is set and you\'re logged in. Any other devices have been logged out.' ],
			'password'         => [ 'success', 'Password changed. Any other devices have been logged out.' ],
			'welcome'          => [ 'success', 'Your password is set — welcome to your client area.' ],
			'email_sent'       => [ 'success', 'Check your new inbox', 'We\'ve sent a link to confirm the new address. Your email address won\'t change until you click it.' ],
			'email_changed'    => [ 'success', 'Your email address has been changed. Use it to log in from now on.' ],
			'delete_requested' => [ 'success', 'We\'ve got your request', 'We\'ll be in touch within one month to confirm what we delete and what we must keep for our records.' ],
			'link_used'        => [ 'warning', 'That login link has expired or has already been used. You can ask for a new one below.' ],
			'quote_link_used'  => [ 'warning', 'That link has expired or has already been used', 'For your security, quote links work once. Log in below, or use "No password?" and we\'ll email you a login link.' ],
			'signed_out'       => [ 'warning', 'Please log in again.' ],
			'limit'            => [ 'warning', 'Too many requests. Please wait a while and try again.' ],
			'expired'          => [ 'warning', 'The page timed out. Please try again.' ],
		];
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	public static function is_post(): bool {
		return 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
	}

	public static function redirect( string $url ): void {
		wp_safe_redirect( $url, 303 );
		exit;
	}

	public static function nonce_action( array $client ): string {
		return 'cc_client_' . (int) $client['id'];
	}

	public static function check_nonce( array $client ): bool {
		return (bool) wp_verify_nonce( (string) wp_unslash( $_POST['_ccnonce'] ?? '' ), self::nonce_action( $client ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	public static function nonce_field( string $action ): void {
		wp_nonce_field( $action, '_ccnonce', false );
	}

	private static function auth_nonce_ok(): bool {
		return (bool) wp_verify_nonce( (string) wp_unslash( $_POST['_ccnonce'] ?? '' ), 'cc_client_auth' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	public static function assets(): void {
		Cleaner_Portal::assets();
		wp_enqueue_style( 'cc-bookings-client', CC_BOOKINGS_URL . 'assets/client.css', [ 'cc-bookings-cleaners' ], Frontend::asset_version( 'assets/client.css' ) );
	}

	public static function notice(): ?array {
		$code = sanitize_key( (string) ( $_GET['ccm'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		return self::messages()[ $code ] ?? null;
	}

	/** Redirect with a one-time message (shared with the booking page's Phase 3 panel, which shows it in #cc-manage). */
	private static function flash( string $url, string $type, string $message, array $extra = [] ): void {
		$key = strtolower( wp_generate_password( 12, false ) );
		set_transient( 'cc_manage_msg_' . $key, [ 'type' => $type, 'message' => $message ] + $extra, 10 * MINUTE_IN_SECONDS );
		self::redirect( add_query_arg( 'cc_msg', $key, $url ) );
	}

	public static function flash_message(): ?array {
		$key = sanitize_key( wp_unslash( (string) ( $_GET['cc_msg'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$msg = $key ? get_transient( 'cc_manage_msg_' . $key ) : null;
		return is_array( $msg ) ? $msg : null;
	}

	public static function message( string $title, string $text, int $status = 200, bool $logout = false, array $extra = [] ): void {
		Frontend::render( 'client-message', $extra + [ 'title' => $title, 'text' => $text, 'logout' => $logout ], $title, $status );
	}

	private static function post(): array {
		return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- every caller checks the nonce first.
	}

	/** Same-area path to return to after logging in. */
	public static function next_path(): string {
		$next = (string) wp_unslash( $_REQUEST['next'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		return self::safe_next( $next );
	}

	private static function safe_next( string $next ): string {
		return preg_match( '#^/account/((bookings|quotes|plans)/([BQP]-\d+/)?|quotes/(Q-\d+)/edit/|profile/)$#', $next ) ? $next : '';
	}

	private static function next_url(): string {
		$next = self::next_path();
		return $next ? home_url( $next ) : self::url();
	}

	/* ---------------------------------------------------------------------
	 * Router
	 * ------------------------------------------------------------------ */

	public static function route(): void {
		$view = sanitize_key( (string) get_query_var( 'cc_account' ) );
		if ( '' === $view ) {
			return;
		}
		Frontend::private_headers();
		header( 'X-Frame-Options: DENY' );
		$token = (string) get_query_var( 'cc_atoken' );
		$ref   = strtoupper( sanitize_text_field( (string) get_query_var( 'cc_aref' ) ) );

		switch ( $view ) {
			case 'quote_link':
				self::quote_link( $token );
				break;
			case 'magic':
				self::magic( $token );
				break;
			case 'email':
				self::confirm_email( $token );
				break;
			case 'reset':
				self::reset( (int) get_query_var( 'cc_aid' ), $token );
				break;
			case 'forgot':
				self::forgot();
				break;
			case 'logout':
				self::logout();
				break;
		}

		$client = Client_Accounts::current();
		if ( ! $client ) {
			if ( is_user_logged_in() ) {
				self::message( 'This is the client area', 'You\'re logged in to the website with an account that isn\'t a client account. Log out first to see your quotes and bookings.', 200, true );
			}
			self::login();
		}
		if ( ! Client_Auth::can_log_in( wp_get_current_user() ) ) {
			wp_logout();
			self::redirect( self::url( '', [ 'ccm' => 'signed_out' ] ) );
		}
		if ( ! $client['password_set'] ) {
			self::setup( $client );
		}
		if ( 'set-password' === $view ) {
			self::redirect( self::next_url() );
		}

		if ( 'quote_edit' === $view ) {
			self::quote_edit( $client, $ref );
		}

		if ( $ref ) {
			if ( 'quotes' === $view ) {
				self::quote( $client, $ref );
			} elseif ( 'bookings' === $view ) {
				self::booking( $client, $ref );
			} elseif ( 'plans' === $view ) {
				self::plan( $client, $ref );
			}
		}
		self::page( $client, isset( self::VIEWS[ $view ] ) ? $view : 'bookings' );
	}

	/* ---------------------------------------------------------------------
	 * Logged out: login, emailed links, password reset
	 * ------------------------------------------------------------------ */

	private static function auth_render( string $mode, array $vars = [], int $status = 200 ): void {
		$titles = [ 'login' => 'Log in', 'forgot' => 'Reset your password', 'reset' => 'Choose a new password', 'magic' => 'Log in', 'quote_link' => 'Your quote is ready', 'email' => 'Confirm your new email' ];
		Frontend::render( 'client-login', $vars + [ 'mode' => $mode, 'error' => '', 'email' => '', 'notice' => self::notice(), 'action' => '', 'method' => 'password', 'next' => '' ], $titles[ $mode ] ?? 'Log in', $status );
	}

	private static function login(): void {
		$error  = '';
		$email  = '';
		$method = 'link' === ( $_GET['method'] ?? '' ) ? 'link' : 'password'; // phpcs:ignore WordPress.Security.NonceVerification
		$key    = sanitize_key( (string) ( $_GET['prefill'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $key ) {
			$email = (string) get_transient( 'cc_cp_prefill_' . $key );
		}
		$path = strtok( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$next = self::next_path() ?: self::safe_next( (string) $path );
		if ( self::is_post() ) {
			$post   = self::post();
			$email  = sanitize_email( (string) ( $post['email'] ?? '' ) );
			$method = 'magic' === ( $post['cc_do'] ?? '' ) ? 'link' : 'password';
			if ( ! self::auth_nonce_ok() ) {
				$error = self::messages()['expired'][1];
			} elseif ( 'link' === $method ) {
				self::redirect( self::url( '', [ 'method' => 'link', 'ccm' => Client_Auth::request_magic( $email, $next ) ? 'link_sent' : 'limit' ] ) );
			} else {
				$user = Client_Auth::login( $email, (string) ( $post['password'] ?? '' ), ! empty( $post['remember'] ) );
				if ( ! is_wp_error( $user ) ) {
					self::redirect( $next ? home_url( $next ) : self::url() );
				}
				$error = $user->get_error_message();
			}
		}
		self::auth_render( 'login', [ 'error' => $error, 'email' => $email, 'method' => $method, 'next' => $next ], $error ? 401 : 200 );
	}

	/** "View my quote" from a quote email. */
	private static function quote_link( string $token ): void {
		if ( ! Security::rate_limit( 'client_link', 30, HOUR_IN_SECONDS ) ) {
			self::message( 'Too many requests', 'Please wait a while and try again.', 429 );
		}
		$link = Client_Auth::find_link( $token, 'quote' );
		if ( ! $link ) {
			self::redirect( Client_Accounts::current() ? self::url( 'quotes' ) : self::url( '', [ 'ccm' => 'quote_link_used' ] ) );
		}
		$client  = Client_Accounts::get( $link['client_id'] );
		$quote   = Quotes::get( $link['quote_id'] );
		$next    = $quote && Client_Accounts::owns( $client, $quote ) ? '/account/quotes/' . $quote['ref'] . '/' : '/account/quotes/';
		$current = Client_Accounts::current();
		if ( $current ) {
			if ( (int) $current['id'] === (int) $client['id'] ) {
				self::redirect( home_url( $next ) );
			}
			self::message( 'You\'re logged in as someone else', 'This link is for a different client account. Log out first, then open the link again.', 200, true );
		}
		if ( is_user_logged_in() ) {
			self::message( 'This is the client area', 'You\'re logged in to the website with an account that isn\'t a client account. Log out first, then open the link again.', 200, true );
		}
		if ( $client['password_set'] ) {
			$key = strtolower( wp_generate_password( 12, false ) );
			set_transient( 'cc_cp_prefill_' . $key, (string) $client['email'], 15 * MINUTE_IN_SECONDS );
			self::redirect( add_query_arg( [ 'next' => $next, 'prefill' => $key ], self::url() ) );
		}
		// No password yet: this link signs them in once, then they choose a password before seeing anything.
		$action = home_url( '/account/q/' . $token . '/' );
		if ( self::is_post() ) {
			$user = get_userdata( (int) $client['user_id'] );
			if ( self::auth_nonce_ok() && $user && Client_Auth::can_log_in( $user ) && Client_Auth::use_link( $link ) ) {
				Client_Auth::log_in( $user, false, 'quote link' );
				self::redirect( self::url( 'set-password', [ 'next' => $next ] ) );
			}
			self::auth_render( 'quote_link', [ 'error' => self::messages()['expired'][1], 'action' => $action, 'first_name' => Client_Accounts::first_name( $client ) ] );
		}
		self::auth_render( 'quote_link', [ 'action' => $action, 'first_name' => Client_Accounts::first_name( $client ) ] );
	}

	private static function magic( string $token ): void {
		$link = Client_Auth::find_link( $token, 'login' );
		if ( ! $link ) {
			self::redirect( self::url( '', [ 'method' => 'link', 'ccm' => 'link_used' ] ) );
		}
		$action = Client_Auth::magic_url( $token );
		if ( self::is_post() ) {
			$client = Client_Accounts::get( $link['client_id'] );
			$user   = $client && $client['user_id'] ? get_userdata( (int) $client['user_id'] ) : null;
			if ( self::auth_nonce_ok() && $user && Client_Auth::can_log_in( $user ) && Client_Auth::use_link( $link ) ) {
				Client_Auth::log_in( $user, false, 'login link' );
				$next = self::safe_next( (string) ( $link['data']['next'] ?? '' ) );
				self::redirect( $next ? home_url( $next ) : self::url() );
			}
			self::auth_render( 'magic', [ 'error' => self::messages()['expired'][1], 'action' => $action ] );
		}
		self::auth_render( 'magic', [ 'action' => $action ] );
	}

	private static function confirm_email( string $token ): void {
		$link = Client_Auth::find_link( $token, 'email' );
		if ( ! $link ) {
			self::message( 'This link has expired', 'This confirmation link has expired or has already been used. Log in and change your email address again if you still need to.', 410 );
		}
		$action = home_url( '/account/email/' . $token . '/' );
		if ( self::is_post() ) {
			$client = Client_Accounts::get( $link['client_id'] );
			if ( ! self::auth_nonce_ok() || ! Client_Auth::use_link( $link ) ) {
				self::auth_render( 'email', [ 'error' => self::messages()['expired'][1], 'action' => $action ] );
			}
			$result = self::change_email( $client, (string) ( $link['data']['email'] ?? '' ) );
			if ( is_wp_error( $result ) ) {
				self::message( 'We couldn\'t change your email', $result->get_error_message(), 409 );
			}
			$current = Client_Accounts::current();
			if ( $current && (int) $current['id'] === (int) $client['id'] ) {
				self::redirect( self::url( 'profile', [ 'ccm' => 'email_changed' ] ) );
			}
			self::message( 'Email address changed', 'Your email address has been changed. Use it to log in from now on.' );
		}
		self::auth_render( 'email', [ 'action' => $action ] );
	}

	private static function forgot(): void {
		if ( Client_Accounts::current() ) {
			self::redirect( self::url( 'profile' ) );
		}
		$error = '';
		if ( self::is_post() ) {
			if ( ! self::auth_nonce_ok() ) {
				$error = self::messages()['expired'][1];
			} else {
				$ok = Client_Auth::request_reset( sanitize_email( (string) ( self::post()['email'] ?? '' ) ) );
				self::redirect( self::url( '', [ 'ccm' => $ok ? 'sent' : 'limit' ] ) );
			}
		}
		self::auth_render( 'forgot', [ 'error' => $error ] );
	}

	private static function reset( int $user_id, string $key ): void {
		$user = Client_Auth::check_reset( $user_id, $key );
		if ( ! $user ) {
			self::auth_render( 'forgot', [ 'error' => 'That reset link has expired or has already been used. Enter your email address for a new one.' ], 410 );
		}
		$error = '';
		if ( self::is_post() ) {
			$post = self::post();
			if ( ! self::auth_nonce_ok() ) {
				$error = self::messages()['expired'][1];
			} else {
				$result = Client_Auth::reset( $user_id, $key, (string) ( $post['password'] ?? '' ), (string) ( $post['password_confirm'] ?? '' ) );
				if ( ! is_wp_error( $result ) ) {
					self::redirect( self::url( '', [ 'ccm' => 'reset_done' ] ) );
				}
				$error = $result->get_error_message();
			}
		}
		self::auth_render( 'reset', [ 'error' => $error, 'action' => home_url( '/account/reset/' . $user_id . '/' . $key . '/' ) ] );
	}

	private static function logout(): void {
		$client = Client_Accounts::current();
		if ( $client && self::is_post() && self::check_nonce( $client ) ) {
			Events::log( 'client', (int) $client['id'], 'logout', 'Logged out', [], 'client:' . $client['id'] );
			wp_logout();
			self::redirect( self::url( '', [ 'ccm' => 'logged_out' ] ) );
		}
		self::redirect( self::url() );
	}

	/* ---------------------------------------------------------------------
	 * First password
	 * ------------------------------------------------------------------ */

	private static function setup( array $client ): void {
		$error = '';
		$input = [];
		if ( self::is_post() && 'setup' === ( $_POST['cc_do'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- checked below.
			$post  = self::post();
			$name  = mb_substr( sanitize_text_field( (string) ( $post['name'] ?? '' ) ), 0, 200 );
			$phone = mb_substr( sanitize_text_field( (string) ( $post['phone'] ?? '' ) ), 0, 50 );
			$input = [ 'name' => $name, 'phone' => $phone ];
			$user  = wp_get_current_user();
			if ( ! self::check_nonce( $client ) ) {
				$error = self::messages()['expired'][1];
			} elseif ( mb_strlen( $name ) < 2 ) {
				$error = 'Please enter your name.';
			} elseif ( strlen( preg_replace( '/\D/', '', $phone ) ) < 10 ) {
				$error = 'Please enter your mobile number.';
			} elseif ( $problem = Cleaner_Auth::password_problem( (string) ( $post['password'] ?? '' ), (string) ( $post['password_confirm'] ?? '' ), Client_Auth::avoid_words( $user, [ 'name' => $name ] ) ) ) {
				$error = $problem;
			} else {
				Client_Accounts::update( (int) $client['id'], [ 'name' => $name, 'phone' => $phone ] );
				wp_update_user( [ 'ID' => $user->ID, 'display_name' => Notify::first_name( $name ), 'first_name' => Notify::first_name( $name ) ] );
				Client_Auth::set_password( $user, (string) $post['password'], 'first password set' );
				self::redirect( add_query_arg( 'ccm', 'welcome', self::next_url() ) );
			}
		}
		Frontend::render( 'client-setup', [ 'client' => $client, 'error' => $error, 'input' => $input, 'next' => self::next_path() ], 'Choose your password' );
	}

	/* ---------------------------------------------------------------------
	 * Logged in: quote, booking and plan pages
	 * ------------------------------------------------------------------ */

	private static function quote( array $client, string $ref ): void {
		$quote = Quotes::by_ref( $ref );
		if ( ! Client_Accounts::owns( $client, $quote ) ) {
			self::message( 'Quote not found', 'This quote isn\'t in your account.', 404 );
		}
		$url = self::url( 'quotes/' . $quote['ref'] );
		if ( self::is_post() ) {
			if ( ! self::check_nonce( $client ) ) {
				self::flash( $url, 'error', self::messages()['expired'][1] );
			}
			self::quote_action( $client, $quote, $url, sanitize_key( (string) ( $_POST['cc_do'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
		}
		if ( 'cancelled' === ( $_GET['checkout'] ?? '' ) && $quote['hold_expires_at'] && ! $quote['booking_id'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			Checkout::expire_hold( $quote['id'], $quote['stripe_session_id'], true );
		}
		if ( 'success' === ( $_GET['checkout'] ?? '' ) && ! $quote['booking_id'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			Checkout::sync_paid_session( $quote );
		}
		if ( ! Quotes::is_expired( $quote ) ) {
			Quotes::mark_viewed( $quote );
		} elseif ( 'expired' !== $quote['status'] ) {
			Quotes::expire( $quote['id'] );
		}
		$quote = Quotes::get( (int) $quote['id'] );
		$notice = self::notice();
		$flash  = self::flash_message() ?: ( $notice ? [ 'type' => $notice[0], 'message' => $notice[1] ] : null );
		Frontend::render( 'client-quote', [ 'quote' => $quote, 'client' => $client, 'flash' => $flash ], 'Quote ' . $quote['ref'] );
	}

	public static function quote_edit_url( string $ref ): string {
		return self::url( 'quotes/' . strtoupper( sanitize_text_field( $ref ) ) . '/edit' );
	}

	private static function quote_edit( array $client, string $ref ): void {
		$quote = $ref ? Quotes::by_ref( $ref ) : null;
		if ( ! $quote || ! Client_Accounts::owns( $client, $quote ) ) {
			self::message( 'Quote not found', 'This quote isn\'t in your account.', 404 );
		}
		if ( $quote['booking_id'] || in_array( $quote['status'], [ 'accepted', 'cancelled', 'superseded' ], true ) ) {
			self::flash( self::url( 'quotes/' . $quote['ref'] ), 'error', 'This quote can\'t be changed any more.' );
		}
		$token = Tokens::issue( 'quotes', (int) $quote['id'] );
		Frontend::render(
			'client-quote-edit',
			[
				'quote'  => $quote,
				'client' => $client,
				'form'   => Form::render(
					[
						'portal'         => true,
						'replaces_ref'   => $quote['ref'],
						'replaces_token' => $token,
						'return_base'    => self::url( 'quotes/' ),
					]
				),
			],
			'Change quote ' . $quote['ref']
		);
	}

	private static function quote_action( array $client, array $quote, string $url, string $do ): void {
		$post = self::post();
		switch ( $do ) {
			case 'accept':
				if ( ! Security::rate_limit( 'accept', 20, HOUR_IN_SECONDS ) ) {
					self::flash( $url, 'error', 'Too many attempts. Please call us.' );
				}
				$result = Checkout::start( $quote, [
					'terms'       => ! empty( $post['terms'] ),
					'early_start' => ! empty( $post['early_start'] ),
					'recurring'   => ! empty( $post['recurring'] ),
					'token'       => '',
					'revision'    => (string) ( $post['revision'] ?? '' ),
				] );
				if ( is_wp_error( $result ) ) {
					$data = (array) $result->get_error_data();
					self::flash( $url, 'error', $result->get_error_message(), [ 'change' => ! empty( $data['change'] ) ] );
				}
				if ( 'stripe' === ( $result['mode'] ?? '' ) && ! empty( $result['url'] ) ) {
					wp_redirect( (string) $result['url'], 303 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Stripe Checkout
					exit;
				}
				self::flash( $url, 'success', (string) ( $result['message'] ?? 'Thank you — we\'ll be in touch shortly.' ) );
				break;

			case 'requote':
				if ( $quote['booking_id'] || in_array( $quote['status'], [ 'accepted', 'cancelled', 'superseded' ], true ) ) {
					self::flash( $url, 'error', 'This quote can\'t be changed any more.' );
				}
				self::redirect( self::quote_edit_url( $quote['ref'] ) );
				break;

			case 'question':
				if ( ! Security::rate_limit( 'question', 5, HOUR_IN_SECONDS ) ) {
					self::flash( $url, 'error', 'You\'ve sent several messages already. Please call us.' );
				}
				$message = mb_substr( sanitize_textarea_field( (string) ( $post['message'] ?? '' ) ), 0, 2000 );
				if ( mb_strlen( $message ) < 3 ) {
					self::flash( $url, 'error', 'Please type your question.' );
				}
				Events::log( 'quote', (int) $quote['id'], 'question', $message, [], 'client:' . $client['id'] );
				do_action( 'cc_quote_question', (int) $quote['id'], $message );
				self::flash( $url, 'success', 'Thanks — we\'ve got your question and will reply by email or phone shortly.' );
				break;
		}
		self::flash( $url, 'error', 'Something went wrong. Please try again.' );
	}

	private static function booking( array $client, string $ref ): void {
		if ( 'success' === ( $_GET['checkout'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$quote = Quotes::by_ref( 'Q-' . substr( $ref, 2 ) );
			if ( $quote && Client_Accounts::owns( $client, $quote ) ) {
				Checkout::sync_paid_session( $quote );
			}
		}
		$booking = Bookings::by_ref( $ref );
		if ( ! Client_Accounts::owns( $client, $booking ) ) {
			// Back from Stripe before the booking exists (webhook delayed and sync could not confirm yet).
			$quote = 'success' === ( $_GET['checkout'] ?? '' ) ? Quotes::by_ref( 'Q-' . substr( $ref, 2 ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
			if ( Client_Accounts::owns( $client, $quote ) && ! $booking ) {
				self::message( 'Payment received — confirming your booking…', 'This usually takes a few seconds. This page refreshes by itself, and we\'ll email your confirmation.', 200, false, [ 'refresh' => 5, 'back' => self::url( 'quotes/' . $quote['ref'] ) ] );
			}
			self::message( 'Booking not found', 'This booking isn\'t in your account.', 404 );
		}
		$url = self::url( 'bookings/' . $booking['ref'] );
		if ( self::is_post() && ! empty( $_POST['cc_manage'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- the handlers check the booking nonce.
			$action = sanitize_key( wp_unslash( (string) $_POST['cc_manage'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			str_starts_with( $action, 'plan_' ) ? Plan_Manage::handle_action( $booking, $url ) : Manage::handle_action( $booking, $url );
		}
		Frontend::render( 'client-booking', [ 'booking' => Bookings::get( (int) $booking['id'] ), 'client' => $client ], 'Booking ' . $booking['ref'] );
	}

	private static function plan( array $client, string $ref ): void {
		$plan = Plans::by_ref( $ref );
		if ( ! $plan || ! Client_Accounts::owns( $client, $plan ) ) {
			self::message( 'Plan not found', 'This regular plan isn\'t in your account.', 404 );
		}
		$visit = Plans::upcoming( (int) $plan['id'] )[0] ?? Bookings::get( (int) $plan['first_booking_id'] );
		$url   = self::url( 'plans/' . $plan['ref'] );
		if ( $visit && self::is_post() && str_starts_with( sanitize_key( wp_unslash( (string) ( $_POST['cc_manage'] ?? '' ) ) ), 'plan_' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- the handler checks the booking nonce.
			Plan_Manage::handle_action( $visit, $url );
		}
		self::render_page( $client, 'plan', [ 'plan' => Plans::get( (int) $plan['id'] ), 'visit' => $visit, 'visits' => Plans::visits( (int) $plan['id'] ) ] );
	}

	/* ---------------------------------------------------------------------
	 * Logged in: tabs
	 * ------------------------------------------------------------------ */

	private static function page( array $client, string $view ): void {
		$error = '';
		$input = [];
		if ( self::is_post() ) {
			$post = self::post();
			if ( ! self::check_nonce( $client ) ) {
				$error = self::messages()['expired'][1];
			} else {
				[ $result, $code ] = self::handle( $client, $view, sanitize_key( (string) ( $post['cc_do'] ?? '' ) ), $post );
				if ( true === $result ) {
					self::redirect( self::url( $view, $code ? [ 'ccm' => $code ] : [] ) );
				}
				$error = $result->get_error_message();
			}
			$input = array_diff_key( $post, array_flip( [ 'password', 'password_confirm', 'current_password', '_ccnonce' ] ) );
		}
		self::render_page( $client, $view, self::data( $client, $view ), $error, $input );
	}

	private static function render_page( array $client, string $view, array $data, string $error = '', array $input = [] ): void {
		$client = Client_Accounts::get( (int) $client['id'] );
		$titles = self::VIEWS + [ 'plan' => 'Regular plan' ];
		Frontend::render( 'client-portal', [
			'client' => $client,
			'view'   => $view,
			'notice' => $error ? [ 'warning', $error ] : self::notice(),
			'input'  => $input,
			'error'  => $error,
			'data'   => $data,
		], $titles[ $view ] ?? 'My account' );
	}

	/**
	 * A tab's form post.
	 *
	 * @return array{0: true|\WP_Error, 1: string} [ result, message code ]
	 */
	private static function handle( array $client, string $view, string $do, array $post ): array {
		$id = (int) $client['id'];
		switch ( $view . ':' . $do ) {
			case 'profile:details':
				$data = [
					'name'      => mb_substr( sanitize_text_field( (string) ( $post['name'] ?? '' ) ), 0, 200 ),
					'phone'     => mb_substr( sanitize_text_field( (string) ( $post['phone'] ?? '' ) ), 0, 50 ),
					'address_1' => mb_substr( sanitize_text_field( (string) ( $post['address_1'] ?? '' ) ), 0, 200 ),
					'address_2' => mb_substr( sanitize_text_field( (string) ( $post['address_2'] ?? '' ) ), 0, 200 ),
					'town'      => mb_substr( sanitize_text_field( (string) ( $post['town'] ?? '' ) ), 0, 100 ),
					'postcode'  => mb_substr( strtoupper( sanitize_text_field( (string) ( $post['postcode'] ?? '' ) ) ), 0, 10 ),
				];
				if ( mb_strlen( $data['name'] ) < 2 ) {
					return [ new \WP_Error( 'name', 'Please enter your name.' ), '' ];
				}
				if ( strlen( preg_replace( '/\D/', '', $data['phone'] ) ) < 10 ) {
					return [ new \WP_Error( 'phone', 'Please enter your mobile number.' ), '' ];
				}
				Client_Accounts::update( $id, $data );
				wp_update_user( [ 'ID' => (int) $client['user_id'], 'display_name' => Notify::first_name( $data['name'] ), 'first_name' => Notify::first_name( $data['name'] ) ] );
				Events::log( 'client', $id, 'profile_saved', 'Updated their details', [], 'client:' . $id );
				return [ true, 'saved' ];

			case 'profile:marketing':
				$on = empty( $post['marketing'] ) ? 0 : 1;
				if ( $on !== (int) $client['marketing_opt_in'] ) {
					global $wpdb;
					Client_Accounts::update( $id, [ 'marketing_opt_in' => $on ] );
					foreach ( Client_Accounts::LINKED as $name ) {
						$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( $name ) . ' SET marketing_opt_in = %d WHERE client_id = %d', $on, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
					}
					Events::log( 'client', $id, 'marketing', $on ? 'Opted in to marketing emails' : 'Opted out of marketing emails', [], 'client:' . $id );
				}
				return [ true, 'saved' ];

			case 'profile:email':
				$new = strtolower( sanitize_email( (string) ( $post['new_email'] ?? '' ) ) );
				if ( ! is_email( $new ) ) {
					return [ new \WP_Error( 'email', 'Please enter a valid email address.' ), '' ];
				}
				if ( $new === strtolower( (string) $client['email'] ) ) {
					return [ new \WP_Error( 'email', 'That\'s already your email address.' ), '' ];
				}
				if ( ! Security::rate_limit( 'client_email', 5, HOUR_IN_SECONDS ) ) {
					return [ new \WP_Error( 'limit', self::messages()['limit'][1] ), '' ];
				}
				// The reply is the same whether or not the address is free, so it can't be used to look accounts up.
				if ( ! get_user_by( 'email', $new ) && ! Client_Accounts::by_email( $new ) ) {
					Client_Auth::revoke_links( $id, 'email' );
					$hours = max( 1, (int) Client_Accounts::opt( 'email_hours' ) );
					$raw   = Client_Auth::issue_link( $id, 'email', $hours * HOUR_IN_SECONDS, 0, [ 'email' => $new ] );
					Notify::send( 'client_email_verify', $new, Client_Accounts::vars( $client ) + [ 'verify_url' => home_url( '/account/email/' . $raw . '/' ), 'hours' => $hours, 'lasts' => Client_Auth::lasts( $hours * 60 ) ], [ 'client', $id ] );
				}
				Events::log( 'client', $id, 'email_change_requested', 'Asked to change their email address (confirmation sent to the new address)', [], 'client:' . $id );
				return [ true, 'email_sent' ];

			case 'profile:password':
				return [ Client_Auth::change_password( wp_get_current_user(), (string) ( $post['current_password'] ?? '' ), (string) ( $post['password'] ?? '' ), (string) ( $post['password_confirm'] ?? '' ) ), 'password' ];

			case 'profile:delete':
				if ( empty( $post['confirm'] ) ) {
					return [ new \WP_Error( 'confirm', 'Please tick the box to confirm.' ), '' ];
				}
				Client_Accounts::request_delete( $client, mb_substr( sanitize_textarea_field( (string) ( $post['note'] ?? '' ) ), 0, 1000 ) );
				return [ true, 'delete_requested' ];
		}
		return [ new \WP_Error( 'unknown', 'Something went wrong. Please try again.' ), '' ];
	}

	/** Switch the account (and open quotes, upcoming bookings and plans) to a confirmed new address. @return true|\WP_Error */
	private static function change_email( array $client, string $new ) {
		global $wpdb;
		$new = strtolower( sanitize_email( $new ) );
		$old = (string) $client['email'];
		if ( ! is_email( $new ) || get_user_by( 'email', $new ) || Client_Accounts::by_email( $new ) ) {
			return new \WP_Error( 'taken', 'That email address can\'t be used for your account. Please call us and we\'ll sort it out.' );
		}
		if ( $client['user_id'] ) {
			$result = wp_update_user( [ 'ID' => (int) $client['user_id'], 'user_email' => $new ] );
			if ( is_wp_error( $result ) ) {
				return new \WP_Error( 'taken', 'That email address can\'t be used for your account. Please call us and we\'ll sort it out.' );
			}
		}
		Client_Accounts::update( (int) $client['id'], [ 'email' => $new ] );
		$id = (int) $client['id'];
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'quotes' ) . " SET email = %s WHERE client_id = %d AND booking_id = 0 AND status IN ('draft','sent','viewed')", $new, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'bookings' ) . " SET email = %s WHERE client_id = %d AND (start_at IS NULL OR start_at > %s) AND status NOT IN ('cancelled','completed')", $new, $id, Db::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( Recurring::TABLE ) . " SET email = %s WHERE client_id = %d AND status <> 'cancelled'", $new, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		Events::log( 'client', $id, 'email_changed', sprintf( 'Email address changed from %s to %s (confirmed from the new inbox)', $old, $new ), [], 'client:' . $id );
		Notify::send( 'client_email_changed', $old, Client_Accounts::vars( $client ) + [ 'new_email' => $new ], [ 'client', $id ] );
		return true;
	}

	/** What each tab shows. */
	private static function data( array $client, string $view ): array {
		switch ( $view ) {
			case 'bookings':
				$upcoming = [];
				$past     = [];
				$now      = time();
				foreach ( Client_Accounts::bookings( $client ) as $booking ) {
					$start = Db::timestamp( $booking['start_at'] );
					if ( ! in_array( $booking['status'], [ 'cancelled', 'completed', 'skipped' ], true ) && ( ! $start || $start > $now - 6 * HOUR_IN_SECONDS ) ) {
						$upcoming[] = $booking;
					} else {
						$past[] = $booking;
					}
				}
				usort( $upcoming, fn( $a, $b ) => [ (string) $a['start_at'], $a['id'] ] <=> [ (string) $b['start_at'], $b['id'] ] );
				return [ 'upcoming' => $upcoming, 'past' => $past, 'open_quotes' => array_values( array_filter( Client_Accounts::quotes( $client ), fn( $q ) => Quotes::is_payable( $q ) ) ) ];
			case 'quotes':
				return [ 'quotes' => array_values( array_filter( Client_Accounts::quotes( $client ), fn( $q ) => 'superseded' !== $q['status'] ) ) ];
			case 'plans':
				return [ 'plans' => Client_Accounts::plans( $client ) ];
			case 'profile':
				$addresses = [];
				foreach ( array_merge( Client_Accounts::bookings( $client ), Client_Accounts::quotes( $client ) ) as $row ) {
					$line = implode( ', ', array_filter( [ $row['address_1'], $row['address_2'], $row['town'], $row['postcode'] ] ) );
					if ( $line ) {
						$addresses[ strtolower( $line ) ] = $line;
					}
				}
				return [ 'addresses' => array_values( $addresses ) ];
		}
		return [];
	}

	/** Customer-facing quote status for lists. */
	public static function quote_status( array $quote ): array {
		if ( $quote['booking_id'] ) {
			return [ 'booked', 'Booked' ];
		}
		if ( Quotes::is_expired( $quote ) ) {
			return [ 'expired', 'Expired' ];
		}
		$map = [
			'draft'      => [ 'pending', 'Being priced' ],
			'sent'       => [ 'ready', 'Ready to book' ],
			'viewed'     => [ 'ready', 'Ready to book' ],
			'accepted'   => [ 'booked', 'Booked' ],
			'superseded' => [ 'closed', 'Replaced' ],
			'cancelled'  => [ 'closed', 'Cancelled' ],
		];
		return $map[ $quote['status'] ] ?? [ 'closed', Status::label( 'quote', (string) $quote['status'] ) ];
	}
}

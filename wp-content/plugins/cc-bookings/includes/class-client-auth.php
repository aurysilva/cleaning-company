<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Client sign-in: email + password, single-use magic login links (clients.magic_minutes, default 15), password resets
 * (WordPress reset keys, clients.reset_minutes) and the emailed links kept in wp_cc_client_links (SHA-256 hashes only):
 *   quote   "View my quote" in quote emails: signs a client without a password in (then they must set one); single use,
 *           clients.quote_link_days (default 7)
 *   login   magic login link
 *   email   confirms a new email address (clients.email_hours)
 * Requests are rate-limited per IP and per email, every message is generic (no account enumeration), and a password
 * change or reset ends every other session. Deactivated clients can't sign in anywhere, wp-login.php included.
 */
final class Client_Auth {
	const GENERIC_ERROR = 'That email address and password don\'t match an account.';

	public static function init(): void {
		add_filter( 'authenticate', [ self::class, 'block_inactive' ], 99, 1 );
		add_action( 'after_password_reset', [ self::class, 'password_was_reset' ] );
	}

	public static function can_log_in( \WP_User $user ): bool {
		$client = Client_Accounts::by_user( $user->ID );
		return $client && 'active' === $client['status'] && Client_Accounts::is_client_user( $user );
	}

	/** `authenticate`: covers wp-login.php and XML-RPC as well as the client area. */
	public static function block_inactive( $user ) {
		if ( $user instanceof \WP_User && Client_Accounts::is_client_user( $user ) && ! self::can_log_in( $user ) ) {
			return new \WP_Error( 'cc_client_blocked', self::GENERIC_ERROR );
		}
		return $user;
	}

	/** Per-account counter (Security::rate_limit is per IP). True while under the limit. */
	private static function under( string $bucket, int $max, int $window, bool $count = true ): bool {
		$key = 'cc_rl_' . md5( 'client|' . $bucket );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		if ( $count ) {
			set_transient( $key, $n + 1, $window );
		}
		return true;
	}

	private static function clear( string $bucket ): void {
		delete_transient( 'cc_rl_' . md5( 'client|' . $bucket ) );
	}

	/* ---------------------------------------------------------------------
	 * Password login
	 * ------------------------------------------------------------------ */

	/** @return \WP_User|\WP_Error */
	public static function login( string $email, string $password, bool $remember = false ) {
		$email  = strtolower( sanitize_email( $email ) );
		$bucket = 'login:' . $email;
		if ( ! Security::rate_limit( 'client_login', 20, 15 * MINUTE_IN_SECONDS ) || ! self::under( $bucket, 5, 15 * MINUTE_IN_SECONDS, false ) ) {
			return new \WP_Error( 'limit', 'Too many attempts. Please wait 15 minutes, or use "No password?" to get a login link.' );
		}
		$user = '' !== $email && '' !== $password ? wp_authenticate( $email, $password ) : new \WP_Error( 'empty' );
		if ( is_wp_error( $user ) || ! Client_Accounts::is_client_user( $user ) || ! self::can_log_in( $user ) ) {
			self::under( $bucket, 5, 15 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'login', self::GENERIC_ERROR );
		}
		self::clear( $bucket );
		self::log_in( $user, $remember, 'password' );
		return $user;
	}

	public static function log_in( \WP_User $user, bool $remember = false, string $method = 'password' ): void {
		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		Client_Accounts::forget_current();
		$client = Client_Accounts::by_user( $user->ID );
		if ( $client ) {
			Client_Accounts::update( (int) $client['id'], [ 'last_login_at' => Db::now() ] );
			Client_Accounts::claim( $client );
			Events::log( 'client', (int) $client['id'], 'login', 'Logged in (' . $method . ')', [], 'client:' . $client['id'] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Emailed links
	 * ------------------------------------------------------------------ */

	/** A new single-use link; returns the raw token for the URL. */
	public static function issue_link( int $client_id, string $purpose, int $seconds, int $quote_id = 0, array $data = [] ): string {
		[ $raw, $hash ] = Tokens::generate();
		Db::insert( Client_Accounts::LINKS, [
			'client_id'  => $client_id,
			'purpose'    => $purpose,
			'quote_id'   => $quote_id,
			'token_hash' => $hash,
			'data'       => $data,
			'expires_at' => Db::datetime( time() + max( 60, $seconds ) ),
		] );
		return $raw;
	}

	/** A link that is unused, in date and belongs to an active client, or null. */
	public static function find_link( string $raw, string $purpose ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,}$/', $raw ) ) {
			return null;
		}
		$link = Db::find( Client_Accounts::LINKS, [ 'token_hash' => Tokens::hash( $raw ), 'purpose' => $purpose ] );
		if ( ! $link || $link['used_at'] || Db::timestamp( $link['expires_at'] ) < time() ) {
			return null;
		}
		$client = Client_Accounts::get( (int) $link['client_id'] );
		if ( ! $client || 'active' !== $client['status'] ) {
			return null;
		}
		$link['client_id'] = (int) $link['client_id'];
		$link['quote_id']  = (int) $link['quote_id'];
		return $link;
	}

	/** Use a link up. False if it was used in the meantime (two tabs). */
	public static function use_link( array $link ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( Client_Accounts::LINKS ) . ' SET used_at = %s, updated_at = %s WHERE id = %d AND used_at IS NULL', Db::now(), Db::now(), (int) $link['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function revoke_links( int $client_id, string $purpose = '' ): void {
		global $wpdb;
		$sql = 'UPDATE ' . Schema::table( Client_Accounts::LINKS ) . ' SET used_at = %s WHERE client_id = %d AND used_at IS NULL';
		$args = [ Db::now(), $client_id ];
		if ( $purpose ) {
			$sql   .= ' AND purpose = %s';
			$args[] = $purpose;
		}
		$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** "View my quote" for emails: a fresh single-use link scoped to the quote. */
	public static function quote_url( array $client, array $quote ): string {
		$raw = self::issue_link( (int) $client['id'], 'quote', max( 1, (int) Client_Accounts::opt( 'quote_link_days' ) ) * DAY_IN_SECONDS, (int) $quote['id'] );
		return home_url( '/account/q/' . $raw . '/' );
	}

	/* ---------------------------------------------------------------------
	 * Magic login links
	 * ------------------------------------------------------------------ */

	/** @return bool False only when this IP is over the limit (the reply is otherwise always the same). */
	public static function request_magic( string $email, string $next = '' ): bool {
		if ( ! Security::rate_limit( 'client_magic', 6, HOUR_IN_SECONDS ) ) {
			return false;
		}
		$user = self::user_for_email( $email, 'magic' );
		if ( ! $user ) {
			return true;
		}
		$client  = Client_Accounts::by_user( $user->ID );
		$minutes = max( 1, (int) Client_Accounts::opt( 'magic_minutes' ) );
		$raw     = self::issue_link( (int) $client['id'], 'login', $minutes * MINUTE_IN_SECONDS, 0, $next ? [ 'next' => $next ] : [] );
		Notify::send( 'client_login_link', $user->user_email, Client_Accounts::vars( $client ) + [ 'login_url' => self::magic_url( $raw ), 'minutes' => $minutes ], [ 'client', (int) $client['id'] ] );
		return true;
	}

	/** An admin-sent login link (Clients screen), valid for a day. */
	public static function send_login_link( array $client ): bool {
		$user = $client['user_id'] ? get_userdata( (int) $client['user_id'] ) : null;
		if ( ! $user || ! self::can_log_in( $user ) ) {
			return false;
		}
		$raw = self::issue_link( (int) $client['id'], 'login', DAY_IN_SECONDS );
		return Notify::send( 'client_login_link', $user->user_email, Client_Accounts::vars( $client ) + [ 'login_url' => self::magic_url( $raw ), 'minutes' => 24 * 60 ], [ 'client', (int) $client['id'] ] );
	}

	public static function magic_url( string $raw ): string {
		return home_url( '/account/magic/' . $raw . '/' );
	}

	/* ---------------------------------------------------------------------
	 * Password reset and change
	 * ------------------------------------------------------------------ */

	public static function request_reset( string $email ): bool {
		if ( ! Security::rate_limit( 'client_reset', 6, HOUR_IN_SECONDS ) ) {
			return false;
		}
		$user = self::user_for_email( $email, 'reset' );
		if ( ! $user ) {
			return true;
		}
		$url = self::reset_url( $user );
		if ( $url ) {
			$client = Client_Accounts::by_user( $user->ID );
			Notify::send( 'client_password_reset', $user->user_email, Client_Accounts::vars( $client ) + [ 'reset_url' => $url, 'minutes' => (int) Client_Accounts::opt( 'reset_minutes' ) ], [ 'client', (int) $client['id'] ] );
		}
		return true;
	}

	/** A fresh reset link (WordPress reset key; any earlier key stops working). */
	public static function reset_url( \WP_User $user ): string {
		$key = get_password_reset_key( $user );
		return is_wp_error( $key ) ? '' : home_url( '/account/reset/' . $user->ID . '/' . $key . '/' );
	}

	public static function check_reset( int $user_id, string $key ): ?\WP_User {
		$user = get_userdata( $user_id );
		if ( ! $user || ! Client_Accounts::is_client_user( $user ) || ! self::can_log_in( $user ) || ! preg_match( '/^[A-Za-z0-9]{10,}$/', $key ) ) {
			return null;
		}
		$limit = fn() => max( 5, (int) Client_Accounts::opt( 'reset_minutes' ) ) * MINUTE_IN_SECONDS;
		add_filter( 'password_reset_expiration', $limit );
		$checked = check_password_reset_key( $key, $user->user_login );
		remove_filter( 'password_reset_expiration', $limit );
		return $checked instanceof \WP_User ? $checked : null;
	}

	/** @return \WP_User|\WP_Error */
	public static function reset( int $user_id, string $key, string $password, string $confirm ) {
		$user = self::check_reset( $user_id, $key );
		if ( ! $user ) {
			return new \WP_Error( 'expired', 'This reset link has expired or has already been used. Please ask for a new one.' );
		}
		$problem = Cleaner_Auth::password_problem( $password, $confirm, self::avoid_words( $user ) );
		if ( $problem ) {
			return new \WP_Error( 'weak', $problem );
		}
		reset_password( $user, $password );
		$client = Client_Accounts::by_user( $user->ID );
		Client_Accounts::update( (int) $client['id'], [ 'password_set' => 1 ] );
		Events::log( 'client', (int) $client['id'], 'password_reset', 'Password reset from an emailed link; other sessions ended', [], 'client:' . $client['id'] );
		self::log_in( get_userdata( $user->ID ), false, 'password reset' );
		return $user;
	}

	/** Set a new password (first time, or a change), end every other session and stay signed in. */
	public static function set_password( \WP_User $user, string $password, string $method ): void {
		wp_set_password( $password, $user->ID );
		\WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		$client = Client_Accounts::by_user( $user->ID );
		if ( $client ) {
			Client_Accounts::update( (int) $client['id'], [ 'password_set' => 1 ] );
			Events::log( 'client', (int) $client['id'], 'password_set', ucfirst( $method ) . '; other sessions ended', [], 'client:' . $client['id'] );
		}
		self::log_in( get_userdata( $user->ID ), false, $method );
	}

	/** @return true|\WP_Error */
	public static function change_password( \WP_User $user, string $current, string $password, string $confirm ) {
		if ( ! Security::rate_limit( 'client_pw_change', 10, HOUR_IN_SECONDS ) || ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			return new \WP_Error( 'current', 'Your current password isn\'t right.' );
		}
		$problem = Cleaner_Auth::password_problem( $password, $confirm, self::avoid_words( $user ) );
		if ( $problem ) {
			return new \WP_Error( 'weak', $problem );
		}
		self::set_password( $user, $password, 'password changed in the client area' );
		return true;
	}

	/** `after_password_reset` (wp-login.php too): end every session for clients. */
	public static function password_was_reset( $user ): void {
		if ( Client_Accounts::is_client_user( $user ) ) {
			\WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
			$client = Client_Accounts::by_user( $user->ID );
			if ( $client && ! $client['password_set'] ) {
				Client_Accounts::update( (int) $client['id'], [ 'password_set' => 1 ] );
			}
		}
	}

	public static function avoid_words( \WP_User $user, array $client = [] ): array {
		$client = $client ?: ( Client_Accounts::by_user( $user->ID ) ?? [] );
		return array_filter( array_merge(
			[ strtok( (string) $user->user_email, '@' ) ],
			preg_split( '/\s+/', (string) ( $client['name'] ?? '' ) ) ?: []
		) );
	}

	private static function user_for_email( string $email, string $kind ): ?\WP_User {
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) || ! self::under( $kind . ':' . $email, 3, HOUR_IN_SECONDS ) ) {
			return null;
		}
		$user = get_user_by( 'email', $email );
		return $user && Client_Accounts::is_client_user( $user ) && self::can_log_in( $user ) ? $user : null;
	}
}

<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Cleaner sign-in: email + password, single-use magic login links (accounts.magic_minutes, default 15) and password
 * resets (WordPress reset keys, limited to accounts.reset_minutes). Requests are rate-limited per IP and per email,
 * every message is generic (no account enumeration), and a password change or reset ends every other session.
 * Deactivated, uninvited and not-yet-set-up cleaners can't sign in anywhere, wp-login.php included.
 */
final class Cleaner_Auth {
	const MAGIC_HASH    = '_cc_magic_hash';
	const MAGIC_EXPIRES = '_cc_magic_expires';
	const GENERIC_ERROR = 'That email address and password don\'t match an active cleaner account.';
	const SENT_MESSAGE  = 'If that email address belongs to a cleaner account, we\'ve sent it a link. Check your inbox (and spam folder).';

	const COMMON = [ 'password', 'passw0rd', '12345678', 'qwerty', 'letmein', 'welcome', 'iloveyou', 'abc123', '11111111', 'cleaning', 'cleaner', 'admin', 'monkey', 'football', 'sunshine', 'princess' ];

	public static function init(): void {
		add_filter( 'authenticate', [ self::class, 'block_inactive' ], 99, 1 );
		add_action( 'after_password_reset', [ self::class, 'password_was_reset' ] );
	}

	public static function can_log_in( \WP_User $user ): bool {
		$cleaner = Cleaner_Accounts::user_cleaner( $user->ID );
		return $cleaner && 'active' === $cleaner['status'] && in_array( Cleaner_Accounts::status( $cleaner ), Cleaner_Accounts::CAN_LOG_IN, true );
	}

	/** `authenticate`: covers wp-login.php and XML-RPC as well as the portal. */
	public static function block_inactive( $user ) {
		if ( $user instanceof \WP_User && Cleaner_Accounts::is_cleaner_user( $user ) && ! self::can_log_in( $user ) ) {
			return new \WP_Error( 'cc_cleaner_blocked', self::GENERIC_ERROR );
		}
		return $user;
	}

	/** Per-account counter (Security::rate_limit is per IP). True while under the limit. */
	private static function under( string $bucket, int $max, int $window, bool $count = true ): bool {
		$key = 'cc_rl_' . md5( 'cleaner|' . $bucket );
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
		delete_transient( 'cc_rl_' . md5( 'cleaner|' . $bucket ) );
	}

	/* ---------------------------------------------------------------------
	 * Password login
	 * ------------------------------------------------------------------ */

	/** @return \WP_User|\WP_Error */
	public static function login( string $email, string $password, bool $remember = false ) {
		$email  = strtolower( sanitize_email( $email ) );
		$bucket = 'login:' . $email;
		if ( ! Security::rate_limit( 'cleaner_login', 20, 15 * MINUTE_IN_SECONDS ) || ! self::under( $bucket, 5, 15 * MINUTE_IN_SECONDS, false ) ) {
			return new \WP_Error( 'limit', 'Too many attempts. Please wait 15 minutes, or use "Email me a login link".' );
		}
		$user = '' !== $email && '' !== $password ? wp_authenticate( $email, $password ) : new \WP_Error( 'empty' );
		if ( is_wp_error( $user ) || ! Cleaner_Accounts::is_cleaner_user( $user ) || ! self::can_log_in( $user ) ) {
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
		Cleaner_Accounts::forget_current();
		$cleaner = Cleaner_Accounts::user_cleaner( $user->ID );
		if ( $cleaner ) {
			Events::log( 'cleaner', (int) $cleaner['id'], 'login', 'Logged in (' . $method . ')', [], 'cleaner:' . $cleaner['id'] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Magic login links
	 * ------------------------------------------------------------------ */

	/** @return bool False only when this IP is over the limit (the reply is otherwise always the same). */
	public static function request_magic( string $email ): bool {
		if ( ! Security::rate_limit( 'cleaner_magic', 6, HOUR_IN_SECONDS ) ) {
			return false;
		}
		$user = self::user_for_email( $email, 'magic' );
		if ( ! $user ) {
			return true;
		}
		$raw     = self::issue_magic( $user );
		$minutes = (int) Cleaner_Accounts::opt( 'magic_minutes' );
		$cleaner = Cleaner_Accounts::user_cleaner( $user->ID );
		Notify::send( 'cleaner_magic_link', $user->user_email, Cleaner_Accounts::vars( $cleaner ) + [ 'login_url' => self::magic_url( $raw ), 'minutes' => $minutes ], [ 'cleaner', (int) $cleaner['id'] ] );
		return true;
	}

	/** New single-use token (replaces any earlier one). Returns the raw token. */
	public static function issue_magic( \WP_User $user ): string {
		[ $raw, $hash ] = Tokens::generate();
		update_user_meta( $user->ID, self::MAGIC_HASH, $hash );
		update_user_meta( $user->ID, self::MAGIC_EXPIRES, time() + max( 1, (int) Cleaner_Accounts::opt( 'magic_minutes' ) ) * MINUTE_IN_SECONDS );
		return $raw;
	}

	public static function magic_url( string $raw ): string {
		return home_url( '/cleaners/magic/' . $raw . '/' );
	}

	public static function verify_magic( string $raw ): ?\WP_User {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,}$/', $raw ) ) {
			return null;
		}
		$users = get_users( [ 'meta_key' => self::MAGIC_HASH, 'meta_value' => Tokens::hash( $raw ), 'number' => 1, 'role' => Cleaner_Accounts::ROLE ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$user  = $users[0] ?? null;
		if ( ! $user || (int) get_user_meta( $user->ID, self::MAGIC_EXPIRES, true ) < time() || ! self::can_log_in( $user ) ) {
			return null;
		}
		return $user;
	}

	/** Use the link up and sign in. */
	public static function use_magic( string $raw ): ?\WP_User {
		$user = self::verify_magic( $raw );
		if ( ! $user ) {
			return null;
		}
		delete_user_meta( $user->ID, self::MAGIC_HASH );
		delete_user_meta( $user->ID, self::MAGIC_EXPIRES );
		self::log_in( $user, false, 'login link' );
		return $user;
	}

	/* ---------------------------------------------------------------------
	 * Password reset and change
	 * ------------------------------------------------------------------ */

	public static function request_reset( string $email ): bool {
		if ( ! Security::rate_limit( 'cleaner_reset', 6, HOUR_IN_SECONDS ) ) {
			return false;
		}
		$user = self::user_for_email( $email, 'reset' );
		if ( ! $user ) {
			return true;
		}
		$url = self::reset_url( $user );
		if ( $url ) {
			$cleaner = Cleaner_Accounts::user_cleaner( $user->ID );
			Notify::send( 'cleaner_password_reset', $user->user_email, Cleaner_Accounts::vars( $cleaner ) + [ 'reset_url' => $url, 'minutes' => (int) Cleaner_Accounts::opt( 'reset_minutes' ) ], [ 'cleaner', (int) $cleaner['id'] ] );
		}
		return true;
	}

	/** A fresh reset link (WordPress reset key; any earlier key stops working). */
	public static function reset_url( \WP_User $user ): string {
		$key = get_password_reset_key( $user );
		return is_wp_error( $key ) ? '' : home_url( '/cleaners/reset/' . $user->ID . '/' . $key . '/' );
	}

	public static function check_reset( int $user_id, string $key ): ?\WP_User {
		$user = get_userdata( $user_id );
		if ( ! $user || ! Cleaner_Accounts::is_cleaner_user( $user ) || ! self::can_log_in( $user ) || ! preg_match( '/^[A-Za-z0-9]{10,}$/', $key ) ) {
			return null;
		}
		$limit = fn() => max( 5, (int) Cleaner_Accounts::opt( 'reset_minutes' ) ) * MINUTE_IN_SECONDS;
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
		$problem = self::password_problem( $password, $confirm, self::avoid_words( $user ) );
		if ( $problem ) {
			return new \WP_Error( 'weak', $problem );
		}
		reset_password( $user, $password );
		$cleaner = Cleaner_Accounts::user_cleaner( $user->ID );
		Events::log( 'cleaner', (int) $cleaner['id'], 'password_reset', 'Password reset from an emailed link; other sessions ended', [], 'cleaner:' . $cleaner['id'] );
		self::log_in( get_userdata( $user->ID ), false, 'password reset' );
		return $user;
	}

	/** Set a new password (setup step 1, or a change in the portal), end every other session and stay signed in. */
	public static function set_password( \WP_User $user, string $password, string $method ): void {
		wp_set_password( $password, $user->ID );
		\WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		$cleaner = Cleaner_Accounts::user_cleaner( $user->ID );
		if ( $cleaner ) {
			Events::log( 'cleaner', (int) $cleaner['id'], 'password_set', ucfirst( $method ) . '; other sessions ended', [], 'cleaner:' . $cleaner['id'] );
		}
		self::log_in( get_userdata( $user->ID ), false, $method );
	}

	/** @return true|\WP_Error */
	public static function change_password( \WP_User $user, string $current, string $password, string $confirm ) {
		if ( ! Security::rate_limit( 'cleaner_pw_change', 10, HOUR_IN_SECONDS ) || ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			return new \WP_Error( 'current', 'Your current password isn\'t right.' );
		}
		$problem = self::password_problem( $password, $confirm, self::avoid_words( $user ) );
		if ( $problem ) {
			return new \WP_Error( 'weak', $problem );
		}
		self::set_password( $user, $password, 'password changed in the portal' );
		return true;
	}

	/** `after_password_reset` (wp-login.php too): end every session for cleaners. */
	public static function password_was_reset( $user ): void {
		if ( Cleaner_Accounts::is_cleaner_user( $user ) ) {
			\WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		}
	}

	/** Strength rules shared by setup, reset and change. Returns the problem, or null when the password is fine. */
	public static function password_problem( string $password, string $confirm, array $avoid = [] ): ?string {
		if ( $password !== $confirm ) {
			return 'The two passwords don\'t match.';
		}
		$length = mb_strlen( $password );
		if ( $length < 10 ) {
			return 'Use at least 10 characters.';
		}
		if ( $length > 128 ) {
			return 'Use no more than 128 characters.';
		}
		$classes = (int) preg_match( '/[a-z]/', $password ) + (int) preg_match( '/[A-Z]/', $password ) + (int) preg_match( '/\d/', $password ) + (int) preg_match( '/[^A-Za-z0-9]/', $password );
		if ( $length < 16 && $classes < 3 ) {
			return 'Make it stronger: mix upper and lower case letters, numbers and symbols, or use a longer phrase of 16 or more characters.';
		}
		if ( preg_match( '/(.)\1{3,}/', $password ) ) {
			return 'Avoid repeating the same character over and over.';
		}
		$lower = strtolower( $password );
		foreach ( $avoid as $word ) {
			$word = strtolower( trim( (string) $word ) );
			if ( mb_strlen( $word ) >= 4 && str_contains( $lower, $word ) ) {
				return 'Don\'t use your name or email address in your password.';
			}
		}
		foreach ( self::COMMON as $common ) {
			if ( str_contains( $lower, $common ) ) {
				return 'That password is too easy to guess. Please choose another.';
			}
		}
		return null;
	}

	public static function avoid_words( \WP_User $user, array $cleaner = [] ): array {
		$cleaner = $cleaner ?: ( Cleaner_Accounts::user_cleaner( $user->ID ) ?? [] );
		return array_filter( array_merge(
			[ strtok( (string) $user->user_email, '@' ) ],
			preg_split( '/\s+/', (string) ( $cleaner['name'] ?? '' ) ) ?: []
		) );
	}

	private static function user_for_email( string $email, string $kind ): ?\WP_User {
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) || ! self::under( $kind . ':' . $email, 3, HOUR_IN_SECONDS ) ) {
			return null;
		}
		$user = get_user_by( 'email', $email );
		return $user && Cleaner_Accounts::is_cleaner_user( $user ) && self::can_log_in( $user ) ? $user : null;
	}
}

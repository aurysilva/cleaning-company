<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * The cleaner portal at /cleaners/ (private: noindex, no-store, never cached, no Referer):
 *   /cleaners/                       login (email + password, or "Email me a login link"); dashboard when logged in
 *   /cleaners/forgot/                password reset request
 *   /cleaners/magic/{token}/         single-use login link (GET shows a button, POST signs in, so link scanners can't use it)
 *   /cleaners/reset/{user}/{key}/    choose a new password
 *   /cleaners/setup/{token}/         invite link, step 1 of setup; /cleaners/setup/?step=… the other steps
 *   /cleaners/availability|profile|documents|bank|earnings/
 *   /cleaners/job/{offer_id}/        job page (same template and Dispatch code as the signed /job/… links)
 *   /cleaners/file/{doc_id}/         a document or photo, for the owning cleaner and admins only
 *   /cleaners/photo/{id}/{sig}/      approved profile photo for customers (signed)
 *   /cleaners/terms/                 the cleaner's agreement (both kinds when logged out)
 *   /cleaners/logout/                POST only
 * Every form carries a nonce (cc_cleaner_auth when logged out, cc_portal_{cleaner_id} when logged in) and actions
 * redirect afterwards (?ccm=code) so a refresh never repeats them.
 */
final class Cleaner_Portal {
	const VIEWS = [
		'home'         => 'My jobs',
		'availability' => 'Availability',
		'profile'      => 'Profile',
		'documents'    => 'Documents',
		'bank'         => 'Bank details',
		'earnings'     => 'Earnings',
	];

	const MESSAGES = [
		'saved'           => [ 'success', 'Saved.' ],
		'logged_out'      => [ 'success', 'You\'ve logged out.' ],
		'sent'            => [ 'success', Cleaner_Auth::SENT_MESSAGE ],
		'link_sent'       => [ 'success', 'Check your email', 'If that address belongs to a cleaner account, we\'ve sent it a login link. It works once, for 15 minutes. Look in your spam folder if it isn\'t there in a minute or two.' ],
		'reset_done'      => [ 'success', 'Your new password is set and you\'re logged in. Any other devices have been logged out.' ],
		'password'        => [ 'success', 'Password changed. Any other devices have been logged out.' ],
		'timeoff'         => [ 'success', 'Time off added.' ],
		'timeoff_removed' => [ 'success', 'Time off removed.' ],
		'uploaded'        => [ 'success', 'Thanks — we\'ll check it shortly.' ],
		'bank'            => [ 'success', 'Bank details saved.' ],
		'link_used'       => [ 'warning', 'That login link has expired or has already been used. You can ask for a new one below.' ],
		'signed_out'      => [ 'warning', 'Please log in again.' ],
		'limit'           => [ 'warning', 'Too many requests. Please wait a while and try again.' ],
		'expired'         => [ 'warning', 'The page timed out. Please try again.' ],
	];

	private static ?array $job_cleaner = null;

	public static function init(): void {
		add_action( 'cc_bookings_rewrites', [ self::class, 'rewrites' ] );
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ 'cc_portal', 'cc_pid', 'cc_ptoken' ] ) );
		add_action( 'template_redirect', [ self::class, 'route' ], 1 );
		add_action( 'cc_bookings_job_form', [ self::class, 'job_form_nonce' ] );
	}

	public static function rewrites(): void {
		add_rewrite_rule( '^cleaners/setup/([A-Za-z0-9_-]{20,})/?$', 'index.php?cc_portal=setup_token&cc_ptoken=$matches[1]', 'top' );
		add_rewrite_rule( '^cleaners/magic/([A-Za-z0-9_-]{20,})/?$', 'index.php?cc_portal=magic&cc_ptoken=$matches[1]', 'top' );
		add_rewrite_rule( '^cleaners/reset/(\d+)/([A-Za-z0-9]{10,})/?$', 'index.php?cc_portal=reset&cc_pid=$matches[1]&cc_ptoken=$matches[2]', 'top' );
		add_rewrite_rule( '^cleaners/photo/(\d+)/([A-Za-z0-9_-]{20,})/?$', 'index.php?cc_portal=photo&cc_pid=$matches[1]&cc_ptoken=$matches[2]', 'top' );
		add_rewrite_rule( '^cleaners/(job|file)/(\d+)/?$', 'index.php?cc_portal=$matches[1]&cc_pid=$matches[2]', 'top' );
		add_rewrite_rule( '^cleaners/(setup|availability|profile|documents|bank|earnings|forgot|logout|terms)/?$', 'index.php?cc_portal=$matches[1]', 'top' );
		add_rewrite_rule( '^cleaners/?$', 'index.php?cc_portal=home', 'top' );
	}

	public static function url( string $view = '', array $args = [] ): string {
		$url = home_url( '/cleaners/' . ( $view && 'home' !== $view ? trim( $view, '/' ) . '/' : '' ) );
		return $args ? add_query_arg( $args, $url ) : $url;
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

	public static function check_nonce( array $cleaner ): bool {
		return (bool) wp_verify_nonce( (string) wp_unslash( $_POST['_ccnonce'] ?? '' ), 'cc_portal_' . (int) $cleaner['id'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	public static function nonce_field( string $action ): void {
		wp_nonce_field( $action, '_ccnonce', false );
	}

	/** Prints the portal nonce inside the job template's forms when it is shown in the portal. */
	public static function job_form_nonce( $offer ): void {
		if ( self::$job_cleaner ) {
			self::nonce_field( 'cc_portal_' . (int) self::$job_cleaner['id'] );
		}
	}

	public static function enqueue_style(): void {
		wp_enqueue_style( 'cc-bookings-cleaners', CC_BOOKINGS_URL . 'assets/cleaners.css', [], Frontend::asset_version( 'assets/cleaners.css' ) );
	}

	public static function assets(): void {
		self::enqueue_style();
		wp_enqueue_script( 'cc-bookings-cleaners', CC_BOOKINGS_URL . 'assets/cleaners.js', [], Frontend::asset_version( 'assets/cleaners.js' ), [ 'in_footer' => true, 'strategy' => 'defer' ] );
	}

	public static function notice(): ?array {
		$code = sanitize_key( (string) ( $_GET['ccm'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		return self::MESSAGES[ $code ] ?? null;
	}

	public static function message( string $title, string $text, int $status = 200, bool $logout = false ): void {
		self::assets();
		Frontend::render( 'cleaner-message', [ 'title' => $title, 'text' => $text, 'logout' => $logout ], $title, $status );
	}

	private static function post(): array {
		return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- every caller checks the nonce first.
	}

	/* ---------------------------------------------------------------------
	 * Router
	 * ------------------------------------------------------------------ */

	public static function route(): void {
		$view = sanitize_key( (string) get_query_var( 'cc_portal' ) );
		if ( '' === $view ) {
			return;
		}
		$id    = (int) get_query_var( 'cc_pid' );
		$token = (string) get_query_var( 'cc_ptoken' );
		if ( 'photo' === $view ) {
			Cleaner_Docs::serve_photo( $id, $token );
		}
		Frontend::private_headers();
		header( 'X-Frame-Options: DENY' );

		switch ( $view ) {
			case 'file':
				Cleaner_Docs::serve( $id );
				break;
			case 'setup_token':
				Cleaner_Setup::route_token( $token );
				break;
			case 'magic':
				self::magic( $token );
				break;
			case 'reset':
				self::reset( $id, $token );
				break;
			case 'forgot':
				self::forgot();
				break;
			case 'logout':
				self::logout();
				break;
			case 'terms':
				self::terms();
				break;
		}

		$cleaner = Cleaner_Accounts::current();
		if ( ! $cleaner ) {
			if ( is_user_logged_in() ) {
				self::message( 'This is the cleaner portal', 'You\'re logged in to the website with an account that isn\'t a cleaner account. Log out first to log in as a cleaner.', 200, true );
			}
			self::login();
		}
		if ( ! Cleaner_Auth::can_log_in( wp_get_current_user() ) ) {
			wp_logout();
			self::redirect( self::url( '', [ 'ccm' => 'signed_out' ] ) );
		}
		if ( 'setup' === $view || 'setup' === Cleaner_Accounts::status( $cleaner ) ) {
			Cleaner_Setup::route( $cleaner );
		}
		if ( 'job' === $view ) {
			self::job( $cleaner, $id );
		}
		self::page( $cleaner, isset( self::VIEWS[ $view ] ) ? $view : 'home' );
	}

	/* ---------------------------------------------------------------------
	 * Logged out: login, login links, password reset
	 * ------------------------------------------------------------------ */

	private static function auth_render( string $mode, array $vars = [], int $status = 200 ): void {
		self::assets();
		$titles = [ 'login' => 'Cleaner login', 'forgot' => 'Reset your password', 'reset' => 'Choose a new password', 'magic' => 'Log in' ];
		Frontend::render( 'cleaner-login', $vars + [ 'mode' => $mode, 'error' => '', 'email' => '', 'notice' => self::notice(), 'action' => '', 'method' => 'password' ], $titles[ $mode ] ?? 'Cleaner login', $status );
	}

	private static function auth_nonce_ok(): bool {
		return (bool) wp_verify_nonce( (string) wp_unslash( $_POST['_ccnonce'] ?? '' ), 'cc_cleaner_auth' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/** Same-portal path to return to after logging in. */
	private static function next_url(): string {
		$next = (string) wp_unslash( $_REQUEST['next'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		return preg_match( '#^/cleaners/(job/\d+|availability|profile|documents|bank|earnings)/$#', $next ) ? home_url( $next ) : self::url();
	}

	private static function login(): void {
		$error  = '';
		$email  = '';
		$method = 'link' === ( $_GET['method'] ?? '' ) ? 'link' : 'password'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( self::is_post() ) {
			$post   = self::post();
			$email  = sanitize_email( (string) ( $post['email'] ?? '' ) );
			$method = 'magic' === ( $post['cc_do'] ?? '' ) ? 'link' : 'password';
			if ( ! self::auth_nonce_ok() ) {
				$error = self::MESSAGES['expired'][1];
			} elseif ( 'link' === $method ) {
				self::redirect( self::url( '', [ 'method' => 'link', 'ccm' => Cleaner_Auth::request_magic( $email ) ? 'link_sent' : 'limit' ] ) );
			} else {
				$user = Cleaner_Auth::login( $email, (string) ( $post['password'] ?? '' ), ! empty( $post['remember'] ) );
				if ( ! is_wp_error( $user ) ) {
					self::redirect( self::next_url() );
				}
				$error = $user->get_error_message();
			}
		}
		$path = strtok( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		self::auth_render( 'login', [ 'error' => $error, 'email' => $email, 'method' => $method, 'next' => '/cleaners/' !== $path ? $path : '' ], $error ? 401 : 200 );
	}

	/** The agreement for the logged-in cleaner's type (the wording they agreed, if kept), or both kinds. */
	private static function terms(): void {
		$cleaner = Cleaner_Accounts::current();
		$docs    = [];
		foreach ( $cleaner ? [ (string) $cleaner['type'] ] : [ 'employed', 'self_employed' ] as $type ) {
			$terms  = Cleaner_Accounts::terms( $type );
			$agreed = $cleaner ? (array) Cleaner_Accounts::account( (int) $cleaner['id'] )['terms'] : [];
			if ( ! empty( $agreed['hash'] ) ) {
				$history = (array) get_option( Cleaner_Accounts::TERMS_OPTION, [] );
				$kept    = $history[ $agreed['type'] . '|' . $agreed['version'] . '|' . substr( (string) $agreed['hash'], 0, 16 ) ] ?? null;
				if ( ! empty( $kept['text'] ) ) {
					$terms = array_merge( $terms, array_intersect_key( $kept, array_flip( [ 'type', 'version', 'text' ] ) ) );
				}
			}
			$docs[] = [ 'label' => Cleaner_Accounts::terms_label( $terms['type'] ), 'terms' => $terms, 'agreed' => $agreed ];
		}
		self::assets();
		Frontend::render( 'cleaner-terms', [ 'docs' => $docs, 'mine' => (bool) $cleaner ], 'Cleaner terms' );
	}

	private static function forgot(): void {
		if ( Cleaner_Accounts::current() ) {
			self::redirect( self::url( 'profile' ) );
		}
		$error = '';
		if ( self::is_post() ) {
			if ( ! self::auth_nonce_ok() ) {
				$error = self::MESSAGES['expired'][1];
			} else {
				$ok = Cleaner_Auth::request_reset( sanitize_email( (string) ( self::post()['email'] ?? '' ) ) );
				self::redirect( self::url( '', [ 'ccm' => $ok ? 'sent' : 'limit' ] ) );
			}
		}
		self::auth_render( 'forgot', [ 'error' => $error ] );
	}

	private static function magic( string $token ): void {
		if ( ! Cleaner_Auth::verify_magic( $token ) ) {
			self::redirect( self::url( '', [ 'method' => 'link', 'ccm' => 'link_used' ] ) );
		}
		if ( self::is_post() ) {
			if ( self::auth_nonce_ok() && Cleaner_Auth::use_magic( $token ) ) {
				self::redirect( self::url() );
			}
			self::auth_render( 'magic', [ 'error' => self::MESSAGES['expired'][1], 'action' => Cleaner_Auth::magic_url( $token ) ] );
		}
		self::auth_render( 'magic', [ 'action' => Cleaner_Auth::magic_url( $token ) ] );
	}

	private static function reset( int $user_id, string $key ): void {
		$user = Cleaner_Auth::check_reset( $user_id, $key );
		if ( ! $user ) {
			self::auth_render( 'forgot', [ 'error' => 'That reset link has expired or has already been used. Enter your email address for a new one.' ], 410 );
		}
		$error = '';
		if ( self::is_post() ) {
			$post = self::post();
			if ( ! self::auth_nonce_ok() ) {
				$error = self::MESSAGES['expired'][1];
			} else {
				$result = Cleaner_Auth::reset( $user_id, $key, (string) ( $post['password'] ?? '' ), (string) ( $post['password_confirm'] ?? '' ) );
				if ( ! is_wp_error( $result ) ) {
					self::redirect( self::url( '', [ 'ccm' => 'reset_done' ] ) );
				}
				$error = $result->get_error_message();
			}
		}
		self::auth_render( 'reset', [ 'error' => $error, 'action' => home_url( '/cleaners/reset/' . $user_id . '/' . $key . '/' ) ] );
	}

	private static function logout(): void {
		$cleaner = Cleaner_Accounts::current();
		if ( $cleaner && self::is_post() && self::check_nonce( $cleaner ) ) {
			Events::log( 'cleaner', (int) $cleaner['id'], 'logout', 'Logged out', [], 'cleaner:' . $cleaner['id'] );
			wp_logout();
			self::redirect( self::url( '', [ 'ccm' => 'logged_out' ] ) );
		}
		self::redirect( self::url() );
	}

	/* ---------------------------------------------------------------------
	 * Logged in
	 * ------------------------------------------------------------------ */

	private static function job( array $cleaner, int $offer_id ): void {
		$offer = $offer_id ? Db::get( 'job_offers', $offer_id ) : null;
		if ( ! $offer || (int) $offer['cleaner_id'] !== (int) $cleaner['id'] ) {
			self::message( 'Job not found', 'This job isn\'t in your account. It may have been offered to someone else.', 404 );
		}
		$url = self::url( 'job/' . $offer_id );
		if ( self::is_post() ) {
			$code   = 'error';
			if ( self::check_nonce( $cleaner ) ) {
				$code = Job_Pages::act( $offer, sanitize_key( (string) ( self::post()['cc_job_action'] ?? '' ) ) )['code'];
			}
			self::redirect( add_query_arg( 'r', $code, $url ) );
		}
		$offer   = Db::get( 'job_offers', $offer_id );
		$booking = Bookings::get( (int) $offer['booking_id'] );
		if ( ! $booking ) {
			self::message( 'Job not found', 'This job no longer exists.', 404 );
		}
		self::$job_cleaner = $cleaner;
		$code              = sanitize_key( (string) ( $_GET['r'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		Frontend::render( 'job', [
			'offer'      => $offer,
			'booking'    => $booking,
			'cleaner'    => $cleaner,
			'view'       => Job_Pages::view( $offer, $booking ),
			'notice'     => Job_Pages::MESSAGES[ $code ] ?? null,
			'action'     => $url,
			'jobs'       => self::url(),
			'jobs_label' => 'Back to my portal',
		], 'Job ' . $booking['ref'] );
	}

	private static function page( array $cleaner, string $view ): void {
		$error = '';
		$input = [];
		if ( self::is_post() ) {
			$post = self::post();
			if ( ! self::check_nonce( $cleaner ) ) {
				$error = self::MESSAGES['expired'][1];
			} else {
				[ $result, $code ] = self::handle( $cleaner, $view, sanitize_key( (string) ( $post['cc_do'] ?? '' ) ), $post );
				if ( true === $result ) {
					self::redirect( self::url( $view, $code ? [ 'ccm' => $code ] : [] ) );
				}
				if ( is_array( $result ) ) {
					self::redirect( $result['url'] );
				}
				$error = $result->get_error_message();
			}
			$input = array_diff_key( $post, array_flip( [ 'password', 'password_confirm', 'current_password', 'bank_holder', 'bank_sort', 'bank_account', '_ccnonce' ] ) );
		}
		$cleaner = Cleaners::get( (int) $cleaner['id'] );
		self::assets();
		Frontend::render( 'cleaner-portal', [
			'cleaner' => $cleaner,
			'account' => Cleaner_Accounts::account( (int) $cleaner['id'] ),
			'view'    => $view,
			'views'   => self::VIEWS,
			'notice'  => $error ? [ 'warning', $error ] : self::notice(),
			'input'   => $input,
			'error'   => $error,
			'data'    => self::data( $cleaner, $view ),
		], self::VIEWS[ $view ] );
	}

	/**
	 * A portal form post.
	 *
	 * @return array{0: true|array|\WP_Error, 1: string} [ result, message code ]; an array result is [ 'url' => redirect ].
	 */
	private static function handle( array $cleaner, string $view, string $do, array $post ): array {
		switch ( $view . ':' . $do ) {
			case 'home:offer':
				$offer = Db::get( 'job_offers', (int) ( $post['offer_id'] ?? 0 ) );
				if ( ! $offer || (int) $offer['cleaner_id'] !== (int) $cleaner['id'] ) {
					return [ new \WP_Error( 'offer', 'That offer isn\'t in your account.' ), '' ];
				}
				$code = Job_Pages::act( $offer, sanitize_key( (string) ( $post['cc_job_action'] ?? '' ) ) )['code'];
				return [ [ 'url' => add_query_arg( 'r', $code, self::url( 'job/' . (int) $offer['id'] ) ) ], '' ];
			case 'availability:availability':
				return [ Cleaner_Profile::save_availability( $cleaner, $post ), 'saved' ];
			case 'availability:timeoff_add':
				$result = Cleaner_Profile::add_time_off( $cleaner, $post );
				return [ $result, 'timeoff' ];
			case 'availability:timeoff_remove':
				return [ Cleaner_Profile::remove_time_off( $cleaner, (int) ( $post['off_id'] ?? 0 ) ) ?: new \WP_Error( 'off', 'That time off couldn\'t be found.' ), 'timeoff_removed' ];
			case 'profile:details':
				return [ Cleaner_Profile::save_details( $cleaner, $post ), 'saved' ];
			case 'profile:photo':
				return [ Cleaner_Profile::save_photo( $cleaner, false ), 'uploaded' ];
			case 'profile:emergency':
				return [ Cleaner_Profile::save_emergency( $cleaner, $post ), 'saved' ];
			case 'profile:password':
				$user = wp_get_current_user();
				return [ Cleaner_Auth::change_password( $user, (string) ( $post['current_password'] ?? '' ), (string) ( $post['password'] ?? '' ), (string) ( $post['password_confirm'] ?? '' ) ), 'password' ];
			case 'documents:documents':
				return [ Cleaner_Profile::save_documents( $cleaner, false ), 'uploaded' ];
			case 'bank:bank':
				return [ Cleaner_Bank::save( $cleaner, $post ), 'bank' ];
		}
		return [ new \WP_Error( 'unknown', 'Something went wrong. Please try again.' ), '' ];
	}

	/** What each page shows. */
	private static function data( array $cleaner, string $view ): array {
		$id = (int) $cleaner['id'];
		switch ( $view ) {
			case 'home':
				$docs     = Cleaner_Docs::current( $id );
				$attention = [];
				foreach ( Cleaner_Docs::types() as $type => $def ) {
					$doc = $docs[ $type ] ?? null;
					if ( $doc && 'rejected' === $doc['status'] ) {
						$attention[] = $def['label'] . ' needs uploading again.';
					} elseif ( $doc && 'expired' === $doc['status'] ) {
						$attention[] = $def['label'] . ' has expired.';
					} elseif ( $doc && $doc['expires_on'] && $doc['expires_on'] <= wp_date( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) && in_array( $doc['status'], [ 'approved', 'pending' ], true ) ) {
						$attention[] = sprintf( '%s expires on %s.', $def['label'], wp_date( 'j M Y', strtotime( $doc['expires_on'] . ' 12:00' ) ) );
					} elseif ( ! $doc && Cleaner_Docs::required( $type, $cleaner ) ) {
						$attention[] = $def['label'] . ' is missing.';
					}
				}
				return Job_Pages::cleaner_jobs( $id ) + [ 'attention' => $attention ];
			case 'availability':
				return [ 'timeoff' => Cleaner_Forms::timeoff( $cleaner ) ];
			case 'earnings':
				return self::earnings( $cleaner );
		}
		return [];
	}

	/** Completed jobs (newest first) with pay, and totals per month. */
	public static function earnings( array $cleaner ): array {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare(
			'SELECT o.id AS offer_id, o.pay_pence, b.* FROM ' . Schema::table( 'job_offers' ) . ' o JOIN ' . Schema::table( 'bookings' ) . ' b ON b.id = o.booking_id'
			. " WHERE o.cleaner_id = %d AND o.status IN ('assigned','accepted') AND b.status = 'completed' ORDER BY b.job_date DESC, b.id DESC LIMIT 200",
			(int) $cleaner['id']
		), ARRAY_A ) ?: [];
		$jobs   = [];
		$months = [];
		foreach ( $rows as $row ) {
			$booking = Db::decode( $row );
			$pay     = (int) $row['pay_pence'] > 0 ? (int) $row['pay_pence'] : Dispatch::pay( $cleaner, $booking );
			$month   = substr( (string) $booking['job_date'], 0, 7 );
			$jobs[]  = [ 'booking' => $booking, 'offer_id' => (int) $row['offer_id'], 'pay' => $pay ];
			$months[ $month ] = ( $months[ $month ] ?? 0 ) + $pay;
		}
		return [ 'jobs' => $jobs, 'months' => $months ];
	}
}

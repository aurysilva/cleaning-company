<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Client (customer) accounts: WordPress logins for customers (role cc_client), created automatically when a quote is
 * submitted and keyed by email address, and the client area at /account/. This class is the bootstrap: schema (own
 * version option), settings (clients.*), the client ↔ quotes / bookings / plans links (client_id on each row), the
 * wp-admin lockout, customer email links (into the account instead of the tokenised pages) and Stripe's return URLs.
 *
 * A client whose email address already belongs to another login (an admin or a cleaner) gets a client row without a
 * login (user_id 0, meta.no_login); their emails keep the tokenised links, exactly as before the client area existed.
 *
 * Hooks fired:
 *   cc_client_created( $client_id )
 *   cc_client_deactivated( $client_id ), cc_client_reactivated( $client_id )
 *   cc_client_delete_requested( $client_id )
 */
final class Client_Accounts {
	const SCHEMA_VERSION = '1';
	const SCHEMA_OPTION  = 'cc_bookings_client_accounts_schema';
	const ROLE           = 'cc_client';
	const TABLE          = 'clients';
	const LINKS          = 'client_links';
	const LINKED         = [ 'quotes', 'bookings', 'plans' ];

	const STATUSES = [ 'active' => 'Active', 'deactivated' => 'Deactivated' ];

	const DEFAULTS = [
		'form_notice'     => 'We\'ll create your secure client area so you can view your quote and manage bookings.',
		'quote_link_days' => 7,
		'magic_minutes'   => 15,
		'reset_minutes'   => 60,
		'email_hours'     => 24,
	];

	const QUOTE_TEMPLATES = [ 'quote_ready', 'quote_updated', 'quote_reminder', 'quote_expiring' ];

	private static array $current = [];

	public static function init(): void {
		self::maybe_install();
		add_filter( 'cc_bookings_email_defaults', [ self::class, 'email_defaults' ], 25 );
		add_filter( 'cc_bookings_email_vars', [ self::class, 'email_vars' ], 90, 3 );
		add_filter( 'cc_bookings_checkout_params', [ self::class, 'checkout_params' ], 20, 2 );
		add_action( 'cc_quote_created', [ self::class, 'quote_created' ], 1 );
		add_action( 'cc_quote_sent', [ self::class, 'quote_created' ], 1 );
		add_action( 'cc_booking_confirmed', [ self::class, 'booking_confirmed' ], 1, 2 );
		add_action( 'cc_plan_created', [ self::class, 'plan_created' ], 1, 2 );
		add_action( 'cc_plan_visit_created', [ self::class, 'visit_created' ], 1, 2 );
		add_filter( 'show_admin_bar', [ self::class, 'admin_bar' ], 99 );
		add_action( 'admin_init', [ self::class, 'block_admin' ], 1 );
		add_action( 'admin_page_access_denied', [ self::class, 'block_admin' ], 1 );
		add_filter( 'login_redirect', [ self::class, 'login_redirect' ], 20, 3 );
		add_action( 'template_redirect', [ self::class, 'block_author' ], 0 );
		add_filter( 'rest_user_query', [ self::class, 'hide_users' ] );
		add_filter( 'wp_sitemaps_users_query_args', [ self::class, 'hide_users' ] );

		Client_Auth::init();
		Client_Portal::init();
		Client_Chrome::init();
		if ( is_admin() ) {
			Admin\Clients_Screen::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cc-bookings clients', Client_Accounts_Cli::class );
		}
	}

	/* ---------------------------------------------------------------------
	 * Schema (own version option; the core schema version is untouched)
	 * ------------------------------------------------------------------ */

	public static function maybe_install(): void {
		if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION && get_option( Schema::OPTION ) ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();

		dbDelta( 'CREATE TABLE ' . Schema::table( self::TABLE ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
email varchar(200) NOT NULL DEFAULT '',
name varchar(200) NOT NULL DEFAULT '',
phone varchar(50) NOT NULL DEFAULT '',
address_1 varchar(200) NOT NULL DEFAULT '',
address_2 varchar(200) NOT NULL DEFAULT '',
town varchar(100) NOT NULL DEFAULT '',
postcode varchar(10) NOT NULL DEFAULT '',
status varchar(20) NOT NULL DEFAULT 'active',
password_set tinyint(1) NOT NULL DEFAULT 0,
marketing_opt_in tinyint(1) NOT NULL DEFAULT 0,
is_test tinyint(1) NOT NULL DEFAULT 0,
meta longtext NULL,
last_login_at datetime NULL,
delete_requested_at datetime NULL,
deactivated_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY email (email),
KEY user_id (user_id),
KEY status (status)
) {$collate};" );

		dbDelta( 'CREATE TABLE ' . Schema::table( self::LINKS ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
client_id bigint(20) unsigned NOT NULL,
purpose varchar(20) NOT NULL DEFAULT '',
quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
token_hash char(64) NOT NULL DEFAULT '',
data text NULL,
expires_at datetime NOT NULL,
used_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY token_hash (token_hash),
KEY client_purpose (client_id,purpose)
) {$collate};" );

		foreach ( self::LINKED as $name ) {
			$table = Schema::table( $name );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table && ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'client_id' ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN client_id bigint(20) unsigned NOT NULL DEFAULT 0, ADD KEY client_id (client_id)" ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		}

		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Client', [ 'read' => true ] );
		}
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		// New /account/ rewrite rules need a flush once.
		add_action( 'init', 'flush_rewrite_rules', 99 );
	}

	public static function opt( string $key ) {
		return Settings::get( 'clients.' . $key, self::DEFAULTS[ $key ] ?? null );
	}

	/* ---------------------------------------------------------------------
	 * Clients
	 * ------------------------------------------------------------------ */

	private static function cast( ?array $row ): ?array {
		if ( ! $row ) {
			return null;
		}
		foreach ( [ 'user_id', 'password_set', 'marketing_opt_in', 'is_test' ] as $key ) {
			$row[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}
		$row['meta'] = is_array( $row['meta'] ?? null ) ? $row['meta'] : [];
		return $row;
	}

	public static function get( int $id ): ?array {
		return $id ? self::cast( Db::get( self::TABLE, $id ) ) : null;
	}

	public static function by_email( string $email ): ?array {
		$email = strtolower( trim( sanitize_email( $email ) ) );
		return $email ? self::cast( Db::find( self::TABLE, [ 'email' => $email ] ) ) : null;
	}

	public static function by_user( int $user_id ): ?array {
		return $user_id > 0 ? self::cast( Db::find( self::TABLE, [ 'user_id' => $user_id ] ) ) : null;
	}

	public static function is_client_user( $user ): bool {
		return $user instanceof \WP_User && in_array( self::ROLE, (array) $user->roles, true );
	}

	/** The logged-in client (any status), or null. */
	public static function current(): ?array {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}
		if ( ! array_key_exists( $user_id, self::$current ) ) {
			self::$current[ $user_id ] = self::is_client_user( wp_get_current_user() ) ? self::by_user( $user_id ) : null;
		}
		return self::$current[ $user_id ];
	}

	public static function forget_current(): void {
		self::$current = [];
	}

	/** A client who can use the client area (active, with a client login), or null. */
	public static function portal_client( int $client_id ): ?array {
		$client = self::get( $client_id );
		if ( ! $client || 'active' !== $client['status'] || ! $client['user_id'] ) {
			return null;
		}
		return self::is_client_user( get_userdata( $client['user_id'] ) ) ? $client : null;
	}

	/**
	 * The client for an email address, created (with a login) if missing, and any quotes, bookings and plans with that
	 * address not yet linked to anyone are linked to it. Never says whether the account already existed.
	 */
	public static function ensure( string $email, array $details = [] ): ?array {
		$email = strtolower( trim( sanitize_email( $email ) ) );
		if ( ! is_email( $email ) ) {
			return null;
		}
		$locked = Db::lock( 'client:' . $email, 5 );
		try {
			$client = self::by_email( $email );
			if ( ! $client ) {
				$name = mb_substr( sanitize_text_field( (string) ( $details['name'] ?? '' ) ), 0, 200 );
				$id   = Db::insert( self::TABLE, [
					'email'            => $email,
					'name'             => $name,
					'phone'            => mb_substr( sanitize_text_field( (string) ( $details['phone'] ?? '' ) ), 0, 50 ),
					'address_1'        => mb_substr( sanitize_text_field( (string) ( $details['address_1'] ?? '' ) ), 0, 200 ),
					'address_2'        => mb_substr( sanitize_text_field( (string) ( $details['address_2'] ?? '' ) ), 0, 200 ),
					'town'             => mb_substr( sanitize_text_field( (string) ( $details['town'] ?? '' ) ), 0, 100 ),
					'postcode'         => mb_substr( strtoupper( sanitize_text_field( (string) ( $details['postcode'] ?? '' ) ) ), 0, 10 ),
					'status'           => 'active',
					'marketing_opt_in' => empty( $details['marketing'] ) ? 0 : 1,
					'is_test'          => Quotes::is_test_customer( $email, $name ) ? 1 : 0,
					'meta'             => [],
				] );
				$client = $id ? self::get( $id ) : self::by_email( $email );
				if ( ! $client ) {
					return null;
				}
				if ( $id ) {
					Events::log( 'client', $id, 'created', 'Client account created for ' . $email );
					do_action( 'cc_client_created', $id );
				}
			}
			if ( ! $client['user_id'] && 'active' === $client['status'] ) {
				self::ensure_user( $client );
			}
			self::claim( $client );
		} finally {
			if ( $locked ) {
				Db::unlock( 'client:' . $email );
			}
		}
		return self::get( (int) $client['id'] );
	}

	/** Create the client's login (role cc_client, random password, anonymous slug), unless the address is taken. */
	private static function ensure_user( array $client ): void {
		$existing = get_user_by( 'email', $client['email'] );
		if ( $existing ) {
			$other = self::by_user( $existing->ID );
			if ( ! self::is_client_user( $existing ) || ( $other && (int) $other['id'] !== (int) $client['id'] ) ) {
				if ( empty( $client['meta']['no_login'] ) ) {
					Db::update( self::TABLE, (int) $client['id'], [ 'meta' => [ 'no_login' => 1 ] + $client['meta'] ] );
					Events::log( 'client', (int) $client['id'], 'no_login', 'No client login: this email address belongs to another login on the website, so this customer\'s emails keep the private links' );
				}
				return;
			}
			$user_id = $existing->ID;
		} else {
			$slug    = 'client-' . (int) $client['id'] . '-' . strtolower( wp_generate_password( 6, false ) );
			$first   = Notify::first_name( (string) $client['name'] );
			$user_id = wp_insert_user( [
				'user_login'           => $slug,
				'user_pass'            => wp_generate_password( 40, true, true ),
				'user_email'           => $client['email'],
				'user_nicename'        => $slug,
				'display_name'         => $first ?: 'Client',
				'first_name'           => $first,
				'role'                 => self::ROLE,
				'show_admin_bar_front' => 'false',
			] );
			if ( is_wp_error( $user_id ) ) {
				Events::log( 'client', (int) $client['id'], 'user_failed', 'Login not created: ' . $user_id->get_error_message() );
				return;
			}
		}
		Db::update( self::TABLE, (int) $client['id'], [ 'user_id' => (int) $user_id ] );
	}

	/** Link quotes, bookings and plans with the client's email address that aren't linked to anyone yet. */
	public static function claim( array $client ): void {
		global $wpdb;
		foreach ( self::LINKED as $name ) {
			$table = Schema::table( $name );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET client_id = %d WHERE client_id = 0 AND email = %s", (int) $client['id'], (string) $client['email'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	public static function update( int $id, array $data ): void {
		Db::update( self::TABLE, $id, $data );
		self::forget_current();
	}

	public static function first_name( array $client ): string {
		return Notify::first_name( (string) $client['name'] ) ?: 'there';
	}

	/** Common email variables for a client. */
	public static function vars( array $client ): array {
		return [
			'first_name'  => self::first_name( $client ),
			'name'        => (string) $client['name'],
			'email'       => (string) $client['email'],
			'account_url' => Client_Portal::url(),
			'admin_url'   => self::admin_url( (int) $client['id'] ),
		];
	}

	public static function admin_url( int $id ): string {
		return admin_url( 'admin.php?page=cc-bookings-clients&id=' . $id );
	}

	/* ---------------------------------------------------------------------
	 * What the client owns
	 * ------------------------------------------------------------------ */

	public static function owns( array $client, ?array $row ): bool {
		return $row && (int) ( $row['client_id'] ?? 0 ) === (int) $client['id'];
	}

	public static function quotes( array $client ): array {
		return Db::all( 'quotes', [ 'client_id' => (int) $client['id'] ], 'id DESC', 100 );
	}

	public static function bookings( array $client ): array {
		return Db::all( 'bookings', [ 'client_id' => (int) $client['id'] ], 'start_at DESC, id DESC', 300 );
	}

	public static function plans( array $client ): array {
		return array_values( array_filter( array_map( fn( $p ) => Plans::get( (int) $p['id'] ), Db::all( Recurring::TABLE, [ 'client_id' => (int) $client['id'] ], 'id DESC', 50 ) ) ) );
	}

	public static function has_plans( array $client ): bool {
		return Db::count( Recurring::TABLE, [ 'client_id' => (int) $client['id'] ] ) > 0;
	}

	/** The booking a paid quote becomes (Bookings::confirm_payment uses the same number). */
	public static function booking_ref( array $quote ): string {
		return 'B-' . preg_replace( '/^Q-/', '', (string) $quote['ref'] );
	}

	/* ---------------------------------------------------------------------
	 * Linking new rows
	 * ------------------------------------------------------------------ */

	/** `cc_quote_created` / `cc_quote_sent`: every quote belongs to the client with its email address. */
	public static function quote_created( $quote_id ): void {
		$quote = Quotes::get( (int) $quote_id );
		if ( ! $quote || (int) ( $quote['client_id'] ?? 0 ) ) {
			return;
		}
		$client = self::ensure( (string) $quote['email'], [
			'name'      => $quote['customer_name'],
			'phone'     => $quote['phone'],
			'address_1' => $quote['address_1'],
			'address_2' => $quote['address_2'],
			'town'      => $quote['town'],
			'postcode'  => $quote['postcode'],
			'marketing' => $quote['marketing_opt_in'],
		] );
		if ( ! $client ) {
			return;
		}
		Db::update( 'quotes', (int) $quote['id'], [ 'client_id' => (int) $client['id'] ] );
		if ( $quote['marketing_opt_in'] && ! $client['marketing_opt_in'] ) {
			self::update( (int) $client['id'], [ 'marketing_opt_in' => 1 ] );
		}
	}

	public static function booking_confirmed( $booking_id, $context = [] ): void {
		$booking = Bookings::get( (int) $booking_id );
		if ( ! $booking || (int) ( $booking['client_id'] ?? 0 ) ) {
			return;
		}
		$client_id = 0;
		if ( $booking['quote_id'] && ( $quote = Quotes::get( (int) $booking['quote_id'] ) ) ) {
			$client_id = (int) ( $quote['client_id'] ?? 0 );
		}
		if ( ! $client_id && (int) $booking['series_id'] && ( $plan = Plans::get( (int) $booking['series_id'] ) ) ) {
			$client_id = (int) ( $plan['client_id'] ?? 0 );
		}
		if ( ! $client_id && ! empty( $context['reclean_of'] ) && ( $original = Bookings::get( (int) $context['reclean_of'] ) ) ) {
			$client_id = (int) ( $original['client_id'] ?? 0 );
		}
		if ( ! $client_id && ( $client = self::by_email( (string) $booking['email'] ) ) ) {
			$client_id = (int) $client['id'];
		}
		if ( $client_id ) {
			Db::update( 'bookings', (int) $booking['id'], [ 'client_id' => $client_id ] );
		}
	}

	public static function plan_created( $plan_id, $booking_id = 0 ): void {
		$booking = Bookings::get( (int) $booking_id );
		if ( $booking && (int) ( $booking['client_id'] ?? 0 ) ) {
			Db::update( Recurring::TABLE, (int) $plan_id, [ 'client_id' => (int) $booking['client_id'] ] );
		}
	}

	public static function visit_created( $plan_id, $visit_id ): void {
		$plan = Plans::get( (int) $plan_id );
		if ( $plan && (int) ( $plan['client_id'] ?? 0 ) ) {
			Db::update( 'bookings', (int) $visit_id, [ 'client_id' => (int) $plan['client_id'] ] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Account status
	 * ------------------------------------------------------------------ */

	/** Block login, end every session and revoke every emailed link. */
	public static function deactivate( int $id, string $reason = '' ): bool {
		$client = self::get( $id );
		if ( ! $client || 'deactivated' === $client['status'] ) {
			return false;
		}
		self::update( $id, [ 'status' => 'deactivated', 'deactivated_at' => Db::now() ] );
		self::revoke_access( $client );
		Events::log( 'client', $id, 'deactivated', 'Client account deactivated' . ( $reason ? ': ' . $reason : '' ) );
		do_action( 'cc_client_deactivated', $id );
		return true;
	}

	public static function reactivate( int $id ): bool {
		$client = self::get( $id );
		if ( ! $client || 'deactivated' !== $client['status'] ) {
			return false;
		}
		self::update( $id, [ 'status' => 'active', 'deactivated_at' => null ] );
		Events::log( 'client', $id, 'reactivated', 'Client account reactivated' );
		do_action( 'cc_client_reactivated', $id );
		return true;
	}

	public static function revoke_access( array $client ): void {
		global $wpdb;
		if ( $client['user_id'] ) {
			\WP_Session_Tokens::get_instance( (int) $client['user_id'] )->destroy_all();
			$wpdb->update( $wpdb->users, [ 'user_activation_key' => '' ], [ 'ID' => (int) $client['user_id'] ] );
			clean_user_cache( (int) $client['user_id'] );
		}
		Client_Auth::revoke_links( (int) $client['id'] );
	}

	/** GDPR: the client asks for their account and data to be deleted; the office handles it by hand. */
	public static function request_delete( array $client, string $note = '' ): void {
		if ( $client['delete_requested_at'] ) {
			return;
		}
		self::update( (int) $client['id'], [ 'delete_requested_at' => Db::now() ] );
		Events::log( 'client', (int) $client['id'], 'delete_requested', 'Asked for their account and personal data to be deleted' . ( $note ? ': ' . $note : '' ), [], 'client:' . $client['id'] );
		Notify::send( 'client_delete_request', Settings::admin_email(), [ 'note' => $note ?: '(no message)' ] + self::vars( $client ), [ 'client', (int) $client['id'] ] );
		do_action( 'cc_client_delete_requested', (int) $client['id'] );
	}

	/* ---------------------------------------------------------------------
	 * Lockout: no wp-admin, no admin bar, no author archives or REST listing
	 * ------------------------------------------------------------------ */

	public static function admin_bar( $show ) {
		return self::is_client_user( wp_get_current_user() ) ? false : $show;
	}

	public static function block_admin(): void {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( self::is_client_user( $user ) && ! user_can( $user, 'edit_posts' ) ) {
			wp_safe_redirect( Client_Portal::url() );
			exit;
		}
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		return self::is_client_user( $user ) ? Client_Portal::url() : $redirect_to;
	}

	public static function block_author(): void {
		global $wp_query;
		if ( ! is_author() ) {
			return;
		}
		$author = get_queried_object();
		if ( self::is_client_user( $author ) || ( ! $author && ( $id = (int) get_query_var( 'author' ) ) && self::is_client_user( get_userdata( $id ) ) ) ) {
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	public static function hide_users( $args ) {
		$args['role__not_in'] = array_merge( (array) ( $args['role__not_in'] ?? [] ), [ self::ROLE ] );
		return $args;
	}

	/* ---------------------------------------------------------------------
	 * Customer emails and Stripe return URLs
	 * ------------------------------------------------------------------ */

	/**
	 * `cc_bookings_email_vars` (last): in customer emails, tokenised quote links become a single-use "view my quote"
	 * link into the account and booking links become the booking's page in the account. Customers without a client
	 * login keep the tokenised links.
	 */
	public static function email_vars( $vars, $key, $object ) {
		$template = Notify::defaults()[ $key ] ?? [];
		if ( ! is_array( $vars ) || 'customer' !== ( $template['to'] ?? '' ) ) {
			return $vars;
		}
		$home   = preg_quote( untrailingslashit( home_url() ), '#' );
		$client = null;
		foreach ( $vars as $name => $value ) {
			if ( ! is_string( $value ) || ! str_starts_with( $value, home_url() ) ) {
				continue;
			}
			$url = '';
			if ( preg_match( '#^' . $home . '/quote/(Q-\d+)/[A-Za-z0-9_-]{20,}/?(?:\?[^\#]*)?(?:\#.*)?$#', $value, $m ) ) {
				[ $url, $client ] = self::quote_email_link( $m[1] );
			} elseif ( preg_match( '#^' . $home . '/booking/(B-\d+)/[A-Za-z0-9_-]{20,}/?(\?[^\#]*)?(\#.*)?$#', $value, $m ) ) {
				$booking = Bookings::by_ref( $m[1] );
				$owner   = $booking ? self::portal_client( (int) ( $booking['client_id'] ?? 0 ) ) : null;
				if ( $owner ) {
					$client = $owner;
					$url    = Client_Portal::url( 'bookings/' . $booking['ref'] ) . ( $m[2] ?? '' ) . ( $m[3] ?? '' );
				}
			}
			if ( $url ) {
				$vars[ $name ] = $url;
			}
		}
		if ( in_array( $key, self::QUOTE_TEMPLATES, true ) ) {
			$days              = max( 1, (int) self::opt( 'quote_link_days' ) );
			$vars['link_note'] = $client
				? sprintf( 'The button signs you in to your secure client area. It works once and expires in %d day%s — after that, log in at %s.', $days, 1 === $days ? '' : 's', Client_Portal::url() )
				: '';
		}
		if ( $client ) {
			$vars['account_url'] = Client_Portal::url();
		}
		return $vars;
	}

	/** One single-use link per quote per request, so the same email never issues two. @return array{0: string, 1: ?array} */
	private static function quote_email_link( string $ref ): array {
		static $links = [];
		if ( ! isset( $links[ $ref ] ) ) {
			$quote  = Quotes::by_ref( $ref );
			$client = $quote ? self::portal_client( (int) ( $quote['client_id'] ?? 0 ) ) : null;
			$links[ $ref ] = $client ? [ Client_Auth::quote_url( $client, $quote ), $client ] : [ '', null ];
		}
		return $links[ $ref ];
	}

	/** `cc_bookings_checkout_params`: Stripe returns clients to their account (booking page, or the quote if they cancel). */
	public static function checkout_params( $params, $quote ) {
		if ( ! is_array( $params ) || ! is_array( $quote ) ) {
			return $params;
		}
		$client = self::portal_client( (int) ( $quote['client_id'] ?? 0 ) );
		if ( $client ) {
			$params['success_url'] = Client_Portal::url( 'bookings/' . self::booking_ref( $quote ), [ 'checkout' => 'success' ] );
			$params['cancel_url']  = Client_Portal::url( 'quotes/' . $quote['ref'], [ 'checkout' => 'cancelled' ] );
		}
		return $params;
	}

	public static function email_defaults( array $templates ): array {
		return array_merge( $templates, [
			'client_login_link'     => [
				'label'      => 'Login link (client)',
				'to'         => 'customer',
				'subject'    => 'Your login link for {brand}',
				'body'       => "Hi {first_name},\n\nTap the button to log in to your client area, where you can see your quotes and manage your bookings. The link works once and expires in {lasts}.\n\nIf you didn't ask for this, you can ignore this email.",
				'button'     => 'Log in',
				'button_url' => 'login_url',
			],
			'client_password_reset' => [
				'label'      => 'Password reset (client)',
				'to'         => 'customer',
				'subject'    => 'Reset your password for {brand}',
				'body'       => "Hi {first_name},\n\nSomeone (hopefully you) asked to reset the password for your client account. Tap the button to choose a new one. The link works once and expires in {lasts}.\n\nIf you didn't ask for this, you can ignore this email — your password won't change.",
				'button'     => 'Choose a new password',
				'button_url' => 'reset_url',
			],
			'client_email_verify'   => [
				'label'      => 'Confirm a new email address (client)',
				'to'         => 'customer',
				'subject'    => 'Confirm your new email address for {brand}',
				'body'       => "Hi {first_name},\n\nPlease confirm that you'd like to use this email address to log in to your client area. The link works once and expires in {lasts}.\n\nIf you didn't ask for this, you can ignore this email and nothing will change.",
				'button'     => 'Confirm my new email',
				'button_url' => 'verify_url',
			],
			'client_email_changed'  => [
				'label'   => 'Email address changed (client, sent to the old address)',
				'to'      => 'customer',
				'subject' => 'Your email address for {brand} has changed',
				'body'    => "Hi {first_name},\n\nThe email address for your client account has been changed to {new_email}. From now on, log in and receive your emails there.\n\nIf you didn't make this change, please call us straight away on {phone}.",
			],
			'client_delete_request' => [
				'label'      => 'Client asked to delete their account (admin)',
				'to'         => 'admin',
				'subject'    => 'Account deletion request: {name}',
				'body'       => "{name} ({email}) has asked for their client account and personal data to be deleted.\n\nTheir message: {note}\n\nUK GDPR gives you one month to respond. Keep what you must for accounting (paid bookings and invoices), then deactivate the account and delete the rest.",
				'button'     => 'Open the client',
				'button_url' => 'admin_url',
			],
		] );
	}
}

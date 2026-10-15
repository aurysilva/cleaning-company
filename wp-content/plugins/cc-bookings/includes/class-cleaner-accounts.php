<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Cleaner accounts: WordPress logins for cleaners (role cc_cleaner, linked by cleaners.user_id), invite-based
 * onboarding, the setup wizard and the cleaner portal at /cleaners/. This class is the bootstrap: schema (own
 * version option), settings (accounts.*), account statuses, email templates, dispatch eligibility, the wp-admin
 * lockout, retention and the daily tick.
 *
 * Account status (cleaners.account_status):
 *   legacy       added before accounts existed: no login, dispatched exactly as before
 *   none         added, not invited yet                  → invited
 *   invited      setup link sent (7 days, single use)     → setup (password set from the link)
 *   setup        working through the setup steps          → review (terms agreed and submitted)
 *   review       waiting for the office to check it       → active (admin approves)
 *   active       logs in, gets offers and jobs
 *   deactivated  login, signed links and sessions revoked → back to the status before (reactivate)
 * Dispatch and slots only use active and legacy cleaners, plus legacy cleaners part-way through onboarding
 * (cleaner_accounts.was_legacy = 1), so inviting an existing cleaner never takes them off the rota.
 *
 * Hooks fired:
 *   cc_cleaner_account_status( $cleaner_id, $to, $from )
 *   cc_cleaner_invited( $cleaner_id )
 *   cc_cleaner_setup_submitted( $cleaner_id )
 *   cc_cleaner_activated( $cleaner_id ), cc_cleaner_deactivated( $cleaner_id )
 *   cc_cleaner_doc_uploaded( $doc_id, $cleaner_id ), cc_cleaner_doc_reviewed( $doc_id, $status )
 *   cc_cleaner_availability_changed( $cleaner_id )
 */
final class Cleaner_Accounts {
	const SCHEMA_VERSION = '1';
	const SCHEMA_OPTION  = 'cc_bookings_cleaner_accounts_schema';
	const TERMS_OPTION   = 'cc_bookings_cleaner_terms_history';
	const ROLE           = 'cc_cleaner';
	const TABLE          = 'cleaner_accounts';
	const DAILY_HOOK     = 'cc_bookings_cleaner_daily';
	const DAILY_OPTION   = 'cc_bookings_cleaner_daily_checked';

	const STATUSES = [
		'none'        => 'Not invited',
		'invited'     => 'Invited',
		'setup'       => 'Setup in progress',
		'review'      => 'Awaiting approval',
		'active'      => 'Active',
		'deactivated' => 'Deactivated',
		'legacy'      => 'No account (legacy)',
	];

	const ELIGIBLE = [ 'active', 'legacy' ];

	/** Statuses that can log in. */
	const CAN_LOG_IN = [ 'setup', 'review', 'active' ];

	const DEFAULTS = [
		'invite_days'                 => 7,
		'magic_minutes'               => 15,
		'reset_minutes'               => 60,
		'reminder_days'               => '30,7',
		'retention_docs_days'         => 730,
		'retention_bank_days'         => 90,
		'terms_employed_version'      => 'draft-1',
		'terms_employed'              => "DRAFT — the business owner must replace this with the real terms for employed cleaners before inviting anyone.\n\n1. Your employment is covered by your written statement of employment particulars and the staff handbook.\n2. You will keep customer addresses, access codes, keys and personal details confidential and only use them to do the job.\n3. You will follow our health and safety, safeguarding and key-handling procedures.\n4. You will tell us straight away about any accident, breakage or complaint.\n5. We process your personal data (including your documents and bank details) to employ and pay you, as described in our staff privacy notice.",
		'terms_self_employed_version' => 'draft-1',
		'terms_self_employed'         => "DRAFT — the business owner must replace this with the real contractor agreement before inviting anyone.\n\n1. You are an independent self-employed contractor, not an employee. You can accept or decline any job offered, and you are responsible for your own tax and National Insurance.\n2. You will hold public liability insurance for the work you accept and keep it in date.\n3. You will keep customer addresses, access codes, keys and personal details confidential, only use them to do the job, and delete them when the job is done (data protection).\n4. You will do the work to the standard described in the job and tell us straight away about any accident, breakage or complaint.\n5. We pay the agreed amount for each completed job to the bank account you give us.",
	];

	private static array $current = [];

	public static function init(): void {
		self::maybe_install();
		add_filter( 'cc_bookings_email_defaults', [ self::class, 'email_defaults' ], 20 );
		add_filter( 'cc_bookings_email_html_vars', fn( $vars ) => array_merge( (array) $vars, [ 'cleaner_card' ] ) );
		add_filter( 'cc_bookings_email_vars', [ self::class, 'email_vars' ], 30, 3 );
		add_filter( 'cc_bookings_dispatch_cleaners', [ self::class, 'dispatch_pool' ], 5 );
		add_filter( 'cc_bookings_booking_crew', [ Cleaner_Docs::class, 'booking_crew' ], 10, 2 );
		add_filter( 'show_admin_bar', [ self::class, 'admin_bar' ], 99 );
		add_action( 'admin_init', [ self::class, 'block_admin' ], 1 );
		add_filter( 'login_redirect', [ self::class, 'login_redirect' ], 20, 3 );
		add_action( 'template_redirect', [ self::class, 'block_author' ], 0 );
		add_filter( 'rest_user_query', [ self::class, 'hide_users' ] );
		add_filter( 'wp_sitemaps_users_query_args', [ self::class, 'hide_users' ] );
		add_action( 'cc_cleaner_saved', [ self::class, 'on_saved' ], 20, 2 );
		add_action( 'init', [ self::class, 'schedule_daily' ] );
		add_action( self::DAILY_HOOK, [ self::class, 'daily' ] );

		Cleaner_Auth::init();
		Cleaner_Portal::init();
		Cleaner_Chrome::init();
		if ( is_admin() ) {
			Admin\Cleaner_Accounts_Screen::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cc-bookings cleaners', Cleaner_Accounts_Cli::class );
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
cleaner_id bigint(20) unsigned NOT NULL,
was_legacy tinyint(1) NOT NULL DEFAULT 0,
status_before varchar(20) NOT NULL DEFAULT '',
invite_hash char(64) NOT NULL DEFAULT '',
invite_expires datetime NULL,
invited_at datetime NULL,
address_1 varchar(200) NOT NULL DEFAULT '',
address_2 varchar(200) NOT NULL DEFAULT '',
town varchar(100) NOT NULL DEFAULT '',
postcode varchar(10) NOT NULL DEFAULT '',
emergency text NULL,
bank text NULL,
bank_last4 varchar(4) NOT NULL DEFAULT '',
bank_updated_at datetime NULL,
terms text NULL,
setup text NULL,
submitted_at datetime NULL,
approved_at datetime NULL,
approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
deactivated_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY cleaner_id (cleaner_id),
KEY invite_hash (invite_hash)
) {$collate};" );

		dbDelta( 'CREATE TABLE ' . Schema::table( Cleaner_Docs::TABLE ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
cleaner_id bigint(20) unsigned NOT NULL,
type varchar(30) NOT NULL DEFAULT '',
status varchar(20) NOT NULL DEFAULT 'pending',
file varchar(100) NOT NULL DEFAULT '',
mime varchar(100) NOT NULL DEFAULT '',
size int(11) unsigned NOT NULL DEFAULT 0,
original varchar(200) NOT NULL DEFAULT '',
expires_on date NULL,
reminded varchar(40) NOT NULL DEFAULT '',
reason varchar(255) NOT NULL DEFAULT '',
reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
reviewed_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY cleaner_type (cleaner_id,type),
KEY status (status),
KEY expires_on (expires_on)
) {$collate};" );

		// Cleaners added before accounts existed keep working exactly as before.
		$table = Schema::table( 'cleaners' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'account_status' ) ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN account_status varchar(20) NOT NULL DEFAULT 'none', ADD KEY account_status (account_status)" ); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->query( "UPDATE {$table} SET account_status = 'legacy'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		self::add_role();
		Files::protect();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		// New /cleaners/ rewrite rules need a flush once.
		add_action( 'init', 'flush_rewrite_rules', 99 );
	}

	public static function add_role(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Cleaner', [ 'read' => true ] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function opt( string $key ) {
		return Settings::get( 'accounts.' . $key, self::DEFAULTS[ $key ] ?? null );
	}

	/** @return array{type: string, version: string, text: string, hash: string} */
	public static function terms( string $type ): array {
		$type = 'self_employed' === $type ? 'self_employed' : 'employed';
		$text = (string) self::opt( 'terms_' . $type );
		return [ 'type' => $type, 'version' => (string) self::opt( 'terms_' . $type . '_version' ), 'text' => $text, 'hash' => hash( 'sha256', $text ) ];
	}

	public static function terms_label( string $type ): string {
		return 'self_employed' === $type ? 'Contractor agreement' : 'Cleaner terms';
	}

	/* ---------------------------------------------------------------------
	 * Accounts
	 * ------------------------------------------------------------------ */

	public static function status( array $cleaner ): string {
		$status = (string) ( $cleaner['account_status'] ?? 'legacy' );
		return isset( self::STATUSES[ $status ] ) ? $status : 'none';
	}

	public static function label( string $status ): string {
		return self::STATUSES[ $status ] ?? ucfirst( $status );
	}

	public static function badge( string $status ): string {
		return sprintf( '<span class="cc-status cc-status--acct-%s">%s</span>', esc_attr( $status ), esc_html( self::label( $status ) ) );
	}

	/** The cleaner_accounts row (created on first use), JSON columns decoded. */
	public static function account( int $cleaner_id ): array {
		$row = Db::find( self::TABLE, [ 'cleaner_id' => $cleaner_id ] );
		if ( ! $row ) {
			Db::insert( self::TABLE, [ 'cleaner_id' => $cleaner_id ] );
			$row = Db::find( self::TABLE, [ 'cleaner_id' => $cleaner_id ] ) ?? [ 'id' => 0, 'cleaner_id' => $cleaner_id ];
		}
		foreach ( [ 'emergency', 'terms', 'setup' ] as $key ) {
			$row[ $key ] = ! empty( $row[ $key ] ) && is_string( $row[ $key ] ) ? ( json_decode( $row[ $key ], true ) ?: [] ) : ( is_array( $row[ $key ] ?? null ) ? $row[ $key ] : [] );
		}
		$row['was_legacy'] = (int) ( $row['was_legacy'] ?? 0 );
		return $row;
	}

	public static function update_account( int $cleaner_id, array $data ): void {
		$account = self::account( $cleaner_id );
		if ( $account['id'] ) {
			Db::update( self::TABLE, (int) $account['id'], $data );
		}
	}

	public static function set_status( int $cleaner_id, string $to, string $note = '' ): void {
		$cleaner = Cleaners::get( $cleaner_id );
		if ( ! $cleaner || ! isset( self::STATUSES[ $to ] ) ) {
			return;
		}
		$from = self::status( $cleaner );
		if ( $from === $to ) {
			return;
		}
		Db::update( 'cleaners', $cleaner_id, [ 'account_status' => $to ] );
		Events::log( 'cleaner', $cleaner_id, 'account_status', $note ?: sprintf( 'Account: %s → %s', self::label( $from ), self::label( $to ) ), [ 'from' => $from, 'to' => $to ] );
		Availability::flush();
		do_action( 'cc_cleaner_account_status', $cleaner_id, $to, $from );
	}

	/** Can this cleaner be dispatched / counted for slots? */
	public static function eligible( array $cleaner ): bool {
		if ( 'active' !== ( $cleaner['status'] ?? '' ) ) {
			return false;
		}
		$status = self::status( $cleaner );
		if ( in_array( $status, self::ELIGIBLE, true ) ) {
			return true;
		}
		return in_array( $status, [ 'invited', 'setup', 'review' ], true ) && self::account( (int) $cleaner['id'] )['was_legacy'];
	}

	/** `cc_bookings_dispatch_cleaners`: only active (and legacy) cleaners are dispatched or counted for slots. */
	public static function dispatch_pool( $cleaners ) {
		return array_values( array_filter( (array) $cleaners, [ self::class, 'eligible' ] ) );
	}

	public static function is_cleaner_user( $user ): bool {
		return $user instanceof \WP_User && in_array( self::ROLE, (array) $user->roles, true );
	}

	public static function user_cleaner( int $user_id ): ?array {
		return $user_id > 0 ? Db::find( 'cleaners', [ 'user_id' => $user_id ] ) : null;
	}

	/** The logged-in cleaner, or null. */
	public static function current(): ?array {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}
		if ( ! array_key_exists( $user_id, self::$current ) ) {
			self::$current[ $user_id ] = self::is_cleaner_user( wp_get_current_user() ) ? self::user_cleaner( $user_id ) : null;
		}
		return self::$current[ $user_id ];
	}

	public static function forget_current(): void {
		self::$current = [];
	}

	/**
	 * The cleaner's WordPress login, created (role cc_cleaner, random password, anonymous slug) if missing.
	 *
	 * @return \WP_User|\WP_Error
	 */
	public static function ensure_user( array $cleaner ) {
		if ( (int) $cleaner['user_id'] && ( $user = get_userdata( (int) $cleaner['user_id'] ) ) ) {
			return $user;
		}
		$email = sanitize_email( (string) $cleaner['email'] );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'email', 'Add a valid email address for this cleaner first.' );
		}
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			if ( ! self::is_cleaner_user( $existing ) || self::user_cleaner( $existing->ID ) ) {
				return new \WP_Error( 'email_taken', 'That email address already belongs to another login on this website. Use a different email address for the cleaner.' );
			}
			$user = $existing;
		} else {
			$slug = 'cleaner-' . (int) $cleaner['id'] . '-' . strtolower( wp_generate_password( 6, false ) );
			$id   = wp_insert_user( [
				'user_login'           => $slug,
				'user_pass'            => wp_generate_password( 40, true, true ),
				'user_email'           => $email,
				'user_nicename'        => $slug,
				'display_name'         => Notify::first_name( (string) $cleaner['name'] ),
				'first_name'           => Notify::first_name( (string) $cleaner['name'] ),
				'role'                 => self::ROLE,
				'show_admin_bar_front' => 'false',
			] );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$user = get_userdata( $id );
			Events::log( 'cleaner', (int) $cleaner['id'], 'user_created', 'Login created for ' . $email );
		}
		Db::update( 'cleaners', (int) $cleaner['id'], [ 'user_id' => $user->ID ] );
		return $user;
	}

	/** Admin save: new cleaners can be invited straight away; email changes follow to the login. */
	public static function on_saved( $cleaner_id, $data = [] ): void {
		$cleaner = Cleaners::get( (int) $cleaner_id );
		if ( ! $cleaner ) {
			return;
		}
		if ( (int) $cleaner['user_id'] && ( $user = get_userdata( (int) $cleaner['user_id'] ) ) && strtolower( $user->user_email ) !== strtolower( (string) $cleaner['email'] ) ) {
			$other = get_user_by( 'email', (string) $cleaner['email'] );
			if ( $other && $other->ID !== $user->ID ) {
				Events::log( 'cleaner', (int) $cleaner_id, 'email_not_synced', 'Login email not changed: the new address belongs to another login' );
			} else {
				wp_update_user( [ 'ID' => $user->ID, 'user_email' => (string) $cleaner['email'] ] );
				Events::log( 'cleaner', (int) $cleaner_id, 'email_synced', 'Login email changed to ' . $cleaner['email'] );
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- runs inside Admin::handle() after check_admin_referer().
		if ( ! empty( $_POST['cc_send_invite'] ) && Plugin::can_manage() && 'none' === self::status( $cleaner ) ) {
			$result = Cleaner_Invites::send( (int) $cleaner_id );
			if ( is_wp_error( $result ) ) {
				Events::log( 'cleaner', (int) $cleaner_id, 'invite_failed', $result->get_error_message() );
			}
		}
	}

	/** What still stands between this cleaner and "Active". */
	public static function missing( int $cleaner_id ): array {
		$cleaner = Cleaners::get( $cleaner_id );
		if ( ! $cleaner ) {
			return [ 'Cleaner not found' ];
		}
		$account = self::account( $cleaner_id );
		$out     = [];
		if ( ! (int) $cleaner['user_id'] || in_array( self::status( $cleaner ), [ 'none', 'invited' ], true ) ) {
			$out[] = 'they haven\'t set up their login yet';
		}
		$todo = array_diff( array_keys( Cleaner_Setup::STEPS ), (array) $account['setup'] );
		if ( $todo ) {
			$out[] = 'setup steps not finished: ' . implode( ', ', array_map( fn( $s ) => Cleaner_Setup::STEPS[ $s ], $todo ) );
		}
		$current = Cleaner_Docs::current( $cleaner_id );
		foreach ( Cleaner_Docs::types() as $type => $def ) {
			if ( Cleaner_Docs::required( $type, $cleaner ) && 'approved' !== ( $current[ $type ]['status'] ?? '' ) ) {
				$out[] = $def['label'] . ' not approved';
			}
		}
		if ( empty( $account['terms']['accepted_at'] ) ) {
			$out[] = 'terms not agreed';
		}
		if ( '' === (string) $account['bank_last4'] ) {
			$out[] = 'no bank details';
		}
		return $out;
	}

	/** @return true|\WP_Error */
	public static function activate( int $cleaner_id, bool $force = false ) {
		$cleaner = Cleaners::get( $cleaner_id );
		if ( ! $cleaner ) {
			return new \WP_Error( 'missing', 'Cleaner not found.' );
		}
		if ( 'active' === self::status( $cleaner ) ) {
			return true;
		}
		$missing = self::missing( $cleaner_id );
		if ( $missing && ! $force ) {
			return new \WP_Error( 'not_ready', 'Not ready to activate: ' . implode( '; ', $missing ) . '.' );
		}
		self::update_account( $cleaner_id, [ 'approved_at' => Db::now(), 'approved_by' => get_current_user_id(), 'status_before' => '' ] );
		Db::update( 'cleaners', $cleaner_id, [ 'status' => 'active' ] );
		self::set_status( $cleaner_id, 'active', 'Account approved and active' . ( $missing ? ' (activated anyway: ' . implode( '; ', $missing ) . ')' : '' ) );
		if ( (int) $cleaner['user_id'] ) {
			Notify::send( 'cleaner_approved', (string) $cleaner['email'], self::vars( $cleaner ) + [ 'portal_url' => Cleaner_Portal::url() ], [ 'cleaner', $cleaner_id ] );
		}
		do_action( 'cc_cleaner_activated', $cleaner_id );
		return true;
	}

	/** Block login, end every session, revoke every signed link and close open offers. */
	public static function deactivate( int $cleaner_id, string $reason = '' ): bool {
		$cleaner = Cleaners::get( $cleaner_id );
		if ( ! $cleaner || 'deactivated' === self::status( $cleaner ) ) {
			return false;
		}
		self::update_account( $cleaner_id, [ 'status_before' => self::status( $cleaner ), 'deactivated_at' => Db::now(), 'invite_hash' => '', 'invite_expires' => null ] );
		Db::update( 'cleaners', $cleaner_id, [ 'status' => 'inactive' ] );
		self::set_status( $cleaner_id, 'deactivated', 'Account deactivated' . ( $reason ? ': ' . $reason : '' ) );
		self::revoke_access( $cleaner );

		foreach ( Db::all( 'job_offers', [ 'cleaner_id' => $cleaner_id, 'status' => 'offered' ] ) as $offer ) {
			Db::update( 'job_offers', (int) $offer['id'], [ 'status' => 'withdrawn' ] );
		}
		$jobs = Cleaners::jobs( $cleaner_id );
		if ( $jobs ) {
			Bookings::alert(
				sprintf( '%s was deactivated but still has %d upcoming job%s', $cleaner['name'], count( $jobs ), 1 === count( $jobs ) ? '' : 's' ),
				'Reassign these bookings: ' . implode( ', ', array_map( fn( $b ) => $b['ref'] . ' (' . Bookings::start_label( $b ) . ')', $jobs ) ) . '.',
				[]
			);
		}
		do_action( 'cc_cleaner_deactivated', $cleaner_id );
		return true;
	}

	/** Sessions, magic links, reset keys and every signed job link stop working. */
	public static function revoke_access( array $cleaner ): void {
		global $wpdb;
		$user_id = (int) $cleaner['user_id'];
		if ( $user_id ) {
			\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			delete_user_meta( $user_id, Cleaner_Auth::MAGIC_HASH );
			delete_user_meta( $user_id, Cleaner_Auth::MAGIC_EXPIRES );
			$wpdb->update( $wpdb->users, [ 'user_activation_key' => '' ], [ 'ID' => $user_id ] );
			clean_user_cache( $user_id );
		}
		Job_Links::revoke( 'cleaners', (int) $cleaner['id'] );
		foreach ( Db::all( 'job_offers', [ 'cleaner_id' => (int) $cleaner['id'] ] ) as $offer ) {
			Job_Links::revoke( 'job_offers', (int) $offer['id'] );
		}
	}

	public static function reactivate( int $cleaner_id ): bool {
		$cleaner = Cleaners::get( $cleaner_id );
		if ( ! $cleaner || 'deactivated' !== self::status( $cleaner ) ) {
			return false;
		}
		$account = self::account( $cleaner_id );
		$to      = (string) $account['status_before'];
		if ( 'invited' === $to || ! isset( self::STATUSES[ $to ] ) || 'deactivated' === $to ) {
			$to = (int) $cleaner['user_id'] && $account['setup'] ? 'setup' : 'none';
		}
		self::update_account( $cleaner_id, [ 'status_before' => '', 'deactivated_at' => null ] );
		Db::update( 'cleaners', $cleaner_id, [ 'status' => 'active' ] );
		self::set_status( $cleaner_id, $to, 'Account reactivated (' . self::label( $to ) . ')' );
		return true;
	}

	/** Common email variables for a cleaner. */
	public static function vars( array $cleaner ): array {
		return [
			'cleaner_first_name' => Notify::first_name( (string) $cleaner['name'] ),
			'cleaner_name'       => (string) $cleaner['name'],
			'admin_url'          => self::admin_url( (int) $cleaner['id'] ),
		];
	}

	public static function admin_url( int $cleaner_id ): string {
		return admin_url( 'admin.php?page=cc-bookings-cleaners&id=' . $cleaner_id );
	}

	/* ---------------------------------------------------------------------
	 * Lockout: no wp-admin, no admin bar, no author archives or REST listing
	 * ------------------------------------------------------------------ */

	public static function admin_bar( $show ) {
		return self::is_cleaner_user( wp_get_current_user() ) ? false : $show;
	}

	public static function block_admin(): void {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( self::is_cleaner_user( $user ) && ! user_can( $user, 'edit_posts' ) ) {
			wp_safe_redirect( Cleaner_Portal::url() );
			exit;
		}
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		return self::is_cleaner_user( $user ) ? Cleaner_Portal::url() : $redirect_to;
	}

	public static function block_author(): void {
		global $wp_query;
		if ( ! is_author() ) {
			return;
		}
		$author = get_queried_object();
		if ( self::is_cleaner_user( $author ) || ( ! $author && ( $id = (int) get_query_var( 'author' ) ) && self::is_cleaner_user( get_userdata( $id ) ) ) ) {
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
	 * Daily: document expiry reminders and retention
	 * ------------------------------------------------------------------ */

	public static function schedule_daily(): void {
		if ( (int) get_option( self::DAILY_OPTION ) > time() - DAY_IN_SECONDS || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::DAILY_HOOK, [], Queue::GROUP ) ) {
			as_schedule_recurring_action( time() + 600, DAY_IN_SECONDS, self::DAILY_HOOK, [], Queue::GROUP );
		}
		update_option( self::DAILY_OPTION, time(), true );
	}

	public static function daily(): void {
		Cleaner_Docs::reminders();
		self::retention();
	}

	/**
	 * UK GDPR: after deactivation, documents (and the profile photo) are deleted after retention_docs_days and bank
	 * details after retention_bank_days. 0 keeps them until deleted by hand.
	 *
	 * @return array{docs: int, bank: int}
	 */
	public static function retention( bool $dry_run = false ): array {
		$docs_days = (int) self::opt( 'retention_docs_days' );
		$bank_days = (int) self::opt( 'retention_bank_days' );
		$out       = [ 'docs' => 0, 'bank' => 0 ];
		foreach ( Db::all( 'cleaners', [ 'account_status' => 'deactivated' ] ) as $cleaner ) {
			$account = self::account( (int) $cleaner['id'] );
			$since   = Db::timestamp( $account['deactivated_at'] ?? null );
			if ( ! $since ) {
				continue;
			}
			if ( $docs_days && $since < time() - $docs_days * DAY_IN_SECONDS && Db::count( Cleaner_Docs::TABLE, [ 'cleaner_id' => (int) $cleaner['id'] ] ) ) {
				++$out['docs'];
				if ( ! $dry_run ) {
					Cleaner_Docs::purge( (int) $cleaner['id'] );
					Events::log( 'cleaner', (int) $cleaner['id'], 'retention', 'Documents and photo deleted under the retention policy' );
				}
			}
			if ( $bank_days && $since < time() - $bank_days * DAY_IN_SECONDS && ! empty( $account['bank'] ) ) {
				++$out['bank'];
				if ( ! $dry_run ) {
					self::update_account( (int) $cleaner['id'], [ 'bank' => null, 'bank_last4' => '', 'bank_updated_at' => null ] );
					Events::log( 'cleaner', (int) $cleaner['id'], 'retention', 'Bank details deleted under the retention policy' );
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Emails
	 * ------------------------------------------------------------------ */

	public static function email_defaults( array $templates ): array {
		if ( isset( $templates['cleaner_customer'] ) ) {
			$templates['cleaner_customer']['body'] = "Hi {first_name},\n\nGood news — {crew} will be cleaning for you on {date}.\n\n{cleaner_card}\n\nIf anything changes, reply to this email or call {phone}.";
		}
		return array_merge( $templates, [
			'cleaner_invite'          => [
				'label'      => 'Account invite (cleaner)',
				'to'         => 'cleaner',
				'subject'    => 'Set up your cleaner account with {brand}',
				'body'       => "Hi {cleaner_first_name},\n\nWelcome to {brand}! Please set up your cleaner account. It takes about 15 minutes and you'll need:\n\n• photo ID (passport or driving licence)\n• proof of your right to work in the UK\n• your DBS certificate\n• public liability insurance (if you're self-employed)\n• your bank details for pay\n• a clear photo of yourself for customers\n\nYour progress is saved as you go. This link works once and expires on {expires}.",
				'button'     => 'Set up my account',
				'button_url' => 'setup_url',
			],
			'cleaner_setup_submitted' => [
				'label'      => 'Cleaner finished account setup (admin)',
				'to'         => 'admin',
				'subject'    => '{cleaner_name} has finished account setup',
				'body'       => "{cleaner_name} has completed their account setup and agreed to the {terms_label} (version {terms_version}).\n\nPlease check their documents and approve the account so they can start getting jobs.",
				'button'     => 'Review the account',
				'button_url' => 'admin_url',
			],
			'cleaner_doc_rejected'    => [
				'label'      => 'Document needs re-uploading (cleaner)',
				'to'         => 'cleaner',
				'subject'    => 'Please upload your {doc} again',
				'body'       => "Hi {cleaner_first_name},\n\nWe couldn't accept the {doc} you uploaded:\n\n{reason}\n\nPlease log in and upload it again.",
				'button'     => 'Upload it again',
				'button_url' => 'portal_url',
			],
			'cleaner_approved'        => [
				'label'      => 'Account approved and active (cleaner)',
				'to'         => 'cleaner',
				'subject'    => 'You\'re all set — your cleaner account with {brand} is active',
				'body'       => "Hi {cleaner_first_name},\n\nGood news — we've checked your details and your account is now active. You'll start getting job offers by email and text, and you can see your jobs, availability and pay any time in your cleaner portal.",
				'button'     => 'Open my portal',
				'button_url' => 'portal_url',
			],
			'cleaner_magic_link'      => [
				'label'      => 'Login link (cleaner)',
				'to'         => 'cleaner',
				'subject'    => 'Your login link for {brand}',
				'body'       => "Hi {cleaner_first_name},\n\nTap the button to log in to your cleaner portal. The link works once and expires in {minutes} minutes.\n\nIf you didn't ask for this, you can ignore this email.",
				'button'     => 'Log in',
				'button_url' => 'login_url',
			],
			'cleaner_password_reset'  => [
				'label'      => 'Password reset (cleaner)',
				'to'         => 'cleaner',
				'subject'    => 'Reset your password for {brand}',
				'body'       => "Hi {cleaner_first_name},\n\nSomeone (hopefully you) asked to reset the password for your cleaner account. Tap the button to choose a new one. The link works once and expires in {minutes} minutes.\n\nIf you didn't ask for this, you can ignore this email — your password won't change.",
				'button'     => 'Choose a new password',
				'button_url' => 'reset_url',
			],
			'cleaner_doc_expiring'    => [
				'label'      => 'Document expiring soon (cleaner)',
				'to'         => 'cleaner',
				'subject'    => 'Your {doc} {expiry_phrase}',
				'body'       => "Hi {cleaner_first_name},\n\nYour {doc} {expiry_phrase} ({expires}). Please upload an up-to-date copy in your cleaner portal so you can keep getting jobs.",
				'button'     => 'Upload a new copy',
				'button_url' => 'portal_url',
			],
			'cleaner_doc_expiring_admin' => [
				'label'      => 'Cleaner document expiring soon (admin)',
				'to'         => 'admin',
				'subject'    => '{cleaner_name}: {doc} {expiry_phrase}',
				'body'       => "{cleaner_name}'s {doc} {expiry_phrase} ({expires}). They've been emailed to upload a new copy.",
				'button'     => 'Open the cleaner',
				'button_url' => 'admin_url',
			],
		] );
	}

	/** "Your cleaner is…" email: first names with approved photos of active cleaners. */
	public static function email_vars( $vars, $template, $object ) {
		if ( 'cleaner_customer' === $template && 'booking' === ( $object[0] ?? '' ) ) {
			$vars['cleaner_card'] = Cleaner_Docs::crew_card_html( (int) $object[1] ) ?: ' ';
		}
		return $vars;
	}
}

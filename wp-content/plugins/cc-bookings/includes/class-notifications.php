<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin notifications: anything a client or cleaner does (and every customer payment or failed charge) is recorded in
 * wp_cc_notifications under a category. Each admin user has their own read state (user meta): opening a category's
 * screen marks that category seen, opening a record marks its notifications read, and "Mark all as read" on Activity
 * clears everything. Unread counts show as WordPress-style bubbles on the Bookings menu and its submenus; they are cached
 * per user and invalidated when a notification is added or read. The office's own actions never create notifications.
 */
final class Notifications {
	const SCHEMA_VERSION = '2';
	const SCHEMA_OPTION  = 'cc_bookings_notifications_schema';
	const TABLE          = 'notifications';
	const STATE          = 'cc_bookings_notif_state';
	const GENERATION     = 'cc_bookings_notif_gen';

	/** category => [ label, admin page ]. */
	const CATEGORIES = [
		'quotes'           => [ 'Quotes', 'cc-bookings-quotes' ],
		'bookings'         => [ 'Bookings', 'cc-bookings-bookings' ],
		'plans'            => [ 'Regular plans', 'cc-bookings-plans' ],
		'clients'          => [ 'Clients', 'cc-bookings-clients' ],
		'cleaners'         => [ 'Cleaners', 'cc-bookings-cleaners' ],
		'cleaner_accounts' => [ 'Cleaner accounts', 'cc-bookings-cleaner-accounts' ],
	];

	/** Admin page => object type whose record (&id=) it shows. */
	const RECORD_PAGES = [
		'cc-bookings-quotes'   => 'quote',
		'cc-bookings-bookings' => 'booking',
		'cc-bookings-plans'    => 'plan',
		'cc-bookings-clients'  => 'client',
		'cc-bookings-cleaners' => 'cleaner',
	];

	/**
	 * "type:event" => [ category, what happened, recorded even from webhooks/background jobs ].
	 * Webhook and background payments are recorded; other events only when a client or cleaner did them.
	 */
	const RULES = [
		'quote:created'             => [ 'quotes', 'Asked for a bespoke quote', false ],
		'quote:client_amended'      => [ 'quotes', 'Changed their quote', false ],
		'quote:accepted_terms'      => [ 'quotes', 'Accepted the quote', false ],
		'quote:accept_requested'    => [ 'quotes', 'Accepted the quote (to pay by phone)', false ],
		'quote:question'            => [ 'quotes', 'Asked a question about their quote', false ],
		'quote:payment_refused'     => [ 'quotes', 'Paid for an out-of-date version of the quote (refund needed)', true ],
		'booking:confirmed'         => [ 'bookings', 'Paid', true ],
		'booking:topup_paid'        => [ 'bookings', 'Paid a top-up', true ],
		'booking:charge_failed'     => [ 'bookings', 'Payment failed', true ],
		'booking:rescheduled'       => [ 'bookings', 'Rescheduled their booking', false ],
		'booking:customer_cancelled' => [ 'bookings', 'Cancelled their booking', false ],
		'booking:reclean_claimed'   => [ 'bookings', 'Asked for a re-clean', false ],
		'booking:offer_accepted'    => [ 'cleaners', 'Accepted a job offer', false ],
		'booking:offer_declined'    => [ 'cleaners', 'Declined a job offer', false ],
		'booking:cleaner_dropped'   => [ 'cleaners', 'Can\'t make a job', false ],
		'booking:cleaner_completed' => [ 'cleaners', 'Marked a job complete', false ],
		'plan:plan_skip'            => [ 'plans', 'Skipped a visit', false ],
		'plan:plan_pause'           => [ 'plans', 'Paused their regular plan', false ],
		'plan:plan_resume'          => [ 'plans', 'Resumed their regular plan', false ],
		'plan:plan_frequency'       => [ 'plans', 'Changed how often', false ],
		'plan:plan_cancel'          => [ 'plans', 'Cancelled their regular plan', false ],
		'plan:plan_card'            => [ 'plans', 'Updated their card', false ],
		'client:profile_saved'      => [ 'clients', 'Updated their details', false ],
		'client:email_changed'      => [ 'clients', 'Changed their email address', false ],
		'client:delete_requested'   => [ 'clients', 'Asked for their account to be deleted', false ],
		'cleaner:availability'      => [ 'cleaners', 'Changed their availability', false ],
		'cleaner:time_off'          => [ 'cleaners', 'Changed their time off', false ],
		'cleaner:services'          => [ 'cleaners', 'Changed the services they offer', false ],
		'cleaner:details_updated'   => [ 'cleaners', 'Updated their contact details', false ],
		'cleaner:emergency'         => [ 'cleaners', 'Updated their emergency contact', false ],
		'cleaner:account_status'    => [ 'cleaner_accounts', 'Submitted their account for review', false ],
		'cleaner:doc_uploaded'      => [ 'cleaner_accounts', 'Uploaded a document', false ],
		'cleaner:bank_updated'      => [ 'cleaner_accounts', 'Updated their bank details', false ],
	];

	private static bool $background = false;

	public static function init(): void {
		self::maybe_install();
		add_action( 'cc_bookings_event', [ self::class, 'record' ], 10, 7 );
		add_action( 'action_scheduler_begin_execute', fn() => self::$background = true );
		add_action( 'action_scheduler_after_execute', fn() => self::$background = false );
		add_action( 'action_scheduler_failed_execution', fn() => self::$background = false );
		add_action( 'admin_menu', [ self::class, 'mark_from_request' ], 0 );
		add_action( 'admin_menu', [ self::class, 'badges' ], 999 );
	}

	/* ---------------------------------------------------------------------
	 * Schema
	 * ------------------------------------------------------------------ */

	public static function maybe_install(): void {
		if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION && get_option( Schema::OPTION ) ) {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( 'CREATE TABLE ' . Schema::table( self::TABLE ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
category varchar(32) NOT NULL DEFAULT '',
object_type varchar(20) NOT NULL DEFAULT '',
object_id bigint(20) unsigned NOT NULL DEFAULT 0,
event varchar(50) NOT NULL DEFAULT '',
summary varchar(255) NOT NULL DEFAULT '',
detail text NULL,
actor varchar(60) NOT NULL DEFAULT '',
actor_name varchar(200) NOT NULL DEFAULT '',
event_id bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY category_id (category,id),
KEY object (object_type,object_id)
) " . $wpdb->get_charset_collate() . ';' );
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		}
	}

	/* ---------------------------------------------------------------------
	 * Recording
	 * ------------------------------------------------------------------ */

	/** `cc_bookings_event`. */
	public static function record( $type, $id, $event, $message = '', $data = [], $actor = '', $event_id = 0 ): void {
		$rule = self::RULES[ $type . ':' . $event ] ?? null;
		if ( ! $rule || ! self::by_client_or_cleaner( (string) $actor, $rule[2] ) ) {
			return;
		}
		[ $category, $summary ] = $rule;
		$data                   = (array) $data;
		if ( 'quote:created' === $type . ':' . $event ) {
			$quote = Quotes::get( (int) $id );
			if ( empty( $data['reasons'] ) || ! $quote || (int) $quote['replaces_id'] ) {
				return; // Instant quotes aren't news; a customer's change is recorded as client_amended.
			}
		}
		if ( 'booking:confirmed' === $type . ':' . $event && ( ! empty( $data['reclean_of'] ) || in_array( $actor, [ 'manual', 'reclean' ], true ) ) ) {
			return;
		}
		if ( 'cleaner:account_status' === $type . ':' . $event && 'review' !== ( $data['to'] ?? '' ) ) {
			return;
		}
		if ( 'booking:confirmed' === $type . ':' . $event && preg_match( '/^Payment of (\S+)/', (string) $message, $m ) ) {
			$summary = 'Paid ' . $m[1];
		}

		Db::insert( self::TABLE, [
			'category'    => $category,
			'object_type' => (string) $type,
			'object_id'   => (int) $id,
			'event'       => (string) $event,
			'summary'     => $summary,
			'detail'      => mb_substr( (string) $message, 0, 2000 ),
			'actor'       => (string) $actor,
			'actor_name'  => mb_substr( self::actor_name( (string) $type, (int) $id, (string) $actor ), 0, 200 ),
			'event_id'    => (int) $event_id,
		] );
		update_option( self::GENERATION, (int) get_option( self::GENERATION, 0 ) + 1, true );
	}

	/** Not the office: a logged-out customer, a client or cleaner login, or (for payments) Stripe/background jobs. */
	private static function by_client_or_cleaner( string $actor, bool $system_ok ): bool {
		if ( is_user_logged_in() && Plugin::can_manage() ) {
			return false;
		}
		if ( 'manual' === $actor || 'admin' === $actor ) {
			return false;
		}
		if ( str_starts_with( $actor, 'user:' ) && ( user_can( (int) substr( $actor, 5 ), Plugin::CAP ) || user_can( (int) substr( $actor, 5 ), 'manage_options' ) ) ) {
			return false;
		}
		if ( $system_ok ) {
			return true;
		}
		if ( self::$background || wp_doing_cron() || in_array( $actor, [ 'system', 'cli', 'stripe', 'simulated' ], true ) ) {
			return false;
		}
		return true;
	}

	private static function actor_name( string $type, int $id, string $actor ): string {
		if ( preg_match( '/^cleaner:(\d+)$/', $actor, $m ) ) {
			return (string) ( Cleaners::get( (int) $m[1] )['name'] ?? '' );
		}
		if ( preg_match( '/^client:(\d+)$/', $actor, $m ) && 'client' !== $type ) {
			return (string) ( Client_Accounts::get( (int) $m[1] )['name'] ?? '' );
		}
		$object = self::object( $type, $id );
		if ( in_array( $type, [ 'cleaner', 'client' ], true ) ) {
			return (string) ( $object['name'] ?? '' );
		}
		return (string) ( $object['customer_name'] ?? '' );
	}

	private static function object( string $type, int $id ): ?array {
		switch ( $type ) {
			case 'quote':
				return Quotes::get( $id );
			case 'booking':
				return Bookings::get( $id );
			case 'plan':
				return Plans::get( $id );
			case 'client':
				return Client_Accounts::get( $id );
			case 'cleaner':
				return Cleaners::get( $id );
		}
		return null;
	}

	/** Reference and admin link for a notification's record. */
	public static function link( array $row ): array {
		$id     = (int) $row['object_id'];
		$object = self::object( (string) $row['object_type'], $id );
		switch ( $row['object_type'] ) {
			case 'quote':
				return [ $object['ref'] ?? 'Quote #' . $id, Quotes::admin_url( $id ) ];
			case 'booking':
				return [ $object['ref'] ?? 'Booking #' . $id, Bookings::admin_url( $id ) ];
			case 'plan':
				return [ $object['ref'] ?? 'Plan #' . $id, Plans::admin_url( $id ) ];
			case 'client':
				return [ $object['name'] ?? 'Client #' . $id, Client_Accounts::admin_url( $id ) ];
			case 'cleaner':
				return [ $object['name'] ?? 'Cleaner #' . $id, Cleaner_Accounts::admin_url( $id ) ];
		}
		return [ ucfirst( (string) $row['object_type'] ), '' ];
	}

	/* ---------------------------------------------------------------------
	 * Read state (per admin user)
	 * ------------------------------------------------------------------ */

	/** @return array{seen: array<string, int>, read: int[]} */
	public static function state( int $user_id = 0 ): array {
		$state = get_user_meta( $user_id ?: get_current_user_id(), self::STATE, true );
		$state = is_array( $state ) ? $state : [];
		return [ 'seen' => array_map( 'intval', (array) ( $state['seen'] ?? [] ) ), 'read' => array_map( 'intval', (array) ( $state['read'] ?? [] ) ) ];
	}

	private static function save_state( array $state ): void {
		$floor         = $state['seen'] && count( $state['seen'] ) === count( self::CATEGORIES ) ? min( $state['seen'] ) : 0;
		$state['read'] = array_values( array_unique( array_filter( $state['read'], fn( $id ) => $id > $floor ) ) );
		update_user_meta( get_current_user_id(), self::STATE, $state );
		delete_transient( self::cache_key() );
	}

	public static function is_unread( array $row, array $state ): bool {
		return (int) $row['id'] > ( $state['seen'][ $row['category'] ] ?? 0 ) && ! in_array( (int) $row['id'], $state['read'], true );
	}

	private static function max_id( string $category = '' ): int {
		global $wpdb;
		$table = Schema::table( self::TABLE );
		return (int) ( $category
			? $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$table} WHERE category = %s", $category ) ) // phpcs:ignore WordPress.DB.PreparedSQL
			: $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function mark_category_seen( string $category ): void {
		if ( ! isset( self::CATEGORIES[ $category ] ) || ! self::counts()[ $category ] ) {
			return;
		}
		$state                        = self::state();
		$state['seen'][ $category ]   = self::max_id( $category );
		self::save_state( $state );
	}

	public static function mark_object_read( string $type, int $id ): void {
		$state = self::state();
		$ids   = array_map( 'intval', array_column( Db::all( self::TABLE, [ 'object_type' => $type, 'object_id' => $id ], 'id DESC', 200 ), 'id' ) );
		$rows  = array_filter( Db::all( self::TABLE, [ 'id' => $ids ] ), fn( $row ) => self::is_unread( $row, $state ) );
		if ( $rows ) {
			$state['read'] = array_merge( $state['read'], array_map( fn( $row ) => (int) $row['id'], $rows ) );
			self::save_state( $state );
		}
	}

	public static function mark_all_read(): void {
		$state = [ 'seen' => [], 'read' => [] ];
		foreach ( array_keys( self::CATEGORIES ) as $category ) {
			$state['seen'][ $category ] = self::max_id( $category );
		}
		self::save_state( $state );
	}

	/** `admin_menu` (early, before the badges): the screen being opened marks its notifications read. */
	public static function mark_from_request(): void {
		if ( ! Plugin::can_manage() ) {
			return;
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$id   = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id && isset( self::RECORD_PAGES[ $page ] ) ) {
			self::mark_object_read( self::RECORD_PAGES[ $page ], $id );
			return;
		}
		foreach ( self::CATEGORIES as $category => [ , $slug ] ) {
			if ( $slug === $page && ! $id ) {
				self::mark_category_seen( $category );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Counts (cached per user)
	 * ------------------------------------------------------------------ */

	private static function cache_key(): string {
		return 'cc_notif_counts_' . get_current_user_id();
	}

	/** SQL condition matching this user's unread notifications. */
	public static function unread_sql( array $state ): array {
		$parts  = [];
		$params = [];
		foreach ( array_keys( self::CATEGORIES ) as $category ) {
			$parts[]  = '(category = %s AND id > %d)';
			$params[] = $category;
			$params[] = $state['seen'][ $category ] ?? 0;
		}
		$sql = '(' . implode( ' OR ', $parts ) . ')';
		if ( $state['read'] ) {
			$sql .= ' AND id NOT IN (' . implode( ',', array_map( 'intval', $state['read'] ) ) . ')';
		}
		return [ $sql, $params ];
	}

	/** @return array<string, int> Unread per category, plus "total". */
	public static function counts(): array {
		$generation = (int) get_option( self::GENERATION, 0 );
		$cached     = get_transient( self::cache_key() );
		if ( is_array( $cached ) && ( $cached['gen'] ?? -1 ) === $generation ) {
			return $cached['counts'];
		}
		global $wpdb;
		[ $sql, $params ] = self::unread_sql( self::state() );
		$rows             = $wpdb->get_results( $wpdb->prepare( 'SELECT category, COUNT(*) AS n FROM ' . Schema::table( self::TABLE ) . " WHERE {$sql} GROUP BY category", $params ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL
		$counts           = array_fill_keys( array_keys( self::CATEGORIES ), 0 );
		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row['category'] ] ) ) {
				$counts[ $row['category'] ] = (int) $row['n'];
			}
		}
		$counts['total'] = array_sum( $counts );
		set_transient( self::cache_key(), [ 'gen' => $generation, 'counts' => $counts ], DAY_IN_SECONDS );
		return $counts;
	}

	public static function bubble( int $count ): string {
		return $count ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>', $count, number_format_i18n( $count ) ) : '';
	}

	/** `admin_menu` (late): bubbles on Bookings and the submenus with unread notifications. */
	public static function badges(): void {
		global $menu, $submenu;
		if ( ! Plugin::can_manage() ) {
			return;
		}
		$counts = self::counts();
		$pages  = array_flip( array_map( fn( $c ) => $c[1], self::CATEGORIES ) );
		foreach ( (array) ( $submenu['cc-bookings'] ?? [] ) as $i => $item ) {
			$category = $pages[ $item[2] ] ?? null;
			if ( $category && $counts[ $category ] ) {
				$submenu['cc-bookings'][ $i ][0] .= self::bubble( $counts[ $category ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
		}
		foreach ( (array) $menu as $i => $item ) {
			if ( 'cc-bookings' === ( $item[2] ?? '' ) && $counts['total'] ) {
				$menu[ $i ][0] .= self::bubble( $counts['total'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
		}
	}
}

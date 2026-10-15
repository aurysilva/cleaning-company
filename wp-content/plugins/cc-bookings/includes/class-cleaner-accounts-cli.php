<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Cleaner account tools: `wp cc-bookings cleaners <command>`. Links printed here are live credentials — treat
 * them like passwords. Nothing here prints the encryption key or decrypted bank details.
 */
final class Cleaner_Accounts_Cli {
	private static function cleaner( string $id ): array {
		$cleaner = Cleaners::get( (int) $id );
		if ( ! $cleaner ) {
			\WP_CLI::error( 'Cleaner not found: ' . $id );
		}
		return $cleaner;
	}

	private static function user( array $cleaner ): \WP_User {
		$user = (int) $cleaner['user_id'] ? get_userdata( (int) $cleaner['user_id'] ) : null;
		if ( ! $user ) {
			\WP_CLI::error( 'This cleaner has no login yet. Send an invite first.' );
		}
		return $user;
	}

	/**
	 * Cleaners with their account status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json. Default table.
	 */
	public function list( array $args, array $assoc ): void {
		$rows = [];
		foreach ( Db::all( 'cleaners', [], 'id ASC' ) as $c ) {
			$account = Cleaner_Accounts::account( (int) $c['id'] );
			$rows[]  = [
				'id'       => $c['id'],
				'name'     => $c['name'],
				'email'    => $c['email'],
				'type'     => $c['type'],
				'status'   => $c['status'],
				'account'  => Cleaner_Accounts::status( $c ),
				'user'     => (int) $c['user_id'] ?: '',
				'steps'    => implode( ',', (array) $account['setup'] ),
				'eligible' => Cleaner_Accounts::eligible( $c ) ? 'yes' : 'no',
			];
		}
		$rows ? \WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array_keys( $rows[0] ) ) : \WP_CLI::log( 'No cleaners.' );
	}

	/**
	 * Add a cleaner (as the admin form does).
	 *
	 * ## OPTIONS
	 *
	 * --name=<name>
	 * : Full name.
	 *
	 * --email=<email>
	 * : Login email.
	 *
	 * [--phone=<phone>]
	 * : Mobile number.
	 *
	 * [--type=<type>]
	 * : employed or self_employed. Default self_employed.
	 *
	 * [--pay-rate=<pounds>]
	 * : Pay rate in pounds.
	 *
	 * [--pay-type=<type>]
	 * : hourly or per_job. Default hourly.
	 *
	 * [--invite]
	 * : Email an invite straight away.
	 */
	public function create( array $args, array $assoc ): void {
		$id = Cleaners::save( [
			'name'     => (string) $assoc['name'],
			'email'    => (string) $assoc['email'],
			'phone'    => (string) ( $assoc['phone'] ?? '' ),
			'type'     => (string) ( $assoc['type'] ?? 'self_employed' ),
			'status'   => 'active',
			'pay_type' => (string) ( $assoc['pay-type'] ?? 'hourly' ),
			'pay_rate' => (string) ( $assoc['pay-rate'] ?? '' ),
		] );
		if ( is_wp_error( $id ) ) {
			\WP_CLI::error( $id->get_error_message() );
		}
		\WP_CLI::success( 'Cleaner ' . $id . ' added (account: ' . Cleaner_Accounts::status( Cleaners::get( $id ) ) . ').' );
		if ( isset( $assoc['invite'] ) ) {
			$this->invite( [ (string) $id ], [] );
		}
	}

	/**
	 * Email an invite (a new link; earlier links stop working).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Cleaner id.
	 */
	public function invite( array $args, array $assoc ): void {
		$cleaner = self::cleaner( $args[0] );
		$result  = Cleaner_Invites::send( (int) $cleaner['id'] );
		is_wp_error( $result ) ? \WP_CLI::error( $result->get_error_message() ) : \WP_CLI::success( 'Invite emailed to ' . $cleaner['email'] . '.' );
	}

	/**
	 * Issue a new setup link without emailing it, and print it (earlier links stop working).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Cleaner id.
	 *
	 * @subcommand setup-link
	 */
	public function setup_link( array $args, array $assoc ): void {
		$cleaner = self::cleaner( $args[0] );
		$result  = Cleaner_Invites::send( (int) $cleaner['id'], false );
		is_wp_error( $result ) ? \WP_CLI::error( $result->get_error_message() ) : \WP_CLI::log( $result );
	}

	/**
	 * Account details, missing items and documents.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Cleaner id.
	 */
	public function status( array $args, array $assoc ): void {
		$c       = self::cleaner( $args[0] );
		$account = Cleaner_Accounts::account( (int) $c['id'] );
		\WP_CLI::log( sprintf( '%s <%s> · %s · cleaner %s · account %s · user %s · eligible for dispatch: %s', $c['name'], $c['email'], $c['type'], $c['status'], Cleaner_Accounts::status( $c ), (int) $c['user_id'] ?: '-', Cleaner_Accounts::eligible( $c ) ? 'yes' : 'no' ) );
		\WP_CLI::log( 'Setup steps done: ' . ( implode( ', ', (array) $account['setup'] ) ?: '-' ) );
		\WP_CLI::log( 'Bank: ' . ( Cleaner_Bank::masked( $account ) ?: '-' ) . ( $account['bank'] ? ' (stored encrypted, ' . strlen( (string) $account['bank'] ) . ' chars, prefix ' . substr( (string) $account['bank'], 0, 3 ) . ')' : '' ) );
		\WP_CLI::log( 'Terms: ' . ( $account['terms'] ? sprintf( '%s v%s at %s from %s', $account['terms']['type'] ?? '', $account['terms']['version'] ?? '', $account['terms']['accepted_at'] ?? '', $account['terms']['ip'] ?? '' ) : '-' ) );
		$missing = Cleaner_Accounts::missing( (int) $c['id'] );
		\WP_CLI::log( 'Missing for activation: ' . ( $missing ? implode( '; ', $missing ) : 'nothing' ) );
		$rows = [];
		foreach ( Db::all( Cleaner_Docs::TABLE, [ 'cleaner_id' => (int) $c['id'] ], 'id ASC' ) as $d ) {
			$rows[] = [ 'doc' => $d['id'], 'type' => $d['type'], 'status' => $d['status'], 'mime' => $d['mime'], 'size' => $d['size'], 'expires' => $d['expires_on'] ?: '', 'file' => $d['file'] ? 'yes' : 'no', 'url' => Cleaner_Docs::url( $d ) ];
		}
		$rows ? \WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) ) : \WP_CLI::log( 'No documents.' );
	}

	/**
	 * Print a single-use login link (as "Email me a login link" sends).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Cleaner id.
	 *
	 * @subcommand login-link
	 */
	public function login_link( array $args, array $assoc ): void {
		$user = self::user( self::cleaner( $args[0] ) );
		if ( ! Cleaner_Auth::can_log_in( $user ) ) {
			\WP_CLI::error( 'This account can\'t log in in its current status.' );
		}
		\WP_CLI::log( Cleaner_Auth::magic_url( Cleaner_Auth::issue_magic( $user ) ) );
	}

	/**
	 * Print a password reset link (earlier reset links stop working).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Cleaner id.
	 *
	 * @subcommand reset-link
	 */
	public function reset_link( array $args, array $assoc ): void {
		$url = Cleaner_Auth::reset_url( self::user( self::cleaner( $args[0] ) ) );
		$url ? \WP_CLI::log( $url ) : \WP_CLI::error( 'Could not create a reset key.' );
	}

	/**
	 * Approve a document.
	 *
	 * ## OPTIONS
	 *
	 * <doc>
	 * : Document id.
	 *
	 * [--all]
	 * : Treat <doc> as a cleaner id and approve all their pending documents.
	 *
	 * @subcommand approve-doc
	 */
	public function approve_doc( array $args, array $assoc ): void {
		$ids = isset( $assoc['all'] ) ? array_column( Db::all( Cleaner_Docs::TABLE, [ 'cleaner_id' => (int) $args[0], 'status' => 'pending' ] ), 'id' ) : [ (int) $args[0] ];
		foreach ( $ids as $id ) {
			Cleaner_Docs::approve( (int) $id ) ? \WP_CLI::success( 'Approved document ' . $id ) : \WP_CLI::warning( 'Could not approve document ' . $id );
		}
	}

	/**
	 * Reject a document (the cleaner is emailed to upload it again).
	 *
	 * ## OPTIONS
	 *
	 * <doc>
	 * : Document id.
	 *
	 * --reason=<reason>
	 * : Shown to the cleaner in the email.
	 *
	 * @subcommand reject-doc
	 */
	public function reject_doc( array $args, array $assoc ): void {
		$result = Cleaner_Docs::reject( (int) $args[0], (string) ( $assoc['reason'] ?? '' ) );
		is_wp_error( $result ) ? \WP_CLI::error( $result->get_error_message() ) : \WP_CLI::success( 'Rejected.' );
	}

	/**
	 * Set or clear a document's expiry date.
	 *
	 * ## OPTIONS
	 *
	 * <doc>
	 * : Document id.
	 *
	 * [<date>]
	 * : YYYY-MM-DD; omit to clear.
	 *
	 * @subcommand doc-expiry
	 */
	public function doc_expiry( array $args, array $assoc ): void {
		$result = Cleaner_Docs::set_expiry( (int) $args[0], (string) ( $args[1] ?? '' ) );
		is_wp_error( $result ) ? \WP_CLI::error( $result->get_error_message() ) : \WP_CLI::success( 'Saved.' );
	}

	/**
	 * Activate an account.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Cleaner id.
	 *
	 * [--force]
	 * : Activate even if items are missing.
	 */
	public function activate( array $args, array $assoc ): void {
		$result = Cleaner_Accounts::activate( (int) self::cleaner( $args[0] )['id'], isset( $assoc['force'] ) );
		is_wp_error( $result ) ? \WP_CLI::error( $result->get_error_message() ) : \WP_CLI::success( 'Active.' );
	}

	/**
	 * Deactivate an account (blocks login, ends sessions, revokes links, withdraws open offers).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Cleaner id.
	 *
	 * [--reason=<reason>]
	 * : Internal note for the event log.
	 */
	public function deactivate( array $args, array $assoc ): void {
		Cleaner_Accounts::deactivate( (int) self::cleaner( $args[0] )['id'], (string) ( $assoc['reason'] ?? '' ) ) ? \WP_CLI::success( 'Deactivated.' ) : \WP_CLI::warning( 'Already deactivated.' );
	}

	/**
	 * Reactivate a deactivated account.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Cleaner id.
	 */
	public function reactivate( array $args, array $assoc ): void {
		Cleaner_Accounts::reactivate( (int) self::cleaner( $args[0] )['id'] ) ? \WP_CLI::success( 'Reactivated.' ) : \WP_CLI::warning( 'Not deactivated.' );
	}

	/**
	 * Send due document-expiry reminders now (normally daily).
	 */
	public function reminders( array $args, array $assoc ): void {
		\WP_CLI::success( Cleaner_Docs::reminders() . ' email(s) sent.' );
	}

	/**
	 * Apply the retention policy to deactivated cleaners now (normally daily).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only list what would be removed.
	 */
	public function retention( array $args, array $assoc ): void {
		$out = Cleaner_Accounts::retention( isset( $assoc['dry-run'] ) );
		\WP_CLI::success( sprintf( '%s documents for %d cleaner(s), bank details for %d.', isset( $assoc['dry-run'] ) ? 'Would delete' : 'Deleted', $out['docs'], $out['bank'] ) );
	}

	/**
	 * Check the bank-details encryption key works (never prints the key).
	 *
	 * @subcommand check-encryption
	 */
	public function check_encryption( array $args, array $assoc ): void {
		if ( ! Cleaner_Bank::ready() ) {
			\WP_CLI::error( 'CC_BOOKINGS_ENC_KEY is ' . ( defined( 'CC_BOOKINGS_ENC_KEY' ) ? 'defined but not a valid 32-byte key.' : 'not defined in wp-config.php.' ) );
		}
		$box = Cleaner_Bank::encrypt( 'round-trip test' );
		'round-trip test' === Cleaner_Bank::decrypt( $box ) ? \WP_CLI::success( 'Key present and working (libsodium secretbox round trip OK).' ) : \WP_CLI::error( 'Round trip failed.' );
	}

	/**
	 * Delete test cleaners named "CL Test…" with their logins, documents, availability, offers and events,
	 * and test quotes/bookings whose customer is named "CL Test…".
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Actually delete (otherwise only lists what would go).
	 *
	 * @subcommand purge-test
	 */
	public function purge_test( array $args, array $assoc ): void {
		global $wpdb;
		$go       = isset( $assoc['yes'] );
		$cleaners = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'cleaners' ) . " WHERE name LIKE 'CL Test%'", ARRAY_A ) ?: [];
		$bookings = $wpdb->get_results( 'SELECT id, ref, quote_id FROM ' . Schema::table( 'bookings' ) . " WHERE customer_name LIKE 'CL Test%'", ARRAY_A ) ?: [];
		$quotes   = $wpdb->get_col( 'SELECT id FROM ' . Schema::table( 'quotes' ) . " WHERE customer_name LIKE 'CL Test%'" ) ?: [];
		\WP_CLI::log( sprintf( 'Test cleaners: %s', implode( ', ', array_map( fn( $c ) => $c['id'] . ' ' . $c['name'], $cleaners ) ) ?: 'none' ) );
		\WP_CLI::log( sprintf( 'Test bookings: %s; quotes: %s', implode( ', ', array_column( $bookings, 'ref' ) ) ?: 'none', implode( ', ', $quotes ) ?: 'none' ) );
		if ( ! $go ) {
			\WP_CLI::log( 'Dry run. Add --yes to delete.' );
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $cleaners as $c ) {
			$id = (int) $c['id'];
			Cleaner_Docs::purge( $id );
			Db::delete( Cleaner_Accounts::TABLE, [ 'cleaner_id' => $id ] );
			Db::delete( 'cleaner_availability', [ 'cleaner_id' => $id ] );
			foreach ( Db::all( 'job_offers', [ 'cleaner_id' => $id ] ) as $offer ) {
				Db::delete( 'events', [ 'object_type' => 'offer', 'object_id' => (int) $offer['id'] ] );
			}
			Db::delete( 'job_offers', [ 'cleaner_id' => $id ] );
			Db::delete( 'events', [ 'object_type' => 'cleaner', 'object_id' => $id ] );
			$user = (int) $c['user_id'] ? get_userdata( (int) $c['user_id'] ) : null;
			if ( $user && Cleaner_Accounts::is_cleaner_user( $user ) && ! user_can( $user, 'edit_posts' ) ) {
				wp_delete_user( $user->ID );
			}
			Db::delete( 'cleaners', [ 'id' => $id ] );
			\WP_CLI::log( 'Deleted cleaner ' . $id );
		}
		// Child rows in any plugin table with a booking_id / quote_id column (dispatch, payments, aftercare, plans…).
		$children = [ 'booking_id' => [], 'quote_id' => [] ];
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'cc_' ) . '%' ) ) as $table ) {
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( array_keys( $children ) as $column ) {
				if ( in_array( $column, $columns, true ) && ! in_array( $table, [ Schema::table( 'bookings' ), Schema::table( 'quotes' ) ], true ) ) {
					$children[ $column ][] = $table;
				}
			}
		}
		foreach ( $bookings as $b ) {
			$bid = (int) $b['id'];
			foreach ( $children['booking_id'] as $table ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE booking_id = %d", $bid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			Db::delete( 'events', [ 'object_type' => 'booking', 'object_id' => $bid ] );
			Db::delete( 'bookings', [ 'id' => $bid ] );
			self::cancel_jobs( [ Reminders::HOOK, Aftercare::REVIEW_HOOK, Dispatch::RUN_HOOK, Dispatch::TIMEOUT_HOOK ], $bid );
			Files::delete_for( (string) $b['ref'] );
			\WP_CLI::log( 'Deleted booking ' . $b['ref'] );
		}
		foreach ( array_map( 'intval', $quotes ) as $qid ) {
			$quote = Quotes::get( $qid );
			foreach ( $children['quote_id'] as $table ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE quote_id = %d", $qid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			Db::delete( 'events', [ 'object_type' => 'quote', 'object_id' => $qid ] );
			Db::delete( 'quotes', [ 'id' => $qid ] );
			if ( $quote ) {
				Files::delete_for( (string) $quote['ref'] );
			}
			Queue::cancel( 'cc_bookings_quote_followup', [ $qid, 'reminder' ] );
			Queue::cancel( 'cc_bookings_quote_followup', [ $qid, 'expiring' ] );
			Queue::cancel( 'cc_bookings_quote_expire', [ $qid ] );
			self::cancel_jobs( [ 'cc_bookings_hold_expire' ], $qid );
			\WP_CLI::log( 'Deleted quote ' . $qid );
		}
		// Office alerts about test cleaners/bookings aren't tied to an object id.
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'events' ) . " WHERE object_type = 'system' AND message LIKE '%CL Test%'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		Availability::flush();
		\WP_CLI::success( 'Test data removed.' );
	}

	/** Cancel pending background jobs whose first argument is $id. */
	private static function cancel_jobs( array $hooks, int $id ): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}
		foreach ( $hooks as $hook ) {
			foreach ( as_get_scheduled_actions( [ 'hook' => $hook, 'status' => 'pending', 'group' => Queue::GROUP, 'per_page' => -1 ] ) as $action ) {
				$args = (array) $action->get_args();
				if ( (int) ( $args[0] ?? 0 ) === $id ) {
					Queue::cancel( $hook, $args );
				}
			}
		}
	}
}

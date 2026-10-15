<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Client account tools: `wp cc-bookings clients <command>`. Links printed here are live credentials — treat them
 * like passwords.
 */
final class Client_Accounts_Cli {
	private static function client( string $id_or_email ): array {
		$client = is_email( $id_or_email ) ? Client_Accounts::by_email( $id_or_email ) : Client_Accounts::get( (int) $id_or_email );
		if ( ! $client ) {
			\WP_CLI::error( 'Client not found: ' . $id_or_email );
		}
		return $client;
	}

	/**
	 * Client accounts.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json. Default table.
	 */
	public function list( array $args, array $assoc ): void {
		$rows = [];
		foreach ( Db::all( Client_Accounts::TABLE, [], 'id ASC' ) as $c ) {
			$rows[] = [
				'id'       => $c['id'],
				'name'     => $c['name'],
				'email'    => $c['email'],
				'status'   => $c['status'],
				'user'     => (int) $c['user_id'] ?: '',
				'password' => (int) $c['password_set'] ? 'yes' : 'no',
				'quotes'   => Db::count( 'quotes', [ 'client_id' => (int) $c['id'] ] ),
				'bookings' => Db::count( 'bookings', [ 'client_id' => (int) $c['id'] ] ),
				'plans'    => Db::count( Recurring::TABLE, [ 'client_id' => (int) $c['id'] ] ),
				'test'     => (int) $c['is_test'] ? 'yes' : '',
			];
		}
		$rows ? \WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array_keys( $rows[0] ) ) : \WP_CLI::log( 'No clients.' );
	}

	/**
	 * Link existing quotes, bookings and plans to client accounts by email address (creating accounts as needed).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Actually do it (otherwise only counts).
	 */
	public function backfill( array $args, array $assoc ): void {
		global $wpdb;
		$emails = $wpdb->get_col( 'SELECT DISTINCT email FROM ' . Schema::table( 'quotes' ) . " WHERE client_id = 0 AND email <> ''" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL
		\WP_CLI::log( count( $emails ) . ' email address(es) with unlinked quotes.' );
		if ( ! isset( $assoc['yes'] ) ) {
			\WP_CLI::log( 'Dry run. Add --yes to link them.' );
			return;
		}
		foreach ( $emails as $email ) {
			$quote = Db::find( 'quotes', [ 'email' => $email, 'client_id' => 0 ] );
			if ( $quote ) {
				Client_Accounts::quote_created( (int) $quote['id'] );
			}
		}
		\WP_CLI::success( 'Done.' );
	}

	/**
	 * Print a single-use login link (as "Email me a login link" sends).
	 *
	 * ## OPTIONS
	 *
	 * <client>
	 * : Client id or email.
	 *
	 * @subcommand login-link
	 */
	public function login_link( array $args, array $assoc ): void {
		$client = self::client( $args[0] );
		if ( ! Client_Accounts::portal_client( (int) $client['id'] ) ) {
			\WP_CLI::error( 'This client can\'t log in.' );
		}
		$raw = Client_Auth::issue_link( (int) $client['id'], 'login', max( 1, (int) Client_Accounts::opt( 'magic_minutes' ) ) * MINUTE_IN_SECONDS );
		\WP_CLI::log( Client_Auth::magic_url( $raw ) );
	}

	/**
	 * Delete test clients named "CP Test…" with their logins and links, and their quotes, bookings and plans
	 * (customer name "CP Test…").
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
		$clients  = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( Client_Accounts::TABLE ) . " WHERE name LIKE 'CP Test%'", ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL
		$bookings = $wpdb->get_results( 'SELECT id, ref FROM ' . Schema::table( 'bookings' ) . " WHERE customer_name LIKE 'CP Test%'", ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL
		$quotes   = $wpdb->get_col( 'SELECT id FROM ' . Schema::table( 'quotes' ) . " WHERE customer_name LIKE 'CP Test%'" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL
		$plans    = $wpdb->get_col( 'SELECT id FROM ' . Schema::table( Recurring::TABLE ) . " WHERE customer_name LIKE 'CP Test%'" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL
		\WP_CLI::log( 'Test clients: ' . ( implode( ', ', array_map( fn( $c ) => $c['id'] . ' ' . $c['name'] . ' <' . $c['email'] . '>', $clients ) ) ?: 'none' ) );
		\WP_CLI::log( sprintf( 'Test bookings: %s; quotes: %s; plans: %s', implode( ', ', array_column( $bookings, 'ref' ) ) ?: 'none', implode( ', ', $quotes ) ?: 'none', implode( ', ', $plans ) ?: 'none' ) );
		if ( ! $go ) {
			\WP_CLI::log( 'Dry run. Add --yes to delete.' );
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';

		// Child rows in any plugin table with a booking_id / quote_id column (payments, dispatch, aftercare, plans, links…).
		$children = [ 'booking_id' => [], 'quote_id' => [] ];
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'cc_' ) . '%' ) ) as $table ) {
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( array_keys( $children ) as $column ) {
				if ( in_array( $column, $columns, true ) && ! in_array( $table, [ Schema::table( 'bookings' ), Schema::table( 'quotes' ), Schema::table( Recurring::TABLE ) ], true ) ) {
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
			self::cancel_jobs( [ Reminders::HOOK, Aftercare::REVIEW_HOOK, Dispatch::RUN_HOOK, Dispatch::TIMEOUT_HOOK, Plan_Charges::CHARGE_HOOK, Plan_Charges::RELEASE_HOOK ], $bid );
			Files::delete_for( (string) $b['ref'] );
			\WP_CLI::log( 'Deleted booking ' . $b['ref'] );
		}
		foreach ( array_map( 'intval', $plans ) as $pid ) {
			Db::delete( 'events', [ 'object_type' => 'plan', 'object_id' => $pid ] );
			Db::delete( Recurring::TABLE, [ 'id' => $pid ] );
			\WP_CLI::log( 'Deleted plan ' . $pid );
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
		foreach ( $clients as $c ) {
			$id = (int) $c['id'];
			Db::delete( Client_Accounts::LINKS, [ 'client_id' => $id ] );
			Db::delete( 'events', [ 'object_type' => 'client', 'object_id' => $id ] );
			$user = (int) $c['user_id'] ? get_userdata( (int) $c['user_id'] ) : null;
			if ( $user && Client_Accounts::is_client_user( $user ) && ! user_can( $user, 'edit_posts' ) ) {
				wp_delete_user( $user->ID );
			}
			Db::delete( Client_Accounts::TABLE, [ 'id' => $id ] );
			\WP_CLI::log( 'Deleted client ' . $id );
		}
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'events' ) . " WHERE object_type = 'system' AND message LIKE '%CP Test%'" ); // phpcs:ignore WordPress.DB.PreparedSQL
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

<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 4 tools: `wp cc-bookings recurring <command>`. Charges, failures, pay links, webhooks and card updates
 * can be simulated; simulations never call Stripe and run the same code as the real outcomes.
 */
final class Recurring_Cli {
	private function plan( string $ref ): array {
		$plan = Plans::by_ref( $ref );
		if ( ! $plan ) {
			\WP_CLI::error( 'Plan not found: ' . $ref );
		}
		return $plan;
	}

	private function visit( string $ref ): array {
		$visit = ctype_digit( $ref ) ? Bookings::get( (int) $ref ) : Bookings::by_ref( $ref );
		if ( ! $visit || ! (int) $visit['series_id'] ) {
			\WP_CLI::error( 'Plan visit not found: ' . $ref );
		}
		return $visit;
	}

	private function done( $result, string $success = '' ): void {
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( is_array( $result ) ) {
			\WP_CLI::log( sprintf( '%s: %s', $result['status'], $result['message'] ) );
			return;
		}
		\WP_CLI::success( $success ?: (string) $result );
	}

	/** Settings, schema and counts. */
	public function status(): void {
		$rows = [];
		foreach ( Recurring::DEFAULTS as $key => $default ) {
			$rows[] = [ 'item' => $key, 'value' => wp_json_encode( Recurring::setting( $key ) ) ];
		}
		$rows[] = [ 'item' => 'plans table schema', 'value' => (string) get_option( Recurring::SCHEMA_OPTION ) ];
		foreach ( Recurring::PLAN_STATUSES as $status => $label ) {
			$rows[] = [ 'item' => 'plans ' . $status, 'value' => (string) Db::count( Recurring::TABLE, [ 'status' => $status ] ) ];
		}
		$rows[] = [ 'item' => 'tick queued', 'value' => Queue::next( Plans::TICK_HOOK ) ? wp_date( 'Y-m-d H:i', Queue::next( Plans::TICK_HOOK ) ) : 'no' ];
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'item', 'value' ] );
	}

	/**
	 * List plans.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : active, paused or cancelled.
	 *
	 * @subcommand list
	 */
	public function list_( array $args, array $assoc ): void {
		$rows = [];
		foreach ( Db::all( Recurring::TABLE, isset( $assoc['status'] ) ? [ 'status' => $assoc['status'] ] : [], 'id DESC', 100 ) as $plan ) {
			$rows[] = [ 'ref' => $plan['ref'], 'status' => $plan['status'], 'frequency' => $plan['frequency'], 'per_visit' => Money::format( (int) $plan['price_pence'] ), 'customer' => $plan['customer_name'], 'next_date' => $plan['next_date'], 'card' => $plan['card_label'] ];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'ref', 'status', 'frequency', 'per_visit', 'customer', 'next_date', 'card' ] );
	}

	/**
	 * Plan details and visits.
	 *
	 * ## OPTIONS
	 *
	 * <plan>
	 * : Plan ref (P-12345) or id.
	 */
	public function show( array $args ): void {
		$plan = $this->plan( $args[0] );
		foreach ( Plans::details_lines( $plan ) as [ $label, $value ] ) {
			\WP_CLI::log( str_pad( $label, 16 ) . str_replace( "\n", ' | ', (string) $value ) );
		}
		\WP_CLI::log( sprintf( 'status %s · provider %s · customer %s · payment method %s · next_date %s', $plan['status'], $plan['provider'], $plan['stripe_customer'] ?: '-', $plan['stripe_payment_method'] ?: '-', $plan['next_date'] ) );
		$rows = [];
		foreach ( Plans::visits( (int) $plan['id'] ) as $v ) {
			$rows[] = [
				'ref'     => $v['ref'],
				'when'    => Bookings::start_label( $v ),
				'status'  => $v['status'],
				'payment' => $v['payment_status'] . ' ' . Money::format( (int) $v['paid_pence'] ) . '/' . Money::format( (int) $v['total_pence'] ),
				'charge'  => ( $t = Queue::next( Plan_Charges::CHARGE_HOOK, [ (int) $v['id'] ] ) ) ? wp_date( 'D j M H:i', $t ) : '-',
				'release' => ( $t = Queue::next( Plan_Charges::RELEASE_HOOK, [ (int) $v['id'] ] ) ) ? wp_date( 'D j M H:i', $t ) : '-',
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'ref', 'when', 'status', 'payment', 'charge', 'release' ] );
	}

	/**
	 * Top up a plan's visits (or run the background tick for all plans).
	 *
	 * ## OPTIONS
	 *
	 * [<plan>]
	 * : Plan ref. Omit to run the tick.
	 */
	public function generate( array $args ): void {
		if ( isset( $args[0] ) ) {
			$plan = $this->plan( $args[0] );
			\WP_CLI::success( Plans::generate( (int) $plan['id'] ) . ' visit(s) created.' );
			return;
		}
		Plans::tick();
		\WP_CLI::success( 'Tick run.' );
	}

	/**
	 * Charge a visit now with the plan's saved card (simulated plans never call Stripe).
	 *
	 * ## OPTIONS
	 *
	 * <visit>
	 * : Visit booking ref.
	 */
	public function charge( array $args ): void {
		$this->done( Plan_Charges::charge( (int) $this->visit( $args[0] )['id'], 'cli' ) );
	}

	/**
	 * Run a visit's off-session charge with a forced simulated outcome (no Stripe call).
	 *
	 * ## OPTIONS
	 *
	 * <visit>
	 * : Visit booking ref.
	 *
	 * --result=<result>
	 * : success or fail.
	 *
	 * [--code=<code>]
	 * : Failure code, e.g. card_declined, authentication_required, expired_card.
	 *
	 * @subcommand simulate-charge
	 */
	public function simulate_charge( array $args, array $assoc ): void {
		$result = (string) ( $assoc['result'] ?? '' );
		if ( ! in_array( $result, [ 'success', 'fail' ], true ) ) {
			\WP_CLI::error( '--result must be success or fail.' );
		}
		$this->done( Plan_Charges::charge( (int) $this->visit( $args[0] )['id'], 'cli', $result, (string) ( $assoc['code'] ?? 'card_declined' ) ) );
	}

	/**
	 * Simulate the customer paying a payment-due visit through the pay link (checkout.session.completed).
	 *
	 * ## OPTIONS
	 *
	 * <visit>
	 * : Visit booking ref.
	 *
	 * @subcommand simulate-pay-link
	 */
	public function simulate_pay_link( array $args ): void {
		$visit = $this->visit( $args[0] );
		$id    = 'cs_sim_visit_' . $visit['id'] . '_' . time();
		Db::insert( 'payments', [ 'booking_id' => (int) $visit['id'], 'type' => 'charge', 'status' => 'pending', 'provider' => 'simulated', 'amount_pence' => (int) $visit['total_pence'], 'stripe_session_id' => $id, 'note' => 'Regular visit: pay link (simulated)' ] );
		$this->done( Stripe::handle_event( [
			'id'   => 'evt_sim_' . time(),
			'type' => 'checkout.session.completed',
			'data' => [ 'object' => [
				'id'             => $id,
				'object'         => 'checkout.session',
				'mode'           => 'payment',
				'payment_status' => 'paid',
				'amount_total'   => (int) $visit['total_pence'],
				'currency'       => 'gbp',
				'customer'       => 'cus_sim_paylink_' . $visit['id'],
				'payment_intent' => 'pi_sim_paylink_' . $visit['id'] . '_' . time(),
				'metadata'       => [ 'type' => 'visit', 'source' => 'pay_link', 'visit_id' => (string) $visit['id'], 'visit_ref' => $visit['ref'], 'plan_id' => (string) $visit['series_id'] ],
			] ],
		] ) );
	}

	/**
	 * Feed a payment_intent.succeeded / payment_failed event for a visit through the webhook handler.
	 *
	 * ## OPTIONS
	 *
	 * <visit>
	 * : Visit booking ref.
	 *
	 * --event=<event>
	 * : succeeded or failed.
	 *
	 * [--code=<code>]
	 * : Failure code (failed only).
	 *
	 * @subcommand simulate-webhook
	 */
	public function simulate_webhook( array $args, array $assoc ): void {
		$visit = $this->visit( $args[0] );
		$ok    = 'succeeded' === ( $assoc['event'] ?? '' );
		$code  = (string) ( $assoc['code'] ?? 'card_declined' );
		$this->done( Stripe::handle_event( [
			'id'   => 'evt_sim_' . time(),
			'type' => $ok ? 'payment_intent.succeeded' : 'payment_intent.payment_failed',
			'data' => [ 'object' => array_filter( [
				'id'                 => 'pi_sim_wh_' . $visit['id'] . '_' . time(),
				'object'             => 'payment_intent',
				'amount'             => (int) $visit['total_pence'],
				'amount_received'    => $ok ? (int) $visit['total_pence'] : 0,
				'status'             => $ok ? 'succeeded' : 'requires_payment_method',
				'last_payment_error' => $ok ? null : [ 'code' => $code, 'message' => Plan_Charges::DECLINE_REASONS[ $code ] ?? 'Declined' ],
				'metadata'           => [ 'type' => 'visit', 'source' => 'off_session', 'visit_id' => (string) $visit['id'], 'visit_ref' => $visit['ref'], 'plan_id' => (string) $visit['series_id'] ],
			], fn( $v ) => null !== $v ) ],
		] ) );
	}

	/**
	 * Simulate a completed card-update (setup mode) checkout for a plan.
	 *
	 * ## OPTIONS
	 *
	 * <plan>
	 * : Plan ref.
	 *
	 * @subcommand simulate-card-update
	 */
	public function simulate_card_update( array $args ): void {
		$plan = $this->plan( $args[0] );
		$this->done( Stripe::handle_event( [
			'id'   => 'evt_sim_' . time(),
			'type' => 'checkout.session.completed',
			'data' => [ 'object' => [
				'id'           => 'cs_sim_setup_' . $plan['id'] . '_' . time(),
				'object'       => 'checkout.session',
				'mode'         => 'setup',
				'status'       => 'complete',
				'customer'     => $plan['stripe_customer'],
				'setup_intent' => 'seti_sim_' . $plan['id'] . '_' . time(),
				'metadata'     => [ 'type' => 'card_update', 'plan_id' => (string) $plan['id'], 'plan_ref' => $plan['ref'] ],
			] ],
		] ) );
	}

	/**
	 * Release an unpaid (payment due) visit now.
	 *
	 * ## OPTIONS
	 *
	 * <visit>
	 * : Visit booking ref.
	 *
	 * [--force]
	 * : Don't wait for the cutoff.
	 */
	public function release( array $args, array $assoc ): void {
		$this->done( Plan_Charges::release( (int) $this->visit( $args[0] )['id'], isset( $assoc['force'] ) ) );
	}

	/**
	 * Skip a visit (as the customer).
	 *
	 * ## OPTIONS
	 *
	 * <visit>
	 * : Visit booking ref.
	 */
	public function skip( array $args ): void {
		$visit = $this->visit( $args[0] );
		$this->done( Plans::skip( (int) $visit['series_id'], (int) $visit['id'] ), 'Skipped ' . $visit['ref'] );
	}

	/**
	 * Pause a plan (as the customer).
	 *
	 * ## OPTIONS
	 *
	 * <plan>
	 * : Plan ref.
	 */
	public function pause( array $args ): void {
		$this->done( Plans::pause( (int) $this->plan( $args[0] )['id'] ), 'Paused.' );
	}

	/**
	 * Resume a paused plan (as the customer).
	 *
	 * ## OPTIONS
	 *
	 * <plan>
	 * : Plan ref.
	 */
	public function resume( array $args ): void {
		$this->done( Plans::resume( (int) $this->plan( $args[0] )['id'] ), 'Resumed.' );
	}

	/**
	 * Change how often (as the customer).
	 *
	 * ## OPTIONS
	 *
	 * <plan>
	 * : Plan ref.
	 *
	 * <frequency>
	 * : weekly, fortnightly or four_weekly.
	 */
	public function frequency( array $args ): void {
		$this->done( Plans::change_frequency( (int) $this->plan( $args[0] )['id'], (string) ( $args[1] ?? '' ) ), 'Frequency changed.' );
	}

	/**
	 * End a plan (as the customer).
	 *
	 * ## OPTIONS
	 *
	 * <plan>
	 * : Plan ref.
	 */
	public function cancel( array $args ): void {
		$this->done( Plans::cancel( (int) $this->plan( $args[0] )['id'] ), 'Plan cancelled.' );
	}

	/**
	 * Delete phase 4 test data only: cleaners named "P4 Test…", and quotes, bookings and plans whose customer
	 * name starts "P4 Test", with their items, offers, payments, claims, events, photos and queued jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @subcommand purge-p4-test
	 */
	public function purge_p4_test( array $args, array $assoc ): void {
		global $wpdb;
		$like     = $wpdb->esc_like( 'P4 Test' ) . '%';
		$cleaners = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'cleaners' ) . ' WHERE name LIKE %s', $like ) ) );
		$bookings = array_map( [ Db::class, 'decode' ], $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE customer_name LIKE %s', $like ), ARRAY_A ) ?: [] );
		$quotes   = array_map( [ Db::class, 'decode' ], $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'quotes' ) . ' WHERE customer_name LIKE %s', $like ), ARRAY_A ) ?: [] );
		$plans    = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( Recurring::TABLE ) . ' WHERE customer_name LIKE %s', $like ) ) );
		if ( ! $cleaners && ! $bookings && ! $quotes && ! $plans ) {
			\WP_CLI::success( 'No P4 test data.' );
			return;
		}
		\WP_CLI::confirm( sprintf( 'Delete %d P4 test cleaner(s), %d booking(s), %d quote(s) and %d plan(s)?', count( $cleaners ), count( $bookings ), count( $quotes ), count( $plans ) ), $assoc );

		$booking_ids = array_map( 'intval', array_column( $bookings, 'id' ) );
		$quote_ids   = array_map( 'intval', array_column( $quotes, 'id' ) );
		foreach ( $bookings as $b ) {
			$id = (int) $b['id'];
			Files::delete_for( Dispatch::photo_folder( $b ) );
			Files::delete_for( 'RC-' . $b['ref'] );
			$state = Dispatch::state( $id );
			for ( $round = 0; $state && $round <= (int) $state['round']; $round++ ) {
				Queue::cancel( Dispatch::TIMEOUT_HOOK, [ $id, $round ] );
			}
			foreach ( [ 'confirmed', 'retry', 'queued', 'dropped', 'rescheduled', 'admin', 'cli', 'reoffer' ] as $reason ) {
				Queue::cancel( Dispatch::RUN_HOOK, [ $id, $reason ] );
			}
			Reminders::unschedule( $id );
			Queue::cancel( Aftercare::REVIEW_HOOK, [ $id ] );
			Plan_Charges::unschedule( $id );
		}
		foreach ( $quotes as $q ) {
			Files::delete_for( $q['ref'] );
			Queue::cancel( 'cc_bookings_quote_followup', [ (int) $q['id'], 'reminder' ] );
			Queue::cancel( 'cc_bookings_quote_followup', [ (int) $q['id'], 'expiring' ] );
			Queue::cancel( 'cc_bookings_quote_expire', [ (int) $q['id'] ] );
		}
		if ( $booking_ids ) {
			$claims = array_map( 'intval', array_column( Db::all( Reclean::TABLE, [ 'booking_id' => $booking_ids ] ), 'id' ) );
			if ( $claims ) {
				Db::delete( 'events', [ 'object_type' => 'reclean', 'object_id' => $claims ] );
				Db::delete( Reclean::TABLE, [ 'id' => $claims ] );
			}
			Db::delete( 'booking_items', [ 'booking_id' => $booking_ids ] );
			Db::delete( 'job_offers', [ 'booking_id' => $booking_ids ] );
			Db::delete( 'payments', [ 'booking_id' => $booking_ids ] );
			Db::delete( 'dispatch', [ 'booking_id' => $booking_ids ] );
			Db::delete( 'events', [ 'object_type' => 'booking', 'object_id' => $booking_ids ] );
			Db::delete( 'bookings', [ 'id' => $booking_ids ] );
		}
		if ( $quote_ids ) {
			Db::delete( 'payments', [ 'quote_id' => $quote_ids ] );
			Db::delete( 'events', [ 'object_type' => 'quote', 'object_id' => $quote_ids ] );
			Db::delete( 'quotes', [ 'id' => $quote_ids ] );
		}
		if ( $plans ) {
			Db::delete( 'events', [ 'object_type' => 'plan', 'object_id' => $plans ] );
			Db::delete( Recurring::TABLE, [ 'id' => $plans ] );
		}
		if ( $cleaners ) {
			Db::delete( 'job_offers', [ 'cleaner_id' => $cleaners ] );
			Db::delete( 'cleaner_availability', [ 'cleaner_id' => $cleaners ] );
			Db::delete( 'events', [ 'object_type' => 'cleaner', 'object_id' => $cleaners ] );
			Db::delete( 'cleaners', [ 'id' => $cleaners ] );
		}
		\WP_CLI::success( sprintf( 'Deleted %d cleaner(s), %d booking(s), %d quote(s), %d plan(s).', count( $cleaners ), count( $booking_ids ), count( $quote_ids ), count( $plans ) ) );
	}
}

<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 3 tools: `wp cc-bookings aftercare <command>`. Cancel, reschedule and complete run the same code as
 * the customer booking page and the admin buttons.
 */
final class Aftercare_Cli {
	private function booking( string $ref ): array {
		$booking = Bookings::by_ref( $ref );
		if ( ! $booking ) {
			\WP_CLI::error( 'Booking not found: ' . $ref );
		}
		return $booking;
	}

	/**
	 * Settings, re-clean claims and queued phase 3 jobs.
	 */
	public function status(): void {
		$rows = [];
		foreach ( Aftercare::DEFAULTS as $key => $default ) {
			$rows[] = [ 'item' => $key, 'value' => wp_json_encode( Aftercare::setting( $key ) ) ];
		}
		$rows[] = [ 'item' => 'review link in use', 'value' => Aftercare::review_url() ?: '(none — review requests are skipped)' ];
		$rows[] = [ 'item' => 're-clean window (hours)', 'value' => (string) Reclean::window_hours() ];
		$rows[] = [ 'item' => 'claims table schema', 'value' => (string) get_option( Aftercare::SCHEMA_OPTION ) ];
		$rows[] = [ 'item' => 'open re-clean claims', 'value' => (string) Db::count( Reclean::TABLE, [ 'status' => 'open' ] ) ];
		$rows[] = [ 'item' => 'refunds to pay by hand', 'value' => (string) count( Manage::pending_refunds() ) ];
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			foreach ( [ Reminders::HOOK, Aftercare::REVIEW_HOOK ] as $hook ) {
				$rows[] = [ 'item' => 'queued ' . $hook, 'value' => (string) count( as_get_scheduled_actions( [ 'hook' => $hook, 'group' => Queue::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ], 'ids' ) ) ];
			}
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'item', 'value' ] );
	}

	/**
	 * Show the refund a customer would get by cancelling, now or N hours before the start.
	 *
	 * ## OPTIONS
	 *
	 * <ref>
	 * : Booking reference.
	 *
	 * [--hours-before=<n>]
	 * : Pretend the cancellation happens this many hours before the start.
	 */
	public function policy( array $args, array $assoc ): void {
		$booking = $this->booking( $args[0] );
		$start   = Reminders::start( $booking );
		$now     = isset( $assoc['hours-before'] ) ? $start - (int) round( (float) $assoc['hours-before'] * HOUR_IN_SECONDS ) : null;
		$policy  = Manage::policy( $booking, $now );
		\WP_CLI::log( sprintf( '%s: %.1f hours before → band %s, %d%% → refund %s of %s paid (%s)', $booking['ref'], $policy['hours'], $policy['band'], $policy['pct'], Money::format( $policy['amount'] ), Money::format( (int) $booking['paid_pence'] ), Manage::refund_mode( $booking ) ) );
	}

	/**
	 * Cancel as the customer would from the booking page (policy refund included).
	 *
	 * ## OPTIONS
	 *
	 * <ref>
	 * : Booking reference.
	 */
	public function cancel( array $args ): void {
		$booking = $this->booking( $args[0] );
		$result  = Manage::cancel( (int) $booking['id'] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		$booking = Bookings::get( (int) $booking['id'] );
		\WP_CLI::success( sprintf( '%s cancelled: band %s (%d%%), refund %s via %s. %s Booking now %s / %s, refunded %s.', $booking['ref'], $result['policy']['band'], $result['policy']['pct'], Money::format( $result['policy']['amount'] ), $result['mode'], $result['refund_status'], $booking['status'], $booking['payment_status'], Money::format( (int) $booking['refunded_pence'] ) ) );
	}

	/**
	 * Move a booking as the customer (available slots only, notice rules apply) or admin (--force).
	 *
	 * ## OPTIONS
	 *
	 * <ref>
	 * : Booking reference.
	 *
	 * [<date>]
	 * : New date YYYY-MM-DD. Default: the first free slot.
	 *
	 * [<slot>]
	 * : Slot key, e.g. morning.
	 *
	 * [--force]
	 * : Admin move: ignore capacity and notice.
	 */
	public function reschedule( array $args, array $assoc ): void {
		$booking = $this->booking( $args[0] );
		$date    = $args[1] ?? '';
		$slot    = $args[2] ?? '';
		if ( ! $date ) {
			$options = Manage::slot_options( $booking );
			$date    = (string) array_key_first( $options );
			$slot    = $date ? (string) array_key_first( $options[ $date ] ) : '';
		}
		$result = Manage::reschedule( (int) $booking['id'], $date, $slot, isset( $assoc['force'] ) ? 'admin' : 'customer', isset( $assoc['force'] ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		$booking = Bookings::get( (int) $booking['id'] );
		\WP_CLI::success( sprintf( '%s moved to %s. Reminders: %s', $booking['ref'], Bookings::start_label( $booking ), wp_json_encode( array_map( fn( $ts ) => wp_date( 'Y-m-d H:i', $ts ), Reminders::queued( (int) $booking['id'] ) ) ) ) );
	}

	/**
	 * Mark a booking complete (same as the admin button / cleaner job page).
	 *
	 * ## OPTIONS
	 *
	 * <ref>
	 * : Booking reference.
	 */
	public function complete( array $args ): void {
		$booking = $this->booking( $args[0] );
		Bookings::complete( (int) $booking['id'] ) ? \WP_CLI::success( $booking['ref'] . ' marked complete.' ) : \WP_CLI::error( 'Could not complete from status ' . $booking['status'] );
	}

	/**
	 * Run this booking's queued phase 3 jobs now (reminders, review request) through Action Scheduler.
	 *
	 * ## OPTIONS
	 *
	 * <ref>
	 * : Booking reference.
	 *
	 * [--hook=<hook>]
	 * : Only this hook (cc_bookings_reminder or cc_bookings_review_request).
	 */
	public function run( array $args, array $assoc ): void {
		$booking = $this->booking( $args[0] );
		if ( ! class_exists( '\ActionScheduler' ) ) {
			\WP_CLI::error( 'Action Scheduler is not loaded.' );
		}
		$jobs = [ [ Aftercare::REVIEW_HOOK, [ (int) $booking['id'] ] ] ];
		foreach ( array_unique( array_merge( Reminders::offsets(), [ 48, 24 ] ) ) as $hours ) {
			$jobs[] = [ Reminders::HOOK, [ (int) $booking['id'], $hours ] ];
		}
		$ran = 0;
		foreach ( $jobs as [ $hook, $hook_args ] ) {
			if ( isset( $assoc['hook'] ) && $assoc['hook'] !== $hook ) {
				continue;
			}
			foreach ( as_get_scheduled_actions( [ 'hook' => $hook, 'args' => $hook_args, 'group' => Queue::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ], 'ids' ) as $action_id ) {
				\ActionScheduler::runner()->process_action( (int) $action_id, 'WP CLI aftercare run' );
				\WP_CLI::log( sprintf( 'Ran action %d: %s %s', $action_id, $hook, wp_json_encode( $hook_args ) ) );
				$ran++;
			}
		}
		\WP_CLI::success( $ran . ' action(s) run.' );
	}
}

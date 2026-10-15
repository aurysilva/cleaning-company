<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 2: automatic dispatch (spec §7).
 *
 * On cc_booking_confirmed (and cc_booking_rescheduled, a cleaner dropping out, or an admin re-offer) run():
 *   1. eligible = active cleaners covering the district, doing the service, free for the job and under their daily limit;
 *   2. employed cleaners are assigned directly (previous cleaner for this customer first, then least busy that day);
 *   3. remaining places are offered to every eligible self-employed cleaner by email + text. The first N to accept
 *      win, enforced by an atomic `UPDATE wp_cc_dispatch SET filled = filled + 1 WHERE filled < seats`;
 *   4. if places are still open at the deadline (shorter for jobs within 48h), the admin gets an urgent email + text.
 *
 * Hooks fired:
 *   cc_dispatch_offered( $booking_id, $offer_id, $cleaner_id )
 *   cc_dispatch_offer_accepted( $booking_id, $offer_id, $cleaner_id )
 *   cc_dispatch_offer_declined( $booking_id, $offer_id, $cleaner_id )
 *   cc_dispatch_cleaner_dropped( $booking_id, $offer_id, $cleaner_id, $reason )
 *   cc_dispatch_escalated( $booking_id, $reason )
 *   cc_dispatch_filled( $booking_id )
 * Listens to: cc_booking_confirmed, cc_booking_rescheduled( $booking_id, $old_start ), cc_booking_status_changed.
 */
final class Dispatch {
	const RUN_HOOK     = 'cc_bookings_dispatch_run';
	const TIMEOUT_HOOK = 'cc_bookings_dispatch_timeout';

	const DEFAULTS = [
		'auto'                => 1,
		'offer_timeout_hours' => 3,
		'urgent_within_hours' => 48,
		'urgent_timeout_mins' => 60,
		'buffer_minutes'      => 30,
		'slot_source'         => 'cleaners',
		'complete_from_mins'  => 120,
		'notify_taken'        => 1,
		'notify_customer'     => 0,
		'notify_cancelled'    => 1,
		'admin_mobile'        => '',
		'sms_log_only'        => 0,
		'sms'                 => [],
	];

	/** True while accept() runs, so the crew re-count doesn't race the seat counter. */
	private static bool $accepting = false;

	public static function init(): void {
		Dispatch_Schema::maybe_upgrade();
		Sms::init();
		Availability::init();
		Dispatch_Notify::init();
		Job_Pages::init();

		add_filter( 'cc_bookings_statuses', [ self::class, 'statuses' ], 10, 2 );
		add_action( 'cc_booking_confirmed', [ self::class, 'on_confirmed' ], 20, 2 );
		add_action( 'cc_booking_rescheduled', [ self::class, 'on_rescheduled' ], 10, 2 );
		add_action( 'cc_booking_status_changed', [ self::class, 'on_status_changed' ], 10, 3 );
		add_action( 'cc_booking_assigned', [ self::class, 'sync' ], 5 );
		add_action( 'cc_booking_unassigned', [ self::class, 'sync' ], 5 );
		add_action( self::RUN_HOOK, [ self::class, 'run_queued' ], 10, 2 );
		add_action( self::TIMEOUT_HOOK, [ self::class, 'timeout' ], 10, 2 );

		if ( is_admin() ) {
			Admin\Dispatch_Screen::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'cc_bookings_loaded', [ Dispatch_Cli::class, 'register' ] );
		}
	}

	/** Dispatch setting (stored under "dispatch" in cc_bookings_settings). */
	public static function opt( string $key ) {
		return Settings::get( 'dispatch.' . $key, self::DEFAULTS[ $key ] ?? null );
	}

	public static function statuses( $statuses, $kind ) {
		if ( 'offer' === $kind ) {
			$statuses['accepting'] = 'Accepting';
		}
		return $statuses;
	}

	/* ---------------------------------------------------------------------
	 * State (wp_cc_dispatch)
	 * ------------------------------------------------------------------ */

	public static function state( int $booking_id, bool $create = false ): ?array {
		$row = Db::find( 'dispatch', [ 'booking_id' => $booking_id ] );
		if ( ! $row && $create ) {
			$booking = Bookings::get( $booking_id );
			Db::insert( 'dispatch', [ 'booking_id' => $booking_id, 'seats' => max( 1, (int) ( $booking['cleaners'] ?? 1 ) ), 'filled' => count( Bookings::crew( $booking_id ) ) ] );
			$row = Db::find( 'dispatch', [ 'booking_id' => $booking_id ] );
		}
		return $row;
	}

	private static function set_state( int $booking_id, array $data ): void {
		$row = self::state( $booking_id, true );
		if ( $row ) {
			Db::update( 'dispatch', (int) $row['id'], $data );
		}
	}

	/** Re-count the crew into `filled` after a manual assign/unassign. */
	public static function sync( $booking_id ): void {
		global $wpdb;
		if ( self::$accepting ) {
			return;
		}
		$on = array_merge( Status::on_job(), [ 'accepting' ] );
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . Schema::table( 'dispatch' ) . ' SET filled = (SELECT COUNT(*) FROM ' . Schema::table( 'job_offers' ) . ' WHERE booking_id = %d AND status IN (' . implode( ',', array_fill( 0, count( $on ), '%s' ) ) . ')), updated_at = %s WHERE booking_id = %d',
			array_merge( [ (int) $booking_id ], $on, [ Db::now(), (int) $booking_id ] )
		) );
		// Filled by hand while offers were out: close them.
		$state = self::state( (int) $booking_id );
		if ( $state && in_array( $state['status'], [ 'offering', 'escalated' ], true ) && (int) $state['filled'] >= (int) $state['seats'] ) {
			Queue::cancel( self::TIMEOUT_HOOK, [ (int) $booking_id, (int) $state['round'] ] );
			self::set_state( (int) $booking_id, [ 'status' => 'filled', 'deadline' => null ] );
			foreach ( self::withdraw_open( (int) $booking_id ) as $other ) {
				Dispatch_Notify::taken( (int) $other['id'] );
			}
		}
	}

	/** Booking can still be staffed: open status and a start time that hasn't passed. */
	public static function dispatchable( array $booking ): bool {
		return in_array( $booking['status'], [ 'confirmed', 'dispatching', 'assigned' ], true )
			&& $booking['job_date']
			&& Availability::job( $booking )['start'] > time();
	}

	/* ---------------------------------------------------------------------
	 * Listeners
	 * ------------------------------------------------------------------ */

	public static function on_confirmed( $booking_id, $context = [] ): void {
		if ( ! self::opt( 'auto' ) ) {
			Events::log( 'booking', (int) $booking_id, 'dispatch', 'Automatic dispatch is off; assign a cleaner manually' );
			return;
		}
		self::safe_run( (int) $booking_id, 'confirmed' );
	}

	public static function run_queued( $booking_id, $reason = 'queued' ): void {
		self::safe_run( (int) $booking_id, (string) $reason );
	}

	/** Run, but never let a dispatch error break the caller (e.g. the Stripe webhook); retry from the queue instead. */
	private static function safe_run( int $booking_id, string $reason, array $opts = [] ): void {
		try {
			self::run( $booking_id, $reason, $opts );
		} catch ( \Throwable $e ) {
			Events::log( 'booking', $booking_id, 'dispatch_error', $e->getMessage(), [ 'file' => basename( $e->getFile() ) . ':' . $e->getLine() ] );
			Queue::at( time() + 5 * MINUTE_IN_SECONDS, self::RUN_HOOK, [ $booking_id, 'retry' ] );
		}
	}

	/** Phase 3 moves the booking first, then fires cc_booking_rescheduled( $booking_id, $old_start ). */
	public static function on_rescheduled( $booking_id, $old_start = '' ): void {
		$booking_id = (int) $booking_id;
		$booking    = Bookings::get( $booking_id );
		if ( ! $booking || ! in_array( $booking['status'], [ 'confirmed', 'dispatching', 'assigned' ], true ) ) {
			return;
		}
		$before = [];
		Dispatch_Notify::$quiet = true;
		foreach ( Bookings::crew( $booking_id ) as $offer ) {
			$before[ (int) $offer['cleaner_id'] ] = (int) $offer['id'];
			Bookings::unassign( $booking_id, (int) $offer['id'], 'withdrawn' );
		}
		Dispatch_Notify::$quiet = false;
		self::withdraw_open( $booking_id );
		Events::log( 'booking', $booking_id, 'dispatch', 'Booking moved; re-dispatching', [ 'old_start' => $old_start ] );

		self::safe_run( $booking_id, 'rescheduled', [ 'include_declined' => true ] );

		$after = array_map( 'intval', array_column( Bookings::crew( $booking_id ), 'cleaner_id' ) );
		foreach ( $before as $cleaner_id => $offer_id ) {
			if ( ! in_array( $cleaner_id, $after, true ) ) {
				Dispatch_Notify::removed( $booking_id, $offer_id, 'The booking has moved to ' . Bookings::start_label( Bookings::get( $booking_id ) ) . ', so this job has been taken off your list.' );
			}
		}
	}

	public static function on_status_changed( $booking_id, $to, $from = '' ): void {
		$booking_id = (int) $booking_id;
		if ( ! in_array( $to, [ 'cancelled', 'completed' ], true ) ) {
			return;
		}
		$state = self::state( $booking_id );
		if ( $state ) {
			Queue::cancel( self::TIMEOUT_HOOK, [ $booking_id, (int) $state['round'] ] );
			self::set_state( $booking_id, [ 'status' => 'cancelled' === $to ? 'cancelled' : 'done', 'deadline' => null ] );
		}
		self::withdraw_open( $booking_id );
		// Bookings::cancel() fires this before withdrawing the crew, so they are still on the job here.
		if ( 'cancelled' === $to && self::opt( 'notify_cancelled' ) && apply_filters( 'cc_bookings_dispatch_notify_cancelled', true, $booking_id ) ) {
			foreach ( Bookings::crew( $booking_id ) as $offer ) {
				Dispatch_Notify::removed( $booking_id, (int) $offer['id'], 'The customer\'s booking has been cancelled. You don\'t need to go.' );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Dispatch
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $opts bonus (pence, extra pay on offers), include_declined (bool).
	 * @return array{ok: bool, message: string, assigned?: string[], offered?: int}
	 */
	public static function run( int $booking_id, string $reason = 'confirmed', array $opts = [] ): array {
		$booking = Bookings::get( $booking_id );
		if ( ! $booking ) {
			return [ 'ok' => false, 'message' => 'Booking not found.' ];
		}
		if ( ! self::dispatchable( $booking ) ) {
			$message = $booking['job_date'] ? 'Booking is closed or already started; not dispatched.' : 'No date yet; assign a cleaner once a date is agreed.';
			Events::log( 'booking', $booking_id, 'dispatch', $message );
			return [ 'ok' => false, 'message' => $message ];
		}
		if ( ! Db::lock( 'dispatch:' . $booking_id, 15 ) ) {
			Queue::at( time() + MINUTE_IN_SECONDS, self::RUN_HOOK, [ $booking_id, $reason ] );
			return [ 'ok' => false, 'message' => 'Dispatch already running; retrying in a minute.' ];
		}

		try {
			$state = self::state( $booking_id, true );
			$seats = max( 1, (int) $booking['cleaners'] );
			$crew  = Bookings::crew( $booking_id );
			$round = (int) $state['round'] + 1;
			$bonus = array_key_exists( 'bonus', $opts ) ? max( 0, (int) $opts['bonus'] ) : (int) $state['bonus_pence'];
			Queue::cancel( self::TIMEOUT_HOOK, [ $booking_id, (int) $state['round'] ] );
			self::set_state( $booking_id, [ 'seats' => $seats, 'filled' => count( $crew ), 'round' => $round, 'status' => 'running', 'bonus_pence' => $bonus, 'deadline' => null, 'escalated_at' => null ] );

			$need = $seats - count( $crew );
			if ( $need <= 0 ) {
				self::set_state( $booking_id, [ 'status' => 'filled' ] );
				return [ 'ok' => true, 'message' => 'Already fully staffed.' ];
			}

			// Leave out the crew, anyone who dropped out of this job and (unless re-offering) anyone who declined it.
			$skip_status = ! empty( $opts['include_declined'] ) ? [ 'dropped' ] : [ 'dropped', 'declined' ];
			$exclude     = array_merge(
				array_map( 'intval', array_column( $crew, 'cleaner_id' ) ),
				array_map( 'intval', array_column( Db::all( 'job_offers', [ 'booking_id' => $booking_id, 'status' => $skip_status ] ), 'cleaner_id' ) )
			);
			$job       = Availability::job( $booking );
			$eligible  = Availability::eligible( $job, $exclude );
			$need_lead = $seats > 1 && ! array_filter( $crew, fn( $o ) => ! empty( $o['cleaner']['can_lead'] ) );

			// Previous offers that are no longer live are closed; eligible cleaners get them re-sent below.
			self::withdraw_open( $booking_id, 'expired' );

			$assigned = [];
			foreach ( self::rank( array_filter( $eligible, fn( $c ) => 'employed' === $c['type'] ), $booking, $need_lead ) as $c ) {
				if ( $need <= 0 ) {
					break;
				}
				if ( $need_lead && 1 === $need && ! $c['can_lead'] ) {
					continue;
				}
				$result = Bookings::assign( $booking_id, (int) $c['id'], 'auto' );
				if ( is_wp_error( $result ) ) {
					continue;
				}
				$assigned[] = $c['name'];
				--$need;
				$need_lead = $need_lead && ! $c['can_lead'];
			}

			if ( $need <= 0 ) {
				self::set_state( $booking_id, [ 'status' => 'filled' ] );
				Events::log( 'booking', $booking_id, 'dispatch', sprintf( 'Auto-assigned %s (%s)', implode( ', ', $assigned ), $reason ) );
				do_action( 'cc_dispatch_filled', $booking_id );
				return [ 'ok' => true, 'message' => 'Assigned ' . implode( ', ', $assigned ) . '.', 'assigned' => $assigned, 'offered' => 0 ];
			}

			$self_employed = array_values( array_filter( $eligible, fn( $c ) => 'self_employed' === $c['type'] && ( ! $need_lead || $need > 1 || $c['can_lead'] ) ) );
			if ( ! $self_employed ) {
				$why = sprintf( 'No eligible cleaner is free for %s (%s, %s). %s', $booking['ref'], Bookings::start_label( $booking ), $job['district'] ?: 'no district', $assigned ? 'Assigned so far: ' . implode( ', ', $assigned ) . '; ' . $need . ' more needed.' : 'Nobody could be assigned or offered the job.' );
				self::escalate( $booking_id, $round, $why );
				return [ 'ok' => false, 'message' => $why, 'assigned' => $assigned, 'offered' => 0 ];
			}

			$deadline = self::deadline( $job['start'] );
			foreach ( $self_employed as $c ) {
				self::offer( $booking, $c, $deadline, $bonus );
			}
			self::set_state( $booking_id, [ 'status' => 'offering', 'deadline' => Db::datetime( $deadline ) ] );
			Queue::at( $deadline, self::TIMEOUT_HOOK, [ $booking_id, $round ] );
			Bookings::set_status( $booking_id, 'dispatching', sprintf( 'Offered to %d self-employed cleaner%s; %d place%s open until %s', count( $self_employed ), 1 === count( $self_employed ) ? '' : 's', $need, 1 === $need ? '' : 's', wp_date( 'D j M H:i', $deadline ) ) );
			Events::log( 'booking', $booking_id, 'dispatch', sprintf( 'Round %d (%s): %s%d offer%s sent%s', $round, $reason, $assigned ? 'assigned ' . implode( ', ', $assigned ) . '; ' : '', count( $self_employed ), 1 === count( $self_employed ) ? '' : 's', $bonus ? ' with ' . Money::format( $bonus ) . ' bonus' : '' ) );
			return [ 'ok' => true, 'message' => sprintf( 'Offered to %d cleaner(s).', count( $self_employed ) ), 'assigned' => $assigned, 'offered' => count( $self_employed ) ];
		} finally {
			Db::unlock( 'dispatch:' . $booking_id );
		}
	}

	/** Previous cleaner for this customer first, then least busy that day, then that week. */
	private static function rank( array $cleaners, array $booking, bool $need_lead ): array {
		global $wpdb;
		$on       = Status::on_job();
		$previous = [];
		if ( $booking['email'] ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT o.cleaner_id, COUNT(*) AS n FROM ' . Schema::table( 'job_offers' ) . ' o JOIN ' . Schema::table( 'bookings' ) . ' b ON b.id = o.booking_id'
				. ' WHERE b.email = %s AND b.id != %d AND b.status != %s AND o.status IN (' . implode( ',', array_fill( 0, count( $on ), '%s' ) ) . ') GROUP BY o.cleaner_id',
				array_merge( [ $booking['email'], $booking['id'], 'cancelled' ], $on )
			), ARRAY_A );
			$previous = array_column( $rows ?: [], 'n', 'cleaner_id' );
		}
		usort( $cleaners, fn( $a, $b ) => [
			$need_lead ? -(int) $a['can_lead'] : 0,
			empty( $previous[ $a['id'] ] ) ? 1 : 0,
			$a['day_jobs'] ?? 0,
			$a['week_jobs'] ?? 0,
			(int) $a['id'],
		] <=> [
			$need_lead ? -(int) $b['can_lead'] : 0,
			empty( $previous[ $b['id'] ] ) ? 1 : 0,
			$b['day_jobs'] ?? 0,
			$b['week_jobs'] ?? 0,
			(int) $b['id'],
		] );
		return $cleaners;
	}

	/** Offer deadline: the normal timeout, or the short one for jobs starting soon; never later than 2h before the job. */
	public static function deadline( int $start ): int {
		$now     = time();
		$urgent  = $start - $now < (int) self::opt( 'urgent_within_hours' ) * HOUR_IN_SECONDS;
		$minutes = $urgent ? (int) self::opt( 'urgent_timeout_mins' ) : (int) round( (float) self::opt( 'offer_timeout_hours' ) * 60 );
		$at      = $now + max( 5, $minutes ) * MINUTE_IN_SECONDS;
		return max( $now + 10 * MINUTE_IN_SECONDS, min( $at, $start - 2 * HOUR_IN_SECONDS ) );
	}

	public static function pay( array $cleaner, array $booking, int $bonus = 0 ): int {
		$rate = (int) $cleaner['pay_rate_pence'];
		$base = 'per_job' === $cleaner['pay_type'] ? $rate : (int) round( $rate * (float) $booking['hours'] );
		return max( 0, $base ) + max( 0, $bonus );
	}

	/** Create or refresh the offer row and send it. */
	private static function offer( array $booking, array $cleaner, int $deadline, int $bonus ): int {
		$data     = [
			'status'       => 'offered',
			'source'       => 'offer',
			'role'         => 'member',
			'pay_pence'    => self::pay( $cleaner, $booking, $bonus ),
			'offered_at'   => Db::now(),
			'responded_at' => null,
			'expires_at'   => Db::datetime( $deadline ),
		];
		$existing = Db::find( 'job_offers', [ 'booking_id' => (int) $booking['id'], 'cleaner_id' => (int) $cleaner['id'] ] );
		if ( $existing ) {
			Db::update( 'job_offers', (int) $existing['id'], $data );
			$offer_id = (int) $existing['id'];
		} else {
			$offer_id = Db::insert( 'job_offers', $data + [ 'booking_id' => (int) $booking['id'], 'cleaner_id' => (int) $cleaner['id'] ] );
		}
		Dispatch_Notify::offer( $offer_id );
		do_action( 'cc_dispatch_offered', (int) $booking['id'], $offer_id, (int) $cleaner['id'] );
		return $offer_id;
	}

	/** Close offers still waiting for an answer (status withdrawn = "taken"/"no longer needed", or expired). */
	public static function withdraw_open( int $booking_id, string $status = 'withdrawn' ): array {
		$open = Db::all( 'job_offers', [ 'booking_id' => $booking_id, 'status' => 'offered' ] );
		foreach ( $open as $offer ) {
			Db::update( 'job_offers', (int) $offer['id'], [ 'status' => $status ] );
		}
		return $open;
	}

	public static function timeout( $booking_id, $round = 0 ): void {
		$booking_id = (int) $booking_id;
		$state      = self::state( $booking_id );
		$booking    = Bookings::get( $booking_id );
		if ( ! $state || ! $booking || (int) $state['round'] !== (int) $round || 'offering' !== $state['status'] ) {
			return;
		}
		$open = (int) $state['seats'] - count( Bookings::crew( $booking_id ) );
		if ( $open <= 0 || ! in_array( $booking['status'], [ 'confirmed', 'dispatching', 'assigned' ], true ) ) {
			self::set_state( $booking_id, [ 'status' => 'filled' ] );
			return;
		}
		$offers = Db::count( 'job_offers', [ 'booking_id' => $booking_id, 'status' => 'offered' ] );
		self::escalate( $booking_id, (int) $round, sprintf(
			'Nobody has accepted %s (%s, %s) in time. %d place%s still open; %d offer%s unanswered and still open to accept. Assign someone, re-offer with a bonus, move or refund the booking.',
			$booking['ref'],
			Bookings::start_label( $booking ),
			$booking['district'],
			$open,
			1 === $open ? '' : 's',
			$offers,
			1 === $offers ? '' : 's'
		) );
	}

	/** Urgent admin alert (email + text). Offers already out stay open, so a late accept still fills the job. */
	public static function escalate( int $booking_id, int $round, string $why ): void {
		self::set_state( $booking_id, [ 'status' => 'escalated', 'escalated_at' => Db::now() ] );
		$booking = Bookings::get( $booking_id );
		if ( $booking && 'dispatching' === $booking['status'] ) {
			Bookings::set_status( $booking_id, 'confirmed', 'Needs a cleaner: dispatch escalated to the office' );
		}
		Bookings::alert( 'Job needs a cleaner: ' . ( $booking['ref'] ?? $booking_id ), $why, [ 'booking_id' => $booking_id, 'urgent' => true ] );
		do_action( 'cc_dispatch_escalated', $booking_id, $why );
	}

	/* ---------------------------------------------------------------------
	 * Cleaner actions (job page, CLI)
	 * ------------------------------------------------------------------ */

	/** @return array{ok: bool, code: string, message: string} */
	private static function result( bool $ok, string $code, string $message ): array {
		return [ 'ok' => $ok, 'code' => $code, 'message' => $message ];
	}

	public static function accept( int $offer_id ): array {
		global $wpdb;
		$offer   = Db::get( 'job_offers', $offer_id );
		$booking = $offer ? Bookings::get( (int) $offer['booking_id'] ) : null;
		$cleaner = $offer ? Cleaners::get( (int) $offer['cleaner_id'] ) : null;
		if ( ! $offer || ! $booking || ! $cleaner ) {
			return self::result( false, 'invalid', 'Job not found.' );
		}
		if ( in_array( $offer['status'], Status::on_job(), true ) ) {
			return self::result( true, 'already', 'This job is already yours.' );
		}
		if ( in_array( $offer['status'], [ 'offered', 'withdrawn', 'expired' ], true ) && count( Bookings::crew( (int) $booking['id'] ) ) >= (int) $booking['cleaners'] ) {
			return self::result( false, 'taken', 'Sorry, this job has been taken.' );
		}
		if ( ! in_array( $offer['status'], [ 'offered', 'declined', 'expired' ], true ) ) {
			return self::result( false, 'withdrawn', 'This offer is no longer available.' );
		}
		if ( ! self::dispatchable( $booking ) ) {
			return self::result( false, 'closed', 'This job is no longer available.' );
		}
		$job  = Availability::job( $booking );
		$busy = Availability::commitments( $job['date'], $job['date'], $job['booking_id'] );
		if ( ! Availability::is_free( $cleaner, $job, $busy[ (int) $cleaner['id'] ] ?? [] ) ) {
			return self::result( false, 'clash', 'This job clashes with your other jobs or hours, so we can\'t give it to you.' );
		}
		if ( ! Db::lock( 'dispatch:' . $booking['id'], 10 ) ) {
			return self::result( false, 'busy', 'Lots of people are answering right now. Please try again in a moment.' );
		}

		self::$accepting = true;
		try {
			$state = self::state( (int) $booking['id'], true );
			$crew  = Bookings::crew( (int) $booking['id'] );
			if ( (int) $state['seats'] > 1 && 1 === (int) $state['seats'] - count( $crew ) && ! $cleaner['can_lead']
				&& ! array_filter( $crew, fn( $o ) => ! empty( $o['cleaner']['can_lead'] ) ) ) {
				return self::result( false, 'lead', 'The last place on this team needs a team lead.' );
			}

			// Claim this offer (stops a double tap), then a seat. The seat UPDATE is the first-to-accept lock.
			$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'job_offers' ) . " SET status = 'accepting', responded_at = %s, updated_at = %s WHERE id = %d AND status = %s", Db::now(), Db::now(), $offer_id, $offer['status'] ) );
			if ( 1 !== (int) $claimed ) {
				return self::result( false, 'withdrawn', 'This offer has just changed. Please reload the page.' );
			}
			$seat = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'dispatch' ) . ' SET filled = filled + 1, updated_at = %s WHERE booking_id = %d AND filled < seats', Db::now(), (int) $booking['id'] ) );
			if ( 1 !== (int) $seat ) {
				Db::update( 'job_offers', $offer_id, [ 'status' => 'withdrawn' ] );
				Events::log( 'booking', (int) $booking['id'], 'offer_taken', sprintf( '%s tried to accept, but the job was already taken', $cleaner['name'] ), [ 'offer_id' => $offer_id ], 'cleaner:' . $cleaner['id'] );
				return self::result( false, 'taken', 'Sorry, this job has been taken.' );
			}

			$assigned = Bookings::assign( (int) $booking['id'], (int) $cleaner['id'], 'offer' );
			if ( is_wp_error( $assigned ) ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'dispatch' ) . ' SET filled = GREATEST(filled - 1, 0) WHERE booking_id = %d', (int) $booking['id'] ) );
				Db::update( 'job_offers', $offer_id, [ 'status' => 'offered' ] );
				return self::result( false, 'error', $assigned->get_error_message() );
			}
			Db::update( 'job_offers', $offer_id, [ 'status' => 'accepted', 'pay_pence' => (int) $offer['pay_pence'] ] );
			Events::log( 'booking', (int) $booking['id'], 'offer_accepted', sprintf( '%s accepted the job', $cleaner['name'] ), [ 'offer_id' => $offer_id, 'cleaner_id' => (int) $cleaner['id'] ], 'cleaner:' . $cleaner['id'] );
			do_action( 'cc_dispatch_offer_accepted', (int) $booking['id'], $offer_id, (int) $cleaner['id'] );

			$state = self::state( (int) $booking['id'] );
			if ( (int) $state['filled'] >= (int) $state['seats'] ) {
				Queue::cancel( self::TIMEOUT_HOOK, [ (int) $booking['id'], (int) $state['round'] ] );
				self::set_state( (int) $booking['id'], [ 'status' => 'filled', 'deadline' => null ] );
				foreach ( self::withdraw_open( (int) $booking['id'] ) as $other ) {
					Dispatch_Notify::taken( (int) $other['id'] );
				}
				do_action( 'cc_dispatch_filled', (int) $booking['id'] );
			}
			return self::result( true, 'accepted', 'The job is yours.' );
		} finally {
			self::$accepting = false;
			Db::unlock( 'dispatch:' . $booking['id'] );
		}
	}

	public static function decline( int $offer_id ): array {
		$offer = Db::get( 'job_offers', $offer_id );
		if ( ! $offer || 'offered' !== $offer['status'] ) {
			return self::result( false, 'withdrawn', 'This offer is no longer open.' );
		}
		$booking_id = (int) $offer['booking_id'];
		Db::update( 'job_offers', $offer_id, [ 'status' => 'declined', 'responded_at' => Db::now() ] );
		$cleaner = Cleaners::get( (int) $offer['cleaner_id'] );
		Events::log( 'booking', $booking_id, 'offer_declined', sprintf( '%s declined', $cleaner['name'] ?? 'Cleaner' ), [ 'offer_id' => $offer_id ], 'cleaner:' . $offer['cleaner_id'] );
		do_action( 'cc_dispatch_offer_declined', $booking_id, $offer_id, (int) $offer['cleaner_id'] );

		$state = self::state( $booking_id );
		if ( $state && 'offering' === $state['status'] && ! Db::count( 'job_offers', [ 'booking_id' => $booking_id, 'status' => [ 'offered', 'accepting' ] ] )
			&& count( Bookings::crew( $booking_id ) ) < (int) $state['seats'] ) {
			Queue::cancel( self::TIMEOUT_HOOK, [ $booking_id, (int) $state['round'] ] );
			$booking = Bookings::get( $booking_id );
			self::escalate( $booking_id, (int) $state['round'], sprintf( 'Every cleaner offered %s (%s) has declined it.', $booking['ref'], Bookings::start_label( $booking ) ) );
		}
		return self::result( true, 'declined', 'Thanks for letting us know.' );
	}

	/** "Can't make it" after accepting or being assigned: off the job, office alerted, job re-dispatched. */
	public static function drop( int $offer_id, string $reason = '' ): array {
		$offer   = Db::get( 'job_offers', $offer_id );
		$booking = $offer ? Bookings::get( (int) $offer['booking_id'] ) : null;
		if ( ! $offer || ! $booking || ! in_array( $offer['status'], Status::on_job(), true ) ) {
			return self::result( false, 'withdrawn', 'You\'re not on this job.' );
		}
		if ( in_array( $booking['status'], [ 'cancelled', 'completed' ], true ) ) {
			return self::result( false, 'closed', 'This job is closed.' );
		}
		$cleaner = Cleaners::get( (int) $offer['cleaner_id'] );
		$reason  = mb_substr( sanitize_text_field( $reason ), 0, 255 );
		Db::update( 'job_offers', $offer_id, [ 'note' => $reason ] );
		Bookings::unassign( (int) $booking['id'], $offer_id, 'dropped' );
		Events::log( 'booking', (int) $booking['id'], 'cleaner_dropped', sprintf( '%s can\'t make it%s', $cleaner['name'] ?? 'Cleaner', $reason ? ': ' . $reason : '' ), [ 'offer_id' => $offer_id ], 'cleaner:' . $offer['cleaner_id'] );
		do_action( 'cc_dispatch_cleaner_dropped', (int) $booking['id'], $offer_id, (int) $offer['cleaner_id'], $reason );

		$result = self::dispatchable( Bookings::get( (int) $booking['id'] ) ) ? self::run( (int) $booking['id'], 'dropped' ) : [ 'message' => 'Too late to re-dispatch automatically.' ];
		Bookings::alert(
			sprintf( '%s can\'t make %s', $cleaner['name'] ?? 'A cleaner', $booking['ref'] ),
			sprintf( '%s has dropped out of %s (%s)%s. Re-dispatch: %s', $cleaner['name'] ?? 'A cleaner', $booking['ref'], Bookings::start_label( $booking ), $reason ? ' — "' . $reason . '"' : '', $result['message'] ),
			[ 'booking_id' => (int) $booking['id'], 'urgent' => true ]
		);
		return self::result( true, 'dropped', 'Thanks for telling us. We\'ll find someone else.' );
	}

	/** Earliest time a cleaner can mark the job complete. */
	public static function complete_from( array $booking ): int {
		return Availability::job( $booking )['start'] - (int) self::opt( 'complete_from_mins' ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Mark the booking complete from the job page (optional photos). Fires cc_booking_completed via Bookings::complete();
	 * everything after that (thank-you email, review request, re-clean window) belongs to phase 3.
	 */
	public static function complete( int $offer_id, array $files = [] ): array {
		$offer   = Db::get( 'job_offers', $offer_id );
		$booking = $offer ? Bookings::get( (int) $offer['booking_id'] ) : null;
		if ( ! $offer || ! $booking || ! in_array( $offer['status'], Status::on_job(), true ) ) {
			return self::result( false, 'withdrawn', 'You\'re not on this job.' );
		}
		if ( 'completed' === $booking['status'] ) {
			return self::result( true, 'completed', 'This job is already marked complete.' );
		}
		if ( time() < self::complete_from( $booking ) ) {
			return self::result( false, 'early', 'You can mark the job complete once it has started.' );
		}
		$stored = [];
		if ( $files ) {
			$error = Files::validate( $files );
			if ( $error ) {
				return self::result( false, 'photos', $error );
			}
			$stored = Files::store( $files, self::photo_folder( $booking ) );
			Db::update( 'job_offers', $offer_id, [ 'photos' => array_values( array_merge( (array) $offer['photos'], $stored ) ) ] );
		}
		if ( 'dispatching' === $booking['status'] ) {
			Bookings::set_status( (int) $booking['id'], 'confirmed', '', true );
		}
		$cleaner = Cleaners::get( (int) $offer['cleaner_id'] );
		Events::log( 'booking', (int) $booking['id'], 'cleaner_completed', sprintf( '%s marked the job complete%s', $cleaner['name'] ?? 'Cleaner', $stored ? sprintf( ' with %d photo%s', count( $stored ), 1 === count( $stored ) ? '' : 's' ) : '' ), [ 'offer_id' => $offer_id, 'photos' => $stored ], 'cleaner:' . $offer['cleaner_id'] );
		if ( ! Bookings::complete( (int) $booking['id'] ) ) {
			return self::result( false, 'error', 'We couldn\'t mark this job complete. Please call the office.' );
		}
		return self::result( true, 'completed', 'Job marked complete. Thank you!' );
	}

	/** Private uploads sub-folder for completion photos. */
	public static function photo_folder( array $booking ): string {
		return $booking['ref'] . '-done';
	}

	/** Completion photos for a booking: [ [ offer_id, cleaner_id, file ], … ]. */
	public static function photos( int $booking_id ): array {
		$out = [];
		foreach ( Db::all( 'job_offers', [ 'booking_id' => $booking_id ], 'id ASC' ) as $offer ) {
			foreach ( (array) $offer['photos'] as $file ) {
				$out[] = [ 'offer_id' => (int) $offer['id'], 'cleaner_id' => (int) $offer['cleaner_id'], 'file' => (string) $file ];
			}
		}
		return $out;
	}
}

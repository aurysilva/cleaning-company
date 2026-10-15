<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Regular-clean plans: one row in wp_cc_plans per customer plan, plus a rolling horizon of visit bookings
 * (bookings.series_id = plan id, quote_id = 0, status scheduled until charged). The first, already-paid booking
 * from the quote is visit 1. Visits are topped up after each payment, completion, cancellation and by a
 * six-hourly tick, which also catches charges or releases the queue missed.
 */
final class Plans {
	const TICK_HOOK   = 'cc_bookings_recurring_tick';
	const TICK_OPTION = 'cc_bookings_recurring_tick_checked';

	public static function init(): void {
		add_action( self::TICK_HOOK, [ self::class, 'tick' ] );
		add_action( 'action_scheduler_init', [ self::class, 'schedule_tick' ] );
		add_action( 'cc_booking_completed', [ self::class, 'top_up_for' ] );
		add_action( 'cc_booking_cancelled', [ self::class, 'top_up_for' ] );
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	public static function get( int $id ): ?array {
		$plan = $id ? Db::get( Recurring::TABLE, $id ) : null;
		return $plan ? self::cast( $plan ) : null;
	}

	public static function by_ref( string $ref ): ?array {
		$ref  = strtoupper( sanitize_text_field( $ref ) );
		$plan = ctype_digit( $ref ) ? Db::get( Recurring::TABLE, (int) $ref ) : Db::find( Recurring::TABLE, [ 'ref' => $ref ] );
		return $plan ? self::cast( $plan ) : null;
	}

	public static function for_booking( array $booking ): ?array {
		return (int) $booking['series_id'] ? self::get( (int) $booking['series_id'] ) : null;
	}

	private static function cast( array $plan ): array {
		foreach ( [ 'interval_days', 'first_booking_id', 'price_pence' ] as $key ) {
			$plan[ $key ] = (int) $plan[ $key ];
		}
		return $plan;
	}

	public static function admin_url( int $id ): string {
		return admin_url( 'admin.php?page=cc-bookings-plans&id=' . $id );
	}

	/** All visits of a plan, oldest first. */
	public static function visits( int $plan_id ): array {
		return $plan_id ? Db::all( 'bookings', [ 'series_id' => $plan_id ], 'job_date ASC, id ASC' ) : [];
	}

	/** Visits still to come (start in the future, not skipped or cancelled). */
	public static function upcoming( int $plan_id ): array {
		return array_values( array_filter( self::visits( $plan_id ), fn( $v ) => in_array( $v['status'], Recurring::UPCOMING, true ) && Db::timestamp( $v['start_at'] ) > time() ) );
	}

	public static function is_paid( array $visit ): bool {
		return (int) $visit['paid_pence'] > 0 || 'paid' === $visit['payment_status'];
	}

	public static function weekday_label( array $plan ): string {
		return $plan['next_date'] ? Slots::date_label( (string) $plan['next_date'], 'l' ) . 's' : '';
	}

	/** Customer link for managing the plan: the next visit's booking page (or the first booking's). */
	public static function manage_url( array $plan ): string {
		$visit = self::upcoming( (int) $plan['id'] )[0] ?? Bookings::get( (int) $plan['first_booking_id'] );
		return $visit ? Aftercare::link( $visit ) . '#cc-plan' : '';
	}

	/** Plan summary rows for emails and pages. */
	public static function details_lines( array $plan ): array {
		$charge = Recurring::charge_hours();
		$next   = [];
		foreach ( array_slice( self::upcoming( (int) $plan['id'] ), 0, 6 ) as $visit ) {
			$start  = Db::timestamp( $visit['start_at'] );
			$next[] = Slots::date_label( (string) $visit['job_date'], 'D j M' ) . ' — ' . ( self::is_paid( $visit )
				? 'paid'
				: ( 'payment_due' === $visit['status'] ? 'payment due' : 'card charged ' . wp_date( 'D j M', $start - $charge * HOUR_IN_SECONDS ) ) );
		}
		return [
			[ 'Regular plan', $plan['ref'] . ( 'active' === $plan['status'] ? '' : ' (' . strtolower( Recurring::PLAN_STATUSES[ $plan['status'] ] ?? $plan['status'] ) . ')' ) ],
			[ 'How often', Recurring::label( (string) $plan['frequency'] ) . ( $plan['next_date'] ? ', ' . self::weekday_label( $plan ) . ', ' . Slots::label( (string) $plan['slot'] ) : '' ) ],
			[ 'Price per visit', Money::format( (int) $plan['price_pence'] ) ],
			[ 'Payment', 'cancelled' === $plan['status'] ? 'No more charges' : ( $plan['card_label'] ? sprintf( '%s, charged %d hours before each visit', $plan['card_label'], $charge ) : sprintf( 'Charged %d hours before each visit', $charge ) ) ],
			[ 'Upcoming visits', $next ? implode( "\n", $next ) : ( 'active' === $plan['status'] ? 'Being arranged' : 'None' ) ],
		];
	}

	public static function details_html( array $plan ): string {
		return Notify::details_html( self::details_lines( $plan ) );
	}

	/* ---------------------------------------------------------------------
	 * Creating a plan and its visits
	 * ------------------------------------------------------------------ */

	/**
	 * Start a plan from the paid first booking of a regular quote. Idempotent per quote.
	 *
	 * @return int|\WP_Error Plan id.
	 */
	public static function create( array $booking, array $quote ) {
		if ( ! Db::lock( 'plan-create:' . $quote['id'] ) ) {
			return new \WP_Error( 'busy', 'Plan is being created.' );
		}
		try {
			$existing = Db::find( Recurring::TABLE, [ 'quote_id' => (int) $quote['id'] ] );
			if ( $existing ) {
				return (int) $existing['id'];
			}
			$consent   = (array) ( $quote['consent']['recurring'] ?? [] );
			$frequency = (string) ( $consent['frequency'] ?? '' );
			if ( ! isset( Recurring::FREQUENCIES[ $frequency ] ) ) {
				$frequency = Recurring::frequency_for( (string) $quote['service_slug'], (array) $quote['answers'] );
			}
			$interval = Recurring::interval( $frequency );
			$next     = Recurring::add_days( (string) $booking['job_date'], $interval );
			$terms    = Recurring::quote_plan( $quote );
			$pence    = (int) ( $consent['visit_pence'] ?? 0 ) ?: (int) ( $terms['visit_pence'] ?? $booking['total_pence'] );
			$items    = (array) ( $terms['items'] ?? [] );
			if ( Pricing::sum( $items ) !== $pence ) {
				$items = [ Pricing::line( 'base', $booking['service_name'] . ' — regular visit', 1, $pence ) ];
			}
			$provider = (string) ( $booking['meta']['provider'] ?? 'stripe' );
			$card     = Plan_Charges::card_from_payment( $provider, (string) $booking['stripe_payment_intent'], (string) ( $booking['meta']['stripe_customer'] ?? '' ) );

			$data = array_intersect_key( $booking, array_flip( [
				'service_id', 'service_slug', 'service_name', 'answers', 'slot', 'hours', 'cleaners',
				'customer_name', 'email', 'phone', 'address_1', 'address_2', 'town', 'postcode', 'district',
				'access_notes', 'marketing_opt_in', 'is_test',
			] ) );
			$plan_id = Db::insert( Recurring::TABLE, $data + [
				'ref'                   => Tokens::reference( 'P', Recurring::TABLE ),
				'status'                => 'active',
				'frequency'             => $frequency,
				'interval_days'         => $interval,
				'quote_id'              => (int) $quote['id'],
				'first_booking_id'      => (int) $booking['id'],
				'items'                 => $items,
				'price_pence'           => $pence,
				'next_date'             => $next,
				'provider'              => in_array( $provider, [ 'stripe', 'simulated', 'manual' ], true ) ? $provider : 'stripe',
				'stripe_customer'       => (string) ( $card['customer'] ?? '' ),
				'stripe_payment_method' => (string) ( $card['payment_method'] ?? '' ),
				'card_label'            => (string) ( $card['card_label'] ?? '' ),
				'consent'               => $consent,
				'meta'                  => [ 'bespoke' => (int) $quote['is_bespoke'] ],
			] );
			if ( ! $plan_id ) {
				return new \WP_Error( 'db', 'Could not save the plan.' );
			}
			$meta = (array) $booking['meta'] + [ 'plan_id' => $plan_id, 'visit_no' => 1 ];
			Db::update( 'bookings', (int) $booking['id'], [ 'series_id' => $plan_id, 'meta' => $meta ] );
			$plan = self::get( $plan_id );
			Events::log( 'plan', $plan_id, 'created', sprintf( '%s plan started from booking %s at %s per visit%s', Recurring::label( $frequency ), $booking['ref'], Money::format( $pence ), $plan['card_label'] ? '; card ' . $plan['card_label'] : '; no saved card' ) );
			Events::log( 'booking', (int) $booking['id'], 'plan_started', 'Visit 1 of regular plan ' . $plan['ref'] );
			if ( ! $plan['stripe_payment_method'] && 'manual' !== $provider ) {
				Bookings::alert( 'Regular plan without a saved card', sprintf( 'Plan %s (%s) started, but no card was saved, so later visits can\'t be charged automatically. The customer will get a pay link for each visit.', $plan['ref'], $plan['customer_name'] ), [ 'booking_id' => (int) $booking['id'] ] );
			}
		} finally {
			Db::unlock( 'plan-create:' . $quote['id'] );
		}
		do_action( 'cc_plan_created', $plan_id, (int) $booking['id'] );
		self::generate( $plan_id );
		return $plan_id;
	}

	/**
	 * Keep `horizon_visits` upcoming visits. New visits must start after the charge time plus an hour, so every
	 * visit gets its normal automatic charge.
	 *
	 * @return int Visits created.
	 */
	public static function generate( int $plan_id ): int {
		if ( ! Db::lock( 'plan:' . $plan_id ) ) {
			return 0;
		}
		$created = 0;
		try {
			$plan = self::get( $plan_id );
			if ( ! $plan || 'active' !== $plan['status'] || ! $plan['next_date'] ) {
				return 0;
			}
			$horizon  = max( 1, min( 12, (int) Recurring::setting( 'horizon_visits' ) ) );
			$earliest = time() + ( Recurring::charge_hours() + 1 ) * HOUR_IN_SECONDS;
			$date     = (string) $plan['next_date'];
			$count    = count( self::upcoming( $plan_id ) );
			$skipped  = array_flip( (array) ( $plan['meta']['skip_dates'] ?? [] ) );
			for ( $guard = 0; $guard < 60 && $count < $horizon; $guard++ ) {
				$start = Slots::start_timestamp( $date, (string) $plan['slot'] );
				if ( $start >= $earliest && ! isset( $skipped[ $date ] ) ) {
					if ( Slots::is_working_day( $date ) ) {
						self::create_visit( $plan, $date );
						$created++;
						$count++;
					} else {
						Events::log( 'plan', $plan_id, 'visit_not_created', sprintf( 'No visit on %s: we are closed that day', Slots::date_label( $date, 'D j M Y' ) ) );
						Bookings::alert( 'Regular visit falls on a closed day', sprintf( 'Plan %s (%s): %s is a closed day, so no visit was created for it. Arrange another day with the customer if needed.', $plan['ref'], $plan['customer_name'], Slots::date_label( $date, 'l j F' ) ) );
					}
				}
				$date = Recurring::add_days( $date, (int) $plan['interval_days'] );
			}
			if ( $date !== $plan['next_date'] ) {
				Db::update( Recurring::TABLE, $plan_id, [ 'next_date' => $date ] );
			}
		} finally {
			Db::unlock( 'plan:' . $plan_id );
		}
		return $created;
	}

	private static function create_visit( array $plan, string $date ): int {
		$first = Bookings::get( (int) $plan['first_booking_id'] );
		$base  = preg_replace( '/\D/', '', (string) ( $first['ref'] ?? '' ) ) ?: (string) $plan['id'];
		$n     = Db::count( 'bookings', [ 'series_id' => (int) $plan['id'] ] ) + 1;
		while ( Bookings::by_ref( 'B-' . $base . sprintf( '%02d', $n ) ) ) {
			$n++;
		}
		$ref   = 'B-' . $base . sprintf( '%02d', $n );
		$start = Slots::start_timestamp( $date, (string) $plan['slot'] );
		Manage::slot_context( $plan );
		$fits = Slots::is_available( $date, (string) $plan['slot'], (float) $plan['hours'], (int) $plan['cleaners'] );

		$data = array_intersect_key( $plan, array_flip( [
			'service_id', 'service_slug', 'service_name', 'answers', 'slot', 'hours', 'cleaners',
			'customer_name', 'email', 'phone', 'address_1', 'address_2', 'town', 'postcode', 'district',
			'access_notes', 'marketing_opt_in', 'is_test',
		] ) );
		$id = Db::insert( 'bookings', $data + [
			'ref'            => $ref,
			'quote_id'       => 0,
			'series_id'      => (int) $plan['id'],
			'job_date'       => $date,
			'status'         => 'scheduled',
			'payment_status' => 'unpaid',
			'start_at'       => Db::datetime( $start ),
			'end_at'         => Db::datetime( $start + (int) round( (float) $plan['hours'] * HOUR_IN_SECONDS ) ),
			'total_pence'    => (int) $plan['price_pence'],
			'paid_pence'     => 0,
			'currency'       => 'gbp',
			'meta'           => [ 'provider' => 'plan', 'plan_id' => (int) $plan['id'], 'plan_ref' => $plan['ref'], 'visit_no' => $n ],
		] );
		self::set_items( $id, (array) $plan['items'] );
		Events::log( 'booking', $id, 'visit_scheduled', sprintf( 'Visit %d of regular plan %s; %s to be charged on %s', $n, $plan['ref'], Money::format( (int) $plan['price_pence'] ), wp_date( 'D j M, H:i', $start - Recurring::charge_hours() * HOUR_IN_SECONDS ) ) );
		Plan_Charges::schedule( $id );
		if ( ! $fits ) {
			Bookings::alert( 'Regular visit may be over capacity', sprintf( 'Plan %s: the visit on %s (%s) was added, but the slot looks full. Please check staffing or move it.', $plan['ref'], Bookings::start_label( Bookings::get( $id ) ), $ref ), [ 'booking_id' => $id ] );
		}
		do_action( 'cc_plan_visit_created', (int) $plan['id'], $id );
		return $id;
	}

	private static function set_items( int $booking_id, array $items ): void {
		Db::delete( 'booking_items', [ 'booking_id' => $booking_id ] );
		foreach ( array_values( $items ) as $i => $item ) {
			Db::insert( 'booking_items', [
				'booking_id'   => $booking_id,
				'type'         => $item['type'] ?? 'base',
				'label'        => $item['label'] ?? '',
				'quantity'     => $item['qty'] ?? 1,
				'unit_pence'   => (int) ( $item['unit'] ?? $item['amount'] ?? 0 ),
				'amount_pence' => (int) ( $item['amount'] ?? 0 ),
				'sort'         => $i,
			] );
		}
	}

	/** After a visit completes or is cancelled, keep the horizon full. */
	public static function top_up_for( $booking_id ): void {
		$booking = Bookings::get( (int) $booking_id );
		if ( $booking && (int) $booking['series_id'] ) {
			self::generate( (int) $booking['series_id'] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Background tick
	 * ------------------------------------------------------------------ */

	public static function schedule_tick(): void {
		if ( (int) get_option( self::TICK_OPTION ) > time() - DAY_IN_SECONDS || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::TICK_HOOK, [], Queue::GROUP ) ) {
			as_schedule_recurring_action( time() + 300, 6 * HOUR_IN_SECONDS, self::TICK_HOOK, [], Queue::GROUP );
		}
		update_option( self::TICK_OPTION, time(), true );
	}

	/** Top up every active plan and run any charge or release the queue missed. */
	public static function tick(): void {
		global $wpdb;
		foreach ( Db::all( Recurring::TABLE, [ 'status' => 'active' ], 'id ASC' ) as $plan ) {
			self::generate( (int) $plan['id'] );
		}
		$visits = $wpdb->get_results( 'SELECT id, status, start_at FROM ' . Schema::table( 'bookings' ) . " WHERE series_id > 0 AND status IN ('scheduled','payment_due') AND paid_pence = 0", ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $visits as $visit ) {
			$start = Db::timestamp( $visit['start_at'] );
			if ( 'scheduled' === $visit['status'] && $start - Recurring::charge_hours() * HOUR_IN_SECONDS <= time() && ! Queue::next( Plan_Charges::CHARGE_HOOK, [ (int) $visit['id'] ] ) ) {
				Plan_Charges::charge( (int) $visit['id'], 'tick' );
			} elseif ( 'payment_due' === $visit['status'] && $start - Recurring::release_hours() * HOUR_IN_SECONDS <= time() && ! Queue::next( Plan_Charges::RELEASE_HOOK, [ (int) $visit['id'] ] ) ) {
				Plan_Charges::release( (int) $visit['id'] );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Changes (customer and admin)
	 * ------------------------------------------------------------------ */

	/** @return true|\WP_Error */
	public static function skip( int $plan_id, int $visit_id, string $actor = 'customer' ) {
		$plan  = self::get( $plan_id );
		$visit = Bookings::get( $visit_id );
		if ( ! $plan || ! $visit || (int) $visit['series_id'] !== $plan_id ) {
			return new \WP_Error( 'missing', 'That visit isn\'t part of this plan.' );
		}
		if ( ! in_array( $visit['status'], [ 'scheduled', 'payment_due' ], true ) || self::is_paid( $visit ) ) {
			return new \WP_Error( 'paid', 'That visit has already been paid for, so it can\'t be skipped here. You can cancel it from its own booking page.' );
		}
		Bookings::set_status( $visit_id, 'skipped', 'Skipped by ' . $actor );
		// Remember the date so a later frequency change or resume doesn't book it again.
		$meta  = (array) $plan['meta'];
		$today = wp_date( 'Y-m-d' );
		$dates = array_filter( array_merge( (array) ( $meta['skip_dates'] ?? [] ), [ (string) $visit['job_date'] ] ), fn( $d ) => $d >= $today );
		$meta['skip_dates'] = array_values( array_unique( $dates ) );
		Db::update( Recurring::TABLE, $plan_id, [ 'meta' => $meta ] );
		self::generate( $plan_id );
		self::notify( $plan_id, 'skip', sprintf( 'You\'ve skipped your visit on %s. No payment will be taken for it.', Bookings::start_label( $visit ) ), $actor, 'visit skipped' );
		return true;
	}

	/** Skip every unpaid upcoming visit. @return string[] Labels of paid visits that still go ahead. */
	private static function skip_unpaid( int $plan_id, string $note ): array {
		$kept = [];
		foreach ( self::upcoming( $plan_id ) as $visit ) {
			if ( self::is_paid( $visit ) ) {
				$kept[] = Bookings::start_label( $visit );
			} elseif ( in_array( $visit['status'], [ 'scheduled', 'payment_due' ], true ) ) {
				Bookings::set_status( (int) $visit['id'], 'skipped', $note );
			}
		}
		return $kept;
	}

	/** @return true|\WP_Error */
	public static function pause( int $plan_id, string $actor = 'customer' ) {
		$plan = self::get( $plan_id );
		if ( ! $plan || 'active' !== $plan['status'] ) {
			return new \WP_Error( 'state', 'Only an active plan can be paused.' );
		}
		Db::update( Recurring::TABLE, $plan_id, [ 'status' => 'paused', 'paused_at' => Db::now() ] );
		$kept = self::skip_unpaid( $plan_id, 'Plan paused by ' . $actor );
		self::notify( $plan_id, 'pause', 'Your regular plan is paused. We won\'t book or charge any more visits until you resume it.' . self::kept_note( $kept ), $actor, 'paused' );
		return true;
	}

	/** @return true|\WP_Error */
	public static function resume( int $plan_id, string $actor = 'customer' ) {
		$plan = self::get( $plan_id );
		if ( ! $plan || 'paused' !== $plan['status'] ) {
			return new \WP_Error( 'state', 'Only a paused plan can be resumed.' );
		}
		Db::update( Recurring::TABLE, $plan_id, [ 'status' => 'active', 'paused_at' => null, 'next_date' => self::restart_date( $plan ) ] );
		self::generate( $plan_id );
		$next = array_values( array_filter( self::upcoming( $plan_id ), fn( $v ) => ! self::is_paid( $v ) ) )[0] ?? null;
		self::notify( $plan_id, 'resume', 'Your regular plan is back on.' . ( $next ? sprintf( ' Your next new visit is %s; we\'ll charge %s to your card %d hours before.', Bookings::start_label( $next ), Money::format( (int) $next['total_pence'] ), Recurring::charge_hours() ) : '' ), $actor, 'resumed' );
		return true;
	}

	/** @return true|\WP_Error */
	public static function change_frequency( int $plan_id, string $frequency, string $actor = 'customer' ) {
		$plan = self::get( $plan_id );
		if ( ! $plan || ! in_array( $plan['status'], [ 'active', 'paused' ], true ) ) {
			return new \WP_Error( 'state', 'This plan has ended.' );
		}
		if ( ! isset( Recurring::FREQUENCIES[ $frequency ] ) ) {
			return new \WP_Error( 'frequency', 'Choose how often.' );
		}
		if ( $frequency === $plan['frequency'] ) {
			return new \WP_Error( 'same', 'Your plan is already ' . strtolower( Recurring::label( $frequency ) ) . '.' );
		}
		$interval = Recurring::interval( $frequency );
		$answers  = (array) $plan['answers'];
		if ( isset( $answers['frequency'] ) ) {
			$answers['frequency'] = $frequency;
		}
		$upcoming = self::upcoming( $plan_id );
		$anchor   = '';
		foreach ( $upcoming as $i => $visit ) {
			if ( 0 === $i || self::is_paid( $visit ) ) {
				$anchor = (string) $visit['job_date'];
			}
		}
		$price = ! empty( $plan['meta']['bespoke'] ) ? null : Recurring::visit_price( (string) $plan['service_slug'], $answers, $anchor ? Recurring::add_days( $anchor, $interval ) : (string) $plan['next_date'], (string) $plan['slot'], $frequency );
		$pence = $price ? (int) $price['total'] : (int) $plan['price_pence'];
		$items = $price ? $price['items'] : (array) $plan['items'];

		Db::update( Recurring::TABLE, $plan_id, [
			'frequency'     => $frequency,
			'interval_days' => $interval,
			'answers'       => $answers,
			'price_pence'   => $pence,
			'items'         => $items,
			'next_date'     => $anchor ? Recurring::add_days( $anchor, $interval ) : self::restart_date( array_merge( $plan, [ 'interval_days' => $interval ] ) ),
		] );
		foreach ( $upcoming as $i => $visit ) {
			if ( self::is_paid( $visit ) ) {
				continue;
			}
			if ( 0 === $i ) {
				Db::update( 'bookings', (int) $visit['id'], [ 'total_pence' => $pence, 'answers' => $answers ] );
				self::set_items( (int) $visit['id'], $items );
			} elseif ( in_array( $visit['status'], [ 'scheduled', 'payment_due' ], true ) ) {
				Bookings::set_status( (int) $visit['id'], 'skipped', 'Plan changed to ' . strtolower( Recurring::label( $frequency ) ) );
			}
		}
		self::generate( $plan_id );
		self::notify( $plan_id, 'frequency', sprintf( 'Your regular plan now runs %s at %s per visit.', Recurring::every( $frequency ), Money::format( $pence ) ), $actor, 'now ' . strtolower( Recurring::label( $frequency ) ) );
		return true;
	}

	/** @return true|\WP_Error */
	public static function cancel( int $plan_id, string $actor = 'customer', string $reason = '' ) {
		$plan = self::get( $plan_id );
		if ( ! $plan || 'cancelled' === $plan['status'] ) {
			return new \WP_Error( 'state', 'This plan has already ended.' );
		}
		Db::update( Recurring::TABLE, $plan_id, [ 'status' => 'cancelled', 'cancelled_at' => Db::now() ] + ( $reason ? [ 'admin_note' => trim( (string) $plan['admin_note'] . "\nCancelled: " . $reason ) ] : [] ) );
		$kept = self::skip_unpaid( $plan_id, 'Plan cancelled by ' . $actor );
		self::notify( $plan_id, 'cancel', 'Your regular plan has ended and we won\'t take any more payments.' . self::kept_note( $kept ), $actor, 'cancelled' );
		return true;
	}

	/** " Your visit(s) on … already paid …" for pause / cancel messages. */
	private static function kept_note( array $kept ): string {
		if ( ! $kept ) {
			return '';
		}
		if ( 1 === count( $kept ) ) {
			return ' Your visit on ' . $kept[0] . ' is already paid and still goes ahead; cancel it from its booking page if you don\'t need it.';
		}
		$last = array_pop( $kept );
		return ' Your visits on ' . implode( ', ', $kept ) . ' and ' . $last . ' are already paid and still go ahead; cancel them from their booking pages if you don\'t need them.';
	}

	/** Save a new card for off-session charges, then retry any visit waiting for payment. */
	public static function set_card( int $plan_id, string $customer, string $payment_method, string $label, string $actor = 'customer' ): void {
		$plan = self::get( $plan_id );
		if ( ! $plan || '' === $payment_method ) {
			return;
		}
		Db::update( Recurring::TABLE, $plan_id, array_filter( [ 'stripe_customer' => $customer, 'stripe_payment_method' => $payment_method, 'card_label' => $label ] ) );
		self::notify( $plan_id, 'card', sprintf( 'The card for your regular payments is now %s.', $label ?: 'updated' ), $actor, 'card updated' );
		foreach ( self::upcoming( $plan_id ) as $visit ) {
			if ( 'payment_due' === $visit['status'] ) {
				Plan_Charges::charge( (int) $visit['id'], 'card_updated' );
			}
		}
	}

	/**
	 * First suitable date for restarting: the plan's weekday, after any visit still going ahead (plus the
	 * interval) and far enough ahead for the normal charge.
	 */
	public static function restart_date( array $plan ): string {
		$tz       = wp_timezone();
		$anchor   = date_create_immutable( (string) ( $plan['next_date'] ?: wp_date( 'Y-m-d' ) ), $tz );
		$weekday  = (int) $anchor->format( 'N' );
		$earliest = time() + ( Recurring::charge_hours() + 24 ) * HOUR_IN_SECONDS;
		$last     = '';
		foreach ( self::upcoming( (int) $plan['id'] ) as $visit ) {
			$last = max( $last, (string) $visit['job_date'] );
		}
		$from = date_create_immutable( $last ? Recurring::add_days( $last, (int) $plan['interval_days'] ) : wp_date( 'Y-m-d' ), $tz );
		for ( $i = 0; $i < 400; $i++ ) {
			$day = $from->modify( '+' . $i . ' days' );
			if ( (int) $day->format( 'N' ) === $weekday && Slots::start_timestamp( $day->format( 'Y-m-d' ), (string) $plan['slot'] ) >= $earliest ) {
				return $day->format( 'Y-m-d' );
			}
		}
		return $from->format( 'Y-m-d' );
	}

	/** Log, email the customer, email the admin for customer changes, and fire cc_plan_changed. */
	public static function notify( int $plan_id, string $change, string $message, string $actor, string $short ): void {
		$plan = self::get( $plan_id );
		if ( ! $plan ) {
			return;
		}
		Events::log( 'plan', $plan_id, 'plan_' . $change, $message, [], $actor );
		$vars = [
			'name'         => $plan['customer_name'],
			'first_name'   => Notify::first_name( $plan['customer_name'] ),
			'email'        => $plan['email'],
			'service'      => $plan['service_name'],
			'plan_ref'     => $plan['ref'],
			'change'       => $message,
			'change_short' => $short,
			'details'      => self::details_html( $plan ),
			'manage_url'   => self::manage_url( $plan ),
			'admin_url'    => self::admin_url( $plan_id ),
		];
		Notify::send( 'plan_updated', $plan['email'], $vars, [ 'plan', $plan_id ] );
		if ( 'customer' === $actor ) {
			Notify::send( 'plan_admin', Settings::admin_email(), array_merge( $vars, [ 'change' => sprintf( '%s (%s) changed their regular plan: %s', $plan['customer_name'], $plan['email'], $message ) ] ), [ 'plan', $plan_id ] );
		}
		do_action( 'cc_plan_changed', $plan_id, $change, $actor );
	}
}

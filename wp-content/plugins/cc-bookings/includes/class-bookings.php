<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Bookings: created only when payment is confirmed (Stripe webhook, simulated payment or an admin-recorded
 * manual payment, all through confirm_payment()).
 *
 * Hooks fired:
 *   cc_booking_confirmed( $booking_id, $context )            after payment; $context['booking_url'] is a fresh customer link
 *   cc_booking_status_changed( $booking_id, $to, $from )
 *   cc_booking_assigned( $booking_id, $cleaner_id, $offer_id )
 *   cc_booking_unassigned( $booking_id, $cleaner_id, $offer_id )
 *   cc_booking_refunded( $booking_id, $amount_pence, $payment_id )
 *   cc_booking_cancelled( $booking_id, $reason )
 *   cc_booking_completed( $booking_id )
 *   cc_booking_topup_created( $booking_id, $payment_id ) / cc_booking_topup_paid( $booking_id, $payment_id )
 *   cc_bookings_admin_alert( $subject, $message, $context )
 */
final class Bookings {
	public static function init(): void {}

	public static function get( int $id ): ?array {
		return Db::get( 'bookings', $id );
	}

	public static function by_ref( string $ref ): ?array {
		return Db::find( 'bookings', [ 'ref' => strtoupper( sanitize_text_field( $ref ) ) ] );
	}

	public static function verify( string $ref, string $token ): ?array {
		$booking = self::by_ref( $ref );
		return $booking && Tokens::matches( $token, $booking ) ? $booking : null;
	}

	public static function url( array $booking, string $token ): string {
		return home_url( '/booking/' . $booking['ref'] . '/' . $token . '/' );
	}

	public static function link( array $booking ): string {
		return self::url( $booking, Tokens::issue( 'bookings', $booking['id'] ) );
	}

	public static function admin_url( int $id ): string {
		return admin_url( 'admin.php?page=cc-bookings-bookings&id=' . $id );
	}

	public static function items( int $id ): array {
		return Db::all( 'booking_items', [ 'booking_id' => $id ], 'sort ASC, id ASC' );
	}

	public static function payments( int $id ): array {
		return Db::all( 'payments', [ 'booking_id' => $id ], 'id ASC' );
	}

	/** Cleaners on the job (assigned or accepted offers), with cleaner details. */
	public static function crew( int $id ): array {
		$out = [];
		foreach ( Db::all( 'job_offers', [ 'booking_id' => $id, 'status' => Status::on_job() ], 'id ASC' ) as $offer ) {
			$offer['cleaner'] = Cleaners::get( $offer['cleaner_id'] );
			$out[]            = $offer;
		}
		return $out;
	}

	public static function start_label( array $booking ): string {
		return $booking['job_date'] ? Slots::date_label( $booking['job_date'] ) . ', ' . Slots::label( $booking['slot'] ) : 'Date to be arranged';
	}

	public static function full_address( array $booking ): string {
		return implode( ', ', array_filter( [ $booking['address_1'], $booking['address_2'], $booking['town'], $booking['postcode'] ] ) );
	}

	/**
	 * The one confirmation path. Idempotent per Stripe session id, serialised with a MySQL lock.
	 *
	 * @param array $args quote_id, session_id, payment_intent, amount (pence), currency, provider (stripe|simulated|manual)
	 * @return int|\WP_Error Booking id.
	 */
	public static function confirm_payment( array $args ) {
		$session = (string) ( $args['session_id'] ?? '' );
		if ( '' === $session ) {
			return new \WP_Error( 'no_session', 'Missing payment session id.' );
		}
		if ( ! Db::lock( 'pay:' . $session ) ) {
			return new \WP_Error( 'locked', 'Payment is being processed; retry later.' );
		}

		try {
			$existing = Db::find( 'bookings', [ 'stripe_session_id' => $session ] );
			if ( $existing ) {
				Events::log( 'booking', $existing['id'], 'payment_duplicate', 'Repeat confirmation for the same session ignored', [ 'session' => $session ], $args['provider'] ?? 'stripe' );
				return $existing['id'];
			}

			$quote = Quotes::get( (int) ( $args['quote_id'] ?? 0 ) );
			if ( ! $quote ) {
				return new \WP_Error( 'no_quote', 'Quote not found for session ' . $session );
			}
			$amount   = (int) ( $args['amount'] ?? 0 );
			$provider = (string) ( $args['provider'] ?? 'stripe' );

			if ( $quote['booking_id'] ) {
				// Paid twice for one quote (e.g. two checkout tabs): record it and ask the admin to refund.
				self::record_charge( $quote, (int) $quote['booking_id'], $args, 'Duplicate payment — refund needed' );
				self::alert( 'Duplicate payment', sprintf( 'Quote %s was paid again (session %s, %s). Please refund the duplicate from booking %d.', $quote['ref'], $session, Money::format( $amount ), $quote['booking_id'] ), [ 'booking_id' => (int) $quote['booking_id'] ] );
				return (int) $quote['booking_id'];
			}

			$fits = ! $quote['job_date'] || Slots::is_available( $quote['job_date'], $quote['slot'], (float) $quote['hours'], (int) $quote['cleaners'], $quote['id'] )
				|| Db::timestamp( $quote['hold_expires_at'] ) > time();

			$start = $quote['job_date'] ? Slots::start_timestamp( $quote['job_date'], $quote['slot'] ) : 0;
			$data  = array_intersect_key( $quote, array_flip( [
				'service_id', 'service_slug', 'service_name', 'answers', 'job_date', 'slot', 'hours', 'cleaners',
				'customer_name', 'email', 'phone', 'address_1', 'address_2', 'town', 'postcode', 'district',
				'access_notes', 'marketing_opt_in', 'is_test',
			] ) );
			$data += [
				'ref'                   => 'B-' . preg_replace( '/^Q-/', '', $quote['ref'] ),
				'quote_id'              => $quote['id'],
				'status'                => 'confirmed',
				'payment_status'        => 'paid',
				'start_at'              => $start ? Db::datetime( $start ) : null,
				'end_at'                => $start ? Db::datetime( $start + (int) round( (float) $quote['hours'] * HOUR_IN_SECONDS ) ) : null,
				'total_pence'           => (int) $quote['total_pence'],
				'paid_pence'            => $amount,
				'currency'              => strtolower( (string) ( $args['currency'] ?? 'gbp' ) ),
				'stripe_session_id'     => $session,
				'stripe_payment_intent' => (string) ( $args['payment_intent'] ?? '' ),
				'meta'                  => [ 'consent' => $quote['consent'], 'provider' => $provider, 'photos' => $quote['photos'] ],
				'confirmed_at'          => Db::now(),
			];
			$booking_id = Db::insert( 'bookings', $data );
			if ( ! $booking_id ) {
				return new \WP_Error( 'db', 'Could not create booking for ' . $quote['ref'] );
			}

			foreach ( array_values( (array) $quote['items'] ) as $i => $item ) {
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

			self::record_charge( $quote, $booking_id, $args );
			Db::update( 'quotes', $quote['id'], [ 'booking_id' => $booking_id, 'hold_expires_at' => null ] );
			Quotes::set_status( $quote['id'], 'accepted', 'Paid and booked as ' . $data['ref'], true );
			Events::log( 'booking', $booking_id, 'confirmed', sprintf( 'Payment of %s received (%s); booking confirmed', Money::format( $amount ), $provider ), [ 'session' => $session ], $provider );

			if ( $amount !== (int) $quote['total_pence'] ) {
				self::alert( 'Payment amount differs', sprintf( 'Booking %s: paid %s but the quote total was %s.', $data['ref'], Money::format( $amount ), Money::format( (int) $quote['total_pence'] ) ), [ 'booking_id' => $booking_id ] );
			}
			if ( ! $fits ) {
				self::alert( 'Slot over capacity', sprintf( 'Booking %s was paid after its slot hold ended and %s is now over capacity. Please check staffing or move the booking.', $data['ref'], self::start_label( self::get( $booking_id ) ) ), [ 'booking_id' => $booking_id ] );
			}
		} finally {
			Db::unlock( 'pay:' . $session );
		}

		$booking = self::get( $booking_id );
		do_action( 'cc_booking_confirmed', $booking_id, [ 'booking_url' => self::link( $booking ), 'provider' => $provider ] );
		return $booking_id;
	}

	private static function record_charge( array $quote, int $booking_id, array $args, string $note = '' ): void {
		$data = [
			'booking_id'            => $booking_id,
			'quote_id'              => $quote['id'],
			'status'                => 'succeeded',
			'provider'              => (string) ( $args['provider'] ?? 'stripe' ),
			'amount_pence'          => (int) ( $args['amount'] ?? 0 ),
			'stripe_payment_intent' => (string) ( $args['payment_intent'] ?? '' ),
			'note'                  => $note,
		];
		$payment = Db::find( 'payments', [ 'stripe_session_id' => (string) $args['session_id'], 'type' => 'charge' ] );
		if ( $payment ) {
			Db::update( 'payments', $payment['id'], $data );
		} else {
			Db::insert( 'payments', $data + [ 'type' => 'charge', 'stripe_session_id' => (string) $args['session_id'], 'created_by' => get_current_user_id() ] );
		}
	}

	public static function alert( string $subject, string $message, array $context = [] ): void {
		Events::log( isset( $context['booking_id'] ) ? 'booking' : 'system', (int) ( $context['booking_id'] ?? 0 ), 'admin_alert', $subject . ': ' . $message );
		do_action( 'cc_bookings_admin_alert', $subject, $message, $context );
	}

	public static function set_status( int $id, string $to, string $note = '', bool $force = false ): bool {
		$booking = self::get( $id );
		if ( ! $booking || ( ! $force && ! Status::can( 'booking', $booking['status'], $to ) ) ) {
			return false;
		}
		if ( $booking['status'] === $to ) {
			return true;
		}
		$data = [ 'status' => $to ];
		if ( 'completed' === $to ) {
			$data['completed_at'] = Db::now();
		} elseif ( 'cancelled' === $to ) {
			$data['cancelled_at'] = Db::now();
		}
		Db::update( 'bookings', $id, $data );
		Events::log( 'booking', $id, 'status', $note ?: sprintf( '%s → %s', Status::label( 'booking', $booking['status'] ), Status::label( 'booking', $to ) ), [ 'from' => $booking['status'], 'to' => $to ] );
		do_action( 'cc_booking_status_changed', $id, $to, $booking['status'] );
		return true;
	}

	/** Put a cleaner on a job (manual in phase 1; phase 2 dispatch uses source "auto"/"offer"). */
	public static function assign( int $booking_id, int $cleaner_id, string $source = 'manual' ) {
		$booking = self::get( $booking_id );
		$cleaner = Cleaners::get( $cleaner_id );
		if ( ! $booking || ! $cleaner ) {
			return new \WP_Error( 'missing', 'Booking or cleaner not found.' );
		}
		if ( in_array( $booking['status'], [ 'cancelled', 'completed' ], true ) ) {
			return new \WP_Error( 'closed', 'This booking is ' . $booking['status'] . '.' );
		}
		$existing = Db::find( 'job_offers', [ 'booking_id' => $booking_id, 'cleaner_id' => $cleaner_id ] );
		if ( $existing && in_array( $existing['status'], Status::on_job(), true ) ) {
			return new \WP_Error( 'exists', $cleaner['name'] . ' is already on this job.' );
		}
		$crew = self::crew( $booking_id );
		$data = [
			'status'       => 'assigned',
			'source'       => $source,
			'role'         => $crew ? 'member' : 'lead',
			'offered_at'   => Db::now(),
			'responded_at' => Db::now(),
		];
		if ( $existing ) {
			Db::update( 'job_offers', $existing['id'], $data );
			$offer_id = $existing['id'];
		} else {
			$offer_id = Db::insert( 'job_offers', $data + [ 'booking_id' => $booking_id, 'cleaner_id' => $cleaner_id ] );
		}
		Events::log( 'booking', $booking_id, 'assigned', sprintf( '%s assigned (%s)', $cleaner['name'], $source ), [ 'cleaner_id' => $cleaner_id, 'offer_id' => $offer_id ] );

		if ( count( $crew ) + 1 >= (int) $booking['cleaners'] ) {
			self::set_status( $booking_id, 'assigned', '', true );
		}
		do_action( 'cc_booking_assigned', $booking_id, $cleaner_id, $offer_id );
		return $offer_id;
	}

	public static function unassign( int $booking_id, int $offer_id, string $status = 'removed' ): void {
		$offer = Db::get( 'job_offers', $offer_id );
		if ( ! $offer || $offer['booking_id'] !== $booking_id ) {
			return;
		}
		Db::update( 'job_offers', $offer_id, [ 'status' => $status, 'responded_at' => Db::now() ] );
		$cleaner = Cleaners::get( $offer['cleaner_id'] );
		Events::log( 'booking', $booking_id, 'unassigned', sprintf( '%s taken off the job', $cleaner['name'] ?? 'Cleaner' ), [ 'cleaner_id' => $offer['cleaner_id'], 'offer_id' => $offer_id ] );
		$booking = self::get( $booking_id );
		if ( 'assigned' === $booking['status'] && count( self::crew( $booking_id ) ) < (int) $booking['cleaners'] ) {
			self::set_status( $booking_id, 'confirmed', 'Needs a cleaner again', true );
		}
		do_action( 'cc_booking_unassigned', $booking_id, $offer['cleaner_id'], $offer_id );
	}

	public static function refundable( array $booking ): int {
		return max( 0, (int) $booking['paid_pence'] - (int) $booking['refunded_pence'] );
	}

	/**
	 * Refund through Stripe (or record it, for simulated / manual payments).
	 *
	 * @return int|\WP_Error Refund payment row id.
	 */
	public static function refund( int $booking_id, int $amount, string $reason, bool $cancel = false ) {
		$booking = self::get( $booking_id );
		if ( ! $booking ) {
			return new \WP_Error( 'missing', 'Booking not found.' );
		}
		$amount = min( $amount, self::refundable( $booking ) );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'nothing', 'There is nothing left to refund.' );
		}

		$charge   = Db::find( 'payments', [ 'booking_id' => $booking_id, 'type' => 'charge', 'status' => 'succeeded' ], 'id ASC' );
		$provider = $charge['provider'] ?? 'manual';
		$refund   = null;
		if ( 'stripe' === $provider ) {
			if ( ! $booking['stripe_payment_intent'] ) {
				return new \WP_Error( 'no_intent', 'No Stripe payment found for this booking.' );
			}
			$refund = Stripe::refund( $booking['stripe_payment_intent'], $amount, [ 'booking_ref' => $booking['ref'], 'reason' => mb_substr( $reason, 0, 200 ) ], 'cc-refund-' . $booking_id . '-' . $booking['refunded_pence'] . '-' . $amount );
			if ( is_wp_error( $refund ) ) {
				Events::log( 'booking', $booking_id, 'refund_failed', $refund->get_error_message() );
				return $refund;
			}
		}

		$payment_id = Db::insert( 'payments', [
			'booking_id'            => $booking_id,
			'quote_id'              => $booking['quote_id'],
			'type'                  => 'refund',
			'status'                => 'succeeded',
			'provider'              => $provider,
			'amount_pence'          => $amount,
			'stripe_payment_intent' => $booking['stripe_payment_intent'],
			'stripe_refund_id'      => (string) ( $refund['id'] ?? '' ),
			'note'                  => mb_substr( $reason, 0, 255 ),
			'created_by'            => get_current_user_id(),
		] );
		self::apply_refund_total( $booking, (int) $booking['refunded_pence'] + $amount );
		Events::log( 'booking', $booking_id, 'refunded', sprintf( 'Refunded %s%s', Money::format( $amount ), $reason ? ': ' . $reason : '' ), [ 'payment_id' => $payment_id, 'provider' => $provider ] );
		do_action( 'cc_booking_refunded', $booking_id, $amount, $payment_id );

		if ( $cancel ) {
			self::cancel( $booking_id, $reason );
		}
		return $payment_id;
	}

	private static function apply_refund_total( array $booking, int $refunded ): void {
		Db::update( 'bookings', $booking['id'], [
			'refunded_pence' => $refunded,
			'payment_status' => $refunded >= (int) $booking['paid_pence'] ? 'refunded' : ( $refunded > 0 ? 'partially_refunded' : $booking['payment_status'] ),
		] );
	}

	/** charge.refunded webhook: pick up refunds made in the Stripe dashboard. */
	public static function sync_refund( string $payment_intent, int $amount_refunded ) {
		$booking = $payment_intent ? Db::find( 'bookings', [ 'stripe_payment_intent' => $payment_intent ] ) : null;
		if ( ! $booking ) {
			return 'ignored: no booking for payment';
		}
		$known = (int) $booking['refunded_pence'];
		if ( $amount_refunded <= $known ) {
			return 'refund already recorded';
		}
		$diff = $amount_refunded - $known;
		Db::insert( 'payments', [
			'booking_id'            => $booking['id'],
			'quote_id'              => $booking['quote_id'],
			'type'                  => 'refund',
			'status'                => 'succeeded',
			'amount_pence'          => $diff,
			'stripe_payment_intent' => $payment_intent,
			'note'                  => 'Refunded in the Stripe dashboard',
		] );
		self::apply_refund_total( $booking, $amount_refunded );
		Events::log( 'booking', $booking['id'], 'refunded', sprintf( 'Refund of %s made in Stripe', Money::format( $diff ) ), [], 'stripe' );
		return 'recorded refund ' . $diff;
	}

	public static function cancel( int $booking_id, string $reason = '' ): bool {
		if ( ! self::set_status( $booking_id, 'cancelled', 'Cancelled' . ( $reason ? ': ' . $reason : '' ) ) ) {
			return false;
		}
		foreach ( self::crew( $booking_id ) as $offer ) {
			Db::update( 'job_offers', $offer['id'], [ 'status' => 'withdrawn' ] );
		}
		do_action( 'cc_booking_cancelled', $booking_id, $reason );
		return true;
	}

	public static function complete( int $booking_id ): bool {
		if ( ! self::set_status( $booking_id, 'completed', 'Marked complete' ) ) {
			return false;
		}
		do_action( 'cc_booking_completed', $booking_id );
		return true;
	}

	/**
	 * Extra charge via a Stripe Payment Link, emailed to the customer.
	 *
	 * @return array|\WP_Error Payment row.
	 */
	public static function create_topup( int $booking_id, int $amount, string $reason ) {
		$booking = self::get( $booking_id );
		if ( ! $booking || $amount <= 0 ) {
			return new \WP_Error( 'invalid', 'Enter an amount.' );
		}
		$label = $reason ?: 'Additional cleaning';
		$link  = Stripe::create_payment_link( $booking, $amount, $label );
		if ( is_wp_error( $link ) ) {
			Events::log( 'booking', $booking_id, 'topup_failed', $link->get_error_message() );
			return $link;
		}
		$payment_id = Db::insert( 'payments', [
			'booking_id'             => $booking_id,
			'quote_id'               => $booking['quote_id'],
			'type'                   => 'top_up',
			'status'                 => 'pending',
			'amount_pence'           => $amount,
			'stripe_payment_link_id' => $link['id'],
			'url'                    => $link['url'],
			'note'                   => mb_substr( $label, 0, 255 ),
			'created_by'             => get_current_user_id(),
		] );
		Events::log( 'booking', $booking_id, 'topup_created', sprintf( 'Top-up link for %s: %s', Money::format( $amount ), $label ), [ 'payment_id' => $payment_id ] );
		do_action( 'cc_booking_topup_created', $booking_id, $payment_id );
		return Db::get( 'payments', $payment_id );
	}

	/** checkout.session.completed for a Payment Link. */
	public static function topup_paid( array $session ) {
		$payment = Db::find( 'payments', [ 'stripe_payment_link_id' => (string) $session['payment_link'], 'type' => 'top_up' ] );
		if ( ! $payment ) {
			return 'ignored: unknown payment link';
		}
		if ( 'succeeded' === $payment['status'] ) {
			return 'top-up already recorded';
		}
		$amount = (int) ( $session['amount_total'] ?? $payment['amount_pence'] );
		Db::update( 'payments', $payment['id'], [
			'status'                => 'succeeded',
			'amount_pence'          => $amount,
			'stripe_session_id'     => (string) $session['id'],
			'stripe_payment_intent' => (string) ( $session['payment_intent'] ?? '' ),
		] );
		$booking = self::get( $payment['booking_id'] );
		Db::insert( 'booking_items', [
			'booking_id'   => $booking['id'],
			'type'         => 'topup',
			'label'        => $payment['note'] ?: 'Additional charge',
			'unit_pence'   => $amount,
			'amount_pence' => $amount,
			'sort'         => 100,
		] );
		Db::update( 'bookings', $booking['id'], [ 'total_pence' => (int) $booking['total_pence'] + $amount, 'paid_pence' => (int) $booking['paid_pence'] + $amount ] );
		Stripe::deactivate_payment_link( (string) $session['payment_link'] );
		Events::log( 'booking', $booking['id'], 'topup_paid', sprintf( 'Top-up of %s paid', Money::format( $amount ) ), [ 'payment_id' => $payment['id'] ], 'stripe' );
		do_action( 'cc_booking_topup_paid', $booking['id'], $payment['id'] );
		return 'top-up recorded';
	}
}

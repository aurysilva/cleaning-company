<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * "Accept & pay": re-price, re-check the slot, hold it for 30 minutes and open Stripe Checkout.
 * Without Stripe keys the acceptance is recorded and the admin is asked to arrange payment.
 */
final class Checkout {
	public static function init(): void {
		add_action( 'cc_bookings_hold_expire', [ self::class, 'expire_hold' ], 10, 2 );
	}

	/**
	 * @param array $input terms (bool), early_start (bool), recurring (bool, regular-clean card authority), token (the customer's link token, reused for Stripe's return URLs)
	 * @return array{mode: string, url?: string, message?: string}|\WP_Error
	 */
	public static function start( array $quote, array $input ) {
		if ( $quote['booking_id'] ) {
			return new \WP_Error( 'booked', 'This quote has already been booked.', [ 'status' => 409 ] );
		}
		if ( Quotes::is_expired( $quote ) ) {
			return new \WP_Error( 'expired', 'This quote has expired. Please get an updated price.', [ 'status' => 410 ] );
		}
		if ( ! Quotes::is_payable( $quote ) ) {
			return new \WP_Error( 'closed', 'This quote can\'t be paid online. Please contact us.', [ 'status' => 409 ] );
		}
		if ( empty( $input['terms'] ) ) {
			return new \WP_Error( 'terms', 'Please agree to the terms and cancellation policy.', [ 'status' => 400, 'errors' => [ 'terms' => 'Required' ] ] );
		}
		$early = Quotes::needs_early_start( $quote );
		if ( $early && empty( $input['early_start'] ) ) {
			return new \WP_Error( 'early_start', 'Please confirm you\'d like us to start within the 14-day cancellation period.', [ 'status' => 400, 'errors' => [ 'early_start' => 'Required' ] ] );
		}

		// Server-side re-price for instant quotes; admin-priced quotes keep the agreed price.
		if ( ! $quote['is_bespoke'] ) {
			$price = Pricing::for_quote( $quote );
			if ( $price['bespoke'] || $price['total'] !== (int) $quote['total_pence'] ) {
				Db::update( 'quotes', $quote['id'], [ 'items' => $price['items'], 'total_pence' => $price['total'], 'hours' => $price['hours'], 'cleaners' => $price['cleaners'] ] );
				Events::log( 'quote', $quote['id'], 'repriced', sprintf( 'Price changed at payment: %s → %s', Money::format( (int) $quote['total_pence'] ), Money::format( $price['total'] ) ) );
				return new \WP_Error( 'price_changed', 'Our prices have changed since this quote was made. Please review the updated total.', [ 'status' => 409, 'reload' => true ] );
			}
		}

		if ( ! $quote['job_date'] || ! $quote['slot'] ) {
			return new \WP_Error( 'no_slot', 'Please choose a date and time first.', [ 'status' => 409, 'change' => true ] );
		}
		if ( ! Slots::is_available( $quote['job_date'], $quote['slot'], (float) $quote['hours'], (int) $quote['cleaners'], $quote['id'] ) ) {
			return new \WP_Error( 'slot_taken', 'Sorry, that date and time is no longer available. Please change your details to pick another.', [ 'status' => 409, 'change' => true ] );
		}

		$extra = apply_filters( 'cc_bookings_checkout_consent', [], $quote, $input );
		if ( is_wp_error( $extra ) ) {
			return $extra;
		}
		$consent = array_merge( [
			'terms_at'    => Db::now(),
			'terms_url'   => Settings::terms_url(),
			'early_start' => $early ? true : null,
			'ip_hash'     => Security::ip_hash(),
		], (array) $extra );
		Db::update( 'quotes', $quote['id'], [ 'consent' => $consent ] );
		Events::log( 'quote', $quote['id'], 'accepted_terms', $early ? 'Agreed to terms and asked to start within 14 days' : 'Agreed to terms', $consent, 'customer' );

		if ( ! Stripe::enabled() ) {
			Events::log( 'quote', $quote['id'], 'accept_requested', 'Customer accepted; online payment not enabled', [], 'customer' );
			do_action( 'cc_quote_accept_requested', $quote['id'] );
			return [
				'mode'    => 'manual',
				'message' => 'Thank you! Online payment isn\'t switched on yet, so we\'ll contact you shortly to take payment and confirm your booking.',
			];
		}

		return self::open_session( Quotes::get( $quote['id'] ), (string) ( $input['token'] ?? '' ) );
	}

	/** Hold the slot and create a Stripe Checkout Session. */
	private static function open_session( array $quote, string $token ) {
		if ( $quote['stripe_session_id'] && Db::timestamp( $quote['hold_expires_at'] ) > time() ) {
			Stripe::expire_session( $quote['stripe_session_id'] );
			self::close_payment( $quote['stripe_session_id'], 'cancelled' );
		}

		$hold    = max( 30, (int) Settings::get( 'booking.hold_minutes', 30 ) ) * MINUTE_IN_SECONDS;
		$expires = time() + $hold;
		Db::update( 'quotes', $quote['id'], [ 'hold_expires_at' => Db::datetime( $expires ) ] );

		if ( ! Tokens::matches( $token, $quote ) ) {
			$token = Tokens::issue( 'quotes', $quote['id'] );
		}
		$session = Stripe::create_checkout_session( $quote, [
			'success_url' => Quotes::url( $quote, $token, [ 'checkout' => 'success' ] ),
			'cancel_url'  => Quotes::url( $quote, $token, [ 'checkout' => 'cancelled' ] ),
			'expires_at'  => $expires + 2 * MINUTE_IN_SECONDS,
		] );
		if ( is_wp_error( $session ) ) {
			Db::update( 'quotes', $quote['id'], [ 'hold_expires_at' => null ] );
			Events::log( 'quote', $quote['id'], 'checkout_failed', $session->get_error_message() );
			return new \WP_Error( 'stripe', 'Sorry, we couldn\'t open the payment page. Please try again or call us.', [ 'status' => 502 ] );
		}

		Db::update( 'quotes', $quote['id'], [ 'stripe_session_id' => $session['id'] ] );
		Db::insert( 'payments', [
			'quote_id'          => $quote['id'],
			'type'              => 'charge',
			'status'            => 'pending',
			'amount_pence'      => (int) $quote['total_pence'],
			'stripe_session_id' => $session['id'],
		] );
		Events::log( 'quote', $quote['id'], 'checkout_started', sprintf( 'Slot held until %s; Stripe Checkout opened', wp_date( 'H:i', $expires ) ), [ 'session' => $session['id'] ], 'customer' );
		Queue::at( $expires, 'cc_bookings_hold_expire', [ $quote['id'], $session['id'] ] );

		return [ 'mode' => 'stripe', 'url' => $session['url'] ];
	}

	/**
	 * Release a slot hold: scheduled at hold expiry, on Stripe's checkout.session.expired, or when the customer cancels.
	 */
	public static function expire_hold( $quote_id, $session_id = '', bool $force = false ): void {
		$quote = Quotes::get( (int) $quote_id );
		if ( ! $quote || $quote['booking_id'] || ! $quote['hold_expires_at'] ) {
			return;
		}
		if ( $session_id && $quote['stripe_session_id'] !== $session_id ) {
			return;
		}
		if ( ! $force && Db::timestamp( $quote['hold_expires_at'] ) > time() ) {
			return;
		}
		Db::update( 'quotes', $quote['id'], [ 'hold_expires_at' => null ] );
		if ( $quote['stripe_session_id'] ) {
			Stripe::expire_session( $quote['stripe_session_id'] );
			self::close_payment( $quote['stripe_session_id'], 'expired' );
		}
		Events::log( 'quote', $quote['id'], 'hold_released', $force ? 'Checkout closed; slot released' : 'Payment not completed in time; slot released' );
	}

	private static function close_payment( string $session_id, string $status ): void {
		$payment = Db::find( 'payments', [ 'stripe_session_id' => $session_id, 'type' => 'charge' ] );
		if ( $payment && 'pending' === $payment['status'] ) {
			Db::update( 'payments', $payment['id'], [ 'status' => $status ] );
		}
	}
}

<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Payments for regular-plan visits.
 *
 * - charge(): off-session PaymentIntent (off_session=true, confirm=true, saved customer + payment method,
 *   idempotency key per visit and attempt), queued for `charge_hours` before the visit.
 * - paid(): the one confirmation path for visits (automatic charge, pay link, payment_intent.succeeded webhook,
 *   admin "paid by hand", CLI simulation). Moves the visit to confirmed and fires cc_booking_confirmed.
 * - failed(): first failure moves the visit to payment_due, emails a pay link, texts and alerts the admin.
 * - release(): a visit still unpaid `release_hours` before the start is cancelled (customer told, slot freed).
 *
 * Plans whose card came from a simulated payment (stripe_customer cus_sim_…) never reach Stripe: their charges
 * use pi_sim_… ids and run the same success / failure code. The CLI can force either outcome.
 */
final class Plan_Charges {
	const CHARGE_HOOK  = 'cc_bookings_plan_charge';
	const RELEASE_HOOK = 'cc_bookings_plan_release';

	/** Saved for off-session charges, so cards only (Apple Pay / Google Pay count as cards); redirect methods can't be charged unattended. */
	const PAYMENT_METHOD_TYPES = [ 'card' ];

	const DECLINE_REASONS = [
		'authentication_required' => 'your bank asked you to confirm the payment yourself',
		'card_declined'           => 'your card was declined',
		'insufficient_funds'      => 'your card was declined (insufficient funds)',
		'expired_card'            => 'your card has expired',
		'incorrect_cvc'           => 'your card details need updating',
		'no_card'                 => 'we don\'t have a card saved for your plan',
	];

	public static function init(): void {
		add_action( self::CHARGE_HOOK, [ self::class, 'run_scheduled' ] );
		add_action( self::RELEASE_HOOK, [ self::class, 'release' ] );
		add_action( 'cc_booking_status_changed', [ self::class, 'status_changed' ], 10, 3 );
		add_filter( 'cc_bookings_stripe_event', [ self::class, 'stripe_event' ], 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Timing
	 * ------------------------------------------------------------------ */

	public static function charge_at( array $visit ): int {
		return Db::timestamp( $visit['start_at'] ) - Recurring::charge_hours() * HOUR_IN_SECONDS;
	}

	public static function release_at( array $visit ): int {
		return Db::timestamp( $visit['start_at'] ) - Recurring::release_hours() * HOUR_IN_SECONDS;
	}

	public static function schedule( int $visit_id ): void {
		$visit = Bookings::get( $visit_id );
		if ( ! $visit ) {
			return;
		}
		self::unschedule( $visit_id );
		Queue::at( max( time() + 60, self::charge_at( $visit ) ), self::CHARGE_HOOK, [ $visit_id ] );
		Queue::at( max( time() + 120, self::release_at( $visit ) ), self::RELEASE_HOOK, [ $visit_id ] );
	}

	public static function unschedule( int $visit_id ): void {
		Queue::cancel( self::CHARGE_HOOK, [ $visit_id ] );
		Queue::cancel( self::RELEASE_HOOK, [ $visit_id ] );
	}

	public static function status_changed( $booking_id, $to, $from = '' ): void {
		if ( in_array( $to, [ 'cancelled', 'skipped', 'completed' ], true ) && in_array( $from, [ 'scheduled', 'payment_due' ], true ) ) {
			self::unschedule( (int) $booking_id );
			self::close_sessions( (int) $booking_id );
		}
	}

	/* ---------------------------------------------------------------------
	 * Cards
	 * ------------------------------------------------------------------ */

	public static function is_simulated( array $plan ): bool {
		return 'simulated' === $plan['provider'] || str_starts_with( (string) $plan['stripe_customer'], 'cus_sim_' );
	}

	public static function real_customer( array $plan ): bool {
		return str_starts_with( (string) $plan['stripe_customer'], 'cus_' ) && ! self::is_simulated( $plan );
	}

	public static function describe_payment_method( $pm ): string {
		if ( ! is_array( $pm ) ) {
			return '';
		}
		if ( 'card' === ( $pm['type'] ?? '' ) && ! empty( $pm['card'] ) ) {
			$card = $pm['card'];
			return sprintf( '%s •••• %s (expires %02d/%02d)', ucfirst( (string) ( $card['brand'] ?? 'Card' ) ), (string) ( $card['last4'] ?? '' ), (int) ( $card['exp_month'] ?? 0 ), (int) ( $card['exp_year'] ?? 0 ) % 100 );
		}
		return ucfirst( str_replace( '_', ' ', (string) ( $pm['type'] ?? 'saved payment method' ) ) );
	}

	/**
	 * Card saved by the first payment. Real payments are read back from Stripe (read-only); simulated ones get a
	 * test card that never reaches Stripe.
	 *
	 * @return array{customer?: string, payment_method?: string, card_label?: string}
	 */
	public static function card_from_payment( string $provider, string $payment_intent, string $customer ): array {
		if ( 'simulated' === $provider || str_starts_with( $payment_intent, 'pi_sim' ) || str_starts_with( $customer, 'cus_sim_' ) ) {
			return [
				'customer'       => $customer ?: 'cus_sim_' . substr( md5( $payment_intent ), 0, 10 ),
				'payment_method' => 'pm_sim_' . substr( md5( $payment_intent . $customer ), 0, 10 ),
				'card_label'     => 'Test card •••• 4242',
			];
		}
		if ( 'stripe' !== $provider || '' === $payment_intent || ! Stripe::enabled() ) {
			return [ 'customer' => $customer ];
		}
		$pi = Stripe::request( 'GET', 'payment_intents/' . rawurlencode( $payment_intent ), [ 'expand' => [ 'payment_method' ] ] );
		if ( is_wp_error( $pi ) ) {
			return [ 'customer' => $customer ];
		}
		$pm = $pi['payment_method'] ?? null;
		return [
			'customer'       => is_array( $pi['customer'] ?? null ) ? (string) $pi['customer']['id'] : ( (string) ( $pi['customer'] ?? '' ) ?: $customer ),
			'payment_method' => is_array( $pm ) ? (string) $pm['id'] : (string) $pm,
			'card_label'     => self::describe_payment_method( $pm ),
		];
	}

	/* ---------------------------------------------------------------------
	 * Charging
	 * ------------------------------------------------------------------ */

	private static function result( bool $ok, string $status, string $message ): array {
		return [ 'ok' => $ok, 'status' => $status, 'message' => $message ];
	}

	public static function run_scheduled( $visit_id ): void {
		self::charge( (int) $visit_id, 'scheduled' );
	}

	/**
	 * Take payment for a visit from the saved card.
	 *
	 * @param string      $source   scheduled|tick|admin|card_updated|cli
	 * @param string|null $simulate success|fail to force a simulated outcome (CLI); never calls Stripe.
	 * @return array{ok: bool, status: string, message: string}
	 */
	public static function charge( int $visit_id, string $source = 'scheduled', ?string $simulate = null, string $code = 'card_declined' ): array {
		if ( ! Db::lock( 'plan-charge:' . $visit_id, 20 ) ) {
			return self::result( false, 'busy', 'A charge for this visit is already running.' );
		}
		try {
			$visit = Bookings::get( $visit_id );
			if ( ! $visit || ! (int) $visit['series_id'] ) {
				return self::result( false, 'invalid', 'That isn\'t a regular-plan visit.' );
			}
			if ( Plans::is_paid( $visit ) ) {
				return self::result( true, 'already_paid', 'Visit ' . $visit['ref'] . ' is already paid.' );
			}
			if ( in_array( $visit['status'], [ 'cancelled', 'skipped', 'completed' ], true ) ) {
				return self::result( false, 'closed', 'Visit ' . $visit['ref'] . ' is ' . $visit['status'] . '.' );
			}
			$plan = Plans::get( (int) $visit['series_id'] );
			if ( ! $plan ) {
				return self::result( false, 'invalid', 'Plan not found.' );
			}
			if ( in_array( $source, [ 'scheduled', 'tick' ], true ) && 'active' !== $plan['status'] ) {
				return self::result( false, 'plan_' . $plan['status'], 'Plan ' . $plan['ref'] . ' is ' . $plan['status'] . '; no charge.' );
			}

			$amount  = (int) $visit['total_pence'];
			$attempt = Db::count( 'payments', [ 'booking_id' => $visit_id, 'type' => 'charge' ] ) + 1;
			$sim     = $simulate ?? ( self::is_simulated( $plan ) ? 'success' : null );
			$row     = [
				'booking_id'   => $visit_id,
				'type'         => 'charge',
				'status'       => 'pending',
				'amount_pence' => $amount,
				'note'         => 'Regular visit: automatic charge (' . $source . ')',
				'created_by'   => get_current_user_id(),
			];

			if ( null !== $sim ) {
				$pi         = 'pi_sim_visit_' . $visit_id . '_' . $attempt;
				$payment_id = Db::insert( 'payments', $row + [ 'provider' => 'simulated', 'stripe_payment_intent' => $pi ] );
				Events::log( 'booking', $visit_id, 'charge_attempt', sprintf( 'Simulated automatic charge of %s (%s)', Money::format( $amount ), $source ), [ 'attempt' => $attempt ], $source );
				$outcome  = 'success' === $sim
					? [ 'id' => $pi, 'status' => 'succeeded', 'amount_received' => $amount ]
					: new \WP_Error( 'stripe_' . $code, self::DECLINE_REASONS[ $code ] ?? 'The payment was declined.' );
				$provider = 'simulated';
			} elseif ( ! Stripe::enabled() || ! self::real_customer( $plan ) || ! $plan['stripe_payment_method'] ) {
				$payment_id = Db::insert( 'payments', $row + [ 'provider' => Stripe::enabled() ? 'stripe' : 'manual' ] );
				$outcome    = new \WP_Error( 'no_card', Stripe::enabled() ? 'No saved card for this plan.' : 'Online payments are not set up.' );
				$provider   = 'stripe';
			} else {
				$payment_id = Db::insert( 'payments', $row + [ 'provider' => 'stripe' ] );
				Events::log( 'booking', $visit_id, 'charge_attempt', sprintf( 'Automatic charge of %s (%s)', Money::format( $amount ), $source ), [ 'attempt' => $attempt ], $source );
				$meta     = [ 'type' => 'visit', 'source' => 'off_session', 'visit_id' => $visit_id, 'visit_ref' => $visit['ref'], 'plan_id' => $plan['id'], 'plan_ref' => $plan['ref'] ];
				$outcome  = Stripe::request( 'POST', 'payment_intents', [
					'amount'                    => $amount,
					'currency'                  => 'gbp',
					'customer'                  => $plan['stripe_customer'],
					'payment_method'            => $plan['stripe_payment_method'],
					'off_session'               => 'true',
					'confirm'                   => 'true',
					'automatic_payment_methods' => [ 'enabled' => 'true', 'allow_redirects' => 'never' ],
					'description'               => sprintf( '%s %s — %s', $visit['ref'], $visit['service_name'], Slots::date_label( (string) $visit['job_date'], 'D j M' ) ),
					'receipt_email'             => $visit['email'],
					'metadata'                  => $meta,
				], 'cc-visit-' . $visit_id . '-' . $attempt );
				$provider = 'stripe';
			}
			return self::outcome( $visit, $payment_id, $outcome, $provider, $source );
		} finally {
			Db::unlock( 'plan-charge:' . $visit_id );
		}
	}

	private static function outcome( array $visit, int $payment_id, $outcome, string $provider, string $source ): array {
		$visit_id = (int) $visit['id'];
		if ( is_wp_error( $outcome ) ) {
			$code = preg_replace( '/^stripe_/', '', (string) $outcome->get_error_code() );
			$data = (array) $outcome->get_error_data();
			Db::update( 'payments', $payment_id, [ 'status' => 'failed', 'note' => mb_substr( 'Regular visit charge failed: ' . $outcome->get_error_message(), 0, 255 ) ] );
			return self::failed( $visit_id, $code ?: 'payment_failed', $outcome->get_error_message(), $source, $data );
		}
		$status = (string) ( $outcome['status'] ?? '' );
		$pi     = (string) ( $outcome['id'] ?? '' );
		if ( 'succeeded' === $status ) {
			$result = self::paid( $visit_id, [ 'payment_intent' => $pi, 'amount' => (int) ( $outcome['amount_received'] ?? $outcome['amount'] ?? $visit['total_pence'] ), 'provider' => $provider, 'payment_id' => $payment_id, 'how' => 'automatic charge' ] );
			return is_wp_error( $result ) ? self::result( false, 'error', $result->get_error_message() ) : self::result( true, 'paid', $result );
		}
		if ( 'processing' === $status ) {
			Db::update( 'payments', $payment_id, [ 'stripe_payment_intent' => $pi, 'note' => 'Regular visit: payment processing' ] );
			Events::log( 'booking', $visit_id, 'charge_processing', 'Payment is processing; the Stripe webhook will confirm the visit', [ 'payment_intent' => $pi ], $source );
			return self::result( true, 'processing', 'Payment processing; waiting for Stripe.' );
		}
		Db::update( 'payments', $payment_id, [ 'status' => 'failed', 'stripe_payment_intent' => $pi, 'note' => mb_substr( 'Regular visit charge not completed: ' . $status, 0, 255 ) ] );
		return self::failed( $visit_id, 'requires_action' === $status ? 'authentication_required' : ( $status ?: 'payment_failed' ), 'Payment status ' . $status, $source );
	}

	/**
	 * Record a successful visit payment and confirm the visit. Idempotent per PaymentIntent.
	 *
	 * @param array $args payment_intent, amount, provider (stripe|simulated|manual), payment_id?, session_id?, how
	 * @return string|\WP_Error
	 */
	public static function paid( int $visit_id, array $args ) {
		if ( ! Db::lock( 'pay:visit:' . $visit_id ) ) {
			return new \WP_Error( 'locked', 'Payment is being processed; retry later.' );
		}
		$provider = (string) ( $args['provider'] ?? 'stripe' );
		$pi       = (string) ( $args['payment_intent'] ?? '' );
		$session  = (string) ( $args['session_id'] ?? '' );
		$how      = (string) ( $args['how'] ?? 'payment' );
		try {
			$visit = Bookings::get( $visit_id );
			if ( ! $visit || ! (int) $visit['series_id'] ) {
				return new \WP_Error( 'missing', 'Visit not found: ' . $visit_id );
			}
			$amount = (int) ( $args['amount'] ?? $visit['total_pence'] );
			$data   = [
				'booking_id'            => $visit_id,
				'type'                  => 'charge',
				'status'                => 'succeeded',
				'provider'              => $provider,
				'amount_pence'          => $amount,
				'stripe_payment_intent' => $pi,
			];

			if ( Plans::is_paid( $visit ) ) {
				if ( '' !== $pi && $pi === $visit['stripe_payment_intent'] ) {
					if ( $session ) {
						self::settle_row( $visit_id, $session, '', $data );
					}
					return 'visit ' . $visit['ref'] . ' already recorded';
				}
				Db::insert( 'payments', $data + [ 'stripe_session_id' => $session, 'note' => 'Duplicate payment — refund needed' ] );
				Bookings::alert( 'Duplicate payment', sprintf( 'Regular visit %s was paid twice (%s, %s). Please refund the duplicate.', $visit['ref'], $pi ?: $how, Money::format( $amount ) ), [ 'booking_id' => $visit_id ] );
				return 'duplicate payment recorded for ' . $visit['ref'];
			}

			$payment_id = self::settle_row( $visit_id, $session, $pi, $data, (int) ( $args['payment_id'] ?? 0 ) );
			$booking    = [ 'payment_status' => 'paid', 'paid_pence' => $amount, 'stripe_payment_intent' => $pi ] + ( $session ? [ 'stripe_session_id' => $session ] : [] );

			if ( in_array( $visit['status'], [ 'cancelled', 'skipped' ], true ) ) {
				Db::update( 'bookings', $visit_id, $booking );
				Events::log( 'booking', $visit_id, 'paid_after_release', sprintf( 'Payment of %s arrived after the visit was %s', Money::format( $amount ), $visit['status'] ), [ 'payment_intent' => $pi ], $provider );
				Bookings::alert( 'Payment for a released visit', sprintf( 'Regular visit %s (%s) was paid (%s) after it was %s. Rebook it with the customer or refund the payment.', $visit['ref'], Bookings::start_label( $visit ), Money::format( $amount ), $visit['status'] ), [ 'booking_id' => $visit_id ] );
				return 'payment recorded for ' . $visit['status'] . ' visit ' . $visit['ref'];
			}

			Db::update( 'bookings', $visit_id, $booking + [ 'confirmed_at' => Db::now() ] );
			if ( in_array( $visit['status'], [ 'scheduled', 'payment_due' ], true ) ) {
				Bookings::set_status( $visit_id, 'confirmed', sprintf( 'Paid %s (%s); visit confirmed', Money::format( $amount ), $how ) );
			}
			Events::log( 'booking', $visit_id, 'confirmed', sprintf( 'Payment of %s received (%s, %s); visit confirmed', Money::format( $amount ), $how, $provider ), [ 'payment_intent' => $pi ], $provider );
			if ( $amount !== (int) $visit['total_pence'] ) {
				Bookings::alert( 'Payment amount differs', sprintf( 'Regular visit %s: paid %s but the visit price is %s.', $visit['ref'], Money::format( $amount ), Money::format( (int) $visit['total_pence'] ) ), [ 'booking_id' => $visit_id ] );
			}
			self::unschedule( $visit_id );
			self::close_sessions( $visit_id, $session );
		} finally {
			Db::unlock( 'pay:visit:' . $visit_id );
		}

		$visit = Bookings::get( $visit_id );
		do_action( 'cc_booking_confirmed', $visit_id, [ 'booking_url' => Bookings::link( $visit ), 'provider' => $provider, 'plan_id' => (int) $visit['series_id'], 'how' => $how ] );
		do_action( 'cc_plan_visit_paid', $visit_id, $payment_id );
		Plans::generate( (int) $visit['series_id'] );
		return 'confirmed visit ' . $visit['ref'];
	}

	/** Mark the matching pending payment row succeeded (or add one). @return int Payment id. */
	private static function settle_row( int $visit_id, string $session, string $pi, array $data, int $payment_id = 0 ): int {
		$row = $payment_id ? Db::get( 'payments', $payment_id ) : null;
		if ( ! $row && $session ) {
			$row = Db::find( 'payments', [ 'booking_id' => $visit_id, 'stripe_session_id' => $session ] );
		}
		if ( ! $row && $pi ) {
			$row = Db::find( 'payments', [ 'booking_id' => $visit_id, 'stripe_payment_intent' => $pi ] );
		}
		if ( ! $row ) {
			$row = Db::find( 'payments', [ 'booking_id' => $visit_id, 'type' => 'charge', 'status' => 'pending' ] );
		}
		if ( $row ) {
			Db::update( 'payments', (int) $row['id'], array_filter( $data, fn( $v ) => '' !== $v ) + ( $session ? [ 'stripe_session_id' => $session ] : [] ) );
			return (int) $row['id'];
		}
		return Db::insert( 'payments', $data + [ 'stripe_session_id' => $session, 'created_by' => get_current_user_id() ] );
	}

	/**
	 * A charge failed. The first failure holds the visit as payment_due and tells the customer and admin;
	 * later failures (e.g. a retry after a card update) are logged only.
	 */
	public static function failed( int $visit_id, string $code, string $message, string $source = 'scheduled', array $data = [] ): array {
		$visit = Bookings::get( $visit_id );
		if ( ! $visit || Plans::is_paid( $visit ) || in_array( $visit['status'], [ 'cancelled', 'skipped', 'completed' ], true ) ) {
			return self::result( false, 'ignored', 'Failure ignored: visit is paid or closed.' );
		}
		$plan   = Plans::get( (int) $visit['series_id'] );
		$reason = self::DECLINE_REASONS[ $code ] ?? 'the payment didn\'t go through';
		Events::log( 'booking', $visit_id, 'charge_failed', sprintf( 'Automatic charge of %s failed: %s', Money::format( (int) $visit['total_pence'] ), $message ), [ 'code' => $code, 'decline' => $data['decline_code'] ?? '' ], $source );
		if ( 'payment_due' === $visit['status'] ) {
			return self::result( false, 'payment_due', 'Charge failed again (' . $code . '); visit still waiting for payment.' );
		}

		Bookings::set_status( $visit_id, 'payment_due', 'Card payment failed (' . $code . '); waiting for the customer to pay', true );
		$visit   = Bookings::get( $visit_id );
		$release = max( time() + 120, self::release_at( $visit ) );
		Queue::cancel( self::CHARGE_HOOK, [ $visit_id ] );
		Queue::cancel( self::RELEASE_HOOK, [ $visit_id ] );
		Queue::at( $release, self::RELEASE_HOOK, [ $visit_id ] );

		$url  = Aftercare::link( $visit );
		$vars = Notify::booking_vars( $visit, $url ) + [
			'amount'  => Money::format( (int) $visit['total_pence'] ),
			'card'    => ( (string) ( $plan['card_label'] ?? '' ) ) ?: 'no card saved',
			'reason'  => $reason,
			'pay_by'  => wp_date( 'l j F \a\t H:i', $release ),
			'pay_url' => $url . '#cc-pay',
		];
		Notify::send( 'visit_payment_due', (string) $visit['email'], $vars, [ 'booking', $visit_id ] );
		Aftercare::sms( (string) $visit['phone'], sprintf( '%s: we couldn\'t take %s for your clean on %s (%s). Please pay by %s: %s', Settings::brand(), $vars['amount'], $vars['date_short'], $reason, wp_date( 'D j M H:i', $release ), $vars['pay_url'] ), $visit_id );
		Bookings::alert( 'Regular visit payment failed: ' . $visit['ref'], sprintf( 'The automatic charge of %s for %s (%s, plan %s) failed: %s. The customer has been sent a pay link; the visit will be released on %s if it is still unpaid.', $vars['amount'], $visit['customer_name'], Bookings::start_label( $visit ), $plan['ref'] ?? '?', $message, wp_date( 'D j M H:i', $release ) ), [ 'booking_id' => $visit_id ] );
		do_action( 'cc_plan_visit_payment_failed', $visit_id, $code, $message );
		return self::result( false, 'payment_due', 'Charge failed (' . $code . '); visit is now waiting for payment and the customer has been sent a pay link.' );
	}

	/** Release a visit that is still unpaid at the cutoff. */
	public static function release( $visit_id, bool $force = false ): string {
		$visit_id = (int) $visit_id;
		$visit    = Bookings::get( $visit_id );
		if ( ! $visit || 'payment_due' !== $visit['status'] || Plans::is_paid( $visit ) ) {
			return 'nothing to release';
		}
		if ( ! $force && time() < self::release_at( $visit ) - 60 ) {
			Queue::at( self::release_at( $visit ), self::RELEASE_HOOK, [ $visit_id ] );
			return 'not due yet';
		}
		$phone = Notify::site_vars()['phone'];
		Bookings::cancel( $visit_id, sprintf( 'We didn\'t receive payment for this visit in time, so it has been released. Your regular plan carries on as normal. To rebook this visit, call us on %s.', $phone ) );
		Events::log( 'booking', $visit_id, 'released', 'Unpaid regular visit released at the payment cutoff' );
		Bookings::alert( 'Unpaid regular visit released: ' . $visit['ref'], sprintf( '%s (%s) didn\'t pay for the visit on %s, so it has been cancelled and the slot freed.', $visit['customer_name'], $visit['email'], Bookings::start_label( $visit ) ), [ 'booking_id' => $visit_id ] );
		do_action( 'cc_plan_visit_released', $visit_id );
		Plans::generate( (int) $visit['series_id'] );
		return 'released visit ' . $visit['ref'];
	}

	/** Expire open pay-link sessions for a visit (except $keep) and mark their rows. */
	public static function close_sessions( int $visit_id, string $keep = '' ): void {
		foreach ( Db::all( 'payments', [ 'booking_id' => $visit_id, 'status' => 'pending' ] ) as $row ) {
			$session = (string) $row['stripe_session_id'];
			if ( '' === $session || $session === $keep ) {
				continue;
			}
			if ( ! str_starts_with( $session, 'cs_sim_' ) ) {
				Stripe::expire_session( $session );
			}
			Db::update( 'payments', (int) $row['id'], [ 'status' => 'expired' ] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Stripe Checkout: pay link and card update
	 * ------------------------------------------------------------------ */

	/** @return string|\WP_Error Checkout URL for paying a payment-due visit. */
	public static function pay_session( array $visit, string $token, string $return = '' ) {
		$visit = Bookings::get( (int) $visit['id'] );
		$plan  = $visit ? Plans::get( (int) $visit['series_id'] ) : null;
		if ( ! $visit || ! $plan || ! in_array( $visit['status'], [ 'payment_due', 'scheduled' ], true ) || Plans::is_paid( $visit ) ) {
			return new \WP_Error( 'state', 'This visit doesn\'t need paying.' );
		}
		if ( ! Stripe::enabled() ) {
			return new \WP_Error( 'disabled', 'Online payment isn\'t available. Please call us to pay.' );
		}
		$release = self::release_at( $visit );
		if ( $release < time() + 31 * MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'late', 'It\'s too late to pay online for this visit. Please call us.' );
		}
		$expires = min( time() + DAY_IN_SECONDS - 300, $release );
		$url     = $return ?: Bookings::url( $visit, $token );
		$meta    = [ 'type' => 'visit', 'source' => 'pay_link', 'visit_id' => (int) $visit['id'], 'visit_ref' => $visit['ref'], 'plan_id' => (int) $plan['id'], 'plan_ref' => $plan['ref'] ];
		$params  = [
			'mode'                => 'payment',
			'client_reference_id' => $visit['ref'],
			'success_url'         => add_query_arg( 'paid', '1', $url ) . '#cc-plan',
			'cancel_url'          => $url . '#cc-plan',
			'expires_at'          => $expires,
			'locale'              => 'en-GB',
			'payment_method_types' => self::PAYMENT_METHOD_TYPES,
			'line_items'          => [
				[
					'quantity'   => 1,
					'price_data' => [
						'currency'     => 'gbp',
						'unit_amount'  => (int) $visit['total_pence'],
						'product_data' => [
							'name'        => mb_substr( $visit['service_name'] . ' — ' . Slots::date_label( (string) $visit['job_date'], 'D j M' ) . ', ' . Slots::label( (string) $visit['slot'] ), 0, 250 ),
							'description' => mb_substr( 'Regular visit ' . $visit['ref'] . ' (plan ' . $plan['ref'] . ')', 0, 490 ),
						],
					],
				],
			],
			'metadata'            => $meta,
			'payment_intent_data' => [
				'description'        => sprintf( '%s %s', $visit['ref'], $visit['service_name'] ),
				'receipt_email'      => $visit['email'],
				'setup_future_usage' => 'off_session',
				'metadata'           => $meta,
			],
		];
		if ( self::real_customer( $plan ) ) {
			$params['customer'] = $plan['stripe_customer'];
		} else {
			$params['customer_email']    = $visit['email'];
			$params['customer_creation'] = 'always';
		}
		$session = Stripe::request( 'POST', 'checkout/sessions', $params, 'cc-visit-pay-' . $visit['id'] . '-' . $expires );
		if ( is_wp_error( $session ) ) {
			Events::log( 'booking', (int) $visit['id'], 'pay_link_failed', $session->get_error_message() );
			return new \WP_Error( 'stripe', 'We couldn\'t open the payment page. Please try again or call us.' );
		}
		Db::insert( 'payments', [
			'booking_id'        => (int) $visit['id'],
			'type'              => 'charge',
			'status'            => 'pending',
			'provider'          => 'stripe',
			'amount_pence'      => (int) $visit['total_pence'],
			'stripe_session_id' => (string) $session['id'],
			'url'               => (string) $session['url'],
			'note'              => 'Regular visit: pay link',
		] );
		Events::log( 'booking', (int) $visit['id'], 'pay_link_opened', 'Customer opened the pay link', [ 'session' => $session['id'] ], 'customer' );
		return (string) $session['url'];
	}

	/** @return string|\WP_Error Checkout URL (setup mode) for saving a new card on the plan. */
	public static function card_session( array $plan, array $booking, string $token, string $return = '' ) {
		if ( ! Stripe::enabled() ) {
			return new \WP_Error( 'disabled', 'Card updates online aren\'t available. Please call us.' );
		}
		$url    = $return ?: Bookings::url( $booking, $token );
		$meta   = [ 'type' => 'card_update', 'plan_id' => (int) $plan['id'], 'plan_ref' => $plan['ref'], 'booking_id' => (int) $booking['id'] ];
		$params = [
			'mode'                 => 'setup',
			'currency'             => 'gbp',
			'payment_method_types' => self::PAYMENT_METHOD_TYPES,
			'client_reference_id'  => $plan['ref'],
			'success_url'          => add_query_arg( 'card', 'updated', $url ) . '#cc-plan',
			'cancel_url'           => $url . '#cc-plan',
			'expires_at'           => time() + HOUR_IN_SECONDS,
			'locale'               => 'en-GB',
			'metadata'             => $meta,
			'setup_intent_data'    => [ 'description' => 'Card for regular plan ' . $plan['ref'], 'metadata' => $meta ],
		];
		if ( self::real_customer( $plan ) ) {
			$params['customer'] = $plan['stripe_customer'];
		} else {
			$params['customer_email']    = $plan['email'];
			$params['customer_creation'] = 'always';
		}
		$session = Stripe::request( 'POST', 'checkout/sessions', $params, 'cc-card-' . $plan['id'] . '-' . $params['expires_at'] );
		if ( is_wp_error( $session ) ) {
			Events::log( 'plan', (int) $plan['id'], 'card_link_failed', $session->get_error_message() );
			return new \WP_Error( 'stripe', 'We couldn\'t open the card page. Please try again or call us.' );
		}
		Events::log( 'plan', (int) $plan['id'], 'card_link_opened', 'Customer opened the card update page', [ 'session' => $session['id'] ], 'customer' );
		return (string) $session['url'];
	}

	/* ---------------------------------------------------------------------
	 * Webhooks (filter cc_bookings_stripe_event)
	 * ------------------------------------------------------------------ */

	public static function stripe_event( $handled, $event ) {
		if ( null !== $handled || ! is_array( $event ) ) {
			return $handled;
		}
		$o    = (array) ( $event['data']['object'] ?? [] );
		$meta = (array) ( $o['metadata'] ?? [] );
		$type = (string) ( $meta['type'] ?? '' );
		$id   = (string) ( $o['id'] ?? '' );
		$sim  = str_starts_with( $id, 'cs_sim_' ) || str_starts_with( $id, 'pi_sim' );

		switch ( (string) $event['type'] ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				if ( 'card_update' === $type ) {
					return self::card_updated( $o );
				}
				if ( 'visit' !== $type ) {
					return null;
				}
				if ( 'paid' !== ( $o['payment_status'] ?? '' ) ) {
					return 'waiting: visit payment not yet settled';
				}
				$pi     = is_array( $o['payment_intent'] ?? null ) ? (string) $o['payment_intent']['id'] : (string) ( $o['payment_intent'] ?? '' );
				$result = self::paid( (int) ( $meta['visit_id'] ?? 0 ), [ 'payment_intent' => $pi, 'amount' => (int) ( $o['amount_total'] ?? 0 ), 'provider' => $sim ? 'simulated' : 'stripe', 'session_id' => $id, 'how' => 'pay link' ] );
				if ( ! is_wp_error( $result ) ) {
					self::adopt_card( (int) ( $meta['plan_id'] ?? 0 ), is_array( $o['customer'] ?? null ) ? (string) $o['customer']['id'] : (string) ( $o['customer'] ?? '' ), $pi, $sim );
				}
				return $result;

			case 'checkout.session.expired':
			case 'checkout.session.async_payment_failed':
				if ( 'visit' === $type ) {
					foreach ( Db::all( 'payments', [ 'stripe_session_id' => $id, 'status' => 'pending' ] ) as $row ) {
						Db::update( 'payments', (int) $row['id'], [ 'status' => 'expired' ] );
					}
					return 'closed visit pay link';
				}
				return 'card_update' === $type ? 'card update page closed' : null;

			case 'payment_intent.succeeded':
				if ( 'visit' !== $type ) {
					return null;
				}
				return self::paid( (int) ( $meta['visit_id'] ?? 0 ), [ 'payment_intent' => $id, 'amount' => (int) ( $o['amount_received'] ?? $o['amount'] ?? 0 ), 'provider' => $sim ? 'simulated' : 'stripe', 'how' => 'pay_link' === ( $meta['source'] ?? '' ) ? 'pay link' : 'automatic charge' ] );

			case 'payment_intent.payment_failed':
				if ( 'visit' !== $type ) {
					return null;
				}
				if ( 'off_session' !== ( $meta['source'] ?? '' ) ) {
					return 'ignored: pay link attempt failed (customer can retry)';
				}
				$error = (array) ( $o['last_payment_error'] ?? [] );
				return self::failed( (int) ( $meta['visit_id'] ?? 0 ), (string) ( $error['code'] ?? 'payment_failed' ), (string) ( $error['message'] ?? 'Payment failed' ), 'webhook', $error )['message'];
		}
		return null;
	}

	/** After a pay-link payment, keep the card it saved for the next automatic charges. */
	private static function adopt_card( int $plan_id, string $customer, string $pi, bool $sim ): void {
		$plan = Plans::get( $plan_id );
		if ( ! $plan || '' === $pi ) {
			return;
		}
		$card = self::card_from_payment( $sim ? 'simulated' : 'stripe', $pi, $customer );
		if ( ! empty( $card['payment_method'] ) && $card['payment_method'] !== $plan['stripe_payment_method'] ) {
			Db::update( Recurring::TABLE, $plan_id, [ 'stripe_customer' => (string) $card['customer'], 'stripe_payment_method' => (string) $card['payment_method'], 'card_label' => (string) $card['card_label'] ] );
			Events::log( 'plan', $plan_id, 'card_saved', 'Card from the pay-link payment saved for later visits: ' . $card['card_label'], [], $sim ? 'simulated' : 'stripe' );
		}
	}

	/** checkout.session.completed in setup mode: save the new card on the plan. */
	private static function card_updated( array $session ) {
		$meta = (array) ( $session['metadata'] ?? [] );
		$plan = Plans::get( (int) ( $meta['plan_id'] ?? 0 ) );
		if ( ! $plan ) {
			return 'ignored: unknown plan for card update';
		}
		$setup    = is_array( $session['setup_intent'] ?? null ) ? (string) $session['setup_intent']['id'] : (string) ( $session['setup_intent'] ?? '' );
		$customer = is_array( $session['customer'] ?? null ) ? (string) $session['customer']['id'] : (string) ( $session['customer'] ?? '' );
		if ( str_starts_with( $setup, 'seti_sim_' ) || str_starts_with( (string) $session['id'], 'cs_sim_' ) ) {
			$pm    = 'pm_sim_' . substr( md5( $setup ), 0, 10 );
			$label = 'Test card •••• 4444';
			$customer = $customer ?: (string) $plan['stripe_customer'];
		} else {
			$intent = Stripe::request( 'GET', 'setup_intents/' . rawurlencode( $setup ), [ 'expand' => [ 'payment_method' ] ] );
			if ( is_wp_error( $intent ) ) {
				return $intent;
			}
			$method   = $intent['payment_method'] ?? null;
			$pm       = is_array( $method ) ? (string) $method['id'] : (string) $method;
			$label    = self::describe_payment_method( $method );
			$customer = $customer ?: ( is_array( $intent['customer'] ?? null ) ? (string) $intent['customer']['id'] : (string) ( $intent['customer'] ?? '' ) );
		}
		if ( '' === $pm ) {
			return 'ignored: no payment method on setup';
		}
		if ( $pm === $plan['stripe_payment_method'] ) {
			return 'card already saved';
		}
		if ( ! str_starts_with( $pm, 'pm_sim_' ) && 'stripe' !== $plan['provider'] ) {
			Db::update( Recurring::TABLE, (int) $plan['id'], [ 'provider' => 'stripe' ] );
		}
		Plans::set_card( (int) $plan['id'], $customer, $pm, $label, 'customer' );
		return 'card updated for plan ' . $plan['ref'];
	}
}

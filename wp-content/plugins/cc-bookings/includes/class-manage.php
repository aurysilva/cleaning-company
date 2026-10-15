<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Customer self-service on the private booking page (/booking/{ref}/{token}/): reschedule into an available
 * slot (free up to N hours before) and cancel with a policy-based refund. Forms post back to the same URL
 * (token + nonce checked) and redirect with a one-time message.
 *
 * Refunds: Stripe payments are refunded through Bookings::refund(); simulated (test) payments are recorded;
 * manual payments, or Stripe payments while the keys are missing, become a pending refund row in payments
 * and the admin is alerted to pay it by hand and then mark it paid.
 */
final class Manage {
	const NONCE = 'cc_manage_';

	public static function init(): void {
		add_action( 'template_redirect', [ self::class, 'handle_post' ], 0 );
		add_action( 'cc_bookings_booking_page_actions', [ self::class, 'panel' ], 10, 2 );
		add_action( 'cc_booking_status_changed', [ self::class, 'status_changed' ], 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Rules
	 * ------------------------------------------------------------------ */

	public static function hours_before( array $booking, ?int $now = null ): float {
		$start = Reminders::start( $booking );
		return $start ? ( $start - ( $now ?? time() ) ) / HOUR_IN_SECONDS : INF;
	}

	public static function can_reschedule( array $booking ): bool {
		return Aftercare::setting( 'allow_reschedule' )
			&& in_array( $booking['status'], Aftercare::OPEN, true )
			&& self::hours_before( $booking ) > (float) Aftercare::setting( 'reschedule_hours' );
	}

	public static function can_cancel( array $booking ): bool {
		return Aftercare::setting( 'allow_cancel' )
			&& in_array( $booking['status'], Aftercare::OPEN, true )
			&& self::hours_before( $booking ) > 0;
	}

	/**
	 * Refund due if the customer cancels at $now: more than full_refund_hours before → 100%,
	 * at least partial_refund_hours → partial_refund_pct, otherwise nothing.
	 *
	 * @return array{band: string, pct: int, amount: int, hours: float}
	 */
	public static function policy( array $booking, ?int $now = null ): array {
		$hours   = self::hours_before( $booking, $now );
		$full    = (float) Aftercare::setting( 'full_refund_hours' );
		$partial = (float) Aftercare::setting( 'partial_refund_hours' );
		if ( $hours > $full ) {
			[ $band, $pct ] = [ 'full', 100 ];
		} elseif ( $hours >= $partial ) {
			[ $band, $pct ] = [ 'partial', max( 0, min( 100, (int) Aftercare::setting( 'partial_refund_pct' ) ) ) ];
		} else {
			[ $band, $pct ] = [ 'none', 0 ];
		}
		$amount = 100 === $pct ? Bookings::refundable( $booking ) : min( Bookings::refundable( $booking ), (int) round( (int) $booking['paid_pence'] * $pct / 100 ) );
		return [ 'band' => $band, 'pct' => $pct, 'amount' => max( 0, $amount ), 'hours' => $hours ];
	}

	/** Policy summary lines built from the numeric settings. */
	public static function policy_lines(): array {
		$full    = (int) Aftercare::setting( 'full_refund_hours' );
		$partial = (int) Aftercare::setting( 'partial_refund_hours' );
		$pct     = (int) Aftercare::setting( 'partial_refund_pct' );
		return [
			sprintf( 'More than %d hours before your clean: full refund.', $full ),
			sprintf( '%d–%d hours before: %d%% refund.', $partial, $full, $pct ),
			sprintf( 'Under %d hours: no refund.', $partial ),
		];
	}

	/** How a refund for this booking is paid: stripe, record (simulated test payment) or manual. */
	public static function refund_mode( array $booking ): string {
		$charge   = Db::find( 'payments', [ 'booking_id' => (int) $booking['id'], 'type' => 'charge', 'status' => 'succeeded' ], 'id ASC' );
		$provider = $charge['provider'] ?? 'manual';
		if ( 'stripe' === $provider && Stripe::enabled() && $booking['stripe_payment_intent'] ) {
			return 'stripe';
		}
		return 'simulated' === $provider ? 'record' : 'manual';
	}

	/**
	 * Bookable slots for moving this booking: date => [ slot => label ], excluding its current slot.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function slot_options( array $booking ): array {
		self::slot_context( $booking );
		$out = [];
		foreach ( Slots::availability( (float) $booking['hours'], (int) $booking['cleaners'], (int) $booking['quote_id'] ) as $date => $slots ) {
			foreach ( $slots as $slot => $ok ) {
				if ( $ok && ! ( $date === $booking['job_date'] && $slot === $booking['slot'] ) ) {
					$out[ $date ][ $slot ] = Slots::label( $slot );
				}
			}
		}
		return $out;
	}

	/** Phase 2 cleaner-calendar slots depend on the job's service and postcode district. */
	public static function slot_context( array $booking ): void {
		if ( method_exists( __NAMESPACE__ . '\\Availability', 'set_context' ) ) {
			Availability::set_context( (string) $booking['service_slug'], (string) $booking['postcode'] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	/**
	 * Move a booking. Customers need an available slot and more than reschedule_hours notice;
	 * admins can pass $force to skip both checks.
	 *
	 * @return true|\WP_Error
	 */
	public static function reschedule( int $id, string $date, string $slot, string $actor = 'customer', bool $force = false ) {
		if ( ! Db::lock( 'manage:' . $id ) ) {
			return new \WP_Error( 'busy', 'Please try again in a moment.' );
		}
		try {
			$booking = Bookings::get( $id );
			if ( ! $booking || ! in_array( $booking['status'], Aftercare::OPEN, true ) ) {
				return new \WP_Error( 'closed', 'This booking can no longer be changed.' );
			}
			if ( ! $force && ! self::can_reschedule( $booking ) ) {
				return new \WP_Error( 'too_late', sprintf( 'Bookings can only be moved online up to %d hours before. Please call us on %s.', (int) Aftercare::setting( 'reschedule_hours' ), Notify::site_vars()['phone'] ) );
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! isset( Slots::definitions()[ $slot ] ) ) {
				return new \WP_Error( 'invalid', 'Please choose a new date and arrival time.' );
			}
			if ( $date === $booking['job_date'] && $slot === $booking['slot'] ) {
				return new \WP_Error( 'same', 'That is already your booking time.' );
			}
			$start = Slots::start_timestamp( $date, $slot );
			self::slot_context( $booking );
			if ( $force ? $start <= time() : ! Slots::is_available( $date, $slot, (float) $booking['hours'], (int) $booking['cleaners'], (int) $booking['quote_id'] ) ) {
				return new \WP_Error( 'unavailable', $force ? 'Choose a time in the future.' : 'Sorry, that time is no longer available. Please choose another.' );
			}

			$old_start = (string) $booking['start_at'];
			$old_label = Bookings::start_label( $booking );
			Db::update( 'bookings', $id, [
				'job_date' => $date,
				'slot'     => $slot,
				'start_at' => Db::datetime( $start ),
				'end_at'   => Db::datetime( $start + (int) round( (float) $booking['hours'] * HOUR_IN_SECONDS ) ),
			] );
			$booking = Bookings::get( $id );
			Events::log( 'booking', $id, 'rescheduled', sprintf( 'Moved from %s to %s (by %s)', $old_label, Bookings::start_label( $booking ), $actor ), [ 'from' => $old_start, 'to' => $booking['start_at'], 'by' => $actor ], 'customer' === $actor ? 'customer' : '' );
		} finally {
			Db::unlock( 'manage:' . $id );
		}

		$crew     = Bookings::crew( $id );
		$dispatch = Aftercare::dispatch_handles( 'rescheduled' );
		$vars     = [
			'old_date'      => $old_label,
			'crew'          => $crew ? implode( ', ', array_map( fn( $o ) => $o['cleaner']['name'] ?? '?', $crew ) ) : 'none',
			'dispatch_note' => $dispatch ? 'Automatic dispatch is re-checking cleaners for the new time.' : 'Please check the cleaners can still make the new time.',
		] + Notify::booking_vars( $booking );
		Notify::send( 'reschedule_customer', (string) $booking['email'], $vars, [ 'booking', $id ] );
		if ( 'customer' === $actor ) {
			Notify::send( 'reschedule_admin', Settings::admin_email(), $vars, [ 'booking', $id ] );
		}
		$when = Slots::date_label( $date, 'D j M' ) . ' ' . strtolower( Slots::label( $slot ) );
		Aftercare::sms( (string) $booking['phone'], sprintf( '%s: your booking %s has moved to %s. Details are in your email.', Settings::brand(), $booking['ref'], $when ), $id );
		if ( ! $dispatch ) {
			Aftercare::notify_crew( $booking, 'reschedule_cleaner', $vars, fn( $c ) => sprintf( '%s: job %s has moved to %s. Call the office if you can\'t make it.', Settings::brand(), $booking['ref'], $when ), $crew );
		}

		do_action( 'cc_booking_rescheduled', $id, $old_start );
		return true;
	}

	/**
	 * Customer cancellation with the policy refund.
	 *
	 * @return array|\WP_Error [ policy, mode, refund_status ]
	 */
	public static function cancel( int $id, string $actor = 'customer', ?int $now = null ) {
		if ( ! Db::lock( 'manage:' . $id ) ) {
			return new \WP_Error( 'busy', 'Please try again in a moment.' );
		}
		try {
			$booking = Bookings::get( $id );
			if ( ! $booking || ! self::can_cancel( $booking ) ) {
				return new \WP_Error( 'closed', 'This booking can no longer be cancelled online. Please call us on ' . Notify::site_vars()['phone'] . '.' );
			}
			$policy = self::policy( $booking, $now );
			$mode   = $policy['amount'] > 0 ? self::refund_mode( $booking ) : 'none';
			$label  = sprintf( 'Cancelled online by the customer %s before the start: %d%% refund under the policy', Aftercare::notice_label( $policy['hours'] ), $policy['pct'] );
			$status = 'No refund is due under the cancellation policy.';
			$reason = 'You cancelled it online. Under our cancellation policy no refund is due.';

			if ( $policy['amount'] > 0 ) {
				$amount = Money::format( $policy['amount'] );
				$result = null;
				if ( in_array( $mode, [ 'stripe', 'record' ], true ) ) {
					remove_action( 'cc_booking_refunded', [ Notify::class, 'booking_refunded' ], 10 );
					$result = Bookings::refund( $id, $policy['amount'], $label );
					add_action( 'cc_booking_refunded', [ Notify::class, 'booking_refunded' ], 10, 3 );
				}
				if ( null === $result || is_wp_error( $result ) ) {
					$error = is_wp_error( $result ) ? $result->get_error_message() : '';
					self::record_pending_refund( $booking, $policy['amount'], $label, $error );
					$mode   = 'manual';
					$status = sprintf( 'Refund of %s is PENDING — pay it by hand, then mark it paid on the booking.%s', $amount, $error ? ' (Stripe said: ' . $error . ')' : '' );
					$reason = sprintf( 'You cancelled it online, so you\'ll get %s back (%d%%) under our cancellation policy. We\'ll process the refund within 2 working days.', $amount, $policy['pct'] );
				} else {
					$status = sprintf( 'Refunded %s automatically.', $amount );
					$reason = sprintf( 'You cancelled it online, so we\'ve refunded %s (%d%%) under our cancellation policy. It usually reaches your account within 5–10 working days.', $amount, $policy['pct'] );
				}
			}

			Events::log( 'booking', $id, 'customer_cancelled', $label, $policy + [ 'mode' => $mode ], 'customer' === $actor ? 'customer' : '' );
			Bookings::cancel( $id, $reason );
		} finally {
			Db::unlock( 'manage:' . $id );
		}

		Notify::send( 'cancel_admin', Settings::admin_email(), [
			'notice'        => Aftercare::notice_label( $policy['hours'] ),
			'refund'        => Money::format( $policy['amount'] ),
			'refund_pct'    => $policy['pct'] . '%',
			'refund_status' => $status,
		] + Notify::booking_vars( Bookings::get( $id ) ), [ 'booking', $id ] );
		return [ 'policy' => $policy, 'mode' => $mode, 'refund_status' => $status ];
	}

	/** A refund owed but not paid automatically (manual payment, or Stripe keys missing). */
	public static function record_pending_refund( array $booking, int $amount, string $reason, string $error = '' ): int {
		$charge     = Db::find( 'payments', [ 'booking_id' => (int) $booking['id'], 'type' => 'charge', 'status' => 'succeeded' ], 'id ASC' );
		$payment_id = Db::insert( 'payments', [
			'booking_id'            => $booking['id'],
			'quote_id'              => $booking['quote_id'],
			'type'                  => 'refund',
			'status'                => 'pending',
			'provider'              => $charge['provider'] ?? 'manual',
			'amount_pence'          => $amount,
			'stripe_payment_intent' => (string) $booking['stripe_payment_intent'],
			'note'                  => mb_substr( 'Pending: ' . $reason, 0, 255 ),
		] );
		Events::log( 'booking', $booking['id'], 'refund_pending', sprintf( 'Refund of %s owed — to be paid by hand', Money::format( $amount ) ), [ 'payment_id' => $payment_id, 'error' => $error ] );
		Bookings::alert( 'Refund to pay by hand', sprintf( 'Booking %s was cancelled and %s is owed to %s, but it can\'t be refunded automatically (%s). Please refund it by hand, then press "Mark refund paid" on the booking.', $booking['ref'], Money::format( $amount ), $booking['customer_name'], $error ?: 'no online payment to refund' ), [ 'booking_id' => (int) $booking['id'] ] );
		do_action( 'cc_booking_refund_pending', (int) $booking['id'], $amount, $payment_id );
		return $payment_id;
	}

	public static function pending_refunds( int $booking_id = 0 ): array {
		return Db::all( 'payments', array_filter( [ 'type' => 'refund', 'status' => 'pending', 'booking_id' => $booking_id ?: null ], fn( $v ) => null !== $v ), 'id ASC' );
	}

	/** Admin confirms a pending refund was paid by hand. */
	public static function mark_refund_paid( int $payment_id ) {
		$payment = Db::get( 'payments', $payment_id );
		if ( ! $payment || 'refund' !== $payment['type'] || 'pending' !== $payment['status'] ) {
			return new \WP_Error( 'missing', 'That refund isn\'t pending.' );
		}
		$booking = Bookings::get( (int) $payment['booking_id'] );
		if ( ! $booking ) {
			return new \WP_Error( 'missing', 'Booking not found.' );
		}
		$refunded = (int) $booking['refunded_pence'] + (int) $payment['amount_pence'];
		Db::update( 'payments', $payment_id, [ 'status' => 'succeeded', 'note' => mb_substr( preg_replace( '/^Pending: /', 'Paid by hand: ', (string) $payment['note'] ), 0, 255 ), 'created_by' => get_current_user_id() ] );
		Db::update( 'bookings', $booking['id'], [
			'refunded_pence' => $refunded,
			'payment_status' => $refunded >= (int) $booking['paid_pence'] ? 'refunded' : 'partially_refunded',
		] );
		Events::log( 'booking', $booking['id'], 'refunded', sprintf( 'Refund of %s marked as paid by hand', Money::format( (int) $payment['amount_pence'] ) ), [ 'payment_id' => $payment_id, 'provider' => 'manual' ] );
		do_action( 'cc_booking_refunded', (int) $booking['id'], (int) $payment['amount_pence'], $payment_id );
		return true;
	}

	/** Any cancellation (customer or admin): tell the cleaners still on the job, unless phase 2 dispatch does. */
	public static function status_changed( $booking_id, $to, $from = '' ): void {
		if ( 'cancelled' !== $to || Aftercare::dispatch_handles( 'cancelled' ) ) {
			return;
		}
		$booking = Bookings::get( (int) $booking_id );
		if ( ! $booking ) {
			return;
		}
		$when = Slots::date_label( (string) $booking['job_date'], 'D j M' );
		Aftercare::notify_crew( $booking, 'cancel_cleaner', Notify::booking_vars( $booking ), fn( $c ) => sprintf( '%s: job %s on %s has been cancelled. You no longer need to go.', Settings::brand(), $booking['ref'], $when ) );
	}

	/* ---------------------------------------------------------------------
	 * Booking page
	 * ------------------------------------------------------------------ */

	public static function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['cc_manage'] ) || ! get_query_var( 'cc_booking' ) ) {
			return;
		}
		Frontend::private_headers();
		$token   = (string) get_query_var( 'cc_token' );
		$booking = Bookings::verify( (string) get_query_var( 'cc_booking' ), $token );
		if ( ! $booking ) {
			return;
		}
		$url    = Bookings::url( $booking, $token );
		$action = sanitize_key( wp_unslash( (string) $_POST['cc_manage'] ) );

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) ( $_POST['_ccnonce'] ?? '' ) ) ), self::NONCE . $booking['id'] ) ) {
			self::redirect( $url, 'error', 'This page had expired, so nothing was changed. Please try again.' );
		}
		if ( ! Security::rate_limit( 'manage', 20, HOUR_IN_SECONDS ) ) {
			self::redirect( $url, 'error', 'Too many attempts. Please wait a while or call us.' );
		}

		switch ( $action ) {
			case 'reschedule':
				[ $date, $slot ] = array_pad( explode( '|', sanitize_text_field( wp_unslash( (string) ( $_POST['when'] ?? '' ) ) ), 2 ), 2, '' );
				$result = self::reschedule( (int) $booking['id'], $date, $slot );
				is_wp_error( $result )
					? self::redirect( $url, 'error', $result->get_error_message() )
					: self::redirect( $url, 'success', 'Done — your booking has moved to ' . Bookings::start_label( Bookings::get( (int) $booking['id'] ) ) . '. We\'ve emailed you the details.' );
				break;

			case 'cancel':
				if ( empty( $_POST['confirm'] ) ) {
					self::redirect( $url, 'error', 'Please tick the box to confirm you want to cancel.' );
				}
				$result = self::cancel( (int) $booking['id'] );
				is_wp_error( $result )
					? self::redirect( $url, 'error', $result->get_error_message() )
					: self::redirect( $url, 'success', 'Your booking has been cancelled. ' . ( $result['policy']['amount'] > 0 ? sprintf( 'You\'ll get %s back.', Money::format( $result['policy']['amount'] ) ) : 'No refund is due under our cancellation policy.' ) . ' We\'ve emailed you a confirmation.' );
				break;

			case 'reclean':
				$result = Reclean::submit( $booking, sanitize_textarea_field( wp_unslash( (string) ( $_POST['message'] ?? '' ) ) ), Files::from_request( $_FILES ) );
				is_wp_error( $result )
					? self::redirect( $url, 'error', $result->get_error_message(), [ 'reclean' => 1 ] )
					: self::redirect( $url, 'success', 'Thank you — we\'ve got your re-clean request and will be in touch shortly.' );
				break;
		}
		self::redirect( $url, 'error', 'Unknown action.' );
	}

	private static function redirect( string $url, string $type, string $message, array $args = [] ): void {
		$key = strtolower( wp_generate_password( 12, false ) );
		set_transient( 'cc_manage_msg_' . $key, [ 'type' => $type, 'message' => $message ], 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( [ 'cc_msg' => $key ] + $args, $url ) . '#cc-manage', 303 );
		exit;
	}

	public static function nonce_field( array $booking ): string {
		return '<input type="hidden" name="_ccnonce" value="' . esc_attr( wp_create_nonce( self::NONCE . $booking['id'] ) ) . '">';
	}

	/** Panels in the booking page sidebar (hook cc_bookings_booking_page_actions). */
	public static function panel( $booking, $token ): void {
		$booking = Bookings::get( (int) $booking['id'] ) ?? $booking;
		$phone   = Notify::site_vars()['phone'];
		$key     = sanitize_key( wp_unslash( (string) ( $_GET['cc_msg'] ?? '' ) ) );
		$msg     = $key ? get_transient( 'cc_manage_msg_' . $key ) : null;
		echo '<div id="cc-manage" class="cc-manage">';
		if ( is_array( $msg ) ) {
			printf( '<div class="cc-notice cc-notice--%s" role="status"><p>%s</p></div>', 'success' === $msg['type'] ? 'success' : 'warning', esc_html( (string) $msg['message'] ) );
		}

		if ( in_array( $booking['status'], Aftercare::OPEN, true ) ) {
			$open = 'reschedule' === ( $_GET['manage'] ?? '' ) ? ' open' : '';
			if ( self::can_reschedule( $booking ) ) {
				$options = self::slot_options( $booking );
				echo '<details class="cc-panel cc-private__card cc-private__ask"' . $open . '><summary>Change date or time</summary>';
				if ( $options ) {
					echo '<form method="post">' . self::nonce_field( $booking ) . '<input type="hidden" name="cc_manage" value="reschedule">'; // phpcs:ignore WordPress.Security.EscapeOutput
					printf( '<p class="cc-small">Free to change up to %d hours before your clean. Only times we can staff are shown.</p>', (int) Aftercare::setting( 'reschedule_hours' ) );
					echo '<div class="cc-quote__field"><label for="cc-manage-when">New date and arrival time</label><select id="cc-manage-when" name="when" required><option value="">Choose…</option>';
					foreach ( $options as $date => $slots ) {
						printf( '<optgroup label="%s">', esc_attr( Slots::date_label( $date, 'l j F' ) ) );
						foreach ( $slots as $slot => $label ) {
							printf( '<option value="%s">%s — %s</option>', esc_attr( $date . '|' . $slot ), esc_html( Slots::date_label( $date, 'D j M' ) ), esc_html( $label ) );
						}
						echo '</optgroup>';
					}
					echo '</select></div><button type="submit" class="cc-btn cc-btn--primary">Move my booking</button></form>';
				} else {
					printf( '<p>There are no other free times at the moment. Please call us on %s.</p>', esc_html( $phone ) );
				}
				echo '</details>';
			} else {
				printf( '<section class="cc-panel cc-private__card"><h2 class="cc-private__heading">Need to change it?</h2><p class="cc-small">It\'s less than %d hours to go, so please call us on %s and we\'ll do our best.</p></section>', (int) Aftercare::setting( 'reschedule_hours' ), esc_html( $phone ) );
			}

			if ( self::can_cancel( $booking ) ) {
				$policy = self::policy( $booking );
				echo '<details class="cc-panel cc-private__card cc-private__ask"><summary>Cancel booking</summary><form method="post">' . self::nonce_field( $booking ) . '<input type="hidden" name="cc_manage" value="cancel">'; // phpcs:ignore WordPress.Security.EscapeOutput
				if ( (int) $booking['paid_pence'] <= 0 ) {
					echo '<p>This visit is free, so there\'s nothing to refund.</p>';
				} elseif ( $policy['amount'] > 0 ) {
					printf( '<p><strong>If you cancel now you\'ll get %s back</strong> (%d%% of what you paid).</p>', esc_html( Money::format( $policy['amount'] ) ), (int) $policy['pct'] );
				} else {
					echo '<p><strong>If you cancel now, no refund is due</strong> under our cancellation policy.</p>';
				}
				echo '<ul class="cc-small">';
				foreach ( self::policy_lines() as $line ) {
					echo '<li>' . esc_html( $line ) . '</li>';
				}
				echo '</ul>';
				echo '<label class="cc-quote__check"><input type="checkbox" name="confirm" value="1" required> <span>Yes, cancel my booking</span></label>';
				echo '<button type="submit" class="cc-btn cc-btn--outline">Cancel booking</button></form></details>';
			}
		}
		echo '</div>';
	}
}

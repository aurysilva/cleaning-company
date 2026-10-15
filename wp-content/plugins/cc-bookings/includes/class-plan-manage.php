<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Regular-plan panel on the customer booking page (any visit of the plan): pay a payment-due visit, skip a
 * visit, pause / resume, change how often, update the card (Stripe Checkout, setup mode) and end the plan.
 * Forms post back to the booking URL with the Phase 3 nonce and token, and redirect with a one-time message
 * shown by the Phase 3 panel (#cc-manage). Runs before Manage::handle_post (priority -1).
 */
final class Plan_Manage {
	public static function init(): void {
		add_action( 'template_redirect', [ self::class, 'handle_post' ], -1 );
		add_action( 'cc_bookings_booking_page_actions', [ self::class, 'panel' ], 15, 2 );
		add_filter( 'cc_bookings_booking_page_notice', [ self::class, 'notice' ], 10, 2 );
	}

	public static function handle_post(): void {
		$action = sanitize_key( wp_unslash( (string) ( $_POST['cc_manage'] ?? '' ) ) );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! str_starts_with( $action, 'plan_' ) || ! get_query_var( 'cc_booking' ) ) {
			return;
		}
		Frontend::private_headers();
		$token   = (string) get_query_var( 'cc_token' );
		$booking = Bookings::verify( (string) get_query_var( 'cc_booking' ), $token );
		if ( ! $booking ) {
			return;
		}
		self::handle_action( $booking, Bookings::url( $booking, $token ) );
	}

	/**
	 * Run a posted plan action for a booking the caller has already authorised (by token, or as the
	 * logged-in client), then redirect back to $url.
	 */
	public static function handle_action( array $booking, string $url ): void {
		$action = sanitize_key( wp_unslash( (string) ( $_POST['cc_manage'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked below.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) ( $_POST['_ccnonce'] ?? '' ) ) ), Manage::NONCE . $booking['id'] ) ) {
			self::redirect( $url, 'error', 'This page had expired, so nothing was changed. Please try again.' );
		}
		if ( ! Security::rate_limit( 'manage', 20, HOUR_IN_SECONDS ) ) {
			self::redirect( $url, 'error', 'Too many attempts. Please wait a while or call us.' );
		}
		$plan = Plans::for_booking( $booking );
		if ( ! $plan ) {
			self::redirect( $url, 'error', 'This booking isn\'t part of a regular plan.' );
		}
		$plan_id = (int) $plan['id'];

		if ( 'plan_pay' === $action ) {
			$result = Plan_Charges::pay_session( $booking, '', $url );
			if ( is_wp_error( $result ) ) {
				self::redirect( $url, 'error', $result->get_error_message() );
			}
			wp_redirect( $result, 303 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Stripe Checkout
			exit;
		}
		if ( ! Recurring::setting( 'customer_changes' ) ) {
			self::redirect( $url, 'error', 'Please call us to change your plan.' );
		}

		switch ( $action ) {
			case 'plan_card':
				$result = Plan_Charges::card_session( $plan, $booking, '', $url );
				if ( is_wp_error( $result ) ) {
					self::redirect( $url, 'error', $result->get_error_message() );
				}
				wp_redirect( $result, 303 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Stripe Checkout
				exit;

			case 'plan_skip':
				$result  = Plans::skip( $plan_id, absint( $_POST['visit'] ?? 0 ) );
				$message = 'Done — that visit is skipped and won\'t be charged. We\'ve emailed you a confirmation.';
				break;

			case 'plan_pause':
				$result  = Plans::pause( $plan_id );
				$message = 'Your plan is paused. No more visits will be booked or charged until you resume it.';
				break;

			case 'plan_resume':
				$result  = Plans::resume( $plan_id );
				$message = 'Welcome back — your plan is running again. We\'ve emailed you your next visits.';
				break;

			case 'plan_frequency':
				$result  = Plans::change_frequency( $plan_id, sanitize_key( wp_unslash( (string) ( $_POST['frequency'] ?? '' ) ) ) );
				$message = 'Done — your plan now runs ' . Recurring::every( sanitize_key( wp_unslash( (string) ( $_POST['frequency'] ?? '' ) ) ) ) . '. We\'ve emailed you the new dates.';
				break;

			case 'plan_cancel':
				if ( empty( $_POST['confirm'] ) ) {
					self::redirect( $url, 'error', 'Please tick the box to confirm you want to end your plan.' );
				}
				$result  = Plans::cancel( $plan_id );
				$message = 'Your regular plan has ended and no more payments will be taken. We\'ve emailed you a confirmation.';
				break;

			default:
				self::redirect( $url, 'error', 'Unknown action.' );
		}
		is_wp_error( $result ) ? self::redirect( $url, 'error', $result->get_error_message() ) : self::redirect( $url, 'success', $message );
	}

	private static function redirect( string $url, string $type, string $message ): void {
		$key = strtolower( wp_generate_password( 12, false ) );
		set_transient( 'cc_manage_msg_' . $key, [ 'type' => $type, 'message' => $message ], 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( [ 'cc_msg' => $key ], $url ) . '#cc-manage', 303 );
		exit;
	}

	/** Status notice at the top of the booking page for plan visits that aren't confirmed yet. */
	public static function notice( $html, $booking ) {
		if ( '' !== $html || ! is_array( $booking ) || ! (int) $booking['series_id'] ) {
			return $html;
		}
		$amount = Money::format( (int) $booking['total_pence'] );
		switch ( $booking['status'] ) {
			case 'scheduled':
				return sprintf(
					'<div class="cc-notice cc-notice--info"><strong>This regular visit is scheduled.</strong><p>We\'ll charge %s to your saved card on %s (%d hours before) and confirm it then. Don\'t need it? Skip it from "Your regular plan" before then.</p></div>',
					esc_html( $amount ),
					esc_html( wp_date( 'l j F', Plan_Charges::charge_at( $booking ) ) ),
					(int) Recurring::charge_hours()
				);
			case 'payment_due':
				return sprintf(
					'<div class="cc-notice cc-notice--warning"><strong>Payment needed for this visit.</strong><p>We couldn\'t take %s from your saved card. Please <a href="#cc-pay">pay now</a> by %s, or we\'ll have to release this visit.</p></div>',
					esc_html( $amount ),
					esc_html( wp_date( 'l j F \a\t H:i', Plan_Charges::release_at( $booking ) ) )
				);
			case 'skipped':
				return '<div class="cc-notice cc-notice--info"><strong>This visit was skipped.</strong><p>No payment was taken for it.</p></div>';
		}
		return $html;
	}

	public static function panel( $booking, $token ): void {
		$booking = Bookings::get( (int) $booking['id'] );
		$plan    = $booking ? Plans::for_booking( $booking ) : null;
		if ( ! $plan ) {
			return;
		}
		$phone    = Notify::site_vars()['phone'];
		$nonce    = Manage::nonce_field( $booking );
		$upcoming = Plans::upcoming( (int) $plan['id'] );
		$changes  = (bool) Recurring::setting( 'customer_changes' );

		echo '<section id="cc-plan" class="cc-panel cc-private__card"><h2 class="cc-private__heading">Your regular plan</h2>';
		if ( isset( $_GET['paid'] ) && ! Plans::is_paid( $booking ) ) {
			echo '<div class="cc-notice cc-notice--success" role="status"><p>Thank you — we\'re confirming your payment. Refresh this page in a minute.</p></div>';
		}
		if ( 'updated' === ( $_GET['card'] ?? '' ) ) {
			echo '<div class="cc-notice cc-notice--success" role="status"><p>Thank you — your new card is being saved. It\'ll show here in a minute.</p></div>';
		}

		if ( 'payment_due' === $booking['status'] ) {
			printf( '<div id="cc-pay" class="cc-notice cc-notice--warning"><p><strong>%s due for this visit.</strong> Pay by %s to keep it.</p>', esc_html( Money::format( (int) $booking['total_pence'] ) ), esc_html( wp_date( 'D j M, H:i', Plan_Charges::release_at( $booking ) ) ) );
			if ( Stripe::enabled() ) {
				echo '<form method="post">' . $nonce . '<input type="hidden" name="cc_manage" value="plan_pay"><button type="submit" class="cc-btn cc-btn--primary">' . esc_html( 'Pay ' . Money::format( (int) $booking['total_pence'] ) . ' now' ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
			} else {
				printf( '<p>Please call us on %s to pay.</p>', esc_html( $phone ) );
			}
			echo '</div>';
		}

		echo '<dl class="cc-private__details">';
		foreach ( Plans::details_lines( $plan ) as [ $label, $value ] ) {
			printf( '<div><dt>%s</dt><dd>%s</dd></div>', esc_html( $label ), nl2br( esc_html( (string) $value ) ) );
		}
		echo '</dl>';

		if ( 'cancelled' === $plan['status'] ) {
			echo '<p class="cc-small">This plan has ended. To start again, get a new quote or call us.</p></section>';
			return;
		}
		if ( ! $changes ) {
			printf( '<p class="cc-small">To change your plan, call us on %s.</p></section>', esc_html( $phone ) );
			return;
		}

		$skippable = array_filter( $upcoming, fn( $v ) => in_array( $v['status'], [ 'scheduled', 'payment_due' ], true ) && ! Plans::is_paid( $v ) );
		if ( $skippable ) {
			echo '<details class="cc-private__ask"><summary>Skip a visit</summary><form method="post">' . $nonce . '<input type="hidden" name="cc_manage" value="plan_skip">'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<div class="cc-quote__field"><label for="cc-plan-visit">Visit to skip</label><select id="cc-plan-visit" name="visit" required>';
			foreach ( $skippable as $visit ) {
				printf( '<option value="%d"%s>%s</option>', (int) $visit['id'], selected( (int) $visit['id'], (int) $booking['id'], false ), esc_html( Bookings::start_label( $visit ) ) );
			}
			echo '</select></div><p class="cc-small">Paid visits can be cancelled from their own booking page instead.</p><button type="submit" class="cc-btn cc-btn--outline">Skip this visit</button></form></details>';
		}

		echo '<details class="cc-private__ask"><summary>Change how often</summary><form method="post">' . $nonce . '<input type="hidden" name="cc_manage" value="plan_frequency">'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="cc-quote__field"><label for="cc-plan-frequency">How often</label><select id="cc-plan-frequency" name="frequency" required>';
		foreach ( Recurring::FREQUENCIES as $key => $info ) {
			$price = ! empty( $plan['meta']['bespoke'] ) ? null : Recurring::visit_price( (string) $plan['service_slug'], (array) $plan['answers'], (string) $plan['next_date'], (string) $plan['slot'], $key );
			$pence = $key === $plan['frequency'] ? (int) $plan['price_pence'] : (int) ( $price['total'] ?? $plan['price_pence'] );
			printf( '<option value="%s"%s>%s — %s per visit</option>', esc_attr( $key ), selected( $key, $plan['frequency'], false ), esc_html( $info['label'] ), esc_html( Money::format( $pence ) ) );
		}
		echo '</select></div><p class="cc-small">Your next visit stays as booked; the visits after it move to the new pattern.</p><button type="submit" class="cc-btn cc-btn--outline">Change plan</button></form></details>';

		echo '<details class="cc-private__ask"><summary>' . ( 'paused' === $plan['status'] ? 'Resume plan' : 'Pause plan' ) . '</summary><form method="post">' . $nonce; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( 'paused' === $plan['status'] ) {
			echo '<input type="hidden" name="cc_manage" value="plan_resume"><p class="cc-small">We\'ll book your next visits on your usual day and charge each one before it as normal.</p><button type="submit" class="cc-btn cc-btn--primary">Resume my plan</button>';
		} else {
			echo '<input type="hidden" name="cc_manage" value="plan_pause"><p class="cc-small">We\'ll remove your upcoming unpaid visits and stop charging until you resume. Paid visits still go ahead.</p><button type="submit" class="cc-btn cc-btn--outline">Pause my plan</button>';
		}
		echo '</form></details>';

		if ( Stripe::enabled() ) {
			echo '<details class="cc-private__ask"><summary>Update card</summary><form method="post">' . $nonce . '<input type="hidden" name="cc_manage" value="plan_card"><p class="cc-small">You\'ll enter the new card on our secure Stripe payment page. Nothing is charged now.</p><button type="submit" class="cc-btn cc-btn--outline">Update my card</button></form></details>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo '<details class="cc-private__ask"><summary>End plan</summary><form method="post">' . $nonce . '<input type="hidden" name="cc_manage" value="plan_cancel">'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="cc-small">We\'ll stop booking and charging visits. Visits you\'ve already paid for still go ahead unless you cancel them.</p>';
		echo '<label class="cc-quote__check"><input type="checkbox" name="confirm" value="1" required> <span>Yes, end my regular plan</span></label>';
		echo '<button type="submit" class="cc-btn cc-btn--outline">End plan</button></form></details>';
		echo '</section>';
	}
}

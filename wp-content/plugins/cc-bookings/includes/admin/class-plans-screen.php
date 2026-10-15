<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Bookings;
use CC\Bookings\Db;
use CC\Bookings\Events;
use CC\Bookings\Money;
use CC\Bookings\Plan_Charges;
use CC\Bookings\Plans;
use CC\Bookings\Queue;
use CC\Bookings\Recurring;
use CC\Bookings\Settings;
use CC\Bookings\Status;
use CC\Bookings\Stripe;

defined( 'ABSPATH' ) || exit;

/**
 * Bookings → Regular plans: plan list, plan detail (visits, charge now, skip, mark paid by hand, release,
 * pause / resume / change frequency / cancel, timeline) and the phase 4 settings. Also adds a "Regular plan"
 * box to the sidebar of every plan visit.
 */
final class Plans_Screen {
	const PAGE = 'cc-bookings-plans';

	const ACTIONS = [ 'plan_settings', 'plan_pause', 'plan_resume', 'plan_cancel', 'plan_frequency', 'plan_generate', 'plan_charge', 'plan_skip', 'plan_mark_paid', 'plan_release', 'plan_note' ];

	public static function init(): void {
		add_filter( 'cc_bookings_admin_pages', [ self::class, 'pages' ], 20 );
		add_action( 'cc_bookings_admin_booking_sidebar', [ self::class, 'sidebar' ], 5 );
		foreach ( self::ACTIONS as $do ) {
			add_action( 'cc_bookings_admin_action_' . $do, fn() => self::handle( $do ) );
		}
	}

	/** "Regular plans" right after Bookings. */
	public static function pages( array $pages ): array {
		$out = [];
		foreach ( $pages as $slug => $page ) {
			$out[ $slug ] = $page;
			if ( 'cc-bookings-bookings' === $slug ) {
				$out[ self::PAGE ] = [ 'Regular plans', [ self::class, 'render' ] ];
			}
		}
		return $out + [ self::PAGE => [ 'Regular plans', [ self::class, 'render' ] ] ];
	}

	private static function fields(): array {
		return [
			'enabled'                => [ 'Offer regular plans', 'Regular House Cleaning quotes with a frequency get a card authority box, a saved card and automatic charges for later visits. Existing plans keep running if this is turned off.', 'bool' ],
			'holiday_lets'           => [ 'Include weekly holiday-let changeovers', '', 'bool' ],
			'horizon_visits'         => [ 'Visits booked ahead', 'How many upcoming visits each plan keeps in the diary (1–12).', 'int' ],
			'charge_hours'           => [ 'Charge the card (hours before each visit)', '', 'int' ],
			'release_hours'          => [ 'Release an unpaid visit (hours before)', 'If a charge fails, the customer has until this point to pay with the link we send. Must be less than the charge time.', 'int' ],
			'adjust_weekly_pct'      => [ 'Weekly price adjustment (%)', 'Negative for a discount, e.g. -10. 0 = no change. Shown as its own line on quotes.', 'pct' ],
			'adjust_fortnightly_pct' => [ 'Fortnightly price adjustment (%)', '', 'pct' ],
			'adjust_four_weekly_pct' => [ 'Every-four-weeks price adjustment (%)', '', 'pct' ],
			'customer_changes'       => [ 'Customers can skip, pause, change and cancel online', '', 'bool' ],
		];
	}

	public static function render(): void {
		$id = Admin::int( 'id' );
		if ( $id && ( $plan = Plans::get( $id ) ) ) {
			self::detail( $plan );
			return;
		}
		Admin::header( 'Regular plans' );
		$status = sanitize_key( Admin::text( 'status' ) );
		echo Admin::filters( self::PAGE, Recurring::PLAN_STATUSES, $status, fn( $s ) => Db::count( Recurring::TABLE, $s ? [ 'status' => $s ] : [] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		$plans = Db::all( Recurring::TABLE, $status ? [ 'status' => $status ] : [], 'id DESC', 200 );
		echo '<table class="widefat striped"><thead><tr><th>Plan</th><th>Customer</th><th>Service</th><th>How often</th><th>Per visit</th><th>Next visit</th><th>Card</th><th>Status</th><th>Started</th></tr></thead><tbody>';
		if ( ! $plans ) {
			echo '<tr><td colspan="9">No plans yet. A plan starts when a customer pays for the first visit of a regular clean.</td></tr>';
		}
		foreach ( $plans as $plan ) {
			$plan = Plans::get( (int) $plan['id'] );
			$next = Plans::upcoming( (int) $plan['id'] )[0] ?? null;
			printf(
				'<tr><td><a href="%s"><strong>%s</strong></a>%s</td><td>%s<br><span class="description">%s</span></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( Plans::admin_url( (int) $plan['id'] ) ),
				esc_html( $plan['ref'] ),
				$plan['is_test'] ? ' <span class="cc-admin__tag">Test</span>' : '',
				esc_html( $plan['customer_name'] ),
				esc_html( $plan['postcode'] ),
				esc_html( $plan['service_name'] ),
				esc_html( Recurring::label( (string) $plan['frequency'] ) ),
				esc_html( Money::format( (int) $plan['price_pence'] ) ),
				$next ? esc_html( Bookings::start_label( $next ) . ' (' . Status::label( 'booking', $next['status'] ) . ')' ) : '—',
				esc_html( $plan['card_label'] ?: '—' ),
				esc_html( Recurring::PLAN_STATUSES[ $plan['status'] ] ?? $plan['status'] ),
				esc_html( Admin::when( $plan['created_at'], 'j M Y' ) )
			);
		}
		echo '</tbody></table>';
		self::settings_form();
		Admin::footer();
	}

	private static function settings_form(): void {
		echo '<h2 id="settings">Settings</h2>';
		echo Admin::form( 'plan_settings' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table"><tbody>';
		foreach ( self::fields() as $key => [ $label, $help, $type ] ) {
			$value = Recurring::setting( $key );
			$name  = 'r[' . $key . ']';
			if ( 'bool' === $type ) {
				$input = sprintf( '<input type="hidden" name="%1$s" value="0"><label><input type="checkbox" name="%1$s" value="1"%2$s> Yes</label>', esc_attr( $name ), checked( (bool) $value, true, false ) );
			} else {
				$input = sprintf( '<input type="number" name="%s" value="%s" class="small-text"%s>', esc_attr( $name ), esc_attr( (string) $value ), 'pct' === $type ? ' step="0.5" min="-50" max="50"' : ' min="1"' );
			}
			printf( '<tr><th>%s</th><td>%s%s</td></tr>', esc_html( $label ), $input, $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		echo '<p class="description">Email wording for failed payments and plan changes is under Settings → Emails. Stripe must send <code>payment_intent.succeeded</code> and <code>payment_intent.payment_failed</code> to the webhook as well as the checkout events.</p>';
		echo '<p><button class="button button-primary">Save settings</button></p></form>';
	}

	private static function detail( array $plan ): void {
		$id = (int) $plan['id'];
		Admin::header( 'Regular plan ' . $plan['ref'], ' <a href="' . esc_url( Admin::url( self::PAGE ) ) . '" class="page-title-action">All plans</a>' );
		echo '<div class="cc-admin__cols"><div class="cc-admin__main">';

		echo '<h2>Plan</h2><table class="form-table"><tbody>';
		$rows = [
			'Status'     => ( Recurring::PLAN_STATUSES[ $plan['status'] ] ?? $plan['status'] ) . ( $plan['paused_at'] ? ' since ' . Admin::when( $plan['paused_at'] ) : '' ) . ( $plan['cancelled_at'] ? ' on ' . Admin::when( $plan['cancelled_at'] ) : '' ),
			'Customer'   => $plan['customer_name'] . ' · ' . $plan['email'] . ' · ' . $plan['phone'],
			'Address'    => implode( ', ', array_filter( [ $plan['address_1'], $plan['address_2'], $plan['town'], $plan['postcode'] ] ) ),
			'Service'    => $plan['service_name'],
			'How often'  => Recurring::label( (string) $plan['frequency'] ) . ', ' . Plans::weekday_label( $plan ) . ', ' . \CC\Bookings\Slots::label( (string) $plan['slot'] ),
			'Per visit'  => Money::format( (int) $plan['price_pence'] ) . ( ! empty( $plan['meta']['bespoke'] ) ? ' (agreed price)' : '' ),
			'Card'       => ( $plan['card_label'] ?: 'none saved' ) . ' · ' . ( Plan_Charges::is_simulated( $plan ) ? 'simulated (test) — never charged in Stripe' : ( $plan['stripe_customer'] ?: 'no Stripe customer' ) ),
			'Next date'  => $plan['next_date'] ? 'Next visit to be added: ' . \CC\Bookings\Slots::date_label( (string) $plan['next_date'], 'D j M Y' ) : '—',
			'Authority'  => (string) ( $plan['consent']['text'] ?? '' ) . ( isset( $plan['consent']['at'] ) ? ' — agreed ' . Admin::when( (string) $plan['consent']['at'] ) : '' ),
			'Started'    => Admin::when( $plan['created_at'] ) . ( ( $first = Bookings::get( (int) $plan['first_booking_id'] ) ) ? ' from booking ' . $first['ref'] : '' ),
		];
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</tbody></table>';

		echo '<h2>Visits</h2><table class="widefat striped"><thead><tr><th>Visit</th><th>When</th><th>Status</th><th>Payment</th><th>Charge / release</th><th></th></tr></thead><tbody>';
		foreach ( Plans::visits( $id ) as $v ) {
			$vid     = (int) $v['id'];
			$charge  = Queue::next( Plan_Charges::CHARGE_HOOK, [ $vid ] );
			$release = Queue::next( Plan_Charges::RELEASE_HOOK, [ $vid ] );
			$open    = in_array( $v['status'], [ 'scheduled', 'payment_due' ], true ) && ! Plans::is_paid( $v );
			$actions = '';
			if ( $open ) {
				$actions .= Admin::button( 'plan_charge', [ 'id' => $id, 'visit' => $vid ], 'Charge now', 'button button-small', Plan_Charges::is_simulated( $plan ) ? 'Run a simulated charge now?' : 'Charge the customer\'s saved card now?' );
				$actions .= ' ' . Admin::button( 'plan_mark_paid', [ 'id' => $id, 'visit' => $vid ], 'Paid by hand', 'button button-small', 'Confirm the customer has paid for this visit another way. It will be confirmed and sent to dispatch.' );
				$actions .= ' ' . Admin::button( 'plan_skip', [ 'id' => $id, 'visit' => $vid ], 'Skip', 'button button-small', 'Skip this visit? The customer will be emailed.' );
				if ( 'payment_due' === $v['status'] ) {
					$actions .= ' ' . Admin::button( 'plan_release', [ 'id' => $id, 'visit' => $vid ], 'Release now', 'button button-small button-link-delete', 'Cancel this unpaid visit now and tell the customer?' );
				}
			}
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( Bookings::admin_url( $vid ) ),
				esc_html( $v['ref'] ),
				esc_html( Bookings::start_label( $v ) ),
				esc_html( Status::label( 'booking', $v['status'] ) ),
				esc_html( Money::format( (int) $v['paid_pence'] ) . ' of ' . Money::format( (int) $v['total_pence'] ) . ' · ' . $v['payment_status'] ),
				esc_html( ( $charge ? 'charge ' . wp_date( 'D j M H:i', $charge ) : '' ) . ( $release ? ( $charge ? '; ' : '' ) . 'release ' . wp_date( 'D j M H:i', $release ) : '' ) ?: '—' ),
				$actions // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
		echo '</tbody></table>';

		echo '<h2>Timeline</h2>';
		echo Admin::timeline( Db::all( 'events', [ 'object_type' => 'plan', 'object_id' => $id ], 'id DESC', 100 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div><div class="cc-admin__side">';

		echo '<h2>Manage plan</h2>';
		if ( 'active' === $plan['status'] ) {
			echo '<p>' . Admin::button( 'plan_generate', [ 'id' => $id ], 'Top up visits now' ) . ' ' . Admin::button( 'plan_pause', [ 'id' => $id ], 'Pause', 'button', 'Pause this plan? Unpaid upcoming visits are skipped and the customer is emailed.' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( 'paused' === $plan['status'] ) {
			echo '<p>' . Admin::button( 'plan_resume', [ 'id' => $id ], 'Resume', 'button button-primary', 'Resume this plan? New visits are booked and the customer is emailed.' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( 'cancelled' !== $plan['status'] ) {
			echo Admin::form( 'plan_frequency', [ 'id' => $id ] ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p><select name="frequency">';
			foreach ( Recurring::FREQUENCIES as $key => $info ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $key, $plan['frequency'], false ), esc_html( $info['label'] ) );
			}
			echo '</select> <button class="button">Change how often</button></p><p class="description">Reprices from today\'s rates; the next visit stays, later unpaid visits move to the new pattern. The customer is emailed.</p></form>';
			echo Admin::form( 'plan_cancel', [ 'id' => $id ], 'onsubmit="return confirm(\'End this plan? Unpaid upcoming visits are skipped and the customer is emailed.\')"' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p><input type="text" name="reason" class="regular-text" placeholder="Reason (internal)"></p><p><button class="button button-link-delete">Cancel plan</button></p></form>';
		}
		echo '<h3>Internal note</h3>' . Admin::form( 'plan_note', [ 'id' => $id ] ) . '<p><textarea name="note" rows="3" class="large-text">' . esc_textarea( (string) $plan['admin_note'] ) . '</textarea></p><p><button class="button">Save note</button></p></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! Stripe::enabled() ) {
			echo '<div class="notice notice-warning inline"><p>Stripe isn\'t configured, so automatic charges will fail and customers will be asked to call.</p></div>';
		}
		echo '</div></div>';
		Admin::footer();
	}

	/** "Regular plan" box in the sidebar of plan visits. */
	public static function sidebar( array $b ): void {
		$plan = (int) $b['series_id'] ? Plans::get( (int) $b['series_id'] ) : null;
		if ( ! $plan ) {
			return;
		}
		echo '<h2>Regular plan</h2>';
		printf( '<p><a href="%s"><strong>%s</strong></a> — %s, %s per visit (%s).</p>', esc_url( Plans::admin_url( (int) $plan['id'] ) ), esc_html( $plan['ref'] ), esc_html( Recurring::label( (string) $plan['frequency'] ) ), esc_html( Money::format( (int) $plan['price_pence'] ) ), esc_html( strtolower( Recurring::PLAN_STATUSES[ $plan['status'] ] ?? $plan['status'] ) ) );
		if ( in_array( $b['status'], [ 'scheduled', 'payment_due' ], true ) && ! Plans::is_paid( $b ) ) {
			$charge = Queue::next( Plan_Charges::CHARGE_HOOK, [ (int) $b['id'] ] );
			printf( '<p>%s</p>', esc_html( 'scheduled' === $b['status'] ? ( $charge ? 'Card charge queued for ' . wp_date( 'D j M H:i', $charge ) . '.' : 'No charge queued.' ) : 'Payment failed — waiting for the customer to pay.' ) );
			echo Admin::button( 'plan_charge', [ 'id' => (int) $plan['id'], 'visit' => (int) $b['id'], 'back' => 'booking' ], 'Charge now', 'button', 'Charge the saved card for this visit now?' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function handle( string $do ): void {
		$id    = Admin::int( 'id' );
		$visit = Admin::int( 'visit' );
		$back  = 'booking' === Admin::text( 'back' ) && $visit ? Bookings::admin_url( $visit ) : ( $id ? Plans::admin_url( $id ) : Admin::url( self::PAGE ) );
		$msg   = fn( $result, string $ok ) => is_wp_error( $result ) ? Admin::done( $back, $result->get_error_message(), 'error' ) : Admin::done( $back, $ok );

		switch ( $do ) {
			case 'plan_settings':
				$input = wp_unslash( (array) ( $_POST['r'] ?? [] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				$out   = [];
				foreach ( self::fields() as $key => [ , , $type ] ) {
					$raw         = $input[ $key ] ?? Recurring::DEFAULTS[ $key ];
					$out[ $key ] = match ( $type ) {
						'bool'  => (int) ! empty( $raw ),
						'pct'   => max( -50, min( 50, round( (float) $raw, 1 ) ) ),
						default => max( 1, (int) $raw ),
					};
				}
				$out['horizon_visits'] = min( 12, $out['horizon_visits'] );
				$out['release_hours']  = min( $out['release_hours'], max( 1, $out['charge_hours'] - 1 ) );
				Settings::set( 'recurring', $out );
				Admin::done( Admin::url( self::PAGE ) . '#settings', 'Settings saved.' );
				break;

			case 'plan_pause':
				$msg( Plans::pause( $id, 'admin' ), 'Plan paused and the customer emailed.' );
				break;

			case 'plan_resume':
				$msg( Plans::resume( $id, 'admin' ), 'Plan resumed and the customer emailed.' );
				break;

			case 'plan_cancel':
				$msg( Plans::cancel( $id, 'admin', Admin::text( 'reason' ) ), 'Plan cancelled and the customer emailed.' );
				break;

			case 'plan_frequency':
				$msg( Plans::change_frequency( $id, sanitize_key( Admin::text( 'frequency' ) ), 'admin' ), 'Frequency changed and the customer emailed.' );
				break;

			case 'plan_generate':
				Admin::done( $back, Plans::generate( $id ) . ' visit(s) added.' );
				break;

			case 'plan_charge':
				$result = Plan_Charges::charge( $visit, 'admin' );
				Admin::done( $back, $result['message'], $result['ok'] ? 'success' : 'error' );
				break;

			case 'plan_skip':
				$msg( Plans::skip( $id, $visit, 'admin' ), 'Visit skipped and the customer emailed.' );
				break;

			case 'plan_mark_paid':
				$v = Bookings::get( $visit );
				$msg( $v ? Plan_Charges::paid( $visit, [ 'provider' => 'manual', 'amount' => (int) $v['total_pence'], 'payment_intent' => '', 'how' => 'paid by hand' ] ) : new \WP_Error( 'missing', 'Visit not found.' ), 'Visit marked paid and confirmed.' );
				break;

			case 'plan_release':
				Admin::done( $back, Plan_Charges::release( $visit, true ) );
				break;

			case 'plan_note':
				Db::update( Recurring::TABLE, $id, [ 'admin_note' => Admin::textarea( 'note' ) ] );
				Events::log( 'plan', $id, 'note', 'Internal note updated', [], 'admin' );
				Admin::done( $back, 'Note saved.' );
				break;
		}
	}
}

<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Aftercare;
use CC\Bookings\Bookings;
use CC\Bookings\Db;
use CC\Bookings\Files;
use CC\Bookings\Manage;
use CC\Bookings\Money;
use CC\Bookings\Reclean;
use CC\Bookings\Reminders;
use CC\Bookings\Settings;
use CC\Bookings\Slots;

defined( 'ABSPATH' ) || exit;

/**
 * Bookings → After booking: re-clean claims, refunds waiting to be paid by hand and the phase 3 settings.
 * Also adds an "After booking" box to each booking's sidebar (reminders, refund policy, move, complete, claims).
 */
final class Aftercare_Screen {
	const PAGE = 'cc-bookings-aftercare';

	const ACTIONS = [ 'aftercare_settings', 'aftercare_complete', 'aftercare_move', 'aftercare_refund_paid', 'reclean_approve', 'reclean_decline' ];

	public static function init(): void {
		add_filter( 'cc_bookings_admin_pages', [ self::class, 'pages' ] );
		add_action( 'cc_bookings_admin_booking_sidebar', [ self::class, 'sidebar' ] );
		foreach ( self::ACTIONS as $do ) {
			add_action( 'cc_bookings_admin_action_' . $do, fn() => self::handle( $do ) );
		}
	}

	/** Inserts "After booking" and "Reports" before Settings. */
	public static function pages( array $pages ): array {
		$out = [];
		foreach ( $pages as $slug => $page ) {
			if ( 'cc-bookings-settings' === $slug ) {
				$out[ self::PAGE ]              = [ 'After booking', [ self::class, 'render' ] ];
				$out[ Reports_Screen::PAGE ] = [ 'Reports', [ Reports_Screen::class, 'render' ] ];
			}
			$out[ $slug ] = $page;
		}
		$out += [ self::PAGE => [ 'After booking', [ self::class, 'render' ] ], Reports_Screen::PAGE => [ 'Reports', [ Reports_Screen::class, 'render' ] ] ];
		return $out;
	}

	private static function fields(): array {
		return [
			'allow_reschedule'     => [ 'Customers can move their booking online', '', 'bool' ],
			'reschedule_hours'     => [ 'Free changes up to (hours before)', 'After this, the booking page asks them to call.', 'int' ],
			'allow_cancel'         => [ 'Customers can cancel online', '', 'bool' ],
			'full_refund_hours'    => [ 'Full refund if cancelled more than (hours before)', '', 'int' ],
			'partial_refund_hours' => [ 'Partial refund down to (hours before)', 'Cancelling with less notice than this gets no refund.', 'int' ],
			'partial_refund_pct'   => [ 'Partial refund (%)', '', 'int' ],
			'reminder_hours'       => [ 'Reminders (hours before, comma separated)', 'Sent to the customer and every cleaner on the job, e.g. 48,24.', 'text' ],
			'reminder_sms'         => [ 'Also send reminders by text', 'Needs the SMS provider (phase 2).', 'bool' ],
			'review_url'           => [ 'Google review link', 'From Google Business Profile → "Ask for reviews". The review request is skipped until this is set.', 'url' ],
			'review_delay_hours'   => [ 'Send the review request after (hours)', 'Counted from when the job is marked complete.', 'float' ],
			'reclean_hours'        => [ 'Re-clean window (hours)', 'Blank = Business Settings value (currently ' . Reclean::window_hours() . ').', 'int_blank' ],
		];
	}

	public static function render(): void {
		Admin::header( 'After booking' );

		$open = Db::all( Reclean::TABLE, [ 'status' => 'open' ], 'id ASC' );
		echo '<h2>Re-clean claims waiting</h2>';
		self::claims_table( $open, 'No claims waiting.' );

		$pending = Manage::pending_refunds();
		echo '<h2>Refunds to pay by hand</h2>';
		if ( $pending ) {
			echo '<table class="widefat striped"><thead><tr><th>Booking</th><th>Customer</th><th>Amount</th><th>Since</th><th></th></tr></thead><tbody>';
			foreach ( $pending as $p ) {
				$b = Bookings::get( (int) $p['booking_id'] );
				printf(
					'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_url( Bookings::admin_url( (int) $p['booking_id'] ) ),
					esc_html( $b['ref'] ?? '?' ),
					esc_html( $b['customer_name'] ?? '' ),
					esc_html( Money::format( (int) $p['amount_pence'] ) ),
					esc_html( Admin::when( $p['created_at'] ) ),
					Admin::button( 'aftercare_refund_paid', [ 'payment' => $p['id'] ], 'Mark refund paid', 'button', 'Confirm you have refunded this amount by hand. The customer will be emailed.' ) // phpcs:ignore WordPress.Security.EscapeOutput
				);
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">None.</p>';
		}

		$recent = Db::all( Reclean::TABLE, [ 'status' => [ 'approved', 'declined' ] ], 'id DESC', 20 );
		if ( $recent ) {
			echo '<h2>Recently decided claims</h2>';
			self::claims_table( $recent, '' );
		}

		echo '<h2 id="settings">Settings</h2>';
		echo Admin::form( 'aftercare_settings' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table"><tbody>';
		foreach ( self::fields() as $key => [ $label, $help, $type ] ) {
			$value = Aftercare::setting( $key );
			$name  = 'a[' . $key . ']';
			if ( 'bool' === $type ) {
				$input = sprintf( '<input type="hidden" name="%1$s" value="0"><label><input type="checkbox" name="%1$s" value="1"%2$s> Yes</label>', esc_attr( $name ), checked( (bool) $value, true, false ) );
			} else {
				$input = sprintf( '<input type="%s" name="%s" value="%s" class="%s"%s>', in_array( $type, [ 'text', 'url' ], true ) ? ( 'url' === $type ? 'url' : 'text' ) : 'number', esc_attr( $name ), esc_attr( (string) $value ), in_array( $type, [ 'text', 'url' ], true ) ? 'regular-text' : 'small-text', 'float' === $type ? ' step="0.5" min="0"' : ' min="0"' );
			}
			printf( '<tr><th>%s</th><td>%s%s</td></tr>', esc_html( $label ), $input, $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		echo '<p class="description">The customer-facing policy shown on the booking page is built from these numbers. Keep the wording in <a href="' . esc_url( Admin::url( 'cc-bookings-settings', [ 'tab' => 'quotes' ] ) ) . '">Settings → Quotes → Cancellation policy</a> in line with them. Email wording for reminders, reschedules, re-cleans and reviews is under Settings → Emails.</p>';
		echo '<p><button class="button button-primary">Save settings</button></p></form>';
		Admin::footer();
	}

	private static function claims_table( array $claims, string $empty ): void {
		if ( ! $claims ) {
			echo '<p class="description">' . esc_html( $empty ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Received</th><th>Booking</th><th>Customer</th><th>What they said</th><th>Photos</th><th>Status</th></tr></thead><tbody>';
		foreach ( $claims as $c ) {
			$b = Bookings::get( (int) $c['booking_id'] );
			printf(
				'<tr><td>%s</td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
				esc_html( Admin::when( $c['created_at'] ) ),
				esc_url( Reclean::admin_url( $c ) ),
				esc_html( $b['ref'] ?? '?' ),
				esc_html( $b['customer_name'] ?? '' ),
				esc_html( wp_trim_words( (string) $c['message'], 20 ) ),
				count( (array) $c['photos'] ),
				esc_html( Reclean::STATUSES[ $c['status'] ] ?? $c['status'] )
			);
		}
		echo '</tbody></table>';
	}

	/** "After booking" box in the booking detail sidebar. */
	public static function sidebar( array $b ): void {
		$id   = (int) $b['id'];
		$open = in_array( $b['status'], Aftercare::OPEN, true );
		echo '<h2>After booking</h2>';

		if ( Aftercare::is_reclean( $b ) ) {
			$parent = Bookings::get( (int) $b['meta']['reclean_of'] );
			printf( '<p><span class="cc-admin__tag">Re-clean</span> Free return visit for <a href="%s">%s</a>. Customer price £0; pay the cleaner as you agree.</p>', esc_url( Bookings::admin_url( (int) $b['meta']['reclean_of'] ) ), esc_html( $parent['ref'] ?? '#' . $b['meta']['reclean_of'] ) );
		}

		if ( $open ) {
			$queued = Reminders::queued( $id );
			echo '<p><strong>Reminders:</strong> ';
			echo $queued ? esc_html( implode( ', ', array_map( fn( $h, $ts ) => sprintf( '%dh — %s', $h, wp_date( 'D j M H:i', $ts ) ), array_keys( $queued ), $queued ) ) ) : 'none queued';
			echo '</p>';
			if ( (int) $b['paid_pence'] > 0 ) {
				$policy = Manage::policy( $b );
				printf( '<p><strong>If the customer cancelled now:</strong> %s refund (%d%%)%s.</p>', esc_html( Money::format( $policy['amount'] ) ), (int) $policy['pct'], 'manual' === Manage::refund_mode( $b ) && $policy['amount'] ? ' — would need paying by hand' : '' );
			}

			echo '<h3>Move booking</h3>';
			echo Admin::form( 'aftercare_move', [ 'id' => $id ] ); // phpcs:ignore WordPress.Security.EscapeOutput
			$slots = '';
			foreach ( Slots::definitions() as $key => $def ) {
				$slots .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $b['slot'], $key, false ), esc_html( Slots::label( $key ) ) );
			}
			printf( '<p><input type="date" name="date" value="%s" min="%s" required> <select name="slot">%s</select></p>', esc_attr( (string) $b['job_date'] ), esc_attr( wp_date( 'Y-m-d' ) ), $slots ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p><label><input type="checkbox" name="force" value="1"> Ignore capacity and minimum notice</label></p>';
			echo '<p><button class="button">Move and notify</button> <span class="description">Emails/texts the customer and cleaners.</span></p></form>';

			if ( ! $b['job_date'] || $b['job_date'] > wp_date( 'Y-m-d' ) ) {
				echo Admin::button( 'aftercare_complete', [ 'id' => $id ], 'Mark complete', 'button', 'This job is in the future. Mark it complete anyway? The customer gets the thank-you email and review request.' ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}

		foreach ( Manage::pending_refunds( $id ) as $p ) {
			printf( '<div class="notice notice-warning inline"><p><strong>%s refund to pay by hand.</strong> %s</p></div>', esc_html( Money::format( (int) $p['amount_pence'] ) ), Admin::button( 'aftercare_refund_paid', [ 'payment' => $p['id'] ], 'Mark refund paid', 'button button-small', 'Confirm you have refunded this amount by hand. The customer will be emailed.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		if ( 'completed' === $b['status'] && ! Aftercare::is_reclean( $b ) ) {
			$end = Reclean::window_end( $b );
			printf( '<p><strong>Re-clean window:</strong> %s %s.</p>', time() <= $end ? 'open until' : 'closed', esc_html( wp_date( 'D j M, H:i', $end ) ) );
		}

		$claims = Reclean::for_booking( $id );
		if ( ! $claims ) {
			return;
		}
		echo '<h3 id="cc-reclean">Re-clean claims</h3>';
		foreach ( $claims as $c ) {
			printf( '<div class="cc-admin__template"><p><strong>%s</strong> — %s</p><p>%s</p>', esc_html( Reclean::STATUSES[ $c['status'] ] ?? $c['status'] ), esc_html( Admin::when( $c['created_at'] ) ), nl2br( esc_html( (string) $c['message'] ) ) );
			if ( $c['photos'] ) {
				echo '<p class="cc-admin__photos">';
				foreach ( (array) $c['photos'] as $name ) {
					printf( '<a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="" loading="lazy"></a>', esc_url( Files::admin_url( (string) $c['photo_ref'], (string) $name ) ) );
				}
				echo '</p>';
			}
			if ( 'approved' === $c['status'] && ( $visit = Bookings::get( (int) $c['reclean_booking_id'] ) ) ) {
				printf( '<p>Re-clean booking: <a href="%s">%s</a> — %s</p>', esc_url( Bookings::admin_url( $visit['id'] ) ), esc_html( $visit['ref'] ), esc_html( Bookings::start_label( $visit ) ) );
			} elseif ( 'declined' === $c['status'] ) {
				printf( '<p class="description">Reason given: %s</p>', esc_html( (string) $c['admin_note'] ) );
			} elseif ( 'open' === $c['status'] ) {
				$free = [];
				Manage::slot_context( $b );
				foreach ( Slots::availability( (float) $b['hours'], (int) $b['cleaners'] ) as $date => $slots ) {
					foreach ( array_keys( array_filter( $slots ) ) as $slot ) {
						$free[] = Slots::date_label( $date, 'D j M' ) . ' ' . Slots::label( $slot, false );
					}
					if ( count( $free ) >= 6 ) {
						break;
					}
				}
				echo Admin::form( 'reclean_approve', [ 'claim' => $c['id'], 'id' => $id ] ); // phpcs:ignore WordPress.Security.EscapeOutput
				$slots = '';
				foreach ( Slots::definitions() as $key => $def ) {
					$slots .= sprintf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( Slots::label( $key ) ) );
				}
				printf( '<p><label>Return visit <input type="date" name="date" min="%s" required></label> <select name="slot">%s</select></p>', esc_attr( wp_date( 'Y-m-d' ) ), $slots ); // phpcs:ignore WordPress.Security.EscapeOutput
				printf( '<p><label>Hours <input type="number" name="hours" step="0.5" min="0.5" value="%s" class="small-text"></label> <label><input type="checkbox" name="force" value="1"> Book anyway (ignore capacity/notice)</label></p>', esc_attr( (string) $b['hours'] ) );
				if ( $free ) {
					echo '<p class="description">Next free: ' . esc_html( implode( ', ', $free ) ) . '</p>';
				}
				echo '<p><input type="text" name="note" class="regular-text" placeholder="Note for the customer (optional)"></p>';
				echo '<p><button class="button button-primary">Approve and book free re-clean</button></p></form>';
				echo Admin::form( 'reclean_decline', [ 'claim' => $c['id'], 'id' => $id ], 'onsubmit="return confirm(\'Decline this claim? The customer will be emailed the reason.\')"' ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<p><input type="text" name="reason" class="regular-text" placeholder="Reason (sent to the customer)" required> <button class="button button-link-delete">Decline</button></p></form>';
			}
			echo '</div>';
		}
	}

	public static function handle( string $do ): void {
		$id   = Admin::int( 'id' );
		$back = $id ? Bookings::admin_url( $id ) : Admin::url( self::PAGE );

		switch ( $do ) {
			case 'aftercare_settings':
				$input = wp_unslash( (array) ( $_POST['a'] ?? [] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				$out   = [];
				foreach ( self::fields() as $key => [ , , $type ] ) {
					$raw         = $input[ $key ] ?? Aftercare::DEFAULTS[ $key ];
					$out[ $key ] = match ( $type ) {
						'bool'      => (int) ! empty( $raw ),
						'int'       => max( 0, (int) $raw ),
						'float'     => max( 0, (float) $raw ),
						'int_blank' => '' === trim( (string) $raw ) ? '' : max( 1, (int) $raw ),
						'url'       => esc_url_raw( trim( (string) $raw ) ),
						default     => implode( ',', array_filter( array_map( 'intval', preg_split( '/[\s,]+/', (string) $raw ) ) ) ),
					};
				}
				$out['partial_refund_pct'] = min( 100, $out['partial_refund_pct'] );
				Settings::set( 'aftercare', $out );
				Admin::done( Admin::url( self::PAGE ) . '#settings', 'Settings saved.' );
				break;

			case 'aftercare_complete':
				Bookings::complete( $id ) ? Admin::done( $back, 'Marked complete. Thank-you email sent and review request scheduled.' ) : Admin::done( $back, 'This booking can\'t be completed from its current status.', 'error' );
				break;

			case 'aftercare_move':
				$result = Manage::reschedule( $id, Admin::text( 'date' ), sanitize_key( Admin::text( 'slot' ) ), 'admin', ! empty( $_POST['force'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				is_wp_error( $result ) ? Admin::done( $back, $result->get_error_message(), 'error' ) : Admin::done( $back, 'Booking moved; the customer and cleaners have been told.' );
				break;

			case 'aftercare_refund_paid':
				$payment = Db::get( 'payments', Admin::int( 'payment' ) );
				$back    = $payment ? Bookings::admin_url( (int) $payment['booking_id'] ) : $back;
				$result  = Manage::mark_refund_paid( Admin::int( 'payment' ) );
				is_wp_error( $result ) ? Admin::done( $back, $result->get_error_message(), 'error' ) : Admin::done( $back, 'Refund marked as paid and the customer emailed.' );
				break;

			case 'reclean_approve':
				$result = Reclean::approve( Admin::int( 'claim' ), Admin::text( 'date' ), sanitize_key( Admin::text( 'slot' ) ), (float) Admin::text( 'hours' ), Admin::text( 'note' ), ! empty( $_POST['force'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				is_wp_error( $result ) ? Admin::done( $back . '#cc-reclean', $result->get_error_message(), 'error' ) : Admin::done( Bookings::admin_url( (int) $result ), 'Re-clean booked and the customer emailed. It has gone to dispatch like any new booking.' );
				break;

			case 'reclean_decline':
				$result = Reclean::decline( Admin::int( 'claim' ), Admin::text( 'reason' ) );
				is_wp_error( $result ) ? Admin::done( $back . '#cc-reclean', $result->get_error_message(), 'error' ) : Admin::done( $back, 'Claim declined and the customer emailed.' );
				break;
		}
	}
}

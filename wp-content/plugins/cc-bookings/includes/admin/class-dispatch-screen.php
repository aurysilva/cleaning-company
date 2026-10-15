<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Availability;
use CC\Bookings\Bookings;
use CC\Bookings\Cleaners;
use CC\Bookings\Db;
use CC\Bookings\Dispatch;
use CC\Bookings\Dispatch_Notify;
use CC\Bookings\Events;
use CC\Bookings\Files;
use CC\Bookings\Job_Links;
use CC\Bookings\Money;
use CC\Bookings\Schema;
use CC\Bookings\Settings;
use CC\Bookings\Sms;
use CC\Bookings\Slots;
use CC\Bookings\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Bookings > Dispatch (jobs waiting for a cleaner, dispatch settings, texts) and the dispatch panel on each
 * booking (offers, re-offer with a bonus, assign from the free cleaners, completion photos).
 * Actions are registered on `cc_bookings_admin_action_dispatch_*`.
 */
final class Dispatch_Screen {
	const PAGE = 'cc-bookings-dispatch';

	const ACTIONS = [ 'dispatch_settings', 'dispatch_run', 'dispatch_reoffer', 'dispatch_assign', 'dispatch_link', 'dispatch_cleaner_link', 'dispatch_cleaner_show', 'dispatch_cleaner_revoke' ];

	public static function init(): void {
		add_filter( 'cc_bookings_admin_pages', [ self::class, 'pages' ], 20 );
		add_action( 'cc_bookings_admin_booking_sidebar', [ self::class, 'booking_panel' ] );
		foreach ( self::ACTIONS as $do ) {
			add_action( 'cc_bookings_admin_action_' . $do, fn() => str_starts_with( $do, 'dispatch_cleaner_' ) ? Cleaner_Profiles_Screen::handle( $do ) : self::handle( $do ) );
		}
	}

	public static function pages( array $pages ): array {
		$out = [];
		foreach ( $pages as $slug => $page ) {
			if ( Cleaner_Profiles_Screen::PAGE === $slug ) {
				$page = [ $page[0], [ Cleaner_Profiles_Screen::class, 'render' ] ];
			}
			$out[ $slug ] = $page;
			if ( Cleaner_Profiles_Screen::PAGE === $slug ) {
				$out[ self::PAGE ] = [ 'Dispatch', [ self::class, 'render' ] ];
			}
		}
		$out[ self::PAGE ] = $out[ self::PAGE ] ?? [ 'Dispatch', [ self::class, 'render' ] ];
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Dispatch page
	 * ------------------------------------------------------------------ */

	public static function render(): void {
		global $wpdb;
		Admin::header( 'Dispatch' );

		$rows = array_map( [ Db::class, 'decode' ], $wpdb->get_results( $wpdb->prepare(
			'SELECT b.*, d.status AS d_status, d.deadline AS d_deadline, d.round AS d_round, d.filled AS d_filled FROM ' . Schema::table( 'bookings' ) . ' b LEFT JOIN ' . Schema::table( 'dispatch' ) . ' d ON d.booking_id = b.id'
			. " WHERE b.status IN ('confirmed','dispatching') AND (b.job_date >= %s OR b.job_date IS NULL) ORDER BY b.job_date ASC, b.slot ASC LIMIT 100",
			wp_date( 'Y-m-d' )
		), ARRAY_A ) ?: [] );

		echo '<h2>Jobs waiting for a cleaner</h2>';
		echo '<table class="widefat striped cc-admin__table"><thead><tr><th>When</th><th>Booking</th><th>Area</th><th>Places</th><th>Dispatch</th><th>Offers</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Every upcoming booking has its cleaners.</td></tr>';
		}
		foreach ( $rows as $b ) {
			$offers = Db::all( 'job_offers', [ 'booking_id' => (int) $b['id'] ] );
			$counts = array_count_values( array_column( $offers, 'status' ) );
			printf(
				'<tr><td>%s</td><td><a href="%s"><strong>%s</strong></a>%s<br>%s</td><td>%s</td><td>%d of %d</td><td>%s%s</td><td>%s</td></tr>',
				esc_html( Bookings::start_label( $b ) ),
				esc_url( Bookings::admin_url( (int) $b['id'] ) ),
				esc_html( $b['ref'] ),
				$b['is_test'] ? ' <span class="cc-admin__test">Test</span>' : '',
				esc_html( $b['service_name'] ),
				esc_html( $b['district'] ),
				count( Bookings::crew( (int) $b['id'] ) ),
				(int) $b['cleaners'],
				esc_html( $b['d_status'] ? ucfirst( $b['d_status'] ) . ' (round ' . (int) $b['d_round'] . ')' : 'Not run' ),
				$b['d_deadline'] && 'offering' === $b['d_status'] ? '<br><span class="description">until ' . esc_html( Admin::when( $b['d_deadline'], 'D j M H:i' ) ) . '</span>' : '',
				esc_html( $counts ? implode( ', ', array_map( fn( $s, $n ) => $n . ' ' . strtolower( Status::label( 'offer', $s ) ), array_keys( $counts ), $counts ) ) : '—' )
			);
		}
		echo '</tbody></table>';

		self::settings_form();

		echo '<h2>Recent texts</h2>';
		$texts = Db::all( 'events', [ 'event' => [ 'sms_sent', 'sms_skipped', 'sms_logged', 'sms_failed' ] ], 'id DESC', 25 );
		echo '<table class="widefat striped"><thead><tr><th>When</th><th>To</th><th>Status</th><th>Message</th></tr></thead><tbody>';
		if ( ! $texts ) {
			echo '<tr><td colspan="4">None yet.</td></tr>';
		}
		foreach ( $texts as $e ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( Admin::when( $e['created_at'] ) ), esc_html( (string) ( $e['data']['to'] ?? '' ) ), esc_html( str_replace( 'sms_', '', $e['event'] ) ), esc_html( $e['message'] ) );
		}
		echo '</tbody></table>';
		Admin::footer();
	}

	private static function settings_form(): void {
		$v      = fn( $k ) => Dispatch::opt( $k );
		$fields = [
			'auto'                => [ 'Dispatch automatically', 'When a booking is paid, assign or offer it straight away.', 'bool' ],
			'offer_timeout_hours' => [ 'Offer timeout (hours)', 'If places are still open after this, you get an urgent email and text. Offers stay open until someone accepts.', 'float' ],
			'urgent_within_hours' => [ 'Short-notice jobs: within (hours)', 'Jobs starting sooner than this use the short timeout below.', 'int' ],
			'urgent_timeout_mins' => [ 'Short-notice timeout (minutes)', '', 'int' ],
			'buffer_minutes'      => [ 'Travel time between jobs (minutes)', '', 'int' ],
			'complete_from_mins'  => [ 'Cleaners can mark complete from (minutes before start)', '', 'int' ],
			'notify_taken'        => [ 'Email "job taken" to other cleaners', '', 'bool' ],
			'notify_customer'     => [ 'Email customers "your cleaner is…"', '', 'bool' ],
			'notify_cancelled'    => [ 'Tell cleaners when a booking is cancelled', '', 'bool' ],
			'admin_mobile'        => [ 'Urgent alerts by text to', 'Mobile number for "nobody accepted" and "cleaner dropped out" alerts.', 'text' ],
			'sms_log_only'        => [ 'Log texts only (don\'t send)', 'For testing: texts are written to the log below instead of sent.', 'bool' ],
		];
		echo '<h2>Settings</h2>';
		echo Admin::form( 'dispatch_settings' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table"><tbody>';
		$sources = [ 'cleaners' => 'Cleaner calendars (uses capacity only when there are no active cleaners)', 'both' => 'Cleaner calendars and the capacity limits', 'capacity' => 'Capacity limits only (Settings → Dates & capacity)' ];
		$opts    = '';
		foreach ( $sources as $key => $label ) {
			$opts .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $v( 'slot_source' ), $key, false ), esc_html( $label ) );
		}
		printf( '<tr><th><label for="cc-d-slot_source">Bookable slots from</label></th><td><select id="cc-d-slot_source" name="d[slot_source]">%s</select></td></tr>', $opts ); // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $fields as $key => [ $label, $help, $type ] ) {
			$id = 'cc-d-' . $key;
			if ( 'bool' === $type ) {
				$input = sprintf( '<input type="hidden" name="d[%1$s]" value="0"><label><input id="%2$s" type="checkbox" name="d[%1$s]" value="1"%3$s> Yes</label>', esc_attr( $key ), esc_attr( $id ), checked( (bool) $v( $key ), true, false ) );
			} else {
				$input = sprintf( '<input id="%s" type="%s" name="d[%s]" value="%s" class="%s"%s>', esc_attr( $id ), 'text' === $type ? 'text' : 'number', esc_attr( $key ), esc_attr( (string) $v( $key ) ), 'text' === $type ? 'regular-text' : 'small-text', 'float' === $type ? ' step="0.25" min="0"' : ' min="0"' );
			}
			printf( '<tr><th><label for="%s">%s</label></th><td>%s%s</td></tr>', esc_attr( $id ), esc_html( $label ), $input, $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$provider = Sms::provider();
		printf( '<tr><th>Text messages</th><td>%s</td></tr>', $provider ? '<span class="cc-admin__ok">' . esc_html( ucfirst( $provider ) . ' configured' ) . '</span>' : '<span class="cc-admin__warn">No provider — texts are logged, not sent.</span> <span class="description">Add <code>CC_TWILIO_SID</code>, <code>CC_TWILIO_TOKEN</code> and <code>CC_TWILIO_FROM</code> (or <code>CC_TWILIO_MESSAGING_SID</code>) to wp-config.php.</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</tbody></table>';

		echo '<h3>Text wording</h3><p class="description">Leave empty for the default. Placeholders: {ref}, {service}, {district}, {date}, {slot}, {length}, {pay}, {url}, {reason}, {phone}, {brand}, {subject}.</p><table class="form-table"><tbody>';
		$custom = (array) $v( 'sms' );
		foreach ( Dispatch_Notify::SMS as $key => $default ) {
			printf( '<tr><th><label for="cc-d-sms-%1$s">%2$s</label></th><td><textarea id="cc-d-sms-%1$s" name="d[sms][%1$s]" rows="2" class="large-text" placeholder="%3$s">%4$s</textarea></td></tr>', esc_attr( $key ), esc_html( ucfirst( $key ) ), esc_attr( $default ), esc_textarea( (string) ( $custom[ $key ] ?? '' ) ) );
		}
		echo '</tbody></table><p><button class="button button-primary">Save dispatch settings</button></p></form>';
	}

	/* ---------------------------------------------------------------------
	 * Booking panel
	 * ------------------------------------------------------------------ */

	public static function booking_panel( array $b ): void {
		$id     = (int) $b['id'];
		$open   = in_array( $b['status'], [ 'confirmed', 'dispatching', 'assigned' ], true );
		$state  = Dispatch::state( $id );
		$offers = Db::all( 'job_offers', [ 'booking_id' => $id ], 'id ASC' );

		echo '<h2>Dispatch</h2>';
		if ( $state ) {
			printf(
				'<p>%s · round %d · %d of %d place%s filled%s%s</p>',
				esc_html( ucfirst( $state['status'] ) ),
				(int) $state['round'],
				(int) $state['filled'],
				(int) $state['seats'],
				1 === (int) $state['seats'] ? '' : 's',
				'offering' === $state['status'] && $state['deadline'] ? esc_html( ' · offers until ' . Admin::when( $state['deadline'], 'D j M H:i' ) ) : '',
				(int) $state['bonus_pence'] ? esc_html( ' · bonus ' . Money::format( (int) $state['bonus_pence'] ) ) : ''
			);
		} else {
			echo '<p class="description">Not dispatched yet.</p>';
		}

		if ( $offers ) {
			echo '<table class="widefat striped"><thead><tr><th>Cleaner</th><th>Status</th><th>Pay</th><th></th></tr></thead><tbody>';
			foreach ( $offers as $o ) {
				$c = Cleaners::get( (int) $o['cleaner_id'] );
				printf(
					'<tr><td>%s<br><span class="description">%s · %s</span></td><td>%s%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $c['name'] ?? '?' ),
					esc_html( Cleaners::TYPES[ $c['type'] ?? '' ] ?? '' ),
					esc_html( (string) $o['source'] ),
					Status::badge( 'offer', (string) $o['status'] ), // phpcs:ignore WordPress.Security.EscapeOutput
					$o['note'] ? '<br><span class="description">' . esc_html( $o['note'] ) . '</span>' : '',
					esc_html( (int) $o['pay_pence'] ? Money::format( (int) $o['pay_pence'] ) : '—' ),
					Admin::button( 'dispatch_link', [ 'id' => $id, 'offer' => $o['id'] ], 'Link', 'button-link' ) // phpcs:ignore WordPress.Security.EscapeOutput
				);
			}
			echo '</tbody></table>';
		}

		$photos = Dispatch::photos( $id );
		if ( $photos ) {
			echo '<h3>Completion photos</h3><p class="cc-admin__photos">';
			foreach ( $photos as $p ) {
				printf( '<a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="" loading="lazy"></a>', esc_url( Files::admin_url( Dispatch::photo_folder( $b ), $p['file'] ) ) );
			}
			echo '</p>';
		}

		if ( ! $open ) {
			return;
		}
		echo '<div class="cc-admin__actions">' . Admin::button( 'dispatch_run', [ 'id' => $id ], 'Run dispatch again' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<h3>Re-offer</h3>';
		echo Admin::form( 'dispatch_reoffer', [ 'id' => $id ] ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<p><label>Bonus (£) <input type="text" name="bonus" value="%s" class="small-text" inputmode="decimal"></label> <button class="button">Re-offer to free cleaners</button></p><p class="description">Sends the job again to every free self-employed cleaner, including anyone who declined, with the bonus added to their pay.</p></form>', esc_attr( (int) ( $state['bonus_pence'] ?? 0 ) ? number_format( (int) $state['bonus_pence'] / 100, 2, '.', '' ) : '' ) );

		$on_job = array_map( 'intval', array_column( Bookings::crew( $id ), 'cleaner_id' ) );
		$free   = $b['job_date'] ? array_map( 'intval', array_column( Availability::eligible( Availability::job( $b ), $on_job ), 'id' ) ) : [];
		$opts   = [ 'free' => '', 'busy' => '' ];
		foreach ( Cleaners::active() as $c ) {
			if ( in_array( (int) $c['id'], $on_job, true ) ) {
				continue;
			}
			$is_free                         = in_array( (int) $c['id'], $free, true );
			$opts[ $is_free ? 'free' : 'busy' ] .= sprintf( '<option value="%d">%s (%s)</option>', $c['id'], esc_html( $c['name'] ), esc_html( strtolower( Cleaners::TYPES[ $c['type'] ] ?? $c['type'] ) ) );
		}
		if ( $opts['free'] || $opts['busy'] ) {
			echo '<h3>Assign</h3>';
			echo Admin::form( 'dispatch_assign', [ 'id' => $id ] ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p><select name="cleaner"><option value="">Choose a cleaner…</option>'
				. ( $opts['free'] ? '<optgroup label="Free for this job">' . $opts['free'] . '</optgroup>' : '' )
				. ( $opts['busy'] ? '<optgroup label="Not free (area, service, hours or another job)">' . $opts['busy'] . '</optgroup>' : '' )
				. '</select> <button class="button button-primary">Assign</button></p></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	public static function handle( string $do ): void {
		if ( 'dispatch_settings' === $do ) {
			self::save_settings();
		}
		$id   = Admin::int( 'id' );
		$back = Bookings::admin_url( $id );
		if ( ! Bookings::get( $id ) ) {
			Admin::done( Admin::url( self::PAGE ), 'Booking not found.', 'error' );
		}
		switch ( $do ) {
			case 'dispatch_run':
				$result = Dispatch::run( $id, 'admin' );
				Admin::done( $back, esc_html( $result['message'] ), $result['ok'] ? 'success' : 'warning' );
				break;
			case 'dispatch_reoffer':
				$bonus  = (int) Money::parse( Admin::text( 'bonus' ) );
				$result = Dispatch::run( $id, 'reoffer', [ 'bonus' => max( 0, $bonus ), 'include_declined' => true ] );
				Admin::done( $back, esc_html( $result['message'] ), $result['ok'] ? 'success' : 'warning' );
				break;
			case 'dispatch_assign':
				$result = Bookings::assign( $id, Admin::int( 'cleaner' ), 'manual' );
				is_wp_error( $result ) ? Admin::done( $back, esc_html( $result->get_error_message() ), 'error' ) : Admin::done( $back, 'Cleaner assigned, emailed and texted.' );
				break;
			case 'dispatch_link':
				$offer = Db::get( 'job_offers', Admin::int( 'offer' ) );
				if ( ! $offer || (int) $offer['booking_id'] !== $id ) {
					Admin::done( $back, 'Offer not found.', 'error' );
				}
				Admin::done( $back, 'Cleaner\'s job link (private):<br><code>' . esc_html( Job_Links::offer_url( (int) $offer['id'] ) ) . '</code>', 'info' );
				break;
		}
		Admin::done( $back, 'Unknown action.', 'error' );
	}

	private static function save_settings(): void {
		$in     = wp_unslash( (array) ( $_POST['d'] ?? [] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$stored = [];
		foreach ( Dispatch::DEFAULTS as $key => $default ) {
			$raw = $in[ $key ] ?? null;
			if ( 'sms' === $key ) {
				$stored['sms'] = array_filter( array_map( fn( $t ) => sanitize_textarea_field( (string) $t ), array_intersect_key( (array) $raw, Dispatch_Notify::SMS ) ) );
			} elseif ( 'slot_source' === $key ) {
				$stored[ $key ] = in_array( $raw, [ 'cleaners', 'both', 'capacity' ], true ) ? $raw : 'cleaners';
			} elseif ( 'admin_mobile' === $key ) {
				$stored[ $key ] = preg_replace( '/[^0-9+ ]/', '', (string) $raw );
			} elseif ( is_int( $default ) && in_array( $key, [ 'auto', 'notify_taken', 'notify_customer', 'notify_cancelled', 'sms_log_only' ], true ) ) {
				$stored[ $key ] = (int) ! empty( $raw );
			} elseif ( 'offer_timeout_hours' === $key ) {
				$stored[ $key ] = max( 0.25, (float) $raw );
			} else {
				$stored[ $key ] = max( 0, (int) $raw );
			}
		}
		Settings::set( 'dispatch', $stored );
		Events::log( 'system', 0, 'settings', 'Dispatch settings saved' );
		Admin::done( Admin::url( self::PAGE ), 'Dispatch settings saved.' );
	}
}

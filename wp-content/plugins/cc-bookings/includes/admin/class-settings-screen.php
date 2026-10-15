<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Catalog;
use CC\Bookings\Notify;
use CC\Bookings\Queue;
use CC\Bookings\Security;
use CC\Bookings\Settings;
use CC\Bookings\Stripe;

defined( 'ABSPATH' ) || exit;

final class Settings_Screen {
	const PAGE = 'cc-bookings-settings';

	const TABS = [
		'pricing' => 'Pricing',
		'booking' => 'Dates & capacity',
		'quotes'  => 'Quotes',
		'emails'  => 'Emails',
		'setup'   => 'Payments & setup',
	];

	/** Scalar settings: path => [ label, help, type ]. */
	private static function fields(): array {
		return [
			'pricing' => [
				'pricing.condition_good'     => [ 'Condition: well kept (×)', 'Multiplier on the base price.', 'float' ],
				'pricing.condition_average'  => [ 'Condition: average (×)', '', 'float' ],
				'pricing.condition_poor'     => [ 'Condition: needs extra work (×)', 'Very poor condition always goes to a bespoke quote.', 'float' ],
				'pricing.furnished_pct'      => [ 'Furnished property (+%)', 'End of tenancy and move-in cleans.', 'float' ],
				'pricing.weekend_pct'        => [ 'Weekend (+%)', '', 'float' ],
				'pricing.short_notice_hours' => [ 'Short notice within (hours)', '', 'int' ],
				'pricing.short_notice_pct'   => [ 'Short notice (+%)', '', 'float' ],
				'pricing.parking_paid'       => [ 'Paid parking (£)', '', 'float' ],
				'pricing.parking_permit'     => [ 'Visitor permit needed (£)', '', 'float' ],
				'pricing.labour_rate'        => [ 'Labour rate for estimating time (£/cleaner-hour)', 'Used to estimate duration when a price row has no hours.', 'float' ],
				'pricing.team_after_hours'   => [ 'Add a cleaner for every (hours)', 'A 10 cleaner-hour job with 5 here gets a team of 2.', 'float' ],
				'pricing.max_team'           => [ 'Largest team', '', 'int' ],
				'pricing.default_minimum'    => [ 'Default minimum charge (£)', 'Per-service minimums are below.', 'float' ],
			],
			'booking' => [
				'booking.min_notice_hours' => [ 'Minimum notice (hours)', '', 'int' ],
				'booking.max_days_ahead'   => [ 'Book up to (days ahead)', '', 'int' ],
				'booking.capacity_jobs'    => [ 'Jobs per arrival slot', 'How many jobs can start in the same slot. 0 = no limit.', 'int' ],
				'booking.capacity_hours'   => [ 'Cleaner-hours per day', 'Total team hours you can staff per day. 0 = no limit.', 'float' ],
				'booking.hold_minutes'     => [ 'Hold a slot during payment (minutes)', 'Minimum 30 (Stripe Checkout limit).', 'int' ],
				'booking.blocked_dates'    => [ 'Closed dates', 'One per line, YYYY-MM-DD (e.g. bank holidays).', 'textarea' ],
			],
			'quotes'  => [
				'quotes.expiry_days'         => [ 'Quotes valid for (days)', '', 'int' ],
				'quotes.reminder_hours'      => [ 'Reminder after (hours)', '0 = no reminder.', 'int' ],
				'quotes.expiring_day'        => [ '"Expires tomorrow" email on day', '0 = don\'t send.', 'int' ],
				'quotes.early_start_days'    => [ 'Ask for early-start consent if the clean is within (days)', 'Consumer Contracts Regulations 14-day cancellation period.', 'int' ],
				'quotes.terms_url'           => [ 'Terms page', 'Path or full URL.', 'text' ],
				'quotes.cancellation_policy' => [ 'Cancellation policy', 'Shown on every quote. One point per line.', 'textarea' ],
				'quotes.regular_note'        => [ 'Note on regular-clean quotes', '', 'textarea' ],
			],
			'emails'  => [
				'emails.from_name'    => [ 'From name', 'Blank = business name.', 'text' ],
				'emails.from_address' => [ 'From address', 'Use an address on your domain once SMTP is set up. Blank = WordPress default.', 'text' ],
				'emails.admin_to'     => [ 'Send admin emails to', 'Blank = the WordPress admin email.', 'text' ],
				'emails.log_only'     => [ 'Log only (don\'t send)', 'For testing: emails are written to Activity instead of sent.', 'bool' ],
			],
		];
	}

	public static function render(): void {
		$tab = Admin::text( 'tab' );
		$tab = isset( self::TABS[ $tab ] ) ? $tab : 'pricing';

		Admin::header( 'Booking settings' );
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( Admin::url( self::PAGE, [ 'tab' => $key ] ) ), $tab === $key ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';

		if ( 'setup' === $tab ) {
			self::setup();
			Admin::footer();
			return;
		}

		echo Admin::form( 'settings', [ 'tab' => $tab ] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table"><tbody>';
		foreach ( self::fields()[ $tab ] as $path => [ $label, $help, $type ] ) {
			self::field( $path, $label, $help, $type );
		}
		echo '</tbody></table>';

		if ( 'pricing' === $tab ) {
			self::services();
		} elseif ( 'booking' === $tab ) {
			self::booking_extra();
		} elseif ( 'emails' === $tab ) {
			self::templates();
		}
		echo '<p><button class="button button-primary">Save settings</button></p></form>';
		Admin::footer();
	}

	private static function name( string $path ): string {
		$parts = explode( '.', $path );
		return 's[' . implode( '][', $parts ) . ']';
	}

	private static function field( string $path, string $label, string $help, string $type ): void {
		$value = Settings::get( $path );
		$id    = 'cc-s-' . str_replace( '.', '-', $path );
		$name  = self::name( $path );
		if ( 'textarea' === $type ) {
			$input = sprintf( '<textarea id="%s" name="%s" rows="3" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
		} elseif ( 'bool' === $type ) {
			$input = sprintf( '<input type="hidden" name="%2$s" value="0"><label><input id="%1$s" type="checkbox" name="%2$s" value="1"%3$s> Yes</label>', esc_attr( $id ), esc_attr( $name ), checked( (bool) $value, true, false ) );
		} else {
			$input = sprintf( '<input id="%s" type="%s" name="%s" value="%s" class="%s"%s>', esc_attr( $id ), 'text' === $type ? 'text' : 'number', esc_attr( $name ), esc_attr( (string) $value ), 'text' === $type ? 'regular-text' : 'small-text', 'float' === $type ? ' step="0.01"' : '' );
		}
		printf( '<tr><th><label for="%s">%s</label></th><td>%s%s</td></tr>', esc_attr( $id ), esc_html( $label ), $input, $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function services(): void {
		echo '<h2>Services</h2><p class="description">Instant: priced from the service\'s price table (edit prices on each service). Bespoke: every request comes to you to price.</p>';
		echo '<table class="widefat striped cc-admin__services"><thead><tr><th>Service</th><th>Pricing</th><th>Minimum charge (£)</th></tr></thead><tbody>';
		foreach ( Catalog::services() as $slug => $service ) {
			if ( Catalog::OTHER === $slug ) {
				continue;
			}
			$row  = Settings::service( $slug );
			$raw  = (array) Settings::get( 'services.' . $slug, [] );
			$mode = 'bespoke' === $service['profile']
				? sprintf( '<input type="hidden" name="s[services][%s][mode]" value="bespoke">Bespoke quote <span class="description">(always)</span>', esc_attr( $slug ) )
				: sprintf( '<select name="s[services][%s][mode]"><option value="auto"%s>Instant price</option><option value="bespoke"%s>Bespoke quote</option></select>', esc_attr( $slug ), selected( $row['mode'], 'auto', false ), selected( $row['mode'], 'bespoke', false ) );
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td><input type="number" step="0.01" min="0" name="s[services][%s][minimum]" value="%s" class="small-text" placeholder="%s"></td></tr>',
				esc_url( (string) get_edit_post_link( $service['id'] ) ),
				esc_html( $service['name'] ),
				$mode, // phpcs:ignore WordPress.Security.EscapeOutput
				esc_attr( $slug ),
				esc_attr( isset( $raw['minimum'] ) ? (string) $raw['minimum'] : '' ),
				esc_attr( (string) Settings::get( 'pricing.default_minimum' ) )
			);
		}
		echo '</tbody></table>';
	}

	private static function booking_extra(): void {
		$days    = [ 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun' ];
		$working = array_map( 'intval', (array) Settings::get( 'booking.working_days', [] ) );
		echo '<h2>Working days</h2><p>';
		foreach ( $days as $n => $label ) {
			printf( '<label class="cc-admin__check"><input type="checkbox" name="s[booking][working_days][]" value="%d"%s> %s</label>', $n, checked( in_array( $n, $working, true ), true, false ), esc_html( $label ) );
		}
		echo '</p>';

		echo '<h2>Arrival slots</h2><table class="widefat striped"><thead><tr><th>Key</th><th>Name</th><th>Arrive from</th><th>Arrive by</th></tr></thead><tbody>';
		foreach ( (array) Settings::get( 'booking.slots', [] ) as $key => $slot ) {
			printf(
				'<tr><td><code>%1$s</code></td><td><input type="text" name="s[booking][slots][%1$s][label]" value="%2$s"></td><td><input type="time" name="s[booking][slots][%1$s][start]" value="%3$s"></td><td><input type="time" name="s[booking][slots][%1$s][end]" value="%4$s"></td></tr>',
				esc_attr( $key ),
				esc_attr( $slot['label'] ),
				esc_attr( $slot['start'] ),
				esc_attr( $slot['end'] )
			);
		}
		echo '</tbody></table>';
	}

	private static function templates(): void {
		echo '<h2>Email wording</h2><p class="description">Leave a box empty to use the default. Placeholders such as {first_name}, {service}, {date}, {total}, {ref}, {items}, {details}, {phone} and {brand} are filled in automatically.</p>';
		foreach ( Notify::defaults() as $key => $default ) {
			$custom = (array) Settings::get( 'emails.templates.' . $key, [] );
			printf( '<details class="cc-admin__template"><summary><strong>%s</strong> <span class="description">%s</span></summary>', esc_html( $default['label'] ), ! empty( array_filter( $custom ) ) ? '(customised)' : '' );
			printf( '<p><label>Subject<br><input type="text" class="large-text" name="s[emails][templates][%s][subject]" value="%s" placeholder="%s"></label></p>', esc_attr( $key ), esc_attr( (string) ( $custom['subject'] ?? '' ) ), esc_attr( $default['subject'] ) );
			printf( '<p><label>Message<br><textarea class="large-text" rows="6" name="s[emails][templates][%s][body]" placeholder="%s">%s</textarea></label></p>', esc_attr( $key ), esc_attr( $default['body'] ), esc_textarea( (string) ( $custom['body'] ?? '' ) ) );
			if ( ! empty( $default['button'] ) ) {
				printf( '<p><label>Button<br><input type="text" class="regular-text" name="s[emails][templates][%s][button]" value="%s" placeholder="%s"></label></p>', esc_attr( $key ), esc_attr( (string) ( $custom['button'] ?? '' ) ), esc_attr( $default['button'] ) );
			}
			echo '</details>';
		}
	}

	private static function setup(): void {
		$yes  = fn( bool $ok, string $on, string $off ) => sprintf( '<span class="cc-admin__%s">%s</span>', $ok ? 'ok' : 'warn', esc_html( $ok ? $on : $off ) );
		$rows = [
			'Stripe secret key'    => $yes( Stripe::enabled(), Stripe::test_mode() ? 'Set (test mode)' : 'Set (live mode)', 'Not set — "Accept & pay" asks the customer to wait for a call' ),
			'Stripe webhook'       => $yes( '' !== Stripe::webhook_secret(), 'Signing secret set', 'Not set' ),
			'Webhook URL'          => '<code>' . esc_html( Stripe::webhook_url() ) . '</code>',
			'Turnstile'            => $yes( '' !== Security::turnstile_site_key(), 'Set', 'Not set — honeypot and rate limits only' ),
			'Background jobs'      => esc_html( Queue::driver() ),
			'Server cron'          => $yes( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'WP-Cron disabled; server cron runs jobs', 'Using WP-Cron (runs on page visits) — add the hPanel cron job for reliable reminders' ),
		];
		echo '<table class="widefat cc-admin__kv"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), $value ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		echo '<h2>Setting up payments</h2><ol class="cc-admin__steps">'
			. '<li>In Stripe, copy the secret key (sk_test_… while testing) and add to <code>wp-config.php</code>: <code>define( \'CC_STRIPE_SECRET\', \'sk_…\' );</code></li>'
			. '<li>In Stripe → Developers → Webhooks, add an endpoint for the URL above with the events <code>checkout.session.completed</code>, <code>checkout.session.expired</code>, <code>checkout.session.async_payment_succeeded</code>, <code>checkout.session.async_payment_failed</code> and <code>charge.refunded</code>.</li>'
			. '<li>Copy its signing secret into <code>wp-config.php</code>: <code>define( \'CC_STRIPE_WEBHOOK_SECRET\', \'whsec_…\' );</code></li>'
			. '<li>Optional spam protection: create a Cloudflare Turnstile widget and add <code>CC_TURNSTILE_SITE</code> and <code>CC_TURNSTILE_SECRET</code>.</li>'
			. '</ol>';
	}

	public static function handle( string $do ): void {
		$tab   = Admin::text( 'tab' );
		$input = wp_unslash( (array) ( $_POST['s'] ?? [] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$back  = Admin::url( self::PAGE, [ 'tab' => $tab ] );
		if ( ! isset( self::fields()[ $tab ] ) ) {
			Admin::done( $back, 'Nothing to save.', 'error' );
		}

		$stored = get_option( Settings::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		foreach ( self::fields()[ $tab ] as $path => [ , , $type ] ) {
			[ $group, $key ] = explode( '.', $path );
			$raw             = $input[ $group ][ $key ] ?? null;
			if ( null === $raw ) {
				continue;
			}
			$stored[ $group ][ $key ] = match ( $type ) {
				'int'      => max( 0, (int) $raw ),
				'float'    => max( 0, (float) $raw ),
				'bool'     => (int) ! empty( $raw ),
				'textarea' => sanitize_textarea_field( (string) $raw ),
				default    => sanitize_text_field( (string) $raw ),
			};
		}
		if ( isset( $stored['emails']['from_address'] ) ) {
			$stored['emails']['from_address'] = sanitize_email( $stored['emails']['from_address'] );
			$stored['emails']['admin_to']     = sanitize_email( (string) ( $stored['emails']['admin_to'] ?? '' ) );
		}

		if ( 'pricing' === $tab ) {
			$services = [];
			foreach ( (array) ( $input['services'] ?? [] ) as $slug => $row ) {
				$services[ sanitize_key( $slug ) ] = [
					'mode'    => ( $row['mode'] ?? '' ) === 'bespoke' ? 'bespoke' : 'auto',
					'minimum' => '' === trim( (string) ( $row['minimum'] ?? '' ) ) ? '' : max( 0, (float) $row['minimum'] ),
				];
			}
			$stored['services'] = $services;
		}

		if ( 'booking' === $tab ) {
			$stored['booking']['working_days'] = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $input['booking']['working_days'] ?? [] ) ), fn( $d ) => $d >= 1 && $d <= 7 ) ) );
			$stored['booking']['hold_minutes'] = max( 30, (int) ( $stored['booking']['hold_minutes'] ?? 30 ) );
			$slots                             = [];
			foreach ( (array) ( $input['booking']['slots'] ?? [] ) as $key => $slot ) {
				$start = preg_match( '/^\d{2}:\d{2}$/', (string) ( $slot['start'] ?? '' ) ) ? $slot['start'] : '';
				$end   = preg_match( '/^\d{2}:\d{2}$/', (string) ( $slot['end'] ?? '' ) ) ? $slot['end'] : '';
				if ( $start && $end ) {
					$slots[ sanitize_key( $key ) ] = [ 'label' => sanitize_text_field( (string) ( $slot['label'] ?? $key ) ), 'start' => $start, 'end' => $end ];
				}
			}
			if ( $slots ) {
				$stored['booking']['slots'] = $slots;
			}
		}

		if ( 'emails' === $tab ) {
			$templates = [];
			foreach ( (array) ( $input['emails']['templates'] ?? [] ) as $key => $row ) {
				$row = array_filter( [
					'subject' => sanitize_text_field( (string) ( $row['subject'] ?? '' ) ),
					'body'    => sanitize_textarea_field( (string) ( $row['body'] ?? '' ) ),
					'button'  => sanitize_text_field( (string) ( $row['button'] ?? '' ) ),
				] );
				if ( $row && isset( Notify::defaults()[ $key ] ) ) {
					$templates[ $key ] = $row;
				}
			}
			$stored['emails']['templates'] = $templates;
		}

		Settings::save( $stored );
		Catalog::flush();
		Admin::done( $back, 'Settings saved.' );
	}
}

<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 3 after-booking automation: bootstrap, settings (aftercare.*), email templates, the "Manage your
 * booking" link in customer emails and completion follow-ups (thank-you, review request, re-clean window).
 * The re-clean claims table has its own installer and version option, separate from the core schema.
 *
 * Hooks fired by phase 3:
 *   cc_booking_rescheduled( $booking_id, $old_start )            $old_start = previous start_at (UTC "Y-m-d H:i:s")
 *   cc_booking_refund_pending( $booking_id, $amount_pence, $payment_id )   refund owed but not paid automatically
 *   cc_booking_reclean_claimed( $claim_id, $booking_id )
 *   cc_booking_reclean_approved( $claim_id, $booking_id, $reclean_booking_id )
 *   cc_booking_reclean_declined( $claim_id, $booking_id, $reason )
 * Re-clean bookings are confirmed through cc_booking_confirmed with $context['provider'] = 'reclean'.
 */
final class Aftercare {
	const SCHEMA_VERSION = '1';
	const SCHEMA_OPTION  = 'cc_bookings_aftercare_schema';
	const REVIEW_HOOK    = 'cc_bookings_review_request';

	const DEFAULTS = [
		'allow_reschedule'     => 1,
		'reschedule_hours'     => 48,
		'allow_cancel'         => 1,
		'full_refund_hours'    => 48,
		'partial_refund_hours' => 24,
		'partial_refund_pct'   => 50,
		'reminder_hours'       => '48,24',
		'reminder_sms'         => 1,
		'review_url'           => '',
		'review_delay_hours'   => 2,
		'reclean_hours'        => '',
	];

	/** Bookings that can still be changed or cancelled. */
	const OPEN = [ 'confirmed', 'dispatching', 'assigned' ];

	public static function init(): void {
		self::maybe_install();
		add_filter( 'cc_bookings_email_defaults', [ self::class, 'email_defaults' ] );
		add_filter( 'cc_bookings_email_vars', [ self::class, 'email_vars' ], 10, 3 );
		add_filter( 'cc_bookings_statuses', [ self::class, 'statuses' ], 10, 2 );
		add_action( 'cc_booking_completed', [ self::class, 'completed' ] );
		add_action( self::REVIEW_HOOK, [ self::class, 'review_request' ] );

		Manage::init();
		Reminders::init();
		Reclean::init();
		if ( is_admin() ) {
			Admin\Aftercare_Screen::init();
			Admin\Reports_Screen::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cc-bookings aftercare', Aftercare_Cli::class );
		}
	}

	/* ---------------------------------------------------------------------
	 * Schema (own version option; the core schema version is untouched)
	 * ------------------------------------------------------------------ */

	public static function maybe_install(): void {
		if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . Schema::table( Reclean::TABLE ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
status varchar(20) NOT NULL DEFAULT 'open',
message text NULL,
photos text NULL,
photo_ref varchar(40) NOT NULL DEFAULT '',
reclean_booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
admin_note text NULL,
decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
decided_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY booking_id (booking_id),
KEY status (status)
) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function setting( string $key ) {
		return Settings::get( 'aftercare.' . $key, self::DEFAULTS[ $key ] ?? null );
	}

	public static function review_url(): string {
		$url = trim( (string) self::setting( 'review_url' ) );
		if ( '' === $url && function_exists( 'cc_option' ) ) {
			$url = trim( (string) cc_option( 'google_review_url', '' ) );
		}
		return esc_url_raw( $url );
	}

	public static function statuses( array $statuses, string $kind ): array {
		if ( 'payment' === $kind ) {
			$statuses['free'] = 'No charge';
		}
		return $statuses;
	}

	/* ---------------------------------------------------------------------
	 * Emails
	 * ------------------------------------------------------------------ */

	public static function email_defaults( array $defaults ): array {
		return $defaults + [
			'reschedule_customer' => [
				'label'      => 'Booking moved (customer)',
				'to'         => 'customer',
				'subject'    => 'Your booking {ref} has moved to {date_short}',
				'body'       => "Hi {first_name},\n\nYour {service} booking {ref} has been moved.\n\nWas: {old_date}\nNow: {date}\n\n{details}\n\nIf anything else changes, reply to this email or call {phone}.",
				'button'     => 'View your booking',
				'button_url' => 'manage_url',
			],
			'reschedule_admin'    => [
				'label'      => 'Booking moved by the customer (admin)',
				'to'         => 'admin',
				'subject'    => 'Booking {ref} moved to {date_short}',
				'body'       => "{name} moved booking {ref} ({service}).\n\nWas: {old_date}\nNow: {date}\n\nCleaners on the job before the move: {crew}. {dispatch_note}",
				'button'     => 'Open booking',
				'button_url' => 'admin_url',
			],
			'reschedule_cleaner'  => [
				'label'   => 'Job moved (cleaner)',
				'to'      => 'cleaner',
				'subject' => 'Job {ref} moved to {date_short}',
				'body'    => "Hi {cleaner_first_name},\n\nJob {ref} ({service}) has moved.\n\nWas: {old_date}\nNow: {date}\n\nIf you can't make the new time, please call the office on {phone} as soon as possible.",
			],
			'cancel_admin'        => [
				'label'      => 'Booking cancelled by the customer (admin)',
				'to'         => 'admin',
				'subject'    => 'Booking {ref} cancelled by the customer',
				'body'       => "{name} cancelled booking {ref} ({service}, {date}) online, {notice} before the start.\n\nRefund under the cancellation policy: {refund} ({refund_pct}). {refund_status}",
				'button'     => 'Open booking',
				'button_url' => 'admin_url',
			],
			'cancel_cleaner'      => [
				'label'   => 'Job cancelled (cleaner)',
				'to'      => 'cleaner',
				'subject' => 'Job {ref} on {date_short} is cancelled',
				'body'    => "Hi {cleaner_first_name},\n\nJob {ref} ({service}, {date}) has been cancelled, so you no longer need to go. Thank you.",
			],
			'reminder_customer'   => [
				'label'      => 'Reminder before the clean (customer)',
				'to'         => 'customer',
				'subject'    => 'Reminder: your clean is {lead} ({date_short})',
				'body'       => "Hi {first_name},\n\nJust a reminder that your {service} is booked for {date}.\n\n{details}\n\n{crew_line}Please make sure we can get in at the arrival time. If you need to change anything, use the link below or call us on {phone}.",
				'button'     => 'View your booking',
				'button_url' => 'manage_url',
			],
			'reminder_cleaner'    => [
				'label'   => 'Reminder before the job (cleaner)',
				'to'      => 'cleaner',
				'subject' => 'Reminder: job {ref} {lead} ({date_short})',
				'body'    => "Hi {cleaner_first_name},\n\nA reminder of your job {lead}.\n\n{job_details}\n\nIf there's a problem, call the office on {phone}.",
			],
			'aftercare_thanks'    => [
				'label'      => 'Job complete: thank you + re-clean guarantee (customer)',
				'to'         => 'customer',
				'subject'    => 'Thank you — your {service} is complete',
				'body'       => "Hi {first_name},\n\nThank you for choosing {brand}. Your {service} ({ref}) is complete.\n\nOur re-clean guarantee: if anything isn't right, tell us by {reclean_until} and we'll come back and put it right for free. Use the button below and add a few photos of the problem.",
				'button'     => 'Report a problem',
				'button_url' => 'reclean_url',
			],
			'aftercare_admin'     => [
				'label'      => 'Job marked complete (admin)',
				'to'         => 'admin',
				'subject'    => 'Job {ref} complete',
				'body'       => "{service} for {name} ({date}) has been marked complete.\n\nRe-clean guarantee open until {reclean_until}. {review_note}",
				'button'     => 'Open booking',
				'button_url' => 'admin_url',
			],
			'review_request'      => [
				'label'      => 'Review request after the clean (customer)',
				'to'         => 'customer',
				'subject'    => 'How did we do, {first_name}?',
				'body'       => "Hi {first_name},\n\nWe hope you're happy with your {service}. If you have a minute, a Google review really helps a local business like ours, and helps your neighbours find a cleaner they can trust.\n\nThank you!",
				'button'     => 'Leave a review',
				'button_url' => 'review_url',
			],
			'reclean_received'    => [
				'label'   => 'Re-clean request received (customer)',
				'to'      => 'customer',
				'subject' => 'We\'ve got your re-clean request for {ref}',
				'body'    => "Hi {first_name},\n\nThanks for letting us know, and sorry it wasn't right. We'll look at your photos and get back to you shortly to arrange a free return visit.\n\nWhat you told us:\n{message}",
			],
			'reclean_admin'       => [
				'label'      => 'Re-clean claim (admin)',
				'to'         => 'admin',
				'subject'    => 'Action needed: re-clean claim on {ref}',
				'body'       => "{name} has asked for a re-clean on booking {ref} ({service}, {date}).\n\n{message}\n\nPhotos: {photo_count}. Approve it to book a free return visit, or decline it with a reason.",
				'button'     => 'Review the claim',
				'button_url' => 'admin_url',
			],
			'reclean_approved'    => [
				'label'      => 'Re-clean booked (customer)',
				'to'         => 'customer',
				'subject'    => 'Your free re-clean is booked for {date_short}',
				'body'       => "Hi {first_name},\n\nWe're sorry the first clean wasn't right. We've booked a free return visit:\n\n{details}\n\nThere's nothing to pay. {note}",
				'button'     => 'View your booking',
				'button_url' => 'manage_url',
			],
			'reclean_declined'    => [
				'label'   => 'Re-clean declined (customer)',
				'to'      => 'customer',
				'subject' => 'Your re-clean request for {ref}',
				'body'    => "Hi {first_name},\n\nThank you for your request. Having looked at it carefully, we're not able to offer a re-clean this time: {reason}\n\nIf you'd like to talk it through, call us on {phone}.",
			],
		];
	}

	/** Adds {manage_url} (a signed booking link) to every customer email about a booking that can still change. */
	public static function email_vars( $vars, $key, $object ) {
		$template = Notify::defaults()[ $key ] ?? [];
		if ( 'customer' !== ( $template['to'] ?? '' ) || 'booking' !== ( $object[0] ?? '' ) || ! empty( $vars['manage_url'] ) ) {
			return $vars;
		}
		$booking = Bookings::get( (int) ( $object[1] ?? 0 ) );
		if ( $booking && in_array( $booking['status'], self::OPEN, true ) ) {
			$vars['manage_url'] = ! empty( $vars['booking_url'] ) ? (string) $vars['booking_url'] : self::link( $booking );
		}
		return $vars;
	}

	/** One fresh customer link per booking per request, so several emails sent together share a token. */
	public static function link( array $booking, array $args = [] ): string {
		static $links = [];
		$links[ $booking['id'] ] ??= Bookings::link( $booking );
		return $args ? add_query_arg( $args, $links[ $booking['id'] ] ) : $links[ $booking['id'] ];
	}

	/** Standard booking variables plus the cleaner-facing job summary. */
	public static function job_details( array $booking, bool $with_phone ): string {
		$lines = [
			[ 'Job', $booking['ref'] . ' — ' . $booking['service_name'] ],
			[ 'When', Bookings::start_label( $booking ) ],
			[ 'Length', Pricing::duration_label( (float) $booking['hours'], (int) $booking['cleaners'] ) ],
			[ 'Address', Bookings::full_address( $booking ) ],
			[ 'Customer', Notify::first_name( $booking['customer_name'] ) . ( $with_phone ? ' — ' . $booking['phone'] : '' ) ],
			[ 'Access', (string) $booking['access_notes'] ],
		];
		$service = Catalog::get( (string) $booking['service_slug'] );
		if ( $service ) {
			$lines = array_merge( $lines, Catalog::summary( $service, (array) $booking['answers'] ) );
			if ( $addons = Catalog::addon_names( $service, (array) $booking['answers'] ) ) {
				$lines[] = [ 'Extras', implode( ', ', $addons ) ];
			}
		}
		return Notify::details_html( $lines );
	}

	/**
	 * Email + text to every cleaner currently on the job.
	 *
	 * @param callable $sms fn( array $cleaner ): string — the text message, or '' for none.
	 */
	public static function notify_crew( array $booking, string $template, array $vars, ?callable $sms = null, ?array $crew = null ): int {
		$sent = 0;
		foreach ( $crew ?? Bookings::crew( (int) $booking['id'] ) as $offer ) {
			$cleaner = $offer['cleaner'] ?? null;
			if ( ! $cleaner ) {
				continue;
			}
			Notify::send( $template, (string) $cleaner['email'], [ 'cleaner_first_name' => Notify::first_name( (string) $cleaner['name'] ) ] + $vars, [ 'booking', (int) $booking['id'] ] );
			$text = $sms ? (string) $sms( $cleaner ) : '';
			if ( '' !== $text && ! empty( $cleaner['phone'] ) ) {
				self::sms( (string) $cleaner['phone'], $text, (int) $booking['id'] );
			}
			$sent++;
		}
		return $sent;
	}

	/** Text message through the phase 1 hook (the phase 2 SMS provider plugs into `cc_bookings_send_sms`). */
	public static function sms( string $to, string $text, int $booking_id ): bool {
		if ( '' === trim( $to ) || ! method_exists( Notify::class, 'sms' ) ) {
			return false;
		}
		return Notify::sms( $to, $text, [ 'booking', $booking_id ] );
	}

	/**
	 * True when phase 2 dispatch is running and owns the cleaner messages for this event
	 * (it re-dispatches on reschedule and tells removed cleaners; it tells the crew about cancellations).
	 */
	public static function dispatch_handles( string $event ): bool {
		$map = [
			'rescheduled' => [ 'cc_booking_rescheduled', 'on_rescheduled' ],
			'cancelled'   => [ 'cc_booking_status_changed', 'on_status_changed' ],
		];
		return isset( $map[ $event ] ) && false !== has_action( $map[ $event ][0], [ __NAMESPACE__ . '\\Dispatch', $map[ $event ][1] ] );
	}

	public static function is_reclean( array $booking ): bool {
		return ! empty( $booking['meta']['reclean_of'] );
	}

	/** "2 days and 3 hours" style notice label. */
	public static function notice_label( float $hours ): string {
		if ( $hours >= 48 ) {
			return sprintf( '%d days', (int) floor( $hours / 24 ) );
		}
		if ( $hours >= 1 ) {
			return sprintf( '%d hour%s', (int) floor( $hours ), (int) floor( $hours ) === 1 ? '' : 's' );
		}
		return 'under an hour';
	}

	/* ---------------------------------------------------------------------
	 * Completion follow-ups
	 * ------------------------------------------------------------------ */

	public static function completed( $booking_id ): void {
		$booking = Bookings::get( (int) $booking_id );
		if ( ! $booking || 'cancelled' === $booking['status'] ) {
			return;
		}
		Reminders::unschedule( $booking['id'] );
		if ( Db::count( 'events', [ 'object_type' => 'booking', 'object_id' => $booking['id'], 'event' => 'aftercare_started' ] ) ) {
			return;
		}

		$until  = Reclean::window_end( $booking );
		$delay  = max( 0.0, (float) self::setting( 'review_delay_hours' ) );
		$review = ! self::is_reclean( $booking );
		Events::log( 'booking', $booking['id'], 'aftercare_started', sprintf( 'Re-clean guarantee open until %s', wp_date( 'D j M, H:i', $until ) ), [ 'reclean_until' => Db::datetime( $until ) ] );

		$vars = [
			'reclean_until' => wp_date( 'l j F, g:ia', $until ),
			'reclean_hours' => (string) Reclean::window_hours(),
			'reclean_url'   => self::link( $booking, [ 'reclean' => 1 ] ) . '#cc-reclean',
			'review_note'   => $review ? sprintf( 'Review request goes out at %s.', wp_date( 'D j M, H:i', time() + (int) round( $delay * HOUR_IN_SECONDS ) ) ) : 'This was a re-clean, so no review request is sent.',
		] + Notify::booking_vars( $booking );

		if ( ! self::is_reclean( $booking ) ) {
			Notify::send( 'aftercare_thanks', (string) $booking['email'], $vars, [ 'booking', $booking['id'] ] );
		}
		Notify::send( 'aftercare_admin', Settings::admin_email(), $vars, [ 'booking', $booking['id'] ] );
		if ( $review ) {
			Queue::cancel( self::REVIEW_HOOK, [ $booking['id'] ] );
			Queue::at( time() + (int) round( $delay * HOUR_IN_SECONDS ), self::REVIEW_HOOK, [ $booking['id'] ] );
		}
	}

	/** Scheduled 2 hours (configurable) after completion. */
	public static function review_request( $booking_id ): void {
		$booking = Bookings::get( (int) $booking_id );
		if ( ! $booking || 'cancelled' === $booking['status'] ) {
			return;
		}
		if ( Db::count( 'events', [ 'object_type' => 'booking', 'object_id' => $booking['id'], 'event' => 'review_requested' ] ) ) {
			return;
		}
		$url = self::review_url();
		if ( '' === $url ) {
			Events::log( 'booking', $booking['id'], 'review_skipped', 'No Google review link set (Bookings → After booking)' );
			return;
		}
		if ( Reclean::open_claim( $booking['id'] ) ) {
			Events::log( 'booking', $booking['id'], 'review_skipped', 'Not sent: the customer has an open re-clean claim' );
			return;
		}
		Notify::send( 'review_request', (string) $booking['email'], [ 'review_url' => $url ] + Notify::booking_vars( $booking ), [ 'booking', $booking['id'] ] );
		Events::log( 'booking', $booking['id'], 'review_requested', 'Review request sent' );
	}
}

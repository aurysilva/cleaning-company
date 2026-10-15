<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Reminders before the clean (default 48 h and 24 h) to the customer and every cleaner on the job, by email
 * and text. Scheduled on confirmation and reschedule, removed on cancellation and completion.
 * Queue action: cc_bookings_reminder( $booking_id, $hours_before ).
 */
final class Reminders {
	const HOOK = 'cc_bookings_reminder';

	public static function init(): void {
		add_action( 'cc_booking_confirmed', [ self::class, 'schedule' ] );
		add_action( 'cc_booking_rescheduled', [ self::class, 'schedule' ] );
		add_action( 'cc_booking_status_changed', [ self::class, 'status_changed' ], 10, 2 );
		add_action( self::HOOK, [ self::class, 'send' ], 10, 2 );
	}

	/** @return int[] hours before the start, largest first. */
	public static function offsets(): array {
		$hours = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', (string) Aftercare::setting( 'reminder_hours' ), -1, PREG_SPLIT_NO_EMPTY ) ), fn( $h ) => $h > 0 && $h <= 24 * 14 );
		$hours = array_values( array_unique( $hours ) );
		rsort( $hours );
		return $hours;
	}

	public static function start( array $booking ): int {
		return Db::timestamp( $booking['start_at'] ) ?: ( $booking['job_date'] ? Slots::start_timestamp( $booking['job_date'], (string) $booking['slot'] ) : 0 );
	}

	public static function schedule( $booking_id ): void {
		$booking = Bookings::get( (int) $booking_id );
		if ( ! $booking ) {
			return;
		}
		self::unschedule( $booking['id'] );
		$start = self::start( $booking );
		if ( ! $start || ! in_array( $booking['status'], Aftercare::OPEN, true ) ) {
			return;
		}
		$queued = [];
		foreach ( self::offsets() as $hours ) {
			$at = $start - $hours * HOUR_IN_SECONDS;
			if ( $at > time() + MINUTE_IN_SECONDS ) {
				Queue::at( $at, self::HOOK, [ $booking['id'], $hours ] );
				$queued[] = sprintf( '%dh (%s)', $hours, wp_date( 'D j M H:i', $at ) );
			}
		}
		Events::log( 'booking', $booking['id'], 'reminders_scheduled', $queued ? 'Reminders queued: ' . implode( ', ', $queued ) : 'No reminders queued (the clean is too soon)', [ 'start' => $booking['start_at'] ] );
	}

	public static function unschedule( int $booking_id ): void {
		foreach ( array_unique( array_merge( self::offsets(), [ 48, 24 ] ) ) as $hours ) {
			Queue::cancel( self::HOOK, [ $booking_id, $hours ] );
		}
	}

	/** @return array<int, int> hours => timestamp of each queued reminder. */
	public static function queued( int $booking_id ): array {
		$out = [];
		foreach ( array_unique( array_merge( self::offsets(), [ 48, 24 ] ) ) as $hours ) {
			if ( $next = Queue::next( self::HOOK, [ $booking_id, $hours ] ) ) {
				$out[ $hours ] = $next;
			}
		}
		return $out;
	}

	public static function status_changed( $booking_id, $to ): void {
		if ( in_array( $to, [ 'cancelled', 'completed' ], true ) ) {
			self::unschedule( (int) $booking_id );
		}
	}

	/** "tomorrow", "today", "on Saturday" or "in 3 days", from the job date. */
	public static function lead( array $booking ): string {
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$job   = date_create_immutable( (string) $booking['job_date'], wp_timezone() );
		if ( ! $job ) {
			return 'soon';
		}
		$days = (int) $today->diff( $job->setTime( 0, 0 ) )->format( '%r%a' );
		return match ( true ) {
			$days <= 0 => 'today',
			1 === $days => 'tomorrow',
			$days < 7  => 'on ' . $job->format( 'l' ),
			default    => 'in ' . $days . ' days',
		};
	}

	public static function send( $booking_id, $hours ): void {
		$booking = Bookings::get( (int) $booking_id );
		$hours   = (int) $hours;
		if ( ! $booking || ! in_array( $booking['status'], Aftercare::OPEN, true ) || self::start( $booking ) <= time() ) {
			return;
		}
		$key = $hours . '@' . $booking['start_at'];
		foreach ( Db::all( 'events', [ 'object_type' => 'booking', 'object_id' => $booking['id'], 'event' => 'reminder_sent' ] ) as $event ) {
			if ( ( $event['data']['key'] ?? '' ) === $key ) {
				return;
			}
		}

		$lead  = self::lead( $booking );
		$crew  = Bookings::crew( $booking['id'] );
		$names = array_filter( array_map( fn( $o ) => $o['cleaner'] ? Notify::first_name( (string) $o['cleaner']['name'] ) : '', $crew ) );
		$vars  = [
			'lead'      => $lead,
			'crew_line' => $names ? sprintf( "Your cleaner%s: %s.\n\n", count( $names ) > 1 ? 's' : '', implode( ', ', $names ) ) : '',
		] + Notify::booking_vars( $booking );

		Notify::send( 'reminder_customer', (string) $booking['email'], $vars, [ 'booking', $booking['id'] ] );
		$sms = (bool) Aftercare::setting( 'reminder_sms' );
		$when = Slots::date_label( (string) $booking['job_date'], 'D j M' ) . ', arriving ' . strtolower( Slots::label( (string) $booking['slot'] ) );
		if ( $sms ) {
			Aftercare::sms( (string) $booking['phone'], sprintf( '%s: reminder, your %s is %s (%s). To change it, use the link in your email or call %s.', Settings::brand(), $booking['service_name'], $lead, $when, Notify::site_vars()['phone'] ), $booking['id'] );
		}

		$vars['job_details'] = Aftercare::job_details( $booking, $hours <= 24 );
		$count = Aftercare::notify_crew( $booking, 'reminder_cleaner', $vars, $sms ? fn( $c ) => sprintf( '%s: reminder, job %s %s (%s) at %s.', Settings::brand(), $booking['ref'], $lead, $when, trim( $booking['town'] . ' ' . $booking['postcode'] ) ) : null, $crew );

		Events::log( 'booking', $booking['id'], 'reminder_sent', sprintf( '%dh reminder sent to the customer and %d cleaner%s', $hours, $count, 1 === $count ? '' : 's' ), [ 'key' => $key ] );
	}
}

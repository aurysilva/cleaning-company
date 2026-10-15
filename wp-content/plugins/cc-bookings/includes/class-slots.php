<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Bookable dates and arrival slots. Phase 1 capacity is admin-set: per slot, a maximum number of jobs
 * and of cleaner-hours (job hours × team size), counting paid bookings and live 30-minute holds.
 * Phase 2 swaps in cleaner calendars through the `cc_bookings_slot_available` filter.
 */
final class Slots {
	public static function definitions(): array {
		return apply_filters( 'cc_bookings_slots', (array) Settings::get( 'booking.slots', [] ) );
	}

	public static function label( string $key, bool $with_times = true ): string {
		$slot = self::definitions()[ $key ] ?? null;
		if ( ! $slot ) {
			return $key;
		}
		return $with_times ? sprintf( '%s (%s–%s)', $slot['label'], self::time_label( $slot['start'] ), self::time_label( $slot['end'] ) ) : $slot['label'];
	}

	/** "08:00" => "8am", "11:30" => "11:30am". */
	public static function time_label( string $time ): string {
		$ts = strtotime( '2000-01-01 ' . $time );
		return $ts ? str_replace( ':00', '', gmdate( 'g:ia', $ts ) ) : $time;
	}

	public static function date_label( string $date, string $format = 'l j F Y' ): string {
		$dt = date_create_immutable( $date, wp_timezone() );
		return $dt ? wp_date( $format, $dt->getTimestamp() ) : $date;
	}

	/** Arrival window start as a Unix timestamp (first slot if none given). */
	public static function start_timestamp( string $date, string $slot = '' ): int {
		$defs = self::definitions();
		$time = $defs[ $slot ]['start'] ?? ( reset( $defs )['start'] ?? '08:00' );
		$dt   = date_create_immutable( $date . ' ' . $time, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	public static function is_working_day( string $date ): bool {
		$dt = date_create_immutable( $date, wp_timezone() );
		if ( ! $dt ) {
			return false;
		}
		$days    = array_map( 'intval', (array) Settings::get( 'booking.working_days', [] ) );
		$blocked = preg_split( '/[\s,]+/', (string) Settings::get( 'booking.blocked_dates', '' ), -1, PREG_SPLIT_NO_EMPTY );
		return in_array( (int) $dt->format( 'N' ), $days, true ) && ! in_array( $dt->format( 'Y-m-d' ), $blocked, true );
	}

	/** Working dates from the first day with any slot past minimum notice, to the booking horizon. */
	public static function dates(): array {
		$tz      = wp_timezone();
		$day     = new \DateTimeImmutable( 'today', $tz );
		$horizon = max( 7, (int) Settings::get( 'booking.max_days_ahead', 60 ) );
		$notice  = time() + (int) Settings::get( 'booking.min_notice_hours', 48 ) * HOUR_IN_SECONDS;
		$last    = array_key_last( self::definitions() );
		$out     = [];
		for ( $i = 0; $i <= $horizon; $i++ ) {
			$date = $day->modify( "+{$i} days" )->format( 'Y-m-d' );
			if ( self::is_working_day( $date ) && self::start_timestamp( $date, (string) $last ) >= $notice ) {
				$out[] = $date;
			}
		}
		return $out;
	}

	/**
	 * Jobs and cleaner-hours already taken per date and slot.
	 *
	 * @return array<string, array<string, array{jobs: int, hours: float}>>
	 */
	public static function usage( string $from, string $to, int $exclude_quote = 0 ): array {
		global $wpdb;
		$usage    = [];
		$statuses = Status::active_booking();
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$bookings = $wpdb->get_results( $wpdb->prepare(
			'SELECT job_date, slot, COUNT(*) AS jobs, SUM(hours * cleaners) AS hours FROM ' . Schema::table( 'bookings' )
			. " WHERE job_date BETWEEN %s AND %s AND status IN ({$in}) GROUP BY job_date, slot",
			$from, $to, ...$statuses
		), ARRAY_A );

		$holds = $wpdb->get_results( $wpdb->prepare(
			'SELECT job_date, slot, COUNT(*) AS jobs, SUM(hours * cleaners) AS hours FROM ' . Schema::table( 'quotes' )
			. ' WHERE job_date BETWEEN %s AND %s AND hold_expires_at > %s AND booking_id = 0 AND id != %d GROUP BY job_date, slot',
			$from, $to, Db::now(), $exclude_quote
		), ARRAY_A );

		foreach ( array_merge( $bookings ?: [], $holds ?: [] ) as $row ) {
			$cell = $usage[ $row['job_date'] ][ $row['slot'] ] ?? [ 'jobs' => 0, 'hours' => 0.0 ];
			$usage[ $row['job_date'] ][ $row['slot'] ] = [
				'jobs'  => $cell['jobs'] + (int) $row['jobs'],
				'hours' => $cell['hours'] + (float) $row['hours'],
			];
		}
		return $usage;
	}

	public static function is_available( string $date, string $slot, float $hours, int $cleaners, int $exclude_quote = 0 ): bool {
		return (bool) ( self::availability( $hours, $cleaners, $exclude_quote, [ $date ] )[ $date ][ $slot ] ?? false );
	}

	/**
	 * @param string[]|null $dates Limit to these dates (default: every bookable date).
	 * @return array<string, array<string, bool>> date => slot => bookable
	 */
	public static function availability( float $hours, int $cleaners, int $exclude_quote = 0, ?array $dates = null ): array {
		$dates = $dates ?? self::dates();
		if ( ! $dates ) {
			return [];
		}
		$usage  = self::usage( min( $dates ), max( $dates ), $exclude_quote );
		$notice = time() + (int) Settings::get( 'booking.min_notice_hours', 48 ) * HOUR_IN_SECONDS;
		$jobs   = max( 0, (int) Settings::get( 'booking.capacity_jobs', 0 ) );
		$cap    = max( 0.0, (float) Settings::get( 'booking.capacity_hours', 0 ) );
		$need   = $hours * max( 1, $cleaners );
		$out    = [];

		foreach ( $dates as $date ) {
			foreach ( array_keys( self::definitions() ) as $slot ) {
				$used = $usage[ $date ][ $slot ] ?? [ 'jobs' => 0, 'hours' => 0.0 ];
				$ok   = self::is_working_day( $date )
					&& self::start_timestamp( $date, $slot ) >= $notice
					&& ( ! $jobs || $used['jobs'] + 1 <= $jobs )
					&& ( ! $cap || $used['hours'] + $need <= $cap + 0.001 );
				$out[ $date ][ $slot ] = (bool) apply_filters( 'cc_bookings_slot_available', $ok, $date, $slot, [ 'hours' => $hours, 'cleaners' => $cleaners, 'used' => $used, 'exclude_quote' => $exclude_quote ] );
			}
		}
		return $out;
	}
}

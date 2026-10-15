<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Cleaner calendars (wp_cc_cleaner_availability) and who is free for a job.
 *
 * Rows: kind "weekly" (weekday 1–7 + start/end time; weekday 0 = "no regular hours"), kind "off"
 * (date_from–date_to, optionally only start–end on those days). A cleaner with no weekly rows is
 * available any time. A cleaner is free for a job when the job fits inside a weekly window, isn't in
 * time off, they're under their daily job limit, and it doesn't overlap another job (plus a travel buffer).
 *
 * Slots: when there are active cleaners, `cc_bookings_slot_available` is answered from these calendars
 * (setting dispatch.slot_source = cleaners|both|capacity); with no cleaners, Phase 1's admin capacity applies.
 */
final class Availability {
	const DAYS = [ 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun' ];

	/** @var array<int, array> cleaner_id => rules */
	private static array $rules = [];

	/** Service and district of the job being priced, from the REST request (the slot filter has no quote yet). */
	private static array $context = [];

	private static ?array $grid = null;

	private static array $area_districts = [];

	private static ?bool $has_cleaners = null;

	public static function init(): void {
		add_filter( 'cc_bookings_slot_available', [ self::class, 'slot_available' ], 10, 4 );
		add_filter( 'rest_pre_dispatch', [ self::class, 'capture_context' ], 5, 3 );
		add_action( 'cc_cleaner_saved', [ self::class, 'save_profile' ], 10, 2 );
		foreach ( [ 'cc_booking_assigned', 'cc_booking_unassigned', 'cc_booking_status_changed', 'cc_cleaner_saved' ] as $hook ) {
			add_action( $hook, [ self::class, 'flush' ], 1 );
		}
	}

	public static function flush(): void {
		self::$rules        = [];
		self::$grid         = null;
		self::$has_cleaners = null;
	}

	/* ---------------------------------------------------------------------
	 * Rules
	 * ------------------------------------------------------------------ */

	/** Load rules for many cleaners in one query. */
	public static function prime( array $cleaner_ids ): void {
		$missing = array_values( array_diff( array_map( 'intval', $cleaner_ids ), array_keys( self::$rules ) ) );
		if ( ! $missing ) {
			return;
		}
		foreach ( $missing as $id ) {
			self::$rules[ $id ] = [ 'weekly' => [], 'off' => [] ];
		}
		foreach ( Db::all( 'cleaner_availability', [ 'cleaner_id' => $missing ], 'id ASC' ) as $row ) {
			$id = (int) $row['cleaner_id'];
			if ( 'weekly' === $row['kind'] ) {
				self::$rules[ $id ]['weekly'][ (int) $row['weekday'] ][] = [ substr( (string) $row['start_time'], 0, 5 ), substr( (string) $row['end_time'], 0, 5 ) ];
			} elseif ( 'off' === $row['kind'] && $row['date_from'] ) {
				self::$rules[ $id ]['off'][] = [
					'id'    => (int) $row['id'],
					'from'  => $row['date_from'],
					'to'    => $row['date_to'] ?: $row['date_from'],
					'start' => $row['start_time'] ? substr( $row['start_time'], 0, 5 ) : '',
					'end'   => $row['end_time'] ? substr( $row['end_time'], 0, 5 ) : '',
					'note'  => (string) $row['note'],
				];
			}
		}
	}

	/** @return array{weekly: array<int, array<int, array{0: string, 1: string}>>, off: array} */
	public static function rules( int $cleaner_id ): array {
		self::prime( [ $cleaner_id ] );
		return self::$rules[ $cleaner_id ];
	}

	/** Replace a cleaner's weekly pattern. $week: weekday => [ [ 'HH:MM', 'HH:MM' ], … ]; null = any time; [] = no regular hours. */
	public static function set_week( int $cleaner_id, ?array $week ): void {
		Db::delete( 'cleaner_availability', [ 'cleaner_id' => $cleaner_id, 'kind' => 'weekly' ] );
		if ( null !== $week ) {
			$rows = 0;
			foreach ( $week as $day => $windows ) {
				$day = (int) $day;
				if ( $day < 1 || $day > 7 ) {
					continue;
				}
				foreach ( isset( $windows[0] ) && is_string( $windows[0] ) ? [ $windows ] : (array) $windows as $window ) {
					[ $start, $end ] = [ self::time( (string) ( $window[0] ?? '' ) ), self::time( (string) ( $window[1] ?? '' ) ) ];
					if ( $start && $end && $end > $start ) {
						Db::insert( 'cleaner_availability', [ 'cleaner_id' => $cleaner_id, 'kind' => 'weekly', 'weekday' => $day, 'start_time' => $start, 'end_time' => $end ] );
						++$rows;
					}
				}
			}
			if ( ! $rows ) {
				Db::insert( 'cleaner_availability', [ 'cleaner_id' => $cleaner_id, 'kind' => 'weekly', 'weekday' => 0, 'start_time' => '00:00', 'end_time' => '00:00', 'note' => 'No regular hours' ] );
			}
		}
		self::flush();
	}

	public static function add_time_off( int $cleaner_id, string $from, string $to = '', string $note = '', string $start = '', string $end = '' ): int {
		$from = self::date( $from );
		$to   = self::date( $to ) ?: $from;
		if ( ! $from ) {
			return 0;
		}
		self::flush();
		return Db::insert( 'cleaner_availability', [
			'cleaner_id' => $cleaner_id,
			'kind'       => 'off',
			'date_from'  => min( $from, $to ),
			'date_to'    => max( $from, $to ),
			'start_time' => self::time( $start ) ?: null,
			'end_time'   => self::time( $end ) ?: null,
			'note'       => mb_substr( sanitize_text_field( $note ), 0, 255 ),
		] );
	}

	/** Weekly pattern as text, e.g. "Mon–Fri 08:00–17:00, Sat 09:00–13:00". */
	public static function week_label( int $cleaner_id ): string {
		$weekly = self::rules( $cleaner_id )['weekly'];
		if ( ! $weekly ) {
			return 'Any time';
		}
		$parts = [];
		foreach ( self::DAYS as $n => $day ) {
			if ( isset( $weekly[ $n ] ) ) {
				$parts[] = $day . ' ' . implode( ', ', array_map( fn( $w ) => $w[0] . '–' . $w[1], $weekly[ $n ] ) );
			}
		}
		return $parts ? implode( '; ', $parts ) : 'No regular hours';
	}

	/** Cleaner's covered areas (Area post ids). */
	public static function areas( array $cleaner ): array {
		$raw = $cleaner['areas'] ?? [];
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		return array_values( array_filter( array_map( 'intval', (array) $raw ) ) );
	}

	public static function area_districts( int $area_id ): array {
		if ( ! isset( self::$area_districts[ $area_id ] ) ) {
			$text = (string) get_post_meta( $area_id, 'postcodes', true );
			if ( function_exists( 'cc_postcode_districts' ) ) {
				$list = (array) cc_postcode_districts( $text );
			} else {
				$list = [];
				foreach ( preg_split( '/\s*,\s*/', strtoupper( trim( $text ) ) ) as $part ) {
					if ( preg_match( '/^([A-Z]{1,2})(\d{1,2})\s*[–-]\s*(?:[A-Z]{1,2})?(\d{1,2})$/u', $part, $m ) ) {
						for ( $i = (int) $m[2]; $i <= (int) $m[3]; $i++ ) {
							$list[] = $m[1] . $i;
						}
					} elseif ( preg_match( '/^[A-Z]{1,2}\d{1,2}[A-Z]?$/', $part ) ) {
						$list[] = $part;
					}
				}
			}
			self::$area_districts[ $area_id ] = array_values( array_unique( $list ) );
		}
		return self::$area_districts[ $area_id ];
	}

	/** Districts a cleaner covers ([] = anywhere). */
	public static function districts( array $cleaner ): array {
		$out = (array) $cleaner['districts'];
		foreach ( self::areas( $cleaner ) as $area ) {
			$out = array_merge( $out, self::area_districts( $area ) );
		}
		return array_values( array_unique( $out ) );
	}

	public static function covers( array $cleaner, string $district ): bool {
		if ( '' === $district || ( ! $cleaner['districts'] && ! self::areas( $cleaner ) ) ) {
			return true;
		}
		return in_array( strtoupper( $district ), self::districts( $cleaner ), true );
	}

	public static function does( array $cleaner, string $service ): bool {
		return '' === $service || ! $cleaner['services'] || in_array( $service, (array) $cleaner['services'], true );
	}

	/* ---------------------------------------------------------------------
	 * Free / busy
	 * ------------------------------------------------------------------ */

	/** Job shape used by the checks: date, start, end (Unix), district, service, booking_id, seats. */
	public static function job( array $booking ): array {
		$start = Db::timestamp( $booking['start_at'] ?? null ) ?: ( $booking['job_date'] ? Slots::start_timestamp( $booking['job_date'], (string) $booking['slot'] ) : 0 );
		$end   = Db::timestamp( $booking['end_at'] ?? null ) ?: $start + (int) round( max( 0.5, (float) $booking['hours'] ) * HOUR_IN_SECONDS );
		return [
			'booking_id' => (int) ( $booking['id'] ?? 0 ),
			'date'       => (string) $booking['job_date'],
			'start'      => $start,
			'end'        => max( $end, $start + 1800 ),
			'district'   => (string) ( $booking['district'] ?: Postcodes::district( (string) $booking['postcode'] ) ),
			'service'    => (string) $booking['service_slug'],
			'seats'      => max( 1, (int) $booking['cleaners'] ),
		];
	}

	/** Does the job fit the cleaner's weekly hours and avoid their time off? */
	public static function works( array $cleaner, int $start, int $end ): bool {
		$rules = self::rules( (int) $cleaner['id'] );
		$date  = wp_date( 'Y-m-d', $start );
		foreach ( $rules['off'] as $off ) {
			if ( $date < $off['from'] || $date > $off['to'] ) {
				continue;
			}
			if ( ! $off['start'] ) {
				return false;
			}
			if ( self::ts( $date, $off['start'] ) < $end && self::ts( $date, $off['end'] ?: '23:59' ) > $start ) {
				return false;
			}
		}
		if ( ! $rules['weekly'] ) {
			return true;
		}
		foreach ( $rules['weekly'][ (int) wp_date( 'N', $start ) ] ?? [] as [ $from, $to ] ) {
			if ( self::ts( $date, $from ) <= $start && self::ts( $date, $to ) >= $end ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Jobs cleaners are on between two dates (assigned, accepted or mid-accept; cancelled bookings ignored).
	 *
	 * @return array<int, array<int, array{booking_id: int, date: string, start: int, end: int}>> cleaner_id => jobs
	 */
	public static function commitments( string $from, string $to, int $exclude_booking = 0 ): array {
		global $wpdb;
		$offer_statuses   = array_merge( Status::on_job(), [ 'accepting' ] );
		$booking_statuses = Status::active_booking();
		$rows             = $wpdb->get_results( $wpdb->prepare(
			'SELECT o.cleaner_id, b.id, b.job_date, b.slot, b.hours, b.start_at, b.end_at FROM ' . Schema::table( 'job_offers' ) . ' o JOIN ' . Schema::table( 'bookings' ) . ' b ON b.id = o.booking_id'
			. ' WHERE o.status IN (' . implode( ',', array_fill( 0, count( $offer_statuses ), '%s' ) ) . ')'
			. ' AND b.status IN (' . implode( ',', array_fill( 0, count( $booking_statuses ), '%s' ) ) . ')'
			. ' AND b.job_date BETWEEN %s AND %s AND b.id != %d',
			array_merge( $offer_statuses, $booking_statuses, [ $from, $to, $exclude_booking ] )
		), ARRAY_A );
		$out = [];
		foreach ( $rows ?: [] as $row ) {
			$job                               = self::job( $row + [ 'district' => '', 'postcode' => '', 'service_slug' => '', 'cleaners' => 1 ] );
			$out[ (int) $row['cleaner_id'] ][] = [ 'booking_id' => (int) $row['id'], 'date' => $row['job_date'], 'start' => $job['start'], 'end' => $job['end'] ];
		}
		return $out;
	}

	/** Free for this job given the cleaner's other jobs ($busy from commitments()). */
	public static function is_free( array $cleaner, array $job, array $busy ): bool {
		if ( ! self::works( $cleaner, $job['start'], $job['end'] ) ) {
			return false;
		}
		$buffer = (int) Dispatch::opt( 'buffer_minutes' ) * MINUTE_IN_SECONDS;
		$today  = 0;
		foreach ( $busy as $other ) {
			if ( (int) $other['booking_id'] === (int) $job['booking_id'] && $job['booking_id'] ) {
				continue;
			}
			if ( $other['date'] === $job['date'] ) {
				++$today;
			}
			if ( $other['start'] < $job['end'] + $buffer && $other['end'] + $buffer > $job['start'] ) {
				return false;
			}
		}
		return $today < max( 1, (int) $cleaner['max_jobs_per_day'] );
	}

	/**
	 * Active cleaners who cover the area, do the service and are free. Each gets `day_jobs` and `week_jobs`.
	 *
	 * @param int[] $exclude Cleaner ids to leave out.
	 */
	public static function eligible( array $job, array $exclude = [] ): array {
		$cleaners = array_filter( self::pool(), fn( $c ) => ! in_array( (int) $c['id'], $exclude, true ) && self::covers( $c, $job['district'] ) && self::does( $c, $job['service'] ) );
		if ( ! $cleaners || ! $job['date'] || ! $job['start'] ) {
			return [];
		}
		self::prime( array_column( $cleaners, 'id' ) );
		$week_end = wp_date( 'Y-m-d', $job['start'] + 6 * DAY_IN_SECONDS );
		$busy     = self::commitments( $job['date'], $week_end, $job['booking_id'] );
		$out      = [];
		foreach ( $cleaners as $c ) {
			$mine = $busy[ $c['id'] ] ?? [];
			if ( ! self::is_free( $c, $job, array_filter( $mine, fn( $b ) => $b['date'] === $job['date'] ) ) ) {
				continue;
			}
			$c['day_jobs']  = count( array_filter( $mine, fn( $b ) => $b['date'] === $job['date'] ) );
			$c['week_jobs'] = count( $mine );
			$out[]          = $c;
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Slots
	 * ------------------------------------------------------------------ */

	public static function has_cleaners(): bool {
		return self::$has_cleaners ??= (bool) self::pool();
	}

	/** Active cleaners that dispatch and slot checks consider (filterable, e.g. to pause a cleaner without deactivating them). */
	public static function pool(): array {
		return array_values( (array) apply_filters( 'cc_bookings_dispatch_cleaners', Cleaners::active() ) );
	}

	public static function capture_context( $result, $server, $request ) {
		if ( $request instanceof \WP_REST_Request && preg_match( '#^/cc/v1/(slots|quotes|price)$#', $request->get_route() ) ) {
			self::$context = [
				'service'  => sanitize_key( (string) $request->get_param( 'service' ) ),
				'district' => Postcodes::district( (string) $request->get_param( 'postcode' ) ),
			];
		}
		return $result;
	}

	/** For CLI/tests: price slots as if for this service and postcode. */
	public static function set_context( string $service, string $postcode ): void {
		self::$context = [ 'service' => sanitize_key( $service ), 'district' => Postcodes::district( $postcode ) ];
		self::$grid    = null;
	}

	/** `cc_bookings_slot_available` handler. */
	public static function slot_available( $ok, $date, $slot, $ctx ) {
		$source = (string) Dispatch::opt( 'slot_source' );
		if ( 'capacity' === $source || ! self::has_cleaners() ) {
			return $ok;
		}
		$start = Slots::start_timestamp( (string) $date, (string) $slot );
		$base  = Slots::is_working_day( (string) $date ) && $start >= time() + (int) Settings::get( 'booking.min_notice_hours', 48 ) * HOUR_IN_SECONDS;
		if ( ! $base || ( 'both' === $source && ! $ok ) ) {
			return false;
		}
		$team = max( 1, (int) ( $ctx['cleaners'] ?? 1 ) );
		return self::spare( (string) $date, $start, (float) ( $ctx['hours'] ?? 0 ), (int) ( $ctx['exclude_quote'] ?? 0 ), (int) ( $ctx['exclude_booking'] ?? 0 ) ) >= $team;
	}

	/** Free cleaners for a window, minus cleaners still owed to unstaffed bookings and live slot holds that overlap it. */
	public static function spare( string $date, int $start, float $hours, int $exclude_quote = 0, int $exclude_booking = 0 ): int {
		$job  = [
			'booking_id' => $exclude_booking,
			'date'       => $date,
			'start'      => $start,
			'end'        => $start + (int) round( max( 0.5, $hours ) * HOUR_IN_SECONDS ),
		] + self::job_context( $exclude_quote );
		$data = self::grid( $date, $exclude_quote, $job['booking_id'] );

		$free = 0;
		foreach ( $data['cleaners'] as $c ) {
			if ( self::covers( $c, $job['district'] ) && self::does( $c, $job['service'] ) && self::is_free( $c, $job, array_filter( $data['busy'][ $c['id'] ] ?? [], fn( $b ) => $b['date'] === $date ) ) ) {
				++$free;
			}
		}
		$buffer = (int) Dispatch::opt( 'buffer_minutes' ) * MINUTE_IN_SECONDS;
		foreach ( $data['demand'][ $date ] ?? [] as $d ) {
			if ( $d['start'] < $job['end'] + $buffer && $d['end'] + $buffer > $job['start'] ) {
				$free -= $d['need'];
			}
		}
		return $free;
	}

	/** Service/district for the job being checked: from the quote when known, else the REST request. */
	private static function job_context( int $quote_id ): array {
		if ( $quote_id && ( $quote = Quotes::get( $quote_id ) ) ) {
			return [ 'service' => (string) $quote['service_slug'], 'district' => (string) ( $quote['district'] ?: Postcodes::district( (string) $quote['postcode'] ) ), 'quote_booking' => (int) $quote['booking_id'] ];
		}
		return [ 'service' => (string) ( self::$context['service'] ?? '' ), 'district' => (string) ( self::$context['district'] ?? '' ) ];
	}

	/** Per-request cache of cleaners, their jobs and open demand across the booking horizon. */
	private static function grid( string $date, int $exclude_quote, int $exclude_booking ): array {
		global $wpdb;
		$key = $exclude_quote . ':' . $exclude_booking;
		if ( isset( self::$grid[ $key ] ) && $date >= self::$grid[ $key ]['from'] && $date <= self::$grid[ $key ]['to'] ) {
			return self::$grid[ $key ];
		}
		if ( $exclude_quote && ! $exclude_booking && ( $quote = Quotes::get( $exclude_quote ) ) ) {
			$exclude_booking = (int) $quote['booking_id'];
		}
		$from     = min( $date, wp_date( 'Y-m-d' ) );
		$to       = max( $date, wp_date( 'Y-m-d', time() + ( (int) Settings::get( 'booking.max_days_ahead', 60 ) + 2 ) * DAY_IN_SECONDS ) );
		$cleaners = self::pool();
		self::prime( array_column( $cleaners, 'id' ) );

		$demand   = [];
		$statuses = array_diff( Status::active_booking(), [ 'completed' ] );
		$on_job   = array_merge( Status::on_job(), [ 'accepting' ] );
		$rows     = $wpdb->get_results( $wpdb->prepare(
			'SELECT b.*, (SELECT COUNT(*) FROM ' . Schema::table( 'job_offers' ) . ' o WHERE o.booking_id = b.id AND o.status IN (' . implode( ',', array_fill( 0, count( $on_job ), '%s' ) ) . ')) AS crew'
			. ' FROM ' . Schema::table( 'bookings' ) . ' b WHERE b.job_date BETWEEN %s AND %s AND b.id != %d AND b.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')',
			array_merge( $on_job, [ $from, $to, $exclude_booking ], array_values( $statuses ) )
		), ARRAY_A );
		foreach ( $rows ?: [] as $row ) {
			$need = (int) $row['cleaners'] - (int) $row['crew'];
			if ( $need > 0 ) {
				$job                           = self::job( Db::decode( $row ) );
				$demand[ $row['job_date'] ][] = [ 'start' => $job['start'], 'end' => $job['end'], 'need' => $need ];
			}
		}
		$holds = $wpdb->get_results( $wpdb->prepare(
			'SELECT job_date, slot, hours, cleaners FROM ' . Schema::table( 'quotes' ) . ' WHERE job_date BETWEEN %s AND %s AND hold_expires_at > %s AND booking_id = 0 AND id != %d',
			$from, $to, Db::now(), $exclude_quote
		), ARRAY_A );
		foreach ( $holds ?: [] as $row ) {
			$start                         = Slots::start_timestamp( $row['job_date'], (string) $row['slot'] );
			$demand[ $row['job_date'] ][] = [ 'start' => $start, 'end' => $start + (int) round( max( 0.5, (float) $row['hours'] ) * HOUR_IN_SECONDS ), 'need' => max( 1, (int) $row['cleaners'] ) ];
		}

		self::$grid[ $key ] = [
			'from'     => $from,
			'to'       => $to,
			'cleaners' => $cleaners,
			'busy'     => self::commitments( $from, $to, $exclude_booking ),
			'demand'   => $demand,
		];
		return self::$grid[ $key ];
	}

	/* ---------------------------------------------------------------------
	 * Admin profile save (fields from Admin\Cleaner_Profiles_Screen, posted to the core cleaner_save action)
	 * ------------------------------------------------------------------ */

	public static function save_profile( $cleaner_id, $data = [] ): void {
		// phpcs:disable WordPress.Security.NonceVerification -- runs inside Admin::handle() after check_admin_referer().
		if ( empty( $_POST['cc_dispatch_profile'] ) || ! Plugin::can_manage() ) {
			return;
		}
		$post = wp_unslash( $_POST );
		// phpcs:enable
		$cleaner_id = (int) $cleaner_id;

		Db::update( 'cleaners', $cleaner_id, [ 'areas' => array_values( array_filter( array_map( 'intval', (array) ( $post['areas'] ?? [] ) ) ) ) ] );

		if ( 'any' === ( $post['hours_mode'] ?? 'set' ) ) {
			self::set_week( $cleaner_id, null );
		} else {
			$week = [];
			foreach ( (array) ( $post['week'] ?? [] ) as $day => $row ) {
				if ( ! empty( $row['on'] ) ) {
					$week[ (int) $day ] = [ [ (string) ( $row['start'] ?? '' ), (string) ( $row['end'] ?? '' ) ] ];
				}
			}
			self::set_week( $cleaner_id, $week );
		}

		$keep  = array_map( 'intval', (array) ( $post['off_keep'] ?? [] ) );
		$shown = array_map( 'intval', (array) ( $post['off_shown'] ?? [] ) );
		foreach ( self::rules( $cleaner_id )['off'] as $off ) {
			if ( in_array( $off['id'], $shown, true ) && ! in_array( $off['id'], $keep, true ) ) {
				Db::delete( 'cleaner_availability', [ 'id' => $off['id'], 'cleaner_id' => $cleaner_id ] );
			}
		}
		if ( ! empty( $post['off_from'] ) ) {
			self::add_time_off( $cleaner_id, (string) $post['off_from'], (string) ( $post['off_to'] ?? '' ), (string) ( $post['off_note'] ?? '' ), (string) ( $post['off_start'] ?? '' ), (string) ( $post['off_end'] ?? '' ) );
		}
		self::flush();
		Events::log( 'cleaner', $cleaner_id, 'availability', 'Hours: ' . self::week_label( $cleaner_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private static function ts( string $date, string $time ): int {
		$dt = date_create_immutable( $date . ' ' . $time, wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	private static function time( string $raw ): string {
		return preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)/', trim( $raw ), $m ) ? sprintf( '%02d:%s', $m[1], $m[2] ) : '';
	}

	private static function date( string $raw ): string {
		$raw = trim( $raw );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
	}
}

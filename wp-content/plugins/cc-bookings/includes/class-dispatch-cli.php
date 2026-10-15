<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatch tools: `wp cc-bookings dispatch <command>`.
 */
final class Dispatch_Cli {
	public static function register(): void {
		\WP_CLI::add_command( 'cc-bookings dispatch', self::class );
	}

	private static function booking( string $ref ): array {
		$booking = ctype_digit( $ref ) ? Bookings::get( (int) $ref ) : Bookings::by_ref( $ref );
		if ( ! $booking ) {
			\WP_CLI::error( 'Booking not found: ' . $ref );
		}
		return $booking;
	}

	private static function out( array $result ): void {
		\WP_CLI::log( sprintf( '%s: %s', $result['code'] ?? ( $result['ok'] ? 'ok' : 'failed' ), $result['message'] ) );
	}

	/**
	 * Dispatch state, crew and offers (with job links) for a booking.
	 *
	 * ## OPTIONS
	 *
	 * <booking>
	 * : Booking ref (B-12345) or id.
	 *
	 * [--links]
	 * : Include each cleaner's job link.
	 */
	public function status( array $args, array $assoc ): void {
		$b     = self::booking( $args[0] );
		$state = Dispatch::state( (int) $b['id'] );
		\WP_CLI::log( sprintf( '%s %s · %s · %s · %d cleaner(s) · status %s', $b['ref'], $b['service_name'], Bookings::start_label( $b ), $b['district'], $b['cleaners'], $b['status'] ) );
		\WP_CLI::log( $state ? sprintf( 'Dispatch: %s, round %d, filled %d/%d, deadline %s, bonus %s', $state['status'], $state['round'], $state['filled'], $state['seats'], $state['deadline'] ?: '-', Money::format( (int) $state['bonus_pence'] ) ) : 'Dispatch: not run' );
		$next = $state ? Queue::next( Dispatch::TIMEOUT_HOOK, [ (int) $b['id'], (int) $state['round'] ] ) : 0;
		if ( $next ) {
			\WP_CLI::log( 'Timeout queued for ' . wp_date( 'Y-m-d H:i', $next ) );
		}
		$rows = [];
		foreach ( Db::all( 'job_offers', [ 'booking_id' => (int) $b['id'] ], 'id ASC' ) as $o ) {
			$c      = Cleaners::get( (int) $o['cleaner_id'] );
			$row    = [ 'offer' => $o['id'], 'cleaner' => $c['name'] ?? '?', 'type' => $c['type'] ?? '', 'status' => $o['status'], 'source' => $o['source'], 'role' => $o['role'], 'pay' => Money::format( (int) $o['pay_pence'] ) ];
			if ( isset( $assoc['links'] ) ) {
				$row['link'] = Job_Links::offer_url( (int) $o['id'] );
			}
			$rows[] = $row;
		}
		$rows ? \WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) ) : \WP_CLI::log( 'No offers.' );
	}

	/**
	 * Run dispatch for a booking now.
	 *
	 * ## OPTIONS
	 *
	 * <booking>
	 * : Booking ref or id.
	 *
	 * [--bonus=<pounds>]
	 * : Re-offer with this bonus (includes cleaners who declined).
	 */
	public function run( array $args, array $assoc ): void {
		$b      = self::booking( $args[0] );
		$opts   = isset( $assoc['bonus'] ) ? [ 'bonus' => (int) Money::parse( $assoc['bonus'] ), 'include_declined' => true ] : [];
		$result = Dispatch::run( (int) $b['id'], $opts ? 'reoffer' : 'cli', $opts );
		$result['ok'] ? \WP_CLI::success( $result['message'] ) : \WP_CLI::warning( $result['message'] );
	}

	/**
	 * Run a booking's queued offer timeout now (escalates if places are still open).
	 *
	 * ## OPTIONS
	 *
	 * <booking>
	 * : Booking ref or id.
	 */
	public function timeout( array $args ): void {
		$b     = self::booking( $args[0] );
		$state = Dispatch::state( (int) $b['id'] );
		if ( ! $state ) {
			\WP_CLI::error( 'Not dispatched.' );
		}
		$round = (int) $state['round'];
		Queue::cancel( Dispatch::TIMEOUT_HOOK, [ (int) $b['id'], $round ] );
		do_action( Dispatch::TIMEOUT_HOOK, (int) $b['id'], $round );
		$state = Dispatch::state( (int) $b['id'] );
		\WP_CLI::success( sprintf( 'Timeout for round %d run; dispatch is now "%s".', $round, $state['status'] ) );
	}

	/**
	 * Act as the cleaner on an offer: accept, decline, drop (can't make it) or complete.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : accept | decline | drop | complete
	 *
	 * <offer>
	 * : Offer id.
	 *
	 * [--reason=<text>]
	 * : For drop.
	 */
	public function act( array $args, array $assoc ): void {
		[ $action, $offer ] = [ $args[0], (int) $args[1] ];
		$result             = match ( $action ) {
			'accept'   => Dispatch::accept( $offer ),
			'decline'  => Dispatch::decline( $offer ),
			'drop'     => Dispatch::drop( $offer, (string) ( $assoc['reason'] ?? '' ) ),
			'complete' => Dispatch::complete( $offer ),
			default    => null,
		};
		if ( ! $result ) {
			\WP_CLI::error( 'Action must be accept, decline, drop or complete.' );
		}
		self::out( $result );
	}

	/**
	 * Bookable slots from cleaner calendars for a job.
	 *
	 * ## OPTIONS
	 *
	 * [--service=<slug>]
	 * : Service slug.
	 *
	 * [--postcode=<postcode>]
	 * : Postcode or district.
	 *
	 * [--hours=<hours>]
	 * : Job length per cleaner. Default 3.
	 *
	 * [--cleaners=<n>]
	 * : Team size. Default 1.
	 *
	 * [--days=<n>]
	 * : Dates to show. Default 10.
	 */
	public function slots( array $args, array $assoc ): void {
		Availability::set_context( (string) ( $assoc['service'] ?? '' ), (string) ( $assoc['postcode'] ?? '' ) );
		$hours = (float) ( $assoc['hours'] ?? 3 );
		$team  = (int) ( $assoc['cleaners'] ?? 1 );
		$dates = array_slice( Slots::dates(), 0, (int) ( $assoc['days'] ?? 10 ) );
		$grid  = Slots::availability( $hours, $team, 0, $dates );
		$rows  = [];
		foreach ( $grid as $date => $slots ) {
			$row = [ 'date' => Slots::date_label( $date, 'D Y-m-d' ) ];
			foreach ( $slots as $slot => $ok ) {
				$row[ $slot ] = $ok ? sprintf( 'yes (%d spare)', Availability::spare( $date, Slots::start_timestamp( $date, $slot ), $hours ) ) : 'no';
			}
			$rows[] = $row;
		}
		\WP_CLI::log( sprintf( 'Source: %s; active cleaners: %d', Availability::has_cleaners() && 'capacity' !== Dispatch::opt( 'slot_source' ) ? Dispatch::opt( 'slot_source' ) : 'capacity', count( Cleaners::active() ) ) );
		$rows ? \WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) ) : \WP_CLI::log( 'No dates.' );
	}

	/**
	 * Print a cleaner's "My jobs" link.
	 *
	 * ## OPTIONS
	 *
	 * <cleaner>
	 * : Cleaner id.
	 *
	 * @subcommand cleaner-link
	 */
	public function cleaner_link( array $args ): void {
		$url = Job_Links::cleaner_url( (int) $args[0] );
		$url ? \WP_CLI::log( $url ) : \WP_CLI::error( 'Cleaner not found.' );
	}

	/**
	 * Recent texts (sent, logged or failed).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Default 20.
	 *
	 * @subcommand sms-log
	 */
	public function sms_log( array $args, array $assoc ): void {
		$rows = Db::all( 'events', [ 'event' => [ 'sms_sent', 'sms_skipped', 'sms_logged', 'sms_failed' ] ], 'id DESC', (int) ( $assoc['limit'] ?? 20 ) );
		\WP_CLI\Utils\format_items( 'table', array_map( fn( $e ) => [
			'when'   => $e['created_at'],
			'object' => $e['object_type'] . ' ' . $e['object_id'],
			'status' => str_replace( 'sms_', '', $e['event'] ),
			'to'     => $e['data']['to'] ?? '',
			'text'   => $e['message'],
		], $rows ), [ 'when', 'object', 'status', 'to', 'text' ] );
	}

	/**
	 * Delete phase 2 test data only: cleaners whose name starts "P2 Test" and quotes/bookings whose customer
	 * name starts "P2 Test", with their offers, availability, dispatch rows, payments, events and photos.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @subcommand purge-p2-test
	 */
	public function purge_p2_test( array $args, array $assoc ): void {
		global $wpdb;
		$like     = $wpdb->esc_like( 'P2 Test' ) . '%';
		$cleaners = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'cleaners' ) . ' WHERE name LIKE %s', $like ) ) );
		$bookings = array_map( [ Db::class, 'decode' ], $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE customer_name LIKE %s', $like ), ARRAY_A ) ?: [] );
		$quotes   = array_map( [ Db::class, 'decode' ], $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'quotes' ) . ' WHERE customer_name LIKE %s', $like ), ARRAY_A ) ?: [] );
		if ( ! $cleaners && ! $bookings && ! $quotes ) {
			\WP_CLI::success( 'No P2 test data.' );
			return;
		}
		\WP_CLI::confirm( sprintf( 'Delete %d P2 test cleaner(s), %d booking(s) and %d quote(s)?', count( $cleaners ), count( $bookings ), count( $quotes ) ), $assoc );

		$booking_ids = array_map( 'intval', array_column( $bookings, 'id' ) );
		$quote_ids   = array_map( 'intval', array_column( $quotes, 'id' ) );
		foreach ( $bookings as $b ) {
			Files::delete_for( Dispatch::photo_folder( $b ) );
			$state = Dispatch::state( (int) $b['id'] );
			for ( $round = 0; $state && $round <= (int) $state['round']; $round++ ) {
				Queue::cancel( Dispatch::TIMEOUT_HOOK, [ (int) $b['id'], $round ] );
			}
			foreach ( [ 'confirmed', 'retry', 'queued', 'dropped', 'rescheduled', 'admin', 'cli', 'reoffer' ] as $reason ) {
				Queue::cancel( Dispatch::RUN_HOOK, [ (int) $b['id'], $reason ] );
			}
		}
		foreach ( $quotes as $q ) {
			Files::delete_for( $q['ref'] );
			Queue::cancel( 'cc_bookings_quote_followup', [ (int) $q['id'], 'reminder' ] );
			Queue::cancel( 'cc_bookings_quote_followup', [ (int) $q['id'], 'expiring' ] );
			Queue::cancel( 'cc_bookings_quote_expire', [ (int) $q['id'] ] );
		}
		if ( $booking_ids ) {
			Db::delete( 'booking_items', [ 'booking_id' => $booking_ids ] );
			Db::delete( 'job_offers', [ 'booking_id' => $booking_ids ] );
			Db::delete( 'payments', [ 'booking_id' => $booking_ids ] );
			Db::delete( 'dispatch', [ 'booking_id' => $booking_ids ] );
			Db::delete( 'events', [ 'object_type' => 'booking', 'object_id' => $booking_ids ] );
			Db::delete( 'bookings', [ 'id' => $booking_ids ] );
		}
		if ( $quote_ids ) {
			Db::delete( 'payments', [ 'quote_id' => $quote_ids ] );
			Db::delete( 'events', [ 'object_type' => 'quote', 'object_id' => $quote_ids ] );
			Db::delete( 'quotes', [ 'id' => $quote_ids ] );
		}
		if ( $cleaners ) {
			Db::delete( 'job_offers', [ 'cleaner_id' => $cleaners ] );
			Db::delete( 'cleaner_availability', [ 'cleaner_id' => $cleaners ] );
			Db::delete( 'events', [ 'object_type' => 'cleaner', 'object_id' => $cleaners ] );
			Db::delete( 'cleaners', [ 'id' => $cleaners ] );
		}
		\WP_CLI::success( sprintf( 'Deleted %d cleaner(s), %d booking(s), %d quote(s).', count( $cleaners ), count( $booking_ids ), count( $quote_ids ) ) );
	}
}

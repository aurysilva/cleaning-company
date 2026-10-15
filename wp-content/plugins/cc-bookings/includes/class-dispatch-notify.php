<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatch emails (editable under Bookings > Settings > Emails) and texts (Bookings > Dispatch).
 * Replaces Notify's cleaner_assigned listener so every cleaner email carries the signed job link, and the
 * customer's phone number stays off emails (the job page shows it from 24 hours before).
 */
final class Dispatch_Notify {
	/** Suppress "taken off the job" messages while dispatch moves people itself (e.g. a reschedule). */
	public static bool $quiet = false;

	const SMS = [
		'offer'    => 'Job offer: {service}, {district}, {date} {slot}, {length}. Pay {pay}. First to accept gets it: {url}',
		'assigned' => 'New job {ref}: {service}, {date} {slot}, {district}. Details: {url}',
		'accepted' => 'Confirmed - job {ref} on {date} {slot} is yours. Details: {url}',
		'removed'  => 'Job {ref} on {date}: {reason} Questions? Call {phone}',
		'alert'    => '{brand} URGENT: {subject}',
	];

	public static function init(): void {
		add_filter( 'cc_bookings_email_defaults', [ self::class, 'templates' ] );
		remove_action( 'cc_booking_assigned', [ Notify::class, 'booking_assigned' ], 10 );
		add_action( 'cc_booking_assigned', [ self::class, 'assigned' ], 10, 3 );
		add_action( 'cc_booking_unassigned', [ self::class, 'unassigned' ], 10, 3 );
		add_action( 'cc_bookings_admin_alert', [ self::class, 'alert_sms' ], 20, 3 );
	}

	public static function templates( array $templates ): array {
		$templates['cleaner_assigned'] = [
			'label'      => 'Job assigned (cleaner)',
			'to'         => 'cleaner',
			'subject'    => 'New job {ref}: {service} on {date_short}',
			'body'       => "Hi {cleaner_first_name},\n\nYou've been given this job.\n\n{job_details}\n\nOpen the job page for directions, access notes and the customer's number (shown from 24 hours before). If you can't make it, tap \"Can't make it\" on the job page straight away, or call the office on {phone}.",
			'button'     => 'Open the job',
			'button_url' => 'job_url',
		];
		return array_merge( $templates, [
			'job_offer'          => [
				'label'      => 'Job offer (self-employed cleaner)',
				'to'         => 'cleaner',
				'subject'    => 'Job offer: {service} in {district} on {date_short}',
				'body'       => "Hi {cleaner_first_name},\n\nWe have a job available:\n\n{job_details}\n\nIt goes to the first {places} to accept, so tap the button if you'd like it. The full address is shown once you've accepted. Offer open until {deadline}.",
				'button'     => 'View and accept',
				'button_url' => 'job_url',
			],
			'job_accepted'       => [
				'label'      => 'Offer accepted — confirmation (cleaner)',
				'to'         => 'cleaner',
				'subject'    => 'Confirmed: job {ref} on {date_short} is yours',
				'body'       => "Hi {cleaner_first_name},\n\nThanks — the job is yours.\n\n{job_details}\n\nThe job page has the access notes and the customer's number (shown from 24 hours before). If anything changes, tap \"Can't make it\" on the job page straight away.",
				'button'     => 'Open the job',
				'button_url' => 'job_url',
			],
			'job_taken'          => [
				'label'   => 'Offer taken by someone else (cleaner)',
				'to'      => 'cleaner',
				'subject' => 'Job {district} on {date_short} has been taken',
				'body'    => "Hi {cleaner_first_name},\n\nThanks for your interest — the {service} job in {district} on {date} has now been taken. We'll send you the next one.",
			],
			'job_removed'        => [
				'label'      => 'Taken off a job (cleaner)',
				'to'         => 'cleaner',
				'subject'    => 'Change to job {ref} on {date_short}',
				'body'       => "Hi {cleaner_first_name},\n\n{reason}\n\nJob: {service} on {date}.\n\nIf you have any questions, call the office on {phone}.",
				'button'     => 'My upcoming jobs',
				'button_url' => 'jobs_url',
			],
			'dispatch_admin'     => [
				'label'      => 'Cleaner assigned or accepted (admin)',
				'to'         => 'admin',
				'subject'    => '{cleaner_name} {action} {ref} ({date_short})',
				'body'       => "{cleaner_name} {action} booking {ref}: {service}, {date}, {district}.\n\nCrew: {crew} ({filled} of {places}).",
				'button'     => 'Open booking',
				'button_url' => 'admin_url',
			],
			'cleaner_customer'   => [
				'label'      => 'Your cleaner is… (customer, optional)',
				'to'         => 'customer',
				'subject'    => 'Your cleaner for {date_short}',
				'body'       => "Hi {first_name},\n\nGood news — {crew} will be cleaning for you on {date}.\n\nIf anything changes, reply to this email or call {phone}.",
				'button'     => 'View your booking',
				'button_url' => 'booking_url',
			],
			'cleaner_jobs_link'  => [
				'label'      => '"My jobs" link (cleaner)',
				'to'         => 'cleaner',
				'subject'    => 'Your {brand} jobs page',
				'body'       => "Hi {cleaner_first_name},\n\nHere's your personal link to see your upcoming jobs and open offers. Keep it private — anyone with the link can see your jobs.",
				'button'     => 'My upcoming jobs',
				'button_url' => 'jobs_url',
			],
		] );
	}

	/** SMS wording, editable in Bookings > Dispatch. */
	public static function sms_text( string $key, array $vars ): string {
		$custom = trim( (string) ( ( (array) Dispatch::opt( 'sms' ) )[ $key ] ?? '' ) );
		return Sms::fill( $custom ?: self::SMS[ $key ], $vars + Notify::site_vars() );
	}

	/* ---------------------------------------------------------------------
	 * Variables
	 * ------------------------------------------------------------------ */

	/** Job variables for cleaner messages. $full adds the address and access notes (never the phone). */
	public static function job_vars( array $booking, array $offer, array $cleaner, bool $full ): array {
		$state   = Dispatch::state( (int) $booking['id'] );
		$service = Catalog::get( (string) $booking['service_slug'] );
		$length  = Pricing::duration_label( (float) $booking['hours'], 1 );
		$lines   = [
			[ 'Job', $booking['ref'] . ' — ' . $booking['service_name'] ],
			[ 'When', Bookings::start_label( $booking ) ],
			[ 'Length', $length ],
			[ 'Team', (int) $booking['cleaners'] > 1 ? sprintf( '%d cleaners', $booking['cleaners'] ) : 'Just you' ],
			$full ? [ 'Address', Bookings::full_address( $booking ) ] : [ 'Area', $booking['district'] ],
			[ 'Pay', (int) $offer['pay_pence'] ? Money::format( (int) $offer['pay_pence'] ) : '' ],
		];
		if ( $full ) {
			$lines[] = [ 'Customer', Notify::first_name( (string) $booking['customer_name'] ) ];
			$lines[] = [ 'Access notes', (string) $booking['access_notes'] ];
		}
		if ( $service ) {
			$lines = array_merge( $lines, Catalog::summary( $service, (array) $booking['answers'] ) );
			if ( $addons = Catalog::addon_names( $service, (array) $booking['answers'] ) ) {
				$lines[] = [ 'Extras', implode( ', ', $addons ) ];
			}
		}
		$slot = (string) $booking['slot'];
		return [
			'ref'                => $booking['ref'],
			'service'            => $booking['service_name'],
			'date'               => $booking['job_date'] ? Slots::date_label( $booking['job_date'], 'D j M' ) : '',
			'date_short'         => $booking['job_date'] ? Slots::date_label( $booking['job_date'], 'D j M' ) : '',
			'slot'               => $slot ? strtolower( Slots::label( $slot ) ) : '',
			'district'           => $booking['district'],
			'length'             => strtolower( $length ),
			'pay'                => Money::format( (int) $offer['pay_pence'] ),
			'places'             => (int) $booking['cleaners'] > 1 ? (int) $booking['cleaners'] . ' cleaners' : 'cleaner',
			'deadline'           => $offer['expires_at'] ? wp_date( 'D j M, H:i', Db::timestamp( $offer['expires_at'] ) ) : '',
			'cleaner_first_name' => Notify::first_name( (string) $cleaner['name'] ),
			'cleaner_name'       => $cleaner['name'],
			'job_details'        => Notify::details_html( $lines ),
			'job_url'            => Job_Links::offer_url( (int) $offer['id'] ),
			'jobs_url'           => Job_Links::cleaner_url( (int) $cleaner['id'] ),
			'url'                => Job_Links::offer_url( (int) $offer['id'] ),
			'filled'             => $state ? (int) $state['filled'] : count( Bookings::crew( (int) $booking['id'] ) ),
		];
	}

	private static function load( int $offer_id ): ?array {
		$offer   = Db::get( 'job_offers', $offer_id );
		$booking = $offer ? Bookings::get( (int) $offer['booking_id'] ) : null;
		$cleaner = $offer ? Cleaners::get( (int) $offer['cleaner_id'] ) : null;
		return $offer && $booking && $cleaner ? [ $offer, $booking, $cleaner ] : null;
	}

	/* ---------------------------------------------------------------------
	 * Messages
	 * ------------------------------------------------------------------ */

	public static function offer( int $offer_id ): void {
		$loaded = self::load( $offer_id );
		if ( ! $loaded ) {
			return;
		}
		[ $offer, $booking, $cleaner ] = $loaded;
		$vars = self::job_vars( $booking, $offer, $cleaner, false );
		Notify::send( 'job_offer', (string) $cleaner['email'], $vars, [ 'booking', (int) $booking['id'] ] );
		Sms::send( (string) $cleaner['phone'], self::sms_text( 'offer', $vars ), [ 'booking', (int) $booking['id'] ] );
	}

	public static function taken( int $offer_id ): void {
		$loaded = self::load( $offer_id );
		if ( ! $loaded || ! Dispatch::opt( 'notify_taken' ) ) {
			return;
		}
		[ $offer, $booking, $cleaner ] = $loaded;
		Notify::send( 'job_taken', (string) $cleaner['email'], self::job_vars( $booking, $offer, $cleaner, false ), [ 'booking', (int) $booking['id'] ] );
	}

	public static function removed( int $booking_id, int $offer_id, string $reason ): void {
		$loaded = self::load( $offer_id );
		if ( ! $loaded ) {
			return;
		}
		[ $offer, $booking, $cleaner ] = $loaded;
		$vars = [ 'reason' => $reason ] + self::job_vars( $booking, $offer, $cleaner, false );
		Notify::send( 'job_removed', (string) $cleaner['email'], $vars, [ 'booking', $booking_id ] );
		Sms::send( (string) $cleaner['phone'], self::sms_text( 'removed', $vars ), [ 'booking', $booking_id ] );
	}

	public static function jobs_link( int $cleaner_id ): bool {
		$cleaner = Cleaners::get( $cleaner_id );
		if ( ! $cleaner ) {
			return false;
		}
		$url  = Job_Links::cleaner_url( $cleaner_id );
		$sent = Notify::send( 'cleaner_jobs_link', (string) $cleaner['email'], [ 'cleaner_first_name' => Notify::first_name( (string) $cleaner['name'] ), 'jobs_url' => $url ], [ 'cleaner', $cleaner_id ] );
		Sms::send( (string) $cleaner['phone'], 'Your ' . Settings::brand() . ' jobs page: ' . $url, [ 'cleaner', $cleaner_id ] );
		return $sent;
	}

	/** cc_booking_assigned: cleaner email + text with the job link; admin email for automatic assignments and accepted offers. */
	public static function assigned( $booking_id, $cleaner_id, $offer_id = 0 ): void {
		$loaded = self::load( (int) $offer_id );
		if ( ! $loaded ) {
			return;
		}
		[ $offer, $booking, $cleaner ] = $loaded;
		if ( ! (int) $offer['pay_pence'] && (int) $cleaner['pay_rate_pence'] ) {
			$offer['pay_pence'] = Dispatch::pay( $cleaner, $booking );
			Db::update( 'job_offers', (int) $offer['id'], [ 'pay_pence' => $offer['pay_pence'] ] );
		}
		$source = (string) $offer['source'];
		$vars   = self::job_vars( $booking, $offer, $cleaner, true );
		Notify::send( 'offer' === $source ? 'job_accepted' : 'cleaner_assigned', (string) $cleaner['email'], $vars, [ 'booking', (int) $booking['id'] ] );
		Sms::send( (string) $cleaner['phone'], self::sms_text( 'offer' === $source ? 'accepted' : 'assigned', $vars ), [ 'booking', (int) $booking['id'] ] );

		$crew = array_map( fn( $o ) => $o['cleaner']['name'] ?? '?', Bookings::crew( (int) $booking['id'] ) );
		if ( 'manual' !== $source ) {
			Notify::send( 'dispatch_admin', Settings::admin_email(), [
				'action'    => 'offer' === $source ? 'accepted' : 'was auto-assigned to',
				'crew'      => implode( ', ', $crew ),
				'filled'    => count( $crew ),
				'places'    => (int) $booking['cleaners'],
				'admin_url' => Bookings::admin_url( (int) $booking['id'] ),
			] + $vars, [ 'booking', (int) $booking['id'] ] );
		}
		if ( Dispatch::opt( 'notify_customer' ) && count( $crew ) >= (int) $booking['cleaners'] ) {
			$names = array_map( fn( $o ) => Notify::first_name( (string) ( $o['cleaner']['name'] ?? '' ) ), Bookings::crew( (int) $booking['id'] ) );
			Notify::send( 'cleaner_customer', (string) $booking['email'], [ 'crew' => implode( ' and ', array_filter( $names ) ), 'booking_url' => Bookings::link( $booking ) ] + Notify::booking_vars( $booking ), [ 'booking', (int) $booking['id'] ] );
		}
	}

	/** cc_booking_unassigned: tell the cleaner, unless they removed themselves or dispatch is moving them. */
	public static function unassigned( $booking_id, $cleaner_id, $offer_id = 0 ): void {
		$offer = Db::get( 'job_offers', (int) $offer_id );
		if ( self::$quiet || ! $offer || 'dropped' === $offer['status'] ) {
			return;
		}
		$booking = Bookings::get( (int) $booking_id );
		if ( $booking && ! in_array( $booking['status'], [ 'cancelled', 'completed' ], true ) ) {
			self::removed( (int) $booking_id, (int) $offer_id, 'The office has taken you off this job, so you don\'t need to go.' );
		}
	}

	/** Urgent admin alerts also go by text to the dispatch admin mobile (logged as failed if none is set). */
	public static function alert_sms( $subject, $message, $context = [] ): void {
		$to = (string) Dispatch::opt( 'admin_mobile' );
		if ( empty( $context['urgent'] ) ) {
			return;
		}
		$text = self::sms_text( 'alert', [ 'subject' => (string) $subject, 'message' => (string) $message ] );
		Sms::send( $to, $text, [ ! empty( $context['booking_id'] ) ? 'booking' : 'system', (int) ( $context['booking_id'] ?? 0 ) ] );
	}
}

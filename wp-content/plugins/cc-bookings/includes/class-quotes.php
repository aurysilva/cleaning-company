<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Quote lifecycle: create from the form (auto-priced or bespoke), send, view, follow-ups, expiry.
 * Hooks fired: cc_quote_created, cc_quote_sent, cc_quote_bespoke_requested, cc_quote_viewed,
 * cc_quote_expired, cc_quote_status_changed.
 */
final class Quotes {
	public static function init(): void {
		add_action( 'cc_bookings_quote_followup', [ self::class, 'followup' ], 10, 2 );
		add_action( 'cc_bookings_quote_expire', [ self::class, 'expire' ] );
	}

	public static function get( int $id ): ?array {
		return Db::get( 'quotes', $id );
	}

	public static function by_ref( string $ref ): ?array {
		return Db::find( 'quotes', [ 'ref' => strtoupper( sanitize_text_field( $ref ) ) ] );
	}

	public static function verify( string $ref, string $token ): ?array {
		$quote = self::by_ref( $ref );
		return $quote && Tokens::matches( $token, $quote ) ? $quote : null;
	}

	public static function url( array $quote, string $token, array $args = [] ): string {
		return add_query_arg( $args, home_url( '/quote/' . $quote['ref'] . '/' . $token . '/' ) );
	}

	/** A fresh link for emails; earlier links keep working. */
	public static function link( array $quote, array $args = [] ): string {
		return self::url( $quote, Tokens::issue( 'quotes', $quote['id'] ), $args );
	}

	public static function admin_url( int $id ): string {
		return admin_url( 'admin.php?page=cc-bookings-quotes&id=' . $id );
	}

	public static function service( array $quote ): ?array {
		return Catalog::get( $quote['service_slug'] );
	}

	public static function is_expired( array $quote ): bool {
		if ( 'expired' === $quote['status'] ) {
			return true;
		}
		return in_array( $quote['status'], Status::payable_quote(), true ) && $quote['expires_at'] && Db::timestamp( $quote['expires_at'] ) < time();
	}

	public static function is_payable( array $quote ): bool {
		return in_array( $quote['status'], Status::payable_quote(), true ) && ! self::is_expired( $quote ) && (int) $quote['total_pence'] > 0 && ! $quote['booking_id'];
	}

	/** The 14-day early-start request applies when the clean is within the cancellation period. */
	public static function needs_early_start( array $quote ): bool {
		$days = (int) Settings::get( 'quotes.early_start_days', 14 );
		return $days > 0 && $quote['job_date'] && Slots::start_timestamp( $quote['job_date'], $quote['slot'] ) < time() + $days * DAY_IN_SECONDS;
	}

	public static function is_test_customer( string $email, string $name ): bool {
		return (bool) preg_match( '/@example\.(com|org|net)$/i', $email ) || false !== stripos( $name, '[test]' );
	}

	/**
	 * Create a quote from a form submission. The price is always calculated here, never taken from the browser.
	 *
	 * @param array $input  Unslashed request params (service, postcode, answers, date, slot, contact fields…).
	 * @param array $files  Normalised uploads from Files::from_request().
	 * @return array{quote: array, url: string, bespoke: bool}|\WP_Error
	 */
	public static function create( array $input, array $files = [] ) {
		$service = Catalog::get( sanitize_key( (string) ( $input['service'] ?? '' ) ) );
		if ( ! $service ) {
			return new \WP_Error( 'invalid', 'Please choose a service.', [ 'status' => 400, 'errors' => [ 'service' => 'Please choose a service' ] ] );
		}

		[ $answers, $errors ] = Catalog::sanitize_answers( $service, is_array( $input['answers'] ?? null ) ? $input['answers'] : [] );

		$postcode = Postcodes::normalise( (string) ( $input['postcode'] ?? '' ) );
		$district = Postcodes::district( $postcode );
		$covered  = Postcodes::is_covered( $postcode );
		if ( ! Postcodes::is_full( $postcode ) ) {
			$errors['postcode'] = 'Please enter your full postcode, e.g. HU5 2AB';
		} elseif ( ! $covered && empty( $input['ask_anyway'] ) ) {
			$errors['postcode'] = sprintf( 'We don\'t usually cover %s. Tick "Ask us anyway" and we\'ll see what we can do.', $district );
		}

		$contact = [
			'customer_name'    => mb_substr( sanitize_text_field( (string) ( $input['name'] ?? '' ) ), 0, 200 ),
			'email'            => sanitize_email( (string) ( $input['email'] ?? '' ) ),
			'phone'            => mb_substr( preg_replace( '/[^0-9+ ]/', '', (string) ( $input['phone'] ?? '' ) ), 0, 40 ),
			'address_1'        => mb_substr( sanitize_text_field( (string) ( $input['address_1'] ?? '' ) ), 0, 200 ),
			'address_2'        => mb_substr( sanitize_text_field( (string) ( $input['address_2'] ?? '' ) ), 0, 200 ),
			'town'             => mb_substr( sanitize_text_field( (string) ( $input['town'] ?? '' ) ), 0, 100 ),
			'access_notes'     => mb_substr( sanitize_textarea_field( (string) ( $input['access_notes'] ?? '' ) ), 0, 2000 ),
			'marketing_opt_in' => ! empty( $input['marketing'] ) ? 1 : 0,
		];
		if ( mb_strlen( $contact['customer_name'] ) < 2 ) {
			$errors['name'] = 'Please enter your name';
		}
		if ( ! is_email( $contact['email'] ) ) {
			$errors['email'] = 'Please enter a valid email address';
		}
		if ( strlen( preg_replace( '/\D/', '', $contact['phone'] ) ) < 10 ) {
			$errors['phone'] = 'Please enter a mobile number so we can text reminders';
		}
		if ( '' === $contact['address_1'] ) {
			$errors['address_1'] = 'Please enter the first line of the address';
		}
		if ( '' === $contact['town'] ) {
			$errors['town'] = 'Please enter the town';
		}

		if ( $files && ( $file_error = Files::validate( $files ) ) ) {
			$errors['photos'] = $file_error;
		} elseif ( ! $files && in_array( $answers['condition'] ?? '', [ 'poor', 'very_poor' ], true ) ) {
			$errors['photos'] = 'Please add a few photos so we can see the condition';
		}

		$date  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $input['date'] ?? '' ) ) ? (string) $input['date'] : '';
		$slot  = isset( Slots::definitions()[ (string) ( $input['slot'] ?? '' ) ] ) ? (string) $input['slot'] : '';
		$price = Pricing::price( $service, $answers, $date, $slot );

		$reasons = $price['reasons'];
		if ( ! $covered && $district ) {
			$reasons[] = sprintf( 'Outside our usual area (%s)', $district );
		}
		$bespoke = (bool) $reasons;

		if ( ! $bespoke ) {
			if ( ! $date || ! $slot ) {
				$errors['date'] = 'Please choose a date and arrival time';
			} elseif ( ! Slots::is_available( $date, $slot, $price['hours'], $price['cleaners'] ) ) {
				$errors['date'] = 'Sorry, that time has just been booked. Please choose another.';
			}
		} elseif ( $date && ! Slots::is_working_day( $date ) ) {
			$date = '';
		}

		if ( $errors ) {
			return new \WP_Error( 'invalid', 'Please check the highlighted details.', [ 'status' => 400, 'errors' => $errors ] );
		}

		$now  = time();
		$data = $contact + [
			'ref'             => Tokens::reference( 'Q', 'quotes' ),
			'status'          => $bespoke ? 'draft' : 'sent',
			'is_bespoke'      => $bespoke ? 1 : 0,
			'bespoke_reasons' => $reasons,
			'service_id'      => $service['id'],
			'service_slug'    => $service['slug'],
			'service_name'    => $service['name'],
			'answers'         => $answers,
			'job_date'        => $date ?: null,
			'slot'            => $slot,
			'hours'           => $price['hours'],
			'cleaners'        => $price['cleaners'],
			'items'           => $price['items'],
			'total_pence'     => $price['total'],
			'postcode'        => $postcode,
			'district'        => $district,
			'covered'         => $covered ? 1 : 0,
			'sent_at'         => $bespoke ? null : Db::datetime( $now ),
			'expires_at'      => $bespoke ? null : Db::datetime( $now + self::expiry_seconds() ),
			'is_test'         => self::is_test_customer( $contact['email'], $contact['customer_name'] ) ? 1 : 0,
			'ip_hash'         => Security::ip_hash(),
		];
		$id = Db::insert( 'quotes', $data );
		if ( ! $id ) {
			return new \WP_Error( 'db', 'Sorry, something went wrong saving your quote. Please call us.', [ 'status' => 500 ] );
		}

		if ( $files ) {
			Db::update( 'quotes', $id, [ 'photos' => Files::store( $files, $data['ref'] ) ] );
		}

		$replaced = self::verify( (string) ( $input['replaces_ref'] ?? '' ), (string) ( $input['replaces_token'] ?? '' ) );
		if ( $replaced && in_array( $replaced['status'], [ 'draft', 'sent', 'viewed', 'expired' ], true ) ) {
			self::set_status( $replaced['id'], 'superseded', 'Customer changed their details: replaced by ' . $data['ref'] );
			self::unschedule( $replaced['id'] );
			Db::update( 'quotes', $id, [ 'replaces_id' => $replaced['id'] ] );
		}

		Events::log( 'quote', $id, 'created', $bespoke ? 'Bespoke quote requested' : 'Instant quote ' . Money::format( $price['total'] ), [ 'reasons' => $reasons ], 'customer' );

		$quote = self::get( $id );
		$token = Tokens::issue( 'quotes', $id );
		$url   = self::url( $quote, $token );

		do_action( 'cc_quote_created', $id, [ 'url' => $url, 'bespoke' => $bespoke ] );
		if ( $bespoke ) {
			do_action( 'cc_quote_bespoke_requested', $id, [ 'url' => $url ] );
		} else {
			self::schedule( $quote );
			do_action( 'cc_quote_sent', $id, [ 'url' => $url ] );
		}

		return [ 'quote' => self::get( $id ), 'url' => $bespoke ? '' : $url, 'bespoke' => $bespoke ];
	}

	private static function expiry_seconds(): int {
		return max( 1, (int) Settings::get( 'quotes.expiry_days', 7 ) ) * DAY_IN_SECONDS;
	}

	/**
	 * Send (or re-send) a priced quote: used by admin after pricing a bespoke request.
	 *
	 * @return string|\WP_Error The new quote link.
	 */
	public static function send( int $id, string $note = '' ) {
		$quote = self::get( $id );
		if ( ! $quote ) {
			return new \WP_Error( 'missing', 'Quote not found.' );
		}
		if ( (int) $quote['total_pence'] <= 0 || ! $quote['items'] ) {
			return new \WP_Error( 'unpriced', 'Add at least one priced line before sending.' );
		}
		if ( $quote['booking_id'] || in_array( $quote['status'], [ 'accepted', 'cancelled', 'superseded' ], true ) ) {
			return new \WP_Error( 'closed', 'This quote is closed and can\'t be sent.' );
		}

		$now = time();
		Db::update( 'quotes', $id, [
			'sent_at'       => Db::datetime( $now ),
			'expires_at'    => Db::datetime( $now + self::expiry_seconds() ),
			'followups_off' => 0,
		] );
		self::set_status( $id, 'sent', $note ?: 'Quote sent to customer', true );

		$quote = self::get( $id );
		$url   = self::link( $quote );
		self::unschedule( $id );
		self::schedule( $quote );
		do_action( 'cc_quote_sent', $id, [ 'url' => $url ] );
		return $url;
	}

	/** Admin pricing of a bespoke (or any open) quote. */
	public static function update_pricing( int $id, array $items, float $hours, int $cleaners, string $date, string $slot, string $note ): void {
		$total = Pricing::sum( $items );
		Db::update( 'quotes', $id, [
			'items'       => $items,
			'total_pence' => $total,
			'hours'       => $hours,
			'cleaners'    => max( 1, $cleaners ),
			'job_date'    => $date ?: null,
			'slot'        => $slot,
			'admin_note'  => $note,
		] );
		Events::log( 'quote', $id, 'priced', 'Priced at ' . Money::format( $total ), [ 'items' => count( $items ), 'hours' => $hours, 'cleaners' => $cleaners ] );
	}

	public static function set_status( int $id, string $to, string $note = '', bool $force = false ): bool {
		$quote = self::get( $id );
		if ( ! $quote || ( ! $force && ! Status::can( 'quote', $quote['status'], $to ) ) ) {
			return false;
		}
		$data = [ 'status' => $to ];
		if ( 'accepted' === $to ) {
			$data['accepted_at'] = Db::now();
		}
		Db::update( 'quotes', $id, $data );
		if ( $quote['status'] !== $to ) {
			Events::log( 'quote', $id, 'status', $note ?: sprintf( '%s → %s', Status::label( 'quote', $quote['status'] ), Status::label( 'quote', $to ) ), [ 'from' => $quote['status'], 'to' => $to ] );
			do_action( 'cc_quote_status_changed', $id, $to, $quote['status'] );
		}
		if ( in_array( $to, [ 'accepted', 'cancelled', 'superseded', 'expired' ], true ) ) {
			self::unschedule( $id, 'expired' !== $to );
		}
		return true;
	}

	/** First visit by the customer (admins previewing don't count). */
	public static function mark_viewed( array $quote ): void {
		if ( 'sent' !== $quote['status'] || Plugin::can_manage() ) {
			return;
		}
		Db::update( 'quotes', $quote['id'], [ 'viewed_at' => Db::now() ] );
		self::set_status( $quote['id'], 'viewed', 'Customer opened the quote' );
		do_action( 'cc_quote_viewed', $quote['id'] );
	}

	public static function stop_followups( array $quote ): void {
		if ( ! $quote['followups_off'] ) {
			Db::update( 'quotes', $quote['id'], [ 'followups_off' => 1 ] );
			self::unschedule( $quote['id'], false );
			Events::log( 'quote', $quote['id'], 'unsubscribed', 'Customer turned off quote reminders', [], 'customer' );
		}
	}

	private static function schedule( array $quote ): void {
		$sent    = Db::timestamp( $quote['sent_at'] );
		$expires = Db::timestamp( $quote['expires_at'] );
		$hours   = (int) Settings::get( 'quotes.reminder_hours', 24 );
		$day     = (int) Settings::get( 'quotes.expiring_day', 6 );
		if ( $hours > 0 && $sent + $hours * HOUR_IN_SECONDS < $expires ) {
			Queue::at( $sent + $hours * HOUR_IN_SECONDS, 'cc_bookings_quote_followup', [ $quote['id'], 'reminder' ] );
		}
		if ( $day > 0 && $sent + $day * DAY_IN_SECONDS < $expires ) {
			Queue::at( $sent + $day * DAY_IN_SECONDS, 'cc_bookings_quote_followup', [ $quote['id'], 'expiring' ] );
		}
		Queue::at( $expires, 'cc_bookings_quote_expire', [ $quote['id'] ] );
	}

	private static function unschedule( int $id, bool $expiry_too = true ): void {
		Queue::cancel( 'cc_bookings_quote_followup', [ $id, 'reminder' ] );
		Queue::cancel( 'cc_bookings_quote_followup', [ $id, 'expiring' ] );
		if ( $expiry_too ) {
			Queue::cancel( 'cc_bookings_quote_expire', [ $id ] );
		}
	}

	/** Scheduled: "still available" (24 h) and "expires tomorrow" (day 6) emails. */
	public static function followup( $id, $kind ): void {
		$quote = self::get( (int) $id );
		if ( ! $quote || $quote['followups_off'] || ! self::is_payable( $quote ) ) {
			return;
		}
		$template = 'expiring' === $kind ? 'quote_expiring' : 'quote_reminder';
		Notify::send( $template, $quote['email'], Notify::quote_vars( $quote, self::link( $quote ) ), [ 'quote', $quote['id'] ] );
	}

	/** Scheduled at expiry. */
	public static function expire( $id ): void {
		$quote = self::get( (int) $id );
		if ( ! $quote || $quote['booking_id'] || ! in_array( $quote['status'], Status::payable_quote(), true ) ) {
			return;
		}
		if ( Db::timestamp( $quote['expires_at'] ) > time() + 60 ) {
			return;
		}
		self::set_status( $quote['id'], 'expired', 'Quote expired' );
		do_action( 'cc_quote_expired', $quote['id'] );
	}

	/** Answers for re-opening the form pre-filled ("Change details"). */
	public static function prefill( array $quote ): array {
		return [
			'service'      => $quote['service_slug'],
			'postcode'     => $quote['postcode'],
			'ask_anyway'   => ! $quote['covered'],
			'answers'      => $quote['answers'],
			'date'         => (string) $quote['job_date'],
			'slot'         => $quote['slot'],
			'name'         => $quote['customer_name'],
			'email'        => $quote['email'],
			'phone'        => $quote['phone'],
			'address_1'    => $quote['address_1'],
			'address_2'    => $quote['address_2'],
			'town'         => $quote['town'],
			'access_notes' => $quote['access_notes'],
			'marketing'    => (bool) $quote['marketing_opt_in'],
		];
	}

	/** Address with house numbers and the inward postcode masked, for the quote page. */
	public static function masked_address( array $row ): string {
		$line = preg_replace( '/\d+[a-z]?\b/i', '••', (string) $row['address_1'] );
		return implode( ', ', array_filter( [ $line, $row['town'], $row['district'] ? $row['district'] . ' •••' : '' ] ) );
	}
}

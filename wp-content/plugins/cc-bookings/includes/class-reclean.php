<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Re-clean guarantee claims (table wp_cc_reclean_claims, installed by Aftercare). The customer submits the
 * form on their booking page with photos, inside the window after completion (aftercare.reclean_hours, else
 * Business Settings reclean_window_hours, else 72). The admin approves — which creates a £0 re-clean booking
 * linked by parent_id and confirms it through cc_booking_confirmed so dispatch picks it up — or declines.
 */
final class Reclean {
	const TABLE    = 'reclean_claims';
	const STATUSES = [ 'open' => 'Waiting for review', 'approved' => 'Approved', 'declined' => 'Declined' ];

	public static function init(): void {
		add_action( 'cc_bookings_booking_page_actions', [ self::class, 'panel' ], 20, 2 );
	}

	public static function window_hours(): int {
		$hours = (int) Aftercare::setting( 'reclean_hours' );
		if ( $hours <= 0 ) {
			$hours = function_exists( 'cc_option' ) ? (int) cc_option( 'reclean_window_hours', 72 ) : 72;
		}
		return $hours > 0 ? $hours : 72;
	}

	public static function window_end( array $booking ): int {
		$done = Db::timestamp( $booking['completed_at'] ) ?: time();
		return $done + self::window_hours() * HOUR_IN_SECONDS;
	}

	public static function in_window( array $booking ): bool {
		return 'completed' === $booking['status'] && ! Aftercare::is_reclean( $booking ) && time() <= self::window_end( $booking );
	}

	public static function get( int $id ): ?array {
		return Db::get( self::TABLE, $id );
	}

	public static function for_booking( int $booking_id ): array {
		return Db::all( self::TABLE, [ 'booking_id' => $booking_id ], 'id DESC' );
	}

	/** An open or approved claim blocks a new one. */
	public static function open_claim( int $booking_id ): ?array {
		return Db::find( self::TABLE, [ 'booking_id' => $booking_id, 'status' => [ 'open', 'approved' ] ] );
	}

	public static function admin_url( array $claim ): string {
		return Bookings::admin_url( (int) $claim['booking_id'] ) . '#cc-reclean';
	}

	private static function vars( array $claim, array $booking ): array {
		return [
			'message'     => (string) $claim['message'],
			'photo_count' => (string) count( (array) $claim['photos'] ),
			'admin_url'   => self::admin_url( $claim ),
		] + Notify::booking_vars( $booking );
	}

	/**
	 * @param array $files Normalised uploads (Files::from_request).
	 * @return int|\WP_Error Claim id.
	 */
	public static function submit( array $booking, string $message, array $files ) {
		$booking = Bookings::get( (int) $booking['id'] );
		if ( ! $booking || ! self::in_window( $booking ) ) {
			return new \WP_Error( 'closed', sprintf( 'Re-clean requests must be made within %d hours of the clean. Please call us on %s.', self::window_hours(), Notify::site_vars()['phone'] ) );
		}
		if ( self::open_claim( $booking['id'] ) ) {
			return new \WP_Error( 'exists', 'We already have a re-clean request for this booking.' );
		}
		$message = trim( mb_substr( $message, 0, 2000 ) );
		if ( mb_strlen( $message ) < 10 ) {
			return new \WP_Error( 'message', 'Please tell us what wasn\'t right.' );
		}
		if ( ! $files ) {
			return new \WP_Error( 'photos', 'Please add at least one photo of the problem.' );
		}
		if ( $error = Files::validate( $files ) ) {
			return new \WP_Error( 'photos', $error );
		}

		$photo_ref = 'RC-' . $booking['ref'];
		$claim_id  = Db::insert( self::TABLE, [
			'booking_id' => $booking['id'],
			'status'     => 'open',
			'message'    => $message,
			'photos'     => Files::store( $files, $photo_ref ),
			'photo_ref'  => $photo_ref,
		] );
		if ( ! $claim_id ) {
			return new \WP_Error( 'db', 'Sorry, something went wrong. Please call us.' );
		}
		$claim = self::get( $claim_id );
		Events::log( 'booking', $booking['id'], 'reclean_claimed', sprintf( 'Re-clean requested with %d photo(s)', count( (array) $claim['photos'] ) ), [ 'claim_id' => $claim_id ], 'customer' );

		$vars = self::vars( $claim, $booking );
		Notify::send( 'reclean_received', (string) $booking['email'], $vars, [ 'booking', $booking['id'] ] );
		Notify::send( 'reclean_admin', Settings::admin_email(), $vars, [ 'booking', $booking['id'] ] );
		do_action( 'cc_booking_reclean_claimed', $claim_id, (int) $booking['id'] );
		return $claim_id;
	}

	/**
	 * Approve a claim and book the free return visit.
	 *
	 * @param bool $force Book even if the slot is full or inside the minimum notice.
	 * @return int|\WP_Error Re-clean booking id.
	 */
	public static function approve( int $claim_id, string $date, string $slot, float $hours = 0, string $note = '', bool $force = false ) {
		$claim    = self::get( $claim_id );
		$original = $claim ? Bookings::get( (int) $claim['booking_id'] ) : null;
		if ( ! $claim || ! $original || 'open' !== $claim['status'] ) {
			return new \WP_Error( 'closed', 'This claim has already been decided.' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! isset( Slots::definitions()[ $slot ] ) ) {
			return new \WP_Error( 'invalid', 'Choose a date and arrival slot for the re-clean.' );
		}
		$hours = $hours > 0 ? $hours : (float) $original['hours'];
		$start = Slots::start_timestamp( $date, $slot );
		if ( $start <= time() ) {
			return new \WP_Error( 'past', 'Choose a time in the future.' );
		}
		Manage::slot_context( $original );
		if ( ! $force && ! Slots::is_available( $date, $slot, $hours, (int) $original['cleaners'] ) ) {
			return new \WP_Error( 'unavailable', 'That slot is full or inside the minimum notice. Pick another, or tick "Book anyway".' );
		}

		$data = array_intersect_key( $original, array_flip( [
			'service_id', 'service_slug', 'service_name', 'answers', 'cleaners', 'customer_name', 'email', 'phone',
			'address_1', 'address_2', 'town', 'postcode', 'district', 'access_notes', 'marketing_opt_in', 'is_test',
		] ) );
		$data += [
			'ref'            => Tokens::reference( 'B', 'bookings' ),
			'quote_id'       => 0,
			'status'         => 'confirmed',
			'payment_status' => 'free',
			'job_date'       => $date,
			'slot'           => $slot,
			'hours'          => $hours,
			'start_at'       => Db::datetime( $start ),
			'end_at'         => Db::datetime( $start + (int) round( $hours * HOUR_IN_SECONDS ) ),
			'total_pence'    => 0,
			'paid_pence'     => 0,
			'parent_id'      => $original['id'],
			'meta'           => [ 'reclean_of' => $original['id'], 'reclean_of_ref' => $original['ref'], 'claim_id' => $claim_id, 'provider' => 'reclean', 'customer_price_pence' => 0 ],
			'admin_note'     => trim( 'Free re-clean under the guarantee for ' . $original['ref'] . '. ' . $note ),
			'confirmed_at'   => Db::now(),
		];
		$booking_id = Db::insert( 'bookings', $data );
		if ( ! $booking_id ) {
			return new \WP_Error( 'db', 'Could not create the re-clean booking.' );
		}
		Db::insert( 'booking_items', [
			'booking_id'   => $booking_id,
			'type'         => 'reclean',
			'label'        => 'Re-clean under our guarantee (no charge)',
			'unit_pence'   => 0,
			'amount_pence' => 0,
		] );
		Db::update( self::TABLE, $claim_id, [
			'status'             => 'approved',
			'reclean_booking_id' => $booking_id,
			'admin_note'         => $note,
			'decided_by'         => get_current_user_id(),
			'decided_at'         => Db::now(),
		] );
		$booking = Bookings::get( $booking_id );
		Events::log( 'booking', $original['id'], 'reclean_approved', sprintf( 'Re-clean approved: %s on %s', $booking['ref'], Bookings::start_label( $booking ) ), [ 'claim_id' => $claim_id, 'reclean_booking_id' => $booking_id ] );
		Events::log( 'booking', $booking_id, 'confirmed', sprintf( 'Free re-clean for %s (claim %d)', $original['ref'], $claim_id ), [ 'reclean_of' => $original['id'] ] );

		$url = Bookings::link( $booking );
		// The standard "payment received" confirmation doesn't fit a free visit; reclean_approved replaces it.
		$removed = remove_action( 'cc_booking_confirmed', [ Notify::class, 'booking_confirmed' ], 10 );
		do_action( 'cc_booking_confirmed', $booking_id, [ 'booking_url' => $url, 'provider' => 'reclean', 'reclean_of' => (int) $original['id'], 'claim_id' => $claim_id ] );
		if ( $removed ) {
			add_action( 'cc_booking_confirmed', [ Notify::class, 'booking_confirmed' ], 10, 2 );
		}
		$booking = Bookings::get( $booking_id );
		Notify::send( 'reclean_approved', (string) $booking['email'], [ 'note' => $note, 'manage_url' => $url ] + Notify::booking_vars( $booking, $url ), [ 'booking', $booking_id ] );
		do_action( 'cc_booking_reclean_approved', $claim_id, (int) $original['id'], $booking_id );
		return $booking_id;
	}

	/** @return true|\WP_Error */
	public static function decline( int $claim_id, string $reason ) {
		$claim   = self::get( $claim_id );
		$booking = $claim ? Bookings::get( (int) $claim['booking_id'] ) : null;
		if ( ! $claim || ! $booking || 'open' !== $claim['status'] ) {
			return new \WP_Error( 'closed', 'This claim has already been decided.' );
		}
		if ( '' === trim( $reason ) ) {
			return new \WP_Error( 'reason', 'Give a reason — it is sent to the customer.' );
		}
		Db::update( self::TABLE, $claim_id, [ 'status' => 'declined', 'admin_note' => $reason, 'decided_by' => get_current_user_id(), 'decided_at' => Db::now() ] );
		Events::log( 'booking', $booking['id'], 'reclean_declined', 'Re-clean declined: ' . $reason, [ 'claim_id' => $claim_id ] );
		Notify::send( 'reclean_declined', (string) $booking['email'], [ 'reason' => $reason ] + Notify::booking_vars( $booking ), [ 'booking', $booking['id'] ] );
		do_action( 'cc_booking_reclean_declined', $claim_id, (int) $booking['id'], $reason );
		return true;
	}

	/** Customer panel on a completed booking (hook cc_bookings_booking_page_actions). */
	public static function panel( $booking, $token ): void {
		$booking = Bookings::get( (int) $booking['id'] ) ?? $booking;
		if ( 'completed' !== $booking['status'] || Aftercare::is_reclean( $booking ) ) {
			return;
		}
		$claim = self::for_booking( (int) $booking['id'] )[0] ?? null;
		$phone = Notify::site_vars()['phone'];
		echo '<section id="cc-reclean" class="cc-panel cc-private__card cc-private__ask"><h2 class="cc-private__heading">Re-clean guarantee</h2>';

		if ( $claim && 'declined' !== $claim['status'] ) {
			$text = 'open' === $claim['status']
				? 'We\'ve got your request and will be in touch shortly.'
				: 'Approved — your free return visit is booked. Check your email for the details.';
			if ( 'approved' === $claim['status'] && ( $visit = Bookings::get( (int) $claim['reclean_booking_id'] ) ) ) {
				$text = 'Approved — your free return visit is booked for ' . Bookings::start_label( $visit ) . '.';
			}
			printf( '<p>%s</p>', esc_html( $text ) );
		} elseif ( self::in_window( $booking ) ) {
			printf( '<p class="cc-small">Not happy with something? Tell us by %s and we\'ll come back and put it right for free.</p>', esc_html( wp_date( 'l j F, g:ia', self::window_end( $booking ) ) ) );
			$open = ! empty( $_GET['reclean'] ) ? ' open' : '';
			echo '<details' . $open . '><summary>Request a re-clean</summary>';
			echo '<form method="post" enctype="multipart/form-data">' . Manage::nonce_field( $booking ) . '<input type="hidden" name="cc_manage" value="reclean">'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<label for="cc-reclean-message">What wasn\'t right?</label><textarea id="cc-reclean-message" name="message" rows="4" maxlength="2000" required></textarea>';
			printf( '<div class="cc-quote__field"><label for="cc-reclean-photos">Photos (at least one, up to %d)</label><input id="cc-reclean-photos" type="file" name="photos[]" accept="image/*" multiple required></div>', (int) Files::MAX_FILES );
			echo '<button type="submit" class="cc-btn cc-btn--primary">Send request</button></form></details>';
		} else {
			printf( '<p class="cc-small">The re-clean window closed on %s. If something isn\'t right, please call us on %s.</p>', esc_html( wp_date( 'l j F', self::window_end( $booking ) ) ), esc_html( $phone ) );
		}
		echo '</section>';
	}
}

<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Quote revisions. When the office changes a quote the customer has already been sent, the version it replaces is kept
 * (wp_cc_quote_revisions: revision number, snapshot of the previous version, what changed, the note to the client), the
 * quote's revision number goes up, any open Stripe Checkout for the old version is closed (slot hold released), and the
 * next quote email is "Your quote has been updated" listing the changes. Only the latest revision can be paid: the
 * revision travels in the Checkout metadata and in the accept forms, and a payment for an older one is refused.
 *
 * Also builds the "what changed" lists for a client's own change (a new quote replacing the old one).
 *
 * Hooks fired: cc_quote_revised( $quote_id, $revision, $changes ).
 */
final class Quote_Revisions {
	const SCHEMA_VERSION = '1';
	const SCHEMA_OPTION  = 'cc_bookings_quote_revisions_schema';
	const TABLE          = 'quote_revisions';

	/** Quote columns kept in a revision snapshot. */
	const FIELDS = [ 'service_slug', 'service_name', 'answers', 'job_date', 'slot', 'hours', 'cleaners', 'items', 'total_pence', 'address_1', 'address_2', 'town', 'postcode', 'access_notes' ];

	public static function init(): void {
		self::maybe_install();
		add_filter( 'cc_bookings_checkout_params', [ self::class, 'checkout_params' ], 30, 2 );
		add_filter( 'cc_bookings_stripe_event', [ self::class, 'stripe_event' ], 5, 2 );
		add_filter( 'cc_bookings_checkout_consent', [ self::class, 'checkout_consent' ], 5, 3 );
		add_filter( 'cc_bookings_email_html_vars', fn( $vars ) => array_merge( (array) $vars, [ 'changes', 'client_note' ] ) );
	}

	/* ---------------------------------------------------------------------
	 * Schema (own version option; the core schema version is untouched)
	 * ------------------------------------------------------------------ */

	public static function maybe_install(): void {
		if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION && get_option( Schema::OPTION ) ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . Schema::table( self::TABLE ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
quote_id bigint(20) unsigned NOT NULL,
revision smallint(5) unsigned NOT NULL DEFAULT 1,
data longtext NULL,
client_note text NULL,
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
notified_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY quote_revision (quote_id,revision)
) " . $wpdb->get_charset_collate() . ';' );

		$table   = Schema::table( 'quotes' );
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL
		$add     = [];
		foreach ( [ 'revision' => 'smallint(5) unsigned NOT NULL DEFAULT 1', 'revised_at' => 'datetime NULL', 'admin_priced' => 'tinyint(1) NOT NULL DEFAULT 0' ] as $column => $definition ) {
			if ( ! in_array( $column, $columns, true ) ) {
				$add[] = "ADD COLUMN {$column} {$definition}";
			}
		}
		if ( $add ) {
			$wpdb->query( "ALTER TABLE {$table} " . implode( ', ', $add ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/* ---------------------------------------------------------------------
	 * Revisions
	 * ------------------------------------------------------------------ */

	public static function revision( array $quote ): int {
		return max( 1, (int) ( $quote['revision'] ?? 1 ) );
	}

	/** Newest first. */
	public static function all( int $quote_id ): array {
		return Db::all( self::TABLE, [ 'quote_id' => $quote_id ], 'revision DESC, id DESC' );
	}

	/** Revisions not emailed to the client yet, oldest first. */
	public static function unnotified( int $quote_id ): array {
		return Db::all( self::TABLE, [ 'quote_id' => $quote_id, 'notified_at' => null ], 'revision ASC, id ASC' );
	}

	public static function snapshot( array $quote ): array {
		return array_intersect_key( $quote, array_flip( self::FIELDS ) );
	}

	/**
	 * An office change to a quote. Saves the columns; when the client has already been sent this quote and something they
	 * can see changed, records a revision (previous version + changes) and closes any open payment for the old version.
	 *
	 * @param array $data Quote columns to change (items, total_pence, hours, cleaners, job_date, slot, answers, admin_note…).
	 * @return array{revision: int, changes: array, revised: bool}|\WP_Error
	 */
	public static function amend( int $quote_id, array $data, string $client_note = '' ) {
		$quote = Quotes::get( $quote_id );
		if ( ! $quote ) {
			return new \WP_Error( 'missing', 'Quote not found.' );
		}
		if ( $quote['booking_id'] || in_array( $quote['status'], [ 'accepted', 'cancelled', 'superseded' ], true ) ) {
			return new \WP_Error( 'closed', 'This quote is closed and can\'t be changed.' );
		}
		$changes = self::diff( $quote, array_merge( $quote, $data ) );
		$revised = $quote['sent_at'] && $changes;
		if ( $revised ) {
			$closed = self::close_checkout( $quote );
			if ( is_wp_error( $closed ) ) {
				return $closed;
			}
		}
		$revision = self::revision( $quote ) + ( $revised ? 1 : 0 );
		Db::update( 'quotes', $quote_id, $data + ( $revised ? [ 'revision' => $revision, 'revised_at' => Db::now() ] : [] ) );
		if ( $revised ) {
			Db::insert( self::TABLE, [
				'quote_id'    => $quote_id,
				'revision'    => $revision,
				'data'        => [ 'before' => self::snapshot( $quote ), 'changes' => $changes ],
				'client_note' => $client_note,
				'created_by'  => get_current_user_id(),
			] );
			Events::log( 'quote', $quote_id, 'revised', sprintf( 'Revision %d: %s', $revision, self::text( $changes ) ), [ 'revision' => $revision ] );
			do_action( 'cc_quote_revised', $quote_id, $revision, $changes );
		}
		return [ 'revision' => $revision, 'changes' => $changes, 'revised' => (bool) $revised ];
	}

	/** Close an open Stripe Checkout for the current version. A session the customer has already paid blocks the change. */
	private static function close_checkout( array $quote ) {
		$session = (string) $quote['stripe_session_id'];
		if ( '' === $session || ! $quote['hold_expires_at'] ) {
			return true;
		}
		if ( str_starts_with( $session, 'cs_' ) && ! str_starts_with( $session, 'cs_sim_' ) && Stripe::enabled() ) {
			$remote = Stripe::request( 'GET', 'checkout/sessions/' . rawurlencode( $session ) );
			if ( ! is_wp_error( $remote ) && 'complete' === ( $remote['status'] ?? '' ) ) {
				return new \WP_Error( 'paid', 'The customer has just paid for this quote, so it can\'t be changed. Refresh the page to see the booking.' );
			}
		}
		Checkout::expire_hold( (int) $quote['id'], $session, true );
		return true;
	}

	/**
	 * The changes not emailed yet (from the version the client last heard about to now) and their notes to the client.
	 *
	 * @return array{changes: array, notes: string[], ids: int[]}
	 */
	public static function pending( array $quote ): array {
		$rows = self::unnotified( (int) $quote['id'] );
		if ( ! $rows ) {
			return [ 'changes' => [], 'notes' => [], 'ids' => [] ];
		}
		$before = array_merge( $quote, (array) ( $rows[0]['data']['before'] ?? [] ) );
		return [
			'changes' => self::diff( $before, $quote ),
			'notes'   => array_values( array_filter( array_map( fn( $r ) => trim( (string) $r['client_note'] ), $rows ) ) ),
			'ids'     => array_map( fn( $r ) => (int) $r['id'], $rows ),
		];
	}

	public static function mark_notified( array $ids ): void {
		foreach ( $ids as $id ) {
			Db::update( self::TABLE, (int) $id, [ 'notified_at' => Db::now() ] );
		}
	}

	/** The latest revision's changes, for the client's quote page. */
	public static function latest_changes( array $quote ): array {
		$row = self::revision( $quote ) > 1 ? Db::find( self::TABLE, [ 'quote_id' => (int) $quote['id'], 'revision' => self::revision( $quote ) ] ) : null;
		return $row ? self::diff( array_merge( $quote, (array) ( $row['data']['before'] ?? [] ) ), $quote ) : [];
	}

	/* ---------------------------------------------------------------------
	 * What changed
	 * ------------------------------------------------------------------ */

	/**
	 * What a client would notice between two versions of a quote, in reading order.
	 *
	 * @return array<int, array{kind: string, label: string, from?: mixed, to?: mixed}> Amounts are in pence.
	 */
	public static function diff( array $old, array $new ): array {
		$out         = [];
		$old_service = Catalog::get( (string) $old['service_slug'] );
		$new_service = Catalog::get( (string) $new['service_slug'] );

		if ( (string) $old['service_slug'] !== (string) $new['service_slug'] ) {
			$out[] = [ 'kind' => 'field', 'label' => 'Service', 'from' => (string) $old['service_name'], 'to' => (string) $new['service_name'] ];
		}
		$when = fn( array $q ) => $q['job_date'] ? Slots::date_label( (string) $q['job_date'], 'D j M Y' ) . ( $q['slot'] ? ', ' . Slots::label( (string) $q['slot'] ) : '' ) : 'Not chosen';
		if ( (string) $old['job_date'] !== (string) $new['job_date'] || (string) $old['slot'] !== (string) $new['slot'] ) {
			$out[] = [ 'kind' => 'field', 'label' => 'Date and arrival', 'from' => $when( $old ), 'to' => $when( $new ) ];
		}

		$details = fn( ?array $service, array $q ) => $service ? array_column( Catalog::summary( $service, (array) $q['answers'] ), 1, 0 ) : [];
		$before  = $details( $old_service, $old );
		$after   = $details( $new_service, $new );
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $label ) {
			$from = (string) ( $before[ $label ] ?? '' );
			$to   = (string) ( $after[ $label ] ?? '' );
			if ( $from !== $to ) {
				$out[] = [ 'kind' => 'field', 'label' => (string) $label, 'from' => '' === $from ? '—' : $from, 'to' => '' === $to ? '—' : $to ];
			}
		}

		$addons = fn( ?array $service, array $q ) => $service ? Catalog::addon_names( $service, (array) $q['answers'] ) : [];
		$had    = $addons( $old_service, $old );
		$has    = $addons( $new_service, $new );
		foreach ( array_diff( $has, $had ) as $name ) {
			$out[] = [ 'kind' => 'addon_added', 'label' => $name ];
		}
		foreach ( array_diff( $had, $has ) as $name ) {
			$out[] = [ 'kind' => 'addon_removed', 'label' => $name ];
		}

		$address = fn( array $q ) => implode( ', ', array_filter( [ $q['address_1'] ?? '', $q['address_2'] ?? '', $q['town'] ?? '', $q['postcode'] ?? '' ] ) );
		if ( $address( $old ) !== $address( $new ) ) {
			$out[] = [ 'kind' => 'field', 'label' => 'Address', 'from' => $address( $old ), 'to' => $address( $new ) ];
		}
		if ( trim( (string) $old['access_notes'] ) !== trim( (string) $new['access_notes'] ) ) {
			$out[] = [ 'kind' => 'field', 'label' => 'Access notes', 'from' => trim( (string) $old['access_notes'] ) ?: '—', 'to' => trim( (string) $new['access_notes'] ) ?: '—' ];
		}
		$from = Pricing::duration_label( (float) $old['hours'], (int) $old['cleaners'] );
		$to   = Pricing::duration_label( (float) $new['hours'], (int) $new['cleaners'] );
		if ( $from !== $to ) {
			$out[] = [ 'kind' => 'field', 'label' => 'Estimated time', 'from' => $from ?: '—', 'to' => $to ?: '—' ];
		}

		$lines = function ( array $items ): array {
			$out = [];
			foreach ( $items as $item ) {
				$label         = trim( (string) ( $item['label'] ?? '' ) );
				$out[ $label ] = ( $out[ $label ] ?? 0 ) + (int) ( $item['amount'] ?? 0 );
			}
			return $out;
		};
		$was = $lines( (array) $old['items'] );
		$now = $lines( (array) $new['items'] );
		foreach ( $now as $label => $amount ) {
			if ( ! array_key_exists( $label, $was ) ) {
				$out[] = [ 'kind' => 'item_added', 'label' => (string) $label, 'to' => $amount ];
			} elseif ( $was[ $label ] !== $amount ) {
				$out[] = [ 'kind' => 'item_changed', 'label' => (string) $label, 'from' => $was[ $label ], 'to' => $amount ];
			}
		}
		foreach ( $was as $label => $amount ) {
			if ( ! array_key_exists( $label, $now ) ) {
				$out[] = [ 'kind' => 'item_removed', 'label' => (string) $label, 'from' => $amount ];
			}
		}

		$total_was = null === $old['total_pence'] ? null : (int) $old['total_pence'];
		$total_now = null === $new['total_pence'] ? null : (int) $new['total_pence'];
		if ( $total_was !== $total_now ) {
			$out[] = [ 'kind' => 'total', 'label' => 'Total', 'from' => $total_was, 'to' => $total_now ];
		}
		return $out;
	}

	private static function money( $pence ): string {
		return null === $pence ? 'to be priced' : Money::format( (int) $pence );
	}

	/** One change as plain text. */
	public static function line( array $change ): string {
		switch ( $change['kind'] ) {
			case 'addon_added':
				return 'Extra added: ' . $change['label'];
			case 'addon_removed':
				return 'Extra removed: ' . $change['label'];
			case 'item_added':
				return sprintf( 'Added to the price: %s (%s)', $change['label'], self::money( $change['to'] ) );
			case 'item_removed':
				return sprintf( 'Removed from the price: %s (%s)', $change['label'], self::money( $change['from'] ) );
			case 'item_changed':
			case 'total':
				return sprintf( '%s: %s → %s', $change['label'], self::money( $change['from'] ), self::money( $change['to'] ) );
		}
		return sprintf( '%s: %s → %s', $change['label'], $change['from'], $change['to'] );
	}

	public static function text( array $changes ): string {
		return implode( '; ', array_map( [ self::class, 'line' ], $changes ) );
	}

	/** One change as HTML, old values struck through. */
	public static function line_html( array $change ): string {
		$label = '<strong>' . esc_html( $change['label'] ) . '</strong>';
		$old   = fn( string $text ) => '<s style="color:#8a9ba5;">' . esc_html( $text ) . '</s>';
		switch ( $change['kind'] ) {
			case 'addon_added':
				return 'Extra added: ' . $label;
			case 'addon_removed':
				return 'Extra removed: ' . $old( $change['label'] );
			case 'item_added':
				return 'Added: ' . $label . ' — ' . esc_html( self::money( $change['to'] ) );
			case 'item_removed':
				return 'Removed: ' . $old( $change['label'] . ' — ' . self::money( $change['from'] ) );
			case 'item_changed':
				return $label . ': ' . $old( self::money( $change['from'] ) ) . ' ' . esc_html( self::money( $change['to'] ) );
		}
		return $label . ': ' . $old( (string) $change['from'] ) . ' → ' . esc_html( (string) $change['to'] );
	}

	/** "What's changed" for an email or the client's quote page: the list, then the old total struck through by the new one. */
	public static function html( array $changes, ?int $total = null, string $heading = 'What\'s changed' ): string {
		$items = '';
		$sum   = null;
		foreach ( $changes as $change ) {
			if ( 'total' === $change['kind'] ) {
				$sum = $change;
				continue;
			}
			$items .= '<li style="margin:0 0 6px;">' . self::line_html( $change ) . '</li>';
		}
		$html = $heading ? '<p style="margin:0 0 8px;font-weight:700;">' . esc_html( $heading ) . '</p>' : '';
		if ( $items ) {
			$html .= '<ul style="margin:0 0 16px;padding-left:20px;">' . $items . '</ul>';
		}
		if ( $sum ) {
			$html .= sprintf(
				'<p style="margin:0 0 16px;font-size:17px;">%s %s<strong>%s</strong></p>',
				null === $sum['to'] ? 'Total:' : 'New total:',
				null === $sum['from'] ? '' : '<s style="color:#8a9ba5;font-weight:400;">' . esc_html( Money::format( (int) $sum['from'] ) ) . '</s> ',
				esc_html( self::money( $sum['to'] ) )
			);
		} elseif ( null !== $total ) {
			$html .= '<p style="margin:0 0 16px;">Total: <strong>' . esc_html( Money::format( $total ) ) . '</strong> (unchanged)</p>';
		}
		return $html;
	}

	/** The office's note to the client, as a highlighted block. */
	public static function note_html( array $notes ): string {
		$notes = array_values( array_filter( array_map( 'trim', $notes ) ) );
		if ( ! $notes ) {
			return '';
		}
		return '<p style="margin:0 0 6px;font-weight:700;">A note from ' . esc_html( Settings::brand() ) . '</p>'
			. '<div style="margin:0 0 16px;padding:12px 14px;background:#f1f8f9;border-left:3px solid #0b7189;border-radius:6px;">' . implode( '', array_map( fn( $n ) => '<p style="margin:0 0 6px;">' . nl2br( esc_html( $n ) ) . '</p>', $notes ) ) . '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Only the latest revision can be paid
	 * ------------------------------------------------------------------ */

	/** `cc_bookings_checkout_params`: the revision goes into the Checkout metadata. */
	public static function checkout_params( $params, $quote ) {
		if ( is_array( $params ) && is_array( $quote ) ) {
			$params['metadata']['revision'] = (string) self::revision( Quotes::get( (int) $quote['id'] ) ?? $quote );
		}
		return $params;
	}

	/** `cc_bookings_checkout_consent`: an accept form showing an older revision is sent back to the latest one. */
	public static function checkout_consent( $extra, $quote, $input ) {
		if ( is_wp_error( $extra ) || ! isset( $input['revision'] ) || '' === (string) $input['revision'] ) {
			return $extra;
		}
		if ( (int) $input['revision'] !== self::revision( $quote ) ) {
			return new \WP_Error( 'revised', 'We\'ve just updated this quote. Please check the new details and price, then accept again.', [ 'status' => 409, 'reload' => true ] );
		}
		return $extra;
	}

	/**
	 * `cc_bookings_stripe_event` (early): a completed Checkout for an older revision is refused, not booked. The office is
	 * alerted to refund it. Normally impossible, as changing the quote closes its Checkout first.
	 */
	public static function stripe_event( $handled, $event ) {
		if ( null !== $handled || ! in_array( $event['type'] ?? '', [ 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ], true ) ) {
			return $handled;
		}
		$object = (array) ( $event['data']['object'] ?? [] );
		$meta   = (array) ( $object['metadata'] ?? [] );
		if ( 'booking' !== ( $meta['type'] ?? '' ) || ! isset( $meta['revision'] ) || 'paid' !== ( $object['payment_status'] ?? '' ) ) {
			return $handled;
		}
		$quote = Quotes::get( (int) ( $meta['quote_id'] ?? 0 ) );
		if ( ! $quote || $quote['booking_id'] || (int) $meta['revision'] === self::revision( $quote ) ) {
			return $handled;
		}
		$session  = (string) ( $object['id'] ?? '' );
		$provider = str_starts_with( $session, 'cs_sim_' ) ? 'simulated' : 'stripe';
		$amount   = (int) ( $object['amount_total'] ?? 0 );
		$intent   = is_array( $object['payment_intent'] ?? null ) ? (string) $object['payment_intent']['id'] : (string) ( $object['payment_intent'] ?? '' );
		$row      = Db::find( 'payments', [ 'stripe_session_id' => $session, 'type' => 'charge' ] );
		if ( $row && 'succeeded' === $row['status'] ) {
			return 'refused: already recorded';
		}
		$data = [
			'quote_id'              => (int) $quote['id'],
			'status'                => 'succeeded',
			'provider'              => $provider,
			'amount_pence'          => $amount,
			'stripe_payment_intent' => $intent,
			'note'                  => sprintf( 'Paid for revision %d of the quote — refund needed', (int) $meta['revision'] ),
		];
		$row ? Db::update( 'payments', (int) $row['id'], $data ) : Db::insert( 'payments', $data + [ 'type' => 'charge', 'stripe_session_id' => $session ] );
		$message = sprintf( 'Quote %s was paid (%s) for revision %d, but it has since been updated to revision %d, so no booking was made. Refund the payment in Stripe and ask the customer to accept the latest version.', $quote['ref'], Money::format( $amount ), (int) $meta['revision'], self::revision( $quote ) );
		Events::log( 'quote', (int) $quote['id'], 'payment_refused', $message, [ 'session' => $session ], $provider );
		Bookings::alert( 'Payment for an out-of-date quote', $message );
		return 'refused: ' . $quote['ref'] . ' has been updated since this payment was started';
	}
}

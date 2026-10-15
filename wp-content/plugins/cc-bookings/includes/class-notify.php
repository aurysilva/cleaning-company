<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Templated emails (wording editable in Bookings > Settings > Emails), wrapped in templates/email.php.
 * Every send is logged to the events table (event email_sent / email_failed) — the mail log.
 * Text messages are a phase 2 hook: Notify::sms() fires `cc_bookings_send_sms`.
 */
final class Notify {
	private static bool $log_only_active = false;

	public static function init(): void {
		add_action( 'cc_quote_sent', [ self::class, 'quote_sent' ], 10, 2 );
		add_action( 'cc_quote_bespoke_requested', [ self::class, 'bespoke_requested' ], 10, 2 );
		add_action( 'cc_quote_accept_requested', [ self::class, 'accept_requested' ] );
		add_action( 'cc_quote_question', [ self::class, 'question' ], 10, 2 );
		add_action( 'cc_booking_confirmed', [ self::class, 'booking_confirmed' ], 10, 2 );
		add_action( 'cc_booking_assigned', [ self::class, 'booking_assigned' ], 10, 3 );
		add_action( 'cc_booking_refunded', [ self::class, 'booking_refunded' ], 10, 3 );
		add_action( 'cc_booking_cancelled', [ self::class, 'booking_cancelled' ], 10, 2 );
		add_action( 'cc_booking_topup_created', [ self::class, 'topup_created' ], 10, 2 );
		add_action( 'cc_booking_topup_paid', [ self::class, 'topup_paid' ], 10, 2 );
		add_action( 'cc_quote_amended', [ self::class, 'quote_amended' ], 10, 3 );
		add_action( 'cc_bookings_admin_alert', [ self::class, 'admin_alert' ], 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Templates
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string, array{label: string, to: string, subject: string, body: string, button?: string, button_url?: string}>
	 */
	public static function defaults(): array {
		return apply_filters( 'cc_bookings_email_defaults', [
			'quote_ready'         => [
				'label'      => 'Quote ready (customer)',
				'to'         => 'customer',
				'subject'    => 'Your {service} quote {ref}',
				'body'       => "Hi {first_name},\n\nThanks for asking {brand} to quote. Your quote for {service} on {date} is ready in your secure client area, with your fixed price and everything that's included.\n\n{client_note}\n\nYour quote is held until {expires}. When you're ready, {next_step}.\n\n{link_note}",
				'button'     => 'View my quote',
				'button_url' => 'quote_url',
			],
			'quote_updated'       => [
				'label'      => 'Quote updated by us (customer)',
				'to'         => 'customer',
				'subject'    => 'Your quote has been updated: {service} {ref}',
				'body'       => "Hi {first_name},\n\nWe've updated your quote {ref} for {service}. Here's what has changed:\n\n{changes}\n\n{client_note}\n\nYour updated quote is held until {expires}. When you're ready, {next_step}.\n\n{link_note}",
				'button'     => 'View my updated quote',
				'button_url' => 'quote_url',
			],
			'quote_changed_admin' => [
				'label'      => 'Client changed their quote (admin)',
				'to'         => 'admin',
				'subject'    => 'Quote changed by {name}: {ref} ({service})',
				'body'       => "{name} ({email}, {customer_phone}) changed their quote {old_ref}. It has been replaced by {ref}.\n\n{changes}\n\n{status_note}\n\n{details}",
				'button'     => 'Open quote',
				'button_url' => 'admin_url',
			],
			'bespoke_received'    => [
				'label'   => 'Bespoke request received (customer)',
				'to'      => 'customer',
				'subject' => 'We\'ve got your quote request {ref}',
				'body'    => "Hi {first_name},\n\nThanks for your request for {service}. This one needs a tailored price, so one of our team will look at the details and send your quote — usually within the hour during opening times.\n\nIf you'd like to talk it through, call us on {phone}.",
			],
			'bespoke_admin'       => [
				'label'      => 'Bespoke request (admin)',
				'to'         => 'admin',
				'subject'    => 'Action needed: price quote {ref} ({service})',
				'body'       => "A customer needs a bespoke quote.\n\n{details}\n\nWhy it wasn't priced automatically: {reasons}",
				'button'     => 'Price this quote',
				'button_url' => 'admin_url',
			],
			'quote_reminder'      => [
				'label'      => 'Quote reminder after 24 hours (customer)',
				'to'         => 'customer',
				'subject'    => 'Your quote {ref} is still available',
				'body'       => "Hi {first_name},\n\nJust a reminder that your quote for {service} on {date} is still available. Slots fill up, so book soon to keep your date.\n\n{link_note}",
				'button'     => 'View my quote',
				'button_url' => 'quote_url',
			],
			'quote_expiring'      => [
				'label'      => 'Quote expires tomorrow (customer)',
				'to'         => 'customer',
				'subject'    => 'Your quote {ref} expires tomorrow',
				'body'       => "Hi {first_name},\n\nYour quote for {service} expires on {expires}. After that we'll need to re-check prices and availability.\n\n{link_note}",
				'button'     => 'View my quote',
				'button_url' => 'quote_url',
			],
			'quote_question'      => [
				'label'      => 'Customer question about a quote (admin)',
				'to'         => 'admin',
				'subject'    => 'Question about quote {ref}',
				'body'       => "{name} ({email}, {customer_phone}) asked about quote {ref}:\n\n{message}",
				'button'     => 'Open quote',
				'button_url' => 'admin_url',
			],
			'accept_request_admin' => [
				'label'      => 'Customer accepted, online payment off (admin)',
				'to'         => 'admin',
				'subject'    => 'Quote {ref} accepted — please take payment',
				'body'       => "{name} accepted quote {ref} ({total}) for {date}, but online payment isn't enabled yet. Contact them on {customer_phone} or {email} to take payment, then record it on the quote to confirm the booking.",
				'button'     => 'Open quote',
				'button_url' => 'admin_url',
			],
			'booking_confirmed'   => [
				'label'      => 'Booking confirmed (customer)',
				'to'         => 'customer',
				'subject'    => 'Booking confirmed: {service} on {date_short}',
				'body'       => "Hi {first_name},\n\nThank you — your payment of {paid} has been received and your booking {ref} is confirmed.\n\n{details}\n\n{items}\n\nWe'll let you know who your cleaner is before the day. If anything changes, reply to this email or call {phone}.",
				'button'     => 'View your booking',
				'button_url' => 'booking_url',
			],
			'booking_admin'       => [
				'label'      => 'Payment received (admin) — new bookings, regular-plan visits, pay links and top-ups',
				'to'         => 'admin',
				'subject'    => 'Payment received: {amount} for {ref} ({service}, {date_short})',
				'body'       => "{name} has paid {amount} — {payment_kind}.\n\n{details}\n\n{items}",
				'button'     => 'Open booking',
				'button_url' => 'admin_url',
			],
			'cleaner_assigned'    => [
				'label'   => 'Job assigned (cleaner)',
				'to'      => 'cleaner',
				'subject' => 'New job {ref}: {service} on {date_short}',
				'body'    => "Hi {cleaner_first_name},\n\nYou've been assigned a job.\n\n{job_details}\n\nPlease reply to confirm, or call the office on {phone} if there's a problem.",
			],
			'booking_refunded'    => [
				'label'   => 'Refund issued (customer)',
				'to'      => 'customer',
				'subject' => 'Refund for booking {ref}',
				'body'    => "Hi {first_name},\n\nWe've refunded {amount} for booking {ref}. It usually reaches your account within 5–10 working days.",
			],
			'booking_cancelled'   => [
				'label'   => 'Booking cancelled (customer)',
				'to'      => 'customer',
				'subject' => 'Booking {ref} cancelled',
				'body'    => "Hi {first_name},\n\nYour booking {ref} for {date} has been cancelled. {reason}\n\nIf you'd like to rebook, just get a new quote on our website or call {phone}.",
			],
			'topup_link'          => [
				'label'      => 'Top-up payment link (customer)',
				'to'         => 'customer',
				'subject'    => 'Payment request for booking {ref}',
				'body'       => "Hi {first_name},\n\nAs discussed, there's an additional charge of {amount} for booking {ref}: {reason}.\n\nYou can pay securely online using the button below.",
				'button'     => 'Pay {amount}',
				'button_url' => 'link',
			],
			'admin_alert'         => [
				'label'      => 'Urgent alert (admin)',
				'to'         => 'admin',
				'subject'    => 'Bookings alert: {subject}',
				'body'       => '{message}',
				'button'     => 'Open',
				'button_url' => 'admin_url',
			],
		] );
	}

	public static function template( string $key ): array {
		$template = self::defaults()[ $key ] ?? null;
		if ( ! $template ) {
			return [];
		}
		$custom = (array) Settings::get( 'emails.templates.' . $key, [] );
		foreach ( [ 'subject', 'body', 'button' ] as $field ) {
			if ( isset( $custom[ $field ] ) && '' !== trim( (string) $custom[ $field ] ) ) {
				$template[ $field ] = (string) $custom[ $field ];
			}
		}
		return $template;
	}

	/**
	 * Placeholders: values are escaped; those listed in $html_vars are trusted HTML built here.
	 *
	 * @return array{subject: string, html: string, text: string}
	 */
	public static function render( string $key, array $vars ): array {
		$template  = self::template( $key );
		$vars     += self::site_vars();
		$html_vars = (array) apply_filters( 'cc_bookings_email_html_vars', [ 'items', 'details', 'job_details' ] );

		$replace_text = function ( string $text ) use ( $vars ): string {
			return preg_replace_callback( '/\{([a-z_]+)\}/', fn( $m ) => array_key_exists( $m[1], $vars ) && ! is_array( $vars[ $m[1] ] ) ? wp_strip_all_tags( (string) $vars[ $m[1] ] ) : $m[0], $text );
		};

		$subject = $replace_text( (string) $template['subject'] );
		$body    = esc_html( (string) $template['body'] );
		$body    = preg_replace_callback( '/\{([a-z_]+)\}/', function ( $m ) use ( $vars, $html_vars ) {
			if ( ! array_key_exists( $m[1], $vars ) || is_array( $vars[ $m[1] ] ) ) {
				return $m[0];
			}
			if ( in_array( $m[1], $html_vars, true ) ) {
				return '' === (string) $vars[ $m[1] ] ? '' : "\n\n<!--html-->" . base64_encode( (string) $vars[ $m[1] ] ) . "<!--/html-->\n\n";
			}
			return nl2br( esc_html( (string) $vars[ $m[1] ] ) );
		}, $body );
		$body = wpautop( $body );
		$body = preg_replace_callback( '/(?:<p>)?<!--html-->([A-Za-z0-9+\/=]+)<!--\/html-->(?:<\/p>)?/', fn( $m ) => base64_decode( $m[1] ), $body );

		$button = '';
		if ( ! empty( $template['button'] ) && ! empty( $template['button_url'] ) && ! empty( $vars[ $template['button_url'] ] ) ) {
			$button = [ 'label' => $replace_text( $template['button'] ), 'url' => (string) $vars[ $template['button_url'] ] ];
		}

		ob_start();
		$email = [ 'subject' => $subject, 'content' => $body, 'button' => $button, 'footer' => $vars['footer'] ?? '', 'brand' => $vars['brand'] ];
		$email['manage_url'] = ! empty( $vars['manage_url'] ) && ( ! $button || $button['url'] !== $vars['manage_url'] ) ? (string) $vars['manage_url'] : '';
		include CC_BOOKINGS_DIR . '/templates/email.php';
		$html = (string) ob_get_clean();

		return [ 'subject' => $subject, 'html' => $html, 'text' => trim( wp_strip_all_tags( $body ) ), 'button_url' => $button ? $button['url'] : '' ];
	}

	/**
	 * @param array $object [ object_type, object_id ] for the mail log.
	 */
	public static function send( string $key, string $to, array $vars, array $object = [ 'system', 0 ] ): bool {
		$to       = sanitize_email( $to );
		$template = self::template( $key );
		if ( ! $to || ! $template ) {
			return false;
		}
		if ( 'admin' === ( $template['to'] ?? '' ) && self::admin_off( $key ) ) {
			Events::log( (string) $object[0], (int) $object[1], 'email_off', sprintf( '"%s" not sent: turned off in Settings → Emails', $template['label'] ), [ 'template' => $key ] );
			return false;
		}
		$vars     = (array) apply_filters( 'cc_bookings_email_vars', $vars, $key, $object );
		$mail     = self::render( $key, $vars );
		$from     = sanitize_email( (string) Settings::get( 'emails.from_address' ) );
		$headers  = [ 'Content-Type: text/html; charset=UTF-8' ];
		if ( $from ) {
			$headers[] = sprintf( 'From: %s <%s>', str_replace( [ '"', '<', '>' ], '', (string) ( Settings::get( 'emails.from_name' ) ?: Settings::brand() ) ), $from );
		}
		if ( 'customer' === ( self::template( $key )['to'] ?? '' ) && ( $reply = sanitize_email( (string) ( function_exists( 'cc_option' ) ? cc_option( 'email' ) : '' ) ) ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		$log_only = (bool) Settings::get( 'emails.log_only' );
		if ( $log_only ) {
			add_filter( 'pre_wp_mail', [ self::class, 'short_circuit' ], 999 );
		}
		$sent = wp_mail( $to, $mail['subject'], $mail['html'], $headers );
		if ( $log_only ) {
			remove_filter( 'pre_wp_mail', [ self::class, 'short_circuit' ], 999 );
		}

		Events::log( (string) $object[0], (int) $object[1], $sent ? 'email_sent' : 'email_failed', $mail['subject'], [
			'template' => $key,
			'to'       => $to,
			'log_only' => $log_only,
			'excerpt'  => mb_substr( preg_replace( '/\s+/', ' ', $mail['text'] ), 0, 400 ),
		] + ( $log_only && $mail['button_url'] ? [ 'button_url' => $mail['button_url'] ] : [] ) );
		return $sent;
	}

	public static function short_circuit(): bool {
		return true;
	}

	/** Admin emails can be switched off one by one (Settings → Emails). */
	public static function admin_off( string $key ): bool {
		return ! empty( ( (array) Settings::get( 'emails.admin_off', [] ) )[ $key ] );
	}

	/** Phase 2 plugs an SMS provider into this filter and returns true when sent. */
	public static function sms( string $to, string $text, array $object = [ 'system', 0 ] ): bool {
		$sent = (bool) apply_filters( 'cc_bookings_send_sms', false, $to, $text );
		Events::log( (string) $object[0], (int) $object[1], $sent ? 'sms_sent' : 'sms_skipped', mb_substr( $text, 0, 160 ), [ 'to' => $to ] );
		return $sent;
	}

	/* ---------------------------------------------------------------------
	 * Variables
	 * ------------------------------------------------------------------ */

	public static function site_vars(): array {
		$phone = function_exists( 'cc_phone_display' ) ? cc_phone_display() : '';
		$email = function_exists( 'cc_option' ) ? (string) cc_option( 'email' ) : '';
		$brand = Settings::brand();
		return [
			'brand'  => $brand,
			'phone'  => $phone,
			'footer' => implode( ' · ', array_filter( [ $brand, $phone, $email, function_exists( 'cc_full_address' ) ? cc_full_address() : '' ] ) ),
		];
	}

	public static function first_name( string $name ): string {
		return trim( strtok( trim( $name ), ' ' ) ?: $name );
	}

	public static function items_html( array $items, ?int $total, string $total_label = 'Total' ): string {
		if ( ! $items ) {
			return '';
		}
		$rows = '';
		foreach ( $items as $item ) {
			$qty   = (float) ( $item['qty'] ?? $item['quantity'] ?? 1 );
			$label = ( $item['label'] ?? '' ) . ( $qty > 1 ? ' × ' . rtrim( rtrim( number_format( $qty, 2 ), '0' ), '.' ) : '' );
			$rows .= sprintf( '<tr><td style="padding:6px 0;border-bottom:1px solid #e5edf0;">%s</td><td style="padding:6px 0;border-bottom:1px solid #e5edf0;text-align:right;white-space:nowrap;">%s</td></tr>', esc_html( $label ), esc_html( Money::format( (int) ( $item['amount'] ?? $item['amount_pence'] ?? 0 ) ) ) );
		}
		if ( null !== $total ) {
			$rows .= sprintf( '<tr><td style="padding:10px 0 0;font-weight:700;">%s</td><td style="padding:10px 0 0;text-align:right;font-weight:700;">%s</td></tr>', esc_html( $total_label ), esc_html( Money::format( $total ) ) );
		}
		return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:8px 0 16px;">' . $rows . '</table>';
	}

	public static function details_html( array $lines ): string {
		$rows = '';
		foreach ( $lines as [ $label, $value ] ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$rows .= sprintf( '<tr><td style="padding:4px 12px 4px 0;color:#556b78;vertical-align:top;white-space:nowrap;">%s</td><td style="padding:4px 0;">%s</td></tr>', esc_html( $label ), nl2br( esc_html( (string) $value ) ) );
		}
		return '<table role="presentation" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:8px 0 16px;">' . $rows . '</table>';
	}

	public static function quote_vars( array $quote, string $url = '' ): array {
		$service = Quotes::service( $quote );
		$lines   = [
			[ 'Reference', $quote['ref'] ],
			[ 'Service', $quote['service_name'] ],
			[ 'Date', $quote['job_date'] ? Slots::date_label( $quote['job_date'] ) . ( $quote['slot'] ? ', ' . Slots::label( $quote['slot'] ) : '' ) : 'Not chosen' ],
			[ 'Customer', $quote['customer_name'] ],
			[ 'Email', $quote['email'] ],
			[ 'Phone', $quote['phone'] ],
			[ 'Address', implode( ', ', array_filter( [ $quote['address_1'], $quote['address_2'], $quote['town'], $quote['postcode'] ] ) ) ],
		];
		if ( $service ) {
			$lines = array_merge( $lines, Catalog::summary( $service, (array) $quote['answers'] ) );
			if ( $addons = Catalog::addon_names( $service, (array) $quote['answers'] ) ) {
				$lines[] = [ 'Add-ons', implode( ', ', $addons ) ];
			}
		}
		if ( $quote['photos'] ) {
			$lines[] = [ 'Photos', count( (array) $quote['photos'] ) . ' attached (view in admin)' ];
		}

		return [
			'name'       => $quote['customer_name'],
			'first_name' => self::first_name( $quote['customer_name'] ),
			'email'      => $quote['email'],
			'ref'        => $quote['ref'],
			'service'    => $quote['service_name'],
			'date'       => $quote['job_date'] ? Slots::date_label( $quote['job_date'] ) . ( $quote['slot'] ? ', ' . strtolower( Slots::label( $quote['slot'] ) ) : '' ) : 'a date to be agreed',
			'date_short' => $quote['job_date'] ? Slots::date_label( $quote['job_date'], 'D j M' ) : '',
			'total'      => null !== $quote['total_pence'] ? Money::format( (int) $quote['total_pence'] ) : '',
			'items'      => self::items_html( (array) $quote['items'], null !== $quote['total_pence'] ? (int) $quote['total_pence'] : null ),
			'details'    => self::details_html( $lines ),
			'reasons'    => implode( '; ', (array) $quote['bespoke_reasons'] ),
			'expires'    => $quote['expires_at'] ? wp_date( 'l j F', Db::timestamp( $quote['expires_at'] ) ) : '',
			'quote_url'  => $url,
			'link_note'  => '',
			'next_step'  => Stripe::enabled() ? 'open it to agree the terms and pay securely — your booking is confirmed straight away' : "open it to agree the terms and we'll call you to take payment and confirm your booking",
			'admin_url'  => Quotes::admin_url( $quote['id'] ),
			'customer_phone' => $quote['phone'],
		];
	}

	public static function booking_vars( array $booking, string $url = '' ): array {
		$service = Catalog::get( $booking['service_slug'] );
		$lines   = [
			[ 'Booking', $booking['ref'] ],
			[ 'Service', $booking['service_name'] ],
			[ 'When', Bookings::start_label( $booking ) ],
			[ 'Estimated time', Pricing::duration_label( (float) $booking['hours'], (int) $booking['cleaners'] ) ],
			[ 'Address', Bookings::full_address( $booking ) ],
		];
		if ( $service ) {
			$lines = array_merge( $lines, Catalog::summary( $service, (array) $booking['answers'] ) );
		}
		if ( $booking['access_notes'] ) {
			$lines[] = [ 'Access notes', $booking['access_notes'] ];
		}
		$items = Bookings::items( $booking['id'] );

		return [
			'name'       => $booking['customer_name'],
			'first_name' => self::first_name( $booking['customer_name'] ),
			'email'      => $booking['email'],
			'customer_phone' => $booking['phone'],
			'ref'        => $booking['ref'],
			'service'    => $booking['service_name'],
			'date'       => Bookings::start_label( $booking ),
			'date_short' => $booking['job_date'] ? Slots::date_label( $booking['job_date'], 'D j M' ) : '',
			'total'      => Money::format( (int) $booking['total_pence'] ),
			'paid'       => Money::format( (int) $booking['paid_pence'] ),
			'items'      => self::items_html( $items, (int) $booking['total_pence'], 'Total paid' ),
			'details'    => self::details_html( $lines ),
			'booking_url' => $url,
			'admin_url'  => Bookings::admin_url( $booking['id'] ),
		];
	}

	private static function admin_to(): string {
		return Settings::admin_email();
	}

	/* ---------------------------------------------------------------------
	 * Listeners
	 * ------------------------------------------------------------------ */

	/** "Your quote is ready", or "Your quote has been updated" when there are changes the client hasn't been told about. */
	public static function quote_sent( $quote_id, $context = [] ): void {
		$quote = Quotes::get( (int) $quote_id );
		if ( ! $quote ) {
			return;
		}
		$vars    = self::quote_vars( $quote, (string) ( $context['url'] ?? '' ) );
		$pending = Quote_Revisions::pending( $quote );
		$vars['client_note'] = Quote_Revisions::note_html( array_unique( array_merge( $pending['notes'], [ (string) ( $context['client_note'] ?? '' ) ] ) ) );
		if ( $pending['changes'] ) {
			$vars['changes'] = Quote_Revisions::html( $pending['changes'], null === $quote['total_pence'] ? null : (int) $quote['total_pence'], '' );
			self::send( 'quote_updated', $quote['email'], $vars, [ 'quote', $quote['id'] ] );
		} else {
			self::send( 'quote_ready', $quote['email'], $vars, [ 'quote', $quote['id'] ] );
		}
		Quote_Revisions::mark_notified( $pending['ids'] );
	}

	public static function bespoke_requested( $quote_id, $context = [] ): void {
		$quote = Quotes::get( (int) $quote_id );
		if ( ! $quote ) {
			return;
		}
		$vars = self::quote_vars( $quote );
		self::send( 'bespoke_received', $quote['email'], $vars, [ 'quote', $quote['id'] ] );
		// A customer's change that now needs pricing is covered by the "client changed their quote" email.
		if ( ! (int) $quote['replaces_id'] ) {
			self::send( 'bespoke_admin', self::admin_to(), $vars, [ 'quote', $quote['id'] ] );
		}
	}

	/** `cc_quote_amended`: the customer changed their quote ("Change details" made a new one). */
	public static function quote_amended( $quote_id, $old_id = 0, $changes = [] ): void {
		$quote = Quotes::get( (int) $quote_id );
		$old   = Quotes::get( (int) $old_id );
		if ( ! $quote ) {
			return;
		}
		$total = null === $quote['total_pence'] ? null : (int) $quote['total_pence'];
		self::send( 'quote_changed_admin', self::admin_to(), [
			'old_ref'     => $old['ref'] ?? '',
			'changes'     => $changes ? Quote_Revisions::html( (array) $changes, $total, 'What they changed' ) : '<p>Nothing the customer can see changed.</p>',
			'status_note' => $quote['is_bespoke']
				? sprintf( 'The new version needs a tailored price (%s). Price it and send it from the quote.', implode( '; ', (array) $quote['bespoke_reasons'] ) )
				: 'The new price was worked out automatically and the customer has been emailed it.',
		] + self::quote_vars( $quote ), [ 'quote', $quote['id'] ] );
	}

	public static function accept_requested( $quote_id ): void {
		$quote = Quotes::get( (int) $quote_id );
		if ( $quote ) {
			self::send( 'accept_request_admin', self::admin_to(), self::quote_vars( $quote ), [ 'quote', $quote['id'] ] );
		}
	}

	public static function question( $quote_id, $message ): void {
		$quote = Quotes::get( (int) $quote_id );
		if ( $quote ) {
			self::send( 'quote_question', self::admin_to(), [ 'message' => (string) $message ] + self::quote_vars( $quote ), [ 'quote', $quote['id'] ] );
		}
	}

	public static function booking_confirmed( $booking_id, $context = [] ): void {
		$booking = Bookings::get( (int) $booking_id );
		if ( ! $booking ) {
			return;
		}
		self::send( 'booking_confirmed', $booking['email'], self::booking_vars( $booking, (string) ( $context['booking_url'] ?? '' ) ), [ 'booking', $booking['id'] ] );
		// Payments the office recorded itself (paid by hand) don't need an email back to the office.
		if ( ! in_array( (string) ( $context['provider'] ?? '' ), [ 'manual', 'reclean' ], true ) ) {
			self::payment_admin( $booking, (int) $booking['paid_pence'], self::payment_kind( $booking, (array) $context ) );
		}
	}

	/** One "Payment received" email to the office for every customer payment. */
	public static function payment_admin( array $booking, int $amount, string $kind ): void {
		self::send( 'booking_admin', self::admin_to(), [ 'amount' => Money::format( $amount ), 'payment_kind' => $kind ] + self::booking_vars( $booking ), [ 'booking', $booking['id'] ] );
	}

	private static function payment_kind( array $booking, array $context ): string {
		if ( ! empty( $context['plan_id'] ) ) {
			$visit = (int) ( $booking['meta']['visit_no'] ?? 0 );
			$how   = (string) ( $context['how'] ?? '' );
			$hows  = [
				'automatic charge' => 'automatic charge to the saved card',
				'pay link'         => 'paid with the pay link after the automatic charge failed',
				'card_updated'     => 'charged after the customer updated their card',
			];
			return sprintf( 'regular plan visit%s, %s', $visit ? ' ' . $visit : '', $hows[ $how ] ?? 'card payment' );
		}
		return (int) $booking['series_id'] ? 'new regular plan booked (first visit confirmed)' : 'new booking confirmed';
	}

	public static function topup_paid( $booking_id, $payment_id ): void {
		$booking = Bookings::get( (int) $booking_id );
		$payment = Db::get( 'payments', (int) $payment_id );
		if ( $booking && $payment ) {
			self::payment_admin( $booking, (int) $payment['amount_pence'], 'top-up: ' . ( $payment['note'] ?: 'additional charge' ) );
		}
	}

	public static function booking_assigned( $booking_id, $cleaner_id, $offer_id = 0 ): void {
		$booking = Bookings::get( (int) $booking_id );
		$cleaner = Cleaners::get( (int) $cleaner_id );
		if ( ! $booking || ! $cleaner ) {
			return;
		}
		$vars  = self::booking_vars( $booking );
		$lines = [
			[ 'Job', $booking['ref'] . ' — ' . $booking['service_name'] ],
			[ 'When', Bookings::start_label( $booking ) ],
			[ 'Length', Pricing::duration_label( (float) $booking['hours'], (int) $booking['cleaners'] ) ],
			[ 'Address', Bookings::full_address( $booking ) ],
			[ 'Customer', self::first_name( $booking['customer_name'] ) . ' — ' . $booking['phone'] ],
			[ 'Access', $booking['access_notes'] ],
		];
		$service = Catalog::get( $booking['service_slug'] );
		if ( $service ) {
			$lines = array_merge( $lines, Catalog::summary( $service, (array) $booking['answers'] ) );
			if ( $addons = Catalog::addon_names( $service, (array) $booking['answers'] ) ) {
				$lines[] = [ 'Extras', implode( ', ', $addons ) ];
			}
		}
		$vars['cleaner_first_name'] = self::first_name( $cleaner['name'] );
		$vars['job_details']        = self::details_html( $lines );
		self::send( 'cleaner_assigned', $cleaner['email'], $vars, [ 'booking', $booking['id'] ] );
	}

	public static function booking_refunded( $booking_id, $amount, $payment_id = 0 ): void {
		$booking = Bookings::get( (int) $booking_id );
		if ( $booking ) {
			self::send( 'booking_refunded', $booking['email'], [ 'amount' => Money::format( (int) $amount ) ] + self::booking_vars( $booking ), [ 'booking', $booking['id'] ] );
		}
	}

	public static function booking_cancelled( $booking_id, $reason = '' ): void {
		$booking = Bookings::get( (int) $booking_id );
		if ( $booking ) {
			self::send( 'booking_cancelled', $booking['email'], [ 'reason' => (string) $reason ] + self::booking_vars( $booking ), [ 'booking', $booking['id'] ] );
		}
	}

	public static function topup_created( $booking_id, $payment_id ): void {
		$booking = Bookings::get( (int) $booking_id );
		$payment = Db::get( 'payments', (int) $payment_id );
		if ( $booking && $payment ) {
			self::send( 'topup_link', $booking['email'], [
				'amount' => Money::format( (int) $payment['amount_pence'] ),
				'reason' => $payment['note'],
				'link'   => (string) $payment['url'],
			] + self::booking_vars( $booking ), [ 'booking', $booking['id'] ] );
		}
	}

	public static function admin_alert( $subject, $message, $context = [] ): void {
		$url = ! empty( $context['booking_id'] ) ? Bookings::admin_url( (int) $context['booking_id'] ) : admin_url( 'admin.php?page=cc-bookings' );
		self::send( 'admin_alert', self::admin_to(), [ 'subject' => (string) $subject, 'message' => (string) $message, 'admin_url' => $url ], [ ! empty( $context['booking_id'] ) ? 'booking' : 'system', (int) ( $context['booking_id'] ?? 0 ) ] );
	}
}

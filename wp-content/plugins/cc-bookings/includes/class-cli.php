<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Bookings tools: `wp cc-bookings <command>`.
 */
final class Cli {
	/**
	 * Confirm a quote's payment through the same path as Stripe's checkout.session.completed webhook.
	 *
	 * ## OPTIONS
	 *
	 * <ref>
	 * : Quote reference, e.g. Q-12345.
	 *
	 * [--amount=<pounds>]
	 * : Amount paid. Defaults to the quote total.
	 *
	 * [--twice]
	 * : Deliver the same event twice, to check idempotency.
	 *
	 * [--revision=<n>]
	 * : Quote revision the payment was started on. Defaults to the latest; an older one is refused.
	 *
	 * @subcommand simulate-payment
	 */
	public function simulate_payment( array $args, array $assoc ): void {
		$quote = Quotes::by_ref( $args[0] );
		if ( ! $quote ) {
			\WP_CLI::error( 'Quote not found: ' . $args[0] );
		}
		$amount = isset( $assoc['amount'] ) ? (int) Money::parse( $assoc['amount'] ) : (int) $quote['total_pence'];
		$event  = [
			'id'       => 'evt_sim_' . wp_generate_password( 12, false ),
			'type'     => 'checkout.session.completed',
			'livemode' => false,
			'data'     => [
				'object' => [
					'id'             => $quote['stripe_session_id'] && str_starts_with( $quote['stripe_session_id'], 'cs_sim_' ) ? $quote['stripe_session_id'] : 'cs_sim_' . $quote['id'] . '_' . wp_generate_password( 10, false ),
					'object'         => 'checkout.session',
					'payment_status' => 'paid',
					'status'         => 'complete',
					'amount_total'   => $amount,
					'currency'       => 'gbp',
					'payment_intent' => 'pi_sim_' . $quote['id'],
					'customer'       => 'cus_sim_' . $quote['id'],
					'metadata'       => [ 'type' => 'booking', 'quote_id' => (string) $quote['id'], 'quote_ref' => $quote['ref'], 'revision' => (string) ( isset( $assoc['revision'] ) ? (int) $assoc['revision'] : Quote_Revisions::revision( $quote ) ) ],
				],
			],
		];
		$runs = isset( $assoc['twice'] ) ? 2 : 1;
		for ( $i = 1; $i <= $runs; $i++ ) {
			$result = Stripe::handle_event( $event );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			\WP_CLI::log( sprintf( 'Delivery %d: %s', $i, $result ) );
		}
		$quote   = Quotes::get( $quote['id'] );
		$booking = $quote['booking_id'] ? Bookings::get( $quote['booking_id'] ) : null;
		if ( ! $booking ) {
			\WP_CLI::error( 'No booking was created.' );
		}
		\WP_CLI::success( sprintf( '%s confirmed as %s (%s, %s paid). Admin: %s', $quote['ref'], $booking['ref'], $booking['status'], Money::format( $booking['paid_pence'] ), Bookings::admin_url( $booking['id'] ) ) );
	}

	/**
	 * Check webhook signature verification: valid, tampered, wrong secret and stale signatures.
	 *
	 * @subcommand test-signature
	 */
	public function test_signature(): void {
		$secret  = 'whsec_' . wp_generate_password( 24, false );
		$payload = wp_json_encode( [ 'id' => 'evt_test', 'type' => 'checkout.session.completed' ] );
		$now     = time();
		$cases   = [
			'valid'            => [ Stripe::verify_signature( $payload, Stripe::sign( $payload, $secret, $now ), $secret ), true ],
			'tampered payload' => [ Stripe::verify_signature( $payload . ' ', Stripe::sign( $payload, $secret, $now ), $secret ), false ],
			'wrong secret'     => [ Stripe::verify_signature( $payload, Stripe::sign( $payload, 'whsec_other', $now ), $secret ), false ],
			'older than 5 min' => [ Stripe::verify_signature( $payload, Stripe::sign( $payload, $secret, $now - 400 ), $secret ), false ],
			'extra v0 + v1'    => [ Stripe::verify_signature( $payload, Stripe::sign( $payload, $secret, $now ) . ',v0=abc', $secret ), true ],
			'missing header'   => [ Stripe::verify_signature( $payload, '', $secret ), false ],
		];
		$failed = 0;
		foreach ( $cases as $name => [ $got, $want ] ) {
			$ok      = $got === $want;
			$failed += $ok ? 0 : 1;
			\WP_CLI::log( sprintf( '%s %-18s %s', $ok ? 'PASS' : 'FAIL', $name, $got ? 'accepted' : 'rejected' ) );
		}
		$failed ? \WP_CLI::error( $failed . ' case(s) failed' ) : \WP_CLI::success( 'Signature verification behaves correctly.' );
	}

	/**
	 * Configuration and health.
	 */
	public function status(): void {
		global $wpdb;
		$rows = [
			[ 'item' => 'Plugin version', 'value' => CC_BOOKINGS_VERSION ],
			[ 'item' => 'DB schema', 'value' => (string) get_option( Schema::OPTION ) ],
			[ 'item' => 'Stripe', 'value' => Stripe::enabled() ? ( Stripe::test_mode() ? 'enabled (test)' : 'enabled (live)' ) : 'not configured' ],
			[ 'item' => 'Webhook secret', 'value' => Stripe::webhook_secret() ? 'set' : 'not set' ],
			[ 'item' => 'Webhook URL', 'value' => Stripe::webhook_url() ],
			[ 'item' => 'Turnstile', 'value' => Security::turnstile_site_key() ? 'enabled' : 'not configured' ],
			[ 'item' => 'Queue', 'value' => Queue::driver() ],
			[ 'item' => 'Emails', 'value' => Settings::get( 'emails.log_only' ) ? 'log only' : 'sending' ],
			[ 'item' => 'Covered districts', 'value' => implode( ' ', Postcodes::covered_districts() ) ],
		];
		foreach ( [ 'quotes', 'bookings', 'booking_items', 'cleaners', 'cleaner_availability', 'job_offers', 'payments', 'events' ] as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( $table ) ) );
			$rows[] = [ 'item' => 'Table ' . $table, 'value' => $exists ? Db::count( $table ) . ' rows' : 'MISSING' ];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'item', 'value' ] );
	}

	/**
	 * Price a job from the command line.
	 *
	 * ## OPTIONS
	 *
	 * <service>
	 * : Service slug.
	 *
	 * [--answers=<json>]
	 * : Answers JSON, e.g. '{"size":1,"condition":"average"}'.
	 *
	 * [--date=<date>]
	 * : Job date, YYYY-MM-DD.
	 *
	 * [--slot=<slot>]
	 * : Arrival slot key, e.g. morning.
	 */
	public function price( array $args, array $assoc ): void {
		$service = Catalog::get( $args[0] );
		if ( ! $service ) {
			\WP_CLI::error( 'Unknown service. Try: ' . implode( ', ', array_keys( Catalog::services() ) ) );
		}
		[ $answers, $errors ] = Catalog::sanitize_answers( $service, (array) json_decode( (string) ( $assoc['answers'] ?? '{}' ), true ) );
		foreach ( $errors as $field => $message ) {
			\WP_CLI::warning( $field . ': ' . $message );
		}
		$result = Pricing::price( $service, $answers, (string) ( $assoc['date'] ?? '' ), (string) ( $assoc['slot'] ?? '' ) );
		if ( $result['bespoke'] ) {
			\WP_CLI::log( 'Bespoke: ' . implode( '; ', $result['reasons'] ) );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', array_map( fn( $i ) => [ 'type' => $i['type'], 'label' => $i['label'], 'qty' => $i['qty'], 'amount' => Money::format( $i['amount'] ) ], $result['items'] ), [ 'type', 'label', 'qty', 'amount' ] );
		\WP_CLI::log( sprintf( 'Total %s · %s', Money::format( $result['total'] ), Pricing::duration_label( $result['hours'], $result['cleaners'] ) ) );
	}

	/**
	 * Recent emails from the activity log.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Default 20.
	 *
	 * @subcommand mail-log
	 */
	public function mail_log( array $args, array $assoc ): void {
		$rows = Db::all( 'events', [ 'event' => [ 'email_sent', 'email_failed' ] ], 'id DESC', (int) ( $assoc['limit'] ?? 20 ) );
		\WP_CLI\Utils\format_items( 'table', array_map( fn( $e ) => [
			'when'     => $e['created_at'],
			'object'   => $e['object_type'] . ' ' . $e['object_id'],
			'template' => $e['data']['template'] ?? '',
			'to'       => $e['data']['to'] ?? '',
			'status'   => 'email_sent' === $e['event'] ? ( empty( $e['data']['log_only'] ) ? 'sent' : 'logged' ) : 'FAILED',
			'subject'  => $e['message'],
		], $rows ), [ 'when', 'object', 'template', 'to', 'status', 'subject' ] );
	}

	/**
	 * Read or change a setting (dot path).
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : e.g. emails.log_only
	 *
	 * [<value>]
	 * : New value (JSON allowed).
	 */
	public function setting( array $args ): void {
		if ( isset( $args[1] ) ) {
			$value = json_decode( $args[1], true );
			Settings::set( $args[0], null === $value ? $args[1] : $value );
		}
		\WP_CLI::log( $args[0] . ' = ' . wp_json_encode( Settings::get( $args[0] ) ) );
	}

	/**
	 * Delete test quotes and bookings (is_test = 1: example.com emails or "[test]" in the name) and everything linked to them.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @subcommand purge-test
	 */
	public function purge_test( array $args, array $assoc ): void {
		$quotes   = Db::all( 'quotes', [ 'is_test' => 1 ] );
		$bookings = Db::all( 'bookings', [ 'is_test' => 1 ] );
		if ( ! $quotes && ! $bookings ) {
			\WP_CLI::success( 'No test data.' );
			return;
		}
		\WP_CLI::confirm( sprintf( 'Delete %d test quote(s) and %d test booking(s)?', count( $quotes ), count( $bookings ) ), $assoc );

		$quote_ids   = array_map( 'intval', array_column( $quotes, 'id' ) );
		$booking_ids = array_map( 'intval', array_column( $bookings, 'id' ) );
		foreach ( $quotes as $quote ) {
			Files::delete_for( $quote['ref'] );
			Queue::cancel( 'cc_bookings_quote_followup', [ $quote['id'], 'reminder' ] );
			Queue::cancel( 'cc_bookings_quote_followup', [ $quote['id'], 'expiring' ] );
			Queue::cancel( 'cc_bookings_quote_expire', [ $quote['id'] ] );
		}
		if ( $booking_ids ) {
			Db::delete( 'booking_items', [ 'booking_id' => $booking_ids ] );
			Db::delete( 'job_offers', [ 'booking_id' => $booking_ids ] );
			Db::delete( 'payments', [ 'booking_id' => $booking_ids ] );
			Db::delete( 'events', [ 'object_type' => 'booking', 'object_id' => $booking_ids ] );
			Db::delete( 'bookings', [ 'id' => $booking_ids ] );
		}
		if ( $quote_ids ) {
			Db::delete( 'payments', [ 'quote_id' => $quote_ids ] );
			Db::delete( 'events', [ 'object_type' => 'quote', 'object_id' => $quote_ids ] );
			Db::delete( 'quotes', [ 'id' => $quote_ids ] );
		}
		\WP_CLI::success( sprintf( 'Deleted %d quote(s) and %d booking(s).', count( $quote_ids ), count( $booking_ids ) ) );
	}
}

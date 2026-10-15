<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe over its REST API (no SDK). Keys come from wp-config constants:
 *   CC_STRIPE_SECRET          sk_test_… / sk_live_…
 *   CC_STRIPE_WEBHOOK_SECRET  whsec_… for the endpoint /wp-json/cc/v1/stripe
 * Bookings are confirmed only from the signed webhook, never from the browser redirect.
 */
final class Stripe {
	const API       = 'https://api.stripe.com/v1/';
	const TOLERANCE = 300;

	public static function enabled(): bool {
		return defined( 'CC_STRIPE_SECRET' ) && '' !== (string) CC_STRIPE_SECRET;
	}

	public static function webhook_secret(): string {
		return defined( 'CC_STRIPE_WEBHOOK_SECRET' ) ? (string) CC_STRIPE_WEBHOOK_SECRET : '';
	}

	public static function test_mode(): bool {
		return self::enabled() && str_starts_with( (string) CC_STRIPE_SECRET, 'sk_test_' );
	}

	public static function webhook_url(): string {
		return rest_url( 'cc/v1/stripe' );
	}

	/**
	 * @return array|\WP_Error Decoded Stripe object.
	 */
	public static function request( string $method, string $path, array $params = [], string $idempotency_key = '' ) {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'stripe_disabled', 'Stripe is not configured.' );
		}
		$headers = [ 'Authorization' => 'Bearer ' . CC_STRIPE_SECRET ];
		if ( $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}
		$args = [ 'method' => $method, 'timeout' => 30, 'headers' => $headers ];
		if ( $params ) {
			if ( 'GET' === $method ) {
				$path .= '?' . http_build_query( $params );
			} else {
				$args['body'] = http_build_query( $params );
			}
		}

		$response = wp_remote_request( self::API . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || ! is_array( $body ) ) {
			$message = $body['error']['message'] ?? 'Stripe error (HTTP ' . $code . ')';
			return new \WP_Error( 'stripe_' . ( $body['error']['code'] ?? $code ), $message, [ 'status' => $code ] );
		}
		return $body;
	}

	/**
	 * @param array $urls success_url, cancel_url, expires_at
	 */
	public static function create_checkout_session( array $quote, array $urls ) {
		$service = $quote['service_name'];
		$when    = $quote['job_date'] ? Slots::date_label( $quote['job_date'], 'D j M' ) . ', ' . Slots::label( $quote['slot'] ) : '';
		$lines   = array_map( fn( $i ) => $i['label'] . ' ' . Money::format( (int) $i['amount'] ), (array) $quote['items'] );

		$params = [
			'mode'                => 'payment',
			'client_reference_id' => $quote['ref'],
			'customer_email'      => $quote['email'],
			'success_url'         => $urls['success_url'],
			'cancel_url'          => $urls['cancel_url'],
			'expires_at'          => (int) $urls['expires_at'],
			'locale'              => 'en-GB',
			'line_items'          => [
				[
					'quantity'   => 1,
					'price_data' => [
						'currency'     => 'gbp',
						'unit_amount'  => (int) $quote['total_pence'],
						'product_data' => [
							'name'        => mb_substr( trim( $service . ( $when ? ' — ' . $when : '' ) ), 0, 250 ),
							'description' => mb_substr( implode( ' · ', $lines ), 0, 490 ),
						],
					],
				],
			],
			'metadata'            => [
				'type'      => 'booking',
				'quote_id'  => $quote['id'],
				'quote_ref' => $quote['ref'],
			],
			'payment_intent_data' => [
				'description'   => sprintf( '%s %s', $quote['ref'], $service ),
				'receipt_email' => $quote['email'],
				'metadata'      => [ 'quote_ref' => $quote['ref'] ],
			],
		];
		return self::request( 'POST', 'checkout/sessions', $params, 'cc-checkout-' . $quote['id'] . '-' . $urls['expires_at'] );
	}

	public static function expire_session( string $session_id ): void {
		if ( self::enabled() && str_starts_with( $session_id, 'cs_' ) ) {
			self::request( 'POST', 'checkout/sessions/' . rawurlencode( $session_id ) . '/expire' );
		}
	}

	public static function refund( string $payment_intent, int $amount, array $metadata, string $idempotency_key ) {
		return self::request( 'POST', 'refunds', [
			'payment_intent' => $payment_intent,
			'amount'         => $amount,
			'metadata'       => $metadata,
		], $idempotency_key );
	}

	/** One-off Stripe Payment Link for an extra charge on a booking. */
	public static function create_payment_link( array $booking, int $amount, string $label ) {
		$price = self::request( 'POST', 'prices', [
			'currency'     => 'gbp',
			'unit_amount'  => $amount,
			'product_data' => [ 'name' => mb_substr( $booking['ref'] . ' — ' . $label, 0, 250 ) ],
		] );
		if ( is_wp_error( $price ) ) {
			return $price;
		}
		return self::request( 'POST', 'payment_links', [
			'line_items'          => [ [ 'price' => $price['id'], 'quantity' => 1 ] ],
			'metadata'            => [ 'type' => 'top_up', 'booking_id' => $booking['id'], 'booking_ref' => $booking['ref'] ],
			'payment_intent_data' => [ 'metadata' => [ 'type' => 'top_up', 'booking_ref' => $booking['ref'] ] ],
			'restrictions'        => [ 'completed_sessions' => [ 'limit' => 1 ] ],
		] );
	}

	public static function deactivate_payment_link( string $id ): void {
		if ( self::enabled() && str_starts_with( $id, 'plink_' ) ) {
			self::request( 'POST', 'payment_links/' . rawurlencode( $id ), [ 'active' => 'false' ] );
		}
	}

	/**
	 * Stripe-Signature check: HMAC-SHA256 of "{t}.{payload}" with the endpoint secret must match a v1 signature,
	 * and the timestamp must be within the tolerance.
	 */
	public static function verify_signature( string $payload, string $header, string $secret, int $tolerance = self::TOLERANCE, ?int $now = null ): bool {
		if ( '' === $secret || '' === $header ) {
			return false;
		}
		$timestamp  = 0;
		$signatures = [];
		foreach ( explode( ',', $header ) as $part ) {
			[ $key, $value ] = array_pad( explode( '=', trim( $part ), 2 ), 2, '' );
			if ( 't' === $key ) {
				$timestamp = (int) $value;
			} elseif ( 'v1' === $key ) {
				$signatures[] = $value;
			}
		}
		if ( ! $timestamp || ! $signatures || abs( ( $now ?? time() ) - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	public static function sign( string $payload, string $secret, ?int $timestamp = null ): string {
		$timestamp = $timestamp ?? time();
		return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	}

	/** REST callback for POST /wp-json/cc/v1/stripe. */
	public static function webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$secret = self::webhook_secret();
		if ( '' === $secret ) {
			return new \WP_REST_Response( [ 'error' => 'Webhook not configured' ], 503 );
		}
		$payload = $request->get_body();
		if ( ! self::verify_signature( $payload, (string) $request->get_header( 'stripe_signature' ), $secret ) ) {
			Events::log( 'system', 0, 'stripe_bad_signature', 'Rejected webhook with an invalid signature', [], 'stripe' );
			return new \WP_REST_Response( [ 'error' => 'Invalid signature' ], 400 );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
		}

		$result = self::handle_event( $event );
		if ( is_wp_error( $result ) ) {
			Events::log( 'system', 0, 'stripe_webhook_error', $result->get_error_message(), [ 'event' => $event['id'] ?? '', 'type' => $event['type'] ], 'stripe' );
			return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );
		}
		return new \WP_REST_Response( [ 'received' => true, 'result' => $result ], 200 );
	}

	/**
	 * Process a verified Stripe event. Also called by `wp cc-bookings simulate-payment`, so the CLI test
	 * runs exactly the same confirmation path as a real webhook.
	 *
	 * @return string|\WP_Error What was done.
	 */
	public static function handle_event( array $event ) {
		$object = $event['data']['object'] ?? [];
		switch ( $event['type'] ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				if ( ! empty( $object['payment_link'] ) ) {
					return Bookings::topup_paid( $object );
				}
				if ( ( $object['metadata']['type'] ?? '' ) !== 'booking' ) {
					return 'ignored: not a booking session';
				}
				if ( 'paid' !== ( $object['payment_status'] ?? '' ) ) {
					return 'waiting: payment not yet settled';
				}
				$booking = Bookings::confirm_payment( [
					'quote_id'       => (int) ( $object['metadata']['quote_id'] ?? 0 ),
					'session_id'     => (string) $object['id'],
					'payment_intent' => is_array( $object['payment_intent'] ?? null ) ? (string) $object['payment_intent']['id'] : (string) ( $object['payment_intent'] ?? '' ),
					'amount'         => (int) ( $object['amount_total'] ?? 0 ),
					'currency'       => (string) ( $object['currency'] ?? 'gbp' ),
					'provider'       => str_starts_with( (string) $object['id'], 'cs_sim_' ) ? 'simulated' : 'stripe',
					'livemode'       => ! empty( $event['livemode'] ),
				] );
				return is_wp_error( $booking ) ? $booking : 'confirmed booking ' . $booking;

			case 'checkout.session.expired':
			case 'checkout.session.async_payment_failed':
				$quote_id = (int) ( $object['metadata']['quote_id'] ?? 0 );
				if ( $quote_id ) {
					Checkout::expire_hold( $quote_id, (string) $object['id'], true );
				}
				return 'released hold';

			case 'charge.refunded':
				return Bookings::sync_refund( (string) ( $object['payment_intent'] ?? '' ), (int) ( $object['amount_refunded'] ?? 0 ) );
		}
		return 'ignored: ' . $event['type'];
	}
}

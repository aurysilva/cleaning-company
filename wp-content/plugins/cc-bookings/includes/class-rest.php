<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Public REST routes under /wp-json/cc/v1. Never cached (LiteSpeed nocache + no-store headers), noindex.
 */
final class Rest {
	const NS = 'cc/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
		add_filter( 'rest_pre_dispatch', [ self::class, 'no_cache_early' ], 10, 3 );
		add_filter( 'rest_post_dispatch', [ self::class, 'no_cache_headers' ], 10, 3 );
	}

	public static function routes(): void {
		$public = '__return_true';
		register_rest_route( self::NS, '/price', [ 'methods' => 'POST', 'callback' => [ self::class, 'price' ], 'permission_callback' => $public ] );
		register_rest_route( self::NS, '/slots', [ 'methods' => 'POST', 'callback' => [ self::class, 'slots' ], 'permission_callback' => $public ] );
		register_rest_route( self::NS, '/quotes', [ 'methods' => 'POST', 'callback' => [ self::class, 'submit' ], 'permission_callback' => $public ] );
		register_rest_route( self::NS, '/quotes/(?P<ref>Q-\d+)/prefill', [ 'methods' => 'GET', 'callback' => [ self::class, 'prefill' ], 'permission_callback' => $public ] );
		register_rest_route( self::NS, '/quotes/(?P<ref>Q-\d+)/accept', [ 'methods' => 'POST', 'callback' => [ self::class, 'accept' ], 'permission_callback' => $public ] );
		register_rest_route( self::NS, '/quotes/(?P<ref>Q-\d+)/question', [ 'methods' => 'POST', 'callback' => [ self::class, 'question' ], 'permission_callback' => $public ] );
		register_rest_route( self::NS, '/stripe', [ 'methods' => 'POST', 'callback' => [ Stripe::class, 'webhook' ], 'permission_callback' => $public ] );
	}

	private static function is_ours( \WP_REST_Request $request ): bool {
		return str_starts_with( $request->get_route(), '/' . self::NS . '/' );
	}

	public static function no_cache_early( $result, $server, $request ) {
		if ( $request instanceof \WP_REST_Request && self::is_ours( $request ) ) {
			do_action( 'litespeed_control_set_nocache', 'cc-bookings REST' );
		}
		return $result;
	}

	public static function no_cache_headers( $response, $server, $request ) {
		if ( $response instanceof \WP_REST_Response && $request instanceof \WP_REST_Request && self::is_ours( $request ) ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
			$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		}
		return $response;
	}

	private static function error( string $message, int $status, array $extra = [] ): \WP_REST_Response {
		return new \WP_REST_Response( [ 'ok' => false, 'message' => $message ] + $extra, $status );
	}

	private static function from_wp_error( \WP_Error $error ): \WP_REST_Response {
		$data = (array) $error->get_error_data();
		return self::error( $error->get_error_message(), (int) ( $data['status'] ?? 400 ), array_diff_key( $data, [ 'status' => 1 ] ) + [ 'code' => $error->get_error_code() ] );
	}

	/** Service + answers from a request, priced. */
	private static function job( \WP_REST_Request $request ): array {
		$service = Catalog::get( sanitize_key( (string) $request->get_param( 'service' ) ) );
		if ( ! $service ) {
			return [ null, [], null ];
		}
		$raw = $request->get_param( 'answers' );
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		[ $answers ] = Catalog::sanitize_answers( $service, is_array( $raw ) ? $raw : [] );
		$date        = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $request->get_param( 'date' ) ) ? (string) $request->get_param( 'date' ) : '';
		$slot        = isset( Slots::definitions()[ (string) $request->get_param( 'slot' ) ] ) ? (string) $request->get_param( 'slot' ) : '';
		return [ $service, $answers, Pricing::price( $service, $answers, $date, $slot ) ];
	}

	public static function price( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Security::rate_limit( 'price', 120, 10 * MINUTE_IN_SECONDS ) ) {
			return self::error( 'Too many requests. Please wait a few minutes.', 429 );
		}
		[ $service, , $price ] = self::job( $request );
		if ( ! $service ) {
			return self::error( 'Unknown service.', 400 );
		}
		$preview = Pricing::public_preview( $price );
		if ( $request->get_param( 'postcode' ) && ! Postcodes::is_covered( (string) $request->get_param( 'postcode' ) ) ) {
			$preview = Pricing::public_preview( [ 'bespoke' => true, 'reasons' => [ 'Outside our usual area' ], 'items' => [], 'total' => null ] + $price );
		}
		return new \WP_REST_Response( [ 'ok' => true ] + $preview );
	}

	public static function slots( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Security::rate_limit( 'slots', 60, 10 * MINUTE_IN_SECONDS ) ) {
			return self::error( 'Too many requests. Please wait a few minutes.', 429 );
		}
		[ $service, , $price ] = self::job( $request );
		if ( ! $service ) {
			return self::error( 'Unknown service.', 400 );
		}
		$preferred = $price['bespoke'];
		$grid      = $preferred ? [] : Slots::availability( $price['hours'], $price['cleaners'] );
		$dates     = [];
		foreach ( Slots::dates() as $date ) {
			$slots = [];
			foreach ( array_keys( Slots::definitions() ) as $slot ) {
				$slots[ $slot ] = $preferred ? Slots::start_timestamp( $date, $slot ) >= time() + (int) Settings::get( 'booking.min_notice_hours', 48 ) * HOUR_IN_SECONDS : ( $grid[ $date ][ $slot ] ?? false );
			}
			$dates[] = [ 'date' => $date, 'label' => Slots::date_label( $date, 'D j M' ), 'weekday' => (int) wp_date( 'N', Slots::start_timestamp( $date ) ), 'slots' => $slots ];
		}
		return new \WP_REST_Response( [
			'ok'        => true,
			'preferred' => $preferred,
			'slots'     => array_map( fn( $key ) => [ 'key' => $key, 'label' => Slots::label( $key ) ], array_keys( Slots::definitions() ) ),
			'dates'     => $dates,
		] );
	}

	public static function submit( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_params();
		if ( Security::is_bot( $params ) ) {
			// Look successful to bots; nothing is stored.
			return new \WP_REST_Response( [ 'ok' => true, 'status' => 'bespoke', 'message' => 'Thanks — we\'ll be in touch.' ] );
		}
		if ( ! Security::rate_limit( 'submit', 6, HOUR_IN_SECONDS ) ) {
			return self::error( 'You\'ve sent several requests already. Please call us instead.', 429 );
		}
		if ( ! Security::verify_turnstile( (string) ( $params['cf-turnstile-response'] ?? '' ) ) ) {
			return self::error( 'Please complete the security check and try again.', 400, [ 'errors' => [ 'turnstile' => 'Security check failed' ] ] );
		}
		if ( isset( $params['answers'] ) && is_string( $params['answers'] ) ) {
			$params['answers'] = json_decode( $params['answers'], true ) ?: [];
		}

		$result = Quotes::create( $params, Files::from_request( $request->get_file_params() ) );
		if ( is_wp_error( $result ) ) {
			return self::from_wp_error( $result );
		}
		if ( $result['bespoke'] ) {
			return new \WP_REST_Response( [
				'ok'      => true,
				'status'  => 'bespoke',
				'ref'     => $result['quote']['ref'],
				'message' => 'Thanks! This one needs a tailored price, so we\'ll look at the details and email your quote — usually within the hour during opening times.',
			] );
		}
		return new \WP_REST_Response( [ 'ok' => true, 'status' => 'quoted', 'ref' => $result['quote']['ref'], 'url' => $result['url'] ] );
	}

	public static function prefill( \WP_REST_Request $request ): \WP_REST_Response {
		$quote = Quotes::verify( (string) $request['ref'], (string) $request->get_param( 'token' ) );
		if ( ! $quote ) {
			return self::error( 'Quote not found.', 404 );
		}
		return new \WP_REST_Response( [ 'ok' => true, 'quote' => Quotes::prefill( $quote ) ] );
	}

	public static function accept( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Security::rate_limit( 'accept', 20, HOUR_IN_SECONDS ) ) {
			return self::error( 'Too many attempts. Please call us.', 429 );
		}
		$quote = Quotes::verify( (string) $request['ref'], (string) $request->get_param( 'token' ) );
		if ( ! $quote ) {
			return self::error( 'Quote not found.', 404 );
		}
		$result = Checkout::start( $quote, [
			'terms'       => rest_sanitize_boolean( $request->get_param( 'terms' ) ),
			'early_start' => rest_sanitize_boolean( $request->get_param( 'early_start' ) ),
			'token'       => (string) $request->get_param( 'token' ),
		] );
		return is_wp_error( $result ) ? self::from_wp_error( $result ) : new \WP_REST_Response( [ 'ok' => true ] + $result );
	}

	public static function question( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Security::rate_limit( 'question', 5, HOUR_IN_SECONDS ) ) {
			return self::error( 'You\'ve sent several messages already. Please call us.', 429 );
		}
		$quote = Quotes::verify( (string) $request['ref'], (string) $request->get_param( 'token' ) );
		if ( ! $quote ) {
			return self::error( 'Quote not found.', 404 );
		}
		$message = mb_substr( sanitize_textarea_field( (string) $request->get_param( 'message' ) ), 0, 2000 );
		if ( mb_strlen( $message ) < 3 ) {
			return self::error( 'Please type your question.', 400 );
		}
		Events::log( 'quote', $quote['id'], 'question', $message, [], 'customer' );
		do_action( 'cc_quote_question', $quote['id'], $message );
		return new \WP_REST_Response( [ 'ok' => true, 'message' => 'Thanks — we\'ve got your question and will reply by email or phone shortly.' ] );
	}
}

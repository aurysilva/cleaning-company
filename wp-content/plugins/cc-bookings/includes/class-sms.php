<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Text messages. Provider-agnostic: Sms::send() goes through Notify::sms(), whose `cc_bookings_send_sms`
 * filter this class answers with Twilio (REST via wp_remote_post) when these wp-config constants exist:
 *   CC_TWILIO_SID, CC_TWILIO_TOKEN, and CC_TWILIO_FROM (number or alphanumeric sender) or CC_TWILIO_MESSAGING_SID.
 * Another provider can hook the same filter at an earlier priority and return true.
 * With no provider (or dispatch "log texts only" on), the full message is written to the activity log
 * as `sms_logged` instead of being sent.
 */
final class Sms {
	public static function init(): void {
		add_filter( 'cc_bookings_send_sms', [ self::class, 'deliver' ], 20, 3 );
	}

	public static function twilio_ready(): bool {
		return defined( 'CC_TWILIO_SID' ) && defined( 'CC_TWILIO_TOKEN' ) && CC_TWILIO_SID && CC_TWILIO_TOKEN
			&& ( ( defined( 'CC_TWILIO_FROM' ) && CC_TWILIO_FROM ) || ( defined( 'CC_TWILIO_MESSAGING_SID' ) && CC_TWILIO_MESSAGING_SID ) );
	}

	/** Name of the active provider, or '' when texts are only logged. */
	public static function provider(): string {
		return (string) apply_filters( 'cc_bookings_sms_provider', self::twilio_ready() ? 'twilio' : '' );
	}

	public static function enabled(): bool {
		return '' !== self::provider() && ! Dispatch::opt( 'sms_log_only' );
	}

	/**
	 * Send a text, or log it when no provider is set up.
	 *
	 * @param array $context [ object_type, object_id ] for the activity log, e.g. [ 'booking', 12 ].
	 */
	public static function send( string $to, string $text, array $context = [ 'system', 0 ] ): bool {
		$context = [ (string) ( $context[0] ?? 'system' ), (int) ( $context[1] ?? 0 ) ];
		$number  = self::normalise( $to );
		$text    = trim( preg_replace( '/[ \t]+/', ' ', $text ) );
		if ( '' === $number || '' === $text ) {
			Events::log( $context[0], $context[1], 'sms_failed', 'No valid mobile number' . ( $to ? ' (' . $to . ')' : '' ), [ 'text' => $text ] );
			return false;
		}
		if ( ! self::enabled() ) {
			Events::log( $context[0], $context[1], 'sms_logged', $text, [ 'to' => $number, 'reason' => self::provider() ? 'log only' : 'no SMS provider configured' ] );
			return false;
		}
		return Notify::sms( $number, $text, $context );
	}

	/** `cc_bookings_send_sms` handler. */
	public static function deliver( $sent, $to, $text ) {
		if ( $sent || ! self::enabled() || 'twilio' !== self::provider() ) {
			return $sent;
		}
		$result = self::twilio( self::normalise( (string) $to ), (string) $text );
		if ( is_wp_error( $result ) ) {
			Events::log( 'system', 0, 'sms_failed', $result->get_error_message(), [ 'to' => $to ] );
			return false;
		}
		return true;
	}

	/** @return string|\WP_Error Twilio message SID. */
	private static function twilio( string $to, string $text ) {
		$body = [ 'To' => $to, 'Body' => $text ];
		if ( defined( 'CC_TWILIO_MESSAGING_SID' ) && CC_TWILIO_MESSAGING_SID ) {
			$body['MessagingServiceSid'] = CC_TWILIO_MESSAGING_SID;
		} else {
			$body['From'] = CC_TWILIO_FROM;
		}
		$response = wp_remote_post( 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( CC_TWILIO_SID ) . '/Messages.json', [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'Basic ' . base64_encode( CC_TWILIO_SID . ':' . CC_TWILIO_TOKEN ) ],
			'body'    => $body,
		] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $data['sid'] ) ) {
			return new \WP_Error( 'twilio', sprintf( 'Twilio error %d: %s', $code, (string) ( $data['message'] ?? 'unknown' ) ) );
		}
		return (string) $data['sid'];
	}

	/** UK-friendly E.164: "07700 900123" => "+447700900123". Returns '' if it doesn't look like a phone number. */
	public static function normalise( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		$plus   = str_starts_with( $raw, '+' );
		$digits = preg_replace( '/\D/', '', $raw );
		if ( ! $plus ) {
			if ( str_starts_with( $digits, '00' ) ) {
				$digits = substr( $digits, 2 );
			} elseif ( str_starts_with( $digits, '0' ) ) {
				$digits = '44' . substr( $digits, 1 );
			}
		}
		return strlen( $digits ) >= 10 && strlen( $digits ) <= 15 ? '+' . $digits : '';
	}

	/** Fill {placeholders} in a text template. */
	public static function fill( string $template, array $vars ): string {
		return preg_replace_callback( '/\{([a-z_]+)\}/', fn( $m ) => array_key_exists( $m[1], $vars ) && is_scalar( $vars[ $m[1] ] ) ? (string) $vars[ $m[1] ] : $m[0], $template );
	}
}

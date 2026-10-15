<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Spam and abuse protection for the public endpoints: honeypot, minimum fill time,
 * Cloudflare Turnstile (when CC_TURNSTILE_SITE / CC_TURNSTILE_SECRET are defined) and per-IP rate limits.
 */
final class Security {
	const HONEYPOT     = 'website';
	const MIN_FILL_MS  = 3000;

	public static function ip(): string {
		return (string) filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP );
	}

	public static function ip_hash(): string {
		return hash_hmac( 'sha256', self::ip(), wp_salt( 'nonce' ) );
	}

	/** True while under the limit; counts this request. */
	public static function rate_limit( string $bucket, int $max, int $window ): bool {
		$key   = 'cc_rl_' . md5( $bucket . '|' . self::ip_hash() );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	public static function is_bot( array $params ): bool {
		return '' !== trim( (string) ( $params[ self::HONEYPOT ] ?? '' ) ) || (int) ( $params['elapsed'] ?? 0 ) < self::MIN_FILL_MS;
	}

	public static function turnstile_site_key(): string {
		return defined( 'CC_TURNSTILE_SITE' ) && defined( 'CC_TURNSTILE_SECRET' ) ? (string) CC_TURNSTILE_SITE : '';
	}

	public static function verify_turnstile( string $token ): bool {
		if ( ! self::turnstile_site_key() ) {
			return true;
		}
		if ( '' === $token ) {
			return false;
		}
		$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
			'timeout' => 10,
			'body'    => [ 'secret' => CC_TURNSTILE_SECRET, 'response' => $token, 'remoteip' => self::ip() ],
		] );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $body['success'] );
	}
}

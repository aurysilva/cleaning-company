<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Cleaners' bank details, encrypted at rest with libsodium secretbox (XSalsa20-Poly1305; WordPress ships
 * sodium_compat when the PHP extension is missing). The 32-byte key is the wp-config constant CC_BOOKINGS_ENC_KEY
 * (base64 or hex) and is never stored in the database. Stored as "v1:" . base64( nonce . ciphertext ) in
 * cleaner_accounts.bank, plus the last 4 digits for the masked "•••• 1234" shown everywhere. Only administrators
 * can see the full details, through an explicit action that is written to the cleaner's audit log.
 * If the key is lost, saved bank details can't be read; cleaners would need to enter them again.
 */
final class Cleaner_Bank {
	public static function key(): string {
		if ( ! defined( 'CC_BOOKINGS_ENC_KEY' ) || ! defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' ) ) {
			return '';
		}
		$raw = trim( (string) CC_BOOKINGS_ENC_KEY );
		$bin = preg_match( '/^[a-f0-9]{64}$/i', $raw ) ? hex2bin( $raw ) : base64_decode( $raw, true );
		return is_string( $bin ) && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $bin ) ? $bin : '';
	}

	public static function ready(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && '' !== self::key();
	}

	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
	}

	public static function decrypt( string $box ): ?string {
		if ( ! str_starts_with( $box, 'v1:' ) || ! self::ready() ) {
			return null;
		}
		$bin = base64_decode( substr( $box, 3 ), true );
		if ( false === $bin || strlen( $bin ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		} catch ( \Throwable $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}

	/**
	 * UK account: holder name, 6-digit sort code, 8-digit account number (spaces and dashes ignored).
	 *
	 * @return array{holder: string, sort_code: string, account_number: string}|\WP_Error
	 */
	public static function validate( array $input ) {
		$holder = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) ( $input['bank_holder'] ?? '' ) ) ) );
		$sort   = preg_replace( '/[\s-]/', '', (string) ( $input['bank_sort'] ?? '' ) );
		$number = preg_replace( '/[\s-]/', '', (string) ( $input['bank_account'] ?? '' ) );
		$errors = [];
		if ( mb_strlen( $holder ) < 2 || mb_strlen( $holder ) > 70 || ! preg_match( "/^[\\p{L} .'&-]+$/u", $holder ) ) {
			$errors[] = 'Enter the name on the account, as the bank has it.';
		}
		if ( ! preg_match( '/^\d{6}$/', $sort ) ) {
			$errors[] = 'The sort code should be 6 digits, e.g. 12-34-56.';
		}
		if ( ! preg_match( '/^\d{8}$/', $number ) ) {
			$errors[] = 'The account number should be 8 digits.';
		} elseif ( '00000000' === $number ) {
			$errors[] = 'That account number doesn\'t look right.';
		}
		if ( $errors ) {
			return new \WP_Error( 'bank', implode( ' ', $errors ) );
		}
		return [ 'holder' => $holder, 'sort_code' => $sort, 'account_number' => $number ];
	}

	/** @return true|\WP_Error */
	public static function save( array $cleaner, array $input ) {
		$details = self::validate( $input );
		if ( is_wp_error( $details ) ) {
			return $details;
		}
		if ( ! self::ready() ) {
			Bookings::alert( 'Bank details can\'t be saved', 'The encryption key CC_BOOKINGS_ENC_KEY is missing or invalid in wp-config.php, so ' . $cleaner['name'] . ' couldn\'t save their bank details.', [] );
			return new \WP_Error( 'key', 'Sorry, we can\'t save bank details right now. Please try again later or call the office.' );
		}
		$id      = (int) $cleaner['id'];
		$account = Cleaner_Accounts::account( $id );
		$changed = '' !== (string) $account['bank_last4'];
		Cleaner_Accounts::update_account( $id, [
			'bank'            => self::encrypt( (string) wp_json_encode( $details ) ),
			'bank_last4'      => substr( $details['account_number'], -4 ),
			'bank_updated_at' => Db::now(),
		] );
		Events::log( 'cleaner', $id, 'bank_updated', sprintf( 'Bank details %s (account ending %s)', $changed ? 'changed' : 'added', substr( $details['account_number'], -4 ) ), [], Cleaner_Accounts::current() ? 'cleaner:' . $id : '' );
		if ( $changed && 'active' === Cleaner_Accounts::status( $cleaner ) ) {
			Bookings::alert( $cleaner['name'] . ' changed their bank details', sprintf( 'New account ending %s. If they didn\'t expect this, check with them before the next pay run.', substr( $details['account_number'], -4 ) ), [] );
		}
		return true;
	}

	public static function masked( array $account ): string {
		return '' !== (string) ( $account['bank_last4'] ?? '' ) ? '•••• ' . $account['bank_last4'] : '';
	}

	/** Full details for an administrator; every view is audit-logged. */
	public static function reveal( int $cleaner_id ): ?array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		$account = Cleaner_Accounts::account( $cleaner_id );
		$plain   = $account['bank'] ? self::decrypt( (string) $account['bank'] ) : null;
		$details = $plain ? json_decode( $plain, true ) : null;
		Events::log( 'cleaner', $cleaner_id, 'bank_revealed', $details ? 'Full bank details viewed' : 'Tried to view bank details (none, or could not decrypt)' );
		return is_array( $details ) ? $details : null;
	}

	public static function format_sort( string $sort ): string {
		return implode( '-', str_split( $sort, 2 ) );
	}
}

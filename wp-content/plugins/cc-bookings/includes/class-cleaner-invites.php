<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Invite links: /cleaners/setup/{token}/. 32 random bytes; only the SHA-256 hash is stored (cleaner_accounts.invite_hash)
 * with an expiry (accounts.invite_days, default 7). Opening the link changes nothing (email scanners are harmless);
 * the token is used up when the cleaner sets their password on the first step, after which setup continues on their
 * login. Sending again issues a new token, so older links stop working.
 */
final class Cleaner_Invites {
	/**
	 * Create the login if needed, issue a fresh token and (optionally) email it.
	 *
	 * @return string|\WP_Error The setup URL.
	 */
	public static function send( int $cleaner_id, bool $email = true ) {
		$cleaner = Cleaners::get( $cleaner_id );
		if ( ! $cleaner ) {
			return new \WP_Error( 'missing', 'Cleaner not found.' );
		}
		$status = Cleaner_Accounts::status( $cleaner );
		if ( ! in_array( $status, [ 'none', 'invited', 'legacy' ], true ) ) {
			return new \WP_Error( 'state', 'This cleaner has already set their password. They can log in, or use "Forgotten password" on the login page.' );
		}
		if ( 'active' !== $cleaner['status'] ) {
			return new \WP_Error( 'inactive', 'Set the cleaner to Active before inviting them.' );
		}
		$user = Cleaner_Accounts::ensure_user( $cleaner );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		[ $raw, $hash ] = Tokens::generate();
		$expires        = time() + max( 1, (int) Cleaner_Accounts::opt( 'invite_days' ) ) * DAY_IN_SECONDS;
		$data           = [ 'invite_hash' => $hash, 'invite_expires' => Db::datetime( $expires ), 'invited_at' => Db::now() ];
		if ( 'legacy' === $status ) {
			$data['was_legacy'] = 1;
		}
		Cleaner_Accounts::update_account( $cleaner_id, $data );
		Cleaner_Accounts::set_status( $cleaner_id, 'invited', 'invited' === $status ? 'Invite re-sent (earlier links no longer work)' : 'Invited to set up an account' );

		$url = self::url( $raw );
		if ( $email ) {
			Notify::send( 'cleaner_invite', (string) $cleaner['email'], Cleaner_Accounts::vars( $cleaner ) + [
				'setup_url' => $url,
				'expires'   => wp_date( 'l j F', $expires ),
			], [ 'cleaner', $cleaner_id ] );
		} else {
			Events::log( 'cleaner', $cleaner_id, 'setup_link', 'New setup link issued without an email (earlier links no longer work)' );
		}
		do_action( 'cc_cleaner_invited', $cleaner_id );
		return $url;
	}

	public static function url( string $raw ): string {
		return home_url( '/cleaners/setup/' . $raw . '/' );
	}

	/** The invited cleaner for a live, unused token, or null. */
	public static function verify( string $raw ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,}$/', $raw ) ) {
			return null;
		}
		$account = Db::find( Cleaner_Accounts::TABLE, [ 'invite_hash' => Tokens::hash( $raw ) ] );
		if ( ! $account || Db::timestamp( $account['invite_expires'] ) < time() ) {
			return null;
		}
		$cleaner = Cleaners::get( (int) $account['cleaner_id'] );
		return $cleaner && 'invited' === Cleaner_Accounts::status( $cleaner ) && 'active' === $cleaner['status'] ? $cleaner : null;
	}

	public static function consume( int $cleaner_id ): void {
		Cleaner_Accounts::update_account( $cleaner_id, [ 'invite_hash' => '', 'invite_expires' => null ] );
	}

	public static function expires( array $account ): int {
		return '' !== (string) $account['invite_hash'] ? Db::timestamp( $account['invite_expires'] ) : 0;
	}
}

<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * The setup wizard. The invite link (/cleaners/setup/{token}/) opens step 1, where the cleaner chooses a password
 * and confirms their details; saving it uses up the token and signs them in. The remaining steps run on their
 * session at /cleaners/setup/?step=…, saving progress per step (cleaner_accounts.setup lists the finished steps),
 * so they can stop and come back later by logging in. Agreeing to the terms submits the account for review.
 */
final class Cleaner_Setup {
	const STEPS = [
		'account'      => 'Your details',
		'availability' => 'Availability & areas',
		'services'     => 'Services',
		'photo'        => 'Profile photo',
		'emergency'    => 'Emergency contact',
		'documents'    => 'Documents',
		'bank'         => 'Bank details',
		'terms'        => 'Agreement',
	];

	const INTRO = [
		'account'      => 'Choose a password and check your contact details and home address.',
		'availability' => 'Tell us where you can travel to and when you can work. You can change this any time.',
		'services'     => 'Which kinds of cleaning can you do?',
		'photo'        => 'Customers see your first name and this photo when you\'re booked for their clean.',
		'emergency'    => 'Who should we contact if something happens to you while you\'re at work?',
		'documents'    => 'We need to check these before you can start. You can upload photos taken on your phone.',
		'bank'         => 'Where should we pay you?',
		'terms'        => 'Please read and agree to the terms. This sends your account to us for checking.',
	];

	public static function url( string $step = '' ): string {
		$url = home_url( '/cleaners/setup/' );
		return $step ? add_query_arg( 'step', $step, $url ) : $url;
	}

	public static function done( array $account ): array {
		return array_values( array_intersect( array_keys( self::STEPS ), (array) $account['setup'] ) );
	}

	public static function next_step( array $account ): string {
		foreach ( array_keys( self::STEPS ) as $step ) {
			if ( ! in_array( $step, (array) $account['setup'], true ) ) {
				return $step;
			}
		}
		return 'terms';
	}

	/** A step can be opened once every step before it is finished. */
	public static function allowed( array $account, string $step ): bool {
		foreach ( array_keys( self::STEPS ) as $key ) {
			if ( $key === $step ) {
				return true;
			}
			if ( ! in_array( $key, (array) $account['setup'], true ) ) {
				return false;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Step 1 from the invite link
	 * ------------------------------------------------------------------ */

	public static function route_token( string $raw ): void {
		$cleaner = Cleaner_Invites::verify( $raw );
		$me      = Cleaner_Accounts::current();
		if ( ! $cleaner ) {
			if ( $me && in_array( Cleaner_Accounts::status( $me ), [ 'setup', 'review', 'active' ], true ) ) {
				Cleaner_Portal::redirect( 'setup' === Cleaner_Accounts::status( $me ) ? self::url() : Cleaner_Portal::url() );
			}
			Cleaner_Portal::message(
				'This setup link isn\'t valid any more',
				'It may have expired, been replaced by a newer invite, or already been used to set your password. If you\'ve already set a password, log in to carry on. Otherwise, ask the office to send you a new invite.',
				410
			);
		}
		if ( is_user_logged_in() && get_current_user_id() !== (int) $cleaner['user_id'] ) {
			Cleaner_Portal::message( 'You\'re logged in as someone else', 'Log out first, then open the setup link from your email again.', 409, true );
		}

		$error = '';
		$input = [];
		if ( Cleaner_Portal::is_post() ) {
			$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! wp_verify_nonce( (string) ( $post['_ccnonce'] ?? '' ), 'cc_setup_invite_' . $cleaner['id'] ) ) {
				$error = 'This page timed out. Please try again.';
			} elseif ( ! Security::rate_limit( 'cleaner_setup_token', 30, HOUR_IN_SECONDS ) ) {
				$error = 'Too many attempts. Please wait a while and try again.';
			} else {
				$user    = get_userdata( (int) $cleaner['user_id'] );
				$problem = $user ? Cleaner_Auth::password_problem( (string) ( $post['password'] ?? '' ), (string) ( $post['password_confirm'] ?? '' ), Cleaner_Auth::avoid_words( $user, [ 'name' => (string) ( $post['name'] ?? $cleaner['name'] ) ] ) ) : 'Your login is missing. Please ask the office to send a new invite.';
				$saved   = $problem ? new \WP_Error( 'password', $problem ) : Cleaner_Profile::save_details( $cleaner, $post );
				if ( is_wp_error( $saved ) ) {
					$error = $saved->get_error_message();
				} else {
					Cleaner_Invites::consume( (int) $cleaner['id'] );
					Cleaner_Accounts::set_status( (int) $cleaner['id'], 'setup', 'Password set from the invite link; setup started' );
					Cleaner_Profile::mark_done( (int) $cleaner['id'], 'account' );
					Cleaner_Auth::set_password( $user, (string) $post['password'], 'password set from the invite link' );
					Cleaner_Portal::redirect( self::url( 'availability' ) );
				}
			}
			$input = array_diff_key( $post, array_flip( [ 'password', 'password_confirm', '_ccnonce' ] ) );
		}
		self::render( $cleaner, 'account', $error, $input, Cleaner_Invites::url( $raw ), 'cc_setup_invite_' . $cleaner['id'], true );
	}

	/* ---------------------------------------------------------------------
	 * Steps 2+ on the cleaner's session
	 * ------------------------------------------------------------------ */

	public static function route( array $cleaner ): void {
		$status = Cleaner_Accounts::status( $cleaner );
		if ( 'review' === $status ) {
			self::render( $cleaner, 'done' );
		}
		if ( 'setup' !== $status ) {
			Cleaner_Portal::redirect( Cleaner_Portal::url() );
		}
		$account = Cleaner_Accounts::account( (int) $cleaner['id'] );
		$step    = sanitize_key( (string) ( $_GET['step'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( self::STEPS[ $step ] ) || ! self::allowed( $account, $step ) ) {
			Cleaner_Portal::redirect( self::url( self::next_step( $account ) ) );
		}

		$error = '';
		$input = [];
		if ( Cleaner_Portal::is_post() ) {
			$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! Cleaner_Portal::check_nonce( $cleaner ) ) {
				$error = 'This page timed out. Please try again.';
			} else {
				$saved = self::save( $cleaner, $account, $step, $post );
				if ( is_wp_error( $saved ) ) {
					$error = $saved->get_error_message();
				} else {
					Cleaner_Profile::mark_done( (int) $cleaner['id'], $step );
					if ( 'terms' === $step ) {
						self::submit( (int) $cleaner['id'] );
						Cleaner_Portal::redirect( self::url() );
					}
					$keys = array_keys( self::STEPS );
					Cleaner_Portal::redirect( self::url( $keys[ array_search( $step, $keys, true ) + 1 ] ) );
				}
			}
			$input = array_diff_key( $post, array_flip( [ 'bank_holder', 'bank_sort', 'bank_account', '_ccnonce' ] ) );
		}
		self::render( Cleaners::get( (int) $cleaner['id'] ), $step, $error, $input, self::url( $step ), 'cc_portal_' . $cleaner['id'] );
	}

	/** @return true|\WP_Error */
	private static function save( array $cleaner, array $account, string $step, array $post ) {
		switch ( $step ) {
			case 'account':
				return Cleaner_Profile::save_details( $cleaner, $post );
			case 'availability':
				return Cleaner_Profile::save_availability( $cleaner, $post );
			case 'services':
				return Cleaner_Profile::save_services( $cleaner, $post );
			case 'photo':
				return Cleaner_Profile::save_photo( $cleaner, true );
			case 'emergency':
				return Cleaner_Profile::save_emergency( $cleaner, $post );
			case 'documents':
				return Cleaner_Profile::save_documents( $cleaner, true );
			case 'bank':
				$empty = '' === trim( (string) ( $post['bank_holder'] ?? '' ) . (string) ( $post['bank_sort'] ?? '' ) . (string) ( $post['bank_account'] ?? '' ) );
				return $empty && '' !== (string) $account['bank_last4'] ? true : Cleaner_Bank::save( $cleaner, $post );
			case 'terms':
				$missing = array_diff( array_slice( array_keys( self::STEPS ), 0, -1 ), (array) $account['setup'] );
				if ( $missing ) {
					return new \WP_Error( 'steps', 'Please finish these steps first: ' . implode( ', ', array_map( fn( $s ) => self::STEPS[ $s ], $missing ) ) . '.' );
				}
				return Cleaner_Profile::accept_terms( $cleaner, $post );
		}
		return new \WP_Error( 'step', 'Unknown step.' );
	}

	public static function submit( int $cleaner_id ): void {
		$cleaner = Cleaners::get( $cleaner_id );
		$account = Cleaner_Accounts::account( $cleaner_id );
		Cleaner_Accounts::update_account( $cleaner_id, [ 'submitted_at' => Db::now() ] );
		Cleaner_Accounts::set_status( $cleaner_id, 'review', 'Setup finished and submitted for review' );
		Notify::send( 'cleaner_setup_submitted', Settings::admin_email(), Cleaner_Accounts::vars( $cleaner ) + [
			'terms_label'   => (string) ( $account['terms']['label'] ?? 'terms' ),
			'terms_version' => (string) ( $account['terms']['version'] ?? '' ),
		], [ 'cleaner', $cleaner_id ] );
		do_action( 'cc_cleaner_setup_submitted', $cleaner_id );
	}

	private static function render( array $cleaner, string $step, string $error = '', array $input = [], string $action = '', string $nonce = '', bool $invite = false ): void {
		Cleaner_Portal::assets();
		Frontend::render( 'cleaner-setup', [
			'cleaner' => $cleaner,
			'account' => Cleaner_Accounts::account( (int) $cleaner['id'] ),
			'step'    => $step,
			'steps'   => self::STEPS,
			'intro'   => self::INTRO[ $step ] ?? '',
			'error'   => $error,
			'input'   => $input,
			'action'  => $action,
			'nonce'   => $nonce,
			'invite'  => $invite,
		], 'done' === $step ? 'Account submitted' : 'Set up your account' );
	}
}

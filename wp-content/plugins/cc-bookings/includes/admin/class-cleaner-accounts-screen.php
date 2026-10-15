<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Cleaner_Accounts;
use CC\Bookings\Cleaner_Bank;
use CC\Bookings\Cleaner_Docs;
use CC\Bookings\Cleaner_Invites;
use CC\Bookings\Cleaners;
use CC\Bookings\Db;
use CC\Bookings\Events;
use CC\Bookings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cleaner accounts in wp-admin: the account panel on each cleaner (invite, activate, deactivate, documents,
 * bank details, agreement), the "Email an invite" option on the cleaner form, and Bookings → Cleaner accounts
 * (overview of accounts waiting for action, document expiry, and the accounts.* settings including the terms).
 */
final class Cleaner_Accounts_Screen {
	const PAGE = 'cc-bookings-cleaner-accounts';

	const ACTIONS = [ 'cacct_invite', 'cacct_activate', 'cacct_deactivate', 'cacct_reactivate', 'cacct_doc_approve', 'cacct_doc_reject', 'cacct_doc_expiry', 'cacct_settings' ];

	public static function init(): void {
		add_filter( 'cc_bookings_admin_pages', [ self::class, 'pages' ], 30 );
		foreach ( self::ACTIONS as $do ) {
			add_action( 'cc_bookings_admin_action_' . $do, fn() => self::handle( $do ) );
		}
		add_action( 'cc_bookings_admin_cleaner_sidebar', [ self::class, 'sidebar' ] );
		add_action( 'cc_bookings_admin_cleaner_form', [ self::class, 'form_fields' ] );
		add_filter( 'cc_bookings_admin_cleaner_status', [ self::class, 'status_cell' ], 10, 2 );
	}

	public static function pages( array $pages ): array {
		$out = [];
		foreach ( $pages as $slug => $page ) {
			$out[ $slug ] = $page;
			if ( 'cc-bookings-cleaners' === $slug ) {
				$out[ self::PAGE ] = [ 'Cleaner accounts', [ self::class, 'render' ] ];
			}
		}
		return isset( $out[ self::PAGE ] ) ? $out : $out + [ self::PAGE => [ 'Cleaner accounts', [ self::class, 'render' ] ] ];
	}

	private static function cleaner_url( int $id ): string {
		return Admin::url( 'cc-bookings-cleaners', [ 'id' => $id ] );
	}

	/* ---------------------------------------------------------------------
	 * On the cleaner edit screen
	 * ------------------------------------------------------------------ */

	public static function status_cell( $html, $cleaner ) {
		$status = Cleaner_Accounts::status( (array) $cleaner );
		return $html . '<br>' . Cleaner_Accounts::badge( $status );
	}

	public static function form_fields( $cleaner ): void {
		$cleaner = (array) $cleaner;
		$status  = (int) ( $cleaner['id'] ?? 0 ) ? Cleaner_Accounts::status( $cleaner ) : 'none';
		if ( ! in_array( $status, [ 'none', 'legacy' ], true ) ) {
			return;
		}
		printf(
			'<tr><th>Account</th><td><label class="cc-admin__check"><input type="checkbox" name="cc_send_invite" value="1"%s> Email an invite to set up their cleaner account</label><p class="description">They choose a password, add their availability, documents and bank details, and agree to the %s. The link works once and expires after %d days.%s</p></td></tr>',
			checked( empty( $cleaner['id'] ), true, false ),
			'cleaner terms or contractor agreement',
			(int) Cleaner_Accounts::opt( 'invite_days' ),
			'legacy' === $status ? ' This cleaner keeps getting jobs as now while they set up.' : ' Until their account is approved they won\'t be offered jobs.'
		);
	}

	private static function progress( array $done ): string {
		$steps = \CC\Bookings\Cleaner_Setup::STEPS;
		$done  = array_intersect_key( $steps, array_flip( $done ) );
		if ( count( $done ) === count( $steps ) ) {
			return 'All steps done';
		}
		return sprintf( '%d of %d done; still to do: %s', count( $done ), count( $steps ), implode( ', ', array_diff_key( $steps, $done ) ) );
	}

	public static function sidebar( $cleaner ): void {
		$c       = Cleaners::get( (int) ( (array) $cleaner )['id'] );
		$id      = (int) $c['id'];
		$status  = Cleaner_Accounts::status( $c );
		$account = Cleaner_Accounts::account( $id );
		echo '<h2>Account ' . Cleaner_Accounts::badge( $status ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput

		$lines = [];
		if ( 'invited' === $status && $account['invited_at'] ) {
			$expires = Cleaner_Invites::expires( $account );
			$lines[] = sprintf( 'Invited %s; the link %s %s.', Admin::when( $account['invited_at'] ), $expires > time() ? 'expires' : 'expired', $expires ? wp_date( 'j M Y, H:i', $expires ) : '' );
		}
		if ( $account['submitted_at'] ) {
			$lines[] = 'Setup submitted ' . Admin::when( $account['submitted_at'] ) . '.';
		}
		if ( $account['approved_at'] && 'active' === $status ) {
			$by      = get_userdata( (int) $account['approved_by'] );
			$lines[] = 'Approved ' . Admin::when( $account['approved_at'] ) . ( $by ? ' by ' . $by->display_name : '' ) . '.';
		}
		if ( 'deactivated' === $status ) {
			$lines[] = 'Deactivated ' . Admin::when( $account['deactivated_at'] ) . '. They can\'t log in and their job links don\'t work.';
		}
		if ( 'legacy' === $status ) {
			$lines[] = 'Added before cleaner accounts: no login, and dispatched as before. Send an invite to give them a portal login.';
		}
		if ( 'none' === $status ) {
			$lines[] = 'Not invited yet. Cleaners need an approved account before they are offered jobs.';
		}
		foreach ( $lines as $line ) {
			echo '<p class="description">' . esc_html( $line ) . '</p>';
		}

		$missing = in_array( $status, [ 'setup', 'review' ], true ) ? Cleaner_Accounts::missing( $id ) : [];
		if ( $missing ) {
			echo '<p><strong>Still needed before activation:</strong></p><ul class="cc-acct__missing">';
			foreach ( $missing as $item ) {
				echo '<li>' . esc_html( ucfirst( $item ) ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<div class="cc-admin__actions">';
		if ( in_array( $status, [ 'none', 'legacy' ], true ) ) {
			echo Admin::button( 'cacct_invite', [ 'id' => $id ], 'Send invite', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( 'invited' === $status ) {
			echo Admin::button( 'cacct_invite', [ 'id' => $id ], 'Resend invite', 'button', 'Send a new invite? The earlier link will stop working.' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( 'review' === $status || 'setup' === $status ) {
			echo $missing // phpcs:ignore WordPress.Security.EscapeOutput
				? Admin::button( 'cacct_activate', [ 'id' => $id, 'force' => 1 ], 'Activate anyway', 'button', 'Activate even though some items are missing or not approved?' )
				: Admin::button( 'cacct_activate', [ 'id' => $id ], 'Approve and activate', 'button button-primary' );
		}
		if ( 'deactivated' === $status ) {
			echo Admin::button( 'cacct_reactivate', [ 'id' => $id ], 'Reactivate', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo Admin::form( 'cacct_deactivate', [ 'id' => $id ], 'class="cc-admin__inline" onsubmit="return confirm(' . esc_attr( wp_json_encode( 'Deactivate this cleaner? They are logged out everywhere, their job links stop working and open offers are withdrawn.' ) ) . ')"' ) // phpcs:ignore WordPress.Security.EscapeOutput
				. '<input type="text" name="reason" placeholder="Reason (optional)" class="regular-text" style="width:12em"> <button type="submit" class="button button-link-delete">Deactivate</button></form>';
		}
		echo '</div>';

		if ( in_array( $status, [ 'none', 'legacy', 'invited' ], true ) && ! $account['submitted_at'] && ! $account['setup'] ) {
			return;
		}

		// Personal details.
		$em    = (array) $account['emergency'];
		$terms = (array) $account['terms'];
		echo '<h3>Details</h3><table class="widefat striped"><tbody>';
		$rows = [
			'Address'           => implode( ', ', array_filter( [ $account['address_1'], $account['address_2'], $account['town'], $account['postcode'] ] ) ),
			'Emergency contact' => $em ? sprintf( '%s (%s), %s', $em['name'] ?? '', $em['relationship'] ?? '', $em['phone'] ?? '' ) : '',
			'Agreement'         => $terms ? sprintf( '%s version %s, agreed %s from IP %s', ucfirst( (string) ( $terms['label'] ?? '' ) ), $terms['version'] ?? '', Admin::when( $terms['accepted_at'] ?? null ), $terms['ip'] ?? '' ) : '',
			'Setup progress'    => self::progress( (array) $account['setup'] ),
		];
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th style="width:9em">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ?: '—' ) );
		}
		echo '</tbody></table>';

		// Bank details: masked; full details only for administrators, on an explicit POST, audit-logged.
		echo '<h3>Bank details</h3>';
		$masked = Cleaner_Bank::masked( $account );
		if ( ! $masked ) {
			echo '<p class="description">None yet.</p>';
		} else {
			printf( '<p>Account <strong>%s</strong>, updated %s.</p>', esc_html( $masked ), esc_html( Admin::when( $account['bank_updated_at'] ) ) );
			// phpcs:ignore WordPress.Security.NonceVerification
			$reveal = isset( $_POST['cc_reveal_bank'] ) && current_user_can( 'manage_options' ) && wp_verify_nonce( (string) wp_unslash( $_POST['cc_reveal_nonce'] ?? '' ), 'cc_reveal_bank_' . $id ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( $reveal ) {
				$details = Cleaner_Bank::reveal( $id );
				if ( $details ) {
					printf(
						'<div class="cc-acct__bank"><p><strong>%s</strong><br>Sort code %s · Account %s</p><p class="description">This view has been recorded in the cleaner\'s history. Reload the page to hide it.</p></div>',
						esc_html( $details['holder'] ),
						esc_html( Cleaner_Bank::format_sort( $details['sort_code'] ) ),
						esc_html( $details['account_number'] )
					);
				} else {
					echo '<p class="description">The details couldn\'t be decrypted (check CC_BOOKINGS_ENC_KEY in wp-config.php).</p>';
				}
			} elseif ( current_user_can( 'manage_options' ) ) {
				printf(
					'<form method="post" action="%s" onsubmit="return confirm(%s)"><input type="hidden" name="cc_reveal_bank" value="1">%s<button type="submit" class="button">Show full details</button></form>',
					esc_url( self::cleaner_url( $id ) ),
					esc_attr( wp_json_encode( 'Show the full bank details? This is recorded in the cleaner\'s history.' ) ),
					wp_nonce_field( 'cc_reveal_bank_' . $id, 'cc_reveal_nonce', false, false ) // phpcs:ignore WordPress.Security.EscapeOutput
				);
			} else {
				echo '<p class="description">Only administrators can see the full details.</p>';
			}
		}

		self::documents( $c );
	}

	private static function documents( array $c ): void {
		$id   = (int) $c['id'];
		$docs = Db::all( Cleaner_Docs::TABLE, [ 'cleaner_id' => $id, 'status' => [ 'pending', 'approved', 'rejected', 'expired' ] ], 'type ASC, id DESC' );
		echo '<h3>Photo and documents</h3>';
		if ( ! $docs ) {
			echo '<p class="description">Nothing uploaded yet.</p>';
			return;
		}
		echo '<table class="widefat striped cc-acct__docs"><tbody>';
		foreach ( $docs as $doc ) {
			$did = (int) $doc['id'];
			echo '<tr><td>';
			printf(
				'<strong>%s</strong> %s<br><a href="%s" target="_blank" rel="noopener">View</a> · <span class="description">%s, uploaded %s%s</span>',
				esc_html( Cleaner_Docs::label( $doc['type'] ) ),
				Cleaner_Docs::badge( $doc['status'] ), // phpcs:ignore WordPress.Security.EscapeOutput
				esc_url( Cleaner_Docs::url( $doc ) ),
				esc_html( strtoupper( (string) pathinfo( (string) $doc['file'], PATHINFO_EXTENSION ) ) . ' ' . size_format( (int) $doc['size'] ) ),
				esc_html( Admin::when( $doc['created_at'], 'j M Y' ) ),
				$doc['reason'] ? '<br>Reason: ' . esc_html( $doc['reason'] ) : ''
			);
			echo '<br>';
			if ( in_array( $doc['status'], [ 'pending', 'rejected' ], true ) ) {
				echo Admin::button( 'cacct_doc_approve', [ 'id' => $id, 'doc' => $did ], 'Approve', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			if ( in_array( $doc['status'], [ 'pending', 'approved' ], true ) ) {
				echo Admin::form( 'cacct_doc_reject', [ 'id' => $id, 'doc' => $did ] ) // phpcs:ignore WordPress.Security.EscapeOutput
					. '<input type="text" name="reason" placeholder="Reason, e.g. blurry photo" required> <button type="submit" class="button">Reject</button></form>';
			}
			if ( 'photo' !== $doc['type'] ) {
				echo Admin::form( 'cacct_doc_expiry', [ 'id' => $id, 'doc' => $did ] ) // phpcs:ignore WordPress.Security.EscapeOutput
					. sprintf( '<input type="date" name="expires_on" value="%s" aria-label="Expiry date"> <button type="submit" class="button">Set expiry</button></form>', esc_attr( (string) $doc['expires_on'] ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * Bookings → Cleaner accounts
	 * ------------------------------------------------------------------ */

	public static function render(): void {
		Admin::header( 'Cleaner accounts' );
		echo '<p class="description">Cleaners get an invite email, set up their login, availability, documents and bank details, and agree to the terms. Approve their documents and activate them on the cleaner\'s page. Only active cleaners (and cleaners added before accounts existed) are offered jobs.</p>';

		if ( ! Cleaner_Bank::ready() ) {
			echo '<div class="notice notice-error inline"><p><strong>Bank details can\'t be saved:</strong> the encryption key CC_BOOKINGS_ENC_KEY is missing or invalid in wp-config.php.</p></div>';
		}

		$cleaners = Db::all( 'cleaners', [], 'name ASC' );
		$by       = [];
		foreach ( $cleaners as $c ) {
			$by[ Cleaner_Accounts::status( $c ) ][] = $c;
		}
		echo '<ul class="subsubsub">';
		$parts = [];
		foreach ( Cleaner_Accounts::STATUSES as $key => $label ) {
			$parts[] = sprintf( '<li>%s <span class="count">(%d)</span></li>', esc_html( $label ), count( $by[ $key ] ?? [] ) );
		}
		echo implode( ' | ', $parts ) . '</ul><br class="clear">'; // phpcs:ignore WordPress.Security.EscapeOutput

		$list = function ( string $title, array $rows, callable $extra ): void {
			echo '<h2>' . esc_html( $title ) . '</h2>';
			if ( ! $rows ) {
				echo '<p class="description">None.</p>';
				return;
			}
			echo '<table class="widefat striped cc-admin__table"><tbody>';
			foreach ( $rows as $row ) {
				printf( '<tr><td><a href="%s"><strong>%s</strong></a></td><td>%s</td></tr>', esc_url( self::cleaner_url( (int) $row['id'] ) ), esc_html( $row['name'] ), $extra( $row ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		};
		$list( 'Waiting for approval', $by['review'] ?? [], function ( $c ) {
			$missing = Cleaner_Accounts::missing( (int) $c['id'] );
			return esc_html( $missing ? 'To check: ' . implode( '; ', $missing ) : 'Everything approved — ready to activate' );
		} );
		$list( 'Setting up or invited', array_merge( $by['setup'] ?? [], $by['invited'] ?? [] ), function ( $c ) {
			$account = Cleaner_Accounts::account( (int) $c['id'] );
			return Cleaner_Accounts::badge( Cleaner_Accounts::status( $c ) ) . ' ' . esc_html( 'invited' === Cleaner_Accounts::status( $c ) ? 'Invited ' . Admin::when( $account['invited_at'], 'j M' ) : count( (array) $account['setup'] ) . ' of ' . count( \CC\Bookings\Cleaner_Setup::STEPS ) . ' steps done' );
		} );

		global $wpdb;
		$soon = $wpdb->get_results( $wpdb->prepare(
			'SELECT d.*, c.name FROM ' . \CC\Bookings\Schema::table( Cleaner_Docs::TABLE ) . ' d JOIN ' . \CC\Bookings\Schema::table( 'cleaners' ) . " c ON c.id = d.cleaner_id WHERE d.status IN ('approved','pending','expired') AND d.expires_on IS NOT NULL AND d.expires_on <= %s AND c.account_status <> 'deactivated' ORDER BY d.expires_on ASC LIMIT 100",
			wp_date( 'Y-m-d', time() + 45 * DAY_IN_SECONDS )
		), ARRAY_A ) ?: [];
		echo '<h2>Documents expiring in the next 45 days</h2>';
		if ( $soon ) {
			echo '<table class="widefat striped cc-admin__table"><tbody>';
			foreach ( $soon as $doc ) {
				printf( '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_url( self::cleaner_url( (int) $doc['cleaner_id'] ) ), esc_html( $doc['name'] ), esc_html( Cleaner_Docs::label( $doc['type'] ) ), esc_html( wp_date( 'j M Y', strtotime( $doc['expires_on'] . ' 12:00' ) ) ), Cleaner_Docs::badge( $doc['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table><p class="description">Cleaners and the office are emailed ' . esc_html( (string) Cleaner_Accounts::opt( 'reminder_days' ) ) . ' days before a document expires. Expired documents don\'t stop dispatch automatically — deactivate the cleaner if they can\'t work.</p>';
		} else {
			echo '<p class="description">None.</p>';
		}

		self::settings_form();
		Admin::footer();
	}

	private static function settings_form(): void {
		echo '<h2>Settings</h2>';
		echo Admin::form( 'cacct_settings' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table"><tbody>';
		$num = function ( string $key, string $label, string $help ): void {
			printf( '<tr><th><label for="cc-a-%1$s">%2$s</label></th><td><input id="cc-a-%1$s" type="number" min="0" name="accounts[%1$s]" value="%3$s" class="small-text"> <p class="description">%4$s</p></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( (string) Cleaner_Accounts::opt( $key ) ), esc_html( $help ) );
		};
		$num( 'invite_days', 'Invite link lasts (days)', 'Resending an invite always makes a new link.' );
		$num( 'magic_minutes', 'Login link lasts (minutes)', 'Single use.' );
		$num( 'reset_minutes', 'Password reset link lasts (minutes)', 'Single use.' );
		printf( '<tr><th><label for="cc-a-reminder_days">Expiry reminders (days before)</label></th><td><input id="cc-a-reminder_days" type="text" name="accounts[reminder_days]" value="%s" class="regular-text"><p class="description">Comma-separated, e.g. 30,7. The cleaner and the office are both emailed.</p></td></tr>', esc_attr( (string) Cleaner_Accounts::opt( 'reminder_days' ) ) );
		$num( 'retention_docs_days', 'Keep documents after deactivation (days)', 'UK GDPR: documents and the photo are deleted this many days after a cleaner is deactivated. 0 = keep until deleted by hand.' );
		$num( 'retention_bank_days', 'Keep bank details after deactivation (days)', 'Long enough to make the final payment. 0 = keep until deleted by hand.' );
		foreach ( [ 'employed' => 'Terms for employed cleaners', 'self_employed' => 'Contractor agreement (self-employed)' ] as $type => $label ) {
			printf(
				'<tr><th><label for="cc-a-terms-%1$s">%2$s</label></th><td><p><label>Version <input type="text" name="accounts[terms_%1$s_version]" value="%3$s" class="regular-text" style="width:10em"></label></p><textarea id="cc-a-terms-%1$s" name="accounts[terms_%1$s]" rows="10" class="large-text">%4$s</textarea><p class="description">Change the version whenever you change the wording. Each cleaner\'s agreement records the version, time and IP address, and the exact wording agreed is kept.</p></td></tr>',
				esc_attr( $type ),
				esc_html( $label ),
				esc_attr( (string) Cleaner_Accounts::opt( 'terms_' . $type . '_version' ) ),
				esc_textarea( (string) Cleaner_Accounts::opt( 'terms_' . $type ) )
			);
		}
		echo '</tbody></table><p><button class="button button-primary">Save settings</button></p></form>';
		echo '<p class="description">Email wording for invites, login links, password resets, document reminders and approvals is under Settings → Emails.</p>';
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	public static function handle( string $do ): void {
		$id   = Admin::int( 'id' );
		$back = $id ? self::cleaner_url( $id ) : Admin::url( self::PAGE );
		if ( 'cacct_settings' !== $do && ! Cleaners::get( $id ) ) {
			Admin::done( Admin::url( 'cc-bookings-cleaners' ), 'Cleaner not found.', 'error' );
		}
		switch ( $do ) {
			case 'cacct_invite':
				$result = Cleaner_Invites::send( $id );
				is_wp_error( $result )
					? Admin::done( $back, esc_html( $result->get_error_message() ), 'error' )
					: Admin::done( $back, 'Invite emailed. The link works once and expires in ' . (int) Cleaner_Accounts::opt( 'invite_days' ) . ' days.' );
				break;
			case 'cacct_activate':
				$result = Cleaner_Accounts::activate( $id, (bool) Admin::int( 'force' ) );
				is_wp_error( $result ) ? Admin::done( $back, esc_html( $result->get_error_message() ), 'error' ) : Admin::done( $back, 'Account active. They\'ve been emailed and will now be offered jobs.' );
				break;
			case 'cacct_deactivate':
				Cleaner_Accounts::deactivate( $id, Admin::text( 'reason' ) );
				Admin::done( $back, 'Deactivated: logged out everywhere, job links revoked and open offers withdrawn.' );
				break;
			case 'cacct_reactivate':
				Cleaner_Accounts::reactivate( $id );
				Admin::done( $back, 'Reactivated. Earlier job links stay revoked; new offers carry new links.' );
				break;
			case 'cacct_doc_approve':
				Cleaner_Docs::approve( Admin::int( 'doc' ) ) ? Admin::done( $back, 'Document approved.' ) : Admin::done( $back, 'That document can\'t be approved now.', 'error' );
				break;
			case 'cacct_doc_reject':
				$result = Cleaner_Docs::reject( Admin::int( 'doc' ), Admin::text( 'reason' ) );
				is_wp_error( $result ) ? Admin::done( $back, esc_html( $result->get_error_message() ), 'error' ) : Admin::done( $back, 'Document rejected and the cleaner emailed to upload it again.' );
				break;
			case 'cacct_doc_expiry':
				$result = Cleaner_Docs::set_expiry( Admin::int( 'doc' ), Admin::text( 'expires_on' ) );
				is_wp_error( $result ) ? Admin::done( $back, esc_html( $result->get_error_message() ), 'error' ) : Admin::done( $back, 'Expiry date saved.' );
				break;
			case 'cacct_settings':
				$in  = (array) wp_unslash( $_POST['accounts'] ?? [] ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in Admin::handle().
				$out = [];
				foreach ( [ 'invite_days' => [ 1, 30 ], 'magic_minutes' => [ 5, 60 ], 'reset_minutes' => [ 15, 1440 ], 'retention_docs_days' => [ 0, 3650 ], 'retention_bank_days' => [ 0, 3650 ] ] as $key => [ $min, $max ] ) {
					$value       = (int) ( $in[ $key ] ?? Cleaner_Accounts::opt( $key ) );
					$out[ $key ] = 0 === $value && 0 === $min ? 0 : max( $min, min( $max, $value ) );
				}
				$days                 = array_unique( array_filter( array_map( 'intval', explode( ',', (string) ( $in['reminder_days'] ?? '' ) ) ), fn( $d ) => $d > 0 && $d <= 365 ) );
				rsort( $days );
				$out['reminder_days'] = implode( ',', $days ?: [ 30, 7 ] );
				foreach ( [ 'employed', 'self_employed' ] as $type ) {
					$out[ 'terms_' . $type ]              = sanitize_textarea_field( (string) ( $in[ 'terms_' . $type ] ?? '' ) ) ?: (string) Cleaner_Accounts::opt( 'terms_' . $type );
					$out[ 'terms_' . $type . '_version' ] = sanitize_text_field( (string) ( $in[ 'terms_' . $type . '_version' ] ?? '' ) ) ?: (string) Cleaner_Accounts::opt( 'terms_' . $type . '_version' );
				}
				Settings::set( 'accounts', $out );
				Events::log( 'system', 0, 'settings', 'Cleaner account settings saved' );
				Admin::done( Admin::url( self::PAGE ), 'Settings saved.' );
				break;
		}
		Admin::done( $back, 'Unknown action.', 'error' );
	}
}

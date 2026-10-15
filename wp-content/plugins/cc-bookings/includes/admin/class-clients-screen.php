<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Bookings;
use CC\Bookings\Client_Accounts;
use CC\Bookings\Client_Auth;
use CC\Bookings\Db;
use CC\Bookings\Events;
use CC\Bookings\Money;
use CC\Bookings\Notify;
use CC\Bookings\Plans;
use CC\Bookings\Quotes;
use CC\Bookings\Recurring;
use CC\Bookings\Schema;
use CC\Bookings\Settings;
use CC\Bookings\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Bookings → Clients: client accounts (created automatically from quotes), each with their quotes, bookings, plans
 * and history; resend "View my quote", send a login link, deactivate / reactivate; and the clients.* settings.
 */
final class Clients_Screen {
	const PAGE = 'cc-bookings-clients';

	const ACTIONS = [ 'client_resend', 'client_login_link', 'client_deactivate', 'client_reactivate', 'client_settings' ];

	const PER_PAGE = 30;

	public static function init(): void {
		add_filter( 'cc_bookings_admin_pages', [ self::class, 'pages' ], 35 );
		foreach ( self::ACTIONS as $do ) {
			add_action( 'cc_bookings_admin_action_' . $do, fn() => self::handle( $do ) );
		}
	}

	public static function pages( array $pages ): array {
		$out = [];
		foreach ( $pages as $slug => $page ) {
			$out[ $slug ] = $page;
			if ( 'cc-bookings-bookings' === $slug ) {
				$out[ self::PAGE ] = [ 'Clients', [ self::class, 'render' ] ];
			}
		}
		return isset( $out[ self::PAGE ] ) ? $out : $out + [ self::PAGE => [ 'Clients', [ self::class, 'render' ] ] ];
	}

	public static function render(): void {
		$id = Admin::int( 'id' );
		if ( $id && ( $client = Client_Accounts::get( $id ) ) ) {
			self::detail( $client );
			return;
		}
		self::index();
	}

	private static function badge( array $client ): string {
		if ( 'deactivated' === $client['status'] ) {
			return '<span class="cc-status cc-status--cancelled">Deactivated</span>';
		}
		if ( ! $client['user_id'] ) {
			return '<span class="cc-status cc-status--draft">No login</span>';
		}
		return $client['password_set'] ? '<span class="cc-status cc-status--confirmed">Active</span>' : '<span class="cc-status cc-status--sent">Invited</span>';
	}

	/* ---------------------------------------------------------------------
	 * List
	 * ------------------------------------------------------------------ */

	private static function index(): void {
		global $wpdb;
		Admin::header( 'Clients' );
		echo '<p class="description">A client account is created automatically for every quote request, keyed by email address. Clients see their quotes, bookings and regular plans at <a href="' . esc_url( home_url( '/account/' ) ) . '" target="_blank" rel="noopener">/account/</a>. "Invited" means they haven\'t set a password yet.</p>';

		$search = Admin::text( 's' );
		$paged  = max( 1, Admin::int( 'paged' ) );
		$table  = Schema::table( Client_Accounts::TABLE );
		$where  = '1=1';
		$args   = [];
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (name LIKE %s OR email LIKE %s OR phone LIKE %s OR postcode LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL
		$list_sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $args, [ self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ] ) ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL

		printf(
			'<form method="get" class="search-box" style="margin:0 0 8px"><input type="hidden" name="page" value="%s"><input type="search" name="s" value="%s" placeholder="Name, email, phone or postcode"> <button class="button">Search</button></form>',
			esc_attr( self::PAGE ),
			esc_attr( $search )
		);

		echo '<table class="widefat striped cc-admin__table"><thead><tr><th>Client</th><th>Account</th><th>Quotes</th><th>Bookings</th><th>Plans</th><th>Last login</th><th>Created</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">No clients yet.</td></tr>';
		}
		foreach ( $rows as $row ) {
			$client = Client_Accounts::get( (int) $row['id'] );
			printf(
				'<tr><td><a href="%s"><strong>%s</strong></a><br><span class="description">%s%s</span></td><td>%s%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
				esc_url( Admin::url( self::PAGE, [ 'id' => (int) $client['id'] ] ) ),
				esc_html( $client['name'] ?: '(no name)' ),
				esc_html( $client['email'] ),
				$client['is_test'] ? ' · test' : '',
				self::badge( $client ), // phpcs:ignore WordPress.Security.EscapeOutput
				$client['delete_requested_at'] ? ' <span class="cc-status cc-status--cancelled">Deletion requested</span>' : '',
				Db::count( 'quotes', [ 'client_id' => (int) $client['id'] ] ),
				Db::count( 'bookings', [ 'client_id' => (int) $client['id'] ] ),
				Db::count( Recurring::TABLE, [ 'client_id' => (int) $client['id'] ] ),
				esc_html( Admin::when( $client['last_login_at'], 'j M Y' ) ),
				esc_html( Admin::when( $client['created_at'], 'j M Y' ) )
			);
		}
		echo '</tbody></table>';
		echo Admin::pagination( self::PAGE, $total, self::PER_PAGE, $paged, $search ? [ 's' => $search ] : [] ); // phpcs:ignore WordPress.Security.EscapeOutput

		self::settings_form();
		Admin::footer();
	}

	/* ---------------------------------------------------------------------
	 * One client
	 * ------------------------------------------------------------------ */

	private static function detail( array $client ): void {
		$id = (int) $client['id'];
		Admin::header( $client['name'] ?: $client['email'], ' <a class="page-title-action" href="' . esc_url( Admin::url( self::PAGE ) ) . '">All clients</a>' );
		echo '<p>' . self::badge( $client ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		$user = $client['user_id'] ? get_userdata( (int) $client['user_id'] ) : null;
		$rows = [
			'Email'         => $client['email'],
			'Mobile'        => $client['phone'],
			'Address'       => implode( ', ', array_filter( [ $client['address_1'], $client['address_2'], $client['town'], $client['postcode'] ] ) ),
			'Login'         => $user ? ( $client['password_set'] ? 'Password set' : 'No password yet (signs in with the link in their quote email)' ) : ( ! empty( $client['meta']['no_login'] ) ? 'None: this email belongs to another login on the website, so their emails use private links instead' : 'None' ),
			'Last login'    => Admin::when( $client['last_login_at'] ),
			'Marketing'     => $client['marketing_opt_in'] ? 'Opted in' : 'No',
			'Created'       => Admin::when( $client['created_at'] ),
		];
		if ( $client['delete_requested_at'] ) {
			$rows['Deletion requested'] = Admin::when( $client['delete_requested_at'] ) . ' — respond within one month (UK GDPR)';
		}
		if ( $client['deactivated_at'] ) {
			$rows['Deactivated'] = Admin::when( $client['deactivated_at'] );
		}
		echo '<table class="widefat striped" style="max-width:56rem"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th style="width:11em">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ?: '—' ) );
		}
		echo '</tbody></table>';

		echo '<div class="cc-admin__actions" style="margin:12px 0">';
		if ( 'active' === $client['status'] && $user ) {
			echo Admin::button( 'client_login_link', [ 'id' => $id ], 'Email a login link', 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( 'deactivated' === $client['status'] ) {
			echo Admin::button( 'client_reactivate', [ 'id' => $id ], 'Reactivate', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo Admin::form( 'client_deactivate', [ 'id' => $id ], 'class="cc-admin__inline" onsubmit="return confirm(' . esc_attr( wp_json_encode( 'Deactivate this client? They are logged out everywhere and their emailed links stop working. Their quotes and bookings are kept.' ) ) . ')"' ) // phpcs:ignore WordPress.Security.EscapeOutput
				. ' <input type="text" name="reason" placeholder="Reason (optional)" class="regular-text" style="width:12em"> <button type="submit" class="button button-link-delete">Deactivate</button></form>';
		}
		echo '</div>';

		echo '<h2>Quotes</h2>';
		$quotes = Client_Accounts::quotes( $client );
		if ( $quotes ) {
			echo '<table class="widefat striped cc-admin__table"><tbody>';
			foreach ( $quotes as $q ) {
				$can = Quotes::is_payable( $q ) && null !== $q['total_pence'];
				printf(
					'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_url( Quotes::admin_url( (int) $q['id'] ) ),
					esc_html( $q['ref'] ),
					esc_html( $q['service_name'] ),
					Status::badge( 'quote', (string) $q['status'] ), // phpcs:ignore WordPress.Security.EscapeOutput
					esc_html( null !== $q['total_pence'] ? Money::format( (int) $q['total_pence'] ) : '—' ),
					esc_html( $q['expires_at'] ? 'Expires ' . Admin::when( $q['expires_at'], 'j M' ) : '' ),
					$can ? Admin::button( 'client_resend', [ 'id' => (int) $client['id'], 'quote' => (int) $q['id'] ], 'Resend "View my quote"', 'button button-small' ) : '' // phpcs:ignore WordPress.Security.EscapeOutput
				);
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">None.</p>';
		}

		echo '<h2>Bookings</h2>';
		$bookings = Client_Accounts::bookings( $client );
		if ( $bookings ) {
			echo '<table class="widefat striped cc-admin__table"><tbody>';
			foreach ( array_slice( $bookings, 0, 100 ) as $b ) {
				printf(
					'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_url( Bookings::admin_url( (int) $b['id'] ) ),
					esc_html( $b['ref'] ),
					esc_html( Bookings::start_label( $b ) ),
					esc_html( $b['service_name'] ),
					Status::badge( 'booking', (string) $b['status'] ), // phpcs:ignore WordPress.Security.EscapeOutput
					esc_html( Money::format( (int) $b['paid_pence'] ) . ' paid' )
				);
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">None.</p>';
		}

		$plans = Client_Accounts::plans( $client );
		if ( $plans ) {
			echo '<h2>Regular plans</h2><table class="widefat striped cc-admin__table"><tbody>';
			foreach ( $plans as $p ) {
				printf(
					'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_url( Plans::admin_url( (int) $p['id'] ) ),
					esc_html( $p['ref'] ),
					esc_html( Recurring::label( (string) $p['frequency'] ) ),
					esc_html( Recurring::PLAN_STATUSES[ $p['status'] ] ?? $p['status'] ),
					esc_html( Money::format( (int) $p['price_pence'] ) . ' per visit' )
				);
			}
			echo '</tbody></table>';
		}

		echo '<h2>History</h2>';
		echo Admin::timeline( Events::for_object( 'client', (int) $client['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		Admin::footer();
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	private static function settings_form(): void {
		echo '<h2>Client account settings</h2>';
		echo Admin::form( 'client_settings' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table"><tbody>';
		printf(
			'<tr><th><label for="cc-c-form_notice">Line under the quote form\'s button</label></th><td><input id="cc-c-form_notice" type="text" name="clients[form_notice]" value="%s" class="large-text"><p class="description">Shown on the last step of the quote form. Leave empty to hide it.</p></td></tr>',
			esc_attr( (string) Client_Accounts::opt( 'form_notice' ) )
		);
		$num = function ( string $key, string $label, string $help ): void {
			printf( '<tr><th><label for="cc-c-%1$s">%2$s</label></th><td><input id="cc-c-%1$s" type="number" min="1" name="clients[%1$s]" value="%3$s" class="small-text"> <p class="description">%4$s</p></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( (string) Client_Accounts::opt( $key ) ), esc_html( $help ) );
		};
		$num( 'quote_link_days', '"View my quote" link lasts (days)', 'Single use. Clients without a password are signed in by it and must then choose one; clients with a password are asked to log in.' );
		$num( 'magic_minutes', 'Login link lasts (minutes)', 'The "No password?" email link. Single use.' );
		$num( 'reset_minutes', 'Password reset link lasts (minutes)', 'Single use.' );
		$num( 'email_hours', 'Email change confirmation lasts (hours)', 'Sent to the new address; the email only changes when it is clicked.' );
		echo '</tbody></table><p><button class="button button-primary">Save settings</button></p></form>';
		echo '<p class="description">Email wording for quote, login, reset and email-change emails is under Settings → Emails.</p>';
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	public static function handle( string $do ): void {
		$id     = Admin::int( 'id' );
		$back   = $id ? Admin::url( self::PAGE, [ 'id' => $id ] ) : Admin::url( self::PAGE );
		$client = $id ? Client_Accounts::get( $id ) : null;
		if ( 'client_settings' !== $do && ! $client ) {
			Admin::done( Admin::url( self::PAGE ), 'Client not found.', 'error' );
		}
		switch ( $do ) {
			case 'client_resend':
				$quote = Quotes::get( Admin::int( 'quote' ) );
				if ( ! $quote || ! Client_Accounts::owns( $client, $quote ) || ! Quotes::is_payable( $quote ) ) {
					Admin::done( $back, 'That quote can\'t be sent now.', 'error' );
				}
				Notify::quote_sent( (int) $quote['id'], [ 'url' => Quotes::link( $quote ) ] );
				Events::log( 'quote', (int) $quote['id'], 'resent', '"View my quote" email resent from the Clients screen' );
				Admin::done( $back, 'Quote email sent with a new "View my quote" link.' );
				break;
			case 'client_login_link':
				Client_Auth::send_login_link( $client )
					? Admin::done( $back, 'Login link emailed. It works once and expires in 24 hours.' )
					: Admin::done( $back, 'This client can\'t log in, so no link was sent.', 'error' );
				break;
			case 'client_deactivate':
				Client_Accounts::deactivate( $id, Admin::text( 'reason' ) );
				Admin::done( $back, 'Deactivated: logged out everywhere and emailed links revoked.' );
				break;
			case 'client_reactivate':
				Client_Accounts::reactivate( $id );
				Admin::done( $back, 'Reactivated. Earlier emailed links stay revoked; send a login link if they need one.' );
				break;
			case 'client_settings':
				$in  = (array) wp_unslash( $_POST['clients'] ?? [] ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in Admin::handle().
				$out = [ 'form_notice' => mb_substr( sanitize_text_field( (string) ( $in['form_notice'] ?? '' ) ), 0, 300 ) ];
				foreach ( [ 'quote_link_days' => [ 1, 30 ], 'magic_minutes' => [ 5, 60 ], 'reset_minutes' => [ 15, 1440 ], 'email_hours' => [ 1, 72 ] ] as $key => [ $min, $max ] ) {
					$out[ $key ] = max( $min, min( $max, (int) ( $in[ $key ] ?? Client_Accounts::opt( $key ) ) ) );
				}
				Settings::set( 'clients', $out );
				Events::log( 'system', 0, 'settings', 'Client account settings saved' );
				Admin::done( Admin::url( self::PAGE ), 'Settings saved.' );
				break;
		}
		Admin::done( $back, 'Unknown action.', 'error' );
	}
}

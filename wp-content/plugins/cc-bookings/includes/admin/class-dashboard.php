<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Bookings;
use CC\Bookings\Db;
use CC\Bookings\Money;
use CC\Bookings\Notifications;
use CC\Bookings\Queue;
use CC\Bookings\Schema;
use CC\Bookings\Security;
use CC\Bookings\Settings;
use CC\Bookings\Slots;
use CC\Bookings\Status;
use CC\Bookings\Stripe;

defined( 'ABSPATH' ) || exit;

final class Dashboard {
	public static function render(): void {
		global $wpdb;
		Admin::header( 'Bookings' );

		$draft    = Db::all( 'quotes', [ 'status' => 'draft', 'booking_id' => 0 ], 'id ASC', 20 );
		$needs    = Db::all( 'bookings', [ 'status' => 'confirmed' ], 'job_date ASC, slot ASC', 20 );
		$upcoming = array_map( [ Db::class, 'decode' ], $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . Schema::table( 'bookings' ) . " WHERE status IN ('confirmed','dispatching','assigned') AND job_date BETWEEN %s AND %s ORDER BY job_date ASC, slot ASC LIMIT 50",
			wp_date( 'Y-m-d' ),
			wp_date( 'Y-m-d', time() + 14 * DAY_IN_SECONDS )
		), ARRAY_A ) ?: [] );

		echo '<div class="cc-admin__cards">';
		self::card( 'Unread notifications', Notifications::counts()['total'], Admin::url( 'cc-bookings-activity', [ 'unread' => '1' ] ) );
		self::card( 'Quotes to price', count( $draft ), Admin::url( 'cc-bookings-quotes', [ 'status' => 'draft' ] ) );
		self::card( 'Bookings needing a cleaner', count( $needs ), Admin::url( 'cc-bookings-bookings', [ 'status' => 'confirmed' ] ) );
		self::card( 'Jobs in the next 14 days', count( $upcoming ), Admin::url( 'cc-bookings-bookings' ) );
		self::card( 'Quotes sent (30 days)', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'quotes' ) . ' WHERE sent_at >= %s', Db::datetime( time() - 30 * DAY_IN_SECONDS ) ) ), Admin::url( 'cc-bookings-quotes' ) );
		echo '</div>';

		echo '<div class="cc-admin__cols"><div>';
		echo '<h2>Quotes waiting for a price</h2>';
		if ( $draft ) {
			echo '<table class="widefat striped"><thead><tr><th>Quote</th><th>Customer</th><th>Service</th><th>Requested</th></tr></thead><tbody>';
			foreach ( $draft as $q ) {
				printf(
					'<tr><td><a href="%s"><strong>%s</strong></a>%s</td><td>%s<br><span class="description">%s</span></td><td>%s</td><td>%s</td></tr>',
					esc_url( \CC\Bookings\Quotes::admin_url( $q['id'] ) ),
					esc_html( $q['ref'] ),
					$q['is_test'] ? ' <span class="cc-admin__test">Test</span>' : '',
					esc_html( $q['customer_name'] ),
					esc_html( $q['postcode'] ),
					esc_html( $q['service_name'] ),
					esc_html( Admin::when( $q['created_at'] ) )
				);
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">None — all caught up.</p>';
		}

		echo '<h2>Upcoming jobs</h2>';
		if ( $upcoming ) {
			echo '<table class="widefat striped"><thead><tr><th>When</th><th>Booking</th><th>Customer</th><th>Crew</th><th>Status</th></tr></thead><tbody>';
			foreach ( $upcoming as $b ) {
				$crew = array_map( fn( $o ) => $o['cleaner']['name'] ?? '?', Bookings::crew( $b['id'] ) );
				printf(
					'<tr><td>%s<br><span class="description">%s</span></td><td><a href="%s"><strong>%s</strong></a>%s<br>%s</td><td>%s<br><span class="description">%s</span></td><td>%s</td><td>%s</td></tr>',
					esc_html( Slots::date_label( $b['job_date'], 'D j M' ) ),
					esc_html( Slots::label( $b['slot'] ) ),
					esc_url( Bookings::admin_url( $b['id'] ) ),
					esc_html( $b['ref'] ),
					$b['is_test'] ? ' <span class="cc-admin__test">Test</span>' : '',
					esc_html( $b['service_name'] ),
					esc_html( $b['customer_name'] ),
					esc_html( $b['postcode'] ),
					esc_html( $crew ? implode( ', ', $crew ) : sprintf( 'Needs %d', $b['cleaners'] ) ),
					Status::badge( 'booking', $b['status'] ) // phpcs:ignore WordPress.Security.EscapeOutput
				);
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">No jobs booked in the next 14 days.</p>';
		}
		echo '</div><div>';

		echo '<h2>System</h2><table class="widefat cc-admin__kv"><tbody>';
		$rows = [
			'Online payment'  => Stripe::enabled() ? ( Stripe::test_mode() ? 'Stripe (test mode)' : 'Stripe (live)' ) : 'Not set up — customers are asked to wait for a call',
			'Stripe webhook'  => Stripe::webhook_secret() ? 'Signing secret set' : 'No signing secret',
			'Spam check'      => Security::turnstile_site_key() ? 'Cloudflare Turnstile' : 'Honeypot only (Turnstile keys not set)',
			'Emails'          => Settings::get( 'emails.log_only' ) ? 'Log only (not sent)' : 'Sending via wp_mail',
			'Background jobs' => Queue::driver() . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? ' (WP-Cron disabled — server cron required)' : ' (WP-Cron)' ),
			'Revenue (30 days)' => Money::format( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(paid_pence - refunded_pence), 0) FROM ' . Schema::table( 'bookings' ) . ' WHERE confirmed_at >= %s AND is_test = 0', Db::datetime( time() - 30 * DAY_IN_SECONDS ) ) ) ),
		];
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</tbody></table>';

		echo '<h2>Recent activity</h2>';
		echo Admin::timeline( array_reverse( Db::all( 'events', [], 'id DESC', 12 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<p><a href="%s">All activity</a></p>', esc_url( Admin::url( 'cc-bookings-activity' ) ) );
		echo '</div></div>';

		Admin::footer();
	}

	private static function card( string $label, int $count, string $url ): void {
		printf( '<a class="cc-admin__card" href="%s"><strong>%d</strong><span>%s</span></a>', esc_url( $url ), $count, esc_html( $label ) );
	}
}

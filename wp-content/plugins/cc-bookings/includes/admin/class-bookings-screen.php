<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Bookings;
use CC\Bookings\Catalog;
use CC\Bookings\Cleaners;
use CC\Bookings\Db;
use CC\Bookings\Events;
use CC\Bookings\Files;
use CC\Bookings\Money;
use CC\Bookings\Pricing;
use CC\Bookings\Quotes;
use CC\Bookings\Schema;
use CC\Bookings\Slots;
use CC\Bookings\Status;
use CC\Bookings\Stripe;

defined( 'ABSPATH' ) || exit;

final class Bookings_Screen {
	const PAGE     = 'cc-bookings-bookings';
	const PER_PAGE = 30;

	public static function render(): void {
		$id = Admin::int( 'id' );
		$id ? self::detail( $id ) : self::list();
	}

	private static function list(): void {
		global $wpdb;
		$status = sanitize_key( Admin::text( 'status' ) );
		$search = Admin::text( 's' );
		$when   = Admin::text( 'when' ) ?: 'upcoming';
		$paged  = max( 1, Admin::int( 'paged' ) );

		$where  = [];
		$params = [];
		if ( $status && isset( Status::all( 'booking' )[ $status ] ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( 'upcoming' === $when ) {
			$where[]  = '(job_date >= %s OR job_date IS NULL)';
			$params[] = wp_date( 'Y-m-d' );
		} elseif ( 'past' === $when ) {
			$where[]  = 'job_date < %s';
			$params[] = wp_date( 'Y-m-d' );
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(ref LIKE %s OR customer_name LIKE %s OR email LIKE %s OR postcode LIKE %s)';
			$params  = array_merge( $params, [ $like, $like, $like, $like ] );
		}
		$order = 'past' === $when ? 'job_date DESC, slot DESC' : 'job_date ASC, slot ASC';
		$sql   = ' FROM ' . Schema::table( 'bookings' ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' );
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( 'SELECT COUNT(*)' . $sql, $params ) : 'SELECT COUNT(*)' . $sql );
		$query = 'SELECT *' . $sql . " ORDER BY {$order}" . sprintf( ' LIMIT %d OFFSET %d', self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$rows  = array_map( [ Db::class, 'decode' ], $wpdb->get_results( $params ? $wpdb->prepare( $query, $params ) : $query, ARRAY_A ) ?: [] );

		Admin::header( 'Bookings' );
		echo Admin::filters( self::PAGE, Status::all( 'booking' ), $status, fn( $s ) => Db::count( 'bookings', $s ? [ 'status' => $s ] : [] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		$whens = [ 'upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'Any date' ];
		$opts  = '';
		foreach ( $whens as $key => $label ) {
			$opts .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $when, $key, false ), esc_html( $label ) );
		}
		printf(
			'<form class="search-box" method="get"><input type="hidden" name="page" value="%s"><input type="hidden" name="status" value="%s"><select name="when">%s</select> <input type="search" name="s" value="%s" placeholder="Ref, name, email or postcode"><button class="button">Filter</button></form>',
			esc_attr( self::PAGE ),
			esc_attr( $status ),
			$opts, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_attr( $search )
		);

		echo '<table class="widefat striped cc-admin__table"><thead><tr><th>When</th><th>Booking</th><th>Customer</th><th>Crew</th><th>Paid</th><th>Status</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">No bookings found.</td></tr>';
		}
		foreach ( $rows as $b ) {
			$crew = array_map( fn( $o ) => $o['cleaner']['name'] ?? '?', Bookings::crew( $b['id'] ) );
			printf(
				'<tr><td>%s<br><span class="description">%s</span></td><td><a href="%s"><strong>%s</strong></a>%s<br>%s</td><td>%s<br><span class="description">%s</span></td><td>%s</td><td>%s<br>%s</td><td>%s</td></tr>',
				esc_html( $b['job_date'] ? Slots::date_label( $b['job_date'], 'D j M Y' ) : 'To arrange' ),
				esc_html( Slots::label( (string) $b['slot'] ) ),
				esc_url( Bookings::admin_url( $b['id'] ) ),
				esc_html( $b['ref'] ),
				$b['is_test'] ? ' <span class="cc-admin__test">Test</span>' : '',
				esc_html( $b['service_name'] ),
				esc_html( $b['customer_name'] ),
				esc_html( $b['postcode'] ),
				esc_html( $crew ? implode( ', ', $crew ) : sprintf( 'Needs %d', $b['cleaners'] ) ),
				esc_html( Money::format( $b['paid_pence'] - $b['refunded_pence'] ) ),
				Status::badge( 'payment', $b['payment_status'] ), // phpcs:ignore WordPress.Security.EscapeOutput
				Status::badge( 'booking', $b['status'] ) // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
		echo '</tbody></table>';
		echo Admin::pagination( self::PAGE, $total, self::PER_PAGE, $paged, array_filter( [ 'status' => $status, 's' => $search, 'when' => $when ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		Admin::footer();
	}

	private static function detail( int $id ): void {
		$b = Bookings::get( $id );
		if ( ! $b ) {
			Admin::header( 'Booking not found' );
			Admin::footer();
			return;
		}
		$open    = ! in_array( $b['status'], [ 'cancelled', 'completed' ], true );
		$crew    = Bookings::crew( $id );
		$service = Catalog::get( (string) $b['service_slug'] );

		Admin::header( 'Booking ' . $b['ref'], ' ' . Status::badge( 'booking', $b['status'] ) . ' ' . Status::badge( 'payment', $b['payment_status'] ) . ( $b['is_test'] ? ' <span class="cc-admin__test">Test</span>' : '' ) );
		printf( '<p><a href="%s">&larr; All bookings</a>%s</p>', esc_url( Admin::url( self::PAGE ) ), $b['quote_id'] ? ' · <a href="' . esc_url( Quotes::admin_url( $b['quote_id'] ) ) . '">Original quote</a>' : '' );

		echo '<div class="cc-admin__cols"><div>';

		echo '<h2>Job</h2><table class="widefat cc-admin__kv"><tbody>';
		$rows = [
			'When'     => Bookings::start_label( $b ),
			'Service'  => $b['service_name'],
			'Duration' => Pricing::duration_label( (float) $b['hours'], (int) $b['cleaners'] ) ?: '—',
		];
		foreach ( $service ? Catalog::summary( $service, (array) $b['answers'] ) : [] as [ $label, $value ] ) {
			$rows[ $label ] = $value;
		}
		$rows['Address']      = Bookings::full_address( $b );
		$rows['Access notes'] = $b['access_notes'] ?: '—';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), nl2br( esc_html( (string) $value ) ) );
		}
		echo '</tbody></table>';

		$quote = $b['quote_id'] ? Quotes::get( $b['quote_id'] ) : null;
		if ( $quote && $quote['photos'] ) {
			echo '<h3>Photos</h3><p class="cc-admin__photos">';
			foreach ( (array) $quote['photos'] as $name ) {
				printf( '<a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="" loading="lazy"></a>', esc_url( Files::admin_url( $quote['ref'], $name ) ) );
			}
			echo '</p>';
		}

		echo '<h2>Cleaners</h2>';
		if ( $crew ) {
			echo '<ul class="cc-admin__crew">';
			foreach ( $crew as $offer ) {
				printf(
					'<li><strong>%s</strong> <span class="description">%s · %s</span> %s</li>',
					esc_html( $offer['cleaner']['name'] ?? 'Unknown' ),
					esc_html( ucfirst( $offer['role'] ) ),
					esc_html( $offer['cleaner']['phone'] ?? '' ),
					$open ? Admin::button( 'booking_unassign', [ 'id' => $id, 'offer' => $offer['id'] ], 'Remove', 'button-link button-link-delete', 'Take this cleaner off the job?' ) : '' // phpcs:ignore WordPress.Security.EscapeOutput
				);
			}
			echo '</ul>';
		} else {
			printf( '<p class="description">No one assigned yet. This job needs %d cleaner%s.</p>', (int) $b['cleaners'], 1 === (int) $b['cleaners'] ? '' : 's' );
		}
		if ( $open ) {
			$on_job  = array_column( $crew, 'cleaner_id' );
			$options = '';
			foreach ( Cleaners::active() as $c ) {
				if ( in_array( $c['id'], $on_job, true ) ) {
					continue;
				}
				$busy     = $b['job_date'] ? count( array_filter( Cleaners::jobs( $c['id'] ), fn( $j ) => $j['job_date'] === $b['job_date'] ) ) : 0;
				$options .= sprintf( '<option value="%d">%s%s</option>', $c['id'], esc_html( $c['name'] ), $busy ? esc_html( sprintf( ' (%d other job%s that day)', $busy, 1 === $busy ? '' : 's' ) ) : '' );
			}
			if ( $options ) {
				echo Admin::form( 'booking_assign', [ 'id' => $id ] ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<p><select name="cleaner"><option value="">Choose a cleaner…</option>' . $options . '</select> <button class="button button-primary">Assign</button></p></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
			} else {
				printf( '<p><a href="%s">Add cleaners</a> to assign them to jobs.</p>', esc_url( Admin::url( Cleaners_Screen::PAGE, [ 'id' => 'new' ] ) ) );
			}
		}

		echo '<h2>History</h2>';
		echo Admin::timeline( Events::timeline( $b ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '</div><div>';

		echo '<h2>Customer</h2><table class="widefat cc-admin__kv"><tbody>';
		foreach ( [ 'Name' => $b['customer_name'], 'Email' => $b['email'], 'Mobile' => $b['phone'] ] as $label => $value ) {
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '</tbody></table>';
		printf( '<p><a class="button" href="mailto:%s">Email customer</a> <a class="button" href="tel:%s">Call</a></p>', esc_attr( $b['email'] ), esc_attr( $b['phone'] ) );

		echo '<h2>Payment</h2>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( Bookings::items( $id ) as $item ) {
			printf( '<tr><td>%s</td><td class="cc-admin__num">%s</td></tr>', esc_html( $item['label'] . ( (float) $item['quantity'] > 1 ? ' × ' . (float) $item['quantity'] : '' ) ), esc_html( Money::format( $item['amount_pence'] ) ) );
		}
		printf( '<tr><th>Total</th><th class="cc-admin__num">%s</th></tr>', esc_html( Money::format( $b['total_pence'] ) ) );
		printf( '<tr><td>Paid</td><td class="cc-admin__num">%s</td></tr>', esc_html( Money::format( $b['paid_pence'] ) ) );
		if ( $b['refunded_pence'] ) {
			printf( '<tr><td>Refunded</td><td class="cc-admin__num">−%s</td></tr>', esc_html( Money::format( $b['refunded_pence'] ) ) );
		}
		echo '</tbody></table>';

		$payments = Bookings::payments( $id );
		if ( $payments ) {
			echo '<h3>Transactions</h3><table class="widefat striped"><thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Status</th></tr></thead><tbody>';
			foreach ( $payments as $p ) {
				printf(
					'<tr><td>%s</td><td>%s<br><span class="description">%s</span></td><td>%s</td><td>%s%s</td></tr>',
					esc_html( Admin::when( $p['created_at'] ) ),
					esc_html( ucfirst( str_replace( '_', '-', $p['type'] ) ) . ' (' . $p['provider'] . ')' ),
					esc_html( (string) $p['note'] ),
					esc_html( Money::format( $p['amount_pence'] ) ),
					esc_html( $p['status'] ),
					'top_up' === $p['type'] && 'pending' === $p['status'] && $p['url'] ? ' <a href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener">link</a>' : ''
				);
			}
			echo '</tbody></table>';
		}

		echo '<h2>Actions</h2><div class="cc-admin__actions">';
		if ( $open && $b['job_date'] && $b['job_date'] <= wp_date( 'Y-m-d' ) ) {
			echo Admin::button( 'booking_complete', [ 'id' => $id ], 'Mark completed', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo Admin::button( 'booking_link', [ 'id' => $id ], 'Get customer link' ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $open ) {
			echo Admin::button( 'booking_cancel', [ 'id' => $id ], 'Cancel without refund', 'button button-link-delete', 'Cancel this booking without a refund? The customer will be emailed.' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';

		$refundable = Bookings::refundable( $b );
		if ( $refundable > 0 ) {
			echo '<h3>Refund</h3>';
			echo Admin::form( 'booking_refund', [ 'id' => $id ], 'onsubmit="return confirm(\'Issue this refund? It cannot be undone.\')"' ); // phpcs:ignore WordPress.Security.EscapeOutput
			printf( '<p><label>Amount (£) <input type="text" name="amount" value="%s" class="small-text" inputmode="decimal"></label> <span class="description">up to %s</span></p>', esc_attr( number_format( $refundable / 100, 2, '.', '' ) ), esc_html( Money::format( $refundable ) ) );
			echo '<p><input type="text" name="reason" class="regular-text" placeholder="Reason (shown to the customer)"></p>';
			if ( $open ) {
				echo '<p><label><input type="checkbox" name="cancel" value="1"> Also cancel the booking</label></p>';
			}
			echo '<p><button class="button">Refund</button></p></form>';
		}

		if ( $open ) {
			echo '<h3>Charge extra</h3>';
			if ( Stripe::enabled() ) {
				echo Admin::form( 'booking_topup', [ 'id' => $id ] ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<p><label>Amount (£) <input type="text" name="amount" class="small-text" inputmode="decimal"></label></p>';
				echo '<p><input type="text" name="reason" class="regular-text" placeholder="What it\'s for (shown to the customer)"></p>';
				echo '<p><button class="button">Email a payment link</button></p></form>';
			} else {
				echo '<p class="description">Payment links need Stripe keys in wp-config.php.</p>';
			}
		}

		echo '<h3>Internal note</h3>';
		echo Admin::form( 'booking_note', [ 'id' => $id ] ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<p><textarea name="admin_note" rows="3" class="large-text">%s</textarea></p><p><button class="button">Save note</button></p></form>', esc_textarea( (string) $b['admin_note'] ) );

		do_action( 'cc_bookings_admin_booking_sidebar', $b );
		echo '</div></div>';
		Admin::footer();
	}

	public static function handle( string $do ): void {
		$id      = Admin::int( 'id' );
		$booking = Bookings::get( $id );
		$back    = Bookings::admin_url( $id );
		if ( ! $booking ) {
			Admin::done( Admin::url( self::PAGE ), 'Booking not found.', 'error' );
		}

		switch ( $do ) {
			case 'booking_assign':
				$result = Bookings::assign( $id, Admin::int( 'cleaner' ) );
				is_wp_error( $result ) ? Admin::done( $back, $result->get_error_message(), 'error' ) : Admin::done( $back, 'Cleaner assigned and emailed.' );
				break;

			case 'booking_unassign':
				Bookings::unassign( $id, Admin::int( 'offer' ) );
				Admin::done( $back, 'Cleaner removed from the job.' );
				break;

			case 'booking_complete':
				Bookings::complete( $id ) ? Admin::done( $back, 'Marked completed.' ) : Admin::done( $back, 'This booking can\'t be completed from its current status.', 'error' );
				break;

			case 'booking_cancel':
				Bookings::cancel( $id, 'Cancelled by us' ) ? Admin::done( $back, 'Booking cancelled.' ) : Admin::done( $back, 'This booking can\'t be cancelled.', 'error' );
				break;

			case 'booking_refund':
				$amount = Money::parse( Admin::text( 'amount' ) );
				if ( ! $amount || $amount <= 0 ) {
					Admin::done( $back, 'Enter an amount to refund.', 'error' );
				}
				$result = Bookings::refund( $id, $amount, Admin::text( 'reason' ), ! empty( $_POST['cancel'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				is_wp_error( $result ) ? Admin::done( $back, $result->get_error_message(), 'error' ) : Admin::done( $back, 'Refund issued.' );
				break;

			case 'booking_topup':
				$amount = Money::parse( Admin::text( 'amount' ) );
				$result = Bookings::create_topup( $id, (int) $amount, Admin::text( 'reason' ) );
				is_wp_error( $result ) ? Admin::done( $back, $result->get_error_message(), 'error' ) : Admin::done( $back, 'Payment link emailed to the customer.' );
				break;

			case 'booking_link':
				Admin::done( $back, 'Customer link (don\'t share publicly):<br><code>' . esc_html( Bookings::link( $booking ) ) . '</code>', 'info' );
				break;

			case 'booking_note':
				Db::update( 'bookings', $id, [ 'admin_note' => Admin::textarea( 'admin_note' ) ] );
				Admin::done( $back, 'Note saved.' );
				break;
		}
		Admin::done( $back, 'Unknown action.', 'error' );
	}
}

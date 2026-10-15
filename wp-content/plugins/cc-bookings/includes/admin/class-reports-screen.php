<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Bookings;
use CC\Bookings\Cleaners;
use CC\Bookings\Db;
use CC\Bookings\Money;
use CC\Bookings\Schema;
use CC\Bookings\Slots;
use CC\Bookings\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Bookings → Reports: bookings and revenue by service, area and month, quote conversion, and CSV exports
 * (cleaner hours and pay for payroll / self-employed invoices, and a bookings list).
 * Bookings are grouped by job date; revenue is paid minus refunded. Quotes are grouped by date received.
 */
final class Reports_Screen {
	const PAGE = 'cc-bookings-reports';

	public static function init(): void {
		add_action( 'cc_bookings_admin_action_reports_csv', [ self::class, 'csv' ] );
	}

	/** @return array{from: string, to: string, test: bool, all_jobs: bool} */
	private static function range(): array {
		$tz   = wp_timezone();
		$from = Admin::text( 'from' );
		$to   = Admin::text( 'to' );
		$ok   = fn( $d ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
		return [
			'from'     => $ok( $from ) ? $from : ( new \DateTimeImmutable( 'first day of this month', $tz ) )->modify( '-11 months' )->format( 'Y-m-d' ),
			'to'       => $ok( $to ) ? $to : ( new \DateTimeImmutable( 'last day of next month', $tz ) )->format( 'Y-m-d' ),
			'test'     => ! empty( $_REQUEST['test'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'all_jobs' => ! empty( $_REQUEST['all_jobs'] ), // phpcs:ignore WordPress.Security.NonceVerification
		];
	}

	/** District => area name, from the Areas posts (same data as the area finder). */
	private static function areas(): array {
		$map = [];
		foreach ( get_posts( [ 'post_type' => 'cc_area', 'post_status' => 'publish', 'posts_per_page' => -1, 'no_found_rows' => true, 'orderby' => 'title', 'order' => 'ASC' ] ) as $area ) {
			$text = (string) get_post_meta( $area->ID, 'postcodes', true );
			foreach ( function_exists( 'cc_postcode_districts' ) ? cc_postcode_districts( $text ) : [] as $district ) {
				$map[ $district ] ??= get_the_title( $area );
			}
		}
		return $map;
	}

	private static function booking_rows( array $r, string $group ): array {
		global $wpdb;
		$sql = 'SELECT ' . $group . ' AS k, COUNT(*) AS bookings, SUM(status = \'cancelled\') AS cancelled, SUM(status = \'completed\') AS completed,'
			. ' SUM(parent_id > 0 AND total_pence = 0) AS recleans, SUM(paid_pence) AS paid, SUM(refunded_pence) AS refunded'
			. ' FROM ' . Schema::table( 'bookings' ) . ' WHERE job_date BETWEEN %s AND %s' . ( $r['test'] ? '' : ' AND is_test = 0' )
			. ' GROUP BY k ORDER BY k';
		return $wpdb->get_results( $wpdb->prepare( $sql, $r['from'], $r['to'] ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function quote_rows( array $r ): array {
		global $wpdb;
		$sql = 'SELECT LEFT(created_at, 7) AS k, COUNT(*) AS quotes, SUM(is_bespoke = 1) AS bespoke,'
			. ' SUM(total_pence IS NOT NULL AND status <> \'draft\') AS priced, SUM(booking_id > 0) AS booked'
			. ' FROM ' . Schema::table( 'quotes' ) . " WHERE created_at BETWEEN %s AND %s AND status <> 'superseded'" . ( $r['test'] ? '' : ' AND is_test = 0' )
			. ' GROUP BY k ORDER BY k';
		return $wpdb->get_results( $wpdb->prepare( $sql, self::utc( $r['from'] ), self::utc( $r['to'], true ) ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function utc( string $date, bool $end = false ): string {
		$dt = date_create_immutable( $date . ( $end ? ' 23:59:59' : ' 00:00:00' ), wp_timezone() );
		return Db::datetime( $dt ? $dt->getTimestamp() : time() );
	}

	private static function pct( int $part, int $whole ): string {
		return $whole ? round( 100 * $part / $whole ) . '%' : '—';
	}

	private static function table( string $title, string $label, array $rows ): void {
		echo '<h2>' . esc_html( $title ) . '</h2><table class="widefat striped cc-admin__report"><thead><tr><th>' . esc_html( $label ) . '</th><th class="cc-admin__num">Bookings</th><th class="cc-admin__num">Cancelled</th><th class="cc-admin__num">Completed</th><th class="cc-admin__num">Revenue (net)</th><th class="cc-admin__num">Average</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">No bookings in this period.</td></tr>';
		}
		$sum = [ 'bookings' => 0, 'cancelled' => 0, 'completed' => 0, 'net' => 0 ];
		foreach ( $rows as $row ) {
			$live = (int) $row['bookings'] - (int) $row['cancelled'];
			$net  = (int) $row['paid'] - (int) $row['refunded'];
			printf(
				'<tr><td>%s</td><td class="cc-admin__num">%d</td><td class="cc-admin__num">%d</td><td class="cc-admin__num">%d</td><td class="cc-admin__num">%s</td><td class="cc-admin__num">%s</td></tr>',
				esc_html( (string) $row['k'] ),
				$live,
				(int) $row['cancelled'],
				(int) $row['completed'],
				esc_html( Money::format( $net ) ),
				esc_html( $live ? Money::format( (int) round( $net / $live ) ) : '—' )
			);
			$sum['bookings']  += $live;
			$sum['cancelled'] += (int) $row['cancelled'];
			$sum['completed'] += (int) $row['completed'];
			$sum['net']       += $net;
		}
		if ( count( $rows ) > 1 ) {
			printf( '<tr><th>Total</th><th class="cc-admin__num">%d</th><th class="cc-admin__num">%d</th><th class="cc-admin__num">%d</th><th class="cc-admin__num">%s</th><th class="cc-admin__num">%s</th></tr>', $sum['bookings'], $sum['cancelled'], $sum['completed'], esc_html( Money::format( $sum['net'] ) ), esc_html( $sum['bookings'] ? Money::format( (int) round( $sum['net'] / $sum['bookings'] ) ) : '—' ) );
		}
		echo '</tbody></table>';
	}

	public static function render(): void {
		$r = self::range();
		Admin::header( 'Reports' );
		printf(
			'<form method="get" class="cc-admin__filters"><input type="hidden" name="page" value="%s"><label>Jobs from <input type="date" name="from" value="%s"></label> <label>to <input type="date" name="to" value="%s"></label> <label><input type="checkbox" name="test" value="1"%s> Include test data</label> <button class="button">Show</button></form>',
			esc_attr( self::PAGE ),
			esc_attr( $r['from'] ),
			esc_attr( $r['to'] ),
			checked( $r['test'], true, false )
		);

		$months = self::booking_rows( $r, 'LEFT(job_date, 7)' );
		$quotes = self::quote_rows( $r );
		$tot    = fn( string $col, array $rows ) => array_sum( array_map( 'intval', array_column( $rows, $col ) ) );
		$live   = $tot( 'bookings', $months ) - $tot( 'cancelled', $months );
		$net    = $tot( 'paid', $months ) - $tot( 'refunded', $months );

		echo '<div class="cc-admin__cards">';
		foreach ( [
			[ $live, 'Bookings (not cancelled)' ],
			[ Money::format( $net ), 'Revenue, net of refunds' ],
			[ $live ? Money::format( (int) round( $net / $live ) ) : '—', 'Average booking' ],
			[ self::pct( $tot( 'booked', $quotes ), $tot( 'priced', $quotes ) ), sprintf( 'Quote conversion (%d of %d priced)', $tot( 'booked', $quotes ), $tot( 'priced', $quotes ) ) ],
			[ $tot( 'cancelled', $months ), 'Cancellations' ],
			[ $tot( 'recleans', $months ), 'Free re-cleans' ],
		] as [ $value, $label ] ) {
			printf( '<div class="cc-admin__card"><strong>%s</strong><span>%s</span></div>', esc_html( (string) $value ), esc_html( $label ) );
		}
		echo '</div>';

		$csv = fn( string $kind, string $label, array $extra = [] ) => sprintf( '<a class="button" href="%s">%s</a>', esc_url( wp_nonce_url( add_query_arg( array_merge( [ 'action' => Admin::ACTION, 'do' => 'reports_csv', 'kind' => $kind, 'from' => $r['from'], 'to' => $r['to'], 'test' => $r['test'] ? 1 : 0 ], $extra ), admin_url( 'admin-post.php' ) ), 'cc_bookings_reports_csv' ) ), esc_html( $label ) );
		echo '<p>' . $csv( 'cleaners', 'Cleaner hours & pay (CSV) — completed jobs' ) . ' ' . $csv( 'cleaners', 'Cleaner hours & pay incl. upcoming jobs', [ 'all_jobs' => 1 ] ) . ' ' . $csv( 'bookings', 'Bookings (CSV)' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		$service = self::booking_rows( $r, 'service_name' );
		usort( $service, fn( $a, $b ) => ( $b['paid'] - $b['refunded'] ) <=> ( $a['paid'] - $a['refunded'] ) );
		self::table( 'By service', 'Service', $service );

		$areas   = self::areas();
		$by_area = [];
		foreach ( self::booking_rows( $r, 'district' ) as $row ) {
			$name = $areas[ $row['k'] ] ?? 'Other (' . ( $row['k'] ?: 'no postcode' ) . ')';
			foreach ( [ 'bookings', 'cancelled', 'completed', 'recleans', 'paid', 'refunded' ] as $col ) {
				$by_area[ $name ][ $col ] = ( $by_area[ $name ][ $col ] ?? 0 ) + (int) $row[ $col ];
			}
			$by_area[ $name ]['k']          = $name;
			$by_area[ $name ]['districts'][] = $row['k'];
		}
		uasort( $by_area, fn( $a, $b ) => ( $b['paid'] - $b['refunded'] ) <=> ( $a['paid'] - $a['refunded'] ) );
		foreach ( $by_area as $name => $row ) {
			$by_area[ $name ]['k'] = $name . ' (' . implode( ', ', array_filter( $row['districts'] ) ) . ')';
		}
		self::table( 'By area', 'Area (postcode districts)', array_values( $by_area ) );

		self::table( 'By month of the clean', 'Month', array_map( fn( $row ) => [ 'k' => wp_date( 'F Y', strtotime( $row['k'] . '-01' ) ) ] + $row, $months ) );

		echo '<h2>Quote conversion by month received</h2><table class="widefat striped"><thead><tr><th>Month</th><th class="cc-admin__num">Quotes</th><th class="cc-admin__num">Bespoke</th><th class="cc-admin__num">Priced</th><th class="cc-admin__num">Booked</th><th class="cc-admin__num">Conversion</th></tr></thead><tbody>';
		if ( ! $quotes ) {
			echo '<tr><td colspan="6">No quotes in this period.</td></tr>';
		}
		foreach ( $quotes as $q ) {
			printf( '<tr><td>%s</td><td class="cc-admin__num">%d</td><td class="cc-admin__num">%d</td><td class="cc-admin__num">%d</td><td class="cc-admin__num">%d</td><td class="cc-admin__num">%s</td></tr>', esc_html( wp_date( 'F Y', strtotime( $q['k'] . '-01' ) ) ), (int) $q['quotes'], (int) $q['bespoke'], (int) $q['priced'], (int) $q['booked'], esc_html( self::pct( (int) $q['booked'], (int) $q['priced'] ) ) );
		}
		echo '</tbody></table><p class="description">Conversion = quotes that became a paid booking ÷ quotes with a price sent. Replaced quotes (customer changed details) are not counted twice.</p>';

		echo '<h2>Cleaner hours &amp; pay — completed jobs</h2>';
		$totals = [];
		foreach ( self::pay_lines( $r ) as $line ) {
			$t                    = $totals[ $line['cleaner'] ] ?? [ 'type' => $line['type'], 'jobs' => 0, 'hours' => 0.0, 'pay' => 0 ];
			$totals[ $line['cleaner'] ] = [ 'type' => $line['type'], 'jobs' => $t['jobs'] + 1, 'hours' => $t['hours'] + $line['hours'], 'pay' => $t['pay'] + $line['pay_pence'] ];
		}
		echo '<table class="widefat striped"><thead><tr><th>Cleaner</th><th>Type</th><th class="cc-admin__num">Jobs</th><th class="cc-admin__num">Hours</th><th class="cc-admin__num">Pay</th></tr></thead><tbody>';
		if ( ! $totals ) {
			echo '<tr><td colspan="5">No completed jobs with a cleaner in this period.</td></tr>';
		}
		foreach ( $totals as $name => $t ) {
			printf( '<tr><td>%s</td><td>%s</td><td class="cc-admin__num">%d</td><td class="cc-admin__num">%s</td><td class="cc-admin__num">%s</td></tr>', esc_html( $name ), esc_html( $t['type'] ), $t['jobs'], esc_html( rtrim( rtrim( number_format( $t['hours'], 2 ), '0' ), '.' ) ), esc_html( Money::format( $t['pay'] ) ) );
		}
		echo '</tbody></table>';
		Admin::footer();
	}

	/**
	 * One line per cleaner per job. Pay: the offer's agreed pay if set, else the cleaner's per-job rate,
	 * else hourly rate × job hours.
	 */
	private static function pay_lines( array $r ): array {
		global $wpdb;
		$on_job   = Status::on_job();
		$statuses = $r['all_jobs'] ? [ 'confirmed', 'dispatching', 'assigned', 'completed' ] : [ 'completed' ];
		$sql      = 'SELECT o.id AS offer_id, o.cleaner_id, o.role, o.pay_pence AS offer_pay, b.id AS booking_id FROM ' . Schema::table( 'job_offers' ) . ' o JOIN ' . Schema::table( 'bookings' ) . ' b ON b.id = o.booking_id'
			. ' WHERE o.status IN (' . implode( ',', array_fill( 0, count( $on_job ), '%s' ) ) . ') AND b.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')'
			. ' AND b.job_date BETWEEN %s AND %s' . ( $r['test'] ? '' : ' AND b.is_test = 0' ) . ' ORDER BY b.job_date ASC, b.slot ASC, o.id ASC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $on_job, $statuses, [ $r['from'], $r['to'] ] ) ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$out = [];
		foreach ( $rows as $row ) {
			$b = Bookings::get( (int) $row['booking_id'] );
			$c = Cleaners::get( (int) $row['cleaner_id'] );
			if ( ! $b || ! $c ) {
				continue;
			}
			$hours = (float) $b['hours'];
			if ( (int) $row['offer_pay'] > 0 ) {
				[ $basis, $pay ] = [ 'Agreed for this job', (int) $row['offer_pay'] ];
			} elseif ( 'per_job' === $c['pay_type'] ) {
				[ $basis, $pay ] = [ 'Per job', (int) $c['pay_rate_pence'] ];
			} else {
				[ $basis, $pay ] = [ 'Hourly', (int) round( (int) $c['pay_rate_pence'] * $hours ) ];
			}
			$out[] = [
				'cleaner'   => $c['name'],
				'type'      => Cleaners::TYPES[ $c['type'] ] ?? $c['type'],
				'email'     => $c['email'],
				'date'      => (string) $b['job_date'],
				'slot'      => Slots::label( (string) $b['slot'], false ),
				'booking'   => $b['ref'],
				'service'   => $b['service_name'],
				'postcode'  => $b['postcode'],
				'role'      => ucfirst( (string) $row['role'] ),
				'hours'     => $hours,
				'basis'     => $basis,
				'rate'      => (int) $c['pay_rate_pence'],
				'pay_pence' => $pay,
				'status'    => Status::label( 'booking', $b['status'] ),
				'note'      => ! empty( $b['meta']['reclean_of'] ) ? 'Re-clean (no charge to customer)' : '',
			];
		}
		return $out;
	}

	/** admin-post.php?action=cc_bookings&do=reports_csv&kind=cleaners|bookings (nonce-checked by Admin::handle). */
	public static function csv(): void {
		global $wpdb;
		$r    = self::range();
		$kind = 'bookings' === Admin::text( 'kind' ) ? 'bookings' : 'cleaners';
		$pounds = fn( int $p ) => number_format( $p / 100, 2, '.', '' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( sprintf( 'Content-Disposition: attachment; filename="%s-%s-to-%s.csv"', 'cleaners' === $kind ? 'cleaner-pay' : 'bookings', $r['from'], $r['to'] ) );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		$row = function ( array $cells ) use ( $out ) {
			fputcsv( $out, array_map( fn( $v ) => is_string( $v ) && preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v, $cells ), ',', '"', '' );
		};

		if ( 'cleaners' === $kind ) {
			$row( [ 'Cleaner', 'Type', 'Email', 'Job date', 'Arrival', 'Booking', 'Service', 'Postcode', 'Role', 'Hours', 'Pay basis', 'Rate (GBP)', 'Pay (GBP)', 'Booking status', 'Note' ] );
			$totals = [];
			foreach ( self::pay_lines( $r ) as $l ) {
				$row( [ $l['cleaner'], $l['type'], $l['email'], $l['date'], $l['slot'], $l['booking'], $l['service'], $l['postcode'], $l['role'], $l['hours'], $l['basis'], $pounds( $l['rate'] ), $pounds( $l['pay_pence'] ), $l['status'], $l['note'] ] );
				$t                       = $totals[ $l['cleaner'] ] ?? [ 0, 0.0, 0 ];
				$totals[ $l['cleaner'] ] = [ $t[0] + 1, $t[1] + $l['hours'], $t[2] + $l['pay_pence'] ];
			}
			$row( [] );
			$row( [ 'Totals', '', '', '', '', '', '', '', 'Jobs', 'Hours', '', '', 'Pay (GBP)' ] );
			foreach ( $totals as $name => [ $jobs, $hours, $pay ] ) {
				$row( [ $name, '', '', '', '', '', '', '', $jobs, $hours, '', '', $pounds( $pay ) ] );
			}
		} else {
			$row( [ 'Booking', 'Job date', 'Arrival', 'Service', 'Customer', 'Postcode', 'Status', 'Payment', 'Total (GBP)', 'Paid (GBP)', 'Refunded (GBP)', 'Net (GBP)', 'Re-clean of', 'Test' ] );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE job_date BETWEEN %s AND %s' . ( $r['test'] ? '' : ' AND is_test = 0' ) . ' ORDER BY job_date ASC, slot ASC', $r['from'], $r['to'] ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( array_map( [ Db::class, 'decode' ], $rows ) as $b ) {
				$row( [ $b['ref'], $b['job_date'], Slots::label( (string) $b['slot'], false ), $b['service_name'], $b['customer_name'], $b['postcode'], Status::label( 'booking', $b['status'] ), Status::label( 'payment', $b['payment_status'] ), $pounds( $b['total_pence'] ), $pounds( $b['paid_pence'] ), $pounds( $b['refunded_pence'] ), $pounds( $b['paid_pence'] - $b['refunded_pence'] ), $b['meta']['reclean_of_ref'] ?? '', $b['is_test'] ? 'yes' : '' ] );
			}
		}
		fclose( $out );
		exit;
	}
}

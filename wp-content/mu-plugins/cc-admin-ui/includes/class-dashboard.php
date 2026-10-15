<?php
namespace CC\AdminUI;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard home: drops the stock WordPress widgets and adds a "Today" overview on top.
 * Booking figures are read-only queries against the cc-bookings tables and only appear when that
 * plugin is loaded, its tables exist and the user may manage bookings.
 */
final class Dashboard {
	const ACTIVE = [ 'confirmed', 'dispatching', 'assigned', 'completed' ];
	const OPEN   = [ 'confirmed', 'dispatching' ];

	public static function init(): void {
		add_action( 'wp_dashboard_setup', [ self::class, 'widgets' ], 999 );
		add_action( 'admin_notices', [ self::class, 'render' ], 1 );
	}

	public static function showing(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'dashboard' === $screen->id && 'full' === Plugin::mode() && apply_filters( 'cc_admin_ui_today', true );
	}

	public static function widgets(): void {
		if ( ! Plugin::active() ) {
			return;
		}
		remove_action( 'welcome_panel', 'wp_welcome_panel' );

		$remove = [ 'dashboard_primary', 'dashboard_secondary', 'dashboard_quick_press', 'dashboard_recent_drafts', 'dashboard_activity', 'dashboard_incoming_links', 'dashboard_plugins', 'bricks_dashboard_widget' ];
		if ( ! current_user_can( 'manage_options' ) ) {
			$remove[] = 'dashboard_site_health';
		}
		foreach ( (array) apply_filters( 'cc_admin_ui_removed_dashboard_widgets', $remove ) as $id ) {
			foreach ( [ 'normal', 'side', 'column3', 'column4' ] as $context ) {
				remove_meta_box( $id, 'dashboard', $context );
			}
		}
	}

	public static function render(): void {
		if ( ! self::showing() ) {
			return;
		}
		$user  = wp_get_current_user();
		$name  = $user->first_name ?: $user->display_name;
		$hour  = (int) wp_date( 'G' );
		$greet = $hour < 12 ? 'Good morning' : ( $hour < 18 ? 'Good afternoon' : 'Good evening' );
		$data  = self::bookings_available() ? self::figures() : null;
		?>
		<section class="cc-ui-today" aria-labelledby="cc-ui-today-title">
			<div class="cc-ui-today__head">
				<div class="cc-ui-today__intro">
					<p class="cc-ui-today__date"><?php echo esc_html( wp_date( 'l j F' ) ); ?></p>
					<h1 id="cc-ui-today-title" class="cc-ui-today__title"><?php echo esc_html( $greet . ', ' . $name ); ?></h1>
					<p class="cc-ui-today__lede">
						<?php echo esc_html( $data ? 'Here’s the day at a glance for ' . Plugin::brand() . '.' : 'Welcome back to ' . Plugin::brand() . '.' ); ?>
					</p>
				</div>
				<nav class="cc-ui-today__actions" aria-label="Quick actions">
					<?php foreach ( self::actions( (bool) $data ) as $i => [ $label, $url ] ) : ?>
						<a class="button <?php echo 0 === $i ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
			</div>
			<?php if ( $data ) : ?>
				<ul class="cc-ui-kpis">
					<?php foreach ( $data['kpis'] as $kpi ) : ?>
						<li class="cc-ui-kpi cc-ui-kpi--<?php echo esc_attr( $kpi['tone'] ); ?>">
							<a href="<?php echo esc_url( $kpi['url'] ); ?>">
								<span class="cc-ui-kpi__label"><?php echo esc_html( $kpi['label'] ); ?></span>
								<strong class="cc-ui-kpi__value"><?php echo esc_html( $kpi['value'] ); ?></strong>
								<span class="cc-ui-kpi__meta"><?php echo esc_html( $kpi['meta'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php self::agenda( $data['agenda'] ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/** @return array<int, array{0: string, 1: string}> */
	private static function actions( bool $bookings ): array {
		$actions = [];
		if ( $bookings ) {
			$actions[] = [ 'Open bookings', self::page_url( 'cc-bookings-bookings' ) ?: self::page_url( 'cc-bookings' ) ];
			$actions[] = [ 'Quotes', self::page_url( 'cc-bookings-quotes' ) ];
			$actions[] = [ 'Dispatch', self::page_url( 'cc-bookings-dispatch' ) ];
			$actions[] = [ 'Reports', self::page_url( 'cc-bookings-reports' ) ];
		}
		if ( current_user_can( 'edit_pages' ) ) {
			$actions[] = [ 'Pages', admin_url( 'edit.php?post_type=page' ) ];
		}
		$actions[] = [ 'View site', home_url( '/' ) ];
		return array_values( array_filter( $actions, fn( $a ) => '' !== $a[1] ) );
	}

	/** Admin URL for a registered Bookings screen, or '' if that screen does not exist (older/newer plugin). */
	private static function page_url( string $slug, array $args = [] ): string {
		if ( '' === menu_page_url( $slug, false ) ) {
			return '';
		}
		return add_query_arg( array_merge( [ 'page' => $slug ], $args ), admin_url( 'admin.php' ) );
	}

	private static function bookings_available(): bool {
		if ( ! class_exists( '\CC\Bookings\Schema' ) ) {
			return false;
		}
		if ( ! current_user_can( 'manage_cc_bookings' ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		global $wpdb;
		foreach ( [ 'bookings', 'quotes', 'payments' ] as $name ) {
			$table = self::table( $name );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}

	private static function table( string $name ): string {
		global $wpdb;
		return method_exists( '\CC\Bookings\Schema', 'table' ) ? \CC\Bookings\Schema::table( $name ) : $wpdb->prefix . 'cc_' . $name;
	}

	private static function figures(): array {
		global $wpdb;
		$bookings = self::table( 'bookings' );
		$quotes   = self::table( 'quotes' );
		$payments = self::table( 'payments' );
		$now      = new \DateTimeImmutable( 'now', wp_timezone() );
		$today    = $now->format( 'Y-m-d' );
		$tomorrow = $now->modify( '+1 day' )->format( 'Y-m-d' );
		$active   = "'" . implode( "','", self::ACTIVE ) . "'";
		$open     = "'" . implode( "','", self::OPEN ) . "'";

		// phpcs:disable WordPress.DB.PreparedSQL -- table names and status lists are fixed strings.
		$day = fn( string $date ) => $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total, COALESCE(SUM(status IN ({$open})), 0) AS open, COALESCE(SUM(is_test), 0) AS tests FROM {$bookings} WHERE job_date = %s AND status IN ({$active})",
			$date
		), ARRAY_A ) ?: [ 'total' => 0, 'open' => 0, 'tests' => 0 ];
		$on_today    = $day( $today );
		$on_tomorrow = $day( $tomorrow );

		$unassigned = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS total, MIN(job_date) AS next_date FROM {$bookings} WHERE status IN ({$open}) AND (job_date >= %s OR job_date IS NULL)",
			$today
		), ARRAY_A ) ?: [ 'total' => 0, 'next_date' => '' ];

		$drafts = $wpdb->get_row(
			"SELECT COALESCE(SUM(is_bespoke = 1), 0) AS bespoke, COUNT(*) AS total FROM {$quotes} WHERE status = 'draft' AND booking_id = 0",
			ARRAY_A
		) ?: [ 'bespoke' => 0, 'total' => 0 ];

		$sow       = (int) get_option( 'start_of_week', 1 );
		$back      = ( (int) $now->format( 'w' ) - $sow + 7 ) % 7;
		$week_from = $now->setTime( 0, 0 )->modify( "-{$back} days" );
		$paid      = $wpdb->get_row( $wpdb->prepare(
			"SELECT COALESCE(SUM(CASE WHEN p.type = 'refund' THEN -p.amount_pence ELSE p.amount_pence END), 0) AS net, COALESCE(SUM(p.type <> 'refund'), 0) AS n
			FROM {$payments} p LEFT JOIN {$bookings} b ON b.id = p.booking_id
			WHERE p.status = 'succeeded' AND p.created_at >= %s AND p.provider <> 'simulated' AND (b.id IS NULL OR b.is_test = 0)",
			$week_from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' )
		), ARRAY_A ) ?: [ 'net' => 0, 'n' => 0 ];

		$agenda = array_map(
			fn( $row ) => method_exists( '\CC\Bookings\Db', 'decode' ) ? \CC\Bookings\Db::decode( $row ) : $row,
			$wpdb->get_results( $wpdb->prepare(
				"SELECT id, ref, status, job_date, slot, service_name, customer_name, postcode, is_test FROM {$bookings} WHERE job_date IN (%s, %s) AND status IN ({$active}) ORDER BY job_date ASC, slot ASC, id ASC LIMIT 8",
				$today,
				$tomorrow
			), ARRAY_A ) ?: []
		);
		// phpcs:enable

		$bookings_url = self::page_url( 'cc-bookings-bookings' ) ?: self::page_url( 'cc-bookings' );
		$net          = (int) ( $paid['net'] ?? 0 );
		$next         = $unassigned['next_date'] ?? '';

		return [
			'kpis'   => [
				[
					'label' => 'Jobs today',
					'value' => (string) (int) $on_today['total'],
					'meta'  => self::day_meta( $on_today ),
					'url'   => $bookings_url,
					'tone'  => 'neutral',
				],
				[
					'label' => 'Jobs tomorrow',
					'value' => (string) (int) $on_tomorrow['total'],
					'meta'  => self::day_meta( $on_tomorrow ),
					'url'   => $bookings_url,
					'tone'  => 'neutral',
				],
				[
					'label' => 'Need a cleaner',
					'value' => (string) (int) $unassigned['total'],
					'meta'  => (int) $unassigned['total'] ? ( $next ? 'Next on ' . wp_date( 'D j M', strtotime( $next . ' 12:00' ) ) : 'No date set yet' ) : 'Every upcoming job is covered',
					'url'   => self::page_url( 'cc-bookings-dispatch' ) ?: add_query_arg( 'status', 'confirmed', $bookings_url ),
					'tone'  => (int) $unassigned['total'] ? 'warn' : 'good',
				],
				[
					'label' => 'Bespoke quotes waiting',
					'value' => (string) (int) $drafts['bespoke'],
					'meta'  => self::quote_meta( (int) $drafts['bespoke'], (int) $drafts['total'] ),
					'url'   => self::page_url( 'cc-bookings-quotes', [ 'status' => 'draft' ] ) ?: $bookings_url,
					'tone'  => (int) $drafts['bespoke'] ? 'warn' : 'good',
				],
				[
					'label' => 'Payments this week',
					'value' => self::money( $net ),
					'meta'  => sprintf( '%d %s since %s, net of refunds', (int) $paid['n'], 1 === (int) $paid['n'] ? 'payment' : 'payments', wp_date( 'D j M', $week_from->getTimestamp() ) ),
					'url'   => self::page_url( 'cc-bookings-reports' ) ?: $bookings_url,
					'tone'  => 'neutral',
				],
			],
			'agenda' => [ 'rows' => $agenda, 'today' => $today ],
		];
	}

	private static function day_meta( array $day ): string {
		$total = (int) $day['total'];
		if ( ! $total ) {
			return 'Nothing booked';
		}
		$parts = [ (int) $day['open'] ? sprintf( '%d need a cleaner', (int) $day['open'] ) : 'All have a cleaner' ];
		if ( (int) $day['tests'] ) {
			$parts[] = sprintf( '%d test', (int) $day['tests'] );
		}
		return implode( ' · ', $parts );
	}

	private static function quote_meta( int $bespoke, int $total ): string {
		$other = $total - $bespoke;
		if ( ! $total ) {
			return 'Nothing to price';
		}
		return $other ? sprintf( '%d other %s to price', $other, 1 === $other ? 'quote' : 'quotes' ) : 'Awaiting your price';
	}

	private static function money( int $pence ): string {
		if ( method_exists( '\CC\Bookings\Money', 'format' ) ) {
			return \CC\Bookings\Money::format( $pence );
		}
		return ( $pence < 0 ? '−' : '' ) . '£' . number_format( abs( $pence ) / 100, 2 );
	}

	private static function agenda( array $agenda ): void {
		$rows = $agenda['rows'];
		?>
		<div class="cc-ui-agenda">
			<h2 class="cc-ui-agenda__title">Up next <span>Today and tomorrow</span></h2>
			<?php if ( ! $rows ) : ?>
				<p class="cc-ui-agenda__empty">No jobs booked for today or tomorrow.</p>
			<?php else : ?>
				<ul class="cc-ui-agenda__list">
					<?php
					foreach ( $rows as $b ) :
						$status = (string) $b['status'];
						$label  = class_exists( '\CC\Bookings\Status' ) ? \CC\Bookings\Status::label( 'booking', $status ) : ucfirst( $status );
						$slot   = class_exists( '\CC\Bookings\Slots' ) ? \CC\Bookings\Slots::label( (string) $b['slot'] ) : (string) $b['slot'];
						$url    = class_exists( '\CC\Bookings\Bookings' ) ? \CC\Bookings\Bookings::admin_url( (int) $b['id'] ) : admin_url( 'admin.php?page=cc-bookings-bookings&id=' . (int) $b['id'] );
						?>
						<li class="cc-ui-agenda__item">
							<span class="cc-ui-agenda__when">
								<strong><?php echo esc_html( $b['job_date'] === $agenda['today'] ? 'Today' : 'Tomorrow' ); ?></strong>
								<?php echo esc_html( $slot ); ?>
							</span>
							<span class="cc-ui-agenda__what">
								<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $b['ref'] ); ?></a>
								<?php echo esc_html( $b['service_name'] ); ?>
								<?php if ( ! empty( $b['is_test'] ) ) : ?>
									<span class="cc-ui-pill cc-ui-pill--test">Test</span>
								<?php endif; ?>
							</span>
							<span class="cc-ui-agenda__who"><?php echo esc_html( trim( $b['customer_name'] . ' · ' . $b['postcode'], ' ·' ) ); ?></span>
							<span class="cc-ui-pill cc-ui-pill--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $label ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}
}

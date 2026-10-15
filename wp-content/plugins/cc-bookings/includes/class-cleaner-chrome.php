<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Header and footer for the cleaner area (/cleaners/…, /job/… links) instead of the marketing site's
 * Bricks templates: logo + "Cleaner portal", the portal tabs when a cleaner is logged in (a bottom tab bar
 * on phones), and a slim help footer. The Bricks header/footer are switched off on these pages only.
 */
final class Cleaner_Chrome {
	/** Plugin templates that belong to the cleaner area. */
	const TEMPLATES = [ 'cleaner-login', 'cleaner-setup', 'cleaner-portal', 'cleaner-message', 'cleaner-terms', 'cleaner-jobs', 'job', 'job-invalid' ];

	const TABS = [
		'home'         => 'Jobs',
		'availability' => 'Availability',
		'earnings'     => 'Earnings',
		'profile'      => 'Profile',
	];

	/** Portal views shown under another tab. */
	const TAB_OF = [ 'documents' => 'profile', 'bank' => 'profile' ];

	const ICONS = [
		'home'         => '<rect x="4" y="4" width="16" height="17" rx="2"/><path d="M9 4V3h6v1M8 10h8M8 14h8M8 18h5"/>',
		'availability' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M8 14h2M14 14h2M8 17h2"/>',
		'earnings'     => '<path d="M4 7h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a1 1 0 0 1-1-1V7Z"/><path d="M4 7V6a2 2 0 0 1 2-2h10v3M16 13h1.5"/>',
		'profile'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4 4-6 8-6s7.2 2 8 6"/>',
	];

	/** @var array{cleaner: ?array, tab: string}|null */
	private static ?array $page = null;

	public static function init(): void {
		add_filter( 'bricks/active_templates', [ self::class, 'bricks_templates' ], 20 );
	}

	/** Bricks picks its templates on `wp`, before the plugin routes run, so match the cleaner routes here. */
	public static function bricks_templates( $templates ) {
		if ( is_array( $templates ) && ( self::$page || self::is_cleaner_request() ) ) {
			$templates['header'] = 0;
			$templates['footer'] = 0;
		}
		return $templates;
	}

	private static function is_cleaner_request(): bool {
		return '' !== (string) get_query_var( 'cc_portal' ) || (int) get_query_var( 'cc_job' ) || (int) get_query_var( 'cc_jobs_for' );
	}

	/** Called by Frontend::render for cleaner templates, before the theme header is output. */
	public static function start( string $template, array $vars ): void {
		$cleaner = 'cleaner-setup' === $template ? null : Cleaner_Accounts::current();
		if ( $cleaner && ( 'setup' === Cleaner_Accounts::status( $cleaner ) || ! Cleaner_Auth::can_log_in( wp_get_current_user() ) ) ) {
			$cleaner = null;
		}
		$tab = '';
		if ( 'cleaner-portal' === $template ) {
			$view = (string) ( $vars['view'] ?? 'home' );
			$tab  = self::TAB_OF[ $view ] ?? $view;
		} elseif ( in_array( $template, [ 'job', 'cleaner-jobs' ], true ) ) {
			$tab = 'home';
		}
		self::$page = [ 'cleaner' => $cleaner, 'tab' => $tab ];

		if ( class_exists( '\Bricks\Database' ) && is_array( \Bricks\Database::$active_templates ?? null ) ) {
			\Bricks\Database::$active_templates['header'] = 0;
			\Bricks\Database::$active_templates['footer'] = 0;
		}
		Cleaner_Portal::enqueue_style();
		add_action( 'bricks_meta_tags', [ self::class, 'viewport' ] );
		add_action( 'bricks_after_header', [ self::class, 'header' ] );
		add_action( 'bricks_before_footer', [ self::class, 'footer' ] );
		add_filter( 'body_class', function ( $classes ) use ( $cleaner ) {
			$classes[] = 'cc-cleaner-area';
			if ( $cleaner ) {
				$classes[] = 'cc-cleaner-area--tabs';
			}
			return $classes;
		} );
	}

	/** Lets the bottom tab bar sit clear of the iPhone home indicator (env(safe-area-inset-*)). */
	public static function viewport(): void {
		echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
	}

	private static function brand(): string {
		return function_exists( 'cc_brand_name' ) ? cc_brand_name() : Settings::brand();
	}

	private static function logo(): string {
		$logo = function_exists( 'cc_option' ) ? cc_option( 'logo' ) : null;
		if ( is_array( $logo ) && ! empty( $logo['url'] ) ) {
			return sprintf( '<img class="cc-cl-brand__logo" src="%s" alt="%s" width="%d" height="%d">', esc_url( $logo['url'] ), esc_attr( self::brand() ), (int) ( $logo['width'] ?? 180 ), (int) ( $logo['height'] ?? 48 ) );
		}
		return sprintf( '<span class="cc-cl-brand__mark" aria-hidden="true">✦</span><span class="cc-cl-brand__name">%s</span>', esc_html( self::brand() ) );
	}

	private static function icon( string $tab ): string {
		return '<svg class="cc-cl-tabs__icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . self::ICONS[ $tab ] . '</svg>';
	}

	private static function skip_link(): void {
		$bricks = class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_setting' );
		if ( ! $bricks || \Bricks\Database::get_setting( 'disableSkipLinks', false ) ) {
			echo '<a class="skip-link cc-cl-skip" href="#brx-content">Skip to main content</a>';
		}
	}

	public static function header(): void {
		if ( ! self::$page ) {
			return;
		}
		$cleaner = self::$page['cleaner'];
		self::skip_link();
		?>
		<header class="cc-cl-header">
			<div class="cc-container cc-cl-header__bar">
				<a class="cc-cl-brand" href="<?php echo esc_url( Cleaner_Portal::url() ); ?>">
					<?php echo self::logo(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in logo(). ?>
					<span class="cc-cl-brand__label">Cleaner portal</span>
				</a>
				<?php if ( $cleaner ) : ?>
					<nav class="cc-cl-tabs" aria-label="Cleaner portal">
						<ul class="cc-cl-tabs__list">
							<?php foreach ( self::TABS as $key => $label ) : ?>
								<li>
									<a class="cc-cl-tabs__link" href="<?php echo esc_url( Cleaner_Portal::url( $key ) ); ?>"<?php echo self::$page['tab'] === $key ? ' aria-current="page"' : ''; ?>>
										<?php echo self::icon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?>
										<span><?php echo esc_html( $label ); ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</nav>
					<div class="cc-cl-user">
						<span class="cc-cl-user__name"><?php echo esc_html( Notify::first_name( (string) $cleaner['name'] ) ); ?></span>
						<form class="cc-cl-user__form" method="post" action="<?php echo esc_url( Cleaner_Portal::url( 'logout' ) ); ?>">
							<?php Cleaner_Portal::nonce_field( 'cc_portal_' . (int) $cleaner['id'] ); ?>
							<button type="submit" class="cc-cl-user__logout">Log out</button>
						</form>
					</div>
				<?php endif; ?>
			</div>
		</header>
		<?php
	}

	public static function footer(): void {
		if ( ! self::$page ) {
			return;
		}
		$phone = function_exists( 'cc_phone_display' ) ? cc_phone_display() : '';
		$tel   = function_exists( 'cc_phone_href' ) ? cc_phone_href() : '';
		$email = function_exists( 'cc_option' ) ? sanitize_email( (string) cc_option( 'email' ) ) : '';
		$help  = [];
		if ( $phone && $tel ) {
			$help[] = sprintf( 'call the office on <a href="%s">%s</a>', esc_attr( $tel ), esc_html( $phone ) );
		}
		if ( $email ) {
			$help[] = sprintf( 'email <a href="mailto:%1$s">%1$s</a>', esc_html( $email ) );
		}
		?>
		<footer class="cc-cl-footer">
			<div class="cc-container cc-cl-footer__inner">
				<?php if ( $help ) : ?>
					<p class="cc-cl-footer__help">Need help? <?php echo ucfirst( implode( ' or ', $help ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>.</p>
				<?php endif; ?>
				<ul class="cc-cl-footer__links">
					<li><a href="<?php echo esc_url( Cleaner_Portal::url( 'terms' ) ); ?>">Cleaner terms</a></li>
					<li><a href="<?php echo esc_url( get_privacy_policy_url() ?: home_url( '/privacy-policy/' ) ); ?>">Privacy</a></li>
				</ul>
				<p class="cc-cl-footer__copy">&copy; <?php echo esc_html( wp_date( 'Y' ) . ' ' . self::brand() ); ?></p>
			</div>
		</footer>
		<?php
	}
}

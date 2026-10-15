<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Header and footer for the client area (/account/…) instead of the marketing site's Bricks templates:
 * logo + "My account", the account tabs when a client is logged in (a bottom tab bar on phones), and a slim
 * footer. Logged-out pages and the first-password page show the logo only. Shares the cleaner area's layout
 * classes (cc-cl-*); client.css adds the client-only bits.
 */
final class Client_Chrome {
	const TEMPLATES = [ 'client-login', 'client-setup', 'client-portal', 'client-quote', 'client-booking', 'client-message' ];

	const ICONS = [
		'bookings' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M8 15l2.5 2.5L16 13"/>',
		'quotes'   => '<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4M9 12h7M9 16h5"/>',
		'plans'    => '<path d="M4 12a8 8 0 0 1 13.7-5.7L20 8.5M20 4v4.5h-4.5M20 12a8 8 0 0 1-13.7 5.7L4 15.5M4 20v-4.5h4.5"/>',
		'profile'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4 4-6 8-6s7.2 2 8 6"/>',
	];

	/** @var array{client: ?array, tab: string}|null */
	private static ?array $page = null;

	public static function init(): void {
		add_filter( 'bricks/active_templates', [ self::class, 'bricks_templates' ], 20 );
	}

	/** Bricks picks its templates on `wp`, before the plugin routes run, so match the account routes here. */
	public static function bricks_templates( $templates ) {
		if ( is_array( $templates ) && ( self::$page || '' !== (string) get_query_var( 'cc_account' ) ) ) {
			$templates['header'] = 0;
			$templates['footer'] = 0;
		}
		return $templates;
	}

	/** Called by Frontend::render for client templates, before the theme header is output. */
	public static function start( string $template, array $vars ): void {
		$client = in_array( $template, [ 'client-login', 'client-setup' ], true ) ? null : Client_Accounts::current();
		if ( $client && ( ! $client['password_set'] || ! Client_Auth::can_log_in( wp_get_current_user() ) ) ) {
			$client = null;
		}
		$tab = '';
		if ( 'client-portal' === $template ) {
			$tab = 'plan' === ( $vars['view'] ?? '' ) ? 'plans' : (string) ( $vars['view'] ?? 'bookings' );
		} elseif ( 'client-quote' === $template ) {
			$tab = 'quotes';
		} elseif ( 'client-booking' === $template ) {
			$tab = 'bookings';
		}
		self::$page = [ 'client' => $client, 'tab' => $tab ];

		if ( class_exists( '\Bricks\Database' ) && is_array( \Bricks\Database::$active_templates ?? null ) ) {
			\Bricks\Database::$active_templates['header'] = 0;
			\Bricks\Database::$active_templates['footer'] = 0;
		}
		Client_Portal::assets();
		add_action( 'bricks_meta_tags', [ Cleaner_Chrome::class, 'viewport' ] );
		add_action( 'bricks_after_header', [ self::class, 'header' ] );
		add_action( 'bricks_before_footer', [ self::class, 'footer' ] );
		add_filter( 'body_class', function ( $classes ) use ( $client ) {
			$classes[] = 'cc-cleaner-area';
			$classes[] = 'cc-client-area';
			if ( $client ) {
				$classes[] = 'cc-cleaner-area--tabs';
			}
			return $classes;
		} );
	}

	/** @return array<string, string> */
	public static function tabs( array $client ): array {
		$tabs = Client_Portal::VIEWS;
		if ( ! Client_Accounts::has_plans( $client ) ) {
			unset( $tabs['plans'] );
		}
		return $tabs;
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

	public static function header(): void {
		if ( ! self::$page ) {
			return;
		}
		$client = self::$page['client'];
		$bricks = class_exists( '\Bricks\Database' ) && method_exists( '\Bricks\Database', 'get_setting' );
		if ( ! $bricks || \Bricks\Database::get_setting( 'disableSkipLinks', false ) ) {
			echo '<a class="skip-link cc-cl-skip" href="#brx-content">Skip to main content</a>';
		}
		?>
		<header class="cc-cl-header">
			<div class="cc-container cc-cl-header__bar">
				<a class="cc-cl-brand" href="<?php echo esc_url( $client ? Client_Portal::url() : home_url( '/' ) ); ?>">
					<?php echo self::logo(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in logo(). ?>
					<?php if ( $client ) : ?>
						<span class="cc-cl-brand__label">My account</span>
					<?php endif; ?>
				</a>
				<?php if ( $client ) : ?>
					<?php $tabs = self::tabs( $client ); ?>
					<nav class="cc-cl-tabs" aria-label="My account" style="--cc-tabs: <?php echo (int) count( $tabs ); ?>">
						<ul class="cc-cl-tabs__list">
							<?php foreach ( $tabs as $key => $label ) : ?>
								<li>
									<a class="cc-cl-tabs__link" href="<?php echo esc_url( Client_Portal::url( $key ) ); ?>"<?php echo self::$page['tab'] === $key ? ' aria-current="page"' : ''; ?>>
										<?php echo self::icon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?>
										<span><?php echo esc_html( 'plans' === $key ? 'Plans' : $label ); ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</nav>
					<div class="cc-cl-user">
						<span class="cc-cl-user__name"><?php echo esc_html( Client_Accounts::first_name( $client ) ); ?></span>
						<form class="cc-cl-user__form" method="post" action="<?php echo esc_url( Client_Portal::url( 'logout' ) ); ?>">
							<?php Client_Portal::nonce_field( Client_Portal::nonce_action( $client ) ); ?>
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
			$help[] = sprintf( 'call us on <a href="%s">%s</a>', esc_attr( $tel ), esc_html( $phone ) );
		}
		if ( $email ) {
			$help[] = sprintf( 'email <a href="mailto:%1$s">%1$s</a>', esc_html( $email ) );
		}
		?>
		<footer class="cc-cl-footer">
			<div class="cc-container cc-cl-footer__inner">
				<?php if ( $help ) : ?>
					<p class="cc-cl-footer__help">Questions? <?php echo ucfirst( implode( ' or ', $help ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>.</p>
				<?php endif; ?>
				<ul class="cc-cl-footer__links">
					<li><a href="<?php echo esc_url( Settings::terms_url() ); ?>">Terms</a></li>
					<li><a href="<?php echo esc_url( get_privacy_policy_url() ?: home_url( '/privacy-policy/' ) ); ?>">Privacy</a></li>
				</ul>
				<p class="cc-cl-footer__copy">&copy; <?php echo esc_html( wp_date( 'Y' ) . ' ' . self::brand() ); ?></p>
			</div>
		</footer>
		<?php
	}
}

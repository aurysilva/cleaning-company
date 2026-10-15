<?php
namespace CC\AdminUI;

defined( 'ABSPATH' ) || exit;

/**
 * Branded wp-login.php: brand panel on the left (top band on small screens), sign-in card on the right.
 */
final class Login {
	public static function init(): void {
		add_action( 'login_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_filter( 'login_body_class', [ self::class, 'body_class' ] );
		add_filter( 'login_headerurl', [ self::class, 'header_url' ] );
		add_filter( 'login_headertext', [ self::class, 'header_text' ] );
		add_filter( 'login_title', [ self::class, 'title' ], 10, 2 );
		add_action( 'login_header', [ self::class, 'aside' ] );
	}

	private static function enabled(): bool {
		return ! ( defined( 'CC_ADMIN_UI_DISABLE' ) && CC_ADMIN_UI_DISABLE ) && apply_filters( 'cc_admin_ui_login', true );
	}

	public static function enqueue(): void {
		if ( ! self::enabled() ) {
			return;
		}
		wp_enqueue_style( 'cc-admin-ui-login', CC_ADMIN_UI_URL . 'assets/css/login.css', [ 'login' ], Plugin::version( 'assets/css/login.css' ) );
		$logo = Plugin::logo_url();
		if ( $logo ) {
			wp_add_inline_style( 'cc-admin-ui-login', sprintf( 'body.cc-ui-login .cc-ui-login-logo{background-image:url("%s")}', esc_url_raw( $logo ) ) );
		}
	}

	public static function body_class( array $classes ): array {
		if ( self::enabled() ) {
			$classes[] = 'cc-ui-login';
			$classes[] = Plugin::logo_url() ? 'cc-ui-login--has-logo' : 'cc-ui-login--text-logo';
		}
		return $classes;
	}

	public static function header_url( $url ) {
		return self::enabled() ? home_url( '/' ) : $url;
	}

	public static function header_text( $text ) {
		return self::enabled() ? Plugin::brand() : $text;
	}

	public static function title( $title, $action_title ) {
		return self::enabled() ? $action_title . ' ‹ ' . Plugin::brand() : $title;
	}

	public static function aside(): void {
		if ( ! self::enabled() ) {
			return;
		}
		?>
		<aside class="cc-ui-login-aside" aria-label="<?php echo esc_attr( Plugin::brand() ); ?>">
			<a class="cc-ui-login-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<span class="cc-ui-login-logo" aria-hidden="true"></span>
				<span class="cc-ui-login-name"><?php echo esc_html( Plugin::brand() ); ?></span>
			</a>
			<div class="cc-ui-login-pitch">
				<p class="cc-ui-login-kicker">Team workspace</p>
				<p class="cc-ui-login-headline">Bookings, quotes and your website in one place.</p>
				<ul>
					<li>See today’s jobs and who is on them</li>
					<li>Price bespoke quotes and send them in minutes</li>
					<li>Keep services, areas and guides up to date</li>
				</ul>
			</div>
			<p class="cc-ui-login-foot">&copy; <?php echo esc_html( wp_date( 'Y' ) . ' ' . Plugin::brand() ); ?></p>
		</aside>
		<?php
	}
}

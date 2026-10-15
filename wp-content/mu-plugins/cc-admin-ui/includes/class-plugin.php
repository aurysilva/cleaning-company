<?php
namespace CC\AdminUI;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the skin applies to this request, loads the stylesheets and owns the per-user
 * "Use classic WordPress look" escape hatch (profile checkbox + toolbar link).
 *
 * Two stylesheet layers:
 *   shell  toolbar, sidebar, footer and brand tokens. Loaded on every skinned screen.
 *   ui     content components (buttons, forms, tables, notices, cards, tabs, dashboard).
 *          Skipped on the block editor and on plugin screens that ship their own design system.
 */
final class Plugin {
	const META   = 'cc_admin_ui_classic';
	const TOGGLE = 'cc_admin_ui_toggle';

	/** @var string '' (not decided yet), 'off', 'shell' or 'full' */
	private static string $mode = '';

	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ], 99 );
		add_filter( 'admin_body_class', [ self::class, 'body_class' ] );
		add_filter( 'style_loader_src', [ self::class, 'drop_colour_scheme' ], 20, 2 );

		add_action( 'personal_options', [ self::class, 'profile_field' ] );
		add_action( 'personal_options_update', [ self::class, 'profile_save' ] );
		add_action( 'edit_user_profile_update', [ self::class, 'profile_save' ] );
		add_action( 'admin_post_' . self::TOGGLE, [ self::class, 'toggle' ] );

		Chrome::init();
		Dashboard::init();
		Login::init();
	}

	public static function brand(): string {
		return function_exists( 'cc_brand_name' ) ? (string) cc_brand_name() : get_bloginfo( 'name' );
	}

	/** Brand logo URL from the theme's Business Settings, if one has been uploaded. */
	public static function logo_url(): string {
		$logo = function_exists( 'cc_option' ) ? cc_option( 'logo' ) : null;
		return is_array( $logo ) && ! empty( $logo['url'] ) ? (string) $logo['url'] : '';
	}

	public static function classic( int $user_id = 0 ): bool {
		return (bool) get_user_meta( $user_id ?: get_current_user_id(), self::META, true );
	}

	/** Whether the skin applies to the current admin request at all. */
	public static function active(): bool {
		global $pagenow;
		if ( defined( 'CC_ADMIN_UI_DISABLE' ) && CC_ADMIN_UI_DISABLE ) {
			return false;
		}
		if ( ! is_admin() || wp_doing_ajax() || ! is_user_logged_in() ) {
			return false;
		}
		// Never touch the Bricks builder or the Customizer (their previews are front-end iframes, but be explicit).
		if ( ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) || is_customize_preview() || 'customize.php' === $pagenow ) {
			return false;
		}
		if ( self::classic() ) {
			return false;
		}
		return (bool) apply_filters( 'cc_admin_ui_enabled', true, get_current_user_id() );
	}

	/** 'off', 'shell' or 'full' for the current screen. Settled on first call after the screen is known. */
	public static function mode(): string {
		if ( '' !== self::$mode ) {
			return self::$mode;
		}
		if ( ! self::active() ) {
			return self::$mode = 'off';
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return 'shell';
		}
		$shell_only = $screen->is_block_editor();
		foreach ( (array) apply_filters( 'cc_admin_ui_shell_only_screens', [ 'hostinger', 'litespeed' ] ) as $needle ) {
			if ( $needle && str_contains( (string) $screen->id, (string) $needle ) ) {
				$shell_only = true;
			}
		}
		return self::$mode = $shell_only ? 'shell' : 'full';
	}

	public static function enqueue(): void {
		$mode = self::mode();
		if ( 'off' === $mode ) {
			return;
		}
		wp_enqueue_style( 'cc-admin-ui-shell', CC_ADMIN_UI_URL . 'assets/css/admin-shell.css', [ 'wp-admin', 'admin-bar' ], self::version( 'assets/css/admin-shell.css' ) );
		if ( 'full' === $mode ) {
			// Depends on the cc-bookings stylesheet when present, so these overrides print after it.
			$deps = wp_style_is( 'cc-bookings-admin', 'enqueued' ) ? [ 'cc-admin-ui-shell', 'cc-bookings-admin' ] : [ 'cc-admin-ui-shell' ];
			wp_enqueue_style( 'cc-admin-ui', CC_ADMIN_UI_URL . 'assets/css/admin-ui.css', $deps, self::version( 'assets/css/admin-ui.css' ) );
			wp_enqueue_script( 'cc-admin-ui', CC_ADMIN_UI_URL . 'assets/js/admin.js', [], self::version( 'assets/js/admin.js' ), [ 'in_footer' => true, 'strategy' => 'defer' ] );
		}
	}

	public static function version( string $file ): string {
		$mtime = @filemtime( CC_ADMIN_UI_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return CC_ADMIN_UI_VERSION . ( $mtime ? '.' . $mtime : '' );
	}

	public static function body_class( string $classes ): string {
		$mode = self::mode();
		if ( 'off' === $mode ) {
			return $classes;
		}
		return $classes . ' cc-ui cc-ui-' . $mode . ( Dashboard::showing() ? ' cc-ui-has-today' : '' );
	}

	/** The skin replaces the admin colour scheme, so the scheme stylesheet is not printed while it is on. */
	public static function drop_colour_scheme( $src, $handle ) {
		return ( 'colors' === $handle && is_admin() && 'off' !== self::mode() ) ? false : $src;
	}

	/* ---------------------------------------------------------------------
	 * Escape hatch
	 * ------------------------------------------------------------------ */

	public static function profile_field( \WP_User $user ): void {
		?>
		<tr class="cc-ui-classic">
			<th scope="row">Admin look</th>
			<td>
				<input type="hidden" name="cc_admin_ui_classic_present" value="1">
				<label for="cc_admin_ui_classic">
					<input type="checkbox" name="cc_admin_ui_classic" id="cc_admin_ui_classic" value="1" <?php checked( self::classic( $user->ID ) ); ?>>
					Use classic WordPress look
				</label>
				<p class="description">Turns off the <?php echo esc_html( self::brand() ); ?> admin design, the Today dashboard and the menu changes for this user only. While the new look is on, the colour scheme above is not used.</p>
			</td>
		</tr>
		<?php
	}

	/** Core has already checked the update-user nonce before these profile actions fire. */
	public static function profile_save( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) || empty( $_POST['cc_admin_ui_classic_present'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! empty( $_POST['cc_admin_ui_classic'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			update_user_meta( $user_id, self::META, '1' );
		} else {
			delete_user_meta( $user_id, self::META );
		}
	}

	public static function toggle_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::TOGGLE ), self::TOGGLE );
	}

	/** Toolbar link: flips the current user between the new and the classic look. */
	public static function toggle(): void {
		check_admin_referer( self::TOGGLE );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_die( 'Please log in.', 403 );
		}
		if ( self::classic( $user_id ) ) {
			delete_user_meta( $user_id, self::META );
		} else {
			update_user_meta( $user_id, self::META, '1' );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}

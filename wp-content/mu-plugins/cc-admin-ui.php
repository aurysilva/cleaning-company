<?php
/**
 * Plugin Name: CC Admin UI
 * Description: App-style wp-admin skin, "Today" dashboard and branded login for the cleaning company site. Per-user opt-out under Profile → "Use classic WordPress look".
 * Version:     1.0.0
 * Author:      Cleaning Company
 *
 * Must-use plugin: lives outside wp-content/plugins and the theme, so WordPress, theme and plugin updates never overwrite it.
 * Emergency off switch for everyone: define( 'CC_ADMIN_UI_DISABLE', true ); in wp-config.php.
 */

defined( 'ABSPATH' ) || exit;

define( 'CC_ADMIN_UI_VERSION', '1.0.0' );
define( 'CC_ADMIN_UI_DIR', __DIR__ . '/cc-admin-ui/' );
define( 'CC_ADMIN_UI_URL', plugins_url( 'cc-admin-ui/', __FILE__ ) );

// Guarded so a request landing mid-deploy (folder being swapped) degrades to plain WordPress instead of a fatal error.
if ( is_readable( CC_ADMIN_UI_DIR . 'includes/class-plugin.php' ) ) {
	foreach ( [ 'plugin', 'chrome', 'dashboard', 'login' ] as $cc_admin_ui_file ) {
		require_once CC_ADMIN_UI_DIR . "includes/class-{$cc_admin_ui_file}.php";
	}
	unset( $cc_admin_ui_file );
	\CC\AdminUI\Plugin::boot();
}

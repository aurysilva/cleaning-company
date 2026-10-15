<?php
/**
 * Plugin Name: CC Bookings
 * Description: Instant quotes, private quote pages, Stripe Checkout and booking management. See docs/quote-to-booking-automation.md.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Cleaning Company
 * Text Domain: cc-bookings
 */

defined( 'ABSPATH' ) || exit;

define( 'CC_BOOKINGS_VERSION', '0.1.0' );
define( 'CC_BOOKINGS_FILE', __FILE__ );
define( 'CC_BOOKINGS_DIR', __DIR__ );
define( 'CC_BOOKINGS_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/lib/action-scheduler/action-scheduler.php';

/**
 * CC\Bookings\Foo_Bar => includes/class-foo-bar.php, CC\Bookings\Admin\Foo => includes/admin/class-foo.php
 */
spl_autoload_register( function ( $class ) {
	$prefix = 'CC\\Bookings\\';
	if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
		return;
	}
	$parts = explode( '\\', substr( $class, strlen( $prefix ) ) );
	$name  = array_pop( $parts );
	$dir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
	$file  = __DIR__ . '/includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
	if ( is_file( $file ) ) {
		require $file;
	}
} );

register_activation_hook( __FILE__, [ CC\Bookings\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ CC\Bookings\Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ CC\Bookings\Plugin::class, 'boot' ] );
add_action( 'cc_bookings_loaded', [ CC\Bookings\Aftercare::class, 'init' ] );
add_action( 'cc_bookings_loaded', [ CC\Bookings\Recurring::class, 'init' ] );

/**
 * The multi-step quote form, rendered by the theme's {cc_quote_form} tag.
 */
function cc_bookings_quote_form(): string {
	return CC\Bookings\Form::render();
}

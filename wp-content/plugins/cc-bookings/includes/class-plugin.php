<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	/** Capability for every Bookings admin screen and action. Granted to administrators on activation. */
	const CAP = 'manage_cc_bookings';

	public static function boot(): void {
		Schema::maybe_upgrade();
		Frontend::init();
		Rest::init();
		Notify::init();
		Quotes::init();
		Checkout::init();
		Bookings::init();
		Dispatch::init();

		if ( is_admin() ) {
			Admin\Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cc-bookings', Cli::class );
		}

		do_action( 'cc_bookings_loaded' );
	}

	public static function activate(): void {
		Schema::install();
		get_role( 'administrator' )?->add_cap( self::CAP );
		Files::protect();
		Frontend::add_rewrites();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public static function can_manage(): bool {
		return current_user_can( self::CAP ) || current_user_can( 'manage_options' );
	}
}

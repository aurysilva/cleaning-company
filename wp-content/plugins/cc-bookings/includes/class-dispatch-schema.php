<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 2 storage, versioned separately from the core schema (option cc_bookings_dispatch_schema).
 *   wp_cc_dispatch      one row per booking being dispatched; `filled < seats` is the first-to-accept lock.
 *   cleaners.areas      Area (region) post ids a cleaner covers, as well as postcode districts.
 *   job_offers.photos   completion photos uploaded from the job page.
 *   job_offers.note     the cleaner's reason when they can't make it.
 */
final class Dispatch_Schema {
	const VERSION = '1';
	const OPTION  = 'cc_bookings_dispatch_schema';

	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION ) !== self::VERSION && get_option( Schema::OPTION ) ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( 'CREATE TABLE ' . Schema::table( 'dispatch' ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
seats tinyint(3) unsigned NOT NULL DEFAULT 1,
filled tinyint(3) unsigned NOT NULL DEFAULT 0,
status varchar(20) NOT NULL DEFAULT 'idle',
round smallint(5) unsigned NOT NULL DEFAULT 0,
bonus_pence int(11) NOT NULL DEFAULT 0,
deadline datetime NULL,
escalated_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY booking_id (booking_id),
KEY status (status)
) " . $wpdb->get_charset_collate() . ';' );

		self::add_column( 'cleaners', 'areas', 'text NULL' );
		self::add_column( 'job_offers', 'photos', 'text NULL' );
		self::add_column( 'job_offers', 'note', "varchar(255) NOT NULL DEFAULT ''" );

		update_option( self::OPTION, self::VERSION, false );
		// New /job/ rewrite rules need a flush once.
		add_action( 'init', 'flush_rewrite_rules', 99 );
	}

	private static function add_column( string $table, string $column, string $definition ): void {
		global $wpdb;
		$name = Schema::table( $table );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$name} LIKE %s", $column ) ) ) {
			$wpdb->query( "ALTER TABLE {$name} ADD COLUMN {$column} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}
}

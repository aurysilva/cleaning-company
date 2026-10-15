<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Background jobs through the bundled Action Scheduler (Tools > Scheduled Actions, group "cc-bookings").
 * Falls back to WP-Cron single events if Action Scheduler is unavailable.
 */
final class Queue {
	const GROUP = 'cc-bookings';

	public static function at( int $timestamp, string $hook, array $args = [] ): void {
		$args = array_values( $args );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( max( time(), $timestamp ), $hook, $args, self::GROUP, true );
			return;
		}
		wp_schedule_single_event( max( time(), $timestamp ), $hook, $args );
	}

	public static function cancel( string $hook, array $args = [] ): void {
		$args = array_values( $args );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, $args, self::GROUP );
		}
		wp_clear_scheduled_hook( $hook, $args );
	}

	public static function next( string $hook, array $args = [] ): int {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$next = as_next_scheduled_action( $hook, array_values( $args ), self::GROUP );
			return is_int( $next ) ? $next : 0;
		}
		return (int) wp_next_scheduled( $hook, array_values( $args ) );
	}

	public static function driver(): string {
		return function_exists( 'as_schedule_single_action' ) ? 'Action Scheduler' : 'WP-Cron';
	}
}

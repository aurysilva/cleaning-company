<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Bookings;
use CC\Bookings\Db;
use CC\Bookings\Events;
use CC\Bookings\Quotes;
use CC\Bookings\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Everything logged by Events, including every email sent (event "email_sent"), filterable.
 */
final class Activity_Screen {
	const PAGE     = 'cc-bookings-activity';
	const PER_PAGE = 50;

	public static function render(): void {
		global $wpdb;
		$type  = sanitize_key( Admin::text( 'type' ) );
		$event = sanitize_key( Admin::text( 'event' ) );
		$paged = max( 1, Admin::int( 'paged' ) );

		$where = array_filter( [ 'object_type' => $type, 'event' => $event ] );
		$total = Db::count( 'events', $where );
		$rows  = Db::all( 'events', $where, 'id DESC', self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		Admin::header( 'Activity' );
		$types  = [ '' => 'Everything', 'quote' => 'Quotes', 'booking' => 'Bookings', 'cleaner' => 'Cleaners', 'system' => 'System' ];
		$events = $wpdb->get_col( 'SELECT DISTINCT event FROM ' . Schema::table( 'events' ) . ' ORDER BY event' ) ?: [];
		$opts   = '';
		foreach ( $types as $key => $label ) {
			$opts .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $type, $key, false ), esc_html( $label ) );
		}
		$eopts = '<option value="">Any event</option>';
		foreach ( $events as $key ) {
			$eopts .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $event, $key, false ), esc_html( str_replace( '_', ' ', $key ) ) );
		}
		printf(
			'<form method="get" class="cc-admin__filters"><input type="hidden" name="page" value="%s"><select name="type">%s</select> <select name="event">%s</select> <button class="button">Filter</button> <a href="%s">Emails only</a></form>',
			esc_attr( self::PAGE ),
			$opts, // phpcs:ignore WordPress.Security.EscapeOutput
			$eopts, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_url( Admin::url( self::PAGE, [ 'event' => 'email_sent' ] ) )
		);

		echo '<table class="widefat striped cc-admin__table"><thead><tr><th>When</th><th>Item</th><th>Event</th><th>Details</th><th>By</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Nothing logged yet.</td></tr>';
		}
		foreach ( $rows as $e ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td><span class="cc-admin__event cc-admin__event--%s">%s</span></td><td>%s%s</td><td>%s</td></tr>',
				esc_html( Admin::when( $e['created_at'] ) ),
				self::object_link( $e ), // phpcs:ignore WordPress.Security.EscapeOutput
				esc_attr( $e['event'] ),
				esc_html( str_replace( '_', ' ', $e['event'] ) ),
				esc_html( $e['message'] ),
				self::data( $e ), // phpcs:ignore WordPress.Security.EscapeOutput
				esc_html( Events::actor_label( (string) $e['actor'] ) )
			);
		}
		echo '</tbody></table>';
		echo Admin::pagination( self::PAGE, $total, self::PER_PAGE, $paged, $where ); // phpcs:ignore WordPress.Security.EscapeOutput
		Admin::footer();
	}

	private static function object_link( array $e ): string {
		if ( 'quote' === $e['object_type'] && $e['object_id'] ) {
			$q = Db::get( 'quotes', $e['object_id'] );
			return $q ? sprintf( '<a href="%s">%s</a>', esc_url( Quotes::admin_url( $q['id'] ) ), esc_html( $q['ref'] ) ) : 'Quote #' . (int) $e['object_id'];
		}
		if ( 'booking' === $e['object_type'] && $e['object_id'] ) {
			$b = Db::get( 'bookings', $e['object_id'] );
			return $b ? sprintf( '<a href="%s">%s</a>', esc_url( Bookings::admin_url( $b['id'] ) ), esc_html( $b['ref'] ) ) : 'Booking #' . (int) $e['object_id'];
		}
		if ( 'cleaner' === $e['object_type'] && $e['object_id'] ) {
			return sprintf( '<a href="%s">Cleaner #%d</a>', esc_url( Admin::url( Cleaners_Screen::PAGE, [ 'id' => $e['object_id'] ] ) ), (int) $e['object_id'] );
		}
		return esc_html( ucfirst( (string) $e['object_type'] ) );
	}

	/** Email events carry the recipient and an excerpt; show them collapsed. */
	private static function data( array $e ): string {
		$data = (array) $e['data'];
		if ( ! $data ) {
			return '';
		}
		$excerpt = $data['excerpt'] ?? '';
		unset( $data['excerpt'] );
		$summary = implode( ' · ', array_map( fn( $k, $v ) => $k . ': ' . ( is_scalar( $v ) ? $v : wp_json_encode( $v ) ), array_keys( $data ), $data ) );
		return '<details class="cc-admin__data"><summary>Details</summary><p>' . esc_html( $summary ) . '</p>' . ( $excerpt ? '<pre>' . esc_html( $excerpt ) . '</pre>' : '' ) . '</details>';
	}
}

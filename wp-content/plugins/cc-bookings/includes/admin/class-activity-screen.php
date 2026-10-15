<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Bookings;
use CC\Bookings\Db;
use CC\Bookings\Events;
use CC\Bookings\Notifications;
use CC\Bookings\Quotes;
use CC\Bookings\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Two tabs: Notifications (what clients and cleaners did, and payments, with this admin's unread state and "Mark all as
 * read") and the full log of everything Events recorded, including every email sent (event "email_sent").
 */
final class Activity_Screen {
	const PAGE     = 'cc-bookings-activity';
	const PER_PAGE = 50;

	public static function init(): void {
		add_action( 'cc_bookings_admin_action_notif_read_all', [ self::class, 'read_all' ] );
	}

	public static function render(): void {
		'log' === Admin::text( 'view' ) ? self::log() : self::feed();
	}

	private static function tabs( string $current ): void {
		$counts = Notifications::counts();
		echo '<nav class="nav-tab-wrapper cc-admin__tabs">';
		foreach ( [ 'feed' => 'Notifications' . Notifications::bubble( $counts['total'] ), 'log' => 'Full log' ] as $key => $label ) {
			printf( '<a class="nav-tab%s" href="%s">%s</a>', $key === $current ? ' nav-tab-active' : '', esc_url( Admin::url( self::PAGE, 'feed' === $key ? [] : [ 'view' => $key ] ) ), wp_kses_post( $label ) );
		}
		echo '</nav>';
	}

	private static function feed(): void {
		global $wpdb;
		$category = sanitize_key( Admin::text( 'category' ) );
		$category = isset( Notifications::CATEGORIES[ $category ] ) ? $category : '';
		$unread   = '1' === Admin::text( 'unread' );
		$paged    = max( 1, Admin::int( 'paged' ) );
		$state    = Notifications::state();
		$counts   = Notifications::counts();

		$where  = [];
		$params = [];
		if ( $category ) {
			$where[]  = 'category = %s';
			$params[] = $category;
		}
		if ( $unread ) {
			[ $sql, $more ] = Notifications::unread_sql( $state );
			$where[]        = $sql;
			$params         = array_merge( $params, $more );
		}
		$from  = ' FROM ' . Schema::table( Notifications::TABLE ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' );
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( 'SELECT COUNT(*)' . $from, $params ) : 'SELECT COUNT(*)' . $from ); // phpcs:ignore WordPress.DB.PreparedSQL
		$query = 'SELECT *' . $from . sprintf( ' ORDER BY id DESC LIMIT %d OFFSET %d', self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$rows  = $wpdb->get_results( $params ? $wpdb->prepare( $query, $params ) : $query, ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL

		Admin::header( 'Activity' );
		self::tabs( 'feed' );
		echo '<p class="description">What clients and cleaners have done, and every payment. Opening a section marks its notifications seen; opening a record marks that record\'s notifications read. Your read state is your own — other admins keep theirs.</p>';

		$opts = '<option value="">All</option>';
		foreach ( Notifications::CATEGORIES as $key => [ $label ] ) {
			$opts .= sprintf( '<option value="%s"%s>%s%s</option>', esc_attr( $key ), selected( $category, $key, false ), esc_html( $label ), $counts[ $key ] ? ' (' . (int) $counts[ $key ] . ' unread)' : '' );
		}
		printf(
			'<form method="get" class="cc-admin__filters"><input type="hidden" name="page" value="%s"><select name="category">%s</select> <label><input type="checkbox" name="unread" value="1"%s> Unread only</label> <button class="button">Filter</button></form>',
			esc_attr( self::PAGE ),
			$opts, // phpcs:ignore WordPress.Security.EscapeOutput
			checked( $unread, true, false )
		);
		if ( $counts['total'] ) {
			echo Admin::button( 'notif_read_all', [], sprintf( 'Mark all as read (%d)', $counts['total'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo '<table class="widefat striped cc-admin__table cc-admin__notifications"><thead><tr><th>When</th><th>Who</th><th>What happened</th><th>Record</th><th>Section</th></tr></thead><tbody>';
		if ( ! $rows ) {
			printf( '<tr><td colspan="5">%s</td></tr>', $unread ? 'You\'re all caught up.' : 'Nothing yet.' );
		}
		foreach ( $rows as $row ) {
			$new           = Notifications::is_unread( $row, $state );
			[ $ref, $url ] = Notifications::link( $row );
			printf(
				'<tr class="%s"><td>%s%s</td><td>%s</td><td><strong>%s</strong>%s</td><td>%s</td><td>%s</td></tr>',
				$new ? 'cc-admin__unread' : '',
				$new ? '<span class="cc-admin__tag">New</span> ' : '',
				esc_html( Admin::when( $row['created_at'] ) ),
				esc_html( $row['actor_name'] ?: Events::actor_label( (string) $row['actor'] ) ),
				esc_html( $row['summary'] ),
				$row['detail'] ? '<br><span class="description">' . esc_html( wp_trim_words( (string) $row['detail'], 40 ) ) . '</span>' : '',
				$url ? sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $ref ) ) : esc_html( $ref ),
				esc_html( Notifications::CATEGORIES[ $row['category'] ][0] ?? $row['category'] )
			);
		}
		echo '</tbody></table>';
		echo Admin::pagination( self::PAGE, $total, self::PER_PAGE, $paged, array_filter( [ 'category' => $category, 'unread' => $unread ? '1' : '' ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		Admin::footer();
	}

	public static function read_all(): void {
		Notifications::mark_all_read();
		Admin::done( Admin::url( self::PAGE ), 'All notifications marked as read.' );
	}

	private static function log(): void {
		global $wpdb;
		$type  = sanitize_key( Admin::text( 'type' ) );
		$event = sanitize_key( Admin::text( 'event' ) );
		$paged = max( 1, Admin::int( 'paged' ) );

		$where = array_filter( [ 'object_type' => $type, 'event' => $event ] );
		$total = Db::count( 'events', $where );
		$rows  = Db::all( 'events', $where, 'id DESC', self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		Admin::header( 'Activity' );
		self::tabs( 'log' );
		$types  = [ '' => 'Everything', 'quote' => 'Quotes', 'booking' => 'Bookings', 'plan' => 'Regular plans', 'client' => 'Clients', 'cleaner' => 'Cleaners', 'system' => 'System' ];
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
			'<form method="get" class="cc-admin__filters"><input type="hidden" name="page" value="%s"><input type="hidden" name="view" value="log"><select name="type">%s</select> <select name="event">%s</select> <button class="button">Filter</button> <a href="%s">Emails only</a></form>',
			esc_attr( self::PAGE ),
			$opts, // phpcs:ignore WordPress.Security.EscapeOutput
			$eopts, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_url( Admin::url( self::PAGE, [ 'view' => 'log', 'event' => 'email_sent' ] ) )
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
		echo Admin::pagination( self::PAGE, $total, self::PER_PAGE, $paged, $where + [ 'view' => 'log' ] ); // phpcs:ignore WordPress.Security.EscapeOutput
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

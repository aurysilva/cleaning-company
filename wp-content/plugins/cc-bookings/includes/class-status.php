<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Central status model for quotes, bookings, payments and job offers: labels and allowed transitions.
 * Later phases add statuses through the `cc_bookings_statuses` and `cc_bookings_transitions` filters.
 */
final class Status {
	public static function all( string $kind ): array {
		$statuses = [
			'quote'   => [
				'draft'      => 'Awaiting price',
				'sent'       => 'Sent',
				'viewed'     => 'Viewed',
				'accepted'   => 'Accepted',
				'expired'    => 'Expired',
				'superseded' => 'Replaced',
				'cancelled'  => 'Cancelled',
			],
			'booking' => [
				'confirmed'   => 'Needs cleaner',
				'dispatching' => 'Finding cleaner',
				'assigned'    => 'Cleaner assigned',
				'completed'   => 'Completed',
				'cancelled'   => 'Cancelled',
			],
			'payment' => [
				'unpaid'             => 'Unpaid',
				'paid'               => 'Paid',
				'partially_refunded' => 'Part refunded',
				'refunded'           => 'Refunded',
			],
			'offer'   => [
				'offered'   => 'Offered',
				'accepted'  => 'Accepted',
				'assigned'  => 'Assigned',
				'declined'  => 'Declined',
				'expired'   => 'Expired',
				'withdrawn' => 'Withdrawn',
				'dropped'   => 'Dropped out',
				'removed'   => 'Removed',
			],
		];
		return apply_filters( 'cc_bookings_statuses', $statuses[ $kind ] ?? [], $kind );
	}

	public static function label( string $kind, string $status ): string {
		return self::all( $kind )[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
	}

	/** Wording shown to customers, which hides internal steps such as dispatch. */
	public static function customer_label( string $kind, string $status ): string {
		$labels = apply_filters(
			'cc_bookings_customer_statuses',
			[
				'booking' => [
					'confirmed'   => 'Confirmed',
					'dispatching' => 'Confirmed',
					'assigned'    => 'Confirmed — cleaner assigned',
					'completed'   => 'Completed',
					'cancelled'   => 'Cancelled',
				],
			]
		);
		return $labels[ $kind ][ $status ] ?? self::label( $kind, $status );
	}

	public static function transitions( string $kind ): array {
		$map = [
			'quote'   => [
				'draft'    => [ 'sent', 'cancelled', 'superseded', 'accepted' ],
				'sent'     => [ 'viewed', 'accepted', 'expired', 'cancelled', 'superseded', 'sent' ],
				'viewed'   => [ 'accepted', 'expired', 'cancelled', 'superseded', 'sent' ],
				'expired'  => [ 'sent', 'cancelled', 'superseded', 'accepted' ],
				'accepted' => [],
			],
			'booking' => [
				'confirmed'   => [ 'dispatching', 'assigned', 'completed', 'cancelled' ],
				'dispatching' => [ 'confirmed', 'assigned', 'cancelled' ],
				'assigned'    => [ 'confirmed', 'dispatching', 'completed', 'cancelled' ],
				'completed'   => [ 'assigned' ],
				'cancelled'   => [],
			],
		];
		return apply_filters( 'cc_bookings_transitions', $map[ $kind ] ?? [], $kind );
	}

	public static function can( string $kind, string $from, string $to ): bool {
		return in_array( $to, self::transitions( $kind )[ $from ] ?? [], true );
	}

	/** Quote statuses in which the customer can still pay. */
	public static function payable_quote(): array {
		return [ 'sent', 'viewed' ];
	}

	/** Booking statuses that take up slot capacity. */
	public static function active_booking(): array {
		return apply_filters( 'cc_bookings_active_booking_statuses', [ 'confirmed', 'dispatching', 'assigned', 'completed' ] );
	}

	/** Job offer statuses that put a cleaner on a job. */
	public static function on_job(): array {
		return [ 'assigned', 'accepted' ];
	}

	public static function badge( string $kind, string $status ): string {
		return sprintf( '<span class="cc-status cc-status--%s">%s</span>', esc_attr( $status ), esc_html( self::label( $kind, $status ) ) );
	}
}

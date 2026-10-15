<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Audit log: every status change, payment, email and admin action, per object.
 */
final class Events {
	public static function log( string $type, int $id, string $event, string $message = '', array $data = [], string $actor = '' ): void {
		$actor = $actor ?: self::actor();
		$row   = Db::insert( 'events', [
			'object_type' => $type,
			'object_id'   => $id,
			'event'       => $event,
			'message'     => $message,
			'data'        => $data,
			'actor'       => $actor,
		] );
		do_action( 'cc_bookings_event', $type, $id, $event, $message, $data, $actor, $row );
	}

	public static function actor(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		$user = get_current_user_id();
		return $user ? 'user:' . $user : 'system';
	}

	public static function actor_label( string $actor ): string {
		if ( str_starts_with( $actor, 'user:' ) ) {
			$user = get_userdata( (int) substr( $actor, 5 ) );
			return $user ? $user->display_name : $actor;
		}
		return ucfirst( $actor );
	}

	public static function for_object( string $type, int $id ): array {
		return Db::all( 'events', [ 'object_type' => $type, 'object_id' => $id ], 'id ASC' );
	}

	/** Timeline for a booking, including its quote's history. */
	public static function timeline( array $booking ): array {
		$events = array_merge( self::for_object( 'booking', $booking['id'] ), $booking['quote_id'] ? self::for_object( 'quote', $booking['quote_id'] ) : [] );
		usort( $events, fn( $a, $b ) => [ $a['created_at'], $a['id'] ] <=> [ $b['created_at'], $b['id'] ] );
		return $events;
	}
}

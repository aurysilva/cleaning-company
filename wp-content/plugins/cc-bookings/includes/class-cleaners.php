<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Cleaner profiles. Phase 1 uses name, contact details, type and status for manual assignment;
 * districts, services, availability and pay are stored for phase 2 dispatch.
 */
final class Cleaners {
	const TYPES = [ 'employed' => 'Employed', 'self_employed' => 'Self-employed' ];

	public static function get( int $id ): ?array {
		return Db::get( 'cleaners', $id );
	}

	public static function active(): array {
		return Db::all( 'cleaners', [ 'status' => 'active' ], 'type ASC, name ASC' );
	}

	/** @return int|\WP_Error */
	public static function save( array $input, int $id = 0 ) {
		$data = [
			'name'             => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
			'email'            => sanitize_email( (string) ( $input['email'] ?? '' ) ),
			'phone'            => preg_replace( '/[^0-9+ ]/', '', (string) ( $input['phone'] ?? '' ) ),
			'type'             => isset( self::TYPES[ $input['type'] ?? '' ] ) ? $input['type'] : 'employed',
			'status'           => ( $input['status'] ?? 'active' ) === 'inactive' ? 'inactive' : 'active',
			'districts'        => array_values( array_filter( array_map( fn( $d ) => Postcodes::district( $d ), preg_split( '/[\s,]+/', (string) ( $input['districts'] ?? '' ) ) ) ) ),
			'services'         => array_values( array_map( 'sanitize_key', (array) ( $input['services'] ?? [] ) ) ),
			'can_lead'         => ! empty( $input['can_lead'] ) ? 1 : 0,
			'max_jobs_per_day' => max( 1, min( 10, (int) ( $input['max_jobs_per_day'] ?? 3 ) ) ),
			'pay_type'         => ( $input['pay_type'] ?? 'hourly' ) === 'per_job' ? 'per_job' : 'hourly',
			'pay_rate_pence'   => (int) Money::parse( $input['pay_rate'] ?? '' ),
			'has_car'          => ! empty( $input['has_car'] ) ? 1 : 0,
			'notes'            => sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ),
		];
		if ( '' === $data['name'] ) {
			return new \WP_Error( 'name', 'Enter the cleaner\'s name.' );
		}
		if ( ! is_email( $data['email'] ) ) {
			return new \WP_Error( 'email', 'Enter a valid email address.' );
		}
		if ( $id ) {
			Db::update( 'cleaners', $id, $data );
			Events::log( 'cleaner', $id, 'updated', 'Profile updated' );
		} else {
			$id = Db::insert( 'cleaners', $data );
			Events::log( 'cleaner', $id, 'created', 'Cleaner added' );
		}
		do_action( 'cc_cleaner_saved', $id, $data );
		return $id;
	}

	/** Upcoming jobs for a cleaner. */
	public static function jobs( int $cleaner_id, bool $upcoming = true ): array {
		global $wpdb;
		$on_job = Status::on_job();
		$in     = implode( ',', array_fill( 0, count( $on_job ), '%s' ) );
		$rows   = $wpdb->get_results( $wpdb->prepare(
			'SELECT b.* FROM ' . Schema::table( 'bookings' ) . ' b JOIN ' . Schema::table( 'job_offers' ) . ' o ON o.booking_id = b.id'
			. " WHERE o.cleaner_id = %d AND o.status IN ({$in})" . ( $upcoming ? ' AND b.job_date >= %s' : ' AND b.job_date IS NOT NULL AND %s = %s' )
			. ' ORDER BY b.job_date ASC, b.slot ASC',
			array_merge( [ $cleaner_id ], $on_job, $upcoming ? [ wp_date( 'Y-m-d' ) ] : [ '1', '1' ] )
		), ARRAY_A );
		return array_map( [ Db::class, 'decode' ], $rows ?: [] );
	}
}

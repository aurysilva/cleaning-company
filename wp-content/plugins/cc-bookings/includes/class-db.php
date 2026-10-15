<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Thin row helpers over $wpdb for the plugin tables. JSON columns are encoded on write and decoded on read.
 */
final class Db {
	const JSON = [ 'answers', 'items', 'bespoke_reasons', 'photos', 'consent', 'meta', 'data', 'districts', 'services', 'token_hashes' ];

	const NO_UPDATED_AT = [ 'booking_items', 'cleaner_availability', 'events' ];

	public static function now(): string {
		return current_time( 'mysql', true );
	}

	/** UTC MySQL datetime for a Unix timestamp. */
	public static function datetime( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	public static function timestamp( ?string $utc ): int {
		return $utc ? (int) strtotime( $utc . ' UTC' ) : 0;
	}

	public static function insert( string $table, array $data ): int {
		global $wpdb;
		$now                = self::now();
		$data['created_at'] = $data['created_at'] ?? $now;
		if ( ! in_array( $table, self::NO_UPDATED_AT, true ) ) {
			$data['updated_at'] = $now;
		}
		$wpdb->insert( Schema::table( $table ), self::encode( $data ) );
		return (int) $wpdb->insert_id;
	}

	public static function update( string $table, int $id, array $data ): bool {
		global $wpdb;
		if ( ! in_array( $table, self::NO_UPDATED_AT, true ) ) {
			$data['updated_at'] = self::now();
		}
		return false !== $wpdb->update( Schema::table( $table ), self::encode( $data ), [ 'id' => $id ] );
	}

	public static function delete( string $table, array $where ): void {
		global $wpdb;
		[ $sql, $params ] = self::where( $where );
		if ( '' === $sql ) {
			return;
		}
		$query = 'DELETE FROM ' . Schema::table( $table ) . $sql;
		$wpdb->query( $params ? $wpdb->prepare( $query, $params ) : $query );
	}

	public static function get( string $table, int $id ): ?array {
		return self::find( $table, [ 'id' => $id ] );
	}

	public static function find( string $table, array $where, string $order = 'id DESC' ): ?array {
		return self::all( $table, $where, $order, 1 )[0] ?? null;
	}

	/**
	 * @param array $where column => value (scalar, or array for IN).
	 */
	public static function all( string $table, array $where = [], string $order = 'id DESC', int $limit = 0, int $offset = 0 ): array {
		global $wpdb;
		[ $sql, $params ] = self::where( $where );
		$order = preg_replace( '/[^a-z0-9_, ]/i', '', $order );
		$query = 'SELECT * FROM ' . Schema::table( $table ) . $sql . ( $order ? " ORDER BY {$order}" : '' );
		if ( $limit ) {
			$query .= sprintf( ' LIMIT %d OFFSET %d', $limit, $offset );
		}
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $query, $params ) : $query, ARRAY_A );
		return array_map( [ self::class, 'decode' ], $rows ?: [] );
	}

	public static function count( string $table, array $where = [] ): int {
		global $wpdb;
		[ $sql, $params ] = self::where( $where );
		$query = 'SELECT COUNT(*) FROM ' . Schema::table( $table ) . $sql;
		return (int) $wpdb->get_var( $params ? $wpdb->prepare( $query, $params ) : $query );
	}

	private static function where( array $where ): array {
		$clauses = [];
		$params  = [];
		foreach ( $where as $column => $value ) {
			$column = preg_replace( '/[^a-z0-9_]/i', '', (string) $column );
			if ( is_array( $value ) ) {
				if ( ! $value ) {
					$clauses[] = '1=0';
					continue;
				}
				$clauses[] = "{$column} IN (" . implode( ',', array_fill( 0, count( $value ), is_int( reset( $value ) ) ? '%d' : '%s' ) ) . ')';
				array_push( $params, ...array_values( $value ) );
			} elseif ( null === $value ) {
				$clauses[] = "{$column} IS NULL";
			} else {
				$clauses[] = "{$column} = " . ( is_int( $value ) ? '%d' : '%s' );
				$params[]  = $value;
			}
		}
		return [ $clauses ? ' WHERE ' . implode( ' AND ', $clauses ) : '', $params ];
	}

	private static function encode( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = wp_json_encode( $value );
			} elseif ( is_bool( $value ) ) {
				$data[ $key ] = (int) $value;
			}
		}
		return $data;
	}

	public static function decode( array $row ): array {
		foreach ( self::JSON as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$row[ $key ] = $row[ $key ] ? ( json_decode( $row[ $key ], true ) ?: [] ) : [];
			}
		}
		foreach ( [ 'id', 'quote_id', 'booking_id', 'cleaner_id', 'service_id', 'object_id', 'cleaners', 'is_bespoke', 'covered', 'is_test', 'followups_off', 'marketing_opt_in' ] as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		foreach ( [ 'total_pence', 'paid_pence', 'refunded_pence', 'amount_pence', 'unit_pence', 'pay_pence', 'pay_rate_pence' ] as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[ $key ] ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		if ( isset( $row['hours'] ) ) {
			$row['hours'] = (float) $row['hours'];
		}
		return $row;
	}

	/** Named MySQL lock, so concurrent webhook deliveries (or offer accepts) are processed one at a time. */
	public static function lock( string $name, int $timeout = 10 ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $name ), $timeout ) );
	}

	public static function unlock( string $name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $name ) ) );
	}

	private static function lock_name( string $name ): string {
		global $wpdb;
		return substr( $wpdb->prefix . 'cc_' . md5( $name ), 0, 64 );
	}
}

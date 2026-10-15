<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings (option cc_bookings_settings), merged over defaults. Read with dot paths:
 * Settings::get( 'pricing.weekend_pct' ). API keys are never stored here; they are wp-config constants.
 */
final class Settings {
	const OPTION = 'cc_bookings_settings';

	private static ?array $cache = null;

	public static function defaults(): array {
		return apply_filters( 'cc_bookings_settings_defaults', [
			'pricing'  => [
				'condition_good'     => 0.95,
				'condition_average'  => 1.0,
				'condition_poor'     => 1.25,
				'furnished_pct'      => 10,
				'weekend_pct'        => 10,
				'short_notice_hours' => 72,
				'short_notice_pct'   => 15,
				'parking_paid'       => 5,
				'parking_permit'     => 0,
				'labour_rate'        => 25,
				'team_after_hours'   => 5,
				'max_team'           => 4,
				'default_minimum'    => 40,
			],
			'services' => [
				'after-builders-cleaning' => [ 'mode' => 'bespoke' ],
				'communal-area-cleaning'  => [ 'mode' => 'bespoke' ],
				'office-cleaning'         => [ 'mode' => 'bespoke' ],
				'commercial-cleaning'     => [ 'mode' => 'bespoke' ],
				'one-off-cleaning'        => [ 'minimum' => 66 ],
				'oven-cleaning'           => [ 'minimum' => 55 ],
			],
			'booking'  => [
				'min_notice_hours' => 48,
				'max_days_ahead'   => 60,
				'working_days'     => [ 1, 2, 3, 4, 5, 6, 7 ],
				'blocked_dates'    => '',
				'slots'            => [
					'morning'   => [ 'label' => 'Morning', 'start' => '08:00', 'end' => '10:00' ],
					'midday'    => [ 'label' => 'Midday', 'start' => '11:00', 'end' => '13:00' ],
					'afternoon' => [ 'label' => 'Afternoon', 'start' => '14:00', 'end' => '16:00' ],
				],
				'capacity_jobs'    => 2,
				'capacity_hours'   => 16,
				'hold_minutes'     => 30,
			],
			'quotes'   => [
				'expiry_days'         => 7,
				'reminder_hours'      => 24,
				'expiring_day'        => 6,
				'early_start_days'    => 14,
				'terms_url'           => '/terms/',
				'cancellation_policy' => "More than 48 hours before your clean: full refund.\n24–48 hours before: 50% refund.\nUnder 24 hours, or if we can't get in on the day: no refund.",
				'regular_note'        => 'This price is for your first visit. We\'ll confirm your regular day and time with you after it.',
			],
			'emails'   => [
				'from_name'    => '',
				'from_address' => '',
				'admin_to'     => '',
				'log_only'     => 0,
				'templates'    => [],
			],
		] );
	}

	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, [] );
			self::$cache = self::merge( self::defaults(), is_array( $stored ) ? $stored : [] );
		}
		return self::$cache;
	}

	public static function get( string $path, $default = null ) {
		$value = self::all();
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $default;
			}
			$value = $value[ $key ];
		}
		return $value;
	}

	public static function save( array $settings ): void {
		update_option( self::OPTION, $settings, false );
		self::$cache = null;
	}

	/** Replace a single value (dot path) and save. */
	public static function set( string $path, $value ): void {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$ref    = &$stored;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
				$ref[ $key ] = [];
			}
			$ref = &$ref[ $key ];
		}
		$ref = $value;
		unset( $ref );
		self::save( $stored );
	}

	/** Associative arrays merge recursively; lists (e.g. working days) are replaced whole. */
	private static function merge( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $base[ $key ] ) && ! array_is_list( $value ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	public static function service( string $slug ): array {
		$row = (array) self::get( 'services.' . $slug, [] );
		return [
			'mode'    => ( $row['mode'] ?? 'auto' ) === 'bespoke' ? 'bespoke' : 'auto',
			'minimum' => isset( $row['minimum'] ) && '' !== $row['minimum'] ? (float) $row['minimum'] : (float) self::get( 'pricing.default_minimum', 0 ),
		];
	}

	public static function admin_email(): string {
		return sanitize_email( (string) self::get( 'emails.admin_to' ) ) ?: (string) get_option( 'admin_email' );
	}

	public static function brand(): string {
		return function_exists( 'cc_brand_name' ) ? cc_brand_name() : get_bloginfo( 'name' );
	}

	public static function terms_url(): string {
		$url = (string) self::get( 'quotes.terms_url', '/terms/' );
		return str_starts_with( $url, '/' ) ? home_url( $url ) : $url;
	}
}

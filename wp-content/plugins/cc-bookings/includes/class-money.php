<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

final class Money {
	public static function pence( $pounds ): int {
		return (int) round( (float) $pounds * 100 );
	}

	public static function format( ?int $pence ): string {
		if ( null === $pence ) {
			return '';
		}
		$sign   = $pence < 0 ? '−' : '';
		$pounds = abs( $pence ) / 100;
		return $sign . '£' . ( abs( $pence ) % 100 === 0 ? number_format( $pounds ) : number_format( $pounds, 2 ) );
	}

	/** Admin input like "120", "£120.50" or "120.5" to pence. */
	public static function parse( $input ): ?int {
		$clean = preg_replace( '/[^0-9.\-]/', '', (string) $input );
		return '' === $clean || ! is_numeric( $clean ) ? null : self::pence( $clean );
	}
}

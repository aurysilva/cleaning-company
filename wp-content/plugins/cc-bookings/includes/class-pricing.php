<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing engine (spec §4):
 *   (base + add-ons) × condition × furnished + weekend / short-notice % + parking → minimum charge → whole pounds.
 * Also estimates duration and team size, and flags jobs that need a bespoke (manual) price.
 * Every adjustment is its own line item so quotes are itemised.
 */
final class Pricing {
	/**
	 * @param string $date Y-m-d (site time). Weekend / short-notice surcharges apply only when given.
	 * @param int|null $now Reference time for short notice (defaults to now).
	 * @return array{bespoke: bool, reasons: string[], items: array, total: ?int, hours: float, cleaners: int}
	 */
	public static function price( ?array $service, array $answers, string $date = '', string $slot = '', ?int $now = null ): array {
		$p       = (array) Settings::get( 'pricing' );
		$labour  = max( 1.0, (float) $p['labour_rate'] );
		$reasons = [];
		$items   = [];
		$hours   = 0.0;
		$team    = null;

		if ( ! $service ) {
			return self::result( [ 'Unknown service' ], [], 0, 1 );
		}
		if ( 'bespoke' === $service['mode'] ) {
			$reasons[] = 'other' === $service['profile'] ? 'Not one of our standard services' : $service['name'] . ' is always priced individually';
		}

		// Base price
		if ( 'items' === $service['profile'] ) {
			foreach ( (array) ( $answers['quantities'] ?? [] ) as $index => $qty ) {
				$row = $service['sizes'][ $index ] ?? null;
				if ( ! $row ) {
					continue;
				}
				if ( null === $row['price'] ) {
					$reasons[] = $row['label'] . ' is priced individually';
					continue;
				}
				$items[]  = self::line( 'base', $row['label'], $qty, Money::pence( $row['price'] ) );
				$hours   += self::row_duration( $row, $labour )['cleaner_hours'] * $qty;
			}
		} elseif ( isset( $answers['size'] ) && isset( $service['sizes'][ $answers['size'] ] ) ) {
			$row = $service['sizes'][ $answers['size'] ];
			if ( null === $row['price'] ) {
				$reasons[] = $row['label'] . ' is priced individually';
			} else {
				$items[]  = self::line( 'base', $service['name'] . ' — ' . $row['label'], 1, Money::pence( $row['price'] ) );
				$duration = self::row_duration( $row, $labour );
				$hours   += $duration['cleaner_hours'];
				$team     = $duration['team'];
			}
		} elseif ( $service['sizes'] && 'bespoke' !== $service['mode'] ) {
			$reasons[] = 'Property size not given';
		}

		// Add-ons
		foreach ( (array) ( $answers['addons'] ?? [] ) as $index => $qty ) {
			$addon = $service['addons'][ $index ] ?? null;
			if ( ! $addon ) {
				continue;
			}
			if ( null === $addon['price'] ) {
				$reasons[] = $addon['name'] . ' is priced on request';
				continue;
			}
			$items[] = self::line( 'addon', $addon['name'], $qty, Money::pence( $addon['price'] ) );
			$hours  += $addon['price'] * $qty / $labour;
		}

		// Condition and furnished factors
		$work      = self::sum( $items );
		$condition = $answers['condition'] ?? '';
		if ( 'very_poor' === $condition ) {
			$reasons[] = 'Very poor condition — we need to see photos before pricing';
		} elseif ( isset( $p[ 'condition_' . $condition ] ) ) {
			$factor = (float) $p[ 'condition_' . $condition ];
			if ( abs( $factor - 1 ) > 0.0001 ) {
				$items[] = self::line( 'adjustment', sprintf( '%s condition (%s)', ucfirst( $condition ), self::pct_label( ( $factor - 1 ) * 100 ) ), 1, self::whole_pounds( $work * ( $factor - 1 ) ) );
				$hours  *= $factor;
			}
		}
		if ( 'furnished' === ( $answers['furnished'] ?? '' ) && (float) $p['furnished_pct'] ) {
			$pct     = (float) $p['furnished_pct'];
			$items[] = self::line( 'adjustment', sprintf( 'Furnished property (%s)', self::pct_label( $pct ) ), 1, self::whole_pounds( self::sum( $items ) * $pct / 100 ) );
			$hours  *= 1 + $pct / 100;
		}

		// Surcharges
		$subtotal = self::sum( $items );
		if ( $date && $subtotal ) {
			$start = Slots::start_timestamp( $date, $slot );
			if ( $start && (int) wp_date( 'N', $start ) >= 6 && (float) $p['weekend_pct'] ) {
				$items[] = self::line( 'surcharge', sprintf( 'Weekend clean (%s)', self::pct_label( (float) $p['weekend_pct'] ) ), 1, self::whole_pounds( $subtotal * (float) $p['weekend_pct'] / 100 ) );
			}
			if ( $start && $slot && (float) $p['short_notice_pct'] && $start - ( $now ?? time() ) < (float) $p['short_notice_hours'] * HOUR_IN_SECONDS ) {
				$items[] = self::line( 'surcharge', sprintf( 'Short-notice booking (%s)', self::pct_label( (float) $p['short_notice_pct'] ) ), 1, self::whole_pounds( $subtotal * (float) $p['short_notice_pct'] / 100 ) );
			}
		}
		$parking = $answers['parking'] ?? '';
		if ( in_array( $parking, [ 'paid', 'permit' ], true ) && (float) $p[ 'parking_' . $parking ] && $subtotal ) {
			$items[] = self::line( 'surcharge', 'paid' === $parking ? 'Paid parking' : 'Parking permit', 1, Money::pence( $p[ 'parking_' . $parking ] ) );
		}

		// Minimum charge, then whole pounds
		$total   = self::sum( $items );
		$minimum = Money::pence( $service['minimum'] );
		if ( $total > 0 && $total < $minimum ) {
			$items[] = self::line( 'minimum', 'Minimum charge top-up', 1, $minimum - $total );
			$total   = $minimum;
		}
		$rounded = self::whole_pounds( $total );
		if ( $rounded !== $total ) {
			$items[] = self::line( 'adjustment', 'Rounding', 1, $rounded - $total );
		}

		// Team and duration
		$max_team = max( 1, (int) $p['max_team'] );
		$cleaners = $team ?? (int) min( $max_team, max( 1, ceil( $hours / max( 1, (float) $p['team_after_hours'] ) ) ) );
		$duration = $hours > 0 ? max( 1, ceil( $hours / $cleaners * 2 ) / 2 ) : 0;

		return apply_filters( 'cc_bookings_price', self::result( $reasons, $items, $duration, $cleaners ), $service, $answers, $date, $slot );
	}

	/** Re-price a stored quote, with time-based surcharges judged from when it was quoted. */
	public static function for_quote( array $quote ): array {
		$reference = Db::timestamp( $quote['sent_at'] ?: $quote['created_at'] ) ?: time();
		return self::price( Catalog::get( $quote['service_slug'] ), (array) $quote['answers'], (string) $quote['job_date'], (string) $quote['slot'], $reference );
	}

	/**
	 * Duration of one price-table row: the row's own hours / cleaners, else hours in its label or note
	 * ("3 hours (minimum)", "Around 2 hours per visit"), else estimated from price ÷ labour rate.
	 *
	 * @return array{cleaner_hours: float, team: ?int}
	 */
	public static function row_duration( array $row, float $labour ): array {
		if ( $row['hours'] ) {
			$team = $row['cleaners'] ?? 1;
			return [ 'cleaner_hours' => (float) $row['hours'] * $team, 'team' => $team ];
		}
		if ( preg_match( '/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?)\b/i', $row['label'] . ' ' . $row['note'], $m ) ) {
			$team = $row['cleaners'] ?? 1;
			return [ 'cleaner_hours' => (float) $m[1] * $team, 'team' => $team ];
		}
		return [ 'cleaner_hours' => null === $row['price'] ? 0.0 : (float) $row['price'] / $labour, 'team' => $row['cleaners'] ];
	}

	public static function duration_label( float $hours, int $cleaners ): string {
		if ( $hours <= 0 ) {
			return '';
		}
		$h = rtrim( rtrim( number_format( $hours, 1 ), '0' ), '.' );
		return $cleaners > 1
			? sprintf( 'About %s hours with a team of %d', $h, $cleaners )
			: sprintf( 'About %s hour%s', $h, '1' === $h ? '' : 's' );
	}

	public static function line( string $type, string $label, $qty, int $unit, ?int $amount = null ): array {
		return [
			'type'   => $type,
			'label'  => $label,
			'qty'    => (float) $qty,
			'unit'   => $unit,
			'amount' => $amount ?? (int) round( $unit * (float) $qty ),
		];
	}

	public static function sum( array $items ): int {
		return (int) array_sum( array_column( $items, 'amount' ) );
	}

	private static function whole_pounds( float $pence ): int {
		return (int) ( round( $pence / 100 ) * 100 );
	}

	private static function pct_label( float $pct ): string {
		$pct = round( $pct, 1 );
		return ( $pct > 0 ? '+' : '−' ) . rtrim( rtrim( number_format( abs( $pct ), 1 ), '0' ), '.' ) . '%';
	}

	private static function result( array $reasons, array $items, float $hours, int $cleaners ): array {
		$reasons = array_values( array_unique( $reasons ) );
		return [
			'bespoke'  => (bool) $reasons,
			'reasons'  => $reasons,
			'items'    => $items,
			'total'    => $reasons ? null : self::sum( $items ),
			'hours'    => $hours,
			'cleaners' => $cleaners,
		];
	}

	/** Price preview for the browser: formatted lines, never raw config. */
	public static function public_preview( array $result ): array {
		return [
			'bespoke'  => $result['bespoke'],
			'reasons'  => $result['reasons'],
			'items'    => array_map( fn( $i ) => [
				'label'  => $i['label'] . ( $i['qty'] > 1 ? ' × ' . rtrim( rtrim( number_format( $i['qty'], 2 ), '0' ), '.' ) : '' ),
				'amount' => Money::format( $i['amount'] ),
				'type'   => $i['type'],
			], $result['bespoke'] ? [] : $result['items'] ),
			'total'    => $result['bespoke'] ? null : Money::format( $result['total'] ),
			'duration' => Pricing::duration_label( $result['hours'], $result['cleaners'] ),
		];
	}
}

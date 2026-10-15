<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Services as the quote form and pricing engine see them: each cc_service post's SCF price_table rows
 * (with optional hours / cleaners) and addons, plus a form "profile" deciding which questions are asked.
 */
final class Catalog {
	const OTHER = 'something-else';

	const PROFILES = [
		'regular-house-cleaning'      => 'regular',
		'one-off-cleaning'            => 'hours',
		'deep-cleaning'               => 'deep',
		'move-in-cleaning'            => 'deep',
		'end-of-tenancy-cleaning'     => 'tenancy',
		'oven-cleaning'               => 'oven',
		'carpet-upholstery-cleaning'  => 'items',
		'holiday-let-airbnb-cleaning' => 'holiday',
		'after-builders-cleaning'     => 'bespoke',
		'communal-area-cleaning'      => 'bespoke',
		'office-cleaning'             => 'bespoke',
		'commercial-cleaning'         => 'bespoke',
	];

	private static ?array $services = null;

	/** @return array<string, array> keyed by slug, in menu order, with "Something else" last. */
	public static function services(): array {
		if ( null !== self::$services ) {
			return self::$services;
		}
		$profiles = apply_filters( 'cc_bookings_service_profiles', self::PROFILES );
		$posts    = get_posts( [
			'post_type'      => 'cc_service',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] );

		self::$services = [];
		foreach ( $posts as $post ) {
			$settings = Settings::service( $post->post_name );
			$profile  = $profiles[ $post->post_name ] ?? 'standard';
			self::$services[ $post->post_name ] = [
				'id'      => $post->ID,
				'slug'    => $post->post_name,
				'name'    => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
				'profile' => $profile,
				'mode'    => 'bespoke' === $profile ? 'bespoke' : $settings['mode'],
				'minimum' => $settings['minimum'],
				'sizes'   => self::sizes( $post->ID ),
				'addons'  => self::addons( $post->ID ),
				'url'     => get_permalink( $post ),
			];
		}
		self::$services[ self::OTHER ] = [
			'id'      => 0,
			'slug'    => self::OTHER,
			'name'    => 'Something else',
			'profile' => 'other',
			'mode'    => 'bespoke',
			'minimum' => 0,
			'sizes'   => [],
			'addons'  => [],
			'url'     => '',
		];
		self::$services = apply_filters( 'cc_bookings_services', self::$services );
		return self::$services;
	}

	public static function get( string $slug ): ?array {
		return self::services()[ $slug ] ?? null;
	}

	public static function by_id( int $id ): ?array {
		foreach ( self::services() as $service ) {
			if ( $service['id'] === $id ) {
				return $service;
			}
		}
		return null;
	}

	public static function flush(): void {
		self::$services = null;
	}

	private static function rows( int $post_id, string $name, array $subfields ): array {
		if ( function_exists( 'get_field' ) ) {
			$rows = get_field( $name, $post_id );
			return is_array( $rows ) ? $rows : [];
		}
		$rows  = [];
		$count = (int) get_post_meta( $post_id, $name, true );
		for ( $i = 0; $i < $count; $i++ ) {
			foreach ( $subfields as $sub ) {
				$rows[ $i ][ $sub ] = get_post_meta( $post_id, "{$name}_{$i}_{$sub}", true );
			}
		}
		return $rows;
	}

	private static function number( $value ): ?float {
		return null === $value || '' === $value || false === $value ? null : (float) $value;
	}

	private static function sizes( int $post_id ): array {
		$out = [];
		foreach ( self::rows( $post_id, 'price_table', [ 'property_size', 'price', 'note', 'hours', 'cleaners' ] ) as $row ) {
			if ( empty( $row['property_size'] ) ) {
				continue;
			}
			$out[] = [
				'label'    => (string) $row['property_size'],
				'note'     => (string) ( $row['note'] ?? '' ),
				'price'    => self::number( $row['price'] ?? null ),
				'hours'    => self::number( $row['hours'] ?? null ),
				'cleaners' => isset( $row['cleaners'] ) && '' !== $row['cleaners'] ? max( 1, (int) $row['cleaners'] ) : null,
			];
		}
		return $out;
	}

	private static function addons( int $post_id ): array {
		$out = [];
		foreach ( self::rows( $post_id, 'addons', [ 'name', 'price', 'description' ] ) as $row ) {
			if ( empty( $row['name'] ) ) {
				continue;
			}
			$description = (string) ( $row['description'] ?? '' );
			$out[]       = [
				'name'        => (string) $row['name'],
				'description' => $description,
				'price'       => self::number( $row['price'] ?? null ),
				'per'         => (bool) preg_match( '/^per\b/i', $description ),
			];
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Questions
	 * ------------------------------------------------------------------ */

	public static function options( string $set ): array {
		$sets = [
			'bathrooms'     => [ '1' => '1', '2' => '2', '3' => '3', '4+' => '4 or more' ],
			'bedrooms'      => [ '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5+' => '5 or more' ],
			'property_type' => [ 'house' => 'House', 'flat' => 'Flat', 'bungalow' => 'Bungalow', 'maisonette' => 'Maisonette', 'other' => 'Other' ],
			'frequency'     => [ 'weekly' => 'Weekly', 'fortnightly' => 'Fortnightly', 'four_weekly' => 'Every four weeks' ],
			'furnished'     => [ 'unfurnished' => 'Unfurnished', 'furnished' => 'Furnished' ],
			'condition'     => [
				'good'      => 'Good — generally clean and tidy',
				'average'   => 'Average — some build-up in places',
				'poor'      => 'Poor — heavy grease, limescale or dirt',
				'very_poor' => 'Very poor — needs a specialist clean',
			],
			'changeover'    => [ 'one_off' => 'Just this once', 'weekly' => 'Weekly', 'several' => 'Several times a week', 'ad_hoc' => 'As bookings come in' ],
			'parking'       => [ 'free' => 'Free parking nearby', 'paid' => 'Paid parking only', 'permit' => 'Permit needed (visitor permit)' ],
			'pets'          => [ 'none' => 'No pets', 'yes' => 'Yes, there are pets at home' ],
			'access'        => [ 'home' => 'I\'ll be home to let you in', 'key_safe' => 'Key safe', 'key_collection' => 'Collect keys (agent or neighbour)' ],
		];
		return $sets[ $set ] ?? [];
	}

	/** Question definitions for a service, in display order. */
	public static function fields( array $service ): array {
		$select = fn( string $name, string $label, bool $required = true, string $help = '' ) => [
			'name'     => $name,
			'label'    => $label,
			'type'     => 'select',
			'options'  => self::options( $name ),
			'required' => $required,
			'help'     => $help,
		];
		$size = fn( string $label, bool $required = true ) => [ 'name' => 'size', 'label' => $label, 'type' => 'size', 'required' => $required ];

		$bathrooms = $select( 'bathrooms', 'Bathrooms' );
		$by_profile = [
			'regular'  => [ $size( 'Property size' ), $bathrooms, $select( 'property_type', 'Property type' ), $select( 'frequency', 'How often?' ) ],
			'hours'    => [ $size( 'How long do you need us for?' ), $select( 'bedrooms', 'Bedrooms' ), $bathrooms, $select( 'property_type', 'Property type' ) ],
			'deep'     => [ $size( 'Property size' ), $bathrooms, $select( 'condition', 'Current condition' ) ],
			'tenancy'  => [ $size( 'Property size' ), $bathrooms, $select( 'furnished', 'Furnished or unfurnished?' ), $select( 'condition', 'Current condition' ) ],
			'oven'     => [ $size( 'Oven type' ) ],
			'items'    => [ [ 'name' => 'quantities', 'label' => 'What needs cleaning?', 'type' => 'quantities', 'required' => true ] ],
			'holiday'  => [ $size( 'Property size' ), $bathrooms, $select( 'changeover', 'How often do you need changeovers?' ) ],
			'standard' => [ $size( 'Property size' ), $bathrooms ],
			'bespoke'  => [ $size( 'Size of the job', false ) ],
			'other'    => [],
		];
		$fields = $by_profile[ $service['profile'] ] ?? $by_profile['standard'];
		if ( ! $service['sizes'] ) {
			$fields = array_values( array_filter( $fields, fn( $f ) => ! in_array( $f['type'], [ 'size', 'quantities' ], true ) ) );
		}

		$bespoke  = in_array( $service['profile'], [ 'bespoke', 'other' ], true );
		$fields[] = [
			'name'        => 'notes',
			'label'       => $bespoke ? 'Tell us about the job' : 'Anything else we should know? (optional)',
			'type'        => 'textarea',
			'required'    => $bespoke,
			'placeholder' => $bespoke ? 'Size, type of property or premises, what needs cleaning, how often…' : '',
		];
		$fields[] = $select( 'parking', 'Parking' );
		$fields[] = $select( 'pets', 'Pets' );
		$fields[] = $select( 'access', 'How will we get in?' );
		$fields[] = [
			'name'     => 'photos',
			'label'    => 'Photos (optional)',
			'type'     => 'photos',
			'required' => false,
			'help'     => 'Up to 6 photos help us price accurately. Required if the condition is poor or very poor.',
		];

		return apply_filters( 'cc_bookings_form_fields', $fields, $service );
	}

	/**
	 * Clean the posted answers for a service.
	 *
	 * @return array{0: array, 1: array<string, string>} [ answers, errors keyed by field ]
	 */
	public static function sanitize_answers( array $service, array $raw ): array {
		$answers = [];
		$errors  = [];
		foreach ( self::fields( $service ) as $field ) {
			$name  = $field['name'];
			$value = $raw[ $name ] ?? null;
			switch ( $field['type'] ) {
				case 'select':
					$value = is_scalar( $value ) ? (string) $value : '';
					if ( isset( $field['options'][ $value ] ) ) {
						$answers[ $name ] = $value;
					} elseif ( $field['required'] ) {
						$errors[ $name ] = sprintf( 'Please choose: %s', strtolower( $field['label'] ) );
					}
					break;
				case 'size':
					if ( is_numeric( $value ) && isset( $service['sizes'][ (int) $value ] ) ) {
						$answers['size'] = (int) $value;
					} elseif ( $field['required'] ) {
						$errors['size'] = sprintf( 'Please choose: %s', strtolower( $field['label'] ) );
					}
					break;
				case 'quantities':
					$quantities = [];
					foreach ( (array) $value as $index => $qty ) {
						$qty = (int) $qty;
						if ( $qty > 0 && isset( $service['sizes'][ (int) $index ] ) ) {
							$quantities[ (int) $index ] = min( 20, $qty );
						}
					}
					if ( $quantities ) {
						$answers['quantities'] = $quantities;
					} else {
						$errors['quantities'] = 'Please tell us at least one thing that needs cleaning';
					}
					break;
				case 'textarea':
					$value = is_scalar( $value ) ? mb_substr( sanitize_textarea_field( (string) $value ), 0, 2000 ) : '';
					if ( '' !== $value ) {
						$answers[ $name ] = $value;
					} elseif ( $field['required'] ) {
						$errors[ $name ] = 'Please tell us a little about the job';
					}
					break;
			}
		}

		$addons = [];
		foreach ( (array) ( $raw['addons'] ?? [] ) as $index => $qty ) {
			$qty = (int) $qty;
			if ( $qty > 0 && isset( $service['addons'][ (int) $index ] ) ) {
				$addons[ (int) $index ] = $service['addons'][ (int) $index ]['per'] ? min( 20, $qty ) : 1;
			}
		}
		if ( $addons ) {
			$answers['addons'] = $addons;
		}

		return [ $answers, $errors ];
	}

	/** Human-readable property details: [ [ label, value ], … ]. */
	public static function summary( array $service, array $answers ): array {
		$lines = [];
		foreach ( self::fields( $service ) as $field ) {
			$name = $field['name'];
			if ( 'size' === $field['type'] && isset( $answers['size'] ) ) {
				$lines[] = [ $field['label'], $service['sizes'][ $answers['size'] ]['label'] ?? '' ];
			} elseif ( 'quantities' === $field['type'] && ! empty( $answers['quantities'] ) ) {
				$parts = [];
				foreach ( $answers['quantities'] as $index => $qty ) {
					$parts[] = ( $service['sizes'][ $index ]['label'] ?? '' ) . ' × ' . $qty;
				}
				$lines[] = [ $field['label'], implode( ', ', $parts ) ];
			} elseif ( 'select' === $field['type'] && isset( $answers[ $name ] ) ) {
				$lines[] = [ $field['label'], $field['options'][ $answers[ $name ] ] ?? $answers[ $name ] ];
			} elseif ( 'textarea' === $field['type'] && ! empty( $answers[ $name ] ) ) {
				$lines[] = [ rtrim( str_replace( ' (optional)', '', $field['label'] ), '?' ), $answers[ $name ] ];
			}
		}
		return $lines;
	}

	/** Chosen add-ons as "Name × 2" strings (no prices). */
	public static function addon_names( array $service, array $answers ): array {
		$out = [];
		foreach ( (array) ( $answers['addons'] ?? [] ) as $index => $qty ) {
			if ( isset( $service['addons'][ $index ] ) ) {
				$out[] = $service['addons'][ $index ]['name'] . ( $qty > 1 ? ' × ' . $qty : '' );
			}
		}
		return $out;
	}

	/**
	 * Data for the quote form script. Never includes prices: they only come back from the price endpoint.
	 */
	public static function public_config(): array {
		$services = [];
		foreach ( self::services() as $service ) {
			$services[] = [
				'slug'    => $service['slug'],
				'name'    => $service['name'],
				'bespoke' => 'bespoke' === $service['mode'],
				'sizes'   => array_map( fn( $s ) => [ 'label' => $s['label'], 'note' => $s['note'], 'quote' => null === $s['price'] ], $service['sizes'] ),
				'addons'  => array_map( fn( $a ) => [ 'name' => $a['name'], 'description' => $a['description'], 'per' => $a['per'], 'quote' => null === $a['price'] ], $service['addons'] ),
				'fields'  => array_map( [ self::class, 'public_field' ], self::fields( $service ) ),
			];
		}
		return $services;
	}

	/** Options as [ value, label ] pairs so their order survives JSON. */
	private static function public_field( array $field ): array {
		$field = array_intersect_key( $field, array_flip( [ 'name', 'label', 'type', 'options', 'required', 'help', 'placeholder' ] ) );
		if ( isset( $field['options'] ) ) {
			$field['options'] = array_map( fn( $value, $label ) => [ (string) $value, $label ], array_keys( $field['options'] ), $field['options'] );
		}
		return $field;
	}
}

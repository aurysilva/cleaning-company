<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Saving what a cleaner edits in the setup wizard and the portal. Availability, areas and services are stored in
 * the dispatcher's own fields (cleaners.areas / districts / services / has_car / max_jobs_per_day and
 * wp_cc_cleaner_availability), so changes affect future offers and booking slots straight away.
 * Every method takes the unslashed POST array and returns true or a WP_Error with a message for the cleaner.
 */
final class Cleaner_Profile {
	public static function areas(): array {
		$out = [];
		foreach ( get_posts( [ 'post_type' => 'cc_area', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ] ) as $post ) {
			$out[ $post->ID ] = $post->post_title;
		}
		return $out;
	}

	public static function services(): array {
		$out = [];
		foreach ( Catalog::services() as $slug => $service ) {
			if ( Catalog::OTHER !== $slug ) {
				$out[ $slug ] = $service['name'];
			}
		}
		return $out;
	}

	private static function actor( array $cleaner ): string {
		return Cleaner_Accounts::current() ? 'cleaner:' . (int) $cleaner['id'] : '';
	}

	/** @return true|\WP_Error */
	public static function save_details( array $cleaner, array $post ) {
		$name     = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) ( $post['name'] ?? '' ) ) ) );
		$phone    = trim( preg_replace( '/[^0-9+ ]/', '', (string) ( $post['phone'] ?? '' ) ) );
		$address  = mb_substr( sanitize_text_field( (string) ( $post['address_1'] ?? '' ) ), 0, 200 );
		$address2 = mb_substr( sanitize_text_field( (string) ( $post['address_2'] ?? '' ) ), 0, 200 );
		$town     = mb_substr( sanitize_text_field( (string) ( $post['town'] ?? '' ) ), 0, 100 );
		$postcode = Postcodes::normalise( (string) ( $post['postcode'] ?? '' ) );
		$errors   = [];
		if ( mb_strlen( $name ) < 2 || ! str_contains( $name, ' ' ) ) {
			$errors[] = 'Enter your full name (first and last name).';
		}
		if ( ! preg_match( '/^\+447\d{9}$/', Sms::normalise( $phone ) ) ) {
			$errors[] = 'Enter a UK mobile number, e.g. 07700 900123.';
		}
		if ( mb_strlen( $address ) < 3 || mb_strlen( $town ) < 2 ) {
			$errors[] = 'Enter your home address and town.';
		}
		if ( ! Postcodes::is_full( $postcode ) ) {
			$errors[] = 'Enter your full postcode, e.g. HU5 3AB.';
		}
		if ( $errors ) {
			return new \WP_Error( 'details', implode( ' ', $errors ) );
		}
		Db::update( 'cleaners', (int) $cleaner['id'], [ 'name' => mb_substr( $name, 0, 100 ), 'phone' => $phone ] );
		Cleaner_Accounts::update_account( (int) $cleaner['id'], [ 'address_1' => $address, 'address_2' => $address2, 'town' => $town, 'postcode' => $postcode ] );
		if ( (int) $cleaner['user_id'] ) {
			$parts = explode( ' ', $name, 2 );
			wp_update_user( [ 'ID' => (int) $cleaner['user_id'], 'first_name' => $parts[0], 'last_name' => $parts[1] ?? '', 'display_name' => $parts[0] ] );
		}
		$changed = array_filter( [ $name !== $cleaner['name'] ? 'name' : '', $phone !== $cleaner['phone'] ? 'mobile' : '' ] );
		Events::log( 'cleaner', (int) $cleaner['id'], 'details_updated', 'Contact details and address saved' . ( $changed ? ' (' . implode( ', ', $changed ) . ' changed)' : '' ), [], self::actor( $cleaner ) );
		return true;
	}

	/** @return true|\WP_Error */
	public static function save_availability( array $cleaner, array $post ) {
		$known     = self::areas();
		$areas     = array_values( array_intersect( array_map( 'intval', (array) ( $post['areas'] ?? [] ) ), array_keys( $known ) ) );
		$districts = array_values( array_unique( array_filter( array_map( fn( $d ) => Postcodes::district( $d ), preg_split( '/[\s,]+/', (string) ( $post['districts'] ?? '' ) ) ?: [] ) ) ) );
		$any       = 'any' === ( $post['hours_mode'] ?? 'set' );
		$week      = [];
		foreach ( (array) ( $post['week'] ?? [] ) as $day => $row ) {
			if ( ! empty( $row['on'] ) && (int) $day >= 1 && (int) $day <= 7 ) {
				$start = substr( (string) ( $row['start'] ?? '' ), 0, 5 );
				$end   = substr( (string) ( $row['end'] ?? '' ), 0, 5 );
				if ( ! preg_match( '/^\d{2}:\d{2}$/', $start ) || ! preg_match( '/^\d{2}:\d{2}$/', $end ) || $end <= $start ) {
					return new \WP_Error( 'hours', sprintf( '%s: the finish time must be after the start time.', Availability::DAYS[ (int) $day ] ) );
				}
				$week[ (int) $day ] = [ [ $start, $end ] ];
			}
		}
		$errors = [];
		if ( ! $areas && ! $districts ) {
			$errors[] = 'Choose at least one area you can travel to.';
		}
		if ( ! $any && ! $week ) {
			$errors[] = 'Tick the days you can work, or choose "Any time".';
		}
		if ( $errors ) {
			return new \WP_Error( 'availability', implode( ' ', $errors ) );
		}
		Db::update( 'cleaners', (int) $cleaner['id'], [
			'areas'            => $areas,
			'districts'        => $districts,
			'has_car'          => ! empty( $post['has_car'] ) ? 1 : 0,
			'max_jobs_per_day' => max( 1, min( 6, (int) ( $post['max_jobs_per_day'] ?? 2 ) ) ),
		] );
		Availability::set_week( (int) $cleaner['id'], $any ? null : $week );
		Events::log( 'cleaner', (int) $cleaner['id'], 'availability', 'Hours: ' . Availability::week_label( (int) $cleaner['id'] ) . '; areas: ' . ( implode( ', ', array_merge( array_map( fn( $id ) => $known[ $id ], $areas ), $districts ) ) ), [], self::actor( $cleaner ) );
		do_action( 'cc_cleaner_availability_changed', (int) $cleaner['id'] );
		return true;
	}

	/** @return true|\WP_Error */
	public static function add_time_off( array $cleaner, array $post ) {
		$from  = (string) ( $post['off_from'] ?? '' );
		$to    = (string) ( $post['off_to'] ?? '' ) ?: $from;
		$today = wp_date( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			return new \WP_Error( 'date', 'Choose the first and last day of your time off.' );
		}
		if ( $from < $today || $to < $from ) {
			return new \WP_Error( 'date', 'Time off must start today or later, and end on or after the first day.' );
		}
		$id = Availability::add_time_off( (int) $cleaner['id'], $from, $to, (string) ( $post['off_note'] ?? '' ) );
		if ( ! $id ) {
			return new \WP_Error( 'date', 'That time off couldn\'t be saved.' );
		}
		$label = $from . ( $to !== $from ? ' to ' . $to : '' );
		Events::log( 'cleaner', (int) $cleaner['id'], 'time_off', 'Time off added: ' . $label, [], self::actor( $cleaner ) );
		$clash = array_filter( Cleaners::jobs( (int) $cleaner['id'] ), fn( $b ) => $b['job_date'] >= $from && $b['job_date'] <= $to );
		if ( $clash ) {
			Bookings::alert(
				sprintf( '%s booked time off over %d job%s', $cleaner['name'], count( $clash ), 1 === count( $clash ) ? '' : 's' ),
				sprintf( 'Time off %s. They are still on: %s. Reassign these or check with them.', $label, implode( ', ', array_map( fn( $b ) => $b['ref'] . ' (' . Bookings::start_label( $b ) . ')', $clash ) ) ),
				[]
			);
			return new \WP_Error( 'clash', sprintf( 'Time off saved. You still have %d job%s on those days — we\'ve told the office. Please call them if you can\'t do %s.', count( $clash ), 1 === count( $clash ) ? '' : 's', 1 === count( $clash ) ? 'it' : 'them' ) );
		}
		do_action( 'cc_cleaner_availability_changed', (int) $cleaner['id'] );
		return true;
	}

	public static function remove_time_off( array $cleaner, int $id ): bool {
		foreach ( Availability::rules( (int) $cleaner['id'] )['off'] as $off ) {
			if ( $off['id'] === $id ) {
				Db::delete( 'cleaner_availability', [ 'id' => $id, 'cleaner_id' => (int) $cleaner['id'], 'kind' => 'off' ] );
				Availability::flush();
				Events::log( 'cleaner', (int) $cleaner['id'], 'time_off', 'Time off removed: ' . $off['from'] . ( $off['to'] !== $off['from'] ? ' to ' . $off['to'] : '' ), [], self::actor( $cleaner ) );
				do_action( 'cc_cleaner_availability_changed', (int) $cleaner['id'] );
				return true;
			}
		}
		return false;
	}

	/** @return true|\WP_Error */
	public static function save_services( array $cleaner, array $post ) {
		$known    = self::services();
		$services = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $post['services'] ?? [] ) ), array_keys( $known ) ) );
		if ( ! $services ) {
			return new \WP_Error( 'services', 'Tick at least one service you can do.' );
		}
		// Ticking every service is stored as "any", so new services are offered too.
		Db::update( 'cleaners', (int) $cleaner['id'], [ 'services' => count( $services ) === count( $known ) ? [] : $services ] );
		Events::log( 'cleaner', (int) $cleaner['id'], 'services', 'Services: ' . implode( ', ', array_map( fn( $s ) => $known[ $s ], $services ) ), [], self::actor( $cleaner ) );
		do_action( 'cc_cleaner_availability_changed', (int) $cleaner['id'] );
		return true;
	}

	/** @return true|\WP_Error */
	public static function save_emergency( array $cleaner, array $post ) {
		$contact = [
			'name'         => mb_substr( trim( sanitize_text_field( (string) ( $post['em_name'] ?? '' ) ) ), 0, 100 ),
			'relationship' => mb_substr( trim( sanitize_text_field( (string) ( $post['em_relationship'] ?? '' ) ) ), 0, 50 ),
			'phone'        => trim( preg_replace( '/[^0-9+ ]/', '', (string) ( $post['em_phone'] ?? '' ) ) ),
		];
		if ( mb_strlen( $contact['name'] ) < 2 || '' === $contact['relationship'] || '' === Sms::normalise( $contact['phone'] ) ) {
			return new \WP_Error( 'emergency', 'Enter your emergency contact\'s name, how they\'re related to you and their phone number.' );
		}
		if ( Sms::normalise( $contact['phone'] ) === Sms::normalise( (string) $cleaner['phone'] ) ) {
			return new \WP_Error( 'emergency', 'Your emergency contact needs a different phone number from yours.' );
		}
		Cleaner_Accounts::update_account( (int) $cleaner['id'], [ 'emergency' => $contact ] );
		Events::log( 'cleaner', (int) $cleaner['id'], 'emergency', 'Emergency contact saved', [], self::actor( $cleaner ) );
		return true;
	}

	/** Photo step / profile: optional new upload; the step needs a photo on file that isn't rejected. */
	public static function save_photo( array $cleaner, bool $require ) {
		$file = Cleaner_Docs::from_request( 'photo' );
		if ( $file ) {
			$result = Cleaner_Docs::upload( $cleaner, 'photo', $file );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$current = Cleaner_Docs::current( (int) $cleaner['id'] )['photo'] ?? null;
		if ( $require && ( ! $current || ! in_array( $current['status'], [ 'pending', 'approved' ], true ) ) ) {
			return new \WP_Error( 'photo', $current && 'rejected' === $current['status'] ? 'Please upload a new photo.' : 'Please choose a photo of yourself.' );
		}
		return $file || $require ? true : new \WP_Error( 'none', 'Choose a photo to upload.' );
	}

	/**
	 * Documents: upload whichever files were chosen; with $require, every required document must be on file.
	 *
	 * @return true|\WP_Error
	 */
	public static function save_documents( array $cleaner, bool $require ) {
		$errors   = [];
		$uploaded = 0;
		foreach ( Cleaner_Docs::document_types() as $type => $def ) {
			$file = Cleaner_Docs::from_request( 'doc_' . $type );
			if ( ! $file ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification -- the caller verified the nonce.
			$result = Cleaner_Docs::upload( $cleaner, $type, $file, sanitize_text_field( wp_unslash( (string) ( $_POST[ 'exp_' . $type ] ?? '' ) ) ) );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				++$uploaded;
			}
		}
		if ( $require ) {
			$current = Cleaner_Docs::current( (int) $cleaner['id'] );
			foreach ( Cleaner_Docs::document_types() as $type => $def ) {
				if ( Cleaner_Docs::required( $type, $cleaner ) && ! in_array( $current[ $type ]['status'] ?? '', [ 'pending', 'approved' ], true ) && ! preg_grep( '/^' . preg_quote( $def['label'], '/' ) . ':/', $errors ) ) {
					$errors[] = $def['label'] . ': please upload ' . ( 'rejected' === ( $current[ $type ]['status'] ?? '' ) ? 'a new copy.' : 'this document.' );
				}
			}
		}
		if ( $errors ) {
			return new \WP_Error( 'documents', implode( ' ', $errors ) );
		}
		if ( ! $require && ! $uploaded ) {
			return new \WP_Error( 'none', 'Choose a file to upload.' );
		}
		return true;
	}

	/** @return true|\WP_Error */
	public static function accept_terms( array $cleaner, array $post ) {
		if ( empty( $post['agree'] ) ) {
			return new \WP_Error( 'terms', 'Please tick the box to agree before you submit.' );
		}
		$terms = Cleaner_Accounts::terms( (string) $cleaner['type'] );
		$label = 'self_employed' === $terms['type'] ? 'contractor agreement' : 'cleaner terms';
		Cleaner_Accounts::update_account( (int) $cleaner['id'], [ 'terms' => [
			'type'        => $terms['type'],
			'label'       => $label,
			'version'     => $terms['version'],
			'hash'        => $terms['hash'],
			'accepted_at' => Db::now(),
			'ip'          => Security::ip(),
			'user_agent'  => mb_substr( sanitize_text_field( wp_unslash( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) ), 0, 200 ),
		] ] );
		// Keep the exact wording that was agreed (one copy per type, version and text).
		$history = (array) get_option( Cleaner_Accounts::TERMS_OPTION, [] );
		$key     = $terms['type'] . '|' . $terms['version'] . '|' . substr( $terms['hash'], 0, 16 );
		if ( ! isset( $history[ $key ] ) ) {
			$history[ $key ] = [ 'type' => $terms['type'], 'version' => $terms['version'], 'hash' => $terms['hash'], 'text' => $terms['text'], 'first_accepted' => Db::now() ];
			update_option( Cleaner_Accounts::TERMS_OPTION, $history, false );
		}
		Events::log( 'cleaner', (int) $cleaner['id'], 'terms_accepted', sprintf( 'Agreed to the %s, version %s (from IP %s)', $label, $terms['version'], Security::ip() ), [ 'hash' => $terms['hash'] ], self::actor( $cleaner ) );
		return true;
	}

	public static function mark_done( int $cleaner_id, string $step ): void {
		$account = Cleaner_Accounts::account( $cleaner_id );
		$done    = (array) $account['setup'];
		if ( ! in_array( $step, $done, true ) ) {
			$done[] = $step;
			Cleaner_Accounts::update_account( $cleaner_id, [ 'setup' => array_values( $done ) ] );
		}
	}
}

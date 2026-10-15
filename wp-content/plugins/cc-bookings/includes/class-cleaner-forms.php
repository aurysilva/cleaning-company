<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Form fields shared by the setup wizard and the portal (the caller prints the <form>, nonce and button).
 * $input holds the values just posted when a save failed, so nothing typed is lost (never passwords or bank details).
 */
final class Cleaner_Forms {
	private static function value( array $input, string $key, $fallback ) {
		return array_key_exists( $key, $input ) ? $input[ $key ] : $fallback;
	}

	public static function field( string $name, string $label, string $value = '', string $type = 'text', array $attrs = [], string $help = '' ): void {
		$id    = (string) ( $attrs['id'] ?? 'cc-f-' . sanitize_html_class( $name ) );
		$class = (string) ( $attrs['class'] ?? 'cc-field__input' );
		unset( $attrs['class'], $attrs['id'] );
		$html = '';
		foreach ( array_filter( $attrs, fn( $v ) => false !== $v ) as $attr => $val ) {
			$html .= true === $val ? ' ' . esc_attr( $attr ) : sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( (string) $val ) );
		}
		printf(
			'<div class="cc-field"><label class="cc-field__label" for="%1$s">%2$s</label>%6$s<input class="%8$s" id="%1$s" name="%3$s" type="%4$s" value="%5$s"%7$s></div>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $type ),
			esc_attr( $value ),
			$help ? '<p class="cc-field__help" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p>' : '',
			$html . ( $help ? ' aria-describedby="' . esc_attr( $id ) . '-help"' : '' ), // phpcs:ignore WordPress.Security.EscapeOutput
			esc_attr( $class )
		);
	}

	public static function details( array $cleaner, array $account, array $input = [] ): void {
		self::field( 'name', 'Full name', (string) self::value( $input, 'name', $cleaner['name'] ), 'text', [ 'autocomplete' => 'name', 'required' => true, 'maxlength' => 100 ] );
		printf( '<div class="cc-field"><span class="cc-field__label">Email</span><p class="cc-field__static">%s</p><p class="cc-field__help">This is your login. To change it, please contact the office.</p></div>', esc_html( (string) $cleaner['email'] ) );
		self::field( 'phone', 'Mobile number', (string) self::value( $input, 'phone', $cleaner['phone'] ), 'tel', [ 'autocomplete' => 'tel', 'required' => true, 'inputmode' => 'tel' ], 'We text job offers and updates to this number.' );
		echo '<fieldset class="cc-fieldset"><legend class="cc-field__label">Home address</legend>';
		self::field( 'address_1', 'Address line 1', (string) self::value( $input, 'address_1', $account['address_1'] ?? '' ), 'text', [ 'autocomplete' => 'address-line1', 'required' => true ] );
		self::field( 'address_2', 'Address line 2 (optional)', (string) self::value( $input, 'address_2', $account['address_2'] ?? '' ), 'text', [ 'autocomplete' => 'address-line2' ] );
		echo '<div class="cc-field-row">';
		self::field( 'town', 'Town or city', (string) self::value( $input, 'town', $account['town'] ?? '' ), 'text', [ 'autocomplete' => 'address-level2', 'required' => true ] );
		self::field( 'postcode', 'Postcode', (string) self::value( $input, 'postcode', $account['postcode'] ?? '' ), 'text', [ 'autocomplete' => 'postal-code', 'required' => true, 'maxlength' => 10, 'class' => 'cc-field__input cc-field__input--short' ] );
		echo '</div></fieldset>';
	}

	public static function password_fields( string $label = 'Choose a password', bool $current = false ): void {
		if ( $current ) {
			self::field( 'current_password', 'Current password', '', 'password', [ 'autocomplete' => 'current-password', 'required' => true ] );
		}
		self::field( 'password', $label, '', 'password', [ 'autocomplete' => 'new-password', 'required' => true, 'minlength' => 10, 'maxlength' => 128, 'data-cc-strength' => 'cc-strength' ], 'At least 10 characters. Mix letters, numbers and symbols, or use a phrase of 16+ characters.' );
		echo '<p class="cc-strength" id="cc-strength" aria-live="polite"></p>';
		self::field( 'password_confirm', 'Type it again', '', 'password', [ 'autocomplete' => 'new-password', 'required' => true, 'maxlength' => 128 ] );
	}

	public static function availability( array $cleaner, array $input = [] ): void {
		$areas    = Cleaner_Profile::areas();
		$chosen   = array_map( 'intval', (array) self::value( $input, 'areas', Availability::areas( $cleaner ) ) );
		$rules    = Availability::rules( (int) $cleaner['id'] );
		$fresh    = ! in_array( 'availability', (array) Cleaner_Accounts::account( (int) $cleaner['id'] )['setup'], true ) && ! $rules['weekly'];
		$weekly   = $fresh ? array_fill_keys( [ 1, 2, 3, 4, 5 ], [ [ '08:00', '17:00' ] ] ) : $rules['weekly'];
		$any      = isset( $input['hours_mode'] ) ? 'any' === $input['hours_mode'] : ( ! $fresh && ! $rules['weekly'] );
		$posted   = (array) ( $input['week'] ?? [] );

		echo '<fieldset class="cc-fieldset"><legend class="cc-field__label">Areas you can travel to</legend>';
		if ( $areas ) {
			echo '<div class="cc-checks">';
			foreach ( $areas as $id => $title ) {
				printf( '<label class="cc-check"><input type="checkbox" name="areas[]" value="%d"%s> <span>%s</span></label>', (int) $id, checked( in_array( (int) $id, $chosen, true ), true, false ), esc_html( $title ) );
			}
			echo '</div>';
		}
		$districts = self::value( $input, 'districts', implode( ', ', (array) $cleaner['districts'] ) );
		self::field( 'districts', $areas ? 'Other postcode districts (optional)' : 'Postcode districts', (string) $districts, 'text', [ 'placeholder' => 'e.g. HU5, HU6, HU10', 'autocapitalize' => 'characters' ], 'The first part of the postcode, separated by commas.' );
		$car = isset( $input['areas'] ) || isset( $input['districts'] ) ? ! empty( $input['has_car'] ) : (bool) $cleaner['has_car'];
		printf( '<label class="cc-check"><input type="checkbox" name="has_car" value="1"%s> <span>I have a car for getting to jobs</span></label>', checked( $car, true, false ) );
		echo '</fieldset>';

		echo '<fieldset class="cc-fieldset" data-cc-hours><legend class="cc-field__label">When you can work</legend>';
		printf( '<label class="cc-check"><input type="radio" name="hours_mode" value="set"%s> <span>These hours each week</span></label>', checked( ! $any, true, false ) );
		printf( '<label class="cc-check"><input type="radio" name="hours_mode" value="any"%s> <span>Any time</span></label>', checked( $any, true, false ) );
		echo '<div class="cc-week" data-cc-week>';
		foreach ( Availability::DAYS as $n => $day ) {
			$window = $weekly[ $n ][0] ?? null;
			$on     = $posted ? ! empty( $posted[ $n ]['on'] ) : (bool) $window;
			$start  = (string) ( $posted[ $n ]['start'] ?? $window[0] ?? '08:00' );
			$end    = (string) ( $posted[ $n ]['end'] ?? $window[1] ?? '17:00' );
			printf(
				'<div class="cc-week__day"><label class="cc-check"><input type="checkbox" name="week[%1$d][on]" value="1"%2$s> <span>%3$s</span></label><span class="cc-week__times"><input class="cc-field__input" type="time" name="week[%1$d][start]" value="%4$s" aria-label="%3$s start" step="900"> <span aria-hidden="true">to</span> <input class="cc-field__input" type="time" name="week[%1$d][end]" value="%5$s" aria-label="%3$s finish" step="900"></span></div>',
				(int) $n,
				checked( $on, true, false ),
				esc_html( $day ),
				esc_attr( $start ),
				esc_attr( $end )
			);
		}
		echo '</div><p class="cc-field__help">You\'re only offered jobs that fit inside these hours, including the job\'s length.</p>';
		self::field( 'max_jobs_per_day', 'Most jobs in one day', (string) self::value( $input, 'max_jobs_per_day', (string) max( 1, (int) $cleaner['max_jobs_per_day'] ) ), 'number', [ 'min' => 1, 'max' => 6, 'inputmode' => 'numeric', 'class' => 'cc-field__input cc-field__input--short' ] );
		echo '</fieldset>';
	}

	public static function timeoff( array $cleaner ): array {
		$today = wp_date( 'Y-m-d' );
		return array_values( array_filter( Availability::rules( (int) $cleaner['id'] )['off'], fn( $o ) => $o['to'] >= $today ) );
	}

	public static function timeoff_fields( array $input = [] ): void {
		echo '<div class="cc-field-row">';
		self::field( 'off_from', 'First day off', (string) ( $input['off_from'] ?? '' ), 'date', [ 'min' => wp_date( 'Y-m-d' ), 'required' => true ] );
		self::field( 'off_to', 'Last day off', (string) ( $input['off_to'] ?? '' ), 'date', [ 'min' => wp_date( 'Y-m-d' ) ] );
		echo '</div>';
		self::field( 'off_note', 'Note (optional)', (string) ( $input['off_note'] ?? '' ), 'text', [ 'maxlength' => 100, 'placeholder' => 'e.g. Holiday' ] );
	}

	public static function services( array $cleaner, array $input = [] ): void {
		$saved  = (array) $cleaner['services'];
		$all    = Cleaner_Profile::services();
		$chosen = isset( $input['services'] ) ? (array) $input['services'] : ( $saved ?: ( in_array( 'services', (array) Cleaner_Accounts::account( (int) $cleaner['id'] )['setup'], true ) ? array_keys( $all ) : [] ) );
		echo '<fieldset class="cc-fieldset"><legend class="cc-field__label">Services you can do</legend><div class="cc-checks">';
		foreach ( $all as $slug => $name ) {
			printf( '<label class="cc-check"><input type="checkbox" name="services[]" value="%s"%s> <span>%s</span></label>', esc_attr( $slug ), checked( in_array( $slug, $chosen, true ), true, false ), esc_html( $name ) );
		}
		echo '</div><p class="cc-field__help">You\'ll only be offered these kinds of job.</p></fieldset>';
	}

	public static function photo( array $cleaner ): void {
		$doc = Cleaner_Docs::current( (int) $cleaner['id'] )['photo'] ?? null;
		echo '<div class="cc-photo">';
		if ( $doc && $doc['file'] ) {
			printf( '<img class="cc-photo__img" src="%s" alt="Your profile photo" width="120" height="120">', esc_url( Cleaner_Docs::url( $doc ) ) );
		} else {
			echo '<span class="cc-photo__img cc-photo__img--empty" aria-hidden="true"></span>';
		}
		echo '<div>';
		if ( $doc ) {
			echo Cleaner_Docs::badge( $doc['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( 'rejected' === $doc['status'] && $doc['reason'] ) {
				printf( '<p class="cc-field__error">%s</p>', esc_html( $doc['reason'] ) );
			}
		}
		echo '<p class="cc-field__help">A clear, friendly head-and-shoulders photo with a plain background, no sunglasses or hats. Customers see it with your first name once you\'re approved.</p></div></div>';
		printf(
			'<div class="cc-field"><label class="cc-field__label" for="cc-f-photo">%s</label><input class="cc-field__file" id="cc-f-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp"></div>',
			$doc ? 'Choose a new photo' : 'Choose a photo'
		);
	}

	public static function emergency( array $account, array $input = [] ): void {
		$em = (array) ( $account['emergency'] ?? [] );
		self::field( 'em_name', 'Their name', (string) self::value( $input, 'em_name', $em['name'] ?? '' ), 'text', [ 'required' => true, 'maxlength' => 100 ] );
		self::field( 'em_relationship', 'How they\'re related to you', (string) self::value( $input, 'em_relationship', $em['relationship'] ?? '' ), 'text', [ 'required' => true, 'maxlength' => 50, 'placeholder' => 'e.g. Partner, parent, friend' ] );
		self::field( 'em_phone', 'Their phone number', (string) self::value( $input, 'em_phone', $em['phone'] ?? '' ), 'tel', [ 'required' => true, 'inputmode' => 'tel' ] );
	}

	public static function documents( array $cleaner ): void {
		$current = Cleaner_Docs::current( (int) $cleaner['id'] );
		foreach ( Cleaner_Docs::document_types() as $type => $def ) {
			$doc      = $current[ $type ] ?? null;
			$required = Cleaner_Docs::required( $type, $cleaner );
			$approved = Cleaner_Docs::approved( (int) $cleaner['id'], $type );
			echo '<fieldset class="cc-fieldset cc-doc">';
			printf( '<legend class="cc-field__label">%s%s</legend>', esc_html( $def['label'] ), $required ? '' : ' <span class="cc-small">(optional)</span>' );
			printf( '<p class="cc-field__help">%s</p>', esc_html( $def['help'] ) );
			if ( $doc ) {
				printf(
					'<p class="cc-doc__status">%s <a href="%s" target="_blank" rel="noopener">View</a>%s</p>',
					Cleaner_Docs::badge( $doc['status'] ), // phpcs:ignore WordPress.Security.EscapeOutput
					esc_url( Cleaner_Docs::url( $doc ) ),
					$doc['expires_on'] ? ' · ' . esc_html( ( 'expired' === $doc['status'] ? 'Expired ' : 'Expires ' ) . wp_date( 'j M Y', strtotime( $doc['expires_on'] . ' 12:00' ) ) ) : ''
				);
				if ( 'rejected' === $doc['status'] && $doc['reason'] ) {
					printf( '<p class="cc-field__error">%s</p>', esc_html( $doc['reason'] ) );
				}
				if ( $approved && (int) $approved['id'] !== (int) $doc['id'] ) {
					echo '<p class="cc-small">Your approved copy stays in place until we check the new one.</p>';
				}
			}
			printf(
				'<div class="cc-field"><label class="cc-field__label cc-field__label--light" for="cc-f-doc-%1$s">%2$s</label><input class="cc-field__file" id="cc-f-doc-%1$s" type="file" name="doc_%1$s" accept="application/pdf,image/jpeg,image/png,image/webp,image/heic"></div>',
				esc_attr( $type ),
				$doc ? 'Upload a new copy' : 'Choose a file (PDF or photo)'
			);
			if ( 'none' !== $def['expiry'] ) {
				self::field( 'exp_' . $type, 'required' === $def['expiry'] ? 'Expiry date' : 'Expiry or recheck date (if it has one)', '', 'date', [ 'min' => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) ] );
			}
			echo '</fieldset>';
		}
		echo '<p class="cc-field__help">PDF, JPG, PNG, WebP or HEIC, up to 10MB each. Photos of documents are fine if every corner is visible and the text is readable. Files are stored privately and only our office team can see them.</p>';
	}

	public static function bank( array $account, array $input = [] ): void {
		$masked = Cleaner_Bank::masked( $account );
		if ( $masked ) {
			printf( '<p class="cc-bank__current">Account on file: <strong>%s</strong>%s</p>', esc_html( $masked ), $account['bank_updated_at'] ? ' <span class="cc-small">(updated ' . esc_html( wp_date( 'j M Y', Db::timestamp( $account['bank_updated_at'] ) ) ) . ')</span>' : '' );
		}
		self::field( 'bank_holder', 'Name on the account', (string) ( $input['bank_holder'] ?? '' ), 'text', [ 'autocomplete' => 'off', 'required' => ! $masked, 'maxlength' => 70 ] );
		echo '<div class="cc-field-row">';
		self::field( 'bank_sort', 'Sort code', '', 'text', [ 'autocomplete' => 'off', 'inputmode' => 'numeric', 'required' => ! $masked, 'maxlength' => 8, 'placeholder' => '12-34-56', 'class' => 'cc-field__input cc-field__input--short' ] );
		self::field( 'bank_account', 'Account number', '', 'text', [ 'autocomplete' => 'off', 'inputmode' => 'numeric', 'required' => ! $masked, 'maxlength' => 10, 'placeholder' => '8 digits', 'class' => 'cc-field__input cc-field__input--short' ] );
		echo '</div><p class="cc-field__help">A UK bank or building society account in your name. Your details are encrypted, and we only ever show the last 4 digits.</p>';
	}

	public static function terms( array $cleaner, array $account ): void {
		$terms = Cleaner_Accounts::terms( (string) $cleaner['type'] );
		$label = Cleaner_Accounts::terms_label( $terms['type'] );
		printf( '<h3 class="cc-terms__title">%s <span class="cc-small">(version %s)</span></h3>', esc_html( $label ), esc_html( $terms['version'] ) );
		printf( '<div class="cc-terms" tabindex="0" role="region" aria-label="%s">%s</div>', esc_attr( $label ), wp_kses_post( wpautop( esc_html( $terms['text'] ) ) ) );
		$agreed = (array) ( $account['terms'] ?? [] );
		if ( ! empty( $agreed['accepted_at'] ) ) {
			printf( '<p class="cc-small">You agreed to version %s on %s.</p>', esc_html( (string) $agreed['version'] ), esc_html( wp_date( 'j M Y, H:i', Db::timestamp( $agreed['accepted_at'] ) ) ) );
		}
		printf( '<label class="cc-check cc-check--strong"><input type="checkbox" name="agree" value="1" required> <span>I, %s, have read and agree to the %s.</span></label>', esc_html( (string) $cleaner['name'] ), esc_html( strtolower( $label ) ) );
	}
}

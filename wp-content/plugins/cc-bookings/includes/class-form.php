<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * The multi-step quote form ({cc_quote_form} in the theme). The markup holds the step shells; assets/quote-form.js
 * builds the service-specific questions from the JSON config. No prices appear in this HTML.
 */
final class Form {
	const STEPS = [
		'service' => 'Service',
		'details' => 'Property',
		'extras'  => 'Extras',
		'date'    => 'Date',
		'contact' => 'Your details',
	];

	public static function render(): string {
		static $html = null;
		if ( null !== $html ) {
			return $html;
		}

		wp_enqueue_script( 'cc-bookings-quote', CC_BOOKINGS_URL . 'assets/quote-form.js', [], Frontend::asset_version( 'assets/quote-form.js' ), [ 'in_footer' => true, 'strategy' => 'defer' ] );
		if ( Security::turnstile_site_key() ) {
			wp_enqueue_script( 'cc-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', [], null, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		}

		$phone  = function_exists( 'cc_phone_display' ) ? cc_phone_display() : '';
		$config = [
			'rest'       => esc_url_raw( rest_url( Rest::NS . '/' ) ),
			'services'   => Catalog::public_config(),
			'districts'  => Postcodes::covered_districts(),
			'turnstile'  => Security::turnstile_site_key(),
			'maxPhotos'  => Files::MAX_FILES,
			'phone'      => $phone,
			'privacyUrl' => get_privacy_policy_url(),
		];

		$progress = '';
		$i        = 0;
		foreach ( self::STEPS as $key => $label ) {
			$progress .= sprintf( '<li class="cc-quote__progress-item%s" data-progress="%s"><span class="cc-quote__progress-num">%d</span><span class="cc-quote__progress-label">%s</span></li>', 0 === $i ? ' is-current' : '', esc_attr( $key ), ++$i, esc_html( $label ) );
		}

		$field = fn( string $name, string $label, string $type = 'text', string $attrs = '', string $help = '' ) => sprintf(
			'<div class="cc-quote__field" data-field="%1$s"><label for="cc-q-%1$s">%2$s</label><input id="cc-q-%1$s" name="%1$s" type="%3$s" %4$s>%5$s<p class="cc-quote__field-error" hidden></p></div>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			$attrs,
			$help ? '<p class="cc-quote__help">' . esc_html( $help ) . '</p>' : ''
		);

		$html = '<div class="cc-quote" data-cc-quote>'
			. '<script type="application/json" class="cc-quote__config">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>'
			. '<noscript><p class="cc-quote__noscript">Our instant quote form needs JavaScript switched on. You can also call us' . ( $phone ? ' on ' . esc_html( $phone ) : '' ) . '.</p></noscript>'
			. '<form class="cc-quote__form" novalidate hidden>'
			. '<ol class="cc-quote__progress">' . $progress . '</ol>'
			. '<div class="cc-quote__progress-bar" aria-hidden="true"><span></span></div>'

			. '<fieldset class="cc-quote__step" data-step="service">'
			. '<legend class="cc-quote__title" tabindex="-1">What would you like cleaned?</legend>'
			. '<div class="cc-quote__services" role="radiogroup" aria-label="Service" data-services></div>'
			. '<p class="cc-quote__field-error" data-error="service" hidden></p>'
			. $field( 'postcode', 'Postcode of the property', 'text', 'autocomplete="postal-code" autocapitalize="characters" inputmode="text" placeholder="e.g. HU5 2AB" maxlength="10" required' )
			. '<p class="cc-quote__coverage" aria-live="polite" data-coverage></p>'
			. '<label class="cc-quote__check" data-ask-anyway hidden><input type="checkbox" name="ask_anyway" value="1"><span>Ask us anyway — we\'ll see if we can help</span></label>'
			. '</fieldset>'

			. '<fieldset class="cc-quote__step" data-step="details" hidden>'
			. '<legend class="cc-quote__title" tabindex="-1">Tell us about the property</legend>'
			. '<div class="cc-quote__fields" data-fields></div>'
			. '</fieldset>'

			. '<fieldset class="cc-quote__step" data-step="extras" hidden>'
			. '<legend class="cc-quote__title" tabindex="-1">Would you like any extras?</legend>'
			. '<div class="cc-quote__addons" data-addons></div>'
			. '</fieldset>'

			. '<fieldset class="cc-quote__step" data-step="date" hidden>'
			. '<legend class="cc-quote__title" tabindex="-1">Choose a date and arrival time</legend>'
			. '<p class="cc-quote__lead" data-date-lead>Greyed-out times are fully booked.</p>'
			. '<div class="cc-quote__calendar" data-calendar></div>'
			. '<p class="cc-quote__field-error" data-error="date" hidden></p>'
			. '</fieldset>'

			. '<fieldset class="cc-quote__step" data-step="contact" hidden>'
			. '<legend class="cc-quote__title" tabindex="-1">Your details</legend>'
			. '<div class="cc-quote__grid">'
			. $field( 'name', 'Full name', 'text', 'autocomplete="name" required maxlength="200"' )
			. $field( 'email', 'Email', 'email', 'autocomplete="email" required maxlength="200"' )
			. $field( 'phone', 'Mobile', 'tel', 'autocomplete="tel" required maxlength="40"', 'For text reminders before your clean.' )
			. $field( 'address_1', 'Address line 1', 'text', 'autocomplete="address-line1" required maxlength="200"' )
			. $field( 'address_2', 'Address line 2 (optional)', 'text', 'autocomplete="address-line2" maxlength="200"' )
			. $field( 'town', 'Town', 'text', 'autocomplete="address-level2" required maxlength="100"' )
			. '</div>'
			. '<div class="cc-quote__field" data-field="access_notes"><label for="cc-q-access_notes">Access notes (optional)</label><textarea id="cc-q-access_notes" name="access_notes" rows="3" maxlength="2000" placeholder="Key safe code, parking tips, alarm…"></textarea></div>'
			. '<label class="cc-quote__check"><input type="checkbox" name="marketing" value="1"><span>Send me occasional offers and cleaning tips (optional)</span></label>'
			. '<div class="cc-quote__hp" aria-hidden="true"><label for="cc-q-website">Website</label><input id="cc-q-website" name="' . esc_attr( Security::HONEYPOT ) . '" type="text" tabindex="-1" autocomplete="off"></div>'
			. '<div class="cc-quote__turnstile" data-turnstile></div>'
			. '<p class="cc-quote__small">We use your details to quote and arrange your clean. See our <a href="' . esc_url( get_privacy_policy_url() ?: home_url( '/privacy-policy/' ) ) . '">privacy policy</a>.</p>'
			. '</fieldset>'

			. '<div class="cc-quote__step cc-quote__done" data-step="done" hidden>'
			. '<p class="cc-quote__title" tabindex="-1">Thanks — check your email</p>'
			. '<p data-done-message></p>'
			. '</div>'

			. '<div class="cc-quote__summary" data-summary hidden aria-live="polite"></div>'
			. '<p class="cc-quote__error" role="alert" data-form-error hidden></p>'
			. '<div class="cc-quote__nav">'
			. '<button type="button" class="cc-btn cc-btn--outline cc-quote__back" data-back hidden>Back</button>'
			. '<button type="button" class="cc-btn cc-btn--primary cc-quote__next" data-next>Next</button>'
			. '<button type="submit" class="cc-btn cc-btn--primary cc-quote__submit" data-submit hidden>Get my quote</button>'
			. '</div>'
			. self::account_notice()
			. '</form>'
			. '</div>';

		return $html;
	}

	/** The line under the submit button (Client accounts setting "form_notice"). */
	private static function account_notice(): string {
		$text = class_exists( __NAMESPACE__ . '\\Client_Accounts' ) ? trim( (string) Client_Accounts::opt( 'form_notice' ) ) : '';
		return '' === $text ? '' : '<p class="cc-quote__small cc-quote__account-note" data-form-notice hidden>' . esc_html( $text ) . '</p>';
	}
}

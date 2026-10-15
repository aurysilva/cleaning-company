<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 4: regular cleans. A regular quote (Regular House Cleaning with a frequency, or a weekly holiday-let
 * changeover) shows the per-visit price and a card authority tick on the quote page. Its first visit is paid through
 * Stripe Checkout with the card saved for off-session use (customer_creation=always, setup_future_usage=off_session).
 * The paid booking starts a plan (wp_cc_plans, own installer) that keeps a rolling horizon of visit bookings
 * (Plans). Each visit is charged off-session before it (Plan_Charges): success confirms it through
 * cc_booking_confirmed, so dispatch, reminders and emails run as for any booking; failure holds it as
 * "payment due" with a pay link, and it is released if still unpaid nearer the time. Customers manage the plan
 * from any visit's booking page (Plan_Manage); the admin has Bookings → Regular plans.
 *
 * Visit statuses (added to bookings): scheduled → confirmed | payment_due | skipped; payment_due → confirmed |
 * skipped | cancelled (released).
 *
 * Hooks fired:
 *   cc_plan_created( $plan_id, $booking_id )
 *   cc_plan_visit_created( $plan_id, $booking_id )
 *   cc_plan_changed( $plan_id, $change, $actor )                 skip|pause|resume|frequency|cancel|card
 *   cc_plan_visit_paid( $booking_id, $payment_id )
 *   cc_plan_visit_payment_failed( $booking_id, $code, $message )
 *   cc_plan_visit_released( $booking_id )
 */
final class Recurring {
	const SCHEMA_VERSION = '1';
	const SCHEMA_OPTION  = 'cc_bookings_recurring_schema';
	const TABLE          = 'plans';

	const FREQUENCIES = [
		'weekly'      => [ 'days' => 7, 'label' => 'Weekly', 'every' => 'every week' ],
		'fortnightly' => [ 'days' => 14, 'label' => 'Fortnightly', 'every' => 'every two weeks' ],
		'four_weekly' => [ 'days' => 28, 'label' => 'Every four weeks', 'every' => 'every four weeks' ],
	];

	const DEFAULTS = [
		'enabled'                => 1,
		'holiday_lets'           => 1,
		'horizon_visits'         => 4,
		'charge_hours'           => 48,
		'release_hours'          => 24,
		'adjust_weekly_pct'      => 0,
		'adjust_fortnightly_pct' => 0,
		'adjust_four_weekly_pct' => 0,
		'customer_changes'       => 1,
	];

	const VISIT_STATUSES = [ 'scheduled' => 'Scheduled (not charged yet)', 'payment_due' => 'Payment due', 'skipped' => 'Skipped' ];

	/** Visits still to happen, paid or not. */
	const UPCOMING = [ 'scheduled', 'payment_due', 'confirmed', 'dispatching', 'assigned' ];

	const PLAN_STATUSES = [ 'active' => 'Active', 'paused' => 'Paused', 'cancelled' => 'Cancelled' ];

	public static function init(): void {
		self::maybe_install();
		add_filter( 'cc_bookings_statuses', [ self::class, 'statuses' ], 10, 2 );
		add_filter( 'cc_bookings_transitions', [ self::class, 'transitions' ], 10, 2 );
		add_filter( 'cc_bookings_customer_statuses', [ self::class, 'customer_statuses' ] );
		add_filter( 'cc_bookings_active_booking_statuses', [ self::class, 'active_statuses' ] );
		add_filter( 'cc_bookings_email_defaults', [ self::class, 'email_defaults' ] );
		add_filter( 'cc_bookings_email_vars', [ self::class, 'email_vars' ], 20, 3 );
		add_filter( 'cc_bookings_price', [ self::class, 'price' ], 10, 5 );
		add_filter( 'cc_bookings_price_preview', [ self::class, 'price_preview' ], 10, 4 );
		add_filter( 'cc_bookings_checkout_consent', [ self::class, 'checkout_consent' ], 10, 3 );
		add_filter( 'cc_bookings_checkout_params', [ self::class, 'checkout_params' ], 10, 2 );
		add_filter( 'cc_bookings_quote_regular_note', [ self::class, 'regular_note' ], 10, 2 );
		add_filter( 'cc_bookings_quote_pay_label', [ self::class, 'pay_label' ], 10, 2 );
		add_action( 'cc_bookings_quote_price_after', [ self::class, 'quote_price_after' ] );
		add_action( 'cc_bookings_quote_pay_fields', [ self::class, 'quote_pay_fields' ] );
		add_action( 'cc_booking_confirmed', [ self::class, 'on_confirmed' ], 5, 2 );

		Plans::init();
		Plan_Charges::init();
		Plan_Manage::init();
		if ( is_admin() ) {
			Admin\Plans_Screen::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cc-bookings recurring', Recurring_Cli::class );
		}
	}

	/* ---------------------------------------------------------------------
	 * Schema (own version option; the core schema version is untouched)
	 * ------------------------------------------------------------------ */

	public static function maybe_install(): void {
		if ( get_option( self::SCHEMA_OPTION ) !== self::SCHEMA_VERSION && get_option( Schema::OPTION ) ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . Schema::table( self::TABLE ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
ref varchar(20) NOT NULL,
status varchar(20) NOT NULL DEFAULT 'active',
frequency varchar(20) NOT NULL DEFAULT 'weekly',
interval_days smallint(5) unsigned NOT NULL DEFAULT 7,
quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
first_booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
service_id bigint(20) unsigned NOT NULL DEFAULT 0,
service_slug varchar(100) NOT NULL DEFAULT '',
service_name varchar(200) NOT NULL DEFAULT '',
answers longtext NULL,
slot varchar(20) NOT NULL DEFAULT '',
hours decimal(5,2) NOT NULL DEFAULT 0,
cleaners tinyint(3) unsigned NOT NULL DEFAULT 1,
items longtext NULL,
price_pence int(11) NOT NULL DEFAULT 0,
next_date date NULL,
customer_name varchar(200) NOT NULL DEFAULT '',
email varchar(200) NOT NULL DEFAULT '',
phone varchar(40) NOT NULL DEFAULT '',
address_1 varchar(200) NOT NULL DEFAULT '',
address_2 varchar(200) NOT NULL DEFAULT '',
town varchar(100) NOT NULL DEFAULT '',
postcode varchar(10) NOT NULL DEFAULT '',
district varchar(6) NOT NULL DEFAULT '',
access_notes text NULL,
marketing_opt_in tinyint(1) NOT NULL DEFAULT 0,
provider varchar(20) NOT NULL DEFAULT 'stripe',
stripe_customer varchar(191) NOT NULL DEFAULT '',
stripe_payment_method varchar(191) NOT NULL DEFAULT '',
card_label varchar(80) NOT NULL DEFAULT '',
consent text NULL,
meta longtext NULL,
admin_note text NULL,
is_test tinyint(1) NOT NULL DEFAULT 0,
paused_at datetime NULL,
cancelled_at datetime NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY ref (ref),
KEY status (status),
KEY email (email),
KEY quote_id (quote_id)
) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/* ---------------------------------------------------------------------
	 * Settings and frequencies
	 * ------------------------------------------------------------------ */

	public static function setting( string $key ) {
		return Settings::get( 'recurring.' . $key, self::DEFAULTS[ $key ] ?? null );
	}

	public static function interval( string $frequency ): int {
		return (int) ( self::FREQUENCIES[ $frequency ]['days'] ?? 7 );
	}

	public static function label( string $frequency ): string {
		return self::FREQUENCIES[ $frequency ]['label'] ?? ucfirst( str_replace( '_', ' ', $frequency ) );
	}

	public static function every( string $frequency ): string {
		return self::FREQUENCIES[ $frequency ]['every'] ?? strtolower( self::label( $frequency ) );
	}

	public static function add_days( string $date, int $days ): string {
		$dt = date_create_immutable( $date, wp_timezone() );
		return $dt ? $dt->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' ) : $date;
	}

	public static function charge_hours(): int {
		return max( 2, (int) self::setting( 'charge_hours' ) );
	}

	public static function release_hours(): int {
		return max( 1, min( self::charge_hours() - 1, (int) self::setting( 'release_hours' ) ) );
	}

	/**
	 * Plan frequency for a job, or '' when it isn't a regular clean. `_plan_frequency` in the answers overrides
	 * the form answer (used when pricing a plan after the customer changes how often).
	 */
	public static function frequency_for( string $service_slug, array $answers ): string {
		$override = (string) ( $answers['_plan_frequency'] ?? '' );
		if ( isset( self::FREQUENCIES[ $override ] ) ) {
			return $override;
		}
		if ( ! self::setting( 'enabled' ) ) {
			return '';
		}
		$profile = Catalog::get( $service_slug )['profile'] ?? '';
		if ( 'regular' === $profile ) {
			$frequency = (string) ( $answers['frequency'] ?? '' );
			return isset( self::FREQUENCIES[ $frequency ] ) ? $frequency : '';
		}
		if ( 'holiday' === $profile && self::setting( 'holiday_lets' ) && 'weekly' === ( $answers['changeover'] ?? '' ) ) {
			return 'weekly';
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Statuses
	 * ------------------------------------------------------------------ */

	public static function statuses( $statuses, $kind ) {
		return 'booking' === $kind ? (array) $statuses + self::VISIT_STATUSES : $statuses;
	}

	public static function transitions( $map, $kind ) {
		if ( 'booking' !== $kind ) {
			return $map;
		}
		$map['scheduled']   = [ 'confirmed', 'payment_due', 'skipped', 'cancelled' ];
		$map['payment_due'] = [ 'confirmed', 'skipped', 'cancelled', 'scheduled' ];
		$map['skipped']     = [];
		return $map;
	}

	public static function customer_statuses( $labels ) {
		$labels['booking'] = (array) ( $labels['booking'] ?? [] ) + [ 'scheduled' => 'Scheduled', 'payment_due' => 'Payment due', 'skipped' => 'Skipped' ];
		return $labels;
	}

	/** Scheduled and payment-due visits keep their slot and cleaner capacity. */
	public static function active_statuses( $statuses ) {
		return array_values( array_unique( array_merge( (array) $statuses, [ 'scheduled', 'payment_due' ] ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Pricing
	 * ------------------------------------------------------------------ */

	/** `cc_bookings_price`: optional per-frequency adjustment (settings recurring.adjust_{frequency}_pct). */
	public static function price( $result, $service = null, $answers = [], $date = '', $slot = '' ) {
		if ( ! is_array( $result ) || ! empty( $result['bespoke'] ) || ! is_array( $service ) || null === ( $result['total'] ?? null ) ) {
			return $result;
		}
		$frequency = self::frequency_for( (string) $service['slug'], (array) $answers );
		$pct       = '' === $frequency ? 0.0 : (float) self::setting( 'adjust_' . $frequency . '_pct' );
		if ( abs( $pct ) < 0.001 ) {
			return $result;
		}
		$total  = (int) $result['total'];
		$amount = (int) ( round( $total * $pct / 10000 ) * 100 );
		if ( $amount < 0 ) {
			$amount = max( $amount, min( 0, Money::pence( $service['minimum'] ) - $total ) );
		}
		if ( 0 === $amount ) {
			return $result;
		}
		$pct_label         = ( $pct > 0 ? '+' : '−' ) . rtrim( rtrim( number_format( abs( $pct ), 1 ), '0' ), '.' ) . '%';
		$result['items'][] = Pricing::line( 'adjustment', sprintf( '%s plan %s (%s)', self::label( $frequency ), $pct < 0 ? 'discount' : 'rate', $pct_label ), 1, $amount );
		$result['total']   = Pricing::sum( $result['items'] );
		return $result;
	}

	public static function price_preview( $preview, $service, $answers, $price ) {
		if ( ! empty( $preview['bespoke'] ) || ! is_array( $service ) ) {
			return $preview;
		}
		$frequency = self::frequency_for( (string) $service['slug'], (array) $answers );
		if ( '' !== $frequency ) {
			$preview['note'] = sprintf( 'Price per visit, %s. You pay for the first visit when you book; after that we charge your saved card %d hours before each visit. Skip, pause or cancel online any time.', self::every( $frequency ), self::charge_hours() );
		}
		return $preview;
	}

	/**
	 * Price of a later visit: today's rates for that date and slot, never with a short-notice surcharge.
	 *
	 * @return array{total: int, items: array}|null
	 */
	public static function visit_price( string $slug, array $answers, string $date, string $slot, string $frequency ): ?array {
		$service = Catalog::get( $slug );
		if ( ! $service || ! $date ) {
			return null;
		}
		$answers['_plan_frequency'] = $frequency;
		$price = Pricing::price( $service, $answers, $date, $slot, Slots::start_timestamp( $date, $slot ) - 30 * DAY_IN_SECONDS );
		return $price['bespoke'] || null === $price['total'] ? null : [ 'total' => (int) $price['total'], 'items' => $price['items'] ];
	}

	/**
	 * Plan terms for a quote: frequency, next visit date and the per-visit price of later visits.
	 * Admin-priced (bespoke) quotes keep their agreed price for every visit.
	 */
	public static function quote_plan( array $quote ): ?array {
		$frequency = self::frequency_for( (string) $quote['service_slug'], (array) $quote['answers'] );
		if ( '' === $frequency || ! $quote['job_date'] || null === $quote['total_pence'] ) {
			return null;
		}
		$next  = self::add_days( (string) $quote['job_date'], self::interval( $frequency ) );
		$price = $quote['is_bespoke']
			? [ 'total' => (int) $quote['total_pence'], 'items' => (array) $quote['items'] ]
			: self::visit_price( (string) $quote['service_slug'], (array) $quote['answers'], $next, (string) $quote['slot'], $frequency );
		if ( ! $price ) {
			return null;
		}
		return [ 'frequency' => $frequency, 'next_date' => $next, 'visit_pence' => $price['total'], 'items' => $price['items'] ];
	}

	public static function consent_text( array $plan ): string {
		return sprintf(
			'I authorise %s to save my card and charge it %s for each later visit (%s), %d hours before each visit, until I cancel. I can skip, pause or cancel my plan online at any time.',
			Settings::brand(),
			Money::format( (int) $plan['visit_pence'] ),
			self::every( (string) $plan['frequency'] ),
			self::charge_hours()
		);
	}

	/* ---------------------------------------------------------------------
	 * Quote page and checkout
	 * ------------------------------------------------------------------ */

	public static function regular_note( $note, $quote ) {
		return is_array( $quote ) && self::quote_plan( $quote ) ? '' : $note;
	}

	public static function pay_label( $label, $quote ) {
		return is_array( $quote ) && self::quote_plan( $quote ) ? 'First visit — pay today' : $label;
	}

	public static function quote_price_after( $quote ): void {
		$plan = is_array( $quote ) ? self::quote_plan( $quote ) : null;
		if ( ! $plan ) {
			return;
		}
		printf(
			'<p class="cc-small"><strong>Regular plan: %1$s.</strong> This total is for your first visit. After that each visit is %2$s, charged to your saved card %3$d hours before the visit. Your next visit would be %4$s, at the same time. Skip a visit, pause, change how often or cancel online at any time.</p>',
			esc_html( strtolower( self::label( $plan['frequency'] ) ) ),
			esc_html( Money::format( (int) $plan['visit_pence'] ) ),
			(int) self::charge_hours(),
			esc_html( Slots::date_label( $plan['next_date'], 'l j F' ) )
		);
	}

	public static function quote_pay_fields( $quote ): void {
		$plan = is_array( $quote ) ? self::quote_plan( $quote ) : null;
		if ( ! $plan ) {
			return;
		}
		printf(
			'<label class="cc-quote__check"><input type="checkbox" name="recurring" value="1" required><span>%s</span></label>',
			esc_html( self::consent_text( $plan ) )
		);
	}

	/** `cc_bookings_checkout_consent`: regular quotes need the card authority tick. */
	public static function checkout_consent( $extra, $quote, $input ) {
		if ( is_wp_error( $extra ) || ! is_array( $quote ) ) {
			return $extra;
		}
		$plan = self::quote_plan( $quote );
		if ( ! $plan ) {
			return $extra;
		}
		if ( empty( $input['recurring'] ) ) {
			return new \WP_Error( 'recurring', 'Please tick the box to authorise the payments for your regular visits.', [ 'status' => 400, 'errors' => [ 'recurring' => 'Required' ] ] );
		}
		$extra['recurring'] = [
			'frequency'   => $plan['frequency'],
			'visit_pence' => (int) $plan['visit_pence'],
			'text'        => self::consent_text( $plan ),
			'at'          => Db::now(),
		];
		return $extra;
	}

	/** `cc_bookings_checkout_params`: save the card for off-session charges and say so on the Stripe page. */
	public static function checkout_params( $params, $quote ) {
		if ( ! is_array( $quote ) || empty( $quote['consent']['recurring'] ) || ! ( $plan = self::quote_plan( $quote ) ) ) {
			return $params;
		}
		$params['customer_creation']                         = 'always';
		$params['payment_intent_data']['setup_future_usage'] = 'off_session';
		$params['payment_intent_data']['metadata']['plan']   = $plan['frequency'];
		$params['metadata']['plan']                          = $plan['frequency'];
		$name = (string) ( $params['line_items'][0]['price_data']['product_data']['name'] ?? '' );
		$params['line_items'][0]['price_data']['product_data']['name'] = mb_substr( $name . ' — first visit, ' . strtolower( self::label( $plan['frequency'] ) ) . ' plan', 0, 250 );
		$params['custom_text']['submit']['message'] = mb_substr( sprintf(
			'Your card will be saved for your regular plan. Each later visit (%s) is charged %s, %d hours before the visit, until you cancel. You can skip, pause or cancel online at any time.',
			self::every( $plan['frequency'] ),
			Money::format( (int) $plan['visit_pence'] ),
			self::charge_hours()
		), 0, 1000 );
		return $params;
	}

	/** Start a plan when the first visit of a regular quote is paid (before the confirmation emails go out). */
	public static function on_confirmed( $booking_id, $context = [] ): void {
		$booking = Bookings::get( (int) $booking_id );
		if ( ! $booking || $booking['series_id'] || ! $booking['quote_id'] || 'reclean' === ( $context['provider'] ?? '' ) ) {
			return;
		}
		$quote = Quotes::get( (int) $booking['quote_id'] );
		if ( ! $quote || '' === self::frequency_for( (string) $quote['service_slug'], (array) $quote['answers'] ) ) {
			return;
		}
		try {
			if ( empty( $quote['consent']['recurring'] ) || ! $booking['job_date'] ) {
				Events::log( 'booking', $booking['id'], 'plan_not_started', 'Regular clean paid without the card authority (or without a date), so no plan was set up' );
				Bookings::alert( 'Regular clean to arrange', sprintf( 'Booking %s is a regular clean, but the customer didn\'t give the card authority for later visits (or there is no date), so no plan was set up. Please arrange the next visits with %s.', $booking['ref'], $booking['customer_name'] ), [ 'booking_id' => (int) $booking['id'] ] );
				return;
			}
			$plan_id = Plans::create( $booking, $quote );
			if ( is_wp_error( $plan_id ) ) {
				Bookings::alert( 'Regular plan not started', sprintf( 'Booking %s: %s', $booking['ref'], $plan_id->get_error_message() ), [ 'booking_id' => (int) $booking['id'] ] );
			}
		} catch ( \Throwable $e ) {
			Events::log( 'booking', (int) $booking['id'], 'plan_error', $e->getMessage(), [ 'file' => basename( $e->getFile() ) . ':' . $e->getLine() ] );
			Bookings::alert( 'Regular plan not started', sprintf( 'Booking %s: %s', $booking['ref'], $e->getMessage() ), [ 'booking_id' => (int) $booking['id'] ] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Emails
	 * ------------------------------------------------------------------ */

	public static function email_defaults( array $defaults ): array {
		return $defaults + [
			'visit_payment_due' => [
				'label'      => 'Regular visit: card payment failed (customer)',
				'to'         => 'customer',
				'subject'    => 'Action needed: payment for your clean on {date_short}',
				'body'       => "Hi {first_name},\n\nWe tried to take {amount} from your saved card ({card}) for your {service} on {date}, but {reason}.\n\nPlease pay using the button below by {pay_by}. If we haven't received payment by then, we'll have to release this visit; your regular plan carries on as normal. You can also update your card on the same page.\n\nIf you need help, call us on {phone}.",
				'button'     => 'Pay {amount} now',
				'button_url' => 'pay_url',
			],
			'plan_updated'      => [
				'label'      => 'Regular plan changed (customer)',
				'to'         => 'customer',
				'subject'    => 'Your regular {service} plan: {change_short}',
				'body'       => "Hi {first_name},\n\n{change}\n\n{details}\n\nYou can skip a visit, pause, change how often or cancel your plan at any time from your booking page.",
				'button'     => 'Manage your plan',
				'button_url' => 'manage_url',
			],
			'plan_admin'        => [
				'label'      => 'Regular plan changed by the customer (admin)',
				'to'         => 'admin',
				'subject'    => 'Regular plan {plan_ref} ({name}): {change_short}',
				'body'       => "{change}\n\n{details}",
				'button'     => 'Open plan',
				'button_url' => 'admin_url',
			],
		];
	}

	/** Plan details in the confirmation and reminder emails of plan visits, and the plan terms in the quote email. */
	public static function email_vars( $vars, $key, $object ) {
		$type = (string) ( $object[0] ?? '' );
		$id   = (int) ( $object[1] ?? 0 );
		if ( 'quote_ready' === $key && 'quote' === $type && ( $quote = Quotes::get( $id ) ) && ( $plan = self::quote_plan( $quote ) ) ) {
			$vars['items'] = (string) ( $vars['items'] ?? '' ) . sprintf(
				'<p style="margin:0 0 16px;">%s</p>',
				esc_html( sprintf( 'Regular plan, %s: the total above is for your first visit. Each later visit is %s, charged to your saved card %d hours before the visit. Skip, pause or cancel online at any time.', self::every( $plan['frequency'] ), Money::format( (int) $plan['visit_pence'] ), self::charge_hours() ) )
			);
		}
		if ( in_array( $key, [ 'booking_confirmed', 'reminder_customer' ], true ) && 'booking' === $type && ( $booking = Bookings::get( $id ) ) && $booking['series_id'] && ( $plan = Plans::get( (int) $booking['series_id'] ) ) ) {
			$vars['details'] = (string) ( $vars['details'] ?? '' ) . Plans::details_html( $plan );
		}
		return $vars;
	}
}

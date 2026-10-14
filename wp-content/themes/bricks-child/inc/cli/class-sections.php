<?php
namespace CC\Cli;

defined( 'ABSPATH' ) || exit;

use CC\Cli\Tree as T;

/**
 * Element trees for Bricks templates and pages. Content comes from {cc_*} dynamic tags,
 * so editors change copy and data in WP Admin rather than in the builder.
 */
final class Sections {

	/* ---------------------------------------------------------------------
	 * Shared building blocks
	 * ------------------------------------------------------------------ */

	private static function quote_button( string $class = 'cc-btn cc-btn--primary' ): array {
		return T::button( '{cc_cta_primary}', '{cc_quote_url}', $class, 'Quote button' );
	}

	private static function call_button( string $class = 'cc-btn cc-btn--outline' ): array {
		return T::button( 'Call {cc_phone}', '{cc_phone_href}', $class, 'Call button' );
	}

	private static function ctas(): array {
		return T::div( 'cc-actions', [ self::quote_button(), self::call_button() ], 'Actions' );
	}

	private static function intro( string $eyebrow, string $heading, string $lead = '', string $class = '' ): array {
		$children = [];
		if ( $eyebrow ) {
			$children[] = T::text( $eyebrow, 'cc-eyebrow' );
		}
		$children[] = T::heading( $heading, 'h2' );
		if ( $lead ) {
			$children[] = T::text( $lead, 'cc-lead' );
		}
		return T::div( trim( 'cc-section-head ' . $class ), $children, 'Section heading' );
	}

	/**
	 * Section that only renders when the given dynamic tag has output.
	 */
	private static function filled_section( string $tag, string $class, array $children, string $label ): array {
		return T::section( $class, [ T::container( '', $children ) ], $label, T::when_filled( $tag ) );
	}

	public static function cta_band( string $heading = 'Ready for a cleaner home?', string $text = 'Get a fixed price in minutes. No obligation, no hidden extras — and every clean is backed by our re-clean guarantee.' ): array {
		return T::section( 'cc-cta', [
			self::cover( '{cc_option_image:cta_image}' ),
			T::container( 'cc-cta__inner', [
				T::div( 'cc-cta__text', [
					T::heading( $heading, 'h2' ),
					T::text( $text, 'cc-lead' ),
				] ),
				T::div( 'cc-actions', [ self::quote_button( 'cc-btn cc-btn--accent' ), self::call_button( 'cc-btn cc-btn--ghost' ) ] ),
			] ),
		], 'CTA band' );
	}

	public static function page_hero( string $heading, string $lead, string $eyebrow = '', bool $actions = true ): array {
		$children = [];
		if ( $eyebrow ) {
			$children[] = T::text( $eyebrow, 'cc-eyebrow' );
		}
		$children[] = T::heading( $heading, 'h1' );
		$children[] = T::text( $lead, 'cc-lead' );
		if ( $actions ) {
			$children[] = self::ctas();
		}
		$children[] = T::block( '{cc_trust_bar}', 'Trust bar' );

		return T::section( 'cc-page-hero', [ self::cover( '{cc_hero_media}' ), T::container( 'cc-page-hero__inner', $children ) ], 'Page hero' );
	}

	private static function how_it_works( string $class = 'cc-section--alt' ): array {
		return T::section( $class, [
			T::container( '', [
				self::intro( 'How it works', 'Booking a clean is easy', '', 'cc-section-head--center' ),
				T::block( '{cc_how_it_works}', 'Steps' ),
			] ),
		], 'How it works' );
	}

	private static function reviews_section( string $heading = 'What our customers say' ): array {
		return self::filled_section( '{cc_reviews}', '', [
			self::intro( 'Reviews', $heading ),
			T::block( '{cc_reviews}', 'Reviews' ),
		], 'Reviews' );
	}

	private static function faqs_section( string $heading = 'Frequently asked questions' ): array {
		return self::filled_section( '{cc_faqs}', 'cc-section--alt', [
			T::div( 'cc-container--narrow', [
				self::intro( 'FAQs', $heading ),
				T::block( '{cc_faqs}', 'FAQs' ),
			] ),
		], 'FAQs' );
	}

	private static function rich_section( string $html, string $class = '', string $label = 'Content' ): array {
		return T::section( $class, [ T::container( 'cc-container--narrow', [ T::rich( $html, 'cc-prose' ) ] ) ], $label );
	}

	/**
	 * Full-bleed background photo, only rendered when the tag returns an image.
	 */
	private static function cover( string $tag ): array {
		return T::el( 'text-basic', array_merge( [ 'text' => $tag, 'tag' => 'div', '_cssClasses' => 'cc-cover' ], T::when_filled( $tag ) ), [], 'Background photo' );
	}

	/**
	 * @param array<int, array{0:string,1:string,2:string}> $items [ icon, title, text ]
	 */
	private static function features( array $items, string $class = '' ): array {
		$html = '<div class="' . esc_attr( trim( 'cc-features ' . $class ) ) . '">';
		foreach ( $items as [ $icon, $title, $text ] ) {
			$html .= '<div class="cc-feature">' . cc_icon( $icon ) . '<h3>' . esc_html( $title ) . '</h3><p>' . esc_html( $text ) . '</p></div>';
		}
		return T::rich( $html . '</div>', '', 'Features' );
	}

	/* ---------------------------------------------------------------------
	 * Header & footer
	 * ------------------------------------------------------------------ */

	public static function header( int $menu_id ): array {
		return [
			T::div( 'cc-topbar', [
				T::container( 'cc-topbar__inner', [
					T::block( '{cc_announcement}', 'Announcement', 'cc-topbar__note' ),
					T::div( 'cc-topbar__contact', [
						T::text( '{cc_hours_today}', 'cc-topbar__hours', 'span' ),
						T::block( '{cc_phone_link}', 'Phone' ),
					] ),
				] ),
			], 'Top bar' ),
			T::div( 'cc-header', [
				T::container( 'cc-header__inner', [
					T::block( '{cc_logo}', 'Logo', 'cc-header__logo' ),
					T::nav( $menu_id, 'cc-nav', 'Primary navigation' ),
					T::div( 'cc-header__actions', [
						T::button( '{cc_phone}', '{cc_phone_href}', 'cc-btn cc-btn--outline cc-header__call', 'Call' ),
						self::quote_button( 'cc-btn cc-btn--primary cc-header__quote' ),
					] ),
				] ),
			], 'Header' ),
		];
	}

	public static function footer(): array {
		$legal = '<ul class="cc-footer__legal-links">'
			. '<li><a href="/privacy-policy/">Privacy</a></li>'
			. '<li><a href="/terms/">Terms</a></li>'
			. '<li><a href="/cookie-policy/">Cookies</a></li>'
			. '</ul>';

		return [
			T::div( 'cc-footer', [
				T::container( '', [
					T::div( 'cc-footer__grid', [
						T::div( 'cc-footer__brand', [
							T::block( '{cc_logo}', 'Logo' ),
							T::text( 'Professional, insured and DBS-checked cleaners for homes, landlords and businesses across Hull & East Yorkshire.', 'cc-footer__about' ),
							T::block( '{cc_trust_list}', 'Trust list' ),
							T::block( '{cc_social}', 'Social links' ),
						] ),
						T::div( 'cc-footer__col', [ T::heading( 'Services', 'h2', 'cc-footer__title' ), T::block( '{cc_footer_services}', 'Services list' ) ] ),
						T::div( 'cc-footer__col', [ T::heading( 'Areas', 'h2', 'cc-footer__title' ), T::block( '{cc_footer_areas}', 'Areas list' ) ] ),
						T::div( 'cc-footer__col', [ T::heading( 'Company', 'h2', 'cc-footer__title' ), T::block( '{cc_company_links}', 'Company links' ) ] ),
						T::div( 'cc-footer__col', [
							T::heading( 'Contact', 'h2', 'cc-footer__title' ),
							T::block( '{cc_contact_list}', 'Contact list' ),
							T::block( '{cc_opening_hours}', 'Opening hours' ),
						] ),
					] ),
					T::div( 'cc-footer__bottom', [
						T::text( '{cc_legal}', 'cc-footer__legal' ),
						T::rich( $legal, '', 'Legal links' ),
					] ),
				] ),
			], 'Footer' ),
			T::div( 'cc-mobile-cta', [
				self::call_button( 'cc-btn cc-btn--outline' ),
				self::quote_button( 'cc-btn cc-btn--primary' ),
			], 'Mobile call to action' ),
		];
	}

	/* ---------------------------------------------------------------------
	 * Single templates
	 * ------------------------------------------------------------------ */

	private static function single_hero( string $sub, bool $card = true ): array {
		$content = T::div( 'cc-hero__content', [
			T::block( '{cc_breadcrumbs}', 'Breadcrumbs' ),
			T::heading( '{cc_hero_heading}', 'h1', 'cc-hero__title' ),
			T::text( $sub, 'cc-lead cc-hero__lead' ),
			self::ctas(),
			T::block( '{cc_trust_bar}', 'Trust bar' ),
		], 'Hero content' );

		$children = [ $content ];
		if ( $card ) {
			$children[] = T::div( 'cc-hero__card', [
				T::text( '{cc_hero_card_title}', 'cc-hero__price', 'p', 'Card title' ),
				T::block( '{cc_hero_facts}', 'Facts' ),
				T::block( '{cc_guarantee}', 'Guarantee' ),
				self::quote_button( 'cc-btn cc-btn--primary cc-btn--block' ),
			], 'Hero card' );
		}

		return T::section( 'cc-hero', [ self::cover( '{cc_hero_media}' ), T::container( 'cc-hero__grid', $children ) ], 'Hero' );
	}

	private static function service_body( bool $local ): array {
		$sections = [];

		$sections[] = T::section( '', [
			T::container( 'cc-split', [
				T::div( 'cc-split__main', [
					self::intro( 'About this service', $local ? '{cc_service_name} in {cc_area_name}' : 'What to expect' ),
					T::block( $local ? '{cc_field:local_intro}' : '{cc_field:intro}', 'Intro' ),
				] ),
				T::div( 'cc-split__aside', array_merge( [
					T::heading( 'Who it\'s for', 'h2', 'cc-h3' ),
					T::block( '{cc_who_for}', 'Who it\'s for' ),
				], $local ? [
					T::div( 'cc-split__places', [
						T::heading( 'Places we cover in {cc_area_name}', 'h2', 'cc-h3' ),
						T::block( '{cc_neighbourhoods}', 'Neighbourhoods' ),
					], 'Places we cover', T::when_filled( '{cc_neighbourhoods}' ) ),
				] : [] ) ),
			] ),
		], 'Intro' );

		$sections[] = self::filled_section( '{cc_checklist}', 'cc-section--alt', [
			self::intro( 'Checklist', 'What\'s included', 'Every clean follows a detailed checklist, so nothing gets missed.' ),
			T::block( '{cc_checklist}', 'Checklist' ),
			T::div( 'cc-not-included', [
				T::heading( 'Not included', 'h3' ),
				T::block( '{cc_not_included}', 'Not included' ),
			], 'Not included', T::when_filled( '{cc_not_included}' ) ),
		], 'Checklist' );

		$sections[] = T::section( '', [
			T::container( 'cc-split', [
				T::div( 'cc-split__main', [
					self::intro( 'Pricing', 'A fixed price for your property', 'Tell us about your property and we\'ll send a fixed quote — confirmed before we start, with no hidden extras.' ),
					T::block( '{cc_price_table}', 'Price table' ),
					T::block( '{cc_quote_promise}', 'Quote promise' ),
					self::quote_button(),
				] ),
				T::div( 'cc-split__aside', [
					T::heading( 'Popular add-ons', 'h2', 'cc-h3' ),
					T::block( '{cc_addons}', 'Add-ons' ),
				], 'Add-ons', T::when_filled( '{cc_addons}' ) ),
			] ),
		], 'Pricing' );

		$sections[] = self::how_it_works();

		$sections[] = self::filled_section( '{cc_before_after}', '', [
			self::intro( 'Results', 'Before & after' ),
			T::block( '{cc_before_after}', 'Before & after' ),
		], 'Before & after' );

		$sections[] = self::reviews_section();

		if ( ! $local ) {
			$sections[] = T::section( 'cc-section--alt', [
				T::container( 'cc-split', [
					T::div( 'cc-split__main', [
						self::intro( 'Local', 'Serving Hull & East Yorkshire' ),
						T::block( '{cc_field:local_section}', 'Local section' ),
					] ),
					T::div( 'cc-split__aside', [
						T::heading( 'Areas we cover', 'h2', 'cc-h3' ),
						T::block( '{cc_service_areas}', 'Areas' ),
					] ),
				] ),
			], 'Local' );
		}

		$sections[] = self::faqs_section();

		if ( $local ) {
			$sections[] = T::section( 'cc-section--alt', [
				T::container( '', [
					self::intro( 'Nearby', '{cc_service_name} in other areas' ),
					T::block( '{cc_service_areas}', 'Other areas' ),
				] ),
			], 'Other areas' );
		}

		$sections[] = self::filled_section( '{cc_related_services}', '', [
			self::intro( 'You might also need', 'Related services' ),
			T::block( '{cc_related_services}', 'Related services' ),
		], 'Related services' );

		$sections[] = self::filled_section( '{cc_service_guides}', 'cc-section--alt', [
			self::intro( 'Guides & advice', $local ? '{cc_service_name} tips from our team' : 'Helpful guides from our cleaners' ),
			T::block( '{cc_service_guides}', 'Guides' ),
		], 'Guides' );

		$sections[] = self::cta_band();

		return $sections;
	}

	public static function service_single(): array {
		return array_merge( [ self::single_hero( '{cc_hero_sub}' ) ], self::service_body( false ) );
	}

	public static function local_service_single(): array {
		return array_merge( [ self::single_hero( '{cc_hero_sub}' ) ], self::service_body( true ) );
	}

	public static function area_single(): array {
		return [
			self::single_hero( 'Insured, DBS-checked local cleaners covering {post_title} and the surrounding area — 7 days a week.' ),
			T::section( '', [
				T::container( 'cc-split', [
					T::div( 'cc-split__main', [
						self::intro( 'Local cleaners', 'Cleaning services in {post_title}' ),
						T::block( '{cc_field:intro}', 'Intro' ),
					] ),
					T::div( 'cc-split__aside', [
						T::heading( 'Places we cover', 'h2', 'cc-h3' ),
						T::block( '{cc_neighbourhoods}', 'Neighbourhoods' ),
					] ),
				] ),
			], 'Intro' ),
			T::section( 'cc-section--alt', [
				T::container( '', [
					self::intro( 'Services', 'Our cleaning services in {post_title}' ),
					T::block( '{cc_area_services}', 'Services in area' ),
				] ),
			], 'Services' ),
			self::filled_section( '{cc_field:local_notes}', '', [
				T::div( 'cc-container--narrow', [
					self::intro( 'Local knowledge', 'Cleaning in {post_title}' ),
					T::block( '{cc_field:local_notes}', 'Local notes' ),
				] ),
			], 'Local notes' ),
			self::how_it_works(),
			self::reviews_section( 'What customers in {post_title} say' ),
			self::faqs_section( 'Cleaning in {post_title}: FAQs' ),
			T::section( '', [
				T::container( '', [
					self::intro( 'Coverage', 'Other areas we cover' ),
					T::block( '{cc_area_links}', 'Areas' ),
				] ),
			], 'Other areas' ),
			self::cta_band( 'Need a cleaner in {post_title}?' ),
		];
	}

	public static function post_single(): array {
		return [
			T::section( 'cc-page-hero cc-page-hero--guide', [
				self::cover( '{cc_hero_media}' ),
				T::container( 'cc-page-hero__inner', [
					T::block( '{cc_breadcrumbs}', 'Breadcrumbs' ),
					T::block( '{cc_guide_eyebrow}', 'Category' ),
					T::heading( '{post_title}', 'h1' ),
					T::text( '{cc_hero_sub}', 'cc-lead' ),
					T::block( '{cc_guide_meta}', 'Meta' ),
				] ),
			], 'Article hero' ),
			T::section( 'cc-section--article', [
				T::container( 'cc-article', [
					T::el( 'post-content', [ '_cssClasses' => 'cc-prose cc-article__content' ], [], 'Post content' ),
					T::div( 'cc-article__aside', [
						T::block( '{cc_toc}', 'Contents' ),
						T::block( '{cc_guide_service}', 'Service box' ),
					], 'Sidebar' ),
				] ),
			], 'Article' ),
			self::faqs_section(),
			self::filled_section( '{cc_related_guides}', '', [
				self::intro( 'Keep reading', 'Related guides' ),
				T::block( '{cc_related_guides}', 'Related guides' ),
			], 'Related guides' ),
			self::cta_band(),
		];
	}

	public static function error_404(): array {
		return [
			T::section( 'cc-page-hero', [
				T::container( 'cc-page-hero__inner', [
					T::text( '404', 'cc-eyebrow' ),
					T::heading( 'Sorry, we can\'t find that page', 'h1' ),
					T::text( 'It may have moved. Try one of our services below, or get in touch and we\'ll help.', 'cc-lead' ),
					T::div( 'cc-actions', [
						T::button( 'Back to home', '/', 'cc-btn cc-btn--primary' ),
						T::button( 'View all services', '/services/', 'cc-btn cc-btn--outline' ),
					] ),
				] ),
			], '404 hero' ),
			T::section( 'cc-section--alt', [
				T::container( '', [
					self::intro( 'Services', 'Popular cleaning services' ),
					T::block( '{cc_service_cards:home}', 'Services' ),
				] ),
			], 'Services' ),
		];
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------ */

	public static function home(): array {
		return [
			T::section( 'cc-hero cc-hero--home', [
				self::cover( '{cc_hero_media}' ),
				T::container( 'cc-hero__grid', [
					T::div( 'cc-hero__content', [
						T::text( 'Hull & East Yorkshire cleaning company', 'cc-eyebrow' ),
						T::heading( 'Professional cleaners in <span class="cc-highlight">Hull & East Yorkshire</span>', 'h1', 'cc-hero__title' ),
						T::text( 'Trusted house cleaning, end of tenancy and commercial cleaning — by insured, DBS-checked local cleaners, with fixed prices and a re-clean guarantee.', 'cc-lead cc-hero__lead' ),
						self::ctas(),
						T::block( '{cc_trust_bar}', 'Trust bar' ),
					], 'Hero content' ),
					T::div( 'cc-hero__card', [
						T::heading( 'Most popular', 'h2', 'cc-hero__card-title' ),
						T::rich(
							'<ul class="cc-quick-links">'
							. '<li><a href="/services/regular-house-cleaning/"><span>Regular house cleaning</span><span>→</span></a></li>'
							. '<li><a href="/services/end-of-tenancy-cleaning/"><span>End of tenancy cleaning</span><span>→</span></a></li>'
							. '<li><a href="/services/deep-cleaning/"><span>Deep cleaning</span><span>→</span></a></li>'
							. '<li><a href="/services/oven-cleaning/"><span>Oven cleaning</span><span>→</span></a></li>'
							. '<li><a href="/services/office-cleaning/"><span>Office cleaning</span><span>→</span></a></li>'
							. '</ul>',
							'',
							'Quick links'
						),
						T::block( '{cc_guarantee}', 'Guarantee' ),
					], 'Hero card' ),
				] ),
			], 'Hero' ),
			T::section( 'cc-stats-band', [ T::container( '', [ T::block( '{cc_stats}', 'Stats' ) ] ) ], 'Stats' ),
			T::section( 'cc-marquee-band', [ T::block( '{cc_marquee}', 'Towns strip' ) ], 'Towns strip' ),
			T::section( '', [
				T::container( '', [
					self::intro( 'Our services', 'Cleaning services for every home and business', 'From weekly house cleaning to end of tenancy and office contracts — all with fixed quotes and the same high standard.' ),
					T::block( '{cc_service_carousel}', 'Service carousel' ),
				] ),
			], 'Services' ),
			T::section( 'cc-section--alt', [
				T::container( 'cc-split cc-split--media', [
					T::block( '{cc_why_media}', 'Photo collage', 'cc-split__media' ),
					T::div( 'cc-split__main', [
						self::intro( 'Why choose us', 'A cleaning company you can trust', 'Local, vetted cleaners who turn up on time, follow a proper checklist and stand behind every clean.' ),
						self::features( [
							[ 'shield', 'Vetted, local cleaners', 'Every cleaner is DBS-checked, trained in-house and lives locally — so you get reliable, familiar faces.' ],
							[ 'receipt', 'Fixed, honest quotes', 'A free quote tailored to your property, with the price agreed before we start. No hidden extras.' ],
							[ 'rotate', 'Re-clean guarantee', 'If anything isn\'t right, tell us within 72 hours and we\'ll come back and put it right for free.' ],
							[ 'calendar', '7 days a week', 'Early, late and weekend slots, with key holding so you don\'t need to be home.' ],
						], 'cc-features--compact' ),
					] ),
				] ),
			], 'Why us' ),
			self::how_it_works( '' ),
			T::section( 'cc-section--alt', [
				T::container( '', [
					self::intro( 'Who we help', 'Specialist cleaning for landlords, businesses and hosts' ),
					T::block( '{cc_promos}', 'Audiences' ),
				] ),
			], 'Audiences' ),
			self::reviews_section(),
			T::section( '', [
				T::container( '', [
					self::intro( 'Areas we cover', 'Local cleaners across Hull & East Yorkshire', 'Our cleaners live locally, so we can offer reliable regular slots and quick one-off cleans.' ),
					T::block( '{cc_area_links:cards}', 'Areas' ),
				] ),
			], 'Areas' ),
			self::filled_section( '{cc_latest_guides}', 'cc-section--alt', [
				self::intro( 'Guides', 'Cleaning tips & advice' ),
				T::block( '{cc_latest_guides}', 'Guides' ),
			], 'Guides' ),
			self::cta_band(),
		];
	}

	public static function services_hub(): array {
		return [
			self::page_hero( 'Cleaning services in Hull & East Yorkshire', 'From regular house cleaning to end of tenancy, office and holiday let cleaning — every service comes with a fixed quote, vetted cleaners and our re-clean guarantee.', 'Our services' ),
			T::section( '', [ T::container( '', [ T::block( '{cc_service_groups}', 'Services by group' ) ] ) ], 'Services' ),
			self::how_it_works(),
			self::cta_band( 'Not sure which clean you need?', 'Tell us about your property and we\'ll recommend the right service and give you a fixed price.' ),
		];
	}

	public static function areas_hub(): array {
		return [
			self::page_hero( 'Areas we cover', 'Our local cleaners work across Hull and East Yorkshire — from the city to the coast and the Wolds.', 'Coverage' ),
			T::section( '', [
				T::container( '', [
					T::block( '{cc_area_links:cards}', 'Area cards' ),
					T::text( 'Don\'t see your town? We may still be able to help — get in touch and ask.', 'cc-lead cc-text-center' ),
				] ),
			], 'Areas' ),
			self::cta_band(),
		];
	}

	public static function about(): array {
		$story = '<h2>Our story</h2>'
			. '<p>We started this company with a simple idea: cleaning should be reliable, honest and done properly. Too many people in Hull and East Yorkshire told us about cleaners who didn\'t turn up, rushed jobs and surprise charges. We set out to do better.</p>'
			. '<p>Today our team of local cleaners looks after homes, rental properties and businesses across the region. We\'re small enough to know our customers by name, and organised enough to handle everything from a weekly clean to a full commercial contract.</p>'
			. '<p><em>Add your own story here — who founded the company, why, and what makes you different.</em></p>';

		$standards = '<h2>Our standards</h2>'
			. '<ul class="cc-ticks">'
			. '<li>Every cleaner is DBS-checked, reference-checked and interviewed in person</li>'
			. '<li>In-house training on our checklists, products and safety</li>'
			. '<li>Public liability insurance on every job</li>'
			. '<li>Keys coded and stored securely</li>'
			. '<li>Spot checks and customer feedback after every clean</li>'
			. '<li>Eco-friendly products as standard</li>'
			. '</ul>';

		return [
			self::page_hero( 'About us', 'A local cleaning company built on reliability, honesty and doing the job properly.', 'Who we are' ),
			self::rich_section( $story, '', 'Story' ),
			T::section( 'cc-section--alt', [
				T::container( '', [
					self::intro( 'Our values', 'What we stand for', '', 'cc-section-head--center' ),
					self::features( [
						[ 'clock', 'Reliability', 'We turn up when we say we will. If plans change, you hear from us first.' ],
						[ 'receipt', 'Honesty', 'Fixed quotes, no hidden extras and straight answers — including when a job isn\'t right for us.' ],
						[ 'heart', 'Care', 'We treat every home like our own, and every customer like a neighbour.' ],
						[ 'users', 'Fair work', 'We pay our cleaners fairly and train them properly. Happy cleaners do better work.' ],
					] ),
				] ),
			], 'Values' ),
			self::rich_section( $standards, '', 'Standards' ),
			T::section( 'cc-section--alt', [
				T::container( 'cc-container--narrow', [ T::block( '{cc_guarantee}', 'Guarantee' ) ] ),
			], 'Guarantee' ),
			self::cta_band(),
		];
	}

	public static function pricing(): array {
		return [
			self::page_hero( 'Cleaning prices', 'Clear, fixed prices for every service. Your final price is confirmed before we start — no hidden extras.', 'Pricing' ),
			T::section( '', [ T::container( '', [ T::block( '{cc_pricing_overview}', 'Pricing overview' ) ] ) ], 'Prices' ),
			self::cta_band( 'Want an exact price?', 'Tell us about your property and we\'ll send a fixed quote, usually within the hour.' ),
		];
	}

	public static function contact(): array {
		$f = fn( string $type, string $label, array $extra = [] ) => array_merge( [ 'id' => T::id(), 'type' => $type, 'label' => $label ], $extra );

		$services = implode( "\n", [
			'Regular house cleaning',
			'Deep cleaning',
			'One-off cleaning',
			'End of tenancy cleaning',
			'Move-in cleaning',
			'After builders cleaning',
			'Oven cleaning',
			'Carpet & upholstery cleaning',
			'Holiday let / Airbnb cleaning',
			'Communal area cleaning',
			'Office cleaning',
			'Commercial cleaning',
			'Something else',
		] );

		$email = $f( 'email', 'Email', [ 'placeholder' => 'you@example.com', 'required' => true, 'width' => '50' ] );

		$form = T::el( 'form', [
			'fields'           => [
				$f( 'text', 'Name', [ 'placeholder' => 'Your name', 'required' => true, 'width' => '50' ] ),
				$email,
				$f( 'tel', 'Phone', [ 'placeholder' => '07…', 'width' => '50' ] ),
				$f( 'text', 'Postcode', [ 'placeholder' => 'e.g. HU5', 'required' => true, 'width' => '50' ] ),
				$f( 'select', 'Service', [ 'options' => $services, 'placeholder' => 'Choose a service', 'required' => true ] ),
				$f( 'textarea', 'Tell us about the clean', [ 'placeholder' => 'Property size, preferred dates, anything we should know…' ] ),
			],
			'showLabels'       => true,
			'actions'          => [ 'email' ],
			'emailSubject'     => 'New quote request from the website',
			'emailTo'          => 'admin_email',
			'replyToEmail'     => '{{' . $email['id'] . '}}',
			'htmlEmail'        => true,
			'successMessage'   => 'Thanks! We\'ve got your request and will be in touch shortly — usually within the hour during opening times.',
			'submitButtonText' => 'Get my free quote',
			'submitButtonStyle' => 'primary',
			'_cssClasses'      => 'cc-form',
		], [], 'Quote form' );

		return [
			self::page_hero( 'Get a free quote', 'Tell us a little about your property and the clean you need. We\'ll reply with a fixed price — usually within the hour during opening times.', 'Contact us', false ),
			T::section( '', [
				T::container( 'cc-split', [
					T::div( 'cc-split__main cc-panel', [
						T::heading( 'Request a quote', 'h2', 'cc-h3' ),
						$form,
					] ),
					T::div( 'cc-split__aside', [
						T::heading( 'Contact details', 'h2', 'cc-h3' ),
						T::block( '{cc_contact_list}', 'Contact list' ),
						T::heading( 'Opening hours', 'h2', 'cc-h3' ),
						T::block( '{cc_opening_hours}', 'Opening hours' ),
						T::heading( 'Areas we cover', 'h2', 'cc-h3' ),
						T::block( '{cc_area_links}', 'Areas' ),
					] ),
				] ),
			], 'Contact' ),
		];
	}

	public static function landlords(): array {
		return [
			self::page_hero( 'Cleaning for landlords & letting agents', 'End of tenancy cleans, communal areas and property turnarounds across Hull & East Yorkshire — reliable, inventory-standard and invoiced the way you need.', 'Landlords & agents' ),
			T::section( '', [
				T::container( '', [
					self::intro( 'Why agents use us', 'Built around the way you work', '', 'cc-section-head--center' ),
					self::features( [
						[ 'clipboard', 'Inventory-standard cleans', 'We follow agent and inventory checklists, so properties pass check-out first time.' ],
						[ 'clock', 'Fast turnarounds', 'Same-week and weekend cleans to cut void periods between tenancies.' ],
						[ 'key', 'Key collection', 'We collect and return keys from your office or key safe.' ],
						[ 'receipt', 'Invoicing to suit you', 'Invoice the landlord, the agent or the tenant — with monthly statements for portfolios.' ],
					] ),
				] ),
			], 'Benefits' ),
			T::section( 'cc-section--alt', [
				T::container( '', [
					self::intro( 'Services', 'Services for landlords & hosts' ),
					T::block( '{cc_service_cards:landlords-hosts}', 'Landlord services' ),
					T::block( '{cc_service_cards:moving-property}', 'Moving services' ),
				] ),
			], 'Services' ),
			self::cta_band( 'Managing several properties?', 'Ask about account pricing and priority booking for letting agents and portfolio landlords.' ),
		];
	}

	public static function business(): array {
		return [
			self::page_hero( 'Office & commercial cleaning', 'Reliable contract cleaning for offices, shops, clinics and industrial sites across Hull & East Yorkshire.', 'For businesses' ),
			T::section( '', [
				T::container( '', [
					self::intro( 'Why businesses choose us', 'Cleaning that just happens', '', 'cc-section-head--center' ),
					self::features( [
						[ 'clock', 'Out of hours', 'Early mornings, evenings or weekends — we work around your business.' ],
						[ 'users', 'Dedicated team', 'The same vetted cleaners every visit, with a named account manager.' ],
						[ 'clipboard', 'Compliance included', 'RAMS, COSHH data sheets, insurance certificates and cleaning logs.' ],
						[ 'building', 'Fixed monthly price', 'A clear specification and one predictable invoice each month.' ],
					] ),
				] ),
			], 'Benefits' ),
			T::section( 'cc-section--alt', [
				T::container( '', [
					self::intro( 'Services', 'Business cleaning services' ),
					T::block( '{cc_service_cards:business}', 'Business services' ),
				] ),
			], 'Services' ),
			self::cta_band( 'Book a free site visit', 'We\'ll visit, agree a specification with you and send a fixed monthly quote.' ),
		];
	}

	public static function guarantee(): array {
		return [
			self::page_hero( '{cc_option:guarantee_title}', '{cc_option:guarantee_short}', 'Our guarantee' ),
			T::section( '', [ T::container( 'cc-container--narrow', [ T::block( '{cc_option_html:guarantee_full}', 'Guarantee details', 'cc-prose' ) ] ) ], 'Details' ),
			self::cta_band(),
		];
	}

	public static function reviews(): array {
		return [
			self::page_hero( 'Customer reviews', 'Real reviews from customers across Hull & East Yorkshire.', 'Reviews', false ),
			self::filled_section( '{cc_reviews}', '', [ T::block( '{cc_reviews}', 'Reviews' ) ], 'Reviews' ),
			T::section( '', [
				T::container( 'cc-container--narrow', [
					T::text( 'We\'re busy collecting reviews from our first customers. Had a clean with us? We\'d love to hear how we did.', 'cc-lead cc-text-center' ),
				] ),
			], 'No reviews yet', [ '_conditions' => [ [ [ 'id' => T::id(), 'key' => 'dynamic_data', 'compare' => '==', 'value' => '', 'dynamic_data' => '{cc_reviews}' ] ] ] ] ),
			self::cta_band(),
		];
	}

	public static function faq(): array {
		return [
			self::page_hero( 'Frequently asked questions', 'Answers to the questions we\'re asked most, grouped by service.', 'FAQs', false ),
			T::section( '', [ T::container( 'cc-container--narrow', [ T::block( '{cc_all_faqs}', 'All FAQs' ) ] ) ], 'FAQs' ),
			self::cta_band( 'Still have a question?', 'Call us or send a message — we\'re happy to help.' ),
		];
	}

	public static function careers(): array {
		$html = '<h2>Why work with us</h2>'
			. '<ul class="cc-ticks">'
			. '<li>Fair pay, paid travel time between jobs and holiday pay</li>'
			. '<li>Flexible hours that fit around school runs and other commitments</li>'
			. '<li>Full training, equipment and uniform provided</li>'
			. '<li>Regular customers, so you get to know the people you clean for</li>'
			. '</ul>'
			. '<h2>What we look for</h2>'
			. '<ul class="cc-ticks">'
			. '<li>Reliable, friendly and proud of doing a job properly</li>'
			. '<li>Right to work in the UK and willing to complete a DBS check</li>'
			. '<li>Cleaning experience is helpful but not essential — we\'ll train you</li>'
			. '<li>A driving licence is useful for our East Yorkshire routes</li>'
			. '</ul>'
			. '<h2>How to apply</h2>'
			. '<p>Send us a message through our <a href="/contact/">contact page</a> with your name, phone number, where you live and the hours you\'re available. We\'ll be in touch to arrange a chat.</p>';

		return [
			self::page_hero( 'Join our cleaning team', 'We\'re looking for reliable, friendly cleaners across Hull & East Yorkshire.', 'Careers', false ),
			self::rich_section( $html, '', 'Careers' ),
		];
	}

	public static function guides(): array {
		return [
			self::page_hero( 'Cleaning guides & advice', 'Practical, local advice on cleaning, moving home, getting your deposit back and running a spotless rental or business — from the cleaning professionals in Hull & East Yorkshire.', 'Guides', false ),
			self::filled_section( '{cc_guide_hub}', 'cc-section--hub', [ T::block( '{cc_guide_hub}', 'Guide topics' ) ], 'Guides' ),
			self::cta_band(),
		];
	}

	public static function legal( string $html ): array {
		return [
			T::section( 'cc-page-hero cc-page-hero--article', [
				T::container( 'cc-container--narrow', [ T::heading( '{post_title}', 'h1' ) ] ),
			], 'Hero' ),
			self::rich_section( $html, '', 'Legal text' ),
		];
	}
}

<?php
/**
 * Privacy, terms and cookie policy copy for legal pages (Bricks {cc_*} tags supported).
 */

defined( 'ABSPATH' ) || exit;

function cc_legal_updated_line(): string {
	return '<p class="cc-legal-updated"><em>Last updated: ' . esc_html( wp_date( 'j F Y' ) ) . '.</em></p>';
}

function cc_legal_privacy_html(): string {
	$privacy = esc_url( home_url( '/privacy-policy/' ) );
	$cookies = esc_url( home_url( '/cookie-policy/' ) );

	return cc_legal_updated_line()
		. '<p>This privacy policy explains how <strong>{cc_option:legal_name}</strong> (“we”, “us”, “our”) collects and uses personal information when you use our website, request a quote, or book a cleaning service. We are the data controller for this information.</p>'
		. '<h2>Contact us</h2><p>If you have questions about this policy or your data, contact us at {cc_email_link} or {cc_phone_link}.'
		. ( cc_full_address() ? ' You can also write to us at {cc_full_address}.' : '' ) . '</p>'
		. '<h2>What we collect</h2><p>Depending on how you use our services, we may collect:</p><ul>'
		. '<li><strong>Identity and contact details</strong> — name, email address, phone number and billing address.</li>'
		. '<li><strong>Property and booking details</strong> — service address, property type, access instructions, dates and times, and information you give us about the clean (for example rooms, pets, or special requirements).</li>'
		. '<li><strong>Quote and payment information</strong> — details needed to prepare quotes, take payment and keep accounting records. Card payments are processed by our payment provider; we do not store full card numbers on our servers.</li>'
		. '<li><strong>Communications</strong> — messages you send us by email, phone, or through our website forms.</li>'
		. '<li><strong>Website usage data</strong> — if you accept analytics cookies, we receive aggregated information about how visitors use our site (see our <a href="' . $cookies . '">Cookie policy</a>).</li>'
		. '</ul>'
		. '<h2>How we use your information</h2><p>We use personal data to:</p><ul>'
		. '<li>respond to enquiries and provide quotes;</li>'
		. '<li>deliver, manage and invoice cleaning services;</li>'
		. '<li>contact you about bookings, changes, or service issues;</li>'
		. '<li>meet legal, tax and insurance obligations;</li>'
		. '<li>improve our website and services (where permitted).</li>'
		. '</ul>'
		. '<p>Our lawful bases under UK data protection law include <strong>contract</strong> (to provide the service you asked for), <strong>legitimate interests</strong> (to run and improve our business in a proportionate way), <strong>legal obligation</strong>, and <strong>consent</strong> where we rely on it (for example non-essential cookies or optional marketing).</p>'
		. '<h2>Marketing</h2><p>We do not sell your data. We only send marketing messages if you have agreed to receive them, and you can opt out at any time.</p>'
		. '<h2>Who we share data with</h2><p>We share information only where necessary with trusted providers who help us operate, such as:</p><ul>'
		. '<li>booking and quote management systems;</li>'
		. '<li>payment processors;</li>'
		. '<li>email and SMS providers;</li>'
		. '<li>website hosting, security and analytics providers (analytics only if you consent).</li>'
		. '</ul><p>These providers process data on our instructions and must protect it appropriately. We may also disclose information if required by law or to protect our rights, customers, or staff.</p>'
		. '<h2>International transfers</h2><p>Some providers (for example analytics or email tools) may process data outside the UK. Where that happens, we use appropriate safeguards such as UK adequacy regulations or standard contractual clauses.</p>'
		. '<h2>How long we keep data</h2><ul>'
		. '<li><strong>Customer and booking records</strong> — for as long as you are a customer and for up to 6 years afterwards for tax and accounting purposes.</li>'
		. '<li><strong>Quote enquiries that do not become bookings</strong> — typically up to 12 months, unless we need to keep them longer for a legitimate reason.</li>'
		. '<li><strong>Website analytics</strong> — according to the retention settings in our analytics tools and your cookie choices.</li>'
		. '</ul>'
		. '<h2>Your rights</h2><p>You have rights including access, correction, erasure, restriction, objection, and data portability in certain cases. You can withdraw consent at any time where we rely on consent. To exercise your rights, contact us using the details above.</p>'
		. '<p>You also have the right to complain to the Information Commissioner’s Office (ICO) at <a href="https://ico.org.uk/" rel="noopener noreferrer">ico.org.uk</a>.</p>'
		. '<h2>Changes</h2><p>We may update this policy from time to time. The latest version will always be published at <a href="' . $privacy . '">this page</a>.</p>';
}

function cc_legal_terms_html(): string {
	$privacy = esc_url( home_url( '/privacy-policy/' ) );

	return cc_legal_updated_line()
		. '<p>These terms apply when you request a quote or book a cleaning service with <strong>{cc_option:legal_name}</strong> (“we”, “us”, “our”). By booking, you agree to these terms. Our <a href="' . $privacy . '">Privacy policy</a> explains how we handle your personal data.</p>'
		. '<h2>Our services</h2><p>We provide domestic and commercial cleaning services in Hull and East Yorkshire. The work we carry out is described in your quote, booking confirmation, or service checklist agreed with you.</p>'
		. '<h2>Quotes and pricing</h2><p>Quotes are based on the information you provide. Prices are fixed for the agreed scope unless we agree changes with you first — for example if the property is significantly larger, dirtier, or different from what was described.</p>'
		. '<h2>Bookings</h2><p>A booking is confirmed when we accept it and you receive confirmation (by email or through your online account). Please check the date, time, address and service details when you book.</p>'
		. '<h2>Cancellations and rescheduling</h2><p>Please give us at least <strong>48 hours’ notice</strong> to cancel or rearrange a clean. If you cancel with less notice, we may charge up to <strong>50% of the booking value</strong> to cover lost time and travel.</p>'
		. '<h2>Access and your responsibilities</h2><p>You agree to provide safe access to the property at the booked time, including keys or entry codes where agreed. You should ensure there is working electricity and hot water, and that valuables and fragile items are secured. Please tell us about hazards, pets, or areas that should not be entered.</p>'
		. '<h2>Our guarantee</h2><p>{cc_option_html:guarantee_short}</p>'
		. '<h2>Damage and breakages</h2><p>We take care in your home or workplace and we are fully insured. Please report any damage or breakage within <strong>48 hours</strong> of the clean so we can investigate promptly.</p>'
		. '<h2>Payment</h2><p>Unless we agree otherwise, payment is due when the clean is completed. For regular or business customers we may invoice monthly with <strong>14-day</strong> payment terms. Late payment may affect future bookings.</p>'
		. '<h2>Complaints</h2><p>If something has gone wrong, contact us at {cc_email_link} or {cc_phone_link} as soon as possible so we can put it right.</p>'
		. '<h2>Limitation of liability</h2><p>Nothing in these terms limits our liability for death or personal injury caused by our negligence, fraud, or any other liability that cannot be excluded by law. Subject to that, our liability for any loss arising from a booking is limited to the amount you paid for that booking, except where a higher amount is required by law or covered by our insurance.</p>'
		. '<h2>General</h2><p>These terms are governed by the laws of England and Wales. If any part of these terms is found to be unenforceable, the rest remains in effect.</p>';
}

function cc_legal_cookies_html(): string {
	$privacy = esc_url( home_url( '/privacy-policy/' ) );

	return cc_legal_updated_line()
		. '<p>This policy explains how <strong>{cc_option:legal_name}</strong> uses cookies and similar technologies on <a href="' . esc_url( home_url( '/' ) ) . '">northbankcleaning.co.uk</a>. For how we use personal data more generally, see our <a href="' . $privacy . '">Privacy policy</a>.</p>'
		. '<h2>What are cookies?</h2><p>Cookies are small text files placed on your device when you visit a website. They help sites work, remember preferences, and (with your permission) understand how visitors use the site.</p>'
		. '<h2>How you choose</h2><p>When you first visit, we show a cookie banner. <strong>Analytics cookies are only set if you click Accept.</strong> You can change your mind at any time using <a href="#cookie-settings">Cookie settings</a> in the website footer, or by clearing cookies in your browser.</p>'
		. '<h2>Cookies we use</h2>'
		. '<h3>Strictly necessary</h3><p>These are required for the site to function securely — for example WordPress session cookies, security features, and load-balancing. They do not need consent under UK rules.</p>'
		. '<h3>Analytics (optional)</h3><p>If you accept analytics cookies, we use <strong>Google Analytics 4</strong> to collect aggregated information such as pages visited, approximate location, device type and how you found us. This helps us improve the website. Google may set cookies such as <code>_ga</code> and <code>_ga_*</code>. We do not use analytics cookies for advertising.</p>'
		. '<h3>Local storage</h3><p>We store your cookie choice (accept or reject) in your browser’s local storage under the key <code>cc-consent</code> so we do not ask you on every page.</p>'
		. '<h2>Third-party cookies</h2><p>Some embedded content or tools may set their own cookies. We keep third-party scripts to a minimum on public pages.</p>'
		. '<h2>Managing cookies in your browser</h2><p>Most browsers let you block or delete cookies. Blocking all cookies may affect how the site works. Guidance is available from your browser provider or at <a href="https://www.aboutcookies.org/" rel="noopener noreferrer">aboutcookies.org</a>.</p>'
		. '<h2>Updates</h2><p>We may update this policy when our site or tools change. Please check this page periodically.</p>';
}

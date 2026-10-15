<?php
/**
 * A quote in the client area: /account/quotes/{ref}/. "Accept & confirm" posts here and opens Stripe Checkout
 * (Checkout::start: re-price, slot check, 30-minute hold). Override with {theme}/cc-bookings/client-quote.php.
 *
 * @var array      $quote
 * @var array      $client
 * @var array|null $flash  One-time message after an action: [ type, message, change ].
 */

use CC\Bookings\Bookings;
use CC\Bookings\Catalog;
use CC\Bookings\Client_Portal;
use CC\Bookings\Db;
use CC\Bookings\Money;
use CC\Bookings\Pricing;
use CC\Bookings\Quote_Revisions;
use CC\Bookings\Quotes;
use CC\Bookings\Settings;
use CC\Bookings\Slots;
use CC\Bookings\Stripe;

defined( 'ABSPATH' ) || exit;

$service  = Quotes::service( $quote );
$expired  = Quotes::is_expired( $quote );
$payable  = Quotes::is_payable( $quote );
$booking  = $quote['booking_id'] ? Bookings::get( (int) $quote['booking_id'] ) : null;
$checkout = sanitize_key( wp_unslash( $_GET['checkout'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
$priced   = null !== $quote['total_pence'] && 'draft' !== $quote['status'];
$closed   = in_array( $quote['status'], [ 'superseded', 'cancelled' ], true );
$when     = $quote['job_date'] ? Slots::date_label( $quote['job_date'] ) . ( $quote['slot'] ? ', ' . Slots::label( $quote['slot'] ) : '' ) : 'To be agreed';
$details  = $service ? Catalog::summary( $service, (array) $quote['answers'] ) : [];
$addons   = $service ? Catalog::addon_names( $service, (array) $quote['answers'] ) : [];
$policy   = (string) Settings::get( 'quotes.cancellation_policy', '' );
$address  = implode( ', ', array_filter( [ $quote['address_1'], $quote['address_2'], $quote['town'], $quote['postcode'] ] ) );
$nonce    = Client_Portal::nonce_action( $client );
$changes  = ! $booking && ! $closed && ! empty( $quote['revised_at'] ) ? Quote_Revisions::latest_changes( $quote ) : [];
$requote  = function ( string $label, string $class = 'cc-btn cc-btn--outline' ) use ( $nonce ): void {
	echo '<form method="post" class="cc-client__inline">';
	Client_Portal::nonce_field( $nonce );
	printf( '<input type="hidden" name="cc_do" value="requote"><button type="submit" class="%s">%s</button></form>', esc_attr( $class ), esc_html( $label ) );
};

get_header();
?>
<main id="brx-content" class="cc-private cc-client">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-private__wrap">
			<p class="cc-client__back"><a href="<?php echo esc_url( Client_Portal::url( 'quotes' ) ); ?>">&larr; All quotes</a></p>
			<header class="cc-private__head">
				<p class="cc-eyebrow">Quote <?php echo esc_html( $quote['ref'] ); ?></p>
				<h1 class="cc-private__title">Your <?php echo esc_html( $quote['service_name'] ); ?> quote</h1>
				<p class="cc-lead">
					<?php if ( $booking ) : ?>
						You're booked — thank you.
					<?php elseif ( 'draft' === $quote['status'] ) : ?>
						We're preparing your tailored price and will email you as soon as it's ready.
					<?php elseif ( $expired ) : ?>
						This quote has expired.
					<?php elseif ( $payable ) : ?>
						Here's your fixed price. Check the details, then <?php echo Stripe::enabled() ? 'accept and pay securely to confirm your booking' : 'accept your quote and we\'ll call you to confirm'; ?>.
					<?php endif; ?>
				</p>
				<?php if ( ! empty( $quote['revised_at'] ) ) : ?>
					<p class="cc-small cc-client__updated">Updated on <?php echo esc_html( wp_date( 'l j F Y, g:ia', Db::timestamp( $quote['revised_at'] ) ) ); ?></p>
				<?php endif; ?>
			</header>

			<?php if ( $flash ) : ?>
				<div class="cc-notice cc-notice--<?php echo 'success' === $flash['type'] ? 'success' : 'warning'; ?>" role="<?php echo 'success' === $flash['type'] ? 'status' : 'alert'; ?>">
					<p><?php echo esc_html( (string) $flash['message'] ); ?></p>
					<?php if ( ! empty( $flash['change'] ) && ( $payable || $expired ) ) : ?>
						<?php $requote( 'Change details' ); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( 'cancelled' === $checkout && ! $booking && $payable ) : ?>
				<div class="cc-notice">
					<strong>Payment cancelled.</strong>
					<p>Nothing has been charged. Your quote is still available below.</p>
				</div>
			<?php endif; ?>

			<?php if ( $booking ) : ?>
				<div class="cc-notice cc-notice--success">
					<strong>Booking <?php echo esc_html( $booking['ref'] ); ?> is confirmed.</strong>
					<p><a href="<?php echo esc_url( Client_Portal::url( 'bookings/' . $booking['ref'] ) ); ?>">View your booking</a></p>
				</div>
			<?php elseif ( $expired ) : ?>
				<div class="cc-notice cc-notice--warning">
					<strong>This quote has expired — get an updated price.</strong>
					<p>Prices and availability change, so we need to re-check them. It only takes a minute with your details pre-filled.</p>
					<?php $requote( 'Get an updated price', 'cc-btn cc-btn--primary' ); ?>
				</div>
			<?php elseif ( $closed ) : ?>
				<div class="cc-notice cc-notice--warning">
					<strong><?php echo 'superseded' === $quote['status'] ? 'This quote has been replaced by a newer one.' : 'This quote has been cancelled.'; ?></strong>
					<p><a href="<?php echo esc_url( Client_Portal::url( 'quotes' ) ); ?>">See your other quotes</a></p>
				</div>
			<?php endif; ?>

			<div class="cc-private__grid">
				<div class="cc-private__main">
					<?php if ( $changes ) : ?>
						<section class="cc-panel cc-private__card cc-client__changes">
							<h2 class="cc-private__heading">What we've changed</h2>
							<?php echo Quote_Revisions::html( $changes, null === $quote['total_pence'] ? null : (int) $quote['total_pence'], '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Quote_Revisions::line_html(). ?>
						</section>
					<?php endif; ?>
					<?php if ( $priced ) : ?>
						<section class="cc-panel cc-private__card">
							<h2 class="cc-private__heading">Your price</h2>
							<table class="cc-private__items">
								<tbody>
									<?php foreach ( (array) $quote['items'] as $item ) : ?>
										<tr class="cc-private__item cc-private__item--<?php echo esc_attr( $item['type'] ?? 'base' ); ?>">
											<td><?php echo esc_html( $item['label'] . ( ( $item['qty'] ?? 1 ) > 1 ? ' × ' . rtrim( rtrim( number_format( (float) $item['qty'], 2 ), '0' ), '.' ) : '' ) ); ?></td>
											<td class="cc-private__amount"><?php echo esc_html( Money::format( (int) $item['amount'] ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
								<tfoot>
									<tr>
										<th scope="row">Total</th>
										<td class="cc-private__amount"><?php echo esc_html( Money::format( (int) $quote['total_pence'] ) ); ?></td>
									</tr>
								</tfoot>
							</table>
							<?php if ( $duration = Pricing::duration_label( (float) $quote['hours'], (int) $quote['cleaners'] ) ) : ?>
								<p class="cc-small"><?php echo esc_html( $duration ); ?>. Fixed price — no hidden extras.</p>
							<?php endif; ?>
							<?php if ( 'regular' === ( $service['profile'] ?? '' ) && ( $note = apply_filters( 'cc_bookings_quote_regular_note', Settings::get( 'quotes.regular_note' ), $quote ) ) ) : ?>
								<p class="cc-small"><?php echo esc_html( $note ); ?></p>
							<?php endif; ?>
							<?php do_action( 'cc_bookings_quote_price_after', $quote ); ?>
						</section>
					<?php endif; ?>

					<section class="cc-panel cc-private__card">
						<h2 class="cc-private__heading">The job</h2>
						<dl class="cc-private__details">
							<div><dt>Service</dt><dd><?php echo esc_html( $quote['service_name'] ); ?></dd></div>
							<div><dt>Date</dt><dd><?php echo esc_html( $when ); ?></dd></div>
							<div><dt>Address</dt><dd><?php echo esc_html( $address ); ?></dd></div>
							<?php foreach ( $details as [ $label, $value ] ) : ?>
								<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo nl2br( esc_html( (string) $value ) ); ?></dd></div>
							<?php endforeach; ?>
							<?php if ( $addons ) : ?>
								<div><dt>Extras</dt><dd><?php echo esc_html( implode( ', ', $addons ) ); ?></dd></div>
							<?php endif; ?>
							<?php if ( $quote['access_notes'] ) : ?>
								<div><dt>Access notes</dt><dd><?php echo nl2br( esc_html( (string) $quote['access_notes'] ) ); ?></dd></div>
							<?php endif; ?>
						</dl>
						<?php if ( $payable ) : ?>
							<?php $requote( 'Change details' ); ?>
						<?php endif; ?>
					</section>

					<?php if ( $service && function_exists( 'cc_checklist_html' ) && ( $checklist = cc_checklist_html( (int) $service['id'] ) ) ) : ?>
						<section class="cc-panel cc-private__card">
							<h2 class="cc-private__heading">What's included</h2>
							<?php echo $checklist; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the theme component. ?>
						</section>
					<?php endif; ?>

					<section class="cc-panel cc-private__card">
						<h2 class="cc-private__heading">Our promise and cancellation policy</h2>
						<?php echo function_exists( 'cc_guarantee_html' ) ? cc_guarantee_html( (int) ( $service['id'] ?? 0 ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php if ( $policy ) : ?>
							<ul class="cc-ticks">
								<?php foreach ( preg_split( '/\r\n|\r|\n/', trim( $policy ) ) as $line ) : ?>
									<li><?php echo esc_html( $line ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</section>
				</div>

				<aside class="cc-private__aside">
					<?php if ( $payable && $priced ) : ?>
						<form class="cc-panel cc-private__pay" method="post" id="cc-accept">
							<?php Client_Portal::nonce_field( $nonce ); ?>
							<input type="hidden" name="cc_do" value="accept">
							<input type="hidden" name="revision" value="<?php echo (int) Quote_Revisions::revision( $quote ); ?>">
							<p class="cc-private__total-label"><?php echo esc_html( apply_filters( 'cc_bookings_quote_pay_label', 'Total to pay', $quote ) ); ?></p>
							<p class="cc-private__total"><?php echo esc_html( Money::format( (int) $quote['total_pence'] ) ); ?></p>
							<p class="cc-small">Quote held until <?php echo esc_html( wp_date( 'l j F', Db::timestamp( $quote['expires_at'] ) ) ); ?>.</p>
							<label class="cc-quote__check">
								<input type="checkbox" name="terms" value="1" required>
								<span>I agree to the <a href="<?php echo esc_url( Settings::terms_url() ); ?>" target="_blank" rel="noopener">terms and conditions</a> and the cancellation policy.</span>
							</label>
							<?php if ( Quotes::needs_early_start( $quote ) ) : ?>
								<label class="cc-quote__check">
									<input type="checkbox" name="early_start" value="1" required>
									<span>I ask you to start the service within the 14-day cancellation period. I understand that if I cancel after the clean has started, I'll pay for the work already done.</span>
								</label>
							<?php endif; ?>
							<?php do_action( 'cc_bookings_quote_pay_fields', $quote ); ?>
							<?php if ( Stripe::enabled() ) : ?>
								<button type="submit" class="cc-btn cc-btn--primary cc-btn--block">Accept &amp; confirm</button>
								<p class="cc-small cc-private__secure">You'll pay on Stripe's secure page. Your slot is held for <?php echo (int) max( 30, (int) Settings::get( 'booking.hold_minutes', 30 ) ); ?> minutes while you pay.</p>
							<?php else : ?>
								<button type="submit" class="cc-btn cc-btn--primary cc-btn--block">Accept &amp; confirm</button>
								<p class="cc-small cc-private__secure">We'll call you to take payment and confirm your booking.</p>
							<?php endif; ?>
						</form>
					<?php endif; ?>

					<?php if ( ! $booking && ! $closed ) : ?>
						<details class="cc-panel cc-private__ask">
							<summary>Ask a question</summary>
							<form method="post">
								<?php Client_Portal::nonce_field( $nonce ); ?>
								<input type="hidden" name="cc_do" value="question">
								<label for="cc-question">Your question</label>
								<textarea id="cc-question" name="message" rows="4" maxlength="2000" required></textarea>
								<button type="submit" class="cc-btn cc-btn--outline">Send question</button>
							</form>
						</details>
					<?php endif; ?>
				</aside>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();

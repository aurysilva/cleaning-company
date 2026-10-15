<?php
/**
 * Private quote page: /quote/{ref}/{token}/. Override with {theme}/cc-bookings/quote.php.
 *
 * @var array  $quote
 * @var string $token
 */

use CC\Bookings\Bookings;
use CC\Bookings\Catalog;
use CC\Bookings\Money;
use CC\Bookings\Pricing;
use CC\Bookings\Quotes;
use CC\Bookings\Settings;
use CC\Bookings\Slots;

defined( 'ABSPATH' ) || exit;

$service  = Quotes::service( $quote );
$expired  = Quotes::is_expired( $quote );
$payable  = Quotes::is_payable( $quote );
$booking  = $quote['booking_id'] ? Bookings::get( (int) $quote['booking_id'] ) : null;
$checkout = sanitize_key( wp_unslash( $_GET['checkout'] ?? '' ) );
$priced   = null !== $quote['total_pence'] && 'draft' !== $quote['status'];
$form_url = function_exists( 'cc_quote_url' ) ? cc_quote_url() : home_url( '/contact/' );
$change   = add_query_arg( [ 'quote' => $quote['ref'], 'token' => $token ], $form_url ) . '#quote';
$when     = $quote['job_date'] ? Slots::date_label( $quote['job_date'] ) . ( $quote['slot'] ? ', ' . Slots::label( $quote['slot'] ) : '' ) : 'To be agreed';
$details  = $service ? Catalog::summary( $service, (array) $quote['answers'] ) : [];
$addons   = $service ? Catalog::addon_names( $service, (array) $quote['answers'] ) : [];
$policy   = (string) Settings::get( 'quotes.cancellation_policy', '' );

get_header();
?>
<main id="brx-content" class="cc-private">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-private__wrap">
			<header class="cc-private__head">
				<p class="cc-eyebrow">Quote <?php echo esc_html( $quote['ref'] ); ?></p>
				<h1 class="cc-private__title">Your <?php echo esc_html( $quote['service_name'] ); ?> quote</h1>
				<p class="cc-lead">
					<?php if ( $booking ) : ?>
						You're booked — thank you, <?php echo esc_html( strtok( $quote['customer_name'], ' ' ) ); ?>.
					<?php elseif ( 'draft' === $quote['status'] ) : ?>
						We're preparing your tailored price and will email you as soon as it's ready.
					<?php elseif ( $expired ) : ?>
						This quote has expired.
					<?php else : ?>
						Hi <?php echo esc_html( strtok( $quote['customer_name'], ' ' ) ); ?>, here's your fixed price. Check the details, then <?php echo CC\Bookings\Stripe::enabled() ? 'book and pay securely' : 'accept your quote and we\'ll call you to confirm'; ?>.
					<?php endif; ?>
				</p>
			</header>

			<?php if ( 'success' === $checkout && ! $booking ) : ?>
				<div class="cc-notice cc-notice--info" data-cc-refresh="5">
					<strong>Payment received — confirming your booking…</strong>
					<p>This usually takes a few seconds. This page will refresh by itself, and we'll email your confirmation.</p>
				</div>
			<?php elseif ( 'cancelled' === $checkout && ! $booking && $payable ) : ?>
				<div class="cc-notice">
					<strong>Payment cancelled.</strong>
					<p>Nothing has been charged. Your quote is still available below.</p>
				</div>
			<?php endif; ?>

			<?php if ( $booking ) : ?>
				<div class="cc-notice cc-notice--success">
					<strong>Booking <?php echo esc_html( $booking['ref'] ); ?> is confirmed.</strong>
					<p>We've emailed your confirmation and a link to view your booking. We'll let you know who your cleaner is before the day.</p>
				</div>
			<?php elseif ( $expired ) : ?>
				<div class="cc-notice cc-notice--warning">
					<strong>This quote has expired — get an updated price.</strong>
					<p>Prices and availability change, so we need to re-check them. It only takes a minute with your details pre-filled.</p>
					<p><a class="cc-btn cc-btn--primary" href="<?php echo esc_url( $change ); ?>">Get an updated price</a></p>
				</div>
			<?php elseif ( in_array( $quote['status'], [ 'superseded', 'cancelled' ], true ) ) : ?>
				<div class="cc-notice cc-notice--warning">
					<strong><?php echo 'superseded' === $quote['status'] ? 'This quote has been replaced by a newer one.' : 'This quote has been cancelled.'; ?></strong>
					<p>Please use the link in your most recent email, or <a href="<?php echo esc_url( $form_url ); ?>">get a new quote</a>.</p>
				</div>
			<?php endif; ?>

			<div class="cc-private__grid">
				<div class="cc-private__main">
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
							<?php if ( 'regular' === ( $service['profile'] ?? '' ) && ( $note = Settings::get( 'quotes.regular_note' ) ) ) : ?>
								<p class="cc-small"><?php echo esc_html( $note ); ?></p>
							<?php endif; ?>
						</section>
					<?php endif; ?>

					<section class="cc-panel cc-private__card">
						<h2 class="cc-private__heading">The job</h2>
						<dl class="cc-private__details">
							<div><dt>Service</dt><dd><?php echo esc_html( $quote['service_name'] ); ?></dd></div>
							<div><dt>Date</dt><dd><?php echo esc_html( $when ); ?></dd></div>
							<div><dt>Address</dt><dd><?php echo esc_html( Quotes::masked_address( $quote ) ); ?></dd></div>
							<?php foreach ( $details as [ $label, $value ] ) : ?>
								<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo nl2br( esc_html( (string) $value ) ); ?></dd></div>
							<?php endforeach; ?>
							<?php if ( $addons ) : ?>
								<div><dt>Extras</dt><dd><?php echo esc_html( implode( ', ', $addons ) ); ?></dd></div>
							<?php endif; ?>
						</dl>
						<?php if ( $payable || $expired ) : ?>
							<p><a href="<?php echo esc_url( $change ); ?>">Change details</a></p>
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
					<?php if ( $payable ) : ?>
						<form class="cc-panel cc-private__pay" novalidate data-cc-accept data-ref="<?php echo esc_attr( $quote['ref'] ); ?>" data-token="<?php echo esc_attr( $token ); ?>">
							<p class="cc-private__total-label">Total to pay</p>
							<p class="cc-private__total"><?php echo esc_html( Money::format( (int) $quote['total_pence'] ) ); ?></p>
							<p class="cc-small">Quote held until <?php echo esc_html( wp_date( 'l j F', CC\Bookings\Db::timestamp( $quote['expires_at'] ) ) ); ?>.</p>
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
							<p class="cc-quote__error" role="alert" data-error hidden></p>
							<?php if ( CC\Bookings\Stripe::enabled() ) : ?>
								<button type="submit" class="cc-btn cc-btn--primary cc-btn--block" data-pay>Accept &amp; pay</button>
								<p class="cc-small cc-private__secure">Secure payment by Stripe. Your slot is held for <?php echo (int) max( 30, (int) Settings::get( 'booking.hold_minutes', 30 ) ); ?> minutes while you pay.</p>
							<?php else : ?>
								<button type="submit" class="cc-btn cc-btn--primary cc-btn--block" data-pay>Accept quote</button>
								<p class="cc-small cc-private__secure">We'll call you to take payment and confirm your booking.</p>
							<?php endif; ?>
						</form>
					<?php endif; ?>

					<?php if ( ! $booking && ! in_array( $quote['status'], [ 'superseded', 'cancelled' ], true ) ) : ?>
						<details class="cc-panel cc-private__ask">
							<summary>Ask a question</summary>
							<form data-cc-question data-ref="<?php echo esc_attr( $quote['ref'] ); ?>" data-token="<?php echo esc_attr( $token ); ?>">
								<label for="cc-question">Your question</label>
								<textarea id="cc-question" name="message" rows="4" maxlength="2000" required></textarea>
								<p class="cc-quote__error" role="alert" data-error hidden></p>
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

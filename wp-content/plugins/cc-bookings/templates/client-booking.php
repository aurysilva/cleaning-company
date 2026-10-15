<?php
/**
 * A booking in the client area: /account/bookings/{ref}/. The same panels as the private booking page (reschedule /
 * cancel, regular plan, re-clean) post back here; Client_Portal checks ownership and runs them.
 * Override with {theme}/cc-bookings/client-booking.php.
 *
 * @var array $booking
 * @var array $client
 */

use CC\Bookings\Bookings;
use CC\Bookings\Catalog;
use CC\Bookings\Client_Portal;
use CC\Bookings\Db;
use CC\Bookings\Money;
use CC\Bookings\Notify;
use CC\Bookings\Pricing;
use CC\Bookings\Quotes;
use CC\Bookings\Status;

defined( 'ABSPATH' ) || exit;

$service  = Catalog::get( $booking['service_slug'] );
$items    = Bookings::items( (int) $booking['id'] );
$payments = Bookings::payments( (int) $booking['id'] );
$topups   = array_filter( $payments, fn( $p ) => 'top_up' === $p['type'] && 'pending' === $p['status'] && $p['url'] );
$history  = array_filter( $payments, fn( $p ) => 'succeeded' === $p['status'] );
$crew     = array_filter( array_map( fn( $o ) => $o['cleaner'] ? Notify::first_name( $o['cleaner']['name'] ) : '', Bookings::crew( (int) $booking['id'] ) ) );
$quote    = $booking['quote_id'] ? Quotes::get( (int) $booking['quote_id'] ) : null;
$checkout = sanitize_key( wp_unslash( $_GET['checkout'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
// Filtered before get_header() so a callback can enqueue its styles in the head.
$crew_html = $crew ? (string) apply_filters( 'cc_bookings_booking_crew', '', $booking ) : '';
$types     = [ 'charge' => 'Payment', 'top_up' => 'Additional payment', 'refund' => 'Refund' ];

get_header();
?>
<main id="brx-content" class="cc-private cc-client">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-private__wrap">
			<p class="cc-client__back"><a href="<?php echo esc_url( Client_Portal::url( 'bookings' ) ); ?>">&larr; All bookings</a></p>
			<header class="cc-private__head">
				<p class="cc-eyebrow">Booking <?php echo esc_html( $booking['ref'] ); ?></p>
				<h1 class="cc-private__title"><?php echo esc_html( $booking['service_name'] ); ?></h1>
				<p class="cc-lead"><?php echo esc_html( Bookings::start_label( $booking ) ); ?></p>
			</header>

			<?php if ( 'success' === $checkout && 'cancelled' !== $booking['status'] ) : ?>
				<div class="cc-notice cc-notice--success" role="status">
					<strong>Thank you — your payment has been received.</strong>
					<p>We've emailed your confirmation. You can manage this booking here any time.</p>
				</div>
			<?php endif; ?>

			<?php if ( $notice = apply_filters( 'cc_bookings_booking_page_notice', '', $booking ) ) : ?>
				<?php echo wp_kses_post( $notice ); ?>
			<?php elseif ( 'cancelled' === $booking['status'] ) : ?>
				<div class="cc-notice cc-notice--warning"><strong>This booking has been cancelled.</strong></div>
			<?php elseif ( 'completed' === $booking['status'] ) : ?>
				<div class="cc-notice cc-notice--success"><strong>This clean is complete. Thank you for choosing us!</strong></div>
			<?php else : ?>
				<div class="cc-notice cc-notice--success">
					<strong>Your booking is confirmed.</strong>
					<?php if ( $crew_html ) : ?>
						<?php echo wp_kses_post( $crew_html ); ?>
					<?php else : ?>
						<p><?php echo $crew ? esc_html( 'Your cleaner' . ( count( $crew ) > 1 ? 's: ' : ': ' ) . implode( ', ', $crew ) . '.' ) : 'We\'ll let you know who your cleaner is before the day.'; ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $topups ) : ?>
				<div class="cc-notice cc-notice--warning" id="cc-topup">
					<?php foreach ( $topups as $p ) : ?>
						<p><strong><?php echo esc_html( Money::format( (int) $p['amount_pence'] ) ); ?> to pay</strong> — <?php echo esc_html( (string) ( $p['note'] ?: 'Additional charge' ) ); ?></p>
						<p><a class="cc-btn cc-btn--primary" href="<?php echo esc_url( (string) $p['url'] ); ?>" rel="noopener">Pay <?php echo esc_html( Money::format( (int) $p['amount_pence'] ) ); ?> securely</a></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="cc-private__grid">
				<div class="cc-private__main">
					<section class="cc-panel cc-private__card">
						<h2 class="cc-private__heading">Details</h2>
						<dl class="cc-private__details">
							<div><dt>Status</dt><dd><?php echo esc_html( Status::customer_label( 'booking', $booking['status'] ) ); ?></dd></div>
							<div><dt>When</dt><dd><?php echo esc_html( Bookings::start_label( $booking ) ); ?></dd></div>
							<div><dt>Estimated time</dt><dd><?php echo esc_html( Pricing::duration_label( (float) $booking['hours'], (int) $booking['cleaners'] ) ); ?></dd></div>
							<div><dt>Address</dt><dd><?php echo esc_html( Bookings::full_address( $booking ) ); ?></dd></div>
							<?php foreach ( $service ? Catalog::summary( $service, (array) $booking['answers'] ) : [] as [ $label, $value ] ) : ?>
								<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo nl2br( esc_html( (string) $value ) ); ?></dd></div>
							<?php endforeach; ?>
							<?php if ( $booking['access_notes'] ) : ?>
								<div><dt>Access notes</dt><dd><?php echo nl2br( esc_html( (string) $booking['access_notes'] ) ); ?></dd></div>
							<?php endif; ?>
							<?php if ( $quote ) : ?>
								<div><dt>Quote</dt><dd><a href="<?php echo esc_url( Client_Portal::url( 'quotes/' . $quote['ref'] ) ); ?>"><?php echo esc_html( $quote['ref'] ); ?></a></dd></div>
							<?php endif; ?>
						</dl>
					</section>

					<section class="cc-panel cc-private__card">
						<h2 class="cc-private__heading">Receipt</h2>
						<table class="cc-private__items">
							<tbody>
								<?php foreach ( $items as $item ) : ?>
									<tr>
										<td><?php echo esc_html( $item['label'] . ( (float) $item['quantity'] > 1 ? ' × ' . rtrim( rtrim( $item['quantity'], '0' ), '.' ) : '' ) ); ?></td>
										<td class="cc-private__amount"><?php echo esc_html( Money::format( (int) $item['amount_pence'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<tr><th scope="row">Total</th><td class="cc-private__amount"><?php echo esc_html( Money::format( (int) $booking['total_pence'] ) ); ?></td></tr>
								<tr><th scope="row">Paid</th><td class="cc-private__amount"><?php echo esc_html( Money::format( (int) $booking['paid_pence'] ) ); ?></td></tr>
								<?php if ( (int) $booking['refunded_pence'] > 0 ) : ?>
									<tr><th scope="row">Refunded</th><td class="cc-private__amount"><?php echo esc_html( Money::format( (int) $booking['refunded_pence'] ) ); ?></td></tr>
								<?php endif; ?>
							</tfoot>
						</table>
						<?php if ( $history ) : ?>
							<ul class="cc-client__payments">
								<?php foreach ( $history as $p ) : ?>
									<li>
										<span><?php echo esc_html( ( $types[ $p['type'] ] ?? 'Payment' ) . ' · ' . wp_date( 'j M Y', Db::timestamp( $p['created_at'] ) ) ); ?></span>
										<span><?php echo esc_html( ( 'refund' === $p['type'] ? '−' : '' ) . Money::format( (int) $p['amount_pence'] ) ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="cc-small">Card receipts come from Stripe by email.</p>
						<?php endif; ?>
					</section>
				</div>
				<aside class="cc-private__aside">
					<?php do_action( 'cc_bookings_booking_page_actions', $booking, '' ); ?>
				</aside>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();

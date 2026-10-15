<?php
/**
 * Customer booking page: /booking/{ref}/{token}/. Phase 3 adds reschedule / cancel here; phase 4 adds the
 * regular-plan panel and the status notice for plan visits (filter cc_bookings_booking_page_notice).
 * Override with {theme}/cc-bookings/booking.php.
 *
 * @var array  $booking
 * @var string $token
 */

use CC\Bookings\Bookings;
use CC\Bookings\Catalog;
use CC\Bookings\Money;
use CC\Bookings\Notify;
use CC\Bookings\Pricing;
use CC\Bookings\Status;

defined( 'ABSPATH' ) || exit;

$service = Catalog::get( $booking['service_slug'] );
$items   = Bookings::items( (int) $booking['id'] );
$crew    = array_filter( array_map( fn( $o ) => $o['cleaner'] ? Notify::first_name( $o['cleaner']['name'] ) : '', Bookings::crew( (int) $booking['id'] ) ) );
// Filtered before get_header() so a callback can enqueue its styles in the head.
$crew_html = $crew ? (string) apply_filters( 'cc_bookings_booking_crew', '', $booking ) : '';

get_header();
?>
<main id="brx-content" class="cc-private">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-private__wrap">
			<header class="cc-private__head">
				<p class="cc-eyebrow">Booking <?php echo esc_html( $booking['ref'] ); ?></p>
				<h1 class="cc-private__title"><?php echo esc_html( $booking['service_name'] ); ?></h1>
				<p class="cc-lead"><?php echo esc_html( Bookings::start_label( $booking ) ); ?></p>
			</header>

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
						</dl>
					</section>
				</div>
				<aside class="cc-private__aside">
					<section class="cc-panel cc-private__card">
						<h2 class="cc-private__heading">Payment</h2>
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
								<tr><th scope="row">Paid</th><td class="cc-private__amount"><?php echo esc_html( Money::format( (int) $booking['paid_pence'] ) ); ?></td></tr>
								<?php if ( (int) $booking['refunded_pence'] > 0 ) : ?>
									<tr><th scope="row">Refunded</th><td class="cc-private__amount"><?php echo esc_html( Money::format( (int) $booking['refunded_pence'] ) ); ?></td></tr>
								<?php endif; ?>
							</tfoot>
						</table>
					</section>
					<?php do_action( 'cc_bookings_booking_page_actions', $booking, $token ); ?>
				</aside>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();

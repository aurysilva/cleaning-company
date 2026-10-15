<?php
/**
 * "My upcoming jobs" for a cleaner: /job/me/{cleaner_id}/{sig}/. Override with {theme}/cc-bookings/cleaner-jobs.php.
 *
 * @var array  $cleaner
 * @var string $sig
 */

use CC\Bookings\Bookings;
use CC\Bookings\Job_Links;
use CC\Bookings\Job_Pages;
use CC\Bookings\Money;
use CC\Bookings\Notify;
use CC\Bookings\Pricing;

defined( 'ABSPATH' ) || exit;

$lists = Job_Pages::cleaner_jobs( (int) $cleaner['id'] );

$row = function ( array $offer, array $booking, bool $mine ): void {
	$url = Job_Links::offer_url_for_cleaner( (int) $offer['id'], (int) $offer['cleaner_id'] );
	?>
	<div>
		<dt><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( Bookings::start_label( $booking ) ); ?></a></dt>
		<dd>
			<?php echo esc_html( $booking['service_name'] ); ?> ·
			<?php echo esc_html( $mine ? $booking['postcode'] : $booking['district'] ); ?> ·
			<?php echo esc_html( Pricing::duration_label( (float) $booking['hours'], 1 ) ); ?>
			<?php echo (int) $booking['cleaners'] > 1 ? esc_html( sprintf( ' · team of %d', $booking['cleaners'] ) ) : ''; ?>
			<?php echo (int) $offer['pay_pence'] ? esc_html( ' · ' . Money::format( (int) $offer['pay_pence'] ) ) : ''; ?>
		</dd>
	</div>
	<?php
};

get_header();
?>
<main id="brx-content" class="cc-private cc-job">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-private__wrap">
			<header class="cc-private__head">
				<p class="cc-eyebrow">Hi <?php echo esc_html( Notify::first_name( (string) $cleaner['name'] ) ); ?></p>
				<h1 class="cc-private__title">My upcoming jobs</h1>
				<p class="cc-lead">Keep this link private. Tap a job for the details.</p>
			</header>

			<?php if ( $lists['offers'] ) : ?>
				<section class="cc-panel cc-private__card">
					<h2 class="cc-private__heading">Offers waiting for you</h2>
					<dl class="cc-private__details">
						<?php foreach ( $lists['offers'] as [ $offer, $booking ] ) { $row( $offer, $booking, false ); } ?>
					</dl>
				</section>
			<?php endif; ?>

			<section class="cc-panel cc-private__card">
				<h2 class="cc-private__heading">Your jobs</h2>
				<?php if ( $lists['jobs'] ) : ?>
					<dl class="cc-private__details">
						<?php foreach ( $lists['jobs'] as [ $offer, $booking ] ) { $row( $offer, $booking, true ); } ?>
					</dl>
				<?php else : ?>
					<p>No upcoming jobs right now.</p>
				<?php endif; ?>
			</section>
		</div>
	</section>
</main>
<?php
get_footer();

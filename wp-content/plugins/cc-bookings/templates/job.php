<?php
/**
 * Cleaner job page: /job/{offer_id}/{sig}/. Before acceptance only the district is shown; the address,
 * access notes and customer's first name appear once the job is theirs, the phone number from 24 hours before.
 * Override with {theme}/cc-bookings/job.php.
 *
 * @var array       $offer
 * @var array       $booking
 * @var array       $cleaner
 * @var string      $view    open|taken|closed|declined|withdrawn|on_job|dropped|removed|completed|cancelled
 * @var array|null  $notice  [ type, message ]
 * @var string      $action  This page's URL (forms post back to it).
 * @var string      $jobs    "My jobs" URL.
 */

use CC\Bookings\Bookings;
use CC\Bookings\Catalog;
use CC\Bookings\Db;
use CC\Bookings\Dispatch;
use CC\Bookings\Job_Pages;
use CC\Bookings\Money;
use CC\Bookings\Notify;
use CC\Bookings\Pricing;

defined( 'ABSPATH' ) || exit;

$service  = Catalog::get( (string) $booking['service_slug'] );
$mine     = in_array( $view, [ 'on_job', 'completed' ], true );
$crew     = Bookings::crew( (int) $booking['id'] );
$team     = array_filter( array_map( fn( $o ) => (int) $o['cleaner_id'] !== (int) $cleaner['id'] && $o['cleaner'] ? $o['cleaner']['name'] : '', $crew ) );
$can_done = 'on_job' === $view && time() >= Dispatch::complete_from( $booking );
$phone    = $mine && Job_Pages::show_phone( $booking );
$states   = [
	'taken'     => [ 'warning', 'This job has been taken.', 'Thanks for your interest — we\'ll send you the next one.' ],
	'closed'    => [ 'warning', 'This job is no longer available.', '' ],
	'cancelled' => [ 'warning', 'This job has been cancelled.', 'You don\'t need to go.' ],
	'declined'  => [ 'info', 'You declined this job.', 'Changed your mind? If it\'s still free you can accept it below.' ],
	'withdrawn' => [ 'warning', 'This offer is no longer available.', '' ],
	'dropped'   => [ 'info', 'You told us you can\'t make this job.', 'The office has been told. Thanks for letting us know.' ],
	'removed'   => [ 'info', 'You\'re no longer on this job.', 'You don\'t need to go.' ],
	'completed' => [ 'success', 'Job complete. Thank you!', '' ],
];

get_header();
?>
<main id="brx-content" class="cc-private cc-job">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-private__wrap">
			<header class="cc-private__head">
				<p class="cc-eyebrow"><?php echo esc_html( $mine ? 'Your job ' . $booking['ref'] : 'Job offer' ); ?></p>
				<h1 class="cc-private__title"><?php echo esc_html( $booking['service_name'] ); ?></h1>
				<p class="cc-lead"><?php echo esc_html( Bookings::start_label( $booking ) ); ?></p>
			</header>

			<?php if ( $notice ) : ?>
				<div class="cc-notice cc-notice--<?php echo esc_attr( $notice[0] ); ?>" role="status"><strong><?php echo esc_html( $notice[1] ); ?></strong></div>
			<?php elseif ( isset( $states[ $view ] ) ) : ?>
				<div class="cc-notice cc-notice--<?php echo esc_attr( $states[ $view ][0] ); ?>">
					<strong><?php echo esc_html( $states[ $view ][1] ); ?></strong>
					<?php if ( $states[ $view ][2] ) : ?><p><?php echo esc_html( $states[ $view ][2] ); ?></p><?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="cc-private__grid">
				<div class="cc-private__main">
					<section class="cc-panel cc-private__card">
						<h2 class="cc-private__heading">The job</h2>
						<dl class="cc-private__details">
							<div><dt>When</dt><dd><?php echo esc_html( Bookings::start_label( $booking ) ); ?></dd></div>
							<div><dt>Length</dt><dd><?php echo esc_html( Pricing::duration_label( (float) $booking['hours'], 1 ) ); ?></dd></div>
							<div><dt>Team</dt><dd><?php echo esc_html( (int) $booking['cleaners'] > 1 ? sprintf( '%d cleaners', $booking['cleaners'] ) . ( $mine && $team ? ' (with ' . implode( ', ', $team ) . ')' : '' ) : 'Just you' ); ?></dd></div>
							<?php if ( (int) $offer['pay_pence'] ) : ?>
								<div><dt>Your pay</dt><dd><?php echo esc_html( Money::format( (int) $offer['pay_pence'] ) ); ?></dd></div>
							<?php endif; ?>
							<?php if ( $mine ) : ?>
								<div><dt>Address</dt><dd><?php echo esc_html( Bookings::full_address( $booking ) ); ?> <a href="<?php echo esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( Bookings::full_address( $booking ) ) ); ?>" target="_blank" rel="noopener noreferrer">Map</a></dd></div>
								<div><dt>Customer</dt><dd><?php echo esc_html( Notify::first_name( (string) $booking['customer_name'] ) ); ?></dd></div>
								<div><dt>Phone</dt><dd>
									<?php if ( $phone ) : ?>
										<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', (string) $booking['phone'] ) ); ?>"><?php echo esc_html( $booking['phone'] ); ?></a>
									<?php else : ?>
										Shown from <?php echo esc_html( wp_date( 'D j M, H:i', CC\Bookings\Availability::job( $booking )['start'] - DAY_IN_SECONDS ) ); ?>
									<?php endif; ?>
								</dd></div>
								<div><dt>Access &amp; parking</dt><dd><?php echo nl2br( esc_html( (string) ( $booking['access_notes'] ?: 'None given' ) ) ); ?></dd></div>
							<?php else : ?>
								<div><dt>Area</dt><dd><?php echo esc_html( $booking['district'] ); ?> <span class="cc-small">(full address once accepted)</span></dd></div>
							<?php endif; ?>
							<?php foreach ( $service ? Catalog::summary( $service, (array) $booking['answers'] ) : [] as [ $label, $value ] ) : ?>
								<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo nl2br( esc_html( (string) $value ) ); ?></dd></div>
							<?php endforeach; ?>
							<?php if ( $service && ( $addons = Catalog::addon_names( $service, (array) $booking['answers'] ) ) ) : ?>
								<div><dt>Extras</dt><dd><?php echo esc_html( implode( ', ', $addons ) ); ?></dd></div>
							<?php endif; ?>
						</dl>
					</section>
				</div>

				<aside class="cc-private__aside">
					<?php if ( in_array( $view, [ 'open', 'declined' ], true ) ) : ?>
						<form class="cc-panel cc-private__pay" method="post" action="<?php echo esc_url( $action ); ?>">
							<p class="cc-private__total-label"><?php echo (int) $booking['cleaners'] > 1 ? esc_html( sprintf( 'Team job — %d places', $booking['cleaners'] ) ) : 'First to accept gets it'; ?></p>
							<?php if ( $offer['expires_at'] && Db::timestamp( $offer['expires_at'] ) > time() ) : ?>
								<p class="cc-small">Offer open until <?php echo esc_html( wp_date( 'D j M, H:i', Db::timestamp( $offer['expires_at'] ) ) ); ?>.</p>
							<?php endif; ?>
							<?php do_action( 'cc_bookings_job_form', $offer ); ?>
							<button type="submit" name="cc_job_action" value="accept" class="cc-btn cc-btn--primary cc-btn--block">Accept job</button>
							<?php if ( 'open' === $view ) : ?>
								<button type="submit" name="cc_job_action" value="decline" class="cc-btn cc-btn--outline cc-btn--block">Decline</button>
							<?php endif; ?>
						</form>
					<?php endif; ?>

					<?php if ( 'on_job' === $view ) : ?>
						<?php if ( $can_done ) : ?>
							<form class="cc-panel cc-private__pay" method="post" action="<?php echo esc_url( $action ); ?>" enctype="multipart/form-data">
								<p class="cc-private__total-label">Finished?</p>
								<label for="cc-job-photos" class="cc-small">Add photos (optional, up to 6)</label>
								<input id="cc-job-photos" type="file" name="photos[]" accept="image/*" multiple>
								<?php do_action( 'cc_bookings_job_form', $offer ); ?>
								<button type="submit" name="cc_job_action" value="complete" class="cc-btn cc-btn--primary cc-btn--block">Mark job complete</button>
							</form>
						<?php else : ?>
							<div class="cc-panel cc-private__pay">
								<p class="cc-private__total-label">On the day</p>
								<p class="cc-small">You can mark the job complete (with photos) from here once it has started.</p>
							</div>
						<?php endif; ?>
						<details class="cc-panel cc-private__ask">
							<summary>Can't make it?</summary>
							<form method="post" action="<?php echo esc_url( $action ); ?>" onsubmit="return confirm('Tell the office you can\'t do this job? It will be offered to someone else.');">
								<label for="cc-job-reason">Reason (optional)</label>
								<textarea id="cc-job-reason" name="reason" rows="3" maxlength="255"></textarea>
								<?php do_action( 'cc_bookings_job_form', $offer ); ?>
								<button type="submit" name="cc_job_action" value="drop" class="cc-btn cc-btn--outline">I can't make it</button>
							</form>
						</details>
					<?php endif; ?>

					<p><a class="cc-btn cc-btn--outline" href="<?php echo esc_url( $jobs ); ?>"><?php echo esc_html( $jobs_label ?? 'My upcoming jobs' ); ?></a></p>
				</aside>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();

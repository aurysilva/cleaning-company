<?php
/**
 * Cleaner portal pages (logged in): /cleaners/ and /cleaners/{availability|profile|documents|bank|earnings}/.
 * The main tabs and Log out are in the cleaner header (Cleaner_Chrome); Profile has its own sub-tabs.
 * Pay amounts appear here (private, logged-in pages only). Override with {theme}/cc-bookings/cleaner-portal.php.
 *
 * @var array      $cleaner
 * @var array      $account
 * @var string     $view
 * @var array      $views   key => title
 * @var array|null $notice  [ type, message ]
 * @var array      $input   Values just posted (when saving failed).
 * @var string     $error
 * @var array      $data    View data (home: offers, jobs, attention; availability: timeoff; earnings: jobs, months).
 */

use CC\Bookings\Availability;
use CC\Bookings\Bookings;
use CC\Bookings\Cleaner_Accounts;
use CC\Bookings\Cleaner_Bank;
use CC\Bookings\Cleaner_Forms;
use CC\Bookings\Cleaner_Portal;
use CC\Bookings\Money;
use CC\Bookings\Notify;
use CC\Bookings\Pricing;

defined( 'ABSPATH' ) || exit;

$status = Cleaner_Accounts::status( $cleaner );
$nonce  = 'cc_portal_' . (int) $cleaner['id'];
$open   = function ( string $do, bool $files = false, string $class = 'cc-panel cc-cleaners__form' ) use ( $view, $nonce ): void {
	printf( '<form class="%s" method="post" action="%s"%s>', esc_attr( $class ), esc_url( Cleaner_Portal::url( $view ) ), $files ? ' enctype="multipart/form-data"' : '' );
	Cleaner_Portal::nonce_field( $nonce );
	printf( '<input type="hidden" name="cc_do" value="%s">', esc_attr( $do ) );
};
$job_line = function ( array $offer, array $booking, bool $mine ): string {
	return implode( ' · ', array_filter( [
		$booking['service_name'],
		$mine ? $booking['postcode'] : $booking['district'],
		Pricing::duration_label( (float) $booking['hours'], 1 ),
		(int) $booking['cleaners'] > 1 ? sprintf( 'team of %d', $booking['cleaners'] ) : '',
		(int) $offer['pay_pence'] ? Money::format( (int) $offer['pay_pence'] ) : '',
	] ) );
};

get_header();
?>
<main id="brx-content" class="cc-private cc-cleaners">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-private__wrap">
			<header class="cc-private__head">
				<p class="cc-eyebrow">Hi <?php echo esc_html( Notify::first_name( (string) $cleaner['name'] ) ); ?></p>
				<h1 class="cc-private__title"><?php echo esc_html( $views[ $view ] ); ?></h1>
			</header>

			<?php if ( in_array( $view, [ 'profile', 'documents', 'bank' ], true ) ) : ?>
				<nav class="cc-portal-nav" aria-label="Profile sections">
					<ul>
						<?php foreach ( [ 'profile', 'documents', 'bank' ] as $key ) : ?>
							<li><a href="<?php echo esc_url( Cleaner_Portal::url( $key ) ); ?>"<?php echo $key === $view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( 'profile' === $key ? 'Your details' : $views[ $key ] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>

			<?php if ( 'review' === $status ) : ?>
				<div class="cc-notice"><strong>We're checking your account.</strong><p>You'll get an email as soon as it's approved, then job offers will start coming through.</p></div>
			<?php endif; ?>
			<?php if ( $notice ) : ?>
				<div class="cc-notice cc-notice--<?php echo esc_attr( $notice[0] ); ?>" role="<?php echo 'warning' === $notice[0] ? 'alert' : 'status'; ?>"><strong><?php echo esc_html( $notice[1] ); ?></strong></div>
			<?php endif; ?>

			<?php if ( 'home' === $view ) : ?>
				<?php if ( $data['attention'] ) : ?>
					<div class="cc-notice cc-notice--warning">
						<strong>Please check your documents</strong>
						<ul><?php foreach ( $data['attention'] as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ul>
						<p><a href="<?php echo esc_url( Cleaner_Portal::url( 'documents' ) ); ?>">Go to documents</a></p>
					</div>
				<?php endif; ?>

				<?php if ( $data['offers'] ) : ?>
					<section class="cc-panel cc-private__card">
						<h2 class="cc-private__heading">Offers waiting for you</h2>
						<ul class="cc-joblist">
							<?php foreach ( $data['offers'] as [ $offer, $booking ] ) : ?>
								<li class="cc-joblist__item">
									<a class="cc-joblist__when" href="<?php echo esc_url( Cleaner_Portal::url( 'job/' . (int) $offer['id'] ) ); ?>"><?php echo esc_html( Bookings::start_label( $booking ) ); ?></a>
									<span class="cc-joblist__meta"><?php echo esc_html( $job_line( $offer, $booking, false ) ); ?></span>
									<?php $open( 'offer', false, 'cc-joblist__actions' ); ?>
										<input type="hidden" name="offer_id" value="<?php echo (int) $offer['id']; ?>">
										<button type="submit" name="cc_job_action" value="accept" class="cc-btn cc-btn--primary">Accept</button>
										<button type="submit" name="cc_job_action" value="decline" class="cc-btn cc-btn--outline">Decline</button>
									</form>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>

				<section class="cc-panel cc-private__card">
					<h2 class="cc-private__heading">Your upcoming jobs</h2>
					<?php if ( $data['jobs'] ) : ?>
						<ul class="cc-joblist">
							<?php foreach ( $data['jobs'] as [ $offer, $booking ] ) : ?>
								<li class="cc-joblist__item">
									<a class="cc-joblist__when" href="<?php echo esc_url( Cleaner_Portal::url( 'job/' . (int) $offer['id'] ) ); ?>"><?php echo esc_html( Bookings::start_label( $booking ) ); ?></a>
									<span class="cc-joblist__meta"><?php echo esc_html( $job_line( $offer, $booking, true ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p>No upcoming jobs right now.<?php echo 'active' === $status ? ' New offers arrive by email and text, and appear here.' : ''; ?></p>
					<?php endif; ?>
				</section>

			<?php elseif ( 'availability' === $view ) : ?>
				<?php $open( 'availability' ); ?>
					<h2 class="cc-private__heading">Areas and working hours</h2>
					<?php Cleaner_Forms::availability( $cleaner, 'availability' === ( $input['cc_do'] ?? '' ) ? $input : [] ); ?>
					<p class="cc-small">Currently: <?php echo esc_html( Availability::week_label( (int) $cleaner['id'] ) ); ?>. Changes apply to new offers straight away; jobs you already have stay booked.</p>
					<button type="submit" class="cc-btn cc-btn--primary">Save availability</button>
				</form>

				<section class="cc-panel cc-private__card">
					<h2 class="cc-private__heading">Time off</h2>
					<?php if ( $data['timeoff'] ) : ?>
						<ul class="cc-timeoff">
							<?php foreach ( $data['timeoff'] as $off ) : ?>
								<li>
									<span><?php echo esc_html( wp_date( 'D j M Y', strtotime( $off['from'] . ' 12:00' ) ) . ( $off['to'] !== $off['from'] ? ' to ' . wp_date( 'D j M Y', strtotime( $off['to'] . ' 12:00' ) ) : '' ) . ( $off['start'] ? ' (' . $off['start'] . '–' . ( $off['end'] ?: '23:59' ) . ')' : '' ) . ( $off['note'] ? ' — ' . $off['note'] : '' ) ); ?></span>
									<?php $open( 'timeoff_remove', false, 'cc-timeoff__remove' ); ?>
										<input type="hidden" name="off_id" value="<?php echo (int) $off['id']; ?>">
										<button type="submit" class="cc-btn cc-btn--outline">Remove</button>
									</form>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p>No time off booked.</p>
					<?php endif; ?>
				</section>
				<?php $open( 'timeoff_add' ); ?>
					<h2 class="cc-private__heading">Add time off</h2>
					<?php Cleaner_Forms::timeoff_fields( 'timeoff_add' === ( $input['cc_do'] ?? '' ) ? $input : [] ); ?>
					<p class="cc-small">You won't be offered jobs on these days. If you already have a job then, please call the office as well.</p>
					<button type="submit" class="cc-btn cc-btn--primary">Add time off</button>
				</form>

			<?php elseif ( 'profile' === $view ) : ?>
				<?php $open( 'details' ); ?>
					<h2 class="cc-private__heading">Your details</h2>
					<?php Cleaner_Forms::details( $cleaner, $account, 'details' === ( $input['cc_do'] ?? '' ) ? $input : [] ); ?>
					<button type="submit" class="cc-btn cc-btn--primary">Save details</button>
				</form>
				<?php $open( 'photo', true ); ?>
					<h2 class="cc-private__heading">Profile photo</h2>
					<?php Cleaner_Forms::photo( $cleaner ); ?>
					<button type="submit" class="cc-btn cc-btn--primary">Upload photo</button>
				</form>
				<?php $open( 'emergency' ); ?>
					<h2 class="cc-private__heading">Emergency contact</h2>
					<?php Cleaner_Forms::emergency( $account, 'emergency' === ( $input['cc_do'] ?? '' ) ? $input : [] ); ?>
					<button type="submit" class="cc-btn cc-btn--primary">Save emergency contact</button>
				</form>
				<?php $open( 'password' ); ?>
					<h2 class="cc-private__heading">Change password</h2>
					<?php Cleaner_Forms::password_fields( 'New password', true ); ?>
					<button type="submit" class="cc-btn cc-btn--primary">Change password</button>
				</form>

			<?php elseif ( 'documents' === $view ) : ?>
				<?php $open( 'documents', true ); ?>
					<?php Cleaner_Forms::documents( $cleaner ); ?>
					<button type="submit" class="cc-btn cc-btn--primary">Upload</button>
				</form>

			<?php elseif ( 'bank' === $view ) : ?>
				<?php $open( 'bank' ); ?>
					<h2 class="cc-private__heading"><?php echo Cleaner_Bank::masked( $account ) ? 'Change bank details' : 'Add bank details'; ?></h2>
					<?php Cleaner_Forms::bank( $account ); ?>
					<button type="submit" class="cc-btn cc-btn--primary">Save bank details</button>
				</form>

			<?php elseif ( 'earnings' === $view ) : ?>
				<section class="cc-panel cc-private__card">
					<h2 class="cc-private__heading">Pay by month</h2>
					<?php if ( $data['months'] ) : ?>
						<table class="cc-private__items">
							<tbody>
								<?php foreach ( $data['months'] as $month => $pence ) : ?>
									<tr><td><?php echo esc_html( wp_date( 'F Y', strtotime( $month . '-15 12:00' ) ) ); ?></td><td class="cc-private__amount"><?php echo esc_html( Money::format( (int) $pence ) ); ?></td></tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="cc-small">Pay for completed jobs, before any deductions. Ask the office if something looks wrong.</p>
					<?php else : ?>
						<p>No completed jobs yet.</p>
					<?php endif; ?>
				</section>
				<?php if ( $data['jobs'] ) : ?>
					<section class="cc-panel cc-private__card">
						<h2 class="cc-private__heading">Past jobs</h2>
						<table class="cc-private__items">
							<tbody>
								<?php foreach ( $data['jobs'] as $row ) : ?>
									<tr>
										<td><a href="<?php echo esc_url( Cleaner_Portal::url( 'job/' . $row['offer_id'] ) ); ?>"><?php echo esc_html( wp_date( 'D j M Y', strtotime( $row['booking']['job_date'] . ' 12:00' ) ) ); ?></a><br><span class="cc-small"><?php echo esc_html( $row['booking']['ref'] . ' · ' . $row['booking']['service_name'] . ' · ' . $row['booking']['district'] ); ?></span></td>
										<td class="cc-private__amount"><?php echo esc_html( Money::format( (int) $row['pay'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</section>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</section>
</main>
<?php
get_footer();

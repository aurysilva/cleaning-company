<?php
/**
 * Client area tabs (logged in): /account/ (bookings), /account/quotes/, /account/plans/ (+ /account/plans/{ref}/)
 * and /account/profile/. The tabs and Log out are in the header (Client_Chrome). Prices show here (private,
 * logged-in pages only). Override with {theme}/cc-bookings/client-portal.php.
 *
 * @var array      $client
 * @var string     $view    bookings|quotes|plans|plan|profile
 * @var array|null $notice  [ type, message, detail ]
 * @var array      $input   Values just posted (when saving failed).
 * @var string     $error
 * @var array      $data    View data.
 */

use CC\Bookings\Bookings;
use CC\Bookings\Cleaner_Forms;
use CC\Bookings\Client_Accounts;
use CC\Bookings\Client_Portal;
use CC\Bookings\Db;
use CC\Bookings\Money;
use CC\Bookings\Plan_Manage;
use CC\Bookings\Plans;
use CC\Bookings\Recurring;
use CC\Bookings\Slots;
use CC\Bookings\Status;

defined( 'ABSPATH' ) || exit;

$nonce = Client_Portal::nonce_action( $client );
$open  = function ( string $do, string $action = '', string $class = 'cc-panel cc-cleaners__form' ) use ( $view, $nonce ): void {
	printf( '<form class="%s" method="post" action="%s">', esc_attr( $class ), esc_url( $action ?: Client_Portal::url( $view ) ) );
	Client_Portal::nonce_field( $nonce );
	printf( '<input type="hidden" name="cc_do" value="%s">', esc_attr( $do ) );
};
$titles = [ 'bookings' => 'Your bookings', 'quotes' => 'Your quotes', 'plans' => 'Your regular plans', 'profile' => 'Your profile', 'plan' => 'Regular plan' ];
$form   = function_exists( 'cc_quote_url' ) ? ( str_starts_with( cc_quote_url(), '/' ) ? home_url( cc_quote_url() ) : cc_quote_url() ) : home_url( '/contact/' );
$value  = fn( string $key ) => (string) ( array_key_exists( $key, $input ) ? $input[ $key ] : ( $client[ $key ] ?? '' ) );
$booking_row = function ( array $booking ): void {
	$plan = (int) $booking['series_id'] ? ' · regular visit' : '';
	?>
	<li class="cc-joblist__item">
		<a class="cc-joblist__when" href="<?php echo esc_url( Client_Portal::url( 'bookings/' . $booking['ref'] ) ); ?>"><?php echo esc_html( Bookings::start_label( $booking ) ); ?></a>
		<span class="cc-joblist__meta"><?php echo esc_html( $booking['service_name'] . ' · ' . $booking['ref'] . $plan ); ?></span>
		<span class="cc-client__status cc-client__status--<?php echo esc_attr( $booking['status'] ); ?>"><?php echo esc_html( Status::customer_label( 'booking', $booking['status'] ) ); ?></span>
	</li>
	<?php
};

get_header();
?>
<main id="brx-content" class="cc-private cc-cleaners cc-client">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-private__wrap">
			<header class="cc-private__head">
				<p class="cc-eyebrow">Hi <?php echo esc_html( Client_Accounts::first_name( $client ) ); ?></p>
				<h1 class="cc-private__title"><?php echo esc_html( 'plan' === $view ? 'Regular plan ' . $data['plan']['ref'] : $titles[ $view ] ); ?></h1>
			</header>

			<?php if ( $notice ) : ?>
				<div class="cc-notice cc-notice--<?php echo esc_attr( $notice[0] ); ?>" role="<?php echo 'warning' === $notice[0] ? 'alert' : 'status'; ?>">
					<strong><?php echo esc_html( $notice[1] ); ?></strong>
					<?php if ( ! empty( $notice[2] ) ) : ?><p><?php echo esc_html( $notice[2] ); ?></p><?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( 'bookings' === $view ) : ?>
				<?php foreach ( $data['open_quotes'] as $q ) : ?>
					<div class="cc-notice cc-notice--info">
						<strong>Your <?php echo esc_html( $q['service_name'] ); ?> quote is ready to book.</strong>
						<p><a href="<?php echo esc_url( Client_Portal::url( 'quotes/' . $q['ref'] ) ); ?>">View quote <?php echo esc_html( $q['ref'] ); ?></a> — held until <?php echo esc_html( wp_date( 'l j F', Db::timestamp( $q['expires_at'] ) ) ); ?>.</p>
					</div>
				<?php endforeach; ?>

				<section class="cc-panel cc-private__card">
					<h2 class="cc-private__heading">Upcoming</h2>
					<?php if ( $data['upcoming'] ) : ?>
						<ul class="cc-joblist"><?php array_map( $booking_row, $data['upcoming'] ); ?></ul>
					<?php else : ?>
						<p>No upcoming bookings. <a href="<?php echo esc_url( $form ); ?>">Get a quote</a> to book a clean.</p>
					<?php endif; ?>
				</section>
				<?php if ( $data['past'] ) : ?>
					<section class="cc-panel cc-private__card">
						<h2 class="cc-private__heading">Past and cancelled</h2>
						<ul class="cc-joblist"><?php array_map( $booking_row, array_slice( $data['past'], 0, 50 ) ); ?></ul>
					</section>
				<?php endif; ?>

			<?php elseif ( 'quotes' === $view ) : ?>
				<section class="cc-panel cc-private__card">
					<?php if ( $data['quotes'] ) : ?>
						<ul class="cc-joblist">
							<?php foreach ( $data['quotes'] as $q ) : ?>
								<?php [ $state, $label ] = Client_Portal::quote_status( $q ); ?>
								<li class="cc-joblist__item">
									<a class="cc-joblist__when" href="<?php echo esc_url( Client_Portal::url( 'quotes/' . $q['ref'] ) ); ?>"><?php echo esc_html( $q['service_name'] . ' · ' . $q['ref'] ); ?></a>
									<span class="cc-joblist__meta">
										<?php
										echo esc_html( implode( ' · ', array_filter( [
											$q['job_date'] ? Slots::date_label( $q['job_date'], 'D j M' ) . ( $q['slot'] ? ', ' . strtolower( Slots::label( $q['slot'] ) ) : '' ) : 'Date to be agreed',
											null !== $q['total_pence'] && 'draft' !== $q['status'] ? Money::format( (int) $q['total_pence'] ) : '',
											'ready' === $state && $q['expires_at'] ? 'held until ' . wp_date( 'D j M', Db::timestamp( $q['expires_at'] ) ) : '',
											'ready' === $state && ! empty( $q['revised_at'] ) ? 'updated ' . wp_date( 'D j M', Db::timestamp( $q['revised_at'] ) ) : '',
										] ) ) );
										?>
									</span>
									<span class="cc-client__status cc-client__status--<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $label ); ?></span>
									<?php if ( 'expired' === $state ) : ?>
										<?php $open( 'requote', Client_Portal::url( 'quotes/' . $q['ref'] ), 'cc-joblist__actions' ); ?>
											<button type="submit" class="cc-btn cc-btn--outline cc-btn--small">Get an updated price</button>
										</form>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p>No quotes yet. <a href="<?php echo esc_url( $form ); ?>">Get a quote</a> — it only takes a minute.</p>
					<?php endif; ?>
				</section>

			<?php elseif ( 'plans' === $view ) : ?>
				<section class="cc-panel cc-private__card">
					<?php if ( $data['plans'] ) : ?>
						<ul class="cc-joblist">
							<?php foreach ( $data['plans'] as $plan ) : ?>
								<li class="cc-joblist__item">
									<a class="cc-joblist__when" href="<?php echo esc_url( Client_Portal::url( 'plans/' . $plan['ref'] ) ); ?>"><?php echo esc_html( $plan['service_name'] . ' · ' . $plan['ref'] ); ?></a>
									<span class="cc-joblist__meta"><?php echo esc_html( Recurring::label( (string) $plan['frequency'] ) . ( 'active' === $plan['status'] && $plan['next_date'] ? ' · ' . Plans::weekday_label( $plan ) : '' ) . ' · ' . Money::format( (int) $plan['price_pence'] ) . ' per visit' ); ?></span>
									<span class="cc-client__status cc-client__status--<?php echo esc_attr( $plan['status'] ); ?>"><?php echo esc_html( Recurring::PLAN_STATUSES[ $plan['status'] ] ?? ucfirst( (string) $plan['status'] ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p>You don't have a regular plan. Choose a regular clean when you get a quote to set one up.</p>
					<?php endif; ?>
				</section>

			<?php elseif ( 'plan' === $view ) : ?>
				<p class="cc-client__back"><a href="<?php echo esc_url( Client_Portal::url( 'plans' ) ); ?>">&larr; All plans</a></p>
				<div class="cc-private__grid">
					<div class="cc-private__main">
						<div id="cc-manage">
							<?php if ( $flash = Client_Portal::flash_message() ) : ?>
								<div class="cc-notice cc-notice--<?php echo 'success' === $flash['type'] ? 'success' : 'warning'; ?>" role="status"><p><?php echo esc_html( (string) $flash['message'] ); ?></p></div>
							<?php endif; ?>
						</div>
						<?php if ( $data['visit'] ) : ?>
							<?php Plan_Manage::panel( $data['visit'], '' ); ?>
						<?php endif; ?>
					</div>
					<aside class="cc-private__aside">
						<section class="cc-panel cc-private__card">
							<h2 class="cc-private__heading">Visits</h2>
							<ul class="cc-joblist">
								<?php foreach ( array_reverse( array_slice( array_reverse( $data['visits'] ), 0, 12 ) ) as $visit ) : ?>
									<li class="cc-joblist__item">
										<a class="cc-joblist__when" href="<?php echo esc_url( Client_Portal::url( 'bookings/' . $visit['ref'] ) ); ?>"><?php echo esc_html( Bookings::start_label( $visit ) ); ?></a>
										<span class="cc-client__status cc-client__status--<?php echo esc_attr( $visit['status'] ); ?>"><?php echo esc_html( Status::customer_label( 'booking', $visit['status'] ) ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						</section>
					</aside>
				</div>

			<?php elseif ( 'profile' === $view ) : ?>
				<div class="cc-client__profile">
					<?php $open( 'details' ); ?>
						<h2 class="cc-private__heading">Your details</h2>
						<?php Cleaner_Forms::field( 'name', 'Your name', $value( 'name' ), 'text', [ 'autocomplete' => 'name', 'required' => true, 'maxlength' => 100 ] ); ?>
						<?php Cleaner_Forms::field( 'phone', 'Mobile number', $value( 'phone' ), 'tel', [ 'autocomplete' => 'tel', 'required' => true, 'inputmode' => 'tel' ] ); ?>
						<fieldset class="cc-fieldset"><legend class="cc-field__label">Main address</legend>
							<?php Cleaner_Forms::field( 'address_1', 'Address line 1', $value( 'address_1' ), 'text', [ 'autocomplete' => 'address-line1' ] ); ?>
							<?php Cleaner_Forms::field( 'address_2', 'Address line 2 (optional)', $value( 'address_2' ), 'text', [ 'autocomplete' => 'address-line2' ] ); ?>
							<div class="cc-field-row">
								<?php Cleaner_Forms::field( 'town', 'Town or city', $value( 'town' ), 'text', [ 'autocomplete' => 'address-level2' ] ); ?>
								<?php Cleaner_Forms::field( 'postcode', 'Postcode', $value( 'postcode' ), 'text', [ 'autocomplete' => 'postal-code', 'maxlength' => 10, 'class' => 'cc-field__input cc-field__input--short' ] ); ?>
							</div>
						</fieldset>
						<p class="cc-field__help">Used to pre-fill your next quote. To change the address of a booking you've already made, please call us.</p>
						<?php if ( $data['addresses'] ) : ?>
							<div class="cc-field"><span class="cc-field__label">Addresses on your quotes and bookings</span>
								<ul class="cc-client__addresses"><?php foreach ( $data['addresses'] as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ul>
							</div>
						<?php endif; ?>
						<button type="submit" class="cc-btn cc-btn--primary">Save details</button>
					</form>

					<?php $open( 'email' ); ?>
						<h2 class="cc-private__heading">Email address</h2>
						<div class="cc-field"><span class="cc-field__label">Current email</span><p class="cc-field__static"><?php echo esc_html( (string) $client['email'] ); ?></p></div>
						<?php Cleaner_Forms::field( 'new_email', 'New email address', '', 'email', [ 'autocomplete' => 'email', 'required' => true, 'inputmode' => 'email' ], 'We\'ll email a link to the new address. Your email won\'t change until you click it.' ); ?>
						<button type="submit" class="cc-btn cc-btn--outline">Change email</button>
					</form>

					<?php $open( 'password' ); ?>
						<h2 class="cc-private__heading">Password</h2>
						<?php Cleaner_Forms::password_fields( 'New password', true ); ?>
						<p class="cc-field__help">Changing your password logs you out on any other phones or computers.</p>
						<button type="submit" class="cc-btn cc-btn--outline">Change password</button>
					</form>

					<?php $open( 'marketing' ); ?>
						<h2 class="cc-private__heading">Emails from us</h2>
						<p class="cc-small">We always email you about your quotes and bookings.</p>
						<label class="cc-check"><input type="checkbox" name="marketing" value="1"<?php checked( (int) $client['marketing_opt_in'], 1 ); ?>> <span>Send me occasional offers and cleaning tips</span></label>
						<button type="submit" class="cc-btn cc-btn--outline">Save</button>
					</form>

					<?php $open( 'delete' ); ?>
						<h2 class="cc-private__heading">Delete my account</h2>
						<?php if ( $client['delete_requested_at'] ) : ?>
							<p>You asked us to delete your account on <?php echo esc_html( wp_date( 'j F Y', Db::timestamp( $client['delete_requested_at'] ) ) ); ?>. We'll be in touch to confirm.</p>
						<?php else : ?>
							<p class="cc-small">We'll delete your account and personal details, except what the law says we must keep (such as records of payments). We'll confirm by email within one month. Please cancel any upcoming bookings or plans first.</p>
							<div class="cc-field"><label class="cc-field__label" for="cc-f-note">Anything we should know? (optional)</label><textarea class="cc-field__input" id="cc-f-note" name="note" rows="3" maxlength="1000"></textarea></div>
							<label class="cc-check"><input type="checkbox" name="confirm" value="1" required> <span>Yes, please delete my account</span></label>
							<button type="submit" class="cc-btn cc-btn--outline">Request deletion</button>
						<?php endif; ?>
					</form>
				</div>
			<?php endif; ?>
		</div>
	</section>
</main>
<?php
get_footer();

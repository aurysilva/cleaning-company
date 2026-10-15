<?php
/**
 * First visit to the client area: choose a password and confirm name and mobile before anything else
 * (/account/set-password/; every other account page redirects here until it's done).
 * Override with {theme}/cc-bookings/client-setup.php.
 *
 * @var array  $client
 * @var string $error
 * @var array  $input
 * @var string $next
 */

use CC\Bookings\Cleaner_Forms;
use CC\Bookings\Client_Portal;

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="brx-content" class="cc-private cc-cleaners cc-client">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-container--narrow cc-private__wrap cc-cleaners__auth">
			<header class="cc-private__head">
				<p class="cc-eyebrow">Your client area</p>
				<h1 class="cc-private__title">Set up your client area</h1>
				<p class="cc-lead">Choose a password so only you can see your quotes and bookings. Your quote is on the next page.</p>
			</header>

			<?php if ( $error ) : ?>
				<div class="cc-notice cc-notice--warning" role="alert"><strong><?php echo esc_html( $error ); ?></strong></div>
			<?php endif; ?>

			<form class="cc-panel cc-cleaners__form" method="post" action="<?php echo esc_url( Client_Portal::url( 'set-password' ) ); ?>">
				<?php Client_Portal::nonce_field( Client_Portal::nonce_action( $client ) ); ?>
				<input type="hidden" name="cc_do" value="setup">
				<?php if ( $next ) : ?><input type="hidden" name="next" value="<?php echo esc_attr( $next ); ?>"><?php endif; ?>
				<div class="cc-field"><span class="cc-field__label">Email</span><p class="cc-field__static"><?php echo esc_html( (string) $client['email'] ); ?></p><p class="cc-field__help">This is your login.</p></div>
				<?php Cleaner_Forms::field( 'name', 'Your name', (string) ( $input['name'] ?? $client['name'] ), 'text', [ 'autocomplete' => 'name', 'required' => true, 'maxlength' => 100 ] ); ?>
				<?php Cleaner_Forms::field( 'phone', 'Mobile number', (string) ( $input['phone'] ?? $client['phone'] ), 'tel', [ 'autocomplete' => 'tel', 'required' => true, 'inputmode' => 'tel' ], 'So your cleaner or our office can reach you on the day.' ); ?>
				<?php Cleaner_Forms::password_fields( 'Choose a password' ); ?>
				<button type="submit" class="cc-btn cc-btn--primary cc-btn--block">Save and view my quote</button>
			</form>
		</div>
	</section>
</main>
<?php
get_footer();

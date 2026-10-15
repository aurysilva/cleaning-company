<?php
/**
 * Cleaner account setup wizard: /cleaners/setup/{token}/ (step 1) and /cleaners/setup/?step=… (the rest).
 * Override with {theme}/cc-bookings/cleaner-setup.php.
 *
 * @var array  $cleaner
 * @var array  $account
 * @var string $step    A key of $steps, or "done".
 * @var array  $steps   key => title
 * @var string $intro
 * @var string $error
 * @var array  $input   Values just posted (when saving failed).
 * @var string $action  Form URL.
 * @var string $nonce   Nonce action.
 * @var bool   $invite  Step 1 from the invite link (asks for a password).
 */

use CC\Bookings\Cleaner_Forms;
use CC\Bookings\Cleaner_Portal;
use CC\Bookings\Cleaner_Setup;
use CC\Bookings\Notify;

defined( 'ABSPATH' ) || exit;

$keys  = array_keys( $steps );
$index = 'done' === $step ? count( $keys ) : (int) array_search( $step, $keys, true );
$done  = Cleaner_Setup::done( $account );
$multi = in_array( $step, [ 'photo', 'documents' ], true );

get_header();
?>
<main id="brx-content" class="cc-private cc-cleaners">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-container--narrow cc-private__wrap">
			<header class="cc-private__head">
				<p class="cc-eyebrow"><?php echo esc_html( 'done' === $step ? 'All done' : sprintf( 'Step %d of %d', $index + 1, count( $keys ) ) ); ?></p>
				<h1 class="cc-private__title"><?php echo esc_html( 'done' === $step ? 'Submitted — we\'ll review your documents' : $steps[ $step ] ); ?></h1>
				<?php if ( 'account' === $step && $invite ) : ?>
					<p class="cc-lead">Welcome, <?php echo esc_html( Notify::first_name( (string) $cleaner['name'] ) ); ?>! Setting up takes about 15 minutes, and your progress is saved after each step.</p>
				<?php elseif ( $intro ) : ?>
					<p class="cc-lead"><?php echo esc_html( $intro ); ?></p>
				<?php endif; ?>
			</header>

			<ol class="cc-wizard-steps" aria-label="Setup progress">
				<?php foreach ( $steps as $key => $title ) : ?>
					<?php
					$state = $key === $step ? 'current' : ( in_array( $key, $done, true ) ? 'done' : 'todo' );
					$link  = 'done' !== $step && ! $invite && 'current' !== $state && Cleaner_Setup::allowed( $account, $key );
					?>
					<li class="cc-wizard-steps__item cc-wizard-steps__item--<?php echo esc_attr( $state ); ?>"<?php echo 'current' === $state ? ' aria-current="step"' : ''; ?>>
						<?php if ( $link ) : ?>
							<a href="<?php echo esc_url( Cleaner_Setup::url( $key ) ); ?>"><?php echo esc_html( $title ); ?></a>
						<?php else : ?>
							<span><?php echo esc_html( $title ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>

			<?php if ( $error ) : ?>
				<div class="cc-notice cc-notice--warning" role="alert"><strong><?php echo esc_html( $error ); ?></strong></div>
			<?php endif; ?>

			<?php if ( 'done' === $step ) : ?>
				<section class="cc-panel cc-private__card">
					<p>Thanks, <?php echo esc_html( Notify::first_name( (string) $cleaner['name'] ) ); ?>. We've got everything we need. We'll check your documents and let you know by email as soon as your account is approved — then you'll start getting job offers.</p>
					<p>If we need anything else, we'll email you. Meanwhile you can log in to check your details or upload a new copy of a document.</p>
					<p><a class="cc-btn cc-btn--primary" href="<?php echo esc_url( Cleaner_Portal::url() ); ?>">Go to my portal</a></p>
				</section>
			<?php else : ?>
				<form class="cc-panel cc-cleaners__form" method="post" action="<?php echo esc_url( $action ); ?>"<?php echo $multi ? ' enctype="multipart/form-data"' : ''; ?>>
					<?php Cleaner_Portal::nonce_field( $nonce ); ?>
					<?php
					switch ( $step ) {
						case 'account':
							Cleaner_Forms::details( $cleaner, $account, $input );
							if ( $invite ) {
								echo '<fieldset class="cc-fieldset"><legend class="cc-field__label">Your password</legend>';
								Cleaner_Forms::password_fields();
								echo '</fieldset>';
							}
							break;
						case 'availability':
							Cleaner_Forms::availability( $cleaner, $input );
							break;
						case 'services':
							Cleaner_Forms::services( $cleaner, $input );
							break;
						case 'photo':
							Cleaner_Forms::photo( $cleaner );
							break;
						case 'emergency':
							Cleaner_Forms::emergency( $account, $input );
							break;
						case 'documents':
							Cleaner_Forms::documents( $cleaner );
							break;
						case 'bank':
							Cleaner_Forms::bank( $account, $input );
							if ( $account['bank_last4'] ) {
								echo '<p class="cc-field__help">Leave these empty to keep the account above.</p>';
							}
							break;
						case 'terms':
							Cleaner_Forms::terms( $cleaner, $account );
							break;
					}
					?>
					<div class="cc-cleaners__nav">
						<?php if ( $index > 0 && ! $invite ) : ?>
							<a class="cc-btn cc-btn--outline" href="<?php echo esc_url( Cleaner_Setup::url( $keys[ $index - 1 ] ) ); ?>">Back</a>
						<?php endif; ?>
						<button type="submit" class="cc-btn cc-btn--primary"><?php echo esc_html( 'terms' === $step ? 'Agree and submit' : 'Save and continue' ); ?></button>
					</div>
				</form>
				<?php if ( ! $invite ) : ?>
					<p class="cc-small">Your progress is saved after each step. You can log in at <a href="<?php echo esc_url( Cleaner_Portal::url() ); ?>"><?php echo esc_html( preg_replace( '#^https?://#', '', Cleaner_Portal::url() ) ); ?></a> to carry on later.</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</section>
</main>
<?php
get_footer();

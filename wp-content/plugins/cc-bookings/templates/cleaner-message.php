<?php
/**
 * A short message page for the cleaner portal (expired links, wrong account…).
 * Override with {theme}/cc-bookings/cleaner-message.php.
 *
 * @var string $title
 * @var string $text
 * @var bool   $logout Offer a log-out button.
 */

use CC\Bookings\Cleaner_Portal;

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="brx-content" class="cc-private cc-cleaners">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-container--narrow cc-private__wrap">
			<header class="cc-private__head">
				<h1 class="cc-private__title"><?php echo esc_html( $title ); ?></h1>
			</header>
			<section class="cc-panel cc-private__card">
				<p><?php echo esc_html( $text ); ?></p>
				<p class="cc-cleaners__nav">
					<?php if ( $logout ) : ?>
						<a class="cc-btn cc-btn--primary" href="<?php echo esc_url( wp_logout_url( home_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/cleaners/' ) ) ) ); ?>">Log out</a>
					<?php else : ?>
						<a class="cc-btn cc-btn--primary" href="<?php echo esc_url( Cleaner_Portal::url() ); ?>">Go to cleaner login</a>
					<?php endif; ?>
				</p>
			</section>
		</div>
	</section>
</main>
<?php
get_footer();

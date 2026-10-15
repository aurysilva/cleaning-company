<?php
/**
 * A short message page for the client area (expired links, wrong account, payment being confirmed…).
 * Override with {theme}/cc-bookings/client-message.php.
 *
 * @var string $title
 * @var string $text
 * @var bool   $logout  Offer a log-out button.
 * @var int    $refresh Seconds before the page reloads itself (0 = never).
 * @var string $back    Optional link back.
 */

use CC\Bookings\Client_Accounts;
use CC\Bookings\Client_Portal;

defined( 'ABSPATH' ) || exit;

$refresh = (int) ( $refresh ?? 0 );
$back    = (string) ( $back ?? '' );
$here    = home_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/account/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

get_header();
?>
<main id="brx-content" class="cc-private cc-cleaners cc-client">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-container--narrow cc-private__wrap">
			<header class="cc-private__head">
				<h1 class="cc-private__title"><?php echo esc_html( $title ); ?></h1>
			</header>
			<section class="cc-panel cc-private__card"<?php echo $refresh ? ' data-cc-refresh="' . (int) $refresh . '"' : ''; ?>>
				<p><?php echo esc_html( $text ); ?></p>
				<p class="cc-cleaners__nav">
					<?php if ( $logout ) : ?>
						<a class="cc-btn cc-btn--primary" href="<?php echo esc_url( wp_logout_url( $here ) ); ?>">Log out</a>
					<?php elseif ( $back ) : ?>
						<a class="cc-btn cc-btn--outline" href="<?php echo esc_url( $back ); ?>">Back to my quote</a>
					<?php else : ?>
						<a class="cc-btn cc-btn--primary" href="<?php echo esc_url( Client_Portal::url() ); ?>"><?php echo Client_Accounts::current() ? 'Go to my account' : 'Log in'; ?></a>
					<?php endif; ?>
				</p>
			</section>
		</div>
	</section>
</main>
<?php
get_footer();

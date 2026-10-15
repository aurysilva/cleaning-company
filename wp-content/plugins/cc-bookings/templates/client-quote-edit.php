<?php
/**
 * Change quote details in the client area: /account/quotes/{ref}/edit/
 *
 * @var array  $quote
 * @var array  $client
 * @var string $form  Quote form HTML from Form::render( portal opts ).
 */

use CC\Bookings\Client_Portal;

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="brx-content" class="cc-private cc-client">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-private__wrap">
			<p class="cc-client__back"><a href="<?php echo esc_url( Client_Portal::url( 'quotes/' . $quote['ref'] ) ); ?>">&larr; Back to quote <?php echo esc_html( $quote['ref'] ); ?></a></p>
			<header class="cc-private__head">
				<p class="cc-eyebrow">Quote <?php echo esc_html( $quote['ref'] ); ?></p>
				<h1 class="cc-private__title">Change your quote details</h1>
				<p class="cc-lead">Update the property, date or extras below. We’ll send you an updated fixed price in your account — your current quote stays open until you accept the new one.</p>
			</header>
			<div class="cc-panel cc-private__card cc-client__quote-form">
				<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Form::render(). ?>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();

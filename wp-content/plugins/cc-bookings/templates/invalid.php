<?php
/**
 * Unknown or revoked private link.
 *
 * @var string $kind quote|booking
 */

defined( 'ABSPATH' ) || exit;

$form_url = function_exists( 'cc_quote_url' ) ? cc_quote_url() : home_url( '/contact/' );

get_header();
?>
<main id="brx-content" class="cc-private">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-container--narrow cc-private__wrap">
			<header class="cc-private__head">
				<h1 class="cc-private__title">This link isn't valid</h1>
				<p class="cc-lead">We couldn't find that <?php echo esc_html( $kind ); ?>. Please use the link in your most recent email, or get a new quote.</p>
			</header>
			<p><a class="cc-btn cc-btn--primary" href="<?php echo esc_url( $form_url ); ?>">Get a quote</a></p>
		</div>
	</section>
</main>
<?php
get_footer();

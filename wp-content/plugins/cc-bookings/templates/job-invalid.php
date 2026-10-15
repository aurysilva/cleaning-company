<?php
/**
 * Unknown or revoked cleaner link. Override with {theme}/cc-bookings/job-invalid.php.
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="brx-content" class="cc-private">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-container--narrow cc-private__wrap">
			<header class="cc-private__head">
				<h1 class="cc-private__title">This link isn't valid</h1>
				<p class="cc-lead">We couldn't find that job. Please use the link in your most recent email or text, or call the office.</p>
			</header>
		</div>
	</section>
</main>
<?php
get_footer();

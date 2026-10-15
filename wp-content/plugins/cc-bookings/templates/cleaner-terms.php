<?php
/**
 * Cleaner terms: /cleaners/terms/. The logged-in cleaner's agreement, or both kinds when logged out.
 * Override with {theme}/cc-bookings/cleaner-terms.php.
 *
 * @var array $docs [ [ label, terms: { type, version, text }, agreed: { version, accepted_at }… ] ]
 * @var bool  $mine A cleaner is logged in.
 */

use CC\Bookings\Db;

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="brx-content" class="cc-private cc-cleaners">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-container--narrow cc-private__wrap">
			<header class="cc-private__head">
				<h1 class="cc-private__title"><?php echo esc_html( $mine ? $docs[0]['label'] : 'Cleaner terms' ); ?></h1>
				<?php if ( ! $mine ) : ?>
					<p class="cc-lead">Employed cleaners agree to the cleaner terms; self-employed cleaners agree to the contractor agreement. You'll be asked to agree when you set up your account.</p>
				<?php endif; ?>
			</header>
			<?php foreach ( $docs as $doc ) : ?>
				<section class="cc-panel cc-private__card">
					<h2 class="cc-private__heading"><?php echo esc_html( $doc['label'] ); ?> <span class="cc-small">(version <?php echo esc_html( $doc['terms']['version'] ); ?>)</span></h2>
					<?php if ( ! empty( $doc['agreed']['accepted_at'] ) ) : ?>
						<p class="cc-small">You agreed to version <?php echo esc_html( (string) $doc['agreed']['version'] ); ?> on <?php echo esc_html( wp_date( 'j M Y, H:i', Db::timestamp( $doc['agreed']['accepted_at'] ) ) ); ?>.</p>
					<?php endif; ?>
					<div class="cc-terms cc-terms--page"><?php echo wp_kses_post( wpautop( esc_html( $doc['terms']['text'] ) ) ); ?></div>
				</section>
			<?php endforeach; ?>
		</div>
	</section>
</main>
<?php
get_footer();

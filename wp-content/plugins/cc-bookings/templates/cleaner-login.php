<?php
/**
 * Cleaner login pages: /cleaners/ (logged out), /cleaners/forgot/, /cleaners/reset/…/, /cleaners/magic/…/.
 * Override with {theme}/cc-bookings/cleaner-login.php.
 *
 * @var string     $mode    login|forgot|reset|magic
 * @var string     $error
 * @var string     $email
 * @var array|null $notice  [ type, message ]
 * @var string     $action  Form URL (reset and magic).
 * @var string     $next    Portal path to return to after logging in.
 * @var string     $method  password|link: the login tab to show (?method=link, or the form just posted).
 */

use CC\Bookings\Cleaner_Forms;
use CC\Bookings\Cleaner_Portal;

defined( 'ABSPATH' ) || exit;

$next    = $next ?? '';
$method  = 'link' === ( $method ?? '' ) ? 'link' : 'password';
$tabs    = [ 'password' => 'Password', 'link' => 'No password?' ];
$base    = $next ? home_url( $next ) : Cleaner_Portal::url();
$message = function () use ( $error, $notice ): void {
	if ( $error ) {
		printf( '<div class="cc-notice cc-notice--warning" role="alert"><strong>%s</strong></div>', esc_html( $error ) );
	} elseif ( $notice ) {
		printf( '<div class="cc-notice cc-notice--%s" role="status"><strong>%s</strong>%s</div>', esc_attr( $notice[0] ), esc_html( $notice[1] ), isset( $notice[2] ) ? '<p>' . esc_html( $notice[2] ) . '</p>' : '' );
	}
};
$heads   = [
	'login'  => [ 'Cleaner login', 'See your jobs, offers, availability and pay.' ],
	'forgot' => [ 'Reset your password', 'Enter the email address you use to log in and we\'ll send you a link to choose a new password.' ],
	'reset'  => [ 'Choose a new password', 'Your new password logs you out of any other phones or computers.' ],
	'magic'  => [ 'Log in', 'Tap the button to log in to your cleaner portal.' ],
];

get_header();
?>
<main id="brx-content" class="cc-private cc-cleaners">
	<section class="cc-section cc-private__section">
		<div class="cc-container cc-container--narrow cc-private__wrap cc-cleaners__auth">
			<header class="cc-private__head">
				<h1 class="cc-private__title"><?php echo esc_html( $heads[ $mode ][0] ); ?></h1>
				<p class="cc-lead"><?php echo esc_html( $heads[ $mode ][1] ); ?></p>
			</header>

			<?php if ( 'login' !== $mode ) : ?>
				<?php $message(); ?>
			<?php endif; ?>

			<?php if ( 'login' === $mode ) : ?>
				<div class="cc-panel cc-login" data-cc-tabs>
					<div class="cc-login__tabs" role="tablist" aria-label="How to log in">
						<?php foreach ( $tabs as $key => $label ) : ?>
							<a class="cc-login__tab" id="cc-login-tab-<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( 'link' === $key ? add_query_arg( 'method', 'link', $base ) : $base ); ?>" role="tab" aria-controls="cc-login-panel-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $key === $method ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</div>

					<div class="cc-login__panel" id="cc-login-panel-password" role="tabpanel" aria-labelledby="cc-login-tab-password" tabindex="0"<?php echo 'password' === $method ? '' : ' hidden'; ?>>
						<?php if ( 'password' === $method ) { $message(); } ?>
						<form class="cc-cleaners__form" method="post" action="<?php echo esc_url( Cleaner_Portal::url() ); ?>">
							<?php Cleaner_Portal::nonce_field( 'cc_cleaner_auth' ); ?>
							<input type="hidden" name="cc_do" value="login">
							<?php if ( $next ) : ?><input type="hidden" name="next" value="<?php echo esc_attr( $next ); ?>"><?php endif; ?>
							<?php Cleaner_Forms::field( 'email', 'Email address', $email, 'email', [ 'autocomplete' => 'username', 'required' => true, 'inputmode' => 'email' ] ); ?>
							<?php Cleaner_Forms::field( 'password', 'Password', '', 'password', [ 'autocomplete' => 'current-password', 'required' => true ] ); ?>
							<p class="cc-login__forgot"><a href="<?php echo esc_url( Cleaner_Portal::url( 'forgot' ) ); ?>">Forgot password?</a></p>
							<label class="cc-check"><input type="checkbox" name="remember" value="1"> <span>Keep me logged in on this device</span></label>
							<button type="submit" class="cc-btn cc-btn--primary cc-btn--block">Log in</button>
						</form>
					</div>

					<div class="cc-login__panel" id="cc-login-panel-link" role="tabpanel" aria-labelledby="cc-login-tab-link" tabindex="0"<?php echo 'link' === $method ? '' : ' hidden'; ?>>
						<?php if ( 'link' === $method ) { $message(); } ?>
						<form class="cc-cleaners__form" method="post" action="<?php echo esc_url( Cleaner_Portal::url() ); ?>">
							<?php Cleaner_Portal::nonce_field( 'cc_cleaner_auth' ); ?>
							<input type="hidden" name="cc_do" value="magic">
							<p class="cc-small">We'll email you a link that logs you in once — no password needed. It works for 15 minutes.</p>
							<?php Cleaner_Forms::field( 'email', 'Email address', $email, 'email', [ 'autocomplete' => 'email', 'required' => true, 'inputmode' => 'email', 'id' => 'cc-f-magic-email' ] ); ?>
							<button type="submit" class="cc-btn cc-btn--primary cc-btn--block">Email me a login link</button>
						</form>
					</div>
				</div>
				<p class="cc-small">New cleaner? Use the setup link in your invite email. Can't find it? Ask the office to send a new one.</p>

			<?php elseif ( 'forgot' === $mode ) : ?>
				<form class="cc-panel cc-cleaners__form" method="post" action="<?php echo esc_url( Cleaner_Portal::url( 'forgot' ) ); ?>">
					<?php Cleaner_Portal::nonce_field( 'cc_cleaner_auth' ); ?>
					<?php Cleaner_Forms::field( 'email', 'Email address', '', 'email', [ 'autocomplete' => 'username', 'required' => true, 'inputmode' => 'email' ] ); ?>
					<button type="submit" class="cc-btn cc-btn--primary cc-btn--block">Send me a reset link</button>
					<p class="cc-small"><a href="<?php echo esc_url( Cleaner_Portal::url() ); ?>">Back to login</a></p>
				</form>

			<?php elseif ( 'reset' === $mode ) : ?>
				<form class="cc-panel cc-cleaners__form" method="post" action="<?php echo esc_url( $action ); ?>">
					<?php Cleaner_Portal::nonce_field( 'cc_cleaner_auth' ); ?>
					<?php Cleaner_Forms::password_fields( 'New password' ); ?>
					<button type="submit" class="cc-btn cc-btn--primary cc-btn--block">Save new password</button>
				</form>

			<?php elseif ( 'magic' === $mode ) : ?>
				<form class="cc-panel cc-cleaners__form" method="post" action="<?php echo esc_url( $action ); ?>">
					<?php Cleaner_Portal::nonce_field( 'cc_cleaner_auth' ); ?>
					<button type="submit" class="cc-btn cc-btn--primary cc-btn--block">Log me in</button>
					<p class="cc-small">This link works once. If you didn't ask for it, just close this page.</p>
				</form>
			<?php endif; ?>
		</div>
	</section>
</main>
<?php
get_footer();

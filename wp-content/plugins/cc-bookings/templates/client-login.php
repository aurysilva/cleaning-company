<?php
/**
 * Client area pages for logged-out visitors: /account/ login, /account/forgot/, /account/reset/…/, and the
 * one-click pages for emailed links (/account/magic/…/, /account/q/…/, /account/email/…/).
 * Override with {theme}/cc-bookings/client-login.php.
 *
 * @var string     $mode    login|forgot|reset|magic|quote_link|email
 * @var string     $error
 * @var string     $email
 * @var array|null $notice  [ type, message, detail ]
 * @var string     $action  Form URL (reset and emailed links).
 * @var string     $next    Account path to return to after logging in.
 * @var string     $method  password|link: the login tab to show.
 */

use CC\Bookings\Cleaner_Forms;
use CC\Bookings\Client_Accounts;
use CC\Bookings\Client_Portal;

defined( 'ABSPATH' ) || exit;

$next    = $next ?? '';
$method  = 'link' === ( $method ?? '' ) ? 'link' : 'password';
$tabs    = [ 'password' => 'Password', 'link' => 'No password?' ];
$base    = $next ? home_url( $next ) : Client_Portal::url();
$minutes = max( 1, (int) Client_Accounts::opt( 'magic_minutes' ) );
$message = function () use ( $error, $notice ): void {
	if ( $error ) {
		printf( '<div class="cc-notice cc-notice--warning" role="alert"><strong>%s</strong></div>', esc_html( $error ) );
	} elseif ( $notice ) {
		printf( '<div class="cc-notice cc-notice--%s" role="status"><strong>%s</strong>%s</div>', esc_attr( $notice[0] ), esc_html( $notice[1] ), isset( $notice[2] ) ? '<p>' . esc_html( $notice[2] ) . '</p>' : '' );
	}
};
$heads   = [
	'login'      => [ 'Log in to your account', 'See your quotes and bookings, and manage them in one place.' ],
	'forgot'     => [ 'Reset your password', 'Enter the email address you use to log in and we\'ll send you a link to choose a new password.' ],
	'reset'      => [ 'Choose a new password', 'Your new password logs you out of any other phones or computers.' ],
	'magic'      => [ 'Log in', 'Tap the button to log in to your account.' ],
	'quote_link' => [ 'Your quote is ready', 'First, we\'ll set up your secure client area so you can view your quote and manage your bookings.' ],
	'email'      => [ 'Confirm your new email', 'Tap the button to use this address for your account from now on.' ],
];
if ( 'quote_link' === $mode && ! empty( $first_name ) ) {
	$heads['quote_link'][0] = sprintf( 'Hi %s — your quote is ready', $first_name );
}

get_header();
?>
<main id="brx-content" class="cc-private cc-cleaners cc-client">
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
						<form class="cc-cleaners__form" method="post" action="<?php echo esc_url( Client_Portal::url() ); ?>">
							<?php Client_Portal::nonce_field( 'cc_client_auth' ); ?>
							<input type="hidden" name="cc_do" value="login">
							<?php if ( $next ) : ?><input type="hidden" name="next" value="<?php echo esc_attr( $next ); ?>"><?php endif; ?>
							<?php Cleaner_Forms::field( 'email', 'Email address', $email, 'email', [ 'autocomplete' => 'username', 'required' => true, 'inputmode' => 'email' ] ); ?>
							<?php Cleaner_Forms::field( 'password', 'Password', '', 'password', [ 'autocomplete' => 'current-password', 'required' => true, 'autofocus' => '' !== $email ] ); ?>
							<p class="cc-login__forgot"><a href="<?php echo esc_url( Client_Portal::url( 'forgot' ) ); ?>">Forgot password?</a></p>
							<label class="cc-check"><input type="checkbox" name="remember" value="1"> <span>Keep me logged in on this device</span></label>
							<button type="submit" class="cc-btn cc-btn--primary cc-btn--block">Log in</button>
						</form>
					</div>

					<div class="cc-login__panel" id="cc-login-panel-link" role="tabpanel" aria-labelledby="cc-login-tab-link" tabindex="0"<?php echo 'link' === $method ? '' : ' hidden'; ?>>
						<?php if ( 'link' === $method ) { $message(); } ?>
						<form class="cc-cleaners__form" method="post" action="<?php echo esc_url( Client_Portal::url() ); ?>">
							<?php Client_Portal::nonce_field( 'cc_client_auth' ); ?>
							<input type="hidden" name="cc_do" value="magic">
							<?php if ( $next ) : ?><input type="hidden" name="next" value="<?php echo esc_attr( $next ); ?>"><?php endif; ?>
							<p class="cc-small"><?php echo esc_html( sprintf( 'We\'ll email you a link that logs you in once — no password needed. It works for %d minutes.', $minutes ) ); ?></p>
							<?php Cleaner_Forms::field( 'email', 'Email address', $email, 'email', [ 'autocomplete' => 'email', 'required' => true, 'inputmode' => 'email', 'id' => 'cc-f-magic-email' ] ); ?>
							<button type="submit" class="cc-btn cc-btn--primary cc-btn--block">Email me a login link</button>
						</form>
					</div>
				</div>
				<p class="cc-small">New here? Your account is created when you <a href="<?php echo esc_url( function_exists( 'cc_quote_url' ) ? ( str_starts_with( cc_quote_url(), '/' ) ? home_url( cc_quote_url() ) : cc_quote_url() ) : home_url( '/contact/' ) ); ?>">ask for a quote</a> — then use the "View my quote" button in your email.</p>

			<?php elseif ( 'forgot' === $mode ) : ?>
				<form class="cc-panel cc-cleaners__form" method="post" action="<?php echo esc_url( Client_Portal::url( 'forgot' ) ); ?>">
					<?php Client_Portal::nonce_field( 'cc_client_auth' ); ?>
					<?php Cleaner_Forms::field( 'email', 'Email address', '', 'email', [ 'autocomplete' => 'username', 'required' => true, 'inputmode' => 'email' ] ); ?>
					<button type="submit" class="cc-btn cc-btn--primary cc-btn--block">Send me a reset link</button>
					<p class="cc-small"><a href="<?php echo esc_url( Client_Portal::url() ); ?>">Back to login</a></p>
				</form>

			<?php elseif ( 'reset' === $mode ) : ?>
				<form class="cc-panel cc-cleaners__form" method="post" action="<?php echo esc_url( $action ); ?>">
					<?php Client_Portal::nonce_field( 'cc_client_auth' ); ?>
					<?php Cleaner_Forms::password_fields( 'New password' ); ?>
					<button type="submit" class="cc-btn cc-btn--primary cc-btn--block">Save new password</button>
				</form>

			<?php elseif ( in_array( $mode, [ 'magic', 'quote_link', 'email' ], true ) ) : ?>
				<form class="cc-panel cc-cleaners__form" method="post" action="<?php echo esc_url( $action ); ?>">
					<?php Client_Portal::nonce_field( 'cc_client_auth' ); ?>
					<?php if ( 'quote_link' === $mode ) : ?>
						<ol class="cc-client__steps">
							<li>Choose a password for your account.</li>
							<li>Check your name and mobile number.</li>
							<li>See your quote and book online.</li>
						</ol>
					<?php endif; ?>
					<button type="submit" class="cc-btn cc-btn--primary cc-btn--block"><?php echo esc_html( [ 'magic' => 'Log me in', 'quote_link' => 'Continue', 'email' => 'Confirm my new email' ][ $mode ] ); ?></button>
					<p class="cc-small">This link works once. If you didn't ask for it, just close this page.</p>
				</form>
			<?php endif; ?>
		</div>
	</section>
</main>
<?php
get_footer();

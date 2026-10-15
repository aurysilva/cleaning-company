<?php
/**
 * Branded HTML email wrapper. Override by copying to {theme}/cc-bookings/email.php.
 *
 * @var array $email subject, content (HTML), button ([label, url] or ''), footer, brand
 */

defined( 'ABSPATH' ) || exit;

$override = locate_template( 'cc-bookings/email.php' );
if ( $override && $override !== __FILE__ ) {
	include $override;
	return;
}
?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $email['subject'] ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f1f8f9;font-family:'Plus Jakarta Sans',Segoe UI,Roboto,Arial,sans-serif;color:#0f2633;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f8f9;padding:24px 12px;">
	<tr>
		<td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #dbe6eb;">
				<tr>
					<td style="background:linear-gradient(135deg,#0b7189 0%,#17b67b 100%);background-color:#0b7189;padding:22px 28px;color:#ffffff;font-size:20px;font-weight:800;letter-spacing:-0.01em;">
						<?php echo esc_html( $email['brand'] ); ?>
					</td>
				</tr>
				<tr>
					<td style="padding:28px;font-size:16px;line-height:1.6;">
						<?php echo $email['content']; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in Notify::render(). ?>
						<?php if ( $email['button'] ) : ?>
							<p style="margin:24px 0 8px;">
								<a href="<?php echo esc_url( $email['button']['url'] ); ?>" style="display:inline-block;padding:14px 26px;border-radius:999px;background:#17b67b;color:#ffffff;font-weight:700;text-decoration:none;">
									<?php echo esc_html( $email['button']['label'] ); ?>
								</a>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $email['manage_url'] ) ) : ?>
							<p style="margin:20px 0 0;font-size:14px;color:#556b78;">
								Need to change or cancel? <a href="<?php echo esc_url( $email['manage_url'] ); ?>" style="color:#0b7189;font-weight:700;">Manage your booking</a>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td style="padding:18px 28px;background:#f1f8f9;color:#556b78;font-size:13px;line-height:1.5;">
						<?php echo esc_html( $email['footer'] ); ?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
</body>
</html>

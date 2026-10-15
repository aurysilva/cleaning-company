<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Bookings;
use CC\Bookings\Catalog;
use CC\Bookings\Db;
use CC\Bookings\Events;
use CC\Bookings\Files;
use CC\Bookings\Money;
use CC\Bookings\Pricing;
use CC\Bookings\Quote_Revisions;
use CC\Bookings\Quotes;
use CC\Bookings\Schema;
use CC\Bookings\Slots;
use CC\Bookings\Status;

defined( 'ABSPATH' ) || exit;

final class Quotes_Screen {
	const PAGE     = 'cc-bookings-quotes';
	const PER_PAGE = 30;

	public static function render(): void {
		$id = Admin::int( 'id' );
		$id ? self::detail( $id ) : self::list();
	}

	private static function list(): void {
		global $wpdb;
		$status = sanitize_key( Admin::text( 'status' ) );
		$search = Admin::text( 's' );
		$paged  = max( 1, Admin::int( 'paged' ) );

		$where  = [];
		$params = [];
		if ( $status && isset( Status::all( 'quote' )[ $status ] ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(ref LIKE %s OR customer_name LIKE %s OR email LIKE %s OR postcode LIKE %s)';
			$params   = array_merge( $params, [ $like, $like, $like, $like ] );
		}
		$sql   = ' FROM ' . Schema::table( 'quotes' ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' );
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( 'SELECT COUNT(*)' . $sql, $params ) : 'SELECT COUNT(*)' . $sql );
		$query = 'SELECT *' . $sql . sprintf( ' ORDER BY id DESC LIMIT %d OFFSET %d', self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$rows  = array_map( [ Db::class, 'decode' ], $wpdb->get_results( $params ? $wpdb->prepare( $query, $params ) : $query, ARRAY_A ) ?: [] );

		Admin::header( 'Quotes' );
		echo Admin::filters( self::PAGE, Status::all( 'quote' ), $status, fn( $s ) => Db::count( 'quotes', $s ? [ 'status' => $s ] : [] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf(
			'<form class="search-box" method="get"><input type="hidden" name="page" value="%s"><input type="search" name="s" value="%s" placeholder="Ref, name, email or postcode"><button class="button">Search</button></form>',
			esc_attr( self::PAGE ),
			esc_attr( $search )
		);

		echo '<table class="widefat striped cc-admin__table"><thead><tr><th>Quote</th><th>Customer</th><th>Service</th><th>Date</th><th>Total</th><th>Status</th><th>Created</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">No quotes found.</td></tr>';
		}
		foreach ( $rows as $q ) {
			printf(
				'<tr><td><a href="%s"><strong>%s</strong></a>%s%s</td><td>%s<br><span class="description">%s · %s</span></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( Quotes::admin_url( $q['id'] ) ),
				esc_html( $q['ref'] ),
				$q['is_bespoke'] ? ' <span class="cc-admin__tag">Bespoke</span>' : '',
				$q['is_test'] ? ' <span class="cc-admin__test">Test</span>' : '',
				esc_html( $q['customer_name'] ),
				esc_html( $q['email'] ),
				esc_html( $q['postcode'] ),
				esc_html( $q['service_name'] ),
				esc_html( $q['job_date'] ? Slots::date_label( $q['job_date'], 'D j M' ) . ' · ' . Slots::label( $q['slot'], false ) : '—' ),
				esc_html( null === $q['total_pence'] ? '—' : Money::format( $q['total_pence'] ) ),
				Status::badge( 'quote', $q['status'] ) . ( $q['booking_id'] ? ' <a href="' . esc_url( Bookings::admin_url( $q['booking_id'] ) ) . '">Booking</a>' : '' ), // phpcs:ignore WordPress.Security.EscapeOutput
				esc_html( Admin::when( $q['created_at'] ) )
			);
		}
		echo '</tbody></table>';
		echo Admin::pagination( self::PAGE, $total, self::PER_PAGE, $paged, array_filter( [ 'status' => $status, 's' => $search ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		Admin::footer();
	}

	private static function detail( int $id ): void {
		$q = Quotes::get( $id );
		if ( ! $q ) {
			Admin::header( 'Quote not found' );
			Admin::footer();
			return;
		}
		$service = Quotes::service( $q );
		$open    = ! $q['booking_id'] && ! in_array( $q['status'], [ 'accepted', 'cancelled', 'superseded' ], true );

		Admin::header( 'Quote ' . $q['ref'], ' ' . Status::badge( 'quote', $q['status'] ) . ( $q['is_test'] ? ' <span class="cc-admin__test">Test</span>' : '' ) . ( $q['is_bespoke'] ? ' <span class="cc-admin__tag">Bespoke</span>' : '' ) );
		printf( '<p><a href="%s">&larr; All quotes</a></p>', esc_url( Admin::url( self::PAGE ) ) );

		if ( $q['booking_id'] ) {
			printf( '<div class="notice notice-info inline"><p>Paid and booked: <a href="%s">view booking</a>.</p></div>', esc_url( Bookings::admin_url( $q['booking_id'] ) ) );
		}
		if ( $q['bespoke_reasons'] ) {
			echo '<div class="notice notice-warning inline"><p><strong>Needs a tailored price:</strong> ' . esc_html( implode( '; ', (array) $q['bespoke_reasons'] ) ) . '</p></div>';
		}

		echo '<div class="cc-admin__cols"><div>';

		echo '<h2>Job</h2><table class="widefat cc-admin__kv"><tbody>';
		$rows = [ 'Service' => $q['service_name'] ];
		foreach ( $service ? Catalog::summary( $service, (array) $q['answers'] ) : [] as [ $label, $value ] ) {
			$rows[ $label ] = $value;
		}
		$rows['Date'] = $q['job_date'] ? Slots::date_label( $q['job_date'] ) . ', ' . Slots::label( $q['slot'] ) : 'Not chosen';
		$rows['Duration'] = Pricing::duration_label( (float) $q['hours'], (int) $q['cleaners'] ) ?: '—';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), nl2br( esc_html( (string) $value ) ) );
		}
		echo '</tbody></table>';

		if ( $q['photos'] ) {
			echo '<h3>Photos</h3><p class="cc-admin__photos">';
			foreach ( (array) $q['photos'] as $name ) {
				printf( '<a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="" loading="lazy"></a>', esc_url( Files::admin_url( $q['ref'], $name ) ) );
			}
			echo '</p>';
		}

		echo '<h2>Price</h2>';
		if ( $open ) {
			self::pricing_form( $q );
		} else {
			self::items_table( (array) $q['items'], $q['total_pence'] );
		}

		self::revisions( $q );

		echo '<h2>History</h2>';
		echo Admin::timeline( Events::for_object( 'quote', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '</div><div>';

		echo '<h2>Customer</h2><table class="widefat cc-admin__kv"><tbody>';
		$customer = [
			'Name'         => $q['customer_name'],
			'Email'        => $q['email'],
			'Mobile'       => $q['phone'],
			'Address'      => implode( "\n", array_filter( [ $q['address_1'], $q['address_2'], $q['town'], $q['postcode'] ] ) ),
			'Area'         => $q['district'] . ( $q['covered'] ? '' : ' (outside usual area)' ),
			'Access notes' => $q['access_notes'] ?: '—',
			'Marketing'    => $q['marketing_opt_in'] ? 'Opted in' : 'No',
		];
		foreach ( $customer as $label => $value ) {
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), nl2br( esc_html( (string) $value ) ) );
		}
		echo '</tbody></table>';
		printf( '<p><a class="button" href="mailto:%s">Email customer</a> <a class="button" href="tel:%s">Call</a></p>', esc_attr( $q['email'] ), esc_attr( $q['phone'] ) );

		echo '<h2>Dates</h2><table class="widefat cc-admin__kv"><tbody>';
		foreach ( [ 'Created' => $q['created_at'], 'Sent' => $q['sent_at'], 'Viewed' => $q['viewed_at'], 'Expires' => $q['expires_at'], 'Accepted' => $q['accepted_at'] ] as $label => $value ) {
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( Admin::when( $value ) ) );
		}
		echo '</tbody></table>';

		echo '<h2>Actions</h2><div class="cc-admin__actions">';
		if ( $open && $q['total_pence'] ) {
			echo Admin::button( 'quote_send', [ 'id' => $id ], 'sent' === $q['status'] || 'viewed' === $q['status'] ? 'Re-send quote email' : 'Send quote to customer', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo Admin::button( 'quote_link', [ 'id' => $id ], 'Get customer link' ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $open && ! $q['followups_off'] && in_array( $q['status'], [ 'sent', 'viewed' ], true ) ) {
			echo Admin::button( 'quote_stop', [ 'id' => $id ], 'Stop reminder emails' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( $open ) {
			echo Admin::button( 'quote_cancel', [ 'id' => $id ], 'Cancel quote', 'button button-link-delete', 'Cancel this quote? The customer will no longer be able to pay.' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';

		if ( $open && $q['total_pence'] ) {
			echo '<h3>Record a payment taken offline</h3>';
			echo Admin::form( 'quote_manual_payment', [ 'id' => $id ], 'onsubmit="return confirm(\'Record this payment and confirm the booking?\')"' ); // phpcs:ignore WordPress.Security.EscapeOutput
			printf( '<p><label>Amount (£) <input type="text" name="amount" value="%s" class="small-text" inputmode="decimal"></label> ', esc_attr( number_format( $q['total_pence'] / 100, 2, '.', '' ) ) );
			echo '<input type="text" name="reference" placeholder="Reference (e.g. bank transfer)" class="regular-text"></p>';
			echo '<p><button class="button">Record payment &amp; confirm booking</button></p></form>';
		}
		echo '</div></div>';
		Admin::footer();
	}

	private static function items_table( array $items, ?int $total ): void {
		if ( ! $items ) {
			echo '<p class="description">Not priced yet.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Item</th><th>Qty</th><th>Amount</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $item['label'] ), esc_html( (string) (float) ( $item['qty'] ?? 1 ) ), esc_html( Money::format( (int) $item['amount'] ) ) );
		}
		printf( '</tbody><tfoot><tr><th colspan="2">Total</th><th>%s</th></tr></tfoot></table>', esc_html( Money::format( $total ) ) );
	}

	private static function pricing_form( array $q ): void {
		$items = array_values( (array) $q['items'] );
		$rows  = array_merge( $items, array_fill( 0, max( 2, 5 - count( $items ) ), [ 'label' => '', 'qty' => 1, 'unit' => '', 'type' => 'base' ] ) );

		echo Admin::form( 'quote_price', [ 'id' => $q['id'] ], 'class="cc-admin__pricing"' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="widefat"><thead><tr><th>Item (shown to the customer)</th><th>Qty</th><th>Unit price (£)</th></tr></thead><tbody>';
		foreach ( $rows as $i => $item ) {
			printf(
				'<tr><td><input type="text" name="items[%1$d][label]" value="%2$s" class="widefat"><input type="hidden" name="items[%1$d][type]" value="%3$s"></td><td><input type="number" step="0.5" min="0" name="items[%1$d][qty]" value="%4$s" class="small-text"></td><td><input type="text" name="items[%1$d][unit]" value="%5$s" class="small-text" inputmode="decimal"></td></tr>',
				$i,
				esc_attr( $item['label'] ),
				esc_attr( $item['type'] ?? 'base' ),
				esc_attr( (string) (float) ( $item['qty'] ?? 1 ) ),
				esc_attr( '' === $item['unit'] ? '' : number_format( (int) $item['unit'] / 100, 2, '.', '' ) )
			);
		}
		printf( '</tbody><tfoot><tr><th colspan="2">Current total</th><th>%s</th></tr></tfoot></table>', esc_html( null === $q['total_pence'] ? '—' : Money::format( $q['total_pence'] ) ) );
		echo '<p class="description">Use a negative unit price for a discount. Empty rows are ignored.</p>';

		echo '<table class="form-table"><tbody>';
		printf( '<tr><th>Hours on site</th><td><input type="number" step="0.5" min="0" name="hours" value="%s" class="small-text"> with <input type="number" min="1" max="10" name="cleaners" value="%d" class="small-text"> cleaner(s)</td></tr>', esc_attr( (string) (float) $q['hours'] ), max( 1, (int) $q['cleaners'] ) );
		$slots = '<option value="">—</option>';
		foreach ( Slots::definitions() as $key => $slot ) {
			$slots .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $q['slot'], $key, false ), esc_html( Slots::label( $key ) ) );
		}
		printf( '<tr><th>Date and arrival</th><td><input type="date" name="job_date" value="%s"> <select name="slot">%s</select><p class="description">The customer\'s preferred date is pre-filled. Capacity is checked again when they pay.</p></td></tr>', esc_attr( (string) $q['job_date'] ), $slots ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<tr><th>Internal note</th><td><textarea name="admin_note" rows="2" class="large-text">%s</textarea></td></tr>', esc_textarea( (string) $q['admin_note'] ) );
		echo '</tbody></table>';

		$service = Quotes::service( $q );
		if ( $service ) {
			echo '<details class="cc-admin__amend"><summary><strong>Property details and extras</strong></summary><table class="form-table"><tbody>';
			self::answer_fields( $service, (array) $q['answers'] );
			echo '<tr><th>Price lines</th><td><label><input type="checkbox" name="reprice" value="1"> Recalculate the price lines, hours and cleaners from the price list (replaces the lines above)</label></td></tr>';
			echo '</tbody></table></details>';
		}

		$sent = (bool) $q['sent_at'];
		echo '<table class="form-table"><tbody>';
		printf(
			'<tr><th>Note to client</th><td><textarea name="client_note" rows="3" class="large-text" maxlength="2000" placeholder="Optional — shown in the email, e.g. why the price changed."></textarea><p class="description">%s</p></td></tr>',
			$sent ? 'The customer has already been sent this quote. Changes they can see are saved as a new version, the old version can no longer be paid, and "Save &amp; email the update" sends them a "Your quote has been updated" email listing what changed.' : 'Included in the quote email.'
		);
		echo '</tbody></table>';
		printf(
			'<p><button class="button" name="then" value="save">Save</button> <button class="button button-primary" name="then" value="send">%s</button></p></form>',
			$sent ? 'Save &amp; email the update' : 'Save &amp; send to customer'
		);
	}

	/** Editable property details (as the customer's form, without photos) and add-ons. */
	private static function answer_fields( array $service, array $answers ): void {
		foreach ( Catalog::fields( $service ) as $field ) {
			$name  = $field['name'];
			$input = '';
			switch ( $field['type'] ) {
				case 'select':
				case 'size':
					$options = 'size' === $field['type'] ? array_map( fn( $s ) => $s['label'], (array) $service['sizes'] ) : $field['options'];
					$input   = '<option value="">—</option>';
					foreach ( $options as $value => $label ) {
						$input .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( (string) $value ), selected( (string) ( $answers[ $name ] ?? '' ), (string) $value, false ), esc_html( (string) $label ) );
					}
					$input = sprintf( '<select name="answers[%s]">%s</select>', esc_attr( $name ), $input );
					break;
				case 'quantities':
					foreach ( (array) $service['sizes'] as $index => $size ) {
						$input .= sprintf( '<label style="display:inline-block;margin:0 12px 6px 0;"><input type="number" min="0" max="20" name="answers[quantities][%d]" value="%s" class="small-text"> %s</label>', (int) $index, esc_attr( (string) ( $answers['quantities'][ $index ] ?? '' ) ), esc_html( $size['label'] ) );
					}
					break;
				case 'textarea':
					$input = sprintf( '<textarea name="answers[%s]" rows="2" class="large-text">%s</textarea>', esc_attr( $name ), esc_textarea( (string) ( $answers[ $name ] ?? '' ) ) );
					break;
			}
			if ( '' !== $input ) {
				printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( rtrim( str_replace( ' (optional)', '', $field['label'] ), '?' ) ), $input ); // phpcs:ignore WordPress.Security.EscapeOutput -- built above.
			}
		}
		if ( $service['addons'] ) {
			$input = '';
			foreach ( (array) $service['addons'] as $index => $addon ) {
				$qty    = (int) ( $answers['addons'][ $index ] ?? 0 );
				$input .= $addon['per']
					? sprintf( '<label style="display:block;margin-bottom:4px;"><input type="number" min="0" max="20" name="answers[addons][%d]" value="%s" class="small-text"> %s</label>', (int) $index, $qty ? $qty : '', esc_html( $addon['name'] ) )
					: sprintf( '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="answers[addons][%d]" value="1"%s> %s</label>', (int) $index, checked( $qty > 0, true, false ), esc_html( $addon['name'] ) );
			}
			printf( '<tr><th>Extras</th><td>%s</td></tr>', $input ); // phpcs:ignore WordPress.Security.EscapeOutput -- built above.
		}
	}

	private static function revisions( array $q ): void {
		$rows = Quote_Revisions::all( (int) $q['id'] );
		if ( ! $rows ) {
			return;
		}
		echo '<h2>Versions</h2><table class="widefat striped"><thead><tr><th>Version</th><th>What changed</th><th>Note to client</th><th>Emailed</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$user = $row['created_by'] ? get_userdata( (int) $row['created_by'] ) : null;
			printf(
				'<tr><td>%s<br><span class="description">%s%s</span></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				(int) $row['revision'] === Quote_Revisions::revision( $q ) ? '<strong>' . (int) $row['revision'] . ' (current)</strong>' : (int) $row['revision'],
				esc_html( Admin::when( $row['created_at'] ) ),
				$user ? ' · ' . esc_html( $user->display_name ) : '',
				esc_html( Quote_Revisions::text( (array) ( $row['data']['changes'] ?? [] ) ) ),
				esc_html( (string) $row['client_note'] ?: '—' ),
				esc_html( $row['notified_at'] ? Admin::when( $row['notified_at'] ) : 'Not yet' )
			);
		}
		printf( '<tr><td>1<br><span class="description">%s</span></td><td colspan="3">First version sent to the customer</td></tr>', esc_html( Admin::when( $q['created_at'] ) ) );
		echo '</tbody></table><p class="description">Each version lists what changed from the one before. Only the current version can be paid.</p>';
	}

	public static function handle( string $do ): void {
		$id    = Admin::int( 'id' );
		$quote = Quotes::get( $id );
		$back  = Quotes::admin_url( $id );
		if ( ! $quote ) {
			Admin::done( Admin::url( self::PAGE ), 'Quote not found.', 'error' );
		}

		switch ( $do ) {
			case 'quote_price':
				$items = [];
				foreach ( (array) ( $_POST['items'] ?? [] ) as $row ) { // phpcs:ignore WordPress.Security.NonceVerification
					$label = sanitize_text_field( wp_unslash( (string) ( $row['label'] ?? '' ) ) );
					$unit  = Money::parse( wp_unslash( (string) ( $row['unit'] ?? '' ) ) );
					if ( '' === $label || null === $unit ) {
						continue;
					}
					$qty     = max( 0, (float) ( $row['qty'] ?? 1 ) ) ?: 1;
					$type    = sanitize_key( (string) ( $row['type'] ?? 'base' ) ) ?: 'base';
					$items[] = Pricing::line( $unit < 0 ? 'adjustment' : $type, $label, $qty, $unit );
				}
				$date     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', Admin::text( 'job_date' ) ) ? Admin::text( 'job_date' ) : '';
				$slot     = isset( Slots::definitions()[ Admin::text( 'slot' ) ] ) ? Admin::text( 'slot' ) : '';
				$hours    = max( 0, (float) Admin::text( 'hours' ) );
				$cleaners = max( 1, Admin::int( 'cleaners' ) );
				$service  = Quotes::service( $quote );
				$answers  = null;
				if ( $service && isset( $_POST['answers'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					[ $clean ] = Catalog::sanitize_answers( $service, (array) wp_unslash( $_POST['answers'] ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
					$editable  = array_merge( array_column( Catalog::fields( $service ), 'name' ), [ 'addons' ] );
					$answers   = array_diff_key( (array) $quote['answers'], array_flip( $editable ) ) + $clean;
				}
				if ( $service && Admin::text( 'reprice' ) ) {
					$price = Pricing::price( $service, $answers ?? (array) $quote['answers'], $date, $slot );
					if ( $price['reasons'] ) {
						Admin::done( $back, 'The price list can\'t price this (' . esc_html( implode( '; ', $price['reasons'] ) ) . '). Nothing was saved — enter the price lines by hand instead.', 'error' );
					}
					[ $items, $hours, $cleaners ] = [ $price['items'], (float) $price['hours'], (int) $price['cleaners'] ];
				}
				if ( ! $items ) {
					Admin::done( $back, 'Add at least one price line. Nothing was saved.', 'error' );
				}
				$client_note = Admin::textarea( 'client_note' );
				$result      = Quotes::update_pricing( $id, $items, $hours, $cleaners, $date, $slot, Admin::textarea( 'admin_note' ), $answers, $client_note );
				if ( is_wp_error( $result ) ) {
					Admin::done( $back, $result->get_error_message(), 'error' );
				}
				$saved = $result['revised'] ? sprintf( 'Saved as version %d (%d change%s).', $result['revision'], count( $result['changes'] ), 1 === count( $result['changes'] ) ? '' : 's' ) : 'Saved.';
				if ( 'send' !== Admin::text( 'then' ) ) {
					Admin::done( $back, $saved . ( $result['revised'] ? ' The customer hasn\'t been emailed yet — use "Save &amp; email the update" or "Re-send quote email" when ready.' : '' ) );
				}
				$url = Quotes::send( $id, '', $result['revised'] ? '' : $client_note );
				if ( is_wp_error( $url ) ) {
					Admin::done( $back, $url->get_error_message(), 'error' );
				}
				Admin::done( $back, $saved . ' ' . ( $quote['sent_at'] ? 'Update' : 'Quote' ) . ' emailed to ' . esc_html( $quote['email'] ) . '.' );
				break;

			case 'quote_send':
				$url = Quotes::send( $id );
				if ( is_wp_error( $url ) ) {
					Admin::done( $back, $url->get_error_message(), 'error' );
				}
				Admin::done( $back, 'Quote emailed to ' . esc_html( $quote['email'] ) . '.' );
				break;

			case 'quote_link':
				$url = Quotes::link( $quote );
				Admin::done( $back, 'Customer link (valid like the emailed ones — don\'t share publicly):<br><code>' . esc_html( $url ) . '</code>', 'info' );
				break;

			case 'quote_stop':
				Quotes::stop_followups( $quote );
				Admin::done( $back, 'Reminder emails stopped.' );
				break;

			case 'quote_cancel':
				Quotes::set_status( $id, 'cancelled', 'Cancelled by admin', true );
				Admin::done( $back, 'Quote cancelled.' );
				break;

			case 'quote_manual_payment':
				$amount = Money::parse( Admin::text( 'amount' ) );
				if ( ! $amount || $amount <= 0 ) {
					Admin::done( $back, 'Enter the amount received.', 'error' );
				}
				$reference = Admin::text( 'reference' );
				$booking   = Bookings::confirm_payment( [
					'quote_id'   => $id,
					'session_id' => 'manual_' . $id . '_' . time(),
					'amount'     => $amount,
					'currency'   => 'gbp',
					'provider'   => 'manual',
				] );
				if ( is_wp_error( $booking ) ) {
					Admin::done( $back, $booking->get_error_message(), 'error' );
				}
				if ( $reference ) {
					Events::log( 'booking', (int) $booking, 'note', 'Offline payment reference: ' . $reference );
				}
				Admin::done( Bookings::admin_url( (int) $booking ), 'Payment recorded and booking confirmed.' );
				break;
		}
		Admin::done( $back, 'Unknown action.', 'error' );
	}
}

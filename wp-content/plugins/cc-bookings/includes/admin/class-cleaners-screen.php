<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Bookings;
use CC\Bookings\Catalog;
use CC\Bookings\Cleaners;
use CC\Bookings\Db;
use CC\Bookings\Events;
use CC\Bookings\Money;
use CC\Bookings\Slots;
use CC\Bookings\Status;

defined( 'ABSPATH' ) || exit;

final class Cleaners_Screen {
	const PAGE = 'cc-bookings-cleaners';

	public static function render(): void {
		$id = Admin::text( 'id' );
		if ( 'new' === $id ) {
			self::edit( null );
		} elseif ( (int) $id ) {
			self::edit( Cleaners::get( (int) $id ) );
		} else {
			self::list();
		}
	}

	private static function list(): void {
		Admin::header( 'Cleaners', sprintf( ' <a href="%s" class="page-title-action">Add cleaner</a>', esc_url( Admin::url( self::PAGE, [ 'id' => 'new' ] ) ) ) );
		$rows = Db::all( 'cleaners', [], 'status ASC, name ASC' );
		echo '<table class="widefat striped cc-admin__table"><thead><tr><th>Name</th><th>Contact</th><th>Type</th><th>Areas</th><th>Upcoming jobs</th><th>Status</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">No cleaners yet. Add your team so you can assign them to bookings.</td></tr>';
		}
		foreach ( $rows as $c ) {
			printf(
				'<tr><td><a href="%s"><strong>%s</strong></a></td><td>%s<br><span class="description">%s</span></td><td>%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
				esc_url( Admin::url( self::PAGE, [ 'id' => $c['id'] ] ) ),
				esc_html( $c['name'] ),
				esc_html( $c['email'] ),
				esc_html( $c['phone'] ),
				esc_html( Cleaners::TYPES[ $c['type'] ] ?? $c['type'] ),
				esc_html( implode( ', ', (array) $c['districts'] ) ?: 'Any' ),
				count( Cleaners::jobs( $c['id'] ) ),
				esc_html( ucfirst( $c['status'] ) )
			);
		}
		echo '</tbody></table>';
		Admin::footer();
	}

	private static function edit( ?array $c ): void {
		$c = $c ?? [ 'id' => 0, 'name' => '', 'email' => '', 'phone' => '', 'type' => 'employed', 'status' => 'active', 'districts' => [], 'services' => [], 'can_lead' => 0, 'max_jobs_per_day' => 2, 'pay_type' => 'hourly', 'pay_rate_pence' => 0, 'has_car' => 1, 'notes' => '' ];
		Admin::header( $c['id'] ? $c['name'] : 'Add cleaner' );
		printf( '<p><a href="%s">&larr; All cleaners</a></p>', esc_url( Admin::url( self::PAGE ) ) );

		echo Admin::form( 'cleaner_save', [ 'id' => $c['id'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table"><tbody>';
		$text = fn( $name, $label, $value, $type = 'text', $help = '' ) => printf(
			'<tr><th><label for="cc-c-%1$s">%2$s</label></th><td><input id="cc-c-%1$s" type="%4$s" name="%1$s" value="%3$s" class="regular-text">%5$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) $value ),
			esc_attr( $type ),
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
		$text( 'name', 'Name', $c['name'] );
		$text( 'email', 'Email', $c['email'], 'email', 'Job details are emailed here.' );
		$text( 'phone', 'Mobile', $c['phone'], 'tel' );

		$select = function ( string $name, string $label, array $options, string $current ): void {
			$html = '';
			foreach ( $options as $value => $text ) {
				$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $text ) );
			}
			printf( '<tr><th><label for="cc-c-%1$s">%2$s</label></th><td><select id="cc-c-%1$s" name="%1$s">%3$s</select></td></tr>', esc_attr( $name ), esc_html( $label ), $html ); // phpcs:ignore WordPress.Security.EscapeOutput
		};
		$select( 'type', 'Type', Cleaners::TYPES, $c['type'] );
		$select( 'status', 'Status', [ 'active' => 'Active', 'inactive' => 'Inactive' ], $c['status'] );

		echo '<tr><th colspan="2"><h2>For automatic dispatch (coming later)</h2></th></tr>';
		$text( 'districts', 'Postcode areas', implode( ', ', (array) $c['districts'] ), 'text', 'e.g. HU5, HU6, HU10. Leave blank for anywhere.' );
		$boxes = '';
		foreach ( Catalog::services() as $slug => $service ) {
			if ( Catalog::OTHER === $slug ) {
				continue;
			}
			$boxes .= sprintf( '<label class="cc-admin__check"><input type="checkbox" name="services[]" value="%s"%s> %s</label>', esc_attr( $slug ), checked( in_array( $slug, (array) $c['services'], true ), true, false ), esc_html( $service['name'] ) );
		}
		printf( '<tr><th>Services</th><td>%s<p class="description">Leave all unticked for any service.</p></td></tr>', $boxes ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<tr><th>Options</th><td><label class="cc-admin__check"><input type="checkbox" name="can_lead" value="1"%s> Can lead a team</label><label class="cc-admin__check"><input type="checkbox" name="has_car" value="1"%s> Has a car</label></td></tr>', checked( $c['can_lead'], 1, false ), checked( $c['has_car'], 1, false ) );
		$text( 'max_jobs_per_day', 'Max jobs per day', $c['max_jobs_per_day'], 'number' );
		$select( 'pay_type', 'Pay', [ 'hourly' => 'Hourly', 'per_job' => 'Per job' ], $c['pay_type'] );
		$text( 'pay_rate', 'Pay rate (£)', $c['pay_rate_pence'] ? number_format( $c['pay_rate_pence'] / 100, 2, '.', '' ) : '' );
		printf( '<tr><th><label for="cc-c-notes">Notes</label></th><td><textarea id="cc-c-notes" name="notes" rows="3" class="large-text">%s</textarea></td></tr>', esc_textarea( (string) $c['notes'] ) );
		echo '</tbody></table><p><button class="button button-primary">Save cleaner</button></p></form>';

		if ( $c['id'] ) {
			$jobs = Cleaners::jobs( $c['id'] );
			echo '<h2>Upcoming jobs</h2>';
			if ( $jobs ) {
				echo '<table class="widefat striped"><tbody>';
				foreach ( $jobs as $b ) {
					printf( '<tr><td>%s</td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>', esc_html( Bookings::start_label( $b ) ), esc_url( Bookings::admin_url( $b['id'] ) ), esc_html( $b['ref'] ), esc_html( $b['postcode'] ), Status::badge( 'booking', $b['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</tbody></table>';
			} else {
				echo '<p class="description">None.</p>';
			}
			echo '<h2>History</h2>' . Admin::timeline( Events::for_object( 'cleaner', $c['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		Admin::footer();
	}

	public static function handle( string $do ): void {
		if ( 'cleaner_save' !== $do ) {
			Admin::done( Admin::url( self::PAGE ), 'Unknown action.', 'error' );
		}
		$id    = Admin::int( 'id' );
		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$result = Cleaners::save( $input, $id );
		if ( is_wp_error( $result ) ) {
			Admin::done( Admin::url( self::PAGE, [ 'id' => $id ?: 'new' ] ), $result->get_error_message(), 'error' );
		}
		Admin::done( Admin::url( self::PAGE, [ 'id' => $result ] ), 'Cleaner saved.' );
	}
}

<?php
namespace CC\Bookings\Admin;

use CC\Bookings\Availability;
use CC\Bookings\Bookings;
use CC\Bookings\Catalog;
use CC\Bookings\Cleaners;
use CC\Bookings\Db;
use CC\Bookings\Events;
use CC\Bookings\Job_Links;
use CC\Bookings\Job_Pages;
use CC\Bookings\Money;
use CC\Bookings\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 2 Cleaners screen (replaces the phase 1 renderer through `cc_bookings_admin_pages`): full profile,
 * regions, weekly hours, time off and the cleaner's "My jobs" link. The form still posts to the core
 * cleaner_save action; Availability::save_profile() stores the extra fields on `cc_cleaner_saved`.
 */
final class Cleaner_Profiles_Screen {
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

	private static function areas(): array {
		$out = [];
		foreach ( get_posts( [ 'post_type' => 'cc_area', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ] ) as $post ) {
			$out[ $post->ID ] = $post->post_title;
		}
		return $out;
	}

	private static function coverage( array $c, array $areas ): string {
		$parts = array_merge(
			array_map( fn( $id ) => $areas[ $id ] ?? '#' . $id, Availability::areas( $c ) ),
			(array) $c['districts']
		);
		return $parts ? implode( ', ', $parts ) : 'Anywhere';
	}

	private static function list(): void {
		Admin::header( 'Cleaners', sprintf( ' <a href="%s" class="page-title-action">Add cleaner</a>', esc_url( Admin::url( self::PAGE, [ 'id' => 'new' ] ) ) ) );
		echo '<p class="description">Dispatch uses each cleaner\'s areas, services, hours, time off and daily limit. Employed cleaners are assigned automatically; self-employed cleaners are offered jobs by email and text.</p>';
		$rows  = Db::all( 'cleaners', [], 'status ASC, name ASC' );
		$areas = self::areas();
		Availability::prime( array_column( $rows, 'id' ) );
		echo '<table class="widefat striped cc-admin__table"><thead><tr><th>Name</th><th>Contact</th><th>Type</th><th>Covers</th><th>Hours</th><th>Pay</th><th>Upcoming</th><th>Status</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">No cleaners yet. Until you add some, booking slots use the capacity under Settings → Dates &amp; capacity.</td></tr>';
		}
		foreach ( $rows as $c ) {
			printf(
				'<tr><td><a href="%s"><strong>%s</strong></a>%s</td><td>%s<br><span class="description">%s</span></td><td>%s</td><td>%s<br><span class="description">%s</span></td><td>%s</td><td>%s</td><td>%d</td><td>%s</td></tr>',
				esc_url( Admin::url( self::PAGE, [ 'id' => $c['id'] ] ) ),
				esc_html( $c['name'] ),
				$c['can_lead'] ? ' <span class="description">(team lead)</span>' : '',
				esc_html( $c['email'] ),
				esc_html( $c['phone'] ),
				esc_html( Cleaners::TYPES[ $c['type'] ] ?? $c['type'] ),
				esc_html( self::coverage( $c, $areas ) ),
				esc_html( $c['services'] ? count( (array) $c['services'] ) . ' services' : 'All services' ),
				esc_html( Availability::week_label( (int) $c['id'] ) ),
				esc_html( $c['pay_rate_pence'] ? Money::format( (int) $c['pay_rate_pence'] ) . ( 'per_job' === $c['pay_type'] ? '/job' : '/hr' ) : '—' ),
				count( Cleaners::jobs( (int) $c['id'] ) ),
				apply_filters( 'cc_bookings_admin_cleaner_status', esc_html( ucfirst( $c['status'] ) ), $c ) // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
		echo '</tbody></table>';
		Admin::footer();
	}

	private static function edit( ?array $c ): void {
		$new = ! $c;
		$c   = $c ?? [ 'id' => 0, 'name' => '', 'email' => '', 'phone' => '', 'type' => 'employed', 'status' => 'active', 'districts' => [], 'areas' => [], 'services' => [], 'can_lead' => 0, 'max_jobs_per_day' => 2, 'pay_type' => 'hourly', 'pay_rate_pence' => 0, 'has_car' => 1, 'notes' => '' ];
		Admin::header( $c['id'] ? $c['name'] : 'Add cleaner' );
		printf( '<p><a href="%s">&larr; All cleaners</a></p>', esc_url( Admin::url( self::PAGE ) ) );

		echo '<div class="cc-admin__cols"><div>';
		echo Admin::form( 'cleaner_save', [ 'id' => $c['id'], 'cc_dispatch_profile' => 1 ] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="form-table"><tbody>';
		$text = fn( $name, $label, $value, $type = 'text', $help = '' ) => printf(
			'<tr><th><label for="cc-c-%1$s">%2$s</label></th><td><input id="cc-c-%1$s" type="%4$s" name="%1$s" value="%3$s" class="regular-text">%5$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) $value ),
			esc_attr( $type ),
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
		$select = function ( string $name, string $label, array $options, string $current, string $help = '' ): void {
			$html = '';
			foreach ( $options as $value => $text ) {
				$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $text ) );
			}
			printf( '<tr><th><label for="cc-c-%1$s">%2$s</label></th><td><select id="cc-c-%1$s" name="%1$s">%3$s</select>%4$s</td></tr>', esc_attr( $name ), esc_html( $label ), $html, $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		};

		$text( 'name', 'Name', $c['name'] );
		$text( 'email', 'Email', $c['email'], 'email', 'Offers and job details are emailed here.' );
		$text( 'phone', 'Mobile', $c['phone'], 'tel', 'Offers and job changes are also texted here.' );
		$select( 'type', 'Type', Cleaners::TYPES, $c['type'], 'Employed: assigned automatically. Self-employed: offered jobs, first to accept gets them.' );
		$select( 'status', 'Status', [ 'active' => 'Active', 'inactive' => 'Inactive (no new jobs, links stop working)' ], $c['status'] );
		do_action( 'cc_bookings_admin_cleaner_form', $c );

		echo '<tr><th colspan="2"><h2>Where and what</h2></th></tr>';
		$areas = self::areas();
		if ( $areas ) {
			$boxes = '';
			foreach ( $areas as $area_id => $title ) {
				$boxes .= sprintf( '<label class="cc-admin__check" title="%s"><input type="checkbox" name="areas[]" value="%d"%s> %s</label>', esc_attr( implode( ' ', Availability::area_districts( $area_id ) ) ), $area_id, checked( in_array( $area_id, Availability::areas( $c ), true ), true, false ), esc_html( $title ) );
			}
			printf( '<tr><th>Regions</th><td>%s<p class="description">Covers every postcode district listed on that area page.</p></td></tr>', $boxes ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$text( 'districts', 'Extra postcode districts', implode( ', ', (array) $c['districts'] ), 'text', 'e.g. HU5, HU6, HU10. Leave regions and districts empty for anywhere.' );
		$boxes = '';
		foreach ( Catalog::services() as $slug => $service ) {
			if ( Catalog::OTHER === $slug ) {
				continue;
			}
			$boxes .= sprintf( '<label class="cc-admin__check"><input type="checkbox" name="services[]" value="%s"%s> %s</label>', esc_attr( $slug ), checked( in_array( $slug, (array) $c['services'], true ), true, false ), esc_html( $service['name'] ) );
		}
		printf( '<tr><th>Services</th><td>%s<p class="description">Leave all unticked for any service.</p></td></tr>', $boxes ); // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<tr><th>Options</th><td><label class="cc-admin__check"><input type="checkbox" name="can_lead" value="1"%s> Can lead a team</label><label class="cc-admin__check"><input type="checkbox" name="has_car" value="1"%s> Has a car</label></td></tr>', checked( $c['can_lead'], 1, false ), checked( $c['has_car'], 1, false ) );

		echo '<tr><th colspan="2"><h2>Hours</h2></th></tr>';
		$rules  = $c['id'] ? Availability::rules( (int) $c['id'] ) : [ 'weekly' => [ 1 => [ [ '08:00', '17:00' ] ], 2 => [ [ '08:00', '17:00' ] ], 3 => [ [ '08:00', '17:00' ] ], 4 => [ [ '08:00', '17:00' ] ], 5 => [ [ '08:00', '17:00' ] ] ], 'off' => [] ];
		$any    = ! $rules['weekly'];
		$grid   = '';
		foreach ( Availability::DAYS as $n => $day ) {
			$window = $rules['weekly'][ $n ][0] ?? null;
			$grid  .= sprintf(
				'<tr><td><label><input type="checkbox" name="week[%1$d][on]" value="1"%2$s> %3$s</label></td><td><input type="time" name="week[%1$d][start]" value="%4$s"> – <input type="time" name="week[%1$d][end]" value="%5$s"></td></tr>',
				$n,
				checked( (bool) $window, true, false ),
				esc_html( $day ),
				esc_attr( $window[0] ?? '08:00' ),
				esc_attr( $window[1] ?? '17:00' )
			);
		}
		printf(
			'<tr><th>Available</th><td><label class="cc-admin__check"><input type="radio" name="hours_mode" value="set"%s> These hours each week</label><label class="cc-admin__check"><input type="radio" name="hours_mode" value="any"%s> Any time</label><table class="cc-admin__week">%s</table><p class="description">A job is only given if it fits inside these hours (arrival time + job length). Untick every day for no regular hours.</p></td></tr>',
			checked( ! $any, true, false ),
			checked( $any, true, false ),
			$grid // phpcs:ignore WordPress.Security.EscapeOutput
		);
		$text( 'max_jobs_per_day', 'Max jobs per day', $c['max_jobs_per_day'], 'number' );

		$off = '';
		foreach ( $rules['off'] as $row ) {
			if ( $row['to'] < wp_date( 'Y-m-d' ) ) {
				continue;
			}
			$off .= sprintf(
				'<li><input type="hidden" name="off_shown[]" value="%1$d"><label><input type="checkbox" name="off_keep[]" value="%1$d" checked> %2$s%3$s%4$s</label></li>',
				$row['id'],
				esc_html( $row['from'] . ( $row['to'] !== $row['from'] ? ' to ' . $row['to'] : '' ) ),
				esc_html( $row['start'] ? ' (' . $row['start'] . '–' . ( $row['end'] ?: '23:59' ) . ')' : '' ),
				esc_html( $row['note'] ? ' — ' . $row['note'] : '' )
			);
		}
		printf(
			'<tr><th>Time off</th><td>%s<p><input type="date" name="off_from" aria-label="From"> to <input type="date" name="off_to" aria-label="To"> <input type="time" name="off_start" aria-label="Start time"> – <input type="time" name="off_end" aria-label="End time"> <input type="text" name="off_note" placeholder="Note, e.g. holiday" class="regular-text"></p><p class="description">Add holidays or appointments. Leave the times empty for whole days. Untick an entry to remove it.</p></td></tr>',
			$off ? '<ul>' . $off . '</ul>' : '<p class="description">None booked.</p>' // phpcs:ignore WordPress.Security.EscapeOutput
		);

		echo '<tr><th colspan="2"><h2>Pay</h2></th></tr>';
		$select( 'pay_type', 'Pay', [ 'hourly' => 'Hourly', 'per_job' => 'Per job' ], $c['pay_type'], 'Shown on job offers and used for pay reports.' );
		$text( 'pay_rate', 'Pay rate (£)', $c['pay_rate_pence'] ? number_format( $c['pay_rate_pence'] / 100, 2, '.', '' ) : '' );
		printf( '<tr><th><label for="cc-c-notes">Notes</label></th><td><textarea id="cc-c-notes" name="notes" rows="3" class="large-text">%s</textarea></td></tr>', esc_textarea( (string) $c['notes'] ) );
		echo '</tbody></table><p><button class="button button-primary">Save cleaner</button></p></form>';
		echo '</div><div>';

		if ( ! $new ) {
			do_action( 'cc_bookings_admin_cleaner_sidebar', $c );
			echo '<h2>"My jobs" link</h2><p class="description">A private link where the cleaner sees their upcoming jobs and open offers. Every job email and text also carries a link to that job.</p><div class="cc-admin__actions">';
			echo Admin::button( 'dispatch_cleaner_link', [ 'id' => $c['id'] ], 'Email & text the link', 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo Admin::button( 'dispatch_cleaner_show', [ 'id' => $c['id'] ], 'Show link' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo Admin::button( 'dispatch_cleaner_revoke', [ 'id' => $c['id'] ], 'Revoke link', 'button button-link-delete', 'Stop the current "My jobs" link working? Job links already sent keep working.' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';

			$lists = Job_Pages::cleaner_jobs( (int) $c['id'] );
			echo '<h2>Upcoming jobs</h2>';
			if ( $lists['jobs'] ) {
				echo '<table class="widefat striped"><tbody>';
				foreach ( $lists['jobs'] as [ $offer, $b ] ) {
					printf( '<tr><td>%s</td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td></tr>', esc_html( Bookings::start_label( $b ) ), esc_url( Bookings::admin_url( (int) $b['id'] ) ), esc_html( $b['ref'] ), esc_html( $b['postcode'] ), Status::badge( 'offer', $offer['status'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</tbody></table>';
			} else {
				echo '<p class="description">None.</p>';
			}
			if ( $lists['offers'] ) {
				echo '<h3>Open offers</h3><table class="widefat striped"><tbody>';
				foreach ( $lists['offers'] as [ $offer, $b ] ) {
					printf( '<tr><td>%s</td><td><a href="%s">%s</a></td><td>%s</td></tr>', esc_html( Bookings::start_label( $b ) ), esc_url( Bookings::admin_url( (int) $b['id'] ) ), esc_html( $b['ref'] ), esc_html( $b['district'] ) );
				}
				echo '</tbody></table>';
			}
			echo '<h2>History</h2>' . Admin::timeline( Events::for_object( 'cleaner', (int) $c['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div></div>';
		Admin::footer();
	}

	public static function handle( string $do ): void {
		$id      = Admin::int( 'id' );
		$cleaner = Cleaners::get( $id );
		$back    = Admin::url( self::PAGE, [ 'id' => $id ] );
		if ( ! $cleaner ) {
			Admin::done( Admin::url( self::PAGE ), 'Cleaner not found.', 'error' );
		}
		switch ( $do ) {
			case 'dispatch_cleaner_link':
				\CC\Bookings\Dispatch_Notify::jobs_link( $id );
				Admin::done( $back, 'Link emailed and texted to ' . esc_html( $cleaner['name'] ) . '.' );
				break;
			case 'dispatch_cleaner_show':
				Admin::done( $back, '"My jobs" link (private):<br><code>' . esc_html( Job_Links::cleaner_url( $id ) ) . '</code>', 'info' );
				break;
			case 'dispatch_cleaner_revoke':
				Job_Links::revoke( 'cleaners', $id );
				Events::log( 'cleaner', $id, 'link_revoked', '"My jobs" link revoked' );
				Admin::done( $back, 'Old link revoked. Use "Email & text the link" to send a new one.' );
				break;
		}
		Admin::done( $back, 'Unknown action.', 'error' );
	}
}

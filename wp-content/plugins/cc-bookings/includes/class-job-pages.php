<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Cleaner pages (no login; links from Job_Links). Private like the quote and booking pages: noindex,
 * no-store, never cached by LiteSpeed, no Referer.
 *   /job/{offer_id}/{sig}/       offer (district only) with Accept / Decline; after accepting, the full job
 *                                with "Can't make it" and "Mark job complete" (optional photos)
 *   /job/me/{cleaner_id}/{sig}/  My upcoming jobs and open offers
 * Actions are POSTed back to the same URL, then redirected (?r=code) so a refresh never repeats them.
 */
final class Job_Pages {
	const MESSAGES = [
		'accepted'  => [ 'success', 'The job is yours. The full details are below.' ],
		'already'   => [ 'success', 'This job is already yours.' ],
		'declined'  => [ 'info', 'Thanks for letting us know. We\'ll offer it to someone else.' ],
		'taken'     => [ 'warning', 'Sorry, this job has been taken.' ],
		'dropped'   => [ 'info', 'Thanks for telling us. We\'ve let the office know and will find someone else.' ],
		'completed' => [ 'success', 'Job marked complete. Thank you!' ],
		'clash'     => [ 'warning', 'This job clashes with your other jobs or working hours, so we can\'t give it to you. Call the office if that\'s wrong.' ],
		'lead'      => [ 'warning', 'The last place on this team needs a team lead.' ],
		'closed'    => [ 'warning', 'This job is no longer available.' ],
		'withdrawn' => [ 'warning', 'This offer is no longer available.' ],
		'early'     => [ 'warning', 'You can mark the job complete once it has started.' ],
		'photos'    => [ 'warning', 'Some photos couldn\'t be uploaded. Please use JPG or PNG images under 8MB, up to 6.' ],
		'busy'      => [ 'warning', 'Lots of people are answering right now. Please try again in a moment.' ],
		'limit'     => [ 'warning', 'Too many attempts. Please wait a few minutes or call the office.' ],
		'error'     => [ 'warning', 'Something went wrong. Please call the office.' ],
	];

	public static function init(): void {
		add_action( 'cc_bookings_rewrites', [ self::class, 'rewrites' ] );
		add_filter( 'query_vars', fn( $vars ) => array_merge( $vars, [ 'cc_job', 'cc_jobs_for', 'cc_sig' ] ) );
		add_action( 'template_redirect', [ self::class, 'route' ], 1 );
	}

	public static function rewrites(): void {
		add_rewrite_rule( '^job/me/(\d+)/([A-Za-z0-9_-]{20,})/?$', 'index.php?cc_jobs_for=$matches[1]&cc_sig=$matches[2]', 'top' );
		add_rewrite_rule( '^job/(\d+)/([A-Za-z0-9_-]{20,})/?$', 'index.php?cc_job=$matches[1]&cc_sig=$matches[2]', 'top' );
	}

	public static function route(): void {
		$offer_id   = (int) get_query_var( 'cc_job' );
		$cleaner_id = (int) get_query_var( 'cc_jobs_for' );
		if ( ! $offer_id && ! $cleaner_id ) {
			return;
		}
		Frontend::private_headers();
		$sig = (string) get_query_var( 'cc_sig' );

		if ( $cleaner_id ) {
			$cleaner = Job_Links::verify_cleaner( $cleaner_id, $sig );
			if ( ! $cleaner ) {
				Frontend::render( 'job-invalid', [], 'Link not valid', 404 );
			}
			Frontend::render( 'cleaner-jobs', [ 'cleaner' => $cleaner, 'sig' => $sig ], 'My jobs' );
		}

		$offer = Job_Links::verify_offer( $offer_id, $sig );
		if ( ! $offer ) {
			Frontend::render( 'job-invalid', [], 'Link not valid', 404 );
		}
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			self::handle( $offer );
		}
		$booking = Bookings::get( (int) $offer['booking_id'] );
		$cleaner = Cleaners::get( (int) $offer['cleaner_id'] );
		if ( ! $booking || ! $cleaner ) {
			Frontend::render( 'job-invalid', [], 'Link not valid', 404 );
		}
		$code = sanitize_key( (string) ( $_GET['r'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		Frontend::render( 'job', [
			'offer'   => $offer,
			'booking' => $booking,
			'cleaner' => $cleaner,
			'view'    => self::view( $offer, $booking ),
			'notice'  => self::MESSAGES[ $code ] ?? null,
			'action'  => self::current_url(),
			'jobs'    => Job_Links::cleaner_url( (int) $cleaner['id'] ),
		], 'Job ' . $booking['ref'] );
	}

	private static function current_url(): string {
		return home_url( strtok( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), '?' ) );
	}

	/** POST from the job page. The signed URL is the credential; actions are idempotent by status. */
	private static function handle( array $offer ): void {
		// phpcs:disable WordPress.Security.NonceVerification -- authorised by the signed link; no logged-in session.
		$do = sanitize_key( (string) ( $_POST['cc_job_action'] ?? '' ) );
		if ( ! Security::rate_limit( 'job:' . $offer['id'], 20, HOUR_IN_SECONDS ) ) {
			$result = [ 'code' => 'limit' ];
		} else {
			$result = match ( $do ) {
				'accept'   => Dispatch::accept( (int) $offer['id'] ),
				'decline'  => Dispatch::decline( (int) $offer['id'] ),
				'drop'     => Dispatch::drop( (int) $offer['id'], (string) wp_unslash( $_POST['reason'] ?? '' ) ),
				'complete' => Dispatch::complete( (int) $offer['id'], Files::from_request( $_FILES ) ),
				default    => [ 'code' => 'error' ],
			};
		}
		// phpcs:enable
		wp_safe_redirect( add_query_arg( 'r', $result['code'], self::current_url() ), 303 );
		exit;
	}

	/**
	 * What the page shows: open | taken | closed | declined | withdrawn | on_job | dropped | removed | completed | cancelled.
	 */
	public static function view( array $offer, array $booking ): string {
		$status = (string) $offer['status'];
		if ( 'cancelled' === $booking['status'] ) {
			return 'cancelled';
		}
		if ( in_array( $status, Status::on_job(), true ) ) {
			return 'completed' === $booking['status'] ? 'completed' : 'on_job';
		}
		if ( in_array( $status, [ 'dropped', 'removed' ], true ) ) {
			return $status;
		}
		$full = count( Bookings::crew( (int) $booking['id'] ) ) >= (int) $booking['cleaners'];
		if ( ! Dispatch::dispatchable( $booking ) ) {
			return 'closed';
		}
		if ( $full ) {
			return 'taken';
		}
		if ( 'declined' === $status ) {
			return 'declined';
		}
		return in_array( $status, [ 'offered', 'expired', 'accepting' ], true ) ? 'open' : 'withdrawn';
	}

	/** The customer's number is shown from 24 hours before the job. */
	public static function show_phone( array $booking ): bool {
		return time() >= Availability::job( $booking )['start'] - DAY_IN_SECONDS;
	}

	/** Open offers and upcoming jobs for "My jobs". */
	public static function cleaner_jobs( int $cleaner_id ): array {
		$out = [ 'offers' => [], 'jobs' => [] ];
		foreach ( Db::all( 'job_offers', [ 'cleaner_id' => $cleaner_id, 'status' => array_merge( Status::on_job(), [ 'offered' ] ) ], 'id DESC', 200 ) as $offer ) {
			$booking = Bookings::get( (int) $offer['booking_id'] );
			if ( ! $booking || ! $booking['job_date'] || $booking['job_date'] < wp_date( 'Y-m-d' ) || in_array( $booking['status'], [ 'cancelled', 'completed' ], true ) ) {
				continue;
			}
			$view = self::view( $offer, $booking );
			if ( 'on_job' === $view ) {
				$out['jobs'][] = [ $offer, $booking ];
			} elseif ( 'open' === $view ) {
				$out['offers'][] = [ $offer, $booking ];
			}
		}
		$sort = fn( $a, $b ) => Availability::job( $a[1] )['start'] <=> Availability::job( $b[1] )['start'];
		usort( $out['jobs'], $sort );
		usort( $out['offers'], $sort );
		return $out;
	}
}

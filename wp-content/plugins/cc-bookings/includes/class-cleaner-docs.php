<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Cleaner documents and the profile photo (wp_cc_cleaner_docs). Files live in
 * uploads/cc-bookings-private/cleaners/{cleaner_id}/ (web access denied) under random names, and are served by
 * /cleaners/file/{doc_id}/ to the owning cleaner and Bookings admins only. Images are re-encoded (resized, metadata
 * stripped); PDFs are checked by their magic bytes. Re-uploading marks the earlier copy replaced (an approved copy
 * stays in force until the new one is approved).
 *
 * The approved photo of an active cleaner is the only file shown to customers, through a signed URL
 * /cleaners/photo/{cleaner_id}/{sig}/ that stops working when the photo changes or the account isn't active.
 * Expiry: reminders to the cleaner and the office 30 and 7 days before (accounts.reminder_days), then "expired".
 */
final class Cleaner_Docs {
	const TABLE     = 'cleaner_docs';
	const MAX_BYTES = 10 * MB_IN_BYTES;
	const IMAGES    = [ 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ];
	const FILES     = self::IMAGES + [ 'heic' => 'image/heic', 'pdf' => 'application/pdf' ];

	const STATUSES = [
		'pending'  => 'Waiting for review',
		'approved' => 'Approved',
		'rejected' => 'Please upload again',
		'expired'  => 'Expired',
		'replaced' => 'Replaced',
	];

	public static function types(): array {
		return apply_filters( 'cc_bookings_cleaner_doc_types', [
			'photo'         => [ 'label' => 'Profile photo', 'help' => 'A clear, friendly head-and-shoulders photo with a plain background. Customers see it with your first name.', 'required' => 'all', 'expiry' => 'none', 'image_only' => true ],
			'photo_id'      => [ 'label' => 'Photo ID', 'help' => 'Your passport or photocard driving licence.', 'required' => 'all', 'expiry' => 'required' ],
			'right_to_work' => [ 'label' => 'Right to work in the UK', 'help' => 'A UK or Irish passport, or your Home Office share-code check, visa or BRP. Add the end date if your permission is time-limited.', 'required' => 'all', 'expiry' => 'optional' ],
			'dbs'           => [ 'label' => 'DBS certificate', 'help' => 'Your basic or enhanced DBS certificate. Add a recheck date if you have one.', 'required' => 'all', 'expiry' => 'optional' ],
			'insurance'     => [ 'label' => 'Public liability insurance', 'help' => 'Your policy schedule showing the cover and end date. Required if you are self-employed.', 'required' => 'self_employed', 'expiry' => 'required' ],
		] );
	}

	/** Document types for the documents step and page (the photo has its own). */
	public static function document_types(): array {
		return array_diff_key( self::types(), [ 'photo' => true ] );
	}

	public static function required( string $type, array $cleaner ): bool {
		$rule = self::types()[ $type ]['required'] ?? '';
		return 'all' === $rule || $rule === $cleaner['type'];
	}

	public static function label( string $type ): string {
		return self::types()[ $type ]['label'] ?? ucfirst( str_replace( '_', ' ', $type ) );
	}

	/** For mid-sentence use: "photo ID", "right to work in the UK", but "DBS certificate". */
	public static function label_inline( string $type ): string {
		$label = self::label( $type );
		return preg_match( '/^[A-Z]{2}/', $label ) ? $label : lcfirst( $label );
	}

	public static function badge( string $status ): string {
		return sprintf( '<span class="cc-status cc-status--doc-%s">%s</span>', esc_attr( $status ), esc_html( self::STATUSES[ $status ] ?? ucfirst( $status ) ) );
	}

	public static function get( int $id ): ?array {
		return Db::get( self::TABLE, $id );
	}

	/** Latest copy of each type that isn't replaced: type => row. */
	public static function current( int $cleaner_id ): array {
		$out = [];
		foreach ( Db::all( self::TABLE, [ 'cleaner_id' => $cleaner_id, 'status' => [ 'pending', 'approved', 'rejected', 'expired' ] ], 'id DESC' ) as $doc ) {
			$out[ $doc['type'] ] ??= $doc;
		}
		return $out;
	}

	public static function approved( int $cleaner_id, string $type ): ?array {
		return Db::find( self::TABLE, [ 'cleaner_id' => $cleaner_id, 'type' => $type, 'status' => 'approved' ] );
	}

	public static function dir( int $cleaner_id ): string {
		return Files::dir() . '/cleaners/' . $cleaner_id;
	}

	public static function path( array $doc ): string {
		return self::dir( (int) $doc['cleaner_id'] ) . '/' . basename( (string) $doc['file'] );
	}

	/** Viewer URL for the owning cleaner and admins. */
	public static function url( array $doc ): string {
		return home_url( '/cleaners/file/' . (int) $doc['id'] . '/' );
	}

	/* ---------------------------------------------------------------------
	 * Upload
	 * ------------------------------------------------------------------ */

	/** One file from $_FILES[ $field ] (single upload), or null when nothing was chosen. */
	public static function from_request( string $field ): ?array {
		$file = $_FILES[ $field ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification -- callers check the nonce.
		if ( ! is_array( $file ) || ! isset( $file['error'] ) || is_array( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return null;
		}
		return [ 'name' => (string) $file['name'], 'tmp_name' => (string) $file['tmp_name'], 'error' => (int) $file['error'], 'size' => (int) $file['size'] ];
	}

	/** @return int|\WP_Error Document id. */
	public static function upload( array $cleaner, string $type, array $file, string $expires = '' ) {
		$def = self::types()[ $type ] ?? null;
		if ( ! $def ) {
			return new \WP_Error( 'type', 'Unknown document.' );
		}
		$label = $def['label'];
		if ( UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'upload', in_array( $file['error'], [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true ) ? $label . ': the file is too big. Please keep it under 10MB.' : $label . ': the file didn\'t upload. Please try again.' );
		}
		if ( $file['size'] > self::MAX_BYTES || $file['size'] <= 0 ) {
			return new \WP_Error( 'size', $label . ': please keep the file under 10MB.' );
		}
		$images = ! empty( $def['image_only'] );
		$check  = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $images ? self::IMAGES : self::FILES );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new \WP_Error( 'type', $label . ': ' . ( $images ? 'please upload a JPG, PNG or WebP photo.' : 'please upload a PDF, or a JPG, PNG, WebP or HEIC photo.' ) );
		}
		if ( 'pdf' === $check['ext'] && '%PDF-' !== (string) file_get_contents( $file['tmp_name'], false, null, 0, 5 ) ) {
			return new \WP_Error( 'type', $label . ': that file isn\'t a valid PDF.' );
		}
		$expires = self::expiry_value( $type, $expires );
		if ( is_wp_error( $expires ) ) {
			return $expires;
		}

		Files::protect();
		$dir = self::dir( (int) $cleaner['id'] );
		wp_mkdir_p( $dir );
		$name = wp_generate_password( 24, false ) . '.' . $check['ext'];
		$dest = $dir . '/' . $name;
		$mime = $check['type'];

		$stored = false;
		if ( in_array( $mime, self::IMAGES, true ) ) {
			$editor = wp_get_image_editor( $file['tmp_name'] );
			if ( ! is_wp_error( $editor ) ) {
				'photo' === $type ? $editor->resize( 600, 600, true ) : $editor->resize( 2000, 2000, false );
				$editor->set_quality( 85 );
				$saved  = $editor->save( $dest, $mime );
				$stored = ! is_wp_error( $saved ) && is_file( $dest );
			}
			if ( ! $stored && 'photo' === $type ) {
				return new \WP_Error( 'image', $label . ': we couldn\'t read that image. Please try a different photo.' );
			}
		}
		if ( ! $stored && ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new \WP_Error( 'store', $label . ': the file couldn\'t be saved. Please try again.' );
		}

		// Earlier copies waiting for review (or rejected / expired) are superseded now; an approved copy stays until this one is approved.
		foreach ( Db::all( self::TABLE, [ 'cleaner_id' => (int) $cleaner['id'], 'type' => $type, 'status' => [ 'pending', 'rejected', 'expired' ] ] ) as $old ) {
			self::retire( $old );
		}
		$id = Db::insert( self::TABLE, [
			'cleaner_id' => (int) $cleaner['id'],
			'type'       => $type,
			'status'     => 'pending',
			'file'       => $name,
			'mime'       => $mime,
			'size'       => (int) filesize( $dest ),
			'original'   => mb_substr( sanitize_file_name( $file['name'] ), 0, 200 ),
			'expires_on' => $expires ?: null,
		] );
		Events::log( 'cleaner', (int) $cleaner['id'], 'doc_uploaded', $label . ' uploaded' . ( $expires ? ' (expires ' . $expires . ')' : '' ), [ 'doc_id' => $id ], Cleaner_Accounts::current() ? 'cleaner:' . $cleaner['id'] : '' );
		do_action( 'cc_cleaner_doc_uploaded', $id, (int) $cleaner['id'] );
		return $id;
	}

	/** @return string|\WP_Error Y-m-d or ''. */
	private static function expiry_value( string $type, string $raw ) {
		$rule = self::types()[ $type ]['expiry'] ?? 'none';
		$raw  = trim( $raw );
		if ( 'none' === $rule ) {
			return '';
		}
		if ( '' === $raw ) {
			return 'required' === $rule ? new \WP_Error( 'expiry', self::label( $type ) . ': please add the expiry date.' ) : '';
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, wp_timezone() );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $raw ) {
			return new \WP_Error( 'expiry', self::label( $type ) . ': please enter the expiry date as a date.' );
		}
		if ( $raw <= wp_date( 'Y-m-d' ) ) {
			return new \WP_Error( 'expiry', self::label( $type ) . ': that date has already passed. Please upload an in-date copy.' );
		}
		return $raw;
	}

	private static function retire( array $doc ): void {
		$path = self::path( $doc );
		if ( $doc['file'] && is_file( $path ) ) {
			wp_delete_file( $path );
		}
		Db::update( self::TABLE, (int) $doc['id'], [ 'status' => 'replaced', 'file' => '' ] );
	}

	/* ---------------------------------------------------------------------
	 * Review (admin)
	 * ------------------------------------------------------------------ */

	public static function approve( int $doc_id ): bool {
		$doc = self::get( $doc_id );
		if ( ! $doc || ! in_array( $doc['status'], [ 'pending', 'rejected' ], true ) ) {
			return false;
		}
		foreach ( Db::all( self::TABLE, [ 'cleaner_id' => (int) $doc['cleaner_id'], 'type' => $doc['type'], 'status' => 'approved' ] ) as $old ) {
			self::retire( $old );
		}
		Db::update( self::TABLE, $doc_id, [ 'status' => 'approved', 'reason' => '', 'reviewed_by' => get_current_user_id(), 'reviewed_at' => Db::now() ] );
		Events::log( 'cleaner', (int) $doc['cleaner_id'], 'doc_approved', self::label( $doc['type'] ) . ' approved', [ 'doc_id' => $doc_id ] );
		do_action( 'cc_cleaner_doc_reviewed', $doc_id, 'approved' );
		return true;
	}

	/** @return true|\WP_Error */
	public static function reject( int $doc_id, string $reason ) {
		$doc     = self::get( $doc_id );
		$reason  = mb_substr( sanitize_text_field( $reason ), 0, 255 );
		$cleaner = $doc ? Cleaners::get( (int) $doc['cleaner_id'] ) : null;
		if ( ! $doc || ! $cleaner || ! in_array( $doc['status'], [ 'pending', 'approved' ], true ) ) {
			return new \WP_Error( 'state', 'This document can\'t be rejected now.' );
		}
		if ( '' === $reason ) {
			return new \WP_Error( 'reason', 'Give a reason so the cleaner knows what to fix.' );
		}
		Db::update( self::TABLE, $doc_id, [ 'status' => 'rejected', 'reason' => $reason, 'reviewed_by' => get_current_user_id(), 'reviewed_at' => Db::now() ] );
		Events::log( 'cleaner', (int) $doc['cleaner_id'], 'doc_rejected', self::label( $doc['type'] ) . ' rejected: ' . $reason, [ 'doc_id' => $doc_id ] );
		Notify::send( 'cleaner_doc_rejected', (string) $cleaner['email'], Cleaner_Accounts::vars( $cleaner ) + [
			'doc'        => self::label_inline( $doc['type'] ),
			'reason'     => $reason,
			'portal_url' => Cleaner_Portal::url( 'photo' === $doc['type'] ? 'profile' : 'documents' ),
		], [ 'cleaner', (int) $cleaner['id'] ] );
		do_action( 'cc_cleaner_doc_reviewed', $doc_id, 'rejected' );
		return true;
	}

	/** @return true|\WP_Error */
	public static function set_expiry( int $doc_id, string $date ) {
		$doc = self::get( $doc_id );
		if ( ! $doc || 'replaced' === $doc['status'] ) {
			return new \WP_Error( 'missing', 'Document not found.' );
		}
		$date = trim( $date );
		if ( '' !== $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new \WP_Error( 'date', 'Enter the date as YYYY-MM-DD.' );
		}
		$data = [ 'expires_on' => $date ?: null, 'reminded' => '' ];
		if ( 'expired' === $doc['status'] && $date > wp_date( 'Y-m-d' ) ) {
			$data['status'] = 'approved';
		}
		Db::update( self::TABLE, $doc_id, $data );
		Events::log( 'cleaner', (int) $doc['cleaner_id'], 'doc_expiry', self::label( $doc['type'] ) . ' expiry set to ' . ( $date ?: 'none' ), [ 'doc_id' => $doc_id ] );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Expiry reminders (daily)
	 * ------------------------------------------------------------------ */

	/** @return int Emails sent. */
	public static function reminders(): int {
		global $wpdb;
		$days = array_values( array_filter( array_map( 'intval', explode( ',', (string) Cleaner_Accounts::opt( 'reminder_days' ) ) ) ) );
		rsort( $days );
		$far  = wp_date( 'Y-m-d', time() + ( ( $days[0] ?? 30 ) + 1 ) * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( self::TABLE ) . " WHERE status IN ('approved','pending') AND expires_on IS NOT NULL AND expires_on <= %s", $far ), ARRAY_A ) ?: [];
		$sent = 0;
		$today = new \DateTimeImmutable( wp_date( 'Y-m-d' ), wp_timezone() );
		foreach ( $rows as $doc ) {
			$doc     = Db::decode( $doc );
			$cleaner = Cleaners::get( (int) $doc['cleaner_id'] );
			if ( ! $cleaner || in_array( Cleaner_Accounts::status( $cleaner ), [ 'deactivated', 'none' ], true ) ) {
				continue;
			}
			$left     = (int) $today->diff( new \DateTimeImmutable( $doc['expires_on'], wp_timezone() ) )->format( '%r%a' );
			$reminded = array_filter( explode( ',', (string) $doc['reminded'] ) );
			$mark     = '';
			if ( $left <= 0 ) {
				if ( ! in_array( 'expired', $reminded, true ) ) {
					$mark = 'expired';
					Db::update( self::TABLE, (int) $doc['id'], [ 'status' => 'expired' ] );
					Events::log( 'cleaner', (int) $cleaner['id'], 'doc_expired', self::label( $doc['type'] ) . ' has expired', [ 'doc_id' => (int) $doc['id'] ] );
				}
			} else {
				foreach ( array_reverse( $days ) as $threshold ) {
					if ( $left <= $threshold && ! in_array( (string) $threshold, $reminded, true ) ) {
						$mark = (string) $threshold;
						break;
					}
				}
			}
			if ( '' === $mark ) {
				continue;
			}
			// Mark every threshold already passed, so a late upload doesn't get the 30-day and 7-day emails together.
			$reminded = array_unique( array_merge( $reminded, [ $mark ], array_map( 'strval', array_filter( $days, fn( $d ) => $d >= $left ) ) ) );
			Db::update( self::TABLE, (int) $doc['id'], [ 'reminded' => implode( ',', $reminded ) ] );
			$vars = Cleaner_Accounts::vars( $cleaner ) + [
				'doc'           => self::label_inline( $doc['type'] ),
				'expires'       => wp_date( 'j F Y', strtotime( $doc['expires_on'] . ' 12:00' ) ),
				'expiry_phrase' => $left <= 0 ? 'has expired' : sprintf( 'expires in %d day%s', $left, 1 === $left ? '' : 's' ),
				'portal_url'    => Cleaner_Portal::url( 'photo' === $doc['type'] ? 'profile' : 'documents' ),
			];
			Notify::send( 'cleaner_doc_expiring', (string) $cleaner['email'], $vars, [ 'cleaner', (int) $cleaner['id'] ] );
			Notify::send( 'cleaner_doc_expiring_admin', Settings::admin_email(), $vars, [ 'cleaner', (int) $cleaner['id'] ] );
			$sent += 2;
		}
		return $sent;
	}

	/** Delete every file and row for a cleaner (retention, test clean-up). */
	public static function purge( int $cleaner_id ): void {
		foreach ( Db::all( self::TABLE, [ 'cleaner_id' => $cleaner_id ] ) as $doc ) {
			$path = self::path( $doc );
			if ( $doc['file'] && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		Db::delete( self::TABLE, [ 'cleaner_id' => $cleaner_id ] );
		$dir = self::dir( $cleaner_id );
		if ( is_dir( $dir ) ) {
			foreach ( glob( $dir . '/*' ) ?: [] as $file ) {
				wp_delete_file( $file );
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/* ---------------------------------------------------------------------
	 * Serving
	 * ------------------------------------------------------------------ */

	/** /cleaners/file/{id}/: the owning cleaner or a Bookings admin. */
	public static function serve( int $doc_id ): void {
		$doc     = self::get( $doc_id );
		$cleaner = Cleaner_Accounts::current();
		$allowed = $doc && ( Plugin::can_manage() || ( $cleaner && (int) $cleaner['id'] === (int) $doc['cleaner_id'] && Cleaner_Auth::can_log_in( wp_get_current_user() ) ) );
		if ( ! $allowed || ! $doc['file'] || ! is_file( self::path( $doc ) ) ) {
			status_header( $doc && is_user_logged_in() ? 403 : 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Not found.';
			exit;
		}
		$ext = pathinfo( (string) $doc['file'], PATHINFO_EXTENSION );
		self::output( self::path( $doc ), (string) $doc['mime'], self::label( $doc['type'] ) . '.' . $ext );
	}

	private static function output( string $path, string $mime, string $filename, string $cache = 'private, no-store, max-age=0' ): void {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header_remove( 'Set-Cookie' );
		header( 'Content-Type: ' . ( $mime ?: 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Cache-Control: ' . $cache );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( str_starts_with( $mime, 'image/' ) ) {
			header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'" );
		}
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Customer-facing photo
	 * ------------------------------------------------------------------ */

	private static function photo_sig( array $doc ): string {
		return rtrim( strtr( base64_encode( hash_hmac( 'sha256', 'photo|' . (int) $doc['cleaner_id'] . '|' . (int) $doc['id'] . '|' . $doc['file'], wp_salt( 'auth' ), true ) ), '+/', '-_' ), '=' );
	}

	/** Signed photo URL for customers: active account with an approved photo only. */
	public static function photo_url( array $cleaner ): string {
		if ( 'active' !== Cleaner_Accounts::status( $cleaner ) || 'active' !== $cleaner['status'] ) {
			return '';
		}
		$doc = self::approved( (int) $cleaner['id'], 'photo' );
		return $doc && $doc['file'] ? home_url( '/cleaners/photo/' . (int) $cleaner['id'] . '/' . self::photo_sig( $doc ) . '/' ) : '';
	}

	public static function serve_photo( int $cleaner_id, string $sig ): void {
		$cleaner = Cleaners::get( $cleaner_id );
		$doc     = $cleaner ? self::approved( $cleaner_id, 'photo' ) : null;
		if ( ! $doc || ! self::photo_url( $cleaner ) || ! hash_equals( self::photo_sig( $doc ), $sig ) || ! is_file( self::path( $doc ) ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Not found.';
			exit;
		}
		self::output( self::path( $doc ), (string) $doc['mime'], 'cleaner.' . pathinfo( (string) $doc['file'], PATHINFO_EXTENSION ), 'private, max-age=86400' );
	}

	/** Crew for customers: [ [ first name, photo URL or '' ], … ]. */
	public static function public_crew( int $booking_id ): array {
		$out = [];
		foreach ( Bookings::crew( $booking_id ) as $offer ) {
			if ( $offer['cleaner'] ) {
				$out[] = [ Notify::first_name( (string) $offer['cleaner']['name'] ), self::photo_url( $offer['cleaner'] ) ];
			}
		}
		return $out;
	}

	/** `cc_bookings_booking_crew` (customer booking page): first names, with photos where approved. */
	public static function booking_crew( $html, $booking ) {
		$crew = self::public_crew( (int) $booking['id'] );
		if ( ! $crew || ! array_filter( array_column( $crew, 1 ) ) ) {
			return $html;
		}
		Cleaner_Portal::enqueue_style();
		$out = '<ul class="cc-crew">';
		foreach ( $crew as [ $name, $photo ] ) {
			$out .= '<li class="cc-crew__member">' . ( $photo ? '<img class="cc-crew__photo" src="' . esc_url( $photo ) . '" alt="" width="56" height="56" loading="lazy">' : '<span class="cc-crew__initial" aria-hidden="true">' . esc_html( mb_substr( $name, 0, 1 ) ) . '</span>' ) . '<span>' . esc_html( $name ) . '</span></li>';
		}
		return '<p>Your cleaner' . ( count( $crew ) > 1 ? 's' : '' ) . ':</p>' . $out . '</ul>';
	}

	/** Email card for "Your cleaner is…": photo and first name of each crew member (inline styles for email clients). */
	public static function crew_card_html( int $booking_id ): string {
		$cells = '';
		foreach ( self::public_crew( $booking_id ) as [ $name, $photo ] ) {
			$cells .= '<td style="padding:0 16px 0 0;text-align:center;vertical-align:top;">'
				. ( $photo ? '<img src="' . esc_url( $photo ) . '" alt="' . esc_attr( $name ) . '" width="72" height="72" style="display:block;width:72px;height:72px;border-radius:50%;margin:0 auto 6px;">' : '' )
				. '<strong style="font-size:15px;color:#0f2633;">' . esc_html( $name ) . '</strong></td>';
		}
		return $cells ? '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:8px 0 16px;"><tr>' . $cells . '</tr></table>' : '';
	}
}

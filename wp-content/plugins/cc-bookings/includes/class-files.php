<?php
namespace CC\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Customer photos, kept out of the media library in uploads/cc-bookings-private (web access denied)
 * and served to admins only through admin-post.php?action=cc_bookings_photo.
 */
final class Files {
	const MAX_FILES = 6;
	const MAX_BYTES = 8 * MB_IN_BYTES;
	const TYPES     = [ 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'gif' => 'image/gif' ];

	public static function dir(): string {
		return trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'cc-bookings-private';
	}

	public static function protect(): void {
		$dir = self::dir();
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
		}
	}

	/**
	 * Normalised list of uploaded files from $_FILES['photos'] (multiple).
	 */
	public static function from_request( array $files ): array {
		$photos = $files['photos'] ?? null;
		if ( ! $photos || ! isset( $photos['name'] ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) $photos['name'] as $i => $name ) {
			if ( (int) ( (array) $photos['error'] )[ $i ] === UPLOAD_ERR_NO_FILE ) {
				continue;
			}
			$out[] = [
				'name'     => (string) $name,
				'tmp_name' => (string) ( (array) $photos['tmp_name'] )[ $i ],
				'error'    => (int) ( (array) $photos['error'] )[ $i ],
				'size'     => (int) ( (array) $photos['size'] )[ $i ],
			];
		}
		return $out;
	}

	public static function validate( array $files ): ?string {
		if ( count( $files ) > self::MAX_FILES ) {
			return sprintf( 'Please send no more than %d photos.', self::MAX_FILES );
		}
		foreach ( $files as $file ) {
			if ( UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				return 'One of the photos didn\'t upload. Please try again.';
			}
			if ( $file['size'] > self::MAX_BYTES ) {
				return 'Each photo must be under 8MB.';
			}
			$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::TYPES );
			if ( empty( $check['ext'] ) ) {
				return 'Photos must be JPG, PNG, WebP, HEIC or GIF images.';
			}
		}
		return null;
	}

	/** @return string[] stored file names, relative to the quote's folder */
	public static function store( array $files, string $ref ): array {
		self::protect();
		$dir = self::dir() . '/' . sanitize_file_name( $ref );
		wp_mkdir_p( $dir );
		$stored = [];
		foreach ( $files as $file ) {
			$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::TYPES );
			$name  = wp_generate_password( 16, false ) . '.' . $check['ext'];
			if ( move_uploaded_file( $file['tmp_name'], $dir . '/' . $name ) ) {
				$stored[] = $name;
			}
		}
		return $stored;
	}

	public static function admin_url( string $ref, string $name ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=cc_bookings_photo&ref=' . rawurlencode( $ref ) . '&file=' . rawurlencode( $name ) ), 'cc_bookings_photo' );
	}

	public static function serve(): void {
		if ( ! Plugin::can_manage() || ! check_admin_referer( 'cc_bookings_photo' ) ) {
			wp_die( 'Not allowed', 403 );
		}
		$ref  = sanitize_file_name( wp_unslash( $_GET['ref'] ?? '' ) );
		$name = sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) );
		$path = self::dir() . '/' . $ref . '/' . $name;
		if ( ! $ref || ! $name || ! is_file( $path ) ) {
			wp_die( 'Photo not found', 404 );
		}
		$type = wp_check_filetype( $path, self::TYPES );
		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ?: 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path );
		exit;
	}

	public static function delete_for( string $ref ): void {
		$dir = self::dir() . '/' . sanitize_file_name( $ref );
		if ( ! $ref || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( glob( $dir . '/*' ) ?: [] as $file ) {
			wp_delete_file( $file );
		}
		@rmdir( $dir );
	}
}

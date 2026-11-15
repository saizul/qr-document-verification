<?php
/**
 * Document fingerprints: SHA-256 of the stored PDF and PDF content checks.
 *
 * Deliberately free of WordPress calls so it can be unit tested with plain PHP.
 * The WordPress glue (post meta, caching) lives in helpers.php.
 *
 * @package QR_Document_Verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SHA-256 fingerprints of verification PDFs.
 */
class QDV_Fingerprint {

	const META_SHA256 = '_qdv_pdf_sha256';
	const META_SIZE   = '_qdv_pdf_size';
	const META_MTIME  = '_qdv_pdf_mtime';

	/**
	 * The PDF specification allows the "%PDF-" header anywhere in the first
	 * 1024 bytes (some generators prepend a BOM or junk bytes).
	 */
	const HEADER_WINDOW = 1024;

	/**
	 * Normalize a SHA-256 hex digest: lower case, separators removed.
	 *
	 * Accepts the forms people copy around: upper case, grouped with spaces,
	 * colons or dashes, or prefixed with "sha256:". Returns '' for anything
	 * that is not exactly 64 hex characters after cleanup.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public static function normalize( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( 0 === strpos( $value, 'sha256:' ) ) {
			$value = substr( $value, 7 );
		} elseif ( 0 === strpos( $value, 'sha-256:' ) ) {
			$value = substr( $value, 8 );
		}
		$value = preg_replace( '/[\s:\-]+/', '', $value );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Group a digest into blocks of four for easier reading, e.g. "3f2a 9c01 ...".
	 *
	 * @param string $hash Digest.
	 * @return string '' for an invalid digest.
	 */
	public static function format( $hash ) {
		$hash = self::normalize( $hash );
		if ( '' === $hash ) {
			return '';
		}
		return implode( ' ', str_split( $hash, 4 ) );
	}

	/**
	 * Short form for list tables: first and last 8 characters.
	 *
	 * @param string $hash Digest.
	 * @return string
	 */
	public static function short( $hash ) {
		$hash = self::normalize( $hash );
		if ( '' === $hash ) {
			return '';
		}
		return substr( $hash, 0, 8 ) . "\xE2\x80\xA6" . substr( $hash, -8 );
	}

	/**
	 * Constant-time comparison of two digests in any accepted notation.
	 *
	 * @param string $expected Stored digest.
	 * @param string $actual   Digest to check.
	 * @return bool False if either side is not a valid digest.
	 */
	public static function matches( $expected, $actual ) {
		$expected = self::normalize( $expected );
		$actual   = self::normalize( $actual );
		if ( '' === $expected || '' === $actual ) {
			return false;
		}
		return hash_equals( $expected, $actual );
	}

	/**
	 * SHA-256 of a file on disk.
	 *
	 * @param string $path File path.
	 * @return string Lower-case hex digest, or '' when the file is unreadable.
	 */
	public static function compute( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}
		$hash = hash_file( 'sha256', $path );
		return false === $hash ? '' : $hash;
	}

	/**
	 * Whether a string of leading bytes looks like the start of a PDF.
	 *
	 * @param string $bytes The first bytes of a file.
	 * @return bool
	 */
	public static function has_pdf_header( $bytes ) {
		$bytes = substr( (string) $bytes, 0, self::HEADER_WINDOW );
		return 1 === preg_match( '/%PDF-[12]\.\d/', $bytes );
	}

	/**
	 * Whether a file on disk really is a PDF (checks the content, not the name).
	 *
	 * The upload's extension and browser-supplied MIME type are easy to fake;
	 * this reads the file header instead.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function is_pdf_file( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		// Plain fopen()/fread(): WP_Filesystem has no partial-read API and this class must stay WordPress-free.
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		$head = fread( $fh, self::HEADER_WINDOW ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return self::has_pdf_header( false === $head ? '' : $head );
	}

	/**
	 * Whether a cached digest must be recomputed for the file as it is now.
	 *
	 * The digest is cached together with the file size and modification time;
	 * if either changed (the PDF was replaced over FTP, restored from a backup,
	 * ...), the cached value no longer describes the file.
	 *
	 * @param array $cached {
	 *     Stored values, all optional.
	 *
	 *     @type string $sha256 Cached digest.
	 *     @type int    $size   File size when it was hashed.
	 *     @type int    $mtime  Modification time when it was hashed.
	 * }
	 * @param int   $size  Current file size.
	 * @param int   $mtime Current modification time.
	 * @return bool
	 */
	public static function needs_refresh( array $cached, $size, $mtime ) {
		if ( '' === self::normalize( isset( $cached['sha256'] ) ? $cached['sha256'] : '' ) ) {
			return true;
		}
		$cached_size  = isset( $cached['size'] ) ? (int) $cached['size'] : -1;
		$cached_mtime = isset( $cached['mtime'] ) ? (int) $cached['mtime'] : -1;
		return (int) $size !== $cached_size || (int) $mtime !== $cached_mtime;
	}
}

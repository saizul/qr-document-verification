<?php
/**
 * Document validity: expiry dates and revocation.
 *
 * Deliberately free of WordPress calls so it can be unit tested with plain PHP.
 * The caller passes in the stored meta values and "today" as a Y-m-d string
 * (in the site's timezone), so the result never depends on the server clock.
 *
 * @package QR_Document_Verification
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validity status of an entry.
 */
class QDV_Status {

	const VALID   = 'valid';
	const EXPIRED = 'expired';
	const REVOKED = 'revoked';

	const META_EXPIRES       = '_qdv_expires';
	const META_REVOKED       = '_qdv_revoked';
	const META_REVOKED_AT    = '_qdv_revoked_at';
	const META_REVOKE_REASON = '_qdv_revoke_reason';

	const MAX_REASON_LENGTH = 300;

	/**
	 * Normalize a date to Y-m-d, or '' when empty or not a real calendar date.
	 *
	 * Accepts Y-m-d (what an <input type="date"> submits), Y/m/d and d.m.Y.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public static function sanitize_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $value, $m ) ) {
			list( , $y, $mo, $d ) = $m;
		} elseif ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m ) ) {
			list( , $d, $mo, $y ) = $m;
		} else {
			return '';
		}
		$y  = (int) $y;
		$mo = (int) $mo;
		$d  = (int) $d;
		if ( $y < 1900 || $y > 9999 || ! checkdate( $mo, $d, $y ) ) {
			return '';
		}
		return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
	}

	/**
	 * Clean a revocation reason: single line, no tags, length capped.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public static function sanitize_reason( $value ) {
		$value = strip_tags( (string) $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Kept WordPress-free for unit tests; the admin also runs sanitize_text_field().
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
		$value = trim( preg_replace( '/\s{2,}/u', ' ', $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::MAX_REASON_LENGTH, 'UTF-8' );
		}
		return substr( $value, 0, self::MAX_REASON_LENGTH );
	}

	/**
	 * Work out the validity of an entry.
	 *
	 * Revocation always wins over expiry. An expiry date is inclusive: the
	 * document is still valid on that day and expired from the next day on.
	 *
	 * @param array  $meta  {
	 *     Stored values, all optional.
	 *
	 *     @type string $expires    Expiry date (Y-m-d) or ''.
	 *     @type mixed  $revoked    Truthy when revoked.
	 *     @type string $revoked_at Revocation date (Y-m-d) or ''.
	 *     @type string $reason     Public revocation reason or ''.
	 * }
	 * @param string $today Today's date (Y-m-d) in the site timezone.
	 * @return array {
	 *     @type string   $status     One of VALID, EXPIRED, REVOKED.
	 *     @type bool     $is_valid   True only for VALID.
	 *     @type string   $expires    Normalized expiry date or ''.
	 *     @type string   $revoked_at Normalized revocation date or ''.
	 *     @type string   $reason     Revocation reason ('' unless revoked).
	 *     @type int|null $days_left  Days until expiry (0 = expires today), negative once expired, null without an expiry date.
	 * }
	 */
	public static function evaluate( array $meta, $today ) {
		$expires    = isset( $meta['expires'] ) ? self::sanitize_date( $meta['expires'] ) : '';
		$revoked    = ! empty( $meta['revoked'] );
		$revoked_at = isset( $meta['revoked_at'] ) ? self::sanitize_date( $meta['revoked_at'] ) : '';
		$reason     = ( $revoked && isset( $meta['reason'] ) ) ? self::sanitize_reason( $meta['reason'] ) : '';
		$today      = self::sanitize_date( $today );

		$days_left = null;
		if ( '' !== $expires && '' !== $today ) {
			$days_left = self::days_between( $today, $expires );
		}

		if ( $revoked ) {
			$status = self::REVOKED;
		} elseif ( null !== $days_left && $days_left < 0 ) {
			$status = self::EXPIRED;
		} else {
			$status = self::VALID;
		}

		return array(
			'status'     => $status,
			'is_valid'   => self::VALID === $status,
			'expires'    => $expires,
			'revoked_at' => $revoked ? $revoked_at : '',
			'reason'     => $reason,
			'days_left'  => $days_left,
		);
	}

	/**
	 * Whether the PDF of an entry with this status may be shown / streamed.
	 *
	 * Revoked documents are never served. Expired documents are served unless
	 * the site chose to hide them.
	 *
	 * @param string $status            One of the status constants.
	 * @param bool   $hide_expired_pdf  Setting: hide PDFs of expired entries.
	 * @return bool
	 */
	public static function pdf_allowed( $status, $hide_expired_pdf = false ) {
		if ( self::REVOKED === $status ) {
			return false;
		}
		if ( self::EXPIRED === $status && $hide_expired_pdf ) {
			return false;
		}
		return true;
	}

	/**
	 * HTTP status code for the raw PDF endpoint when the PDF is withheld.
	 *
	 * 410 Gone tells caches and crawlers the document was withdrawn on purpose.
	 *
	 * @param string $status One of the status constants.
	 * @return int
	 */
	public static function withheld_http_code( $status ) {
		return ( self::VALID === $status ) ? 404 : 410;
	}

	/**
	 * Whole days from $from to $to (both Y-m-d). Negative when $to is earlier.
	 *
	 * @param string $from Start date.
	 * @param string $to   End date.
	 * @return int
	 */
	public static function days_between( $from, $to ) {
		$a = gmmktime( 0, 0, 0, (int) substr( $from, 5, 2 ), (int) substr( $from, 8, 2 ), (int) substr( $from, 0, 4 ) );
		$b = gmmktime( 0, 0, 0, (int) substr( $to, 5, 2 ), (int) substr( $to, 8, 2 ), (int) substr( $to, 0, 4 ) );
		return (int) round( ( $b - $a ) / 86400 );
	}
}

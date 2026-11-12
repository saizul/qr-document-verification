<?php
/**
 * Shared helper functions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize a URL slug. Allows a nested path such as "en/verify".
 */
function qdv_sanitize_slug( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$value = preg_replace( '#[^a-z0-9/_-]#', '', $value );
	$value = trim( $value, '/' );
	return '' === $value ? QDV_DEFAULT_SLUG : $value;
}

/**
 * The configured URL slug (default "verify"), e.g. /{slug}/{token}/.
 */
function qdv_get_slug() {
	return qdv_sanitize_slug( get_option( 'qdv_slug', QDV_DEFAULT_SLUG ) );
}

/**
 * Absolute path to the protected folder where verification PDFs live.
 * It sits inside uploads but is blocked from direct web access by .htaccess.
 */
function qdv_protected_dir() {
	$up = wp_upload_dir();
	return trailingslashit( $up['basedir'] ) . 'qdv-pdf';
}

/**
 * Build the public verification URL for a token.
 *
 * @param string $token The entry token.
 * @param bool   $pdf   When true, return the raw-PDF stream URL instead of the page.
 */
function qdv_verify_url( $token, $pdf = false ) {
	$token = sanitize_key( $token );

	if ( get_option( 'permalink_structure' ) ) {
		$path = qdv_get_slug() . '/' . $token . '/';
		if ( $pdf ) {
			$path .= 'pdf/';
		}
		return home_url( '/' . $path );
	}

	// Plain permalinks fallback.
	$args = array( 'qdv_token' => $token );
	if ( $pdf ) {
		$args['qdv_pdf'] = 1;
	}
	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Find a published entry ID by its token. Returns 0 if none.
 */
function qdv_find_post_by_token( $token ) {
	$token = sanitize_key( $token );
	if ( '' === $token ) {
		return 0;
	}

	$q = new WP_Query( array(
		'post_type'      => QDV_POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'no_found_rows'  => true,
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'   => '_qdv_token',
				'value' => $token,
			),
		),
	) );

	return $q->have_posts() ? (int) $q->posts[0] : 0;
}

/**
 * Find a published entry ID by ID number. Returns 0 if none.
 */
function qdv_find_post_by_idnumber( $id_number ) {
	$id_number = sanitize_text_field( $id_number );
	if ( '' === $id_number ) {
		return 0;
	}

	$q = new WP_Query( array(
		'post_type'      => QDV_POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'no_found_rows'  => true,
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'   => '_qdv_id_number',
				'value' => $id_number,
			),
		),
	) );

	return $q->have_posts() ? (int) $q->posts[0] : 0;
}

/**
 * Validity status of an entry (valid / expired / revoked).
 *
 * @param int $post_id Entry ID.
 * @return array See QDV_Status::evaluate().
 */
function qdv_entry_status( $post_id ) {
	return QDV_Status::evaluate(
		array(
			'expires'    => get_post_meta( $post_id, QDV_Status::META_EXPIRES, true ),
			'revoked'    => get_post_meta( $post_id, QDV_Status::META_REVOKED, true ),
			'revoked_at' => get_post_meta( $post_id, QDV_Status::META_REVOKED_AT, true ),
			'reason'     => get_post_meta( $post_id, QDV_Status::META_REVOKE_REASON, true ),
		),
		qdv_today()
	);
}

/**
 * Today's date (Y-m-d) in the site timezone.
 */
function qdv_today() {
	return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : current_time( 'Y-m-d' );
}

/**
 * Format a stored Y-m-d date with the site's date format. '' stays ''.
 *
 * @param string $ymd Date in Y-m-d form.
 */
function qdv_format_date( $ymd ) {
	$ymd = QDV_Status::sanitize_date( $ymd );
	if ( '' === $ymd ) {
		return '';
	}
	// Noon UTC keeps the calendar day stable whatever the site timezone is.
	$ts = strtotime( $ymd . ' 12:00:00 UTC' );
	return function_exists( 'wp_date' ) ? wp_date( get_option( 'date_format' ), $ts, new DateTimeZone( 'UTC' ) ) : date_i18n( get_option( 'date_format' ), $ts );
}

/**
 * Revoke an entry, or reinstate it when $revoked is false.
 *
 * @param int    $post_id Entry ID.
 * @param bool   $revoked Revoke (true) or reinstate (false).
 * @param string $reason  Optional public reason, only used when revoking.
 */
function qdv_set_revoked( $post_id, $revoked, $reason = null ) {
	if ( $revoked ) {
		if ( ! get_post_meta( $post_id, QDV_Status::META_REVOKED, true ) ) {
			update_post_meta( $post_id, QDV_Status::META_REVOKED, 1 );
			update_post_meta( $post_id, QDV_Status::META_REVOKED_AT, qdv_today() );
		}
		if ( null !== $reason ) {
			update_post_meta( $post_id, QDV_Status::META_REVOKE_REASON, QDV_Status::sanitize_reason( sanitize_text_field( $reason ) ) );
		}
		return;
	}
	delete_post_meta( $post_id, QDV_Status::META_REVOKED );
	delete_post_meta( $post_id, QDV_Status::META_REVOKED_AT );
	delete_post_meta( $post_id, QDV_Status::META_REVOKE_REASON );
}

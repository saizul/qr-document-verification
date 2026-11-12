<?php
/**
 * Dependency-free tests for QDV_Status. Run: php tests/status-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../qr-document-verification/includes/class-status.php';

$failures = 0;
$total    = 0;

function check( $label, $actual, $expected ) {
	global $failures, $total;
	$total++;
	if ( $actual !== $expected ) {
		$failures++;
		echo "FAIL: $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
	}
}

// Date sanitizing.
check( 'iso date', QDV_Status::sanitize_date( '2026-03-09' ), '2026-03-09' );
check( 'padded', QDV_Status::sanitize_date( '2026-3-9' ), '2026-03-09' );
check( 'slashes', QDV_Status::sanitize_date( '2026/12/31' ), '2026-12-31' );
check( 'dotted d.m.Y', QDV_Status::sanitize_date( '31.12.2026' ), '2026-12-31' );
check( 'whitespace', QDV_Status::sanitize_date( '  2026-01-02 ' ), '2026-01-02' );
check( 'empty', QDV_Status::sanitize_date( '' ), '' );
check( 'null', QDV_Status::sanitize_date( null ), '' );
check( 'impossible day', QDV_Status::sanitize_date( '2026-02-30' ), '' );
check( 'leap day ok', QDV_Status::sanitize_date( '2028-02-29' ), '2028-02-29' );
check( 'leap day bad', QDV_Status::sanitize_date( '2027-02-29' ), '' );
check( 'month 13', QDV_Status::sanitize_date( '2026-13-01' ), '' );
check( 'garbage', QDV_Status::sanitize_date( 'tomorrow' ), '' );
check( 'trailing junk', QDV_Status::sanitize_date( '2026-01-01; DROP' ), '' );
check( 'year too small', QDV_Status::sanitize_date( '0999-01-01' ), '' );

// Reason sanitizing.
check( 'reason tags', QDV_Status::sanitize_reason( '<b>Issued</b> in error' ), 'Issued in error' );
check( 'reason newlines', QDV_Status::sanitize_reason( "Line one\r\nline\ttwo  " ), 'Line one line two' );
check( 'reason cap', strlen( QDV_Status::sanitize_reason( str_repeat( 'a', 500 ) ) ), QDV_Status::MAX_REASON_LENGTH );
check( 'reason unicode cap', mb_strlen( QDV_Status::sanitize_reason( str_repeat( 'আ', 400 ) ), 'UTF-8' ), QDV_Status::MAX_REASON_LENGTH );

// Day arithmetic, across month / leap / DST-irrelevant boundaries.
check( 'days same', QDV_Status::days_between( '2026-05-01', '2026-05-01' ), 0 );
check( 'days forward', QDV_Status::days_between( '2026-02-27', '2026-03-01' ), 2 );
check( 'days leap', QDV_Status::days_between( '2028-02-28', '2028-03-01' ), 2 );
check( 'days backward', QDV_Status::days_between( '2026-01-10', '2025-12-31' ), -10 );
check( 'days across dst', QDV_Status::days_between( '2026-03-28', '2026-03-30' ), 2 );

// No expiry, not revoked.
$s = QDV_Status::evaluate( array(), '2026-06-15' );
check( 'plain valid', $s['status'], QDV_Status::VALID );
check( 'plain is_valid', $s['is_valid'], true );
check( 'plain no days', $s['days_left'], null );

// Expiry date is inclusive.
$meta = array( 'expires' => '2026-06-15' );
check( 'before expiry', QDV_Status::evaluate( $meta, '2026-06-14' )['status'], QDV_Status::VALID );
check( 'on expiry day', QDV_Status::evaluate( $meta, '2026-06-15' )['status'], QDV_Status::VALID );
check( 'on expiry days_left', QDV_Status::evaluate( $meta, '2026-06-15' )['days_left'], 0 );
check( 'day after expiry', QDV_Status::evaluate( $meta, '2026-06-16' )['status'], QDV_Status::EXPIRED );
check( 'expired days_left', QDV_Status::evaluate( $meta, '2026-06-20' )['days_left'], -5 );
check( 'expired not valid', QDV_Status::evaluate( $meta, '2026-06-16' )['is_valid'], false );
check( 'year boundary', QDV_Status::evaluate( array( 'expires' => '2026-12-31' ), '2027-01-01' )['status'], QDV_Status::EXPIRED );

// Bad stored expiry is ignored rather than expiring the document.
check( 'bad expiry ignored', QDV_Status::evaluate( array( 'expires' => 'soon' ), '2030-01-01' )['status'], QDV_Status::VALID );
check( 'bad expiry blank', QDV_Status::evaluate( array( 'expires' => 'soon' ), '2030-01-01' )['expires'], '' );
// Unknown "today" never marks a document expired.
check( 'bad today', QDV_Status::evaluate( $meta, '' )['status'], QDV_Status::VALID );

// Revocation wins over expiry and keeps date + reason.
$r = QDV_Status::evaluate(
	array(
		'expires'    => '2030-01-01',
		'revoked'    => '1',
		'revoked_at' => '2026-04-02',
		'reason'     => 'Issued in error',
	),
	'2026-05-01'
);
check( 'revoked', $r['status'], QDV_Status::REVOKED );
check( 'revoked date', $r['revoked_at'], '2026-04-02' );
check( 'revoked reason', $r['reason'], 'Issued in error' );
check( 'revoked keeps expiry', $r['expires'], '2030-01-01' );
check( 'revoked and expired', QDV_Status::evaluate( array( 'expires' => '2020-01-01', 'revoked' => 1 ), '2026-01-01' )['status'], QDV_Status::REVOKED );
check( 'revoked meta empty string', QDV_Status::evaluate( array( 'revoked' => '' ), '2026-01-01' )['status'], QDV_Status::VALID );

// A leftover reason / date is not exposed for a reinstated entry.
$n = QDV_Status::evaluate( array( 'revoked' => '', 'revoked_at' => '2026-01-01', 'reason' => 'old' ), '2026-02-01' );
check( 'stale reason hidden', $n['reason'], '' );
check( 'stale date hidden', $n['revoked_at'], '' );

// PDF access.
check( 'pdf valid', QDV_Status::pdf_allowed( QDV_Status::VALID ), true );
check( 'pdf valid hide setting', QDV_Status::pdf_allowed( QDV_Status::VALID, true ), true );
check( 'pdf expired default', QDV_Status::pdf_allowed( QDV_Status::EXPIRED ), true );
check( 'pdf expired hidden', QDV_Status::pdf_allowed( QDV_Status::EXPIRED, true ), false );
check( 'pdf revoked', QDV_Status::pdf_allowed( QDV_Status::REVOKED ), false );
check( 'http revoked', QDV_Status::withheld_http_code( QDV_Status::REVOKED ), 410 );
check( 'http expired', QDV_Status::withheld_http_code( QDV_Status::EXPIRED ), 410 );

echo "$total checks, $failures failed\n";
exit( $failures ? 1 : 0 );

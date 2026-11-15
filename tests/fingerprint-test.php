<?php
/**
 * Dependency-free tests for QDV_Fingerprint. Run: php tests/fingerprint-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../qr-document-verification/includes/class-fingerprint.php';

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

// SHA-256 of the empty string and of "abc" (FIPS 180-2 test vectors).
$empty = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
$abc   = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

// Normalizing.
check( 'lower hex kept', QDV_Fingerprint::normalize( $abc ), $abc );
check( 'upper case', QDV_Fingerprint::normalize( strtoupper( $abc ) ), $abc );
check( 'grouped spaces', QDV_Fingerprint::normalize( implode( ' ', str_split( $abc, 4 ) ) ), $abc );
check( 'colons', QDV_Fingerprint::normalize( implode( ':', str_split( $abc, 2 ) ) ), $abc );
check( 'dashes + newline', QDV_Fingerprint::normalize( substr( $abc, 0, 32 ) . "-\n" . substr( $abc, 32 ) ), $abc );
check( 'sha256: prefix', QDV_Fingerprint::normalize( 'sha256:' . $abc ), $abc );
check( 'SHA-256: prefix', QDV_Fingerprint::normalize( 'SHA-256:' . $abc ), $abc );
check( 'surrounding space', QDV_Fingerprint::normalize( "  $abc\t" ), $abc );
check( 'too short', QDV_Fingerprint::normalize( substr( $abc, 0, 63 ) ), '' );
check( 'too long', QDV_Fingerprint::normalize( $abc . '0' ), '' );
check( 'non hex', QDV_Fingerprint::normalize( str_repeat( 'g', 64 ) ), '' );
check( 'empty', QDV_Fingerprint::normalize( '' ), '' );
check( 'null', QDV_Fingerprint::normalize( null ), '' );
check( 'sha1 length rejected', QDV_Fingerprint::normalize( sha1( 'abc' ) ), '' );
check( 'md5sum style line rejected', QDV_Fingerprint::normalize( $abc . '  file.pdf' ), '' );

// Formatting.
check( 'format groups', QDV_Fingerprint::format( $abc ), implode( ' ', str_split( $abc, 4 ) ) );
check( 'format group count', count( explode( ' ', QDV_Fingerprint::format( $abc ) ) ), 16 );
check( 'format invalid', QDV_Fingerprint::format( 'nope' ), '' );
check( 'short', QDV_Fingerprint::short( $abc ), "ba7816bf\xE2\x80\xA6f20015ad" );
check( 'short invalid', QDV_Fingerprint::short( '' ), '' );

// Matching.
check( 'match same', QDV_Fingerprint::matches( $abc, $abc ), true );
check( 'match notation', QDV_Fingerprint::matches( $abc, 'SHA256:' . strtoupper( QDV_Fingerprint::format( $abc ) ) ), true );
check( 'no match', QDV_Fingerprint::matches( $abc, $empty ), false );
check( 'one digit off', QDV_Fingerprint::matches( $abc, substr( $abc, 0, 63 ) . 'c' ), false );
check( 'invalid expected', QDV_Fingerprint::matches( '', '' ), false );
check( 'invalid actual', QDV_Fingerprint::matches( $abc, 'x' ), false );

// PDF header detection.
check( 'header 1.7', QDV_Fingerprint::has_pdf_header( "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n" ), true );
check( 'header 2.0', QDV_Fingerprint::has_pdf_header( '%PDF-2.0' ), true );
check( 'header after junk', QDV_Fingerprint::has_pdf_header( str_repeat( ' ', 100 ) . '%PDF-1.4' ), true );
check( 'header after BOM', QDV_Fingerprint::has_pdf_header( "\xEF\xBB\xBF%PDF-1.5" ), true );
check( 'header too late', QDV_Fingerprint::has_pdf_header( str_repeat( 'x', 1024 ) . '%PDF-1.4' ), false );
check( 'html disguised', QDV_Fingerprint::has_pdf_header( '<html><script>alert(1)</script>' ), false );
check( 'png', QDV_Fingerprint::has_pdf_header( "\x89PNG\r\n\x1a\n" ), false );
check( 'bad version', QDV_Fingerprint::has_pdf_header( '%PDF-9.9' ), false );
check( 'empty bytes', QDV_Fingerprint::has_pdf_header( '' ), false );

// Files on disk.
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qdv-fp-' . getmypid();
mkdir( $tmp );
$pdf  = $tmp . DIRECTORY_SEPARATOR . 'a.pdf';
$fake = $tmp . DIRECTORY_SEPARATOR . 'fake.pdf';
$none = $tmp . DIRECTORY_SEPARATOR . 'empty.pdf';
file_put_contents( $pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" );
file_put_contents( $fake, "<?php echo 'not a pdf';" );
file_put_contents( $none, '' );

check( 'compute matches hash()', QDV_Fingerprint::compute( $pdf ), hash( 'sha256', file_get_contents( $pdf ) ) );
check( 'compute empty file', QDV_Fingerprint::compute( $none ), $empty );
check( 'compute missing', QDV_Fingerprint::compute( $tmp . '/missing.pdf' ), '' );
check( 'compute directory', QDV_Fingerprint::compute( $tmp ), '' );
check( 'compute empty path', QDV_Fingerprint::compute( '' ), '' );
check( 'is_pdf real', QDV_Fingerprint::is_pdf_file( $pdf ), true );
check( 'is_pdf fake', QDV_Fingerprint::is_pdf_file( $fake ), false );
check( 'is_pdf empty', QDV_Fingerprint::is_pdf_file( $none ), false );
check( 'is_pdf missing', QDV_Fingerprint::is_pdf_file( $tmp . '/missing.pdf' ), false );
check( 'is_pdf null', QDV_Fingerprint::is_pdf_file( null ), false );

// A one-byte change produces a different fingerprint.
$before = QDV_Fingerprint::compute( $pdf );
file_put_contents( $pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\r" );
check( 'tamper changes hash', QDV_Fingerprint::matches( $before, QDV_Fingerprint::compute( $pdf ) ), false );

foreach ( array( $pdf, $fake, $none ) as $f ) {
	unlink( $f );
}
rmdir( $tmp );

// Cache invalidation.
$cached = array( 'sha256' => $abc, 'size' => 120, 'mtime' => 1700000000 );
check( 'cache fresh', QDV_Fingerprint::needs_refresh( $cached, 120, 1700000000 ), false );
check( 'cache fresh string meta', QDV_Fingerprint::needs_refresh( array( 'sha256' => $abc, 'size' => '120', 'mtime' => '1700000000' ), 120, 1700000000 ), false );
check( 'cache size changed', QDV_Fingerprint::needs_refresh( $cached, 121, 1700000000 ), true );
check( 'cache mtime changed', QDV_Fingerprint::needs_refresh( $cached, 120, 1700000001 ), true );
check( 'cache no hash', QDV_Fingerprint::needs_refresh( array( 'size' => 120, 'mtime' => 1700000000 ), 120, 1700000000 ), true );
check( 'cache bad hash', QDV_Fingerprint::needs_refresh( array( 'sha256' => 'zz', 'size' => 120, 'mtime' => 1700000000 ), 120, 1700000000 ), true );
check( 'cache empty meta', QDV_Fingerprint::needs_refresh( array( 'sha256' => '', 'size' => '', 'mtime' => '' ), 0, 0 ), true );
check( 'cache missing size', QDV_Fingerprint::needs_refresh( array( 'sha256' => $abc ), 0, 0 ), true );

echo "$total checks, $failures failed\n";
exit( $failures ? 1 : 0 );

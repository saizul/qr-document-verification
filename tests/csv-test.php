<?php
/**
 * Dependency-free tests for QDV_CSV. Run: php tests/csv-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../qr-document-verification/includes/class-csv.php';

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

function pairs( $parsed ) {
	return array_map( function ( $r ) {
		return array( $r['line'], $r['name'], $r['id'] );
	}, $parsed['rows'] );
}

// Basic rows, header skipped, row numbers follow the file.
$p = QDV_CSV::parse( "Name,ID\nJane Doe,12345\nJohn Smith,12346\n" );
check( 'header detected', $p['had_header'], true );
check( 'basic rows', pairs( $p ), array( array( 2, 'Jane Doe', '12345' ), array( 3, 'John Smith', '12346' ) ) );

// No header, CRLF, blank lines, BOM.
$p = QDV_CSV::parse( "\xEF\xBB\xBFJane,1\r\n\r\nJohn,2\r\n" );
check( 'bom and blank lines', pairs( $p ), array( array( 1, 'Jane', '1' ), array( 3, 'John', '2' ) ) );
check( 'no header', $p['had_header'], false );

// Delimiters and quoting.
check( 'semicolon', pairs( QDV_CSV::parse( "A;1\nB;2" ) ), array( array( 1, 'A', '1' ), array( 2, 'B', '2' ) ) );
check( 'tab', pairs( QDV_CSV::parse( "A\t1\nB\t2" ) ), array( array( 1, 'A', '1' ), array( 2, 'B', '2' ) ) );
check( 'quoted comma', pairs( QDV_CSV::parse( "\"Doe, Jane\",9\n" ) ), array( array( 1, 'Doe, Jane', '9' ) ) );
check( 'escaped quote', pairs( QDV_CSV::parse( "\"Jane \"\"JD\"\" Doe\",9\n" ) ), array( array( 1, 'Jane "JD" Doe', '9' ) ) );
check( 'unicode', pairs( QDV_CSV::parse( "সাইজুল আমিন,১২৩\n" ) ), array( array( 1, 'সাইজুল আমিন', '১২৩' ) ) );

// Whitespace trimmed, delimiter-only lines ignored, custom sanitizer applied.
check( 'trim', pairs( QDV_CSV::parse( "  Jane  ,  7  \n,,\n" ) ), array( array( 1, 'Jane', '7' ) ) );
check( 'sanitizer', pairs( QDV_CSV::parse( "<b>Jane</b>,7", 'strip_tags' ) ), array( array( 1, 'Jane', '7' ) ) );

// Header only / empty.
check( 'header only', QDV_CSV::parse( "name,id\n" )['rows'], array() );
check( 'empty', QDV_CSV::parse( '' )['rows'], array() );

// Row cap.
$big = '';
for ( $i = 1; $i <= QDV_CSV::MAX_ROWS + 5; $i++ ) {
	$big .= "N$i,$i\n";
}
$p = QDV_CSV::parse( $big );
check( 'row cap', count( $p['rows'] ), QDV_CSV::MAX_ROWS );
check( 'truncated flag', $p['truncated'], true );

// Validation.
$rows = QDV_CSV::parse( "Name,ID\nJane,1\n,2\nBob,\nAnn,1\nCy,ONE\nDee,one\nEd,9\n" )['rows'];
$v    = QDV_CSV::validate( $rows, function ( $id ) {
	return '9' === $id;
} );
$by   = array();
foreach ( $v['rows'] as $r ) {
	$by[ $r['line'] ] = $r;
}
check( 'valid row', $by[2]['valid'], true );
check( 'empty name', $by[3]['errors'], array( 'Name is empty.' ) );
check( 'empty id', $by[4]['errors'], array( 'ID number is empty.' ) );
check( 'in-file duplicate', $by[5]['errors'], array( 'Duplicate of ID number on row 2.' ) );
check( 'case-insensitive duplicate', $by[7]['errors'], array( 'Duplicate of ID number on row 6.' ) );
check( 'existing entry', $by[8]['errors'], array( 'An entry with this ID number already exists.' ) );
check( 'counts', array( $v['valid'], $v['invalid'] ), array( 2, 5 ) );

// Length limits.
$long = QDV_CSV::validate( array(
	array( 'line' => 1, 'name' => str_repeat( 'a', 201 ), 'id' => str_repeat( '1', 101 ) ),
) );
check( 'length errors', count( $long['rows'][0]['errors'] ), 2 );
$ctl = QDV_CSV::validate( array( array( 'line' => 1, 'name' => 'x', 'id' => "1\x07" ) ) );
check( 'control chars', $ctl['rows'][0]['errors'], array( 'ID number contains control characters.' ) );

echo "$total checks, $failures failed\n";
exit( $failures ? 1 : 0 );

<?php
/**
 * CSV parsing and row validation for the bulk importer.
 *
 * Deliberately free of WordPress calls so it can be unit tested with plain PHP.
 * Anything WordPress specific (sanitizing, "does this ID already exist") is
 * injected as a callable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QDV_CSV {

	const MAX_ROWS        = 1000;
	const MAX_NAME_LENGTH = 200;
	const MAX_ID_LENGTH   = 100;

	/**
	 * Parse CSV text into Name / ID rows.
	 *
	 * Handles a UTF-8 BOM, comma / semicolon / tab delimiters, quoted fields,
	 * blank lines and an optional "name,id" header row. Row numbers refer to the
	 * record position in the file (the header counts as row 1), so they match
	 * what a spreadsheet shows.
	 *
	 * @param string        $text     Raw file contents.
	 * @param callable|null $sanitize Applied to every cell. Defaults to trim().
	 * @return array {
	 *     @type array[] $rows       Each: line (int), name (string), id (string).
	 *     @type bool    $had_header Whether a header row was skipped.
	 *     @type bool    $truncated  True when more than MAX_ROWS rows were present.
	 * }
	 */
	public static function parse( $text, $sanitize = null ) {
		if ( null === $sanitize ) {
			$sanitize = 'trim';
		}
		$text = (string) $text;
		if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}
		// Normalize line endings so fgetcsv sees consistent records.
		$text      = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$delimiter = self::detect_delimiter( $text );

		$rows       = array();
		$had_header = false;
		$truncated  = false;
		$record     = 0;
		$first_seen = false;

		$handle = fopen( 'php://memory', 'r+' );
		fwrite( $handle, $text );
		rewind( $handle );

		// Escape char is empty: RFC 4180 style, a backslash is just data.
		while ( false !== ( $cols = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) {
			$record++;
			if ( array( null ) === $cols ) {
				continue; // Blank line.
			}
			$name = isset( $cols[0] ) ? call_user_func( $sanitize, (string) $cols[0] ) : '';
			$idn  = isset( $cols[1] ) ? call_user_func( $sanitize, (string) $cols[1] ) : '';

			if ( ! $first_seen ) {
				$first_seen = true;
				if ( self::is_header( $name, $idn ) ) {
					$had_header = true;
					continue;
				}
			}
			if ( '' === $name && '' === $idn && 0 === count( array_filter( $cols, 'strlen' ) ) ) {
				continue; // Only delimiters, e.g. ",,".
			}
			if ( count( $rows ) >= self::MAX_ROWS ) {
				$truncated = true;
				break;
			}
			$rows[] = array(
				'line' => $record,
				'name' => $name,
				'id'   => $idn,
			);
		}
		fclose( $handle );

		return array(
			'rows'       => $rows,
			'had_header' => $had_header,
			'truncated'  => $truncated,
		);
	}

	/**
	 * Pick the delimiter used on the first non-empty line.
	 */
	public static function detect_delimiter( $text ) {
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$best  = ',';
			$count = 0;
			foreach ( array( ',', ';', "\t" ) as $d ) {
				$n = substr_count( $line, $d );
				if ( $n > $count ) {
					$best  = $d;
					$count = $n;
				}
			}
			return $best;
		}
		return ',';
	}

	/**
	 * Whether a pair of cells looks like the header row ("name,id" and friends).
	 */
	public static function is_header( $name, $idn ) {
		$names = array( 'name', 'full name', 'fullname' );
		$ids   = array( 'id', 'id number', 'idnumber', 'id_number', 'permit number', 'number' );
		return in_array( strtolower( trim( $name ) ), $names, true ) && in_array( strtolower( trim( $idn ) ), $ids, true );
	}

	/**
	 * Validate parsed rows.
	 *
	 * @param array[]       $rows   Output of parse()['rows'].
	 * @param callable|null $exists Receives an ID number, returns truthy when an entry already has it.
	 * @return array {
	 *     @type array[] $rows    Each parse() row plus `errors` (string[]) and `valid` (bool).
	 *     @type int     $valid   Number of valid rows.
	 *     @type int     $invalid Number of rows with at least one error.
	 * }
	 */
	public static function validate( array $rows, $exists = null ) {
		$seen    = array();
		$out     = array();
		$valid   = 0;
		$invalid = 0;

		foreach ( $rows as $row ) {
			$errors = array();
			$name   = $row['name'];
			$idn    = $row['id'];

			if ( '' === $name ) {
				$errors[] = 'Name is empty.';
			} elseif ( self::length( $name ) > self::MAX_NAME_LENGTH ) {
				$errors[] = 'Name is longer than ' . self::MAX_NAME_LENGTH . ' characters.';
			}

			if ( '' === $idn ) {
				$errors[] = 'ID number is empty.';
			} elseif ( self::length( $idn ) > self::MAX_ID_LENGTH ) {
				$errors[] = 'ID number is longer than ' . self::MAX_ID_LENGTH . ' characters.';
			} elseif ( preg_match( '/[\x00-\x1F\x7F]/', $idn ) ) {
				$errors[] = 'ID number contains control characters.';
			} else {
				$key = strtolower( $idn );
				if ( isset( $seen[ $key ] ) ) {
					$errors[] = 'Duplicate of ID number on row ' . $seen[ $key ] . '.';
				} elseif ( $exists && call_user_func( $exists, $idn ) ) {
					$errors[] = 'An entry with this ID number already exists.';
				}
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = $row['line'];
				}
			}

			$row['errors'] = $errors;
			$row['valid']  = empty( $errors );
			$row['valid'] ? $valid++ : $invalid++;
			$out[] = $row;
		}

		return array(
			'rows'    => $out,
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}

	private static function length( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}
}

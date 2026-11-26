<?php
/**
 * gettext chain consistency for Inquiry Form Inbox.
 *
 * Checks the three-link chain POT -> PO -> MO for every bundled locale:
 *
 *   1. every msgid used in source appears in the POT          (source -> POT)
 *   2. every POT msgid appears in each PO                     (POT -> PO)
 *   3. every PO msgid appears in the matching MO              (PO -> MO)
 *   4. no MO entry carries an empty translation               (the 2.0.4 regression)
 *   5. no PO entry carries an empty translation, header aside
 *
 * Link 4 is the important one: an MO entry with an empty msgstr makes gettext return an
 * empty string instead of falling back to the msgid, which blanked the plugin row and
 * broke activation in 2.0.4.
 *
 * The MO reader is hand-rolled because the gettext extension cannot read arbitrary MO
 * files and this host has neither `zip` nor `intl`.
 *
 * Usage:
 *   php tests/static/i18n-parity.php [--json=<path>] [--pot=<path>]
 */

declare( strict_types = 1 );

$root     = dirname( __DIR__, 2 );
$json_out = '';
$pot_path = $root . '/languages/inquiry-form-inbox.pot';

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--json=' ) ) {
		$json_out = substr( $arg, 7 );
	} elseif ( 0 === strpos( $arg, '--pot=' ) ) {
		$pot_path = substr( $arg, 6 );
	} else {
		fwrite( STDERR, "Unknown argument: {$arg}\n" );
		exit( 2 );
	}
}

/**
 * Read a single quoted string that may continue on following lines.
 *
 * @param string[] $lines Line buffer.
 * @param int      $index Current line index, advanced past continuations.
 * @return string
 */
function ifi_i18n_read_quoted( array $lines, int &$index ): string {
	$line = $lines[ $index ];
	$pos  = strpos( $line, '"' );
	$text = '';
	if ( false === $pos ) {
		return '';
	}
	$text = ifi_i18n_decode( substr( $line, $pos ) );

	while ( isset( $lines[ $index + 1 ] ) && preg_match( '/^\s*"/', $lines[ $index + 1 ] ) ) {
		$index++;
		$text .= ifi_i18n_decode( trim( $lines[ $index ] ) );
	}

	return $text;
}

/**
 * Decode a PO/MO-style quoted literal (leading and trailing quotes expected).
 *
 * Both quote styles are handled: the gettext calls in this plugin use single quotes, and
 * a single-quoted PHP literal only recognises \' and \\ as escapes.
 *
 * @param string $quoted Quoted literal.
 * @return string
 */
function ifi_i18n_decode( string $quoted ): string {
	$quoted = trim( $quoted );
	if ( '' === $quoted ) {
		return '';
	}

	$quote = $quoted[0];
	if ( '"' !== $quote && "'" !== $quote ) {
		return $quoted;
	}
	if ( '"' === substr( $quoted, -1 ) || "'" === substr( $quoted, -1 ) ) {
		$quoted = substr( $quoted, 0, -1 );
	}
	$quoted = substr( $quoted, 1 );

	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $quoted );
	}

	return str_replace(
		array( '\\n', '\\t', '\\r', '\\"', '\\\\' ),
		array( "\n", "\t", "\r", '"', '\\' ),
		$quoted
	);
}

/**
 * Parse a .po / .pot file into msgid => msgstr.
 *
 * Obsolete (#~) and previous-msgid blocks are skipped. Plural forms are not used by this
 * plugin, but the reader tolerates them by folding into the same map keyed on msgid.
 *
 * @param string $path File path.
 * @return array<string, string>
 */
function ifi_i18n_parse_po( string $path ): array {
	$raw   = (string) file_get_contents( $path );
	$lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: array();
	$map   = array();
	$total = count( $lines );
	$id    = null;
	$str   = null;

	$flush = static function () use ( &$map, &$id, &$str ) {
		if ( null !== $id && null !== $str ) {
			$map[ $id ] = $str;
		}
		$id  = null;
		$str = null;
	};

	for ( $i = 0; $i < $total; $i++ ) {
		$line = $lines[ $i ];

		if ( '' === trim( $line ) ) {
			$flush();
			continue;
		}
		if ( '#' === $line[0] ) {
			// A comment closes the previous entry only when it is a translator comment
			// that introduces a new one; keeping it simple is safe because every entry in
			// this project starts with at least one "#:" reference line.
			if ( 0 === strpos( $line, '#:' ) || 0 === strpos( $line, '#.' ) ) {
				$flush();
			}
			continue;
		}
		if ( 0 === strpos( $line, 'msgctxt ' ) ) {
			$flush();
			$i++;
			continue;
		}
		if ( 0 === strpos( $line, 'msgid_plural ' ) ) {
			$i++;
			continue;
		}
		if ( 0 === strpos( $line, 'msgid ' ) ) {
			$id  = ifi_i18n_read_quoted( $lines, $i );
			$str = null;
			continue;
		}
		if ( 0 === strpos( $line, 'msgstr ' ) ) {
			$str = ifi_i18n_read_quoted( $lines, $i );
			continue;
		}
		if ( preg_match( '/^msgstr\[(\d+)\]\s/', $line, $m ) ) {
			// Plural translations: only index 0 is meaningful for parity here.
			$value = ifi_i18n_read_quoted( $lines, $i );
			if ( '0' === $m[1] ) {
				$str = $value;
			}
			continue;
		}
		if ( 0 === strpos( $line, '"' ) && null !== $str ) {
			$str .= ifi_i18n_decode( trim( $line ) );
			continue;
		}
	}
	$flush();

	unset( $map[''] ); // The header entry has no business in a parity check.
	return $map;
}

/**
 * Parse a binary .mo file into msgid => msgstr.
 *
 * @param string $path File path.
 * @return array<string, string>
 * @throws RuntimeException When the magic number or a table offset is invalid.
 */
function ifi_i18n_parse_mo( string $path ): array {
	$data = (string) file_get_contents( $path );
	if ( strlen( $data ) < 28 ) {
		throw new RuntimeException( 'File is too short to be an MO file.' );
	}

	$magic = unpack( 'V', substr( $data, 0, 4 ) )[1];
	if ( 0x950412de === $magic ) {
		$fmt = 'V';
	} elseif ( 0xde120495 === $magic ) {
		$fmt = 'N';
	} else {
		throw new RuntimeException( sprintf( 'Bad magic number 0x%08x.', $magic ) );
	}

	$read = static function ( int $offset ) use ( $data, $fmt ): int {
		if ( $offset + 4 > strlen( $data ) ) {
			throw new RuntimeException( sprintf( 'Offset %d is past end of file.', $offset ) );
		}
		return (int) unpack( $fmt, substr( $data, $offset, 4 ) )[1];
	};

	$count   = $read( 8 );
	$orig_at = $read( 12 );
	$trans_at = $read( 16 );

	$map = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$id_len  = $read( $orig_at + $i * 8 );
		$id_off  = $read( $orig_at + $i * 8 + 4 );
		$str_len = $read( $trans_at + $i * 8 );
		$str_off = $read( $trans_at + $i * 8 + 4 );

		$id  = substr( $data, $id_off, $id_len );
		$str = substr( $data, $str_off, $str_len );
		$map[ $id ] = $str;
	}

	unset( $map[''] );
	return $map;
}

// --- Collect sources -------------------------------------------------------

$source_strings = array();
$source_files   = array_merge(
	(array) glob( $root . '/includes/*.php' ),
	(array) glob( $root . '/*.php' )
);
foreach ( $source_files as $file ) {
	if ( ! is_file( $file ) ) {
		continue;
	}
	$code = (string) file_get_contents( $file );
	// Only single-argument gettext calls with a literal string are counted; that is what
	// this plugin uses everywhere.
	if ( preg_match_all( '/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\s*\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*,\s*[\'"]inquiry-form-inbox[\'"]/', $code, $matches ) ) {
		foreach ( $matches[1] as $literal ) {
			$source_strings[] = ifi_i18n_decode( $literal );
		}
	}
}
$source_strings = array_values( array_unique( $source_strings ) );
sort( $source_strings );

$report = array(
	'pot'      => array(),
	'po'       => array(),
	'mo'       => array(),
	'problems' => array(),
);

// --- Link 1: POT exists and parses ----------------------------------------

if ( ! is_file( $pot_path ) ) {
	$report['problems'][] = 'POT file is missing: ' . $pot_path;
	$pot = array();
} else {
	$pot = ifi_i18n_parse_po( $pot_path );
	$report['pot'] = array(
		'path'   => str_replace( '\\', '/', substr( $pot_path, strlen( $root ) + 1 ) ),
		'msgids' => count( $pot ),
	);
}

// --- Links 2-5 per locale --------------------------------------------------

$po_files = (array) glob( $root . '/languages/*.po' );
sort( $po_files );

foreach ( $po_files as $po_path ) {
	$locale = preg_replace( '/^inquiry-form-inbox-(.+)\.po$/', '$1', basename( $po_path ) );
	$po     = ifi_i18n_parse_po( $po_path );

	$entry = array(
		'locale'  => $locale,
		'po'      => count( $po ),
		'missing' => array_values( array_diff( array_keys( $pot ), array_keys( $po ) ) ),
		'extra'   => array_values( array_diff( array_keys( $po ), array_keys( $pot ) ) ),
		'empty'   => array(),
		'mo'      => null,
		'mo_missing' => array(),
		'mo_empty'   => array(),
	);

	foreach ( $po as $id => $str ) {
		if ( '' === $str ) {
			$entry['empty'][] = $id;
		}
	}

	$mo_path = $root . '/languages/inquiry-form-inbox-' . $locale . '.mo';
	if ( ! is_file( $mo_path ) ) {
		$entry['mo'] = 'missing';
		$report['problems'][] = sprintf( '%s: MO file is missing.', $locale );
	} else {
		try {
			$mo = ifi_i18n_parse_mo( $mo_path );
			$entry['mo'] = count( $mo );
			// An entry whose PO translation is empty is deliberately omitted from the MO:
			// shipping it would make gettext return an empty string instead of falling back
			// to the msgid. Only non-empty translations must survive the compile step.
			$translated = array();
			foreach ( $po as $id => $str ) {
				if ( '' !== $str ) {
					$translated[] = $id;
				}
			}
			$entry['mo_missing'] = array_values( array_diff( $translated, array_keys( $mo ) ) );
			foreach ( $mo as $id => $str ) {
				if ( '' === $str ) {
					$entry['mo_empty'][] = $id;
				}
			}
		} catch ( RuntimeException $e ) {
			$entry['mo'] = 'unreadable';
			$report['problems'][] = sprintf( '%s: MO parse failed — %s', $locale, $e->getMessage() );
		}
	}

	if ( $entry['missing'] ) {
		$report['problems'][] = sprintf( '%s: %d POT msgid(s) not translated.', $locale, count( $entry['missing'] ) );
	}
	if ( $entry['extra'] ) {
		$report['problems'][] = sprintf( '%s: %d msgid(s) no longer in POT (stale).', $locale, count( $entry['extra'] ) );
	}
	if ( $entry['mo_empty'] ) {
		$report['problems'][] = sprintf( '%s: %d MO entr(ies) have an empty translation — gettext will render a blank string (the 2.0.4 regression).', $locale, count( $entry['mo_empty'] ) );
	}
	if ( $entry['mo_missing'] ) {
		$report['problems'][] = sprintf( '%s: %d PO msgid(s) missing from MO (stale MO).', $locale, count( $entry['mo_missing'] ) );
	}

	$report['po'][] = $entry;
}

$missing_from_pot = array_values( array_diff( $source_strings, array_keys( $pot ) ) );
if ( $missing_from_pot ) {
	$report['problems'][] = sprintf( '%d source string(s) are not in the POT — the POT is stale.', count( $missing_from_pot ) );
}

// --- Report ---------------------------------------------------------------

$lines   = array();
$lines[] = 'Inquiry Form Inbox — gettext parity';
$lines[] = sprintf( '  source strings : %d', count( $source_strings ) );
$lines[] = sprintf( '  POT msgids     : %d', count( $pot ) );
foreach ( $report['po'] as $entry ) {
	$lines[] = sprintf(
		'  %-6s PO %3d | MO %s | untranslated %d | stale %d | MO-empty %d',
		$entry['locale'],
		$entry['po'],
		is_int( $entry['mo'] ) ? sprintf( '%3d', $entry['mo'] ) : (string) $entry['mo'],
		count( $entry['empty'] ),
		count( $entry['extra'] ),
		is_array( $entry['mo_empty'] ) ? count( $entry['mo_empty'] ) : 0
	);
}
$lines[] = '';

if ( $missing_from_pot ) {
	$lines[] = sprintf( 'NOT IN POT (%d):', count( $missing_from_pot ) );
	foreach ( array_slice( $missing_from_pot, 0, 20 ) as $string ) {
		$lines[] = '  - ' . $string;
	}
	if ( count( $missing_from_pot ) > 20 ) {
		$lines[] = sprintf( '  … and %d more', count( $missing_from_pot ) - 20 );
	}
	$lines[] = '';
}

if ( $report['problems'] ) {
	$lines[] = sprintf( 'PROBLEMS (%d):', count( $report['problems'] ) );
	foreach ( $report['problems'] as $problem ) {
		$lines[] = '  - ' . $problem;
	}
	$lines[] = '';
}

// Warnings that do not fail the build: individual untranslated strings fall back to the
// source string, which is correct behaviour; a stale POT fails.
$fatal = $missing_from_pot || $report['problems'] && array_filter(
	$report['problems'],
	static function ( $problem ) {
		return false !== strpos( $problem, 'MO' ) || false !== strpos( $problem, 'POT file is missing' );
	}
);

$lines[] = 'Result: ' . ( $fatal ? 'FAIL' : 'OK' ) . ( $missing_from_pot ? '' : ' (warnings only)' );

echo implode( "\n", $lines ) . "\n";

if ( '' !== $json_out ) {
	$dir = dirname( $json_out );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	$report['source_strings']    = count( $source_strings );
	$report['missing_from_pot']  = $missing_from_pot;
	$report['status']            = $fatal ? 'FAIL' : 'OK';
	file_put_contents(
		$json_out,
		json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);
}

exit( $fatal ? 1 : 0 );

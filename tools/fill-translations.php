<?php
/**
 * Fill in msgstr values for a PO file without touching anything else.
 *
 * `wp i18n update-po` leaves every new string with an empty msgstr, and there is no
 * WP-CLI command to set a translation. This walks the PO line by line, keeps comments and
 * source references byte-for-byte, and rewrites only the msgstr block of the msgids listed
 * in the translation map.
 *
 * Usage:
 *   php tools/fill-translations.php languages/inquiry-form-inbox-zh_CN.po <map-file.php>
 *
 * The map file must return an array of msgid => msgstr. Entries that need a gettext
 * context (the same English word translated differently per screen, e.g. the "Trash"
 * view link vs the row action) use the key "context\x04msgid". Exit code 1 when a listed
 * msgid is not found, so a typo cannot silently produce a half-translated catalogue.
 */

declare( strict_types = 1 );

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php tools/fill-translations.php <po-file> <map-file>\n" );
	exit( 2 );
}

$po_path  = $argv[1];
$map_path = $argv[2];

if ( ! is_file( $po_path ) || ! is_file( $map_path ) ) {
	fwrite( STDERR, "Missing input file.\n" );
	exit( 2 );
}

/** @var array<string, string> $map */
$map = require $map_path;
if ( ! is_array( $map ) ) {
	fwrite( STDERR, "Map file must return an array.\n" );
	exit( 2 );
}

/**
 * Decode a PO quoted literal.
 *
 * @param string $quoted Quoted literal.
 * @return string
 */
function ifi_po_decode( string $quoted ): string {
	$quoted = trim( $quoted );
	if ( '' === $quoted || '"' !== $quoted[0] ) {
		return '';
	}
	$quoted = substr( $quoted, 0, -1 );
	$quoted = substr( $quoted, 1 );

	return str_replace(
		array( '\\n', '\\t', '\\r', '\\"', '\\\\' ),
		array( "\n", "\t", "\r", '"', '\\' ),
		$quoted
	);
}

/**
 * Encode a string as one or more PO quoted lines, wrapped at 76 characters.
 *
 * @param string $text      Text to encode.
 * @param string $prefix    "msgstr" or an empty string for continuation lines.
 * @param string $indent    Leading whitespace.
 * @return string[] Lines, without trailing newlines.
 */
function ifi_po_encode( string $text, string $prefix, string $indent = '' ): array {
	$escaped = str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $text );

	if ( strlen( $escaped ) <= 70 && false === strpos( $escaped, "\n" ) ) {
		return array( $indent . $prefix . ' "' . $escaped . '"' );
	}

	// Wrap on spaces; fall back to a hard wrap for text with no spaces.
	$chunks = array();
	$buffer = '';
	foreach ( preg_split( '/( )/', $escaped, -1, PREG_SPLIT_DELIM_CAPTURE ) as $piece ) {
		if ( strlen( $buffer . $piece ) > 70 && '' !== $buffer ) {
			// 断在空格上时把空格留在行尾。丢掉的空格在拼接后无法恢复，
			// 「IP 地址」会变成「IP地址」——中文里夹英文/数字时尤其明显。
			if ( ' ' === $piece ) {
				$chunks[] = $buffer . ' ';
				$buffer   = '';
				continue;
			}
			$chunks[] = $buffer;
			$buffer   = ltrim( $piece );
		} else {
			$buffer .= $piece;
		}
	}
	if ( '' !== $buffer ) {
		$chunks[] = $buffer;
	}

	$lines = array( $indent . $prefix . ' ""' );
	foreach ( $chunks as $chunk ) {
		$lines[] = $indent . '"' . $chunk . '"';
	}
	return $lines;
}

$raw   = (string) file_get_contents( $po_path );
$lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: array();
$total = count( $lines );

$out     = array();
$filled  = array();
$i       = 0;
$ctx     = '';

while ( $i < $total ) {
	$line = $lines[ $i ];

	// Pass comments and blank lines straight through.
	if ( '' === $line || '#' === $line[0] ) {
		$out[] = $line;
		$ctx   = '';
		$i++;
		continue;
	}

	/*
	 * gettext 上下文：同一个 msgid 在两种语境里可以有不同译法（本插件用它区分
	 * 「回收站」视图与「移至回收站」行操作）。映射表里的键写成 "context\x04msgid"。
	 */
	if ( 0 === strpos( $line, 'msgctxt ' ) ) {
		$ctx   = ifi_po_decode( substr( $line, strpos( $line, '"' ) ) );
		$out[] = $line;
		$i++;
		continue;
	}

	if ( 0 !== strpos( $line, 'msgid ' ) ) {
		// Anything else (msgctxt, msgid_plural, stray) is copied verbatim.
		$out[] = $line;
		$i++;
		continue;
	}

	// Collect the whole msgid block.
	$msgid_lines = array( $line );
	$i++;
	while ( $i < $total && '"' === ( $lines[ $i ][0] ?? '' ) ) {
		$msgid_lines[] = $lines[ $i ];
		$i++;
	}

	$msgid = '';
	foreach ( $msgid_lines as $msgid_line ) {
		$msgid .= ifi_po_decode( substr( $msgid_line, strpos( $msgid_line, '"' ) ) );
	}

	// Collect the msgstr block (and any plural variants) verbatim.
	$msgstr_lines = array();
	while ( $i < $total ) {
		$candidate = $lines[ $i ];
		if ( 0 === strpos( $candidate, 'msgstr' ) || '"' === ( $candidate[0] ?? '' ) ) {
			$msgstr_lines[] = $candidate;
			$i++;
			continue;
		}
		break;
	}

	$out = array_merge( $out, $msgid_lines );

	$key = '' !== $ctx ? $ctx . "\x04" . $msgid : $msgid;
	if ( isset( $map[ $key ] ) ) {
		$translation = (string) $map[ $key ];
		// Keep the indentation of the msgstr line we are replacing (usually none).
		$out         = array_merge( $out, ifi_po_encode( $translation, 'msgstr' ) );
		$filled[]    = $key;
	} else {
		$out = array_merge( $out, $msgstr_lines );
	}
	$ctx = '';
}

$missing = array_diff( array_keys( $map ), $filled );
if ( $missing ) {
	fwrite( STDERR, "These msgids were not found in {$po_path}:\n" );
	foreach ( $missing as $msgid ) {
		fwrite( STDERR, '  - ' . $msgid . "\n" );
	}
	exit( 1 );
}

file_put_contents( $po_path, implode( "\n", $out ) . "\n" );

printf( "%s: filled %d of %d mapped strings.\n", basename( $po_path ), count( $filled ), count( $map ) );

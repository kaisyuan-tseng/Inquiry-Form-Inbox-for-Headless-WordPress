<?php
/**
 * readme.txt compliance check for the WordPress.org plugin directory.
 *
 * Encodes the field-level rules that get a readme rejected or a plugin page broken:
 * size ceiling, tag ceiling, short-description ceiling, Stable tag vs plugin header,
 * Tested up to ceiling against the installed core, screenshot declarations without files,
 * and an oversized changelog. See docs/WPORG-AUDIT.md for the source of each rule.
 *
 * Usage:
 *   php tests/static/readme-check.php [--json=<path>] [--wp=<path to WP root>]
 *
 * Exit code: 0 pass, 1 fail.
 */

declare( strict_types = 1 );

$root     = dirname( __DIR__, 2 );
$wp_root  = dirname( $root, 3 );
$json_out = '';

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--json=' ) ) {
		$json_out = substr( $arg, 7 );
	} elseif ( 0 === strpos( $arg, '--wp=' ) ) {
		$wp_root = substr( $arg, 5 );
	} else {
		fwrite( STDERR, "Unknown argument: {$arg}\n" );
		exit( 2 );
	}
}

$readme_path = $root . '/readme.txt';
$main_path   = $root . '/inquiry-form-inbox.php';

$checks = array();

/**
 * Record one check result.
 *
 * @param string $rule    Short rule id.
 * @param string $verdict pass|fail|warn|info.
 * @param string $detail  Human-readable evidence.
 */
function ifi_readme_add( array &$checks, string $rule, string $verdict, string $detail ): void {
	$checks[] = array(
		'rule'    => $rule,
		'verdict' => $verdict,
		'detail'  => $detail,
	);
}

if ( ! is_file( $readme_path ) ) {
	fwrite( STDERR, "readme.txt not found at {$readme_path}\n" );
	exit( 1 );
}

$readme = (string) file_get_contents( $readme_path );
$main   = is_file( $main_path ) ? (string) file_get_contents( $main_path ) : '';

/**
 * Read a single-line readme header field.
 *
 * @param string $text  Readme contents.
 * @param string $field Field name, case-insensitive.
 * @return string
 */
function ifi_readme_field( string $text, string $field ): string {
	if ( preg_match( '/^' . preg_quote( $field, '/' ) . '\s*:\s*(.+)$/mi', $text, $m ) ) {
		return trim( $m[1] );
	}
	return '';
}

// --- Size -----------------------------------------------------------------

$size = strlen( $readme );
ifi_readme_add(
	$checks,
	'size <= 10k',
	$size <= 10240 ? 'pass' : 'fail',
	sprintf( '%d bytes', $size )
);

// --- Version alignment ----------------------------------------------------

$header_version = '';
if ( preg_match( '/^\s*\*?\s*Version:\s*(\S+)/mi', $main, $m ) ) {
	$header_version = trim( $m[1] );
}
$stable = ifi_readme_field( $readme, 'Stable tag' );
ifi_readme_add(
	$checks,
	'Stable tag == header Version',
	( '' !== $stable && $stable === $header_version ) ? 'pass' : 'fail',
	sprintf( 'Stable tag "%s" vs header "%s"', $stable, $header_version )
);
ifi_readme_add(
	$checks,
	'Stable tag != trunk',
	'trunk' === strtolower( $stable ) ? 'fail' : 'pass',
	$stable
);

// --- Tested up to ---------------------------------------------------------

$tested = ifi_readme_field( $readme, 'Tested up to' );
$wp_version = '';
if ( is_file( $wp_root . '/wp-includes/version.php' ) ) {
	if ( preg_match( '/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents( $wp_root . '/wp-includes/version.php' ), $m ) ) {
		$wp_version = $m[1];
	}
}
$tested_numeric = (bool) preg_match( '/^\d+(\.\d+)*$/', $tested );
$tested_major   = '';
if ( $tested_numeric ) {
	$parts        = explode( '.', $tested );
	$tested_major = $parts[0] . '.' . ( $parts[1] ?? '0' );
}
ifi_readme_add(
	$checks,
	'Tested up to is numeric',
	$tested_numeric ? 'pass' : 'fail',
	$tested
);
ifi_readme_add(
	$checks,
	'Tested up to has no minor',
	( $tested_numeric && 2 === count( explode( '.', $tested ) ) ) ? 'pass' : 'warn',
	sprintf( '"%s" — the directory ignores minor versions; write the major only', $tested )
);
if ( '' !== $wp_version && '' !== $tested_major ) {
	$core_major = implode( '.', array_slice( explode( '.', $wp_version ), 0, 2 ) );
	ifi_readme_add(
		$checks,
		'Tested up to <= installed core',
		version_compare( $tested_major, $core_major, '<=' ) ? 'pass' : 'fail',
		sprintf( 'tested %s vs installed %s', $tested, $wp_version )
	);
}

// --- Tags -----------------------------------------------------------------

$tags_raw = ifi_readme_field( $readme, 'Tags' );
$tags     = array_values( array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) ) );
ifi_readme_add(
	$checks,
	'Tags <= 5',
	count( $tags ) <= 5 ? 'pass' : 'fail',
	sprintf( '%d tags: %s', count( $tags ), implode( ', ', $tags ) )
);
$slug = basename( $root );
ifi_readme_add(
	$checks,
	'No tag equal to the plugin slug',
	! in_array( $slug, array_map( 'strtolower', $tags ), true ) ? 'pass' : 'warn',
	'exclusive tags are hidden by the directory'
);

// --- Short description ----------------------------------------------------

$body = preg_replace( '/^===.*?===\s*$/m', '', $readme, 1 );
$body = (string) preg_replace( '/^Contributors:.*?^License URI:.*?$/mis', '', $body, 1 );
$lines = preg_split( '/\r\n|\r|\n/', (string) $body ) ?: array();
$short = '';
foreach ( $lines as $line ) {
	$line = trim( $line );
	if ( '' === $line || '=' === $line[0] || '#' === $line[0] ) {
		continue;
	}
	$short = $line;
	break;
}
$short_len = function_exists( 'mb_strlen' ) ? mb_strlen( $short ) : strlen( $short );
ifi_readme_add(
	$checks,
	'Short description <= 150 chars',
	$short_len <= 150 ? 'pass' : 'fail',
	sprintf( '%d chars: %s', $short_len, $short )
);
ifi_readme_add(
	$checks,
	'Short description has no markup',
	( false === strpos( $short, '<' ) && false === strpos( $short, '[' ) ) ? 'pass' : 'warn',
	$short
);

// --- Screenshots ----------------------------------------------------------

$screenshot_block = '';
if ( preg_match( '/==\s*Screenshots\s*==(.*?)(?=\n==|\z)/si', $readme, $m ) ) {
	$screenshot_block = trim( $m[1] );
}
if ( '' === $screenshot_block ) {
	ifi_readme_add( $checks, 'Screenshots section', 'pass', 'absent' );
} else {
	preg_match_all( '/^\s*(\d+)\.\s/m', $screenshot_block, $m );
	$declared = array_map( 'intval', $m[1] );
	$missing  = array();
	foreach ( $declared as $index ) {
		if ( ! is_file( $root . '/wporg-assets/screenshot-' . $index . '.png' ) ) {
			$missing[] = 'wporg-assets/screenshot-' . $index . '.png';
		}
	}
	ifi_readme_add(
		$checks,
		'Screenshots declared => files exist',
		$missing ? 'fail' : 'pass',
		$missing
			? sprintf( '%d declared, missing: %s', count( $declared ), implode( ', ', $missing ) )
			: sprintf( '%d declared, all present', count( $declared ) )
	);
}

// --- Changelog length -----------------------------------------------------

$changelog = '';
$has_changelog = (bool) preg_match( '/==\s*Changelog\s*==(.*?)(?=\n==|\z)/si', $readme, $m );
if ( $has_changelog ) {
	$changelog = $m[1];
	preg_match_all( '/^=\s*(\d+\.\d+(?:\.\d+)?)\s*=/m', $changelog, $m );
	$versions = $m[1];
	$majors   = array_values( array_unique( array_map( static function ( $v ) {
		$p = explode( '.', $v );
		return $p[0] . '.' . ( $p[1] ?? '0' );
	}, $versions ) ) );
	ifi_readme_add(
		$checks,
		'Changelog keeps current + one major back',
		count( $majors ) <= 2 ? 'pass' : 'fail',
		sprintf( '%d entries across %d majors (%s)', count( $versions ), count( $majors ), implode( ', ', $majors ) )
	);
} else {
	ifi_readme_add(
		$checks,
		'Changelog section',
		'pass',
		'not present; full history lives in changelog.txt'
	);
}

// --- Required headers -----------------------------------------------------

$required = array( 'Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Stable tag', 'License', 'License URI' );
$absent   = array();
foreach ( $required as $field ) {
	if ( '' === ifi_readme_field( $readme, $field ) ) {
		$absent[] = $field;
	}
}
ifi_readme_add(
	$checks,
	'Required header fields present',
	$absent ? 'fail' : 'pass',
	$absent ? 'missing: ' . implode( ', ', $absent ) : 'all present'
);

// --- readme.MD must not ship ---------------------------------------------

$md = glob( $root . '/readme.{md,MD,markdown}' );
ifi_readme_add(
	$checks,
	'No readme.MD alongside readme.txt',
	$md ? 'fail' : 'pass',
	$md ? implode( ', ', array_map( 'basename', $md ) ) : 'none'
);

// --- Requires PHP consistency (info only) --------------------------------

$header_php = '';
if ( preg_match( '/^\s*\*?\s*Requires PHP:\s*(\S+)/mi', $main, $m ) ) {
	$header_php = trim( $m[1] );
}
$readme_php = ifi_readme_field( $readme, 'Requires PHP' );
ifi_readme_add(
	$checks,
	'Requires PHP — header is authoritative',
	$header_php === $readme_php ? 'pass' : 'info',
	sprintf( 'header "%s", readme "%s" (ignored since WP 5.8)', $header_php, $readme_php )
);

// --- Report ---------------------------------------------------------------

$fails  = array_filter( $checks, static function ( $c ) {
	return 'fail' === $c['verdict'];
} );
$warns  = array_filter( $checks, static function ( $c ) {
	return 'warn' === $c['verdict'];
} );

$lines   = array();
$lines[] = 'Inquiry Form Inbox — readme.txt compliance';
$lines[] = sprintf( '  readme : %d bytes', $size );
$lines[] = sprintf( '  core   : %s', $wp_version ? $wp_version : 'unknown' );
$lines[] = '';
foreach ( $checks as $check ) {
	$lines[] = sprintf(
		'  %-6s %-42s %s',
		strtoupper( $check['verdict'] ),
		$check['rule'],
		$check['detail']
	);
}
$lines[] = '';
$lines[] = sprintf( 'Result: %s (%d fail, %d warn)', $fails ? 'FAIL' : 'OK', count( $fails ), count( $warns ) );

echo implode( "\n", $lines ) . "\n";

if ( '' !== $json_out ) {
	$dir = dirname( $json_out );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	file_put_contents(
		$json_out,
		json_encode(
			array(
				'readme_bytes' => $size,
				'core_version' => $wp_version,
				'checks'       => $checks,
				'status'       => $fails ? 'FAIL' : 'OK',
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		)
	);
}

exit( $fails ? 1 : 0 );

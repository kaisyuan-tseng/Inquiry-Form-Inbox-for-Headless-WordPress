<?php
/**
 * PHP syntax lint for every plugin source file.
 *
 * Runs `php -l` in a subprocess per file so the check uses the same binary that will run
 * the plugin, then writes a machine-readable report. Exits 1 on any parse error.
 *
 * Usage:
 *   php tests/static/php-lint.php [--json=<path>]
 */

declare( strict_types = 1 );

$root     = dirname( __DIR__, 2 );
$json_out = '';
$targets  = array();

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--json=' ) ) {
		$json_out = substr( $arg, 7 );
	} else {
		fwrite( STDERR, "Unknown argument: {$arg}\n" );
		exit( 2 );
	}
}

// Collect plugin-owned PHP, skipping toolchain and test scaffolding.
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $item ) {
	if ( ! $item->isFile() || 'php' !== strtolower( $item->getExtension() ) ) {
		continue;
	}
	$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $root ) + 1 ) );
	foreach ( array( 'vendor/', 'node_modules/', 'tests/', '.git/' ) as $skip ) {
		if ( 0 === strpos( $relative, $skip ) ) {
			continue 2;
		}
	}
	$targets[] = $relative;
}
sort( $targets );

$results = array();
$failed  = 0;
$php_bin = PHP_BINARY;

foreach ( $targets as $relative ) {
	$path = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
	$out  = array();
	$code = 0;
	exec( escapeshellarg( $php_bin ) . ' -l ' . escapeshellarg( $path ) . ' 2>&1', $out, $code );
	$clean = ( 0 === $code );
	if ( ! $clean ) {
		$failed++;
	}
	// "No syntax errors detected" is noise; keep only real problems.
	$message = $clean
		? ''
		: trim( implode( "\n", array_filter( $out, static function ( $line ) {
			return false === strpos( $line, 'No syntax errors detected' );
		} ) ) );
	$results[] = array(
		'file'  => $relative,
		'ok'    => $clean,
		'error' => $message,
	);
}

$lines   = array();
$lines[] = 'Inquiry Form Inbox — PHP syntax lint';
$lines[] = sprintf( '  PHP      : %s', PHP_VERSION );
$lines[] = sprintf( '  files    : %d', count( $results ) );
$lines[] = sprintf( '  failures : %d', $failed );
if ( $failed ) {
	$lines[] = '';
	foreach ( $results as $result ) {
		if ( ! $result['ok'] ) {
			$lines[] = '  FAIL ' . $result['file'];
			$lines[] = '       ' . str_replace( "\n", "\n       ", $result['error'] );
		}
	}
}
$lines[] = '';
$lines[] = 'Result: ' . ( $failed ? 'FAIL' : 'OK' );

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
				'php'      => PHP_VERSION,
				'files'    => count( $results ),
				'failures' => $failed,
				'results'  => $results,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		)
	);
}

exit( $failed ? 1 : 0 );

<?php
/**
 * Gate: run every check in the order that fails cheapest first.
 *
 * The point of the ordering is that a syntax error or a stale translation catalogue should
 * never cost a database round trip. Static checks run first and stop the run; the three
 * suites that write to the scratch schema only run when IFI_ALLOW_TEST_WRITES is set.
 *
 * Results are always written to a file as well as printed, because this project is developed
 * on a machine where terminal output can be lost.
 *
 * Usage:
 *   php tests/bin/gate.php [--fast] [--report-dir=<path>]
 *
 *   --fast   Static checks and unit tests only.
 */

declare( strict_types = 1 );

$root    = dirname( __DIR__, 2 );
$fast    = in_array( '--fast', $argv, true );
$reports = $root . '/tests/reports/run-' . gmdate( 'Ymd-His' );

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--report-dir=' ) ) {
		$reports = substr( $arg, 13 );
	}
}

if ( ! is_dir( $reports ) ) {
	mkdir( $reports, 0777, true );
}

/**
 * Run one step and return [ok, seconds, output].
 *
 * @param string $label  Step label.
 * @param string $command Command line.
 * @param string $root   Working directory.
 * @return array{label: string, ok: bool, seconds: float, output: string}
 */
function ifi_gate_run( string $label, string $command, string $root ): array {
	$start  = microtime( true );
	$output = array();
	$status = 0;
	exec( 'cd ' . escapeshellarg( $root ) . ' && ' . $command . ' 2>&1', $output, $status );

	return array(
		'label'   => $label,
		'ok'      => 0 === $status,
		'seconds' => microtime( true ) - $start,
		'output'  => implode( "\n", $output ),
	);
}

$steps = array(
	array( 'php -l', 'php tests/static/php-lint.php --json=' . escapeshellarg( $reports . '/php-lint.json' ) ),
	array( 'phpcs', 'php vendor/bin/phpcs -q --report=json --report-file=' . escapeshellarg( $reports . '/phpcs.json' ) ),
	array( 'string keys', 'php tests/static/lint-strings.php --json=' . escapeshellarg( $reports . '/strings.json' ) ),
	array( 'gettext chain', 'php tests/static/i18n-parity.php --json=' . escapeshellarg( $reports . '/i18n.json' ) ),
	array( 'readme', 'php tests/static/readme-check.php --json=' . escapeshellarg( $reports . '/readme.json' ) ),
	array( 'unit', 'php vendor/bin/phpunit -c tests/phpunit.unit.xml' ),
);

if ( ! $fast ) {
	/*
	 * The suites read IFI_ALLOW_TEST_WRITES from their own environment. Child processes
	 * inherit this process's environment, so there is no need to prefix the command — and a
	 * POSIX-style `VAR=1 command` prefix would not work anyway, because exec() goes through
	 * cmd.exe on Windows.
	 */
	if ( getenv( 'IFI_ALLOW_TEST_WRITES' ) ) {
		$steps[] = array( 'integration', 'php vendor/bin/phpunit -c tests/phpunit.integration.xml' );
		$steps[] = array( 'http', 'node tests/e2e/run.mjs' );
		$steps[] = array( 'ui', 'node tests/ui/run.mjs' );
	} else {
		$skipped = array( 'integration', 'http', 'ui' );
	}
}

$results = array();
$failed  = false;
$skipped = $skipped ?? array();

echo "Inquiry Form Inbox — gate\n\n";

foreach ( $steps as $step ) {
	if ( $failed ) {
		$results[] = array(
			'label'   => $step[0],
			'ok'      => false,
			'seconds' => 0.0,
			'output'  => 'skipped: an earlier step failed',
		);
		continue;
	}

	$result    = ifi_gate_run( $step[0], $step[1], $root );
	$results[] = $result;

	printf(
		"  %-14s %-4s  %5.1fs\n",
		$result['label'],
		$result['ok'] ? 'ok' : 'FAIL',
		$result['seconds']
	);

	if ( ! $result['ok'] ) {
		$failed = true;
		$indented = preg_replace( '/^/m', '         ', substr( $result['output'], 0, 2000 ) );
		echo $indented . "\n";
	}
}

$summary = array();
$summary[] = 'Inquiry Form Inbox — gate run ' . gmdate( 'c' );
$summary[] = 'PHP ' . PHP_VERSION . ' · WordPress scratch prefix · ' . ( $fast ? 'fast mode' : 'full mode' );
$summary[] = '';
foreach ( $results as $result ) {
	$summary[] = sprintf( '%-14s %-4s %6.1fs', $result['label'], $result['ok'] ? 'ok' : 'FAIL', $result['seconds'] );
}
foreach ( $skipped as $label ) {
	$summary[] = sprintf( '%-14s %-4s', $label, 'skip' );
}
$summary[] = '';
$summary[] = $failed ? 'RESULT: FAIL' : 'RESULT: OK';

file_put_contents( $reports . '/summary.txt', implode( "\n", $summary ) . "\n" );

foreach ( $skipped as $label ) {
	printf( "  %-14s skip  (set IFI_ALLOW_TEST_WRITES=1)\n", $label );
}

echo "\n" . ( $failed ? 'RESULT: FAIL' : 'RESULT: OK' ) . "\n";
echo 'Report: ' . $reports . "/summary.txt\n";

exit( $failed ? 1 : 0 );

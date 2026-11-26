<?php
/**
 * Run uninstall.php in its own process against the scratch schema.
 *
 * Uninstall code only ever runs when WordPress deletes a plugin, which cannot be simulated
 * in-process: it guards on WP_UNINSTALL_PLUGIN and calls cleanup functions that assume a
 * complete bootstrap. So the integration suite shells out to this script and then asserts on
 * the surviving rows.
 *
 * The upload sandbox is attached *before* uninstall.php loads, and asserted. uninstall.php
 * deletes wp_upload_dir()/ifi-inquiries recursively — on a developer machine that directory
 * belongs to the real site, so pointing it at the scratch prefix is not enough on its own.
 *
 * Usage:
 *   php tests/bin/run-uninstall.php
 */

declare( strict_types = 1 );

define( 'WP_UNINSTALL_PLUGIN', 'inquiry-form-inbox/inquiry-form-inbox.php' );

require __DIR__ . '/../bootstrap/wp-base.php';

\Ifi\Tests\Scratch::attach_upload_filter();

try {
	\Ifi\Tests\Scratch::assert_upload_sandbox();
} catch ( RuntimeException $e ) {
	fwrite( STDERR, 'Refusing to uninstall: ' . $e->getMessage() . "\n" );
	exit( 4 );
}

fwrite( STDERR, 'Uploads sandbox: ' . \Ifi\Tests\Scratch::uploads_base() . "\n" );

require dirname( __DIR__, 2 ) . '/uninstall.php';

echo "uninstall.php completed\n";

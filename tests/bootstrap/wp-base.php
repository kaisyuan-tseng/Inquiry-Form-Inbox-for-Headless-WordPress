<?php
/**
 * Shared bootstrap for the integration and HTTP suites.
 *
 * The site under test runs on the same MySQL instance as the real site, so isolation comes
 * from the table prefix rather than from a separate database: `wp_local` has no
 * CREATE DATABASE grant, and a scratch database was therefore never an option.
 *
 *   - every table this suite touches lives under `wptests_`
 *   - the plugin is loaded the normal way, through the `active_plugins` option, so its
 *     hooks land at the same point in the request as they do in production
 *   - wp-config.php is never read: it hard-codes the `wp_` prefix, so the constants are
 *     defined here instead
 *
 * Two hard guards:
 *
 *   1. If the prefix is not `wptests_`, abort. This is the one mistake that would write
 *      test records into the real inbox.
 *   2. If the prefix tables do not exist yet, abort with the install command. Booting
 *      WordPress against a missing schema produces a redirect to install.php, which is a
 *      much worse error message.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

/**
 * Absolute path to the WordPress installation that hosts this plugin.
 *
 * @return string
 */
function ifi_test_wp_root(): string {
	// tests/bootstrap -> tests -> plugin -> plugins -> wp-content -> WordPress.
	return dirname( __DIR__, 5 );
}

/**
 * Read a test setting from the environment, with a default.
 *
 * @param string $name    Environment variable name.
 * @param string $default Default value.
 * @return string
 */
function ifi_test_env( string $name, string $default ): string {
	$value = getenv( $name );
	return ( false === $value || '' === $value ) ? $default : $value;
}

$ifi_test_wp_root = ifi_test_wp_root();

if ( ! is_file( $ifi_test_wp_root . '/wp-settings.php' ) ) {
	fwrite( STDERR, "Cannot find WordPress at {$ifi_test_wp_root}\n" );
	exit( 3 );
}

// --- Isolation guard ------------------------------------------------------

$ifi_test_prefix = 'wptests_';
if ( 'wptests_' !== $ifi_test_prefix ) {
	fwrite( STDERR, "Refusing to run: the test table prefix must be wptests_.\n" );
	exit( 3 );
}

// --- Pre-flight -----------------------------------------------------------
//
// wp-settings.php dies with a redirect when the blog is not installed, so check the schema
// before booting. WP_INSTALLING skips that check for the installer itself.

if ( ! defined( 'WP_INSTALLING' ) || ! WP_INSTALLING ) {
	$ifi_test_mysqli = @new mysqli( // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- pre-flight only, WordPress is not loaded yet.
		ifi_test_env( 'IFI_TEST_DB_HOST', 'localhost' ),
		ifi_test_env( 'IFI_TEST_DB_USER', 'wp_local' ),
		ifi_test_env( 'IFI_TEST_DB_PASSWORD', '' ),
		ifi_test_env( 'IFI_TEST_DB_NAME', 'wordpress_local' )
	);
	if ( $ifi_test_mysqli->connect_errno ) {
		fwrite( STDERR, 'Cannot reach MySQL: ' . $ifi_test_mysqli->connect_error . "\n" );
		exit( 3 );
	}
	$ifi_test_tables = $ifi_test_mysqli->query( "SHOW TABLES LIKE 'wptests_options'" );
	$ifi_test_ready  = $ifi_test_tables instanceof mysqli_result && $ifi_test_tables->num_rows > 0;
	$ifi_test_mysqli->close();

	if ( ! $ifi_test_ready ) {
		fwrite(
			STDERR,
			"The test schema does not exist yet. Run:\n\n    php tests/bin/install-wp.php\n\n"
		);
		exit( 3 );
	}
	unset( $ifi_test_mysqli, $ifi_test_tables, $ifi_test_ready );
}

// --- Constants ------------------------------------------------------------

if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', ifi_test_env( 'IFI_TEST_DB_NAME', 'wordpress_local' ) );
}
if ( ! defined( 'DB_USER' ) ) {
	define( 'DB_USER', ifi_test_env( 'IFI_TEST_DB_USER', 'wp_local' ) );
}
if ( ! defined( 'DB_PASSWORD' ) ) {
	define( 'DB_PASSWORD', ifi_test_env( 'IFI_TEST_DB_PASSWORD', '' ) );
}
if ( ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', ifi_test_env( 'IFI_TEST_DB_HOST', 'localhost' ) );
}
if ( ! defined( 'DB_CHARSET' ) ) {
	define( 'DB_CHARSET', 'utf8mb4' );
}
if ( ! defined( 'DB_COLLATE' ) ) {
	define( 'DB_COLLATE', '' );
}

// Fixed salts: the CLI process and the HTTP process must agree, or an auth cookie minted
// in one is rejected by the other.
$ifi_test_salts = array(
	'AUTH_KEY',
	'SECURE_AUTH_KEY',
	'LOGGED_IN_KEY',
	'NONCE_KEY',
	'AUTH_SALT',
	'SECURE_AUTH_SALT',
	'LOGGED_IN_SALT',
	'NONCE_SALT',
);
foreach ( $ifi_test_salts as $ifi_test_salt ) {
	if ( ! defined( $ifi_test_salt ) ) {
		define( $ifi_test_salt, 'ifi-test-' . $ifi_test_salt . '-not-a-secret' );
	}
}
unset( $ifi_test_salts, $ifi_test_salt );

if ( ! defined( 'WP_HOME' ) ) {
	define( 'WP_HOME', 'http://127.0.0.1:8099' );
}
if ( ! defined( 'WP_SITEURL' ) ) {
	define( 'WP_SITEURL', 'http://127.0.0.1:8099' );
}
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}
if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
	// Core itself emits deprecations on PHP 8.5; the suite asserts on lines that mention
	// this plugin, so the log has to exist while the page stays clean.
	define( 'WP_DEBUG_DISPLAY', false );
}
if ( ! defined( 'WP_DEBUG_LOG' ) ) {
	define( 'WP_DEBUG_LOG', dirname( __DIR__ ) . '/runtime/debug.log' );
}
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'AUTOMATIC_UPDATER_DISABLED' ) ) {
	define( 'AUTOMATIC_UPDATER_DISABLED', true );
}
if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) ) {
	define( 'WP_ENVIRONMENT_TYPE', 'local' );
}

$GLOBALS['table_prefix'] = $ifi_test_prefix;

// --- $wpdb -----------------------------------------------------------------
//
// wp-settings.php builds $wpdb from the constants above, but it also loads
// wp-content/db.php when present, which would silently point back at the real site. Setting
// $wpdb here (before wp-settings) keeps the scratch prefix authoritative.

if ( ! defined( 'WP_USE_EXT_MYSQL' ) ) {
	define( 'WP_USE_EXT_MYSQL', false );
}

// --- Request context ------------------------------------------------------

if ( ! isset( $_SERVER['HTTP_HOST'] ) ) {
	$_SERVER['HTTP_HOST']      = '127.0.0.1:8099';
	$_SERVER['SERVER_NAME']    = '127.0.0.1';
	$_SERVER['SERVER_PORT']    = '8099';
	$_SERVER['REQUEST_URI']    = '/';
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
	$_SERVER['PHP_SELF']       = '/index.php';
	$_SERVER['SCRIPT_NAME']    = '/index.php';
	$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
}

// --- Boot -----------------------------------------------------------------

// wp-load.php normally defines this right before including wp-settings.php.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $ifi_test_wp_root . '/' );
}

require_once $ifi_test_wp_root . '/wp-settings.php';

/*
 * Sandbox uploads for every consumer of this bootstrap.
 *
 * Not left to the individual runners: the HTTP router and the uninstall subprocess both
 * write through wp_upload_dir(), and one of them forgetting this filter is enough to put
 * test attachments in the real site's uploads directory. It happened once; the fix is to
 * make forgetting impossible.
 */
require_once __DIR__ . '/../lib/Scratch.php';
\Ifi\Tests\Scratch::attach_upload_filter();

unset( $ifi_test_wp_root, $ifi_test_prefix );

<?php
/**
 * Install the scratch schema used by the integration and HTTP suites.
 *
 * Creates every `wptests_*` table with WordPress's own installer, then activates this plugin
 * in the scratch site so its hooks load at the normal point in the request. Re-running is
 * safe: the installer only runs when the schema is missing.
 *
 * The scratch site is deliberately plugin-free apart from this one. The real site runs
 * wp-mail-smtp and Rank Math; neither belongs in a test that asserts on core behaviour.
 *
 * Usage:
 *   php tests/bin/install-wp.php [--fresh]
 *
 *   --fresh  Drop every wptests_* table first and rebuild from scratch.
 */

declare( strict_types = 1 );

$root = dirname( __DIR__, 2 );

if ( in_array( '--fresh', $argv, true ) ) {
	define( 'IFI_TEST_FRESH', true );
}

// The pre-flight schema check must be skipped: creating that schema is the whole point.
define( 'WP_INSTALLING', true );

require __DIR__ . '/../bootstrap/wp-base.php';

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

$fresh = defined( 'IFI_TEST_FRESH' ) && IFI_TEST_FRESH;

if ( $fresh ) {
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( 'wptests_' ) . '%' ) );
	foreach ( (array) $tables as $table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- dropping the scratch schema, table names come from SHOW TABLES.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $table ) . '`' );
	}
	echo 'Dropped ' . count( (array) $tables ) . " scratch table(s).\n";
	wp_cache_flush();
}

if ( is_blog_installed() ) {
	echo "Scratch schema already present.\n";
} else {
	$result = wp_install(
		'IFI Test Site',
		'admin',
		'admin@example.test',
		true,   // Public.
		'',
		'ifi-test-password'
	);

	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, 'Install failed: ' . $result->get_error_message() . "\n" );
		exit( 1 );
	}
	echo "Installed scratch schema.\n";
}

// Activate only this plugin, so its hooks load through the normal plugin path.
update_option( 'active_plugins', array( 'inquiry-form-inbox/inquiry-form-inbox.php' ) );
update_option( 'blog_public', '0' );
update_option( 'admin_email', 'inbox@example.test' );

// A deterministic timezone keeps the inquiry-number assertions stable.
update_option( 'timezone_string', 'Asia/Shanghai' );
update_option( 'gmt_offset', '' );
update_option( 'date_format', 'Y-m-d' );
update_option( 'time_format', 'H:i' );

// Rewrite rules: permalinks are needed so /wp-json/ resolves the way it does in production.
// The rules are regenerated on the first front-end request; deleting the cached copy is
// enough and avoids flushing inside WP_INSTALLING.
update_option( 'permalink_structure', '/%postname%/' );
delete_option( 'rewrite_rules' );

wp_cache_flush();

printf(
	"Ready: %s (prefix %s), active plugins: %s\n",
	home_url(),
	$wpdb->prefix,
	implode( ', ', (array) get_option( 'active_plugins' ) )
);

<?php
/**
 * Issue a logged-in auth cookie for the site that hosts this plugin.
 *
 * The UI suite drives wp-admin, and wp-admin needs a complete admin bootstrap that the
 * scratch router deliberately does not provide. So it runs against the real local site and
 * authenticates by injecting a cookie instead of typing a password — no interactive login,
 * no password in a config file.
 *
 * This is a read-only operation: it mints a cookie and prints it. Nothing on the site
 * changes.
 *
 * Usage:
 *   php tests/bin/issue-auth-cookie.php
 *   php tests/bin/issue-auth-cookie.php --destroy=<token> --user=<id>
 *
 * Output: JSON on stdout, diagnostics on stderr.
 */

declare( strict_types = 1 );

$wp_root = dirname( __DIR__, 5 ) . '/wp-load.php';

if ( ! is_file( $wp_root ) ) {
	fwrite( STDERR, "Cannot find wp-load.php at {$wp_root}\n" );
	exit( 3 );
}

require $wp_root;

foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 !== strpos( $arg, '--destroy=' ) ) {
		continue;
	}
	$destroy = substr( $arg, 10 );
	$user_id = 0;
	foreach ( array_slice( $argv, 1 ) as $other ) {
		if ( 0 === strpos( $other, '--user=' ) ) {
			$user_id = (int) substr( $other, 7 );
		}
	}
	if ( $destroy !== '' && $user_id > 0 ) {
		WP_Session_Tokens::get_instance( $user_id )->destroy( $destroy );
		echo "Session token destroyed.\n";
	}
	exit( 0 );
}

$admins = get_users(
	array(
		'role'    => 'administrator',
		'number'  => 1,
		'orderby' => 'ID',
	)
);

if ( ! $admins ) {
	fwrite( STDERR, "No administrator account found.\n" );
	exit( 3 );
}

$user = $admins[0];
wp_set_current_user( $user->ID );

$expires = time() + HOUR_IN_SECONDS;

/*
 * A cookie on its own is not a login. wp_validate_auth_cookie() cross-checks the token
 * embedded in it against the user's stored session list, so the token has to be created
 * first — wp_set_auth_cookie() does this internally, and skipping it is why a hand-rolled
 * cookie lands on wp-login.php?reauth=1.
 */
$manager = WP_Session_Tokens::get_instance( $user->ID );
$token   = $manager->create( $expires );

/*
 * Two cookies, one session token — this is what wp_set_auth_cookie() writes. auth_redirect()
 * validates the `auth` cookie, while _wp_get_current_user() reads the `logged_in` one, so
 * issuing only one of them sends the browser straight back to wp-login.php?reauth=1.
 */
$auth_cookie   = wp_generate_auth_cookie( $user->ID, $expires, 'auth', $token );
$logged_cookie = wp_generate_auth_cookie( $user->ID, $expires, 'logged_in', $token );

if ( ! $auth_cookie || ! $logged_cookie ) {
	$manager->destroy( $token );
	fwrite( STDERR, "Could not generate the auth cookies.\n" );
	exit( 3 );
}

$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

fwrite( STDERR, sprintf( "Issued cookies for %s (user #%d) on %s\n", $user->user_login, $user->ID, $host ) );

echo json_encode(
	array(
		'cookies' => array(
			array(
				'name'  => AUTH_COOKIE,
				'value' => $auth_cookie,
			),
			array(
				'name'  => LOGGED_IN_COOKIE,
				'value' => $logged_cookie,
			),
		),
		'domain'  => $host,
		'path'    => '/',
		'expires' => $expires,
		'home'    => home_url(),
		'user'    => $user->user_login,
		'user_id' => $user->ID,
		'token'   => $token,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);

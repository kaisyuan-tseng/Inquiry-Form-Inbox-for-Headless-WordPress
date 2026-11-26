<?php
/**
 * Router for the HTTP suite: a real WordPress front controller on 127.0.0.1:8099.
 *
 * Why a separate server instead of the site on :8081 — the integration suite writes to a
 * scratch schema and a sandboxed uploads directory. Posting to :8081 would put test
 * inquiries in the real inbox and let wp-mail-smtp send them.
 *
 * Three routes exist here and nowhere else:
 *
 *   /media/*          serves the sandboxed uploads, so a test can assert that the URL a
 *                     record carries actually resolves
 *   /__test__/state   dumps the records and their meta, so scenarios can assert on the
 *                     result of a request from outside the process
 *   /__test__/reset   wipes the scratch schema between scenarios
 *
 * The two /__test__ routes require a token header. They live in tests/, which .distignore
 * excludes, so none of this reaches a distributed copy.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

const IFI_E2E_TOKEN = 'ifi-e2e-local-only';

/**
 * Send a JSON response and stop.
 *
 * @param mixed $payload Payload.
 * @param int   $status  HTTP status.
 */
function ifi_e2e_json( $payload, int $status = 200 ): void {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
}

/**
 * Whether the caller presented the test token.
 *
 * @return bool
 */
function ifi_e2e_authorised(): bool {
	return IFI_E2E_TOKEN === ( $_SERVER['HTTP_X_IFI_TEST_TOKEN'] ?? '' );
}

$ifi_e2e_uri  = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
$ifi_e2e_root = dirname( __DIR__, 2 );
$ifi_e2e_runtime = dirname( __DIR__ ) . '/runtime';

// --- Sandboxed media ------------------------------------------------------

if ( 0 === strpos( $ifi_e2e_uri, '/media/' ) ) {
	$ifi_e2e_rel  = rawurldecode( substr( $ifi_e2e_uri, strlen( '/media/' ) ) );
	$ifi_e2e_file = $ifi_e2e_runtime . '/uploads/' . str_replace( '..', '', $ifi_e2e_rel );

	if ( ! is_file( $ifi_e2e_file ) ) {
		http_response_code( 404 );
		echo 'not found';
		exit;
	}

	$ifi_e2e_types = array(
		'pdf'  => 'application/pdf',
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'webp' => 'image/webp',
		'zip'  => 'application/zip',
	);
	$ifi_e2e_ext   = strtolower( (string) pathinfo( $ifi_e2e_file, PATHINFO_EXTENSION ) );
	header( 'Content-Type: ' . ( $ifi_e2e_types[ $ifi_e2e_ext ] ?? 'application/octet-stream' ) );
	header( 'Content-Length: ' . (string) filesize( $ifi_e2e_file ) );
	readfile( $ifi_e2e_file );
	exit;
}

// --- Test control ---------------------------------------------------------

if ( 0 === strpos( $ifi_e2e_uri, '/__test__/' ) ) {
	if ( ! ifi_e2e_authorised() ) {
		http_response_code( 403 );
		echo 'forbidden';
		exit;
	}

	require_once dirname( __DIR__ ) . '/bootstrap/wp-base.php';
	require_once dirname( __DIR__ ) . '/lib/Scratch.php';

	if ( '/__test__/reset' === $ifi_e2e_uri ) {
		\Ifi\Tests\Scratch::attach_upload_filter();
		\Ifi\Tests\Scratch::reset_state();
		ifi_e2e_json( array( 'reset' => true ) );
	}

	if ( '/__test__/state' === $ifi_e2e_uri ) {
		\Ifi\Tests\Scratch::attach_upload_filter();

		$records = array();
		foreach ( \Ifi\Tests\Scratch::inquiries() as $post ) {
			$meta = get_post_meta( $post->ID );
			$flat = array();
			foreach ( $meta as $key => $values ) {
				$flat[ $key ] = maybe_unserialize( $values[0] ?? '' );
			}
			$records[] = array(
				'id'      => $post->ID,
				'status'  => $post->post_status,
				'title'   => $post->post_title,
				'content' => $post->post_content,
				'meta'    => $flat,
			);
		}

		ifi_e2e_json(
			array(
				'count'   => count( $records ),
				'records' => $records,
				// The front end and the plugin both have to live with PHP's own upload cap:
				// extra files are dropped by PHP before the plugin ever sees them.
				'limits'  => array(
					'max_file_uploads' => (int) ini_get( 'max_file_uploads' ),
					'ifi_max_files'    => (int) apply_filters( 'ifi_max_files', \Inquiry_Form_Inbox::MAX_FILES ),
				),
			)
		);
	}

	ifi_e2e_json( array( 'error' => 'unknown test route' ), 404 );
}

// --- WordPress ------------------------------------------------------------

require_once dirname( __DIR__ ) . '/bootstrap/wp-base.php';

// wp-base already sandboxed uploads; assert it rather than trust it. A test attachment that
// lands in the real site's uploads directory is the one failure this suite must never have.
$ifi_e2e_uploads = wp_normalize_path( wp_upload_dir()['basedir'] );
if ( 0 !== strpos( $ifi_e2e_uploads, wp_normalize_path( dirname( __DIR__ ) . '/runtime/uploads' ) ) ) {
	http_response_code( 500 );
	echo 'uploads are not sandboxed: ' . esc_html( $ifi_e2e_uploads );
	exit;
}

// Capture mail so a submission does not depend on a working sendmail binary. Returning true
// short-circuits wp_mail() before PHPMailer is built; the failure switch flips it to false.
// A failed short-circuit does not fire wp_mail_failed on its own, and the plugin relies on
// that action to record the reason, so it is fired here exactly as a real SMTP error would.
$ifi_e2e_fail_mail = ! empty( $_SERVER['HTTP_X_IFI_TEST_FAIL_MAIL'] );
add_filter(
	'pre_wp_mail',
	static function () use ( $ifi_e2e_fail_mail ) {
		if ( $ifi_e2e_fail_mail ) {
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Simulated SMTP failure over HTTP.' ) );
			return false;
		}
		return true;
	},
	10,
	1
);

// Equivalent to wp-blog-header.php minus the wp-load.php call, which wp-base already did.
$GLOBALS['wp_did_header'] = true;
wp();
require_once ABSPATH . WPINC . '/template-loader.php';

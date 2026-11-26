<?php
/**
 * Base class for every test that touches the scratch schema.
 *
 * The suite writes to a real database and a real filesystem, which makes two things
 * non-negotiable: an explicit opt-in before anything is written, and a teardown that leaves
 * no trace. Both live here so no individual test has to remember them.
 *
 * Opt-in is an environment variable rather than a config default, because the failure mode
 * of getting it wrong is silent: the tests would pass while filling the developer's inbox
 * with fake inquiries.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Shared setup, teardown and request helpers.
 */
abstract class IfiTestCase extends TestCase {

	/** Administrator user id for the scratch site. */
	protected int $admin_id = 0;

	/** Editor user id for the scratch site. */
	protected int $editor_id = 0;

	/**
	 * Deep clone of the hook registry taken in setUp.
	 *
	 * WordPress hooks live for the whole PHP process, so a filter added in one test keeps
	 * firing in every test after it. That is not a theoretical problem: an early version of
	 * this suite registered `ifi_required_fields` in one case and broke the next twelve.
	 * WP_Hook objects are mutated in place by add_filter(), so the snapshot has to be a
	 * clone rather than a copy of the array.
	 *
	 * @var array<string, \WP_Hook>
	 */
	private array $hook_snapshot = array();

	/** Action-run counters, restored alongside the hook registry. */
	private array $action_snapshot = array();

	protected function setUp(): void {
		parent::setUp();

		if ( ! getenv( 'IFI_ALLOW_TEST_WRITES' ) ) {
			$this->markTestSkipped(
				'Set IFI_ALLOW_TEST_WRITES=1 to run tests that write to the scratch wptests_ schema.'
			);
		}

		Scratch::assert_schema();
		Scratch::attach_upload_filter();
		Scratch::assert_upload_sandbox();

		// Attach the harness hooks before snapshotting, so they survive the restore.
		MailSpy::attach();
		MailSpy::reset();

		$this->hook_snapshot   = $this->clone_hooks( $GLOBALS['wp_filter'] ?? array() );
		$this->action_snapshot = $GLOBALS['wp_actions'] ?? array();

		Scratch::reset_state();

		$this->admin_id  = $this->ensure_user( 'ifi_admin', 'administrator' );
		$this->editor_id = $this->ensure_user( 'ifi_editor', 'editor' );
		wp_set_current_user( $this->admin_id );

		// The plugin grants capabilities on activation; the scratch site never activated it
		// through the UI, so grant them explicitly. This is also what the self-heal path
		// does on a real upgrade.
		\IFI_Capabilities::init_capabilities();
	}

	protected function tearDown(): void {
		Scratch::reset_state();

		$GLOBALS['wp_filter']        = $this->clone_hooks( $this->hook_snapshot );
		$GLOBALS['wp_actions']       = $this->action_snapshot;
		$GLOBALS['wp_current_filter'] = array();

		wp_set_current_user( 0 );
		wp_cache_flush();

		parent::tearDown();
	}

	/**
	 * Clone a hook registry so later mutations cannot reach back into the snapshot.
	 *
	 * @param array<string, \WP_Hook> $hooks Hook registry.
	 * @return array<string, \WP_Hook>
	 */
	private function clone_hooks( array $hooks ): array {
		$copy = array();
		foreach ( $hooks as $name => $hook ) {
			if ( $hook instanceof \WP_Hook ) {
				$copy[ $name ] = clone $hook;
			}
		}
		return $copy;
	}

	/**
	 * Create a user if it does not exist yet and return its id.
	 *
	 * @param string $login Login name.
	 * @param string $role  Role slug.
	 * @return int
	 */
	private function ensure_user( string $login, string $role ): int {
		$user = get_user_by( 'login', $login );
		if ( $user ) {
			return (int) $user->ID;
		}

		$id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => 'ifi-test-password',
				'user_email' => $login . '@example.test',
				'role'       => $role,
			)
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Submit an inquiry through the REST route, in-process.
	 *
	 * Note: file uploads cannot be exercised this way — move_uploaded_file() requires a real
	 * upload request. Those cases live in tests/e2e/.
	 *
	 * @param array $params Body parameters.
	 * @return WP_REST_Request|WP_REST_Response|WP_Error
	 */
	protected function submit( array $params = array() ) {
		$request = new WP_REST_Request( 'POST', '/inquiry-form-inbox/v1/inquiry' );
		$request->set_body_params(
			array_merge(
				array(
					'fullName' => 'Test Buyer',
					'email'    => 'buyer@example.com',
					'message'  => 'Please quote 500 units.',
				),
				$params
			)
		);

		return rest_do_request( $request );
	}

	/**
	 * Assert a response is a successful submission.
	 *
	 * @param mixed $response Response from submit().
	 */
	protected function assert_sent( $response ): void {
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'sent' => true ), $response->get_data() );
	}

	/**
	 * Assert a response is an error with a given code and status.
	 *
	 * rest_do_request() runs the full dispatch pipeline, which converts a WP_Error returned
	 * by the callback into a WP_REST_Response carrying `code` / `message` / `data.status`.
	 * Both shapes are accepted so a test can assert on the outcome without caring which
	 * layer produced it.
	 *
	 * @param mixed  $response Response from submit().
	 * @param string $code     Expected error code.
	 * @param int    $status   Expected HTTP status.
	 * @return string The human-readable message, for further assertions.
	 */
	protected function assert_error( $response, string $code, int $status ): string {
		if ( $response instanceof WP_Error ) {
			$this->assertSame( $code, $response->get_error_code() );
			$data = $response->get_error_data();
			$this->assertIsArray( $data );
			$this->assertSame( $status, (int) ( $data['status'] ?? 0 ) );
			return (string) $response->get_error_message();
		}

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $status, $response->get_status() );
		$body = $response->get_data();
		$this->assertIsArray( $body );
		$this->assertSame( $code, (string) ( $body['code'] ?? '' ) );

		return (string) ( $body['message'] ?? '' );
	}

	/**
	 * The most recently created inquiry record.
	 *
	 * @return \WP_Post|null
	 */
	protected function latest_inquiry(): ?\WP_Post {
		$posts = Scratch::inquiries();
		return $posts ? $posts[ count( $posts ) - 1 ] : null;
	}

	/**
	 * Count inquiry records, optionally filtered by a meta value.
	 *
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Expected value.
	 * @return int
	 */
	protected function count_inquiries( string $meta_key = '', $meta_value = null ): int {
		$count = 0;
		foreach ( Scratch::inquiries() as $post ) {
			if ( '' === $meta_key ) {
				$count++;
				continue;
			}
			if ( (string) get_post_meta( $post->ID, $meta_key, true ) === (string) $meta_value ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Write spam rules directly, bypassing the settings screen.
	 *
	 * @param array $rules Partial rules; merged over the defaults by the plugin.
	 */
	protected function set_spam_rules( array $rules ): void {
		update_option( 'ifi_spam_rules', $rules, false );
	}

	/**
	 * Enable or disable auto-confirmation with optional language groups.
	 *
	 * When no groups are given, one default group is written with a body — that mirrors what
	 * the settings screen saves. Leaving `html` empty is a legitimate state (the owner
	 * cleared the source), but it is not a useful default for a test.
	 *
	 * @param bool  $enabled Enabled.
	 * @param array $groups  Language groups.
	 */
	protected function set_autoreply( bool $enabled, array $groups = array() ): void {
		if ( ! $groups ) {
			$groups = array(
				array(
					'lang'    => '',
					'subject' => '',
					'html'    => '<p>Hello {{rows.fullName.value}}</p>',
					'labels'  => array(),
				),
			);
		}

		update_option(
			'ifi_autoreply',
			array(
				'enabled'       => $enabled ? '1' : '0',
				'ignore_unsent' => '0',
				'groups'        => $groups,
			),
			false
		);
		\Inquiry_Form_Inbox::flush_template_cache();
	}
}

<?php
/**
 * Activation: silent callback, one-time success notice, single plugin header.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;

/**
 * Covers IFI_Migration::activate() and the plugin folder structure.
 */
final class ActivationTest extends IfiTestCase {

	private const HOOK = 'activate_inquiry-form-inbox/inquiry-form-inbox.php';

	protected function tearDown(): void {
		delete_transient( 'ifi_activation_notice_' . get_current_user_id() );
		parent::tearDown();
	}

	public function test_activation_produces_no_output(): void {
		wp_set_current_user( $this->admin_id );

		ob_start();
		do_action( self::HOOK, false );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_activation_sets_the_one_time_success_notice(): void {
		wp_set_current_user( $this->admin_id );
		delete_transient( 'ifi_activation_notice_' . $this->admin_id );

		do_action( self::HOOK, false );

		$this->assertNotFalse( get_transient( 'ifi_activation_notice_' . $this->admin_id ) );
	}

	public function test_the_plugin_folder_has_exactly_one_plugin_header(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins( '/inquiry-form-inbox' );

		$this->assertSame( array( 'inquiry-form-inbox.php' ), array_keys( $plugins ) );
	}
}

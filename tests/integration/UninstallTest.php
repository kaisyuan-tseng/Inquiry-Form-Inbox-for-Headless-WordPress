<?php
/**
 * Uninstall: what must not survive.
 *
 * "Uninstalling removes everything" is easy to claim and easy to get wrong — the previous
 * release left `ifi_labels_manual` behind precisely because nobody enumerated the options.
 * These tests enumerate.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;
use Ifi\Tests\Scratch;

/**
 * Covers uninstall.php by running it in a subprocess.
 */
final class UninstallTest extends IfiTestCase {

	/**
	 * Run uninstall.php in a fresh PHP process.
	 *
	 * @return string Combined output.
	 */
	private function run_uninstall(): string {
		$script = dirname( __DIR__ ) . '/bin/run-uninstall.php';
		$output = array();
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, "uninstall.php failed:\n" . implode( "\n", $output ) );

		return implode( "\n", $output );
	}

	/**
	 * Seed one of everything the plugin writes.
	 *
	 * @return int Inquiry post id.
	 */
	private function seed_everything(): int {
		$this->submit();

		update_option( 'ifi_spam_rules', array( 'honeypot' => '1' ), false );
		update_option( 'ifi_labels_manual', '1', false );
		update_option( 'ifi_db_version', '30000', false );
		update_option( 'ifi_seq_' . current_time( 'Ymd' ), 5, false );
		set_transient( 'ifi_rl_min_deadbeef', array( 'count' => 1 ), 3600 );
		set_transient( 'ifi_activation_notice_1', 1, 3600 );

		$post = $this->latest_inquiry();
		$this->assertNotNull( $post );

		$dir = Scratch::uploads_base() . '/ifi-inquiries/202601/' . $post->ID . '-abc';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/drawing.pdf', '%PDF-1.4 test' );
		update_post_meta(
			$post->ID,
			'ifi_inquiry_attachments',
			array(
				array(
					'name' => 'drawing.pdf',
					'path' => wp_normalize_path( $dir . '/drawing.pdf' ),
					'url'  => '/media/ifi-inquiries/202601/' . $post->ID . '-abc/drawing.pdf',
					'size' => 13,
				),
			)
		);

		return (int) $post->ID;
	}

	public function test_uninstall_removes_inquiry_records(): void {
		$id = $this->seed_everything();

		$this->run_uninstall();
		wp_cache_flush();

		$this->assertNull( get_post( $id ) );
		$this->assertCount( 0, Scratch::inquiries() );
	}

	public function test_uninstall_removes_every_option_the_plugin_writes(): void {
		$this->seed_everything();

		$this->run_uninstall();
		wp_cache_flush();

		global $wpdb;
		$left = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'ifi_' ) . '%',
				$wpdb->esc_like( '_transient_ifi_' ) . '%'
			)
		);

		$this->assertSame( array(), $left );
	}

	public function test_uninstall_removes_the_labels_flag(): void {
		// Regression lock: this option is written by the migration, not by the settings
		// screen, so it was missing from the original uninstall list.
		update_option( 'ifi_labels_manual', '1', false );

		$this->run_uninstall();
		wp_cache_flush();

		$this->assertFalse( get_option( 'ifi_labels_manual' ) );
	}

	public function test_uninstall_removes_the_sequence_counters(): void {
		update_option( 'ifi_seq_' . current_time( 'Ymd' ), 5, false );

		$this->run_uninstall();
		wp_cache_flush();

		$this->assertFalse( get_option( 'ifi_seq_' . current_time( 'Ymd' ) ) );
	}

	public function test_uninstall_removes_the_attachment_directory(): void {
		$this->seed_everything();

		$base = Scratch::uploads_base() . '/ifi-inquiries';
		$this->assertDirectoryExists( $base );

		$this->run_uninstall();

		$this->assertDirectoryDoesNotExist( $base );
	}

	public function test_uninstall_removes_the_capabilities_from_roles(): void {
		$this->run_uninstall();
		wp_cache_flush();

		// WP_Roles caches the role table in a global object for the life of the process, and
		// wp_cache_flush() does not rebuild it. Drop it so the assertion reads what the
		// subprocess actually wrote.
		unset( $GLOBALS['wp_roles'] );
		$role = get_role( 'administrator' );

		$this->assertFalse( $role->has_cap( 'ifi_view_inquiries' ) );
		$this->assertFalse( $role->has_cap( 'ifi_edit_inquiries' ) );
		$this->assertFalse( $role->has_cap( 'ifi_delete_inquiries' ) );
	}

	public function test_the_uninstall_runner_refuses_to_touch_real_uploads(): void {
		// The runner asserts its upload sandbox before loading uninstall.php. If that guard
		// ever regresses, this fails instead of deleting the developer's real attachments.
		$output = $this->run_uninstall();

		$this->assertStringContainsString( 'Uploads sandbox:', $output );
		$this->assertStringContainsString( 'runtime', $output );
		$this->assertStringContainsString( 'uploads', $output );
		$this->assertStringNotContainsString( 'wp-content/uploads', $output );
	}

	public function test_uninstall_leaves_other_plugins_data_alone(): void {
		update_option( 'ifi_template', array( 'subject' => 'x' ), false );
		update_option( 'some_other_plugin_setting', 'keep me', false );

		$this->run_uninstall();
		wp_cache_flush();

		$this->assertSame( 'keep me', get_option( 'some_other_plugin_setting' ) );
	}

	public function test_uninstall_is_idempotent(): void {
		$this->seed_everything();

		$this->run_uninstall();
		$second = $this->run_uninstall();

		$this->assertStringContainsString( 'completed', $second );
	}
}

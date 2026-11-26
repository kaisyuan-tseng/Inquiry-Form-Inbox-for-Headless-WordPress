<?php
/**
 * Admin detail output: stored bodies are re-filtered before they reach the browser.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;
use Ifi\Tests\Reflect;

/**
 * Covers render_stored_body().
 */
final class AdminRenderTest extends IfiTestCase {

	public function test_stored_bodies_are_filtered_before_the_admin_detail_view(): void {
		$html = '<script>alert(1)</script><p style="color:rgba(0,0,0,.5)">x</p>';

		$out = (string) Reflect::call( 'render_stored_body', array( $html ) );

		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringContainsString( 'rgba(0,0,0,.5)', $out );
	}

	public function test_view_mode_is_disabled_for_inquiries(): void {
		$types = (array) Reflect::call( 'disable_view_mode', array( array( 'post', 'page', 'ifi_inquiry' ) ) );

		$this->assertContains( 'post', $types );
		$this->assertNotContains( 'ifi_inquiry', $types );
	}

	public function test_bulk_actions_trash_restore_and_permanently_delete(): void {
		$id = (int) wp_insert_post(
			array(
				'post_type'   => 'ifi_inquiry',
				'post_status' => 'publish',
				'post_title'  => 'Bulk fixture',
			)
		);

		Reflect::call( 'run_bulk_action', array( 'trash', array( $id ) ) );
		$this->assertSame( 'trash', get_post_status( $id ) );

		Reflect::call( 'run_bulk_action', array( 'untrash', array( $id ) ) );
		$this->assertSame( 'publish', get_post_status( $id ) );

		Reflect::call( 'run_bulk_action', array( 'trash', array( $id ) ) );
		Reflect::call( 'run_bulk_action', array( 'delete', array( $id ) ) );
		$this->assertNull( get_post( $id ) );
	}

	public function test_bulk_delete_only_removes_trashed_records(): void {
		$id = (int) wp_insert_post(
			array(
				'post_type'   => 'ifi_inquiry',
				'post_status' => 'publish',
				'post_title'  => 'Not trashed',
			)
		);

		Reflect::call( 'run_bulk_action', array( 'delete', array( $id ) ) );

		$this->assertSame( 'publish', get_post_status( $id ) );
	}
}

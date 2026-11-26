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

	public function test_empty_trash_button_is_recognised_as_delete_all(): void {
		$original = $_REQUEST;

		try {
			$_REQUEST = array( 'delete_all' => 'Empty Trash' );
			$this->assertSame( 'delete_all', Reflect::call( 'current_bulk_action', array() ) );

			$_REQUEST = array( 'delete_all2' => 'Empty Trash' );
			$this->assertSame( 'delete_all', Reflect::call( 'current_bulk_action', array() ) );
		} finally {
			$_REQUEST = $original;
		}
	}

	public function test_inquiry_badge_follows_the_triage_order(): void {
		$today = $this->make_inquiry();
		$this->assertSame( 'new', $this->badge_state( $today ) );

		update_post_meta( $today, 'ifi_inquiry_viewed', time() );
		$this->assertSame( 'pending', $this->badge_state( $today ) );

		update_post_meta( $today, 'ifi_inquiry_contacted', time() );
		$this->assertSame( 'contacted', $this->badge_state( $today ) );

		update_post_meta( $today, 'ifi_inquiry_spam', 'honeypot' );
		$this->assertSame( 'spam', $this->badge_state( $today ) );

		$older = $this->make_inquiry( 2 );
		$this->assertSame( 'pending', $this->badge_state( $older ) );

		wp_trash_post( $today );
		$this->assertNull( Reflect::call( 'inquiry_badge', array( $today ) ) );
	}

	/** 创建一条指定天数的询盘，日期同时写 post_date 与 post_date_gmt */
	private function make_inquiry( int $days_ago = 0 ): int {
		$date = gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );
		return (int) wp_insert_post(
			array(
				'post_type'     => 'ifi_inquiry',
				'post_status'   => 'publish',
				'post_title'    => 'Badge fixture',
				'post_date'     => get_date_from_gmt( $date ),
				'post_date_gmt' => $date,
			)
		);
	}

	private function badge_state( int $post_id ): ?string {
		$badge = Reflect::call( 'inquiry_badge', array( $post_id ) );
		return is_array( $badge ) ? (string) ( $badge['state'] ?? '' ) : null;
	}
}

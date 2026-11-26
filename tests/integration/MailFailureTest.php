<?php
/**
 * Mail failure handling: what a failed send leaves behind.
 *
 * The notification and the confirmation are deliberately independent — a notification that
 * bounces must not stop the visitor from learning their message arrived, and a confirmation
 * that bounces must not mark the notification as failed. Both directions are asserted.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;
use Ifi\Tests\MailSpy;
use Ifi\Tests\Scratch;

/**
 * Covers the wp_mail_failed path through the REST endpoint.
 */
final class MailFailureTest extends IfiTestCase {

	public function test_a_failed_notification_returns_500(): void {
		MailSpy::fail_next( 'SMTP connect() failed' );

		$this->assert_error( $this->submit(), 'inquiry_mail_failed', 500 );
	}

	public function test_a_failed_notification_still_archives_the_inquiry(): void {
		MailSpy::fail_next();

		$this->submit();

		// The visitor must be able to retry, but the record must not vanish: without it the
		// site owner would never learn someone tried to reach them.
		$this->assertCount( 1, Scratch::inquiries() );
	}

	public function test_a_failed_notification_records_the_smtp_reason(): void {
		MailSpy::fail_next( 'SMTP connect() failed' );

		$this->submit();

		$post = $this->latest_inquiry();
		$this->assertSame( 'no', get_post_meta( $post->ID, 'ifi_inquiry_mail_sent', true ) );
		$this->assertSame( 'SMTP connect() failed', get_post_meta( $post->ID, 'ifi_inquiry_mail_error', true ) );
	}

	public function test_a_successful_send_records_no_error(): void {
		$this->submit();

		$post = $this->latest_inquiry();
		$this->assertSame( 'yes', get_post_meta( $post->ID, 'ifi_inquiry_mail_sent', true ) );
		$this->assertSame( '', get_post_meta( $post->ID, 'ifi_inquiry_mail_error', true ) );
	}

	public function test_a_failed_confirmation_does_not_fail_the_submission(): void {
		$this->set_autoreply( true );
		// First call is the notification, second is the confirmation.
		MailSpy::fail_on_call( 2, 'Recipient address rejected' );

		$this->assert_sent( $this->submit() );
	}

	public function test_a_failed_confirmation_is_recorded_separately(): void {
		$this->set_autoreply( true );
		MailSpy::fail_on_call( 2, 'Recipient address rejected' );

		$this->submit();

		$post = $this->latest_inquiry();
		$this->assertSame( 'yes', get_post_meta( $post->ID, 'ifi_inquiry_mail_sent', true ) );
		$this->assertSame( 'no', get_post_meta( $post->ID, 'ifi_inquiry_autoreply_sent', true ) );
		$this->assertSame(
			'Recipient address rejected',
			get_post_meta( $post->ID, 'ifi_inquiry_autoreply_error', true )
		);
	}

	public function test_the_error_from_a_previous_send_does_not_leak_into_the_next(): void {
		MailSpy::fail_next( 'First failure' );
		$this->submit();

		MailSpy::reset();
		$this->submit();

		$posts = Scratch::inquiries();
		$this->assertSame( '', get_post_meta( $posts[1]->ID, 'ifi_inquiry_mail_error', true ) );
	}

	public function test_a_failed_notification_leaves_the_duplicate_window_closed(): void {
		$this->set_spam_rules( array( 'duplicate' => 30 ) );

		MailSpy::fail_next();
		$this->submit( array( 'message' => 'Retry me' ) );

		MailSpy::reset();
		$this->assert_sent( $this->submit( array( 'message' => 'Retry me' ) ) );
		$this->assertSame( 1, MailSpy::count() );
	}
}

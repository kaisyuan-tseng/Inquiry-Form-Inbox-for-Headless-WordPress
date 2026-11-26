<?php
/**
 * Spam rules: the four content rules plus the network blocklist.
 *
 * Every rule has the same contract — the submission gets a normal success response and no
 * email, and the record is archived with the rule that caught it. The point of the tests is
 * the negative space: which rule did *not* fire, and whether a blocked submission really
 * skipped the mailer.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;
use Ifi\Tests\MailSpy;
use Ifi\Tests\Scratch;

/**
 * Covers keyword, domain, link, duplicate and IP blocking.
 */
final class SpamRulesTest extends IfiTestCase {

	public function test_a_blocked_keyword_returns_success_without_sending(): void {
		$this->set_spam_rules( array( 'keywords' => array( 'viagra' ) ) );

		$this->assert_sent( $this->submit( array( 'message' => 'Cheap viagra here' ) ) );
		$this->assertSame( 0, MailSpy::count() );
	}

	public function test_a_blocked_keyword_is_archived_with_the_reason(): void {
		$this->set_spam_rules( array( 'keywords' => array( 'viagra' ) ) );

		$this->submit( array( 'message' => 'Cheap viagra here' ) );

		$post = $this->latest_inquiry();
		$this->assertSame( 'keyword', get_post_meta( $post->ID, 'ifi_inquiry_spam', true ) );
		$this->assertSame( 'viagra', get_post_meta( $post->ID, 'ifi_inquiry_spam_detail', true ) );
	}

	public function test_a_blocked_domain_returns_success_without_sending(): void {
		$this->set_spam_rules( array( 'domains' => array( 'mailinator.com' ) ) );

		$this->assert_sent( $this->submit( array( 'email' => 'throwaway@mailinator.com' ) ) );
		$this->assertSame( 0, MailSpy::count() );
		$this->assertSame( 'domain', get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_spam', true ) );
	}

	public function test_a_subdomain_of_a_blocked_domain_is_blocked_too(): void {
		$this->set_spam_rules( array( 'domains' => array( 'mailinator.com' ) ) );

		$this->assert_sent( $this->submit( array( 'email' => 'throwaway@eu.mailinator.com' ) ) );

		$this->assertSame( 'domain', get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_spam', true ) );
	}

	public function test_too_many_links_are_blocked(): void {
		$this->set_spam_rules( array( 'max_links' => 2 ) );

		$this->assert_sent(
			$this->submit(
				array( 'message' => 'a http://x.example b http://y.example c http://z.example' )
			)
		);

		$this->assertSame( 'links', get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_spam', true ) );
		$this->assertSame( 0, MailSpy::count() );
	}

	public function test_links_within_the_limit_are_allowed(): void {
		$this->set_spam_rules( array( 'max_links' => 2 ) );

		$this->assert_sent( $this->submit( array( 'message' => 'a http://x.example b http://y.example' ) ) );

		$this->assertSame( 1, MailSpy::count() );
		$this->assertSame( '', get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_spam', true ) );
	}

	public function test_a_duplicate_inside_the_window_is_blocked(): void {
		$this->set_spam_rules( array( 'duplicate' => 30 ) );

		$this->assert_sent( $this->submit( array( 'message' => 'Identical message' ) ) );
		$this->assert_sent( $this->submit( array( 'message' => 'Identical message' ) ) );

		$this->assertCount( 2, Scratch::inquiries() );
		$this->assertSame( 'duplicate', get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_spam', true ) );
		$this->assertSame( 1, MailSpy::count() );
	}

	public function test_a_different_message_is_not_a_duplicate(): void {
		$this->set_spam_rules( array( 'duplicate' => 30 ) );

		$this->submit( array( 'message' => 'First message' ) );
		$this->submit( array( 'message' => 'Second message' ) );

		$this->assertSame( 2, MailSpy::count() );
	}

	public function test_the_duplicate_window_is_only_armed_after_a_successful_send(): void {
		$this->set_spam_rules( array( 'duplicate' => 30 ) );

		MailSpy::fail_next();
		$this->submit( array( 'message' => 'Retried message' ) );

		// The first attempt failed to deliver, so the visitor's retry must not be judged a
		// duplicate of their own failure.
		MailSpy::reset();
		$this->assert_sent( $this->submit( array( 'message' => 'Retried message' ) ) );
		$this->assertSame( 1, MailSpy::count() );
		$this->assertSame( '', get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_spam', true ) );
	}

	public function test_a_blocked_ip_is_dropped_without_leaving_a_record(): void {
		$this->set_spam_rules( array( 'ips' => array( '127.0.0.1' ) ) );

		$this->assert_sent( $this->submit() );

		$this->assertCount( 0, Scratch::inquiries() );
		$this->assertSame( 0, MailSpy::count() );
	}

	public function test_a_blocked_ip_still_returns_success_so_a_bot_learns_nothing(): void {
		$this->set_spam_rules( array( 'ips' => array( '127.0.0.0/8' ) ) );

		$response = $this->submit();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'sent' => true ), $response->get_data() );
	}

	public function test_turning_off_archive_drops_a_blocked_submission_without_a_record(): void {
		$this->set_spam_rules(
			array(
				'keywords' => array( 'viagra' ),
				'archive'  => '0',
			)
		);

		$this->assert_sent( $this->submit( array( 'message' => 'Cheap viagra' ) ) );

		$this->assertCount( 0, Scratch::inquiries() );
		$this->assertSame( 0, MailSpy::count() );
	}

	public function test_a_clean_submission_is_delivered_even_with_rules_configured(): void {
		$this->set_spam_rules(
			array(
				'keywords'  => array( 'viagra' ),
				'domains'   => array( 'mailinator.com' ),
				'max_links' => 5,
				'duplicate' => 30,
			)
		);

		$this->assert_sent(
			$this->submit(
				array(
					'email'   => 'buyer@example.com',
					'message' => 'Please quote 500 units. https://example.com/product',
				)
			)
		);

		$this->assertSame( 1, MailSpy::count() );
		$this->assertSame( '', get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_spam', true ) );
	}

	public function test_a_custom_verdict_filter_can_block_a_submission(): void {
		add_filter(
			'ifi_spam_verdict',
			static function ( $verdict, $fields ) {
				if ( 'custom-rule' === ( $fields['message'] ?? '' ) ) {
					return array(
						'reason' => 'custom',
						'hit'    => 'custom-rule',
					);
				}
				return $verdict;
			},
			10,
			2
		);

		$this->assert_sent( $this->submit( array( 'message' => 'custom-rule' ) ) );

		$this->assertSame( 0, MailSpy::count() );
		$this->assertSame( 'custom', get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_spam', true ) );
	}

	public function test_spam_rules_can_be_replaced_by_a_filter(): void {
		add_filter(
			'ifi_spam_rules',
			static function () {
				return array( 'keywords' => array( 'forbidden-phrase' ) );
			}
		);

		$this->assert_sent( $this->submit( array( 'message' => 'contains forbidden-phrase here' ) ) );

		$this->assertSame( 0, MailSpy::count() );
	}

	public function test_a_spam_record_is_kept_out_of_the_delivery_status(): void {
		$this->set_spam_rules( array( 'keywords' => array( 'viagra' ) ) );

		$this->submit( array( 'message' => 'viagra' ) );

		$post = $this->latest_inquiry();
		$this->assertSame( '', get_post_meta( $post->ID, 'ifi_inquiry_mail_sent', true ) );
		$this->assertSame( '', get_post_meta( $post->ID, 'ifi_inquiry_autoreply_sent', true ) );
	}
}

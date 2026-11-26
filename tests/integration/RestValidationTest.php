<?php
/**
 * Validation: required fields, email syntax, length caps and the honeypot.
 *
 * The contract with the front end is that a 400 means "fix the payload" and a 200 means
 * "we took it" — including for spam, where a 200 is deliberate so a bot cannot tell it was
 * blocked. These tests pin both halves of that down.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;
use Ifi\Tests\MailSpy;
use Ifi\Tests\Scratch;

/**
 * Covers the rejection paths of the REST endpoint.
 */
final class RestValidationTest extends IfiTestCase {

	public function test_a_missing_required_field_returns_400(): void {
		$response = $this->submit( array( 'fullName' => '' ) );

		$this->assert_error( $response, 'inquiry_missing_field', 400 );
		$this->assertCount( 0, Scratch::inquiries() );
		$this->assertSame( 0, MailSpy::count() );
	}

	public function test_a_missing_field_is_named_in_the_error(): void {
		$message = $this->assert_error( $this->submit( array( 'message' => '' ) ), 'inquiry_missing_field', 400 );

		$this->assertStringContainsString( 'message', $message );
	}

	public function test_an_invalid_email_returns_400(): void {
		$response = $this->submit( array( 'email' => 'not-an-email' ) );

		$this->assert_error( $response, 'inquiry_invalid_email', 400 );
		$this->assertCount( 0, Scratch::inquiries() );
	}

	public function test_the_required_field_list_is_filterable(): void {
		add_filter(
			'ifi_required_fields',
			static function () {
				return array( 'fullName', 'email', 'message', 'application' );
			}
		);

		$message = $this->assert_error( $this->submit(), 'inquiry_missing_field', 400 );

		$this->assertStringContainsString( 'application', $message );
	}

	public function test_a_long_message_is_truncated_to_the_documented_limit(): void {
		$this->submit( array( 'message' => str_repeat( 'a', 2500 ) ) );

		$values = get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_field_values', true );
		$this->assertSame( 2000, mb_strlen( $values['message'] ) );
	}

	public function test_a_long_name_is_truncated_to_the_documented_limit(): void {
		$this->submit( array( 'fullName' => str_repeat( 'b', 200 ) ) );

		$values = get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_field_values', true );
		$this->assertSame( 80, mb_strlen( $values['fullName'] ) );
	}

	public function test_multibyte_truncation_does_not_split_a_character(): void {
		$this->submit( array( 'message' => str_repeat( '询', 2500 ) ) );

		$values = get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_field_values', true );
		$this->assertSame( 2000, mb_strlen( $values['message'] ) );
		$this->assertTrue( mb_check_encoding( $values['message'], 'UTF-8' ) );
	}

	public function test_a_filled_honeypot_returns_success_without_sending(): void {
		$response = $this->submit( array( 'companyWebsite' => 'http://spam.example' ) );

		$this->assert_sent( $response );
		$this->assertSame( 0, MailSpy::count() );
	}

	public function test_a_filled_honeypot_is_archived_in_the_spam_view(): void {
		$this->submit( array( 'companyWebsite' => 'http://spam.example' ) );

		$posts = Scratch::inquiries();
		$this->assertCount( 1, $posts );
		$this->assertSame( 'honeypot', get_post_meta( $posts[0]->ID, 'ifi_inquiry_spam', true ) );
	}

	public function test_a_filled_honeypot_records_no_delivery_state(): void {
		$this->submit( array( 'companyWebsite' => 'http://spam.example' ) );

		$post = $this->latest_inquiry();
		$this->assertSame( '', get_post_meta( $post->ID, 'ifi_inquiry_mail_sent', true ) );
		$this->assertSame( '', get_post_meta( $post->ID, 'ifi_inquiry_autoreply_sent', true ) );
	}

	public function test_an_empty_honeypot_is_treated_as_a_real_submission(): void {
		$this->assert_sent( $this->submit( array( 'companyWebsite' => '' ) ) );

		$this->assertSame( 1, MailSpy::count() );
		$this->assertSame( '', get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_spam', true ) );
	}

	public function test_the_honeypot_can_be_turned_off(): void {
		update_option( 'ifi_spam_rules', array( 'honeypot' => '0' ), false );

		$this->assert_sent( $this->submit( array( 'companyWebsite' => 'http://spam.example' ) ) );
		$this->assertSame( 1, MailSpy::count() );
		$this->assertSame( '', get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_spam', true ) );
	}

	public function test_the_honeypot_field_name_is_filterable(): void {
		add_filter(
			'ifi_honeypot_field',
			static function () {
				return 'website_url';
			}
		);

		$this->assert_sent( $this->submit( array( 'website_url' => 'filled' ) ) );
		$this->assertSame( 0, MailSpy::count() );
	}

	public function test_the_honeypot_field_name_can_be_changed_on_the_block_rules_screen(): void {
		update_option(
			'ifi_spam_rules',
			array(
				'honeypot'       => '1',
				'honeypot_field' => 'website_url',
			),
			false
		);

		$this->assert_sent( $this->submit( array( 'website_url' => 'filled' ) ) );
		$this->assertSame( 0, MailSpy::count() );
	}

	public function test_a_form_field_cannot_be_used_as_the_honeypot_name(): void {
		// pageTitle 通常每条提交都非空：真让它当蜜罐，正常询盘会被静默丢光
		update_option(
			'ifi_spam_rules',
			array(
				'honeypot'       => '1',
				'honeypot_field' => 'pageTitle',
			),
			false
		);

		$this->assert_sent( $this->submit( array( 'pageTitle' => 'Flexible Gooseneck Tube' ) ) );

		$this->assertSame( 1, MailSpy::count() );
		$this->assertSame( '', get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_spam', true ) );
	}

	public function test_an_invalid_timezone_is_dropped_rather_than_stored(): void {
		$this->submit( array( 'timezone' => 'Asia/Shanghia' ) );

		$post = $this->latest_inquiry();
		$this->assertSame( '', get_post_meta( $post->ID, 'ifi_inquiry_timezone', true ) );
	}

	public function test_an_invalid_browser_language_is_dropped_rather_than_stored(): void {
		$this->submit( array( 'browserLanguage' => 'not a language!!' ) );

		$post = $this->latest_inquiry();
		$this->assertSame( '', get_post_meta( $post->ID, 'ifi_inquiry_lang', true ) );
	}

	public function test_a_client_supplied_ip_is_ignored(): void {
		$this->submit( array( 'ip' => '203.0.113.99' ) );

		$post = $this->latest_inquiry();
		$this->assertSame( '127.0.0.1', get_post_meta( $post->ID, 'ifi_inquiry_ip', true ) );
	}
}

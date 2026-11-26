<?php
/**
 * The happy path: a well-formed submission is archived, numbered and emailed.
 *
 * This is the one flow every other feature hangs off, so the assertions are deliberately
 * specific about the stored shape — a record that exists but has no inquiry number or no
 * field values is a silent regression that only shows up weeks later in the admin list.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;
use Ifi\Tests\MailSpy;
use Ifi\Tests\Scratch;

/**
 * Covers POST /inquiry-form-inbox/v1/inquiry on the success path.
 */
final class RestSubmitTest extends IfiTestCase {

	public function test_a_valid_submission_returns_sent_true(): void {
		$this->assert_sent( $this->submit() );
	}

	public function test_a_valid_submission_creates_one_archived_record(): void {
		$this->submit();

		$posts = Scratch::inquiries();
		$this->assertCount( 1, $posts );
		$this->assertSame( 'publish', $posts[0]->post_status );
		$this->assertSame( 'Test Buyer', $posts[0]->post_title );
	}

	public function test_the_record_carries_the_documented_meta(): void {
		$this->submit(
			array(
				'pageUrl'         => 'https://example.test/products/gooseneck/',
				'pageTitle'       => 'Gooseneck tube',
				'pageTitleEn'     => 'Gooseneck Tube',
				'formVariant'     => 'modal',
				'locale'          => 'zh-hans',
				'browserLanguage' => 'zh-CN',
				'timezone'        => 'Asia/Shanghai',
				'fieldLabels'     => array(
					'fullName' => 'Name',
					'message'  => 'Message',
				),
			)
		);

		$post = $this->latest_inquiry();
		$this->assertNotNull( $post );

		$this->assertSame( 'buyer@example.com', get_post_meta( $post->ID, 'ifi_inquiry_email', true ) );
		$this->assertSame( '127.0.0.1', get_post_meta( $post->ID, 'ifi_inquiry_ip', true ) );
		$this->assertSame( 'zh-CN', get_post_meta( $post->ID, 'ifi_inquiry_lang', true ) );
		$this->assertSame( 'Asia/Shanghai', get_post_meta( $post->ID, 'ifi_inquiry_timezone', true ) );
		$this->assertSame( 'modal', get_post_meta( $post->ID, 'ifi_inquiry_variant', true ) );
		$this->assertSame( 'zh-hans', get_post_meta( $post->ID, 'ifi_inquiry_locale', true ) );
		$this->assertSame(
			'https://example.test/products/gooseneck/',
			get_post_meta( $post->ID, 'ifi_inquiry_page_url', true )
		);
		$this->assertSame( 'Gooseneck Tube', get_post_meta( $post->ID, 'ifi_inquiry_page_title_en', true ) );
	}

	public function test_the_submitted_field_values_are_stored_separately_from_the_body(): void {
		$this->submit(
			array(
				'fullName'    => 'Test Buyer',
				'message'     => 'Please quote 500 units.',
				'application' => 'Industrial robot',
			)
		);

		$post   = $this->latest_inquiry();
		$values = get_post_meta( $post->ID, 'ifi_inquiry_field_values', true );

		$this->assertIsArray( $values );
		$this->assertSame( 'Test Buyer', $values['fullName'] );
		$this->assertSame( 'Please quote 500 units.', $values['message'] );
		$this->assertSame( 'Industrial robot', $values['application'] );
		// Environment fields are not user input and must not be exported as such.
		$this->assertArrayNotHasKey( 'ip', $values );
	}

	public function test_environment_fields_are_not_listed_as_form_fields(): void {
		$this->submit(
			array(
				'pageTitle'       => 'Contact',
				'browserLanguage' => 'en-US',
			)
		);

		$post   = $this->latest_inquiry();
		$fields = get_post_meta( $post->ID, 'ifi_inquiry_fields', true );

		$this->assertIsArray( $fields );
		$this->assertContains( 'fullName', $fields );
		$this->assertNotContains( 'pageTitle', $fields );
		$this->assertNotContains( 'browserLanguage', $fields );
	}

	public function test_the_inquiry_number_matches_the_documented_format(): void {
		$this->submit();

		$no = (string) get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_no', true );

		// The date half must come from current_time(), not date(): the sequence option is
		// keyed on site time, so a test that used date() would break across midnight.
		$this->assertSame( 'IQ-' . current_time( 'Ymd' ) . '001', $no );
		$this->assertMatchesRegularExpression( '/^IQ-\d{11}$/', $no );
	}

	public function test_inquiry_numbers_increase_within_the_same_day(): void {
		$this->submit();
		$this->submit();
		$this->submit();

		$numbers = array_map(
			static function ( $post ) {
				return (string) get_post_meta( $post->ID, 'ifi_inquiry_no', true );
			},
			Scratch::inquiries()
		);

		$day = current_time( 'Ymd' );
		$this->assertSame(
			array( 'IQ-' . $day . '001', 'IQ-' . $day . '002', 'IQ-' . $day . '003' ),
			$numbers
		);
	}

	public function test_the_notification_is_sent_to_the_admin_address(): void {
		$this->submit();

		$this->assertSame( 1, MailSpy::count() );
		$mail = MailSpy::last();
		$this->assertStringContainsString( 'inbox@example.test', (string) $mail['to'] );
		$this->assertStringContainsString( 'Test Buyer', $mail['subject'] );
	}

	public function test_the_recorded_delivery_state_matches_the_send_result(): void {
		$this->submit();

		$post = $this->latest_inquiry();
		$this->assertSame( 'yes', get_post_meta( $post->ID, 'ifi_inquiry_mail_sent', true ) );
		// Auto-confirmation is off by default, so its state must stay unset rather than
		// recording a failure the site owner never asked for.
		$this->assertSame( '', get_post_meta( $post->ID, 'ifi_inquiry_autoreply_sent', true ) );
	}

	public function test_the_stored_body_contains_the_submitted_values_and_labels(): void {
		$this->submit(
			array(
				'fullName'    => 'Zhang Wei',
				'message'     => 'Need 2000 pcs',
				'fieldLabels' => array( 'fullName' => '姓名' ),
			)
		);

		$body = $this->latest_inquiry()->post_content;

		$this->assertStringContainsString( '姓名', $body );
		$this->assertStringContainsString( 'Zhang Wei', $body );
		$this->assertStringContainsString( 'Need 2000 pcs', $body );
	}

	public function test_a_submission_without_optional_fields_still_succeeds(): void {
		$this->assert_sent(
			$this->submit(
				array(
					'fullName' => 'Minimal',
					'email'    => 'minimal@example.com',
					'message'  => 'hi',
				)
			)
		);
	}

	public function test_custom_fields_are_passed_through_by_name(): void {
		$this->submit(
			array(
				'outerDiameter' => '12mm',
				'quantity'      => '500',
				'quantityUnit'  => 'PCS',
			)
		);

		$values = get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_field_values', true );

		$this->assertSame( '12mm', $values['outerDiameter'] );
		$this->assertSame( '500', $values['quantity'] );
		// The unit is merged into the value for display but still stored separately.
		$this->assertSame( 'PCS', $values['quantityUnit'] );
	}

	public function test_html_in_a_field_is_stripped_before_storage(): void {
		$this->submit( array( 'message' => 'Hello <script>alert(1)</script> world' ) );

		$values = get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_field_values', true );

		$this->assertStringNotContainsString( '<script', $values['message'] );
		$this->assertStringContainsString( 'Hello', $values['message'] );
	}
}

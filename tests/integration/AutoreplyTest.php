<?php
/**
 * Auto-confirmation: who gets a second email, and in which language.
 *
 * The per-language part is the interesting half. A language group changes the template, and
 * since 3.0.15 it also changes the locale used for the fixed labels — before that, the
 * subject fell back to English while the body was Chinese.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;
use Ifi\Tests\MailSpy;

/**
 * Covers send_autoreply(), reply_group() and locale switching during render.
 */
final class AutoreplyTest extends IfiTestCase {

	/**
	 * Build one language group with an HTML body.
	 *
	 * @param string $lang    Canonical language tag, '' for the default group.
	 * @param string $subject Subject template.
	 * @param string $html    HTML body.
	 * @return array
	 */
	private function group( string $lang, string $subject = '', string $html = '<p>Hello {{rows.fullName.value}}</p>' ): array {
		return array(
			'lang'    => $lang,
			'subject' => $subject,
			'html'    => $html,
			'labels'  => array(),
		);
	}

	public function test_auto_confirmation_is_off_by_default(): void {
		$this->submit();

		$this->assertSame( 1, MailSpy::count() );
		$this->assertSame( '', get_post_meta( $this->latest_inquiry()->ID, 'ifi_inquiry_autoreply_sent', true ) );
	}

	public function test_auto_confirmation_sends_a_second_email_to_the_visitor(): void {
		$this->set_autoreply( true );

		$this->submit( array( 'email' => 'visitor@example.com' ) );

		$this->assertSame( 2, MailSpy::count() );
		$this->assertNotNull( MailSpy::last_to( 'visitor@example.com' ) );
	}

	public function test_the_confirmation_records_its_own_delivery_state(): void {
		$this->set_autoreply( true );

		$this->submit();

		$post = $this->latest_inquiry();
		$this->assertSame( 'yes', get_post_meta( $post->ID, 'ifi_inquiry_mail_sent', true ) );
		$this->assertSame( 'yes', get_post_meta( $post->ID, 'ifi_inquiry_autoreply_sent', true ) );
	}

	public function test_the_confirmation_replies_to_the_inquiry_inbox(): void {
		$this->set_autoreply( true );

		$this->submit();

		$mail    = MailSpy::last();
		$headers = is_array( $mail['headers'] ) ? implode( "\n", $mail['headers'] ) : (string) $mail['headers'];

		$this->assertStringContainsString( 'inbox@example.test', $headers );
	}

	public function test_the_confirmation_carries_no_attachments(): void {
		$this->set_autoreply( true );

		$this->submit();

		$mail = MailSpy::last();
		$this->assertSame( array(), (array) $mail['attachments'] );
	}

	public function test_a_language_group_supplies_the_subject(): void {
		$this->set_autoreply(
			true,
			array(
				$this->group( '', 'Default subject' ),
				$this->group( 'zh-hans', '我们已收到您的询盘' ),
			)
		);

		$this->submit( array( 'browserLanguage' => 'zh-CN' ) );

		$this->assertSame( '我们已收到您的询盘', MailSpy::last()['subject'] );
	}

	public function test_the_default_group_is_used_when_no_language_matches(): void {
		$this->set_autoreply(
			true,
			array(
				$this->group( '', 'Default subject' ),
				$this->group( 'zh-hans', '我们已收到您的询盘' ),
			)
		);

		$this->submit( array( 'browserLanguage' => 'fr-FR' ) );

		$this->assertSame( 'Default subject', MailSpy::last()['subject'] );
	}

	public function test_an_empty_subject_falls_back_to_the_visitor_language(): void {
		$this->set_autoreply( true );

		$this->submit( array( 'browserLanguage' => 'en-US' ) );

		$this->assertSame( 'We received your inquiry', MailSpy::last()['subject'] );
	}

	public function test_the_fallback_subject_follows_a_chinese_visitor(): void {
		// A group with a language tag but no subject: the subject has to come from the
		// dictionary, and it has to come from the visitor's locale.
		$this->set_autoreply(
			true,
			array( $this->group( 'zh-hans', '' ) )
		);

		$this->submit( array( 'browserLanguage' => 'zh-CN' ) );

		// This is the regression 3.0.15 fixed: the template switched language but the
		// fallback subject stayed in the site language.
		$this->assertSame( '已收到您的询盘', MailSpy::last()['subject'] );
	}

	public function test_a_traditional_group_falls_back_to_english_without_a_zh_tw_pack(): void {
		// zh_TW was removed deliberately in the terminology pass. The language group still
		// matches and the template still renders — gettext simply has no Traditional
		// catalogue, so its fixed labels fall back to the English source. Asserting that
		// keeps the removal from turning into a silent crash later.
		$this->set_autoreply(
			true,
			array( $this->group( 'zh-hant' ) )
		);

		$this->submit( array( 'browserLanguage' => 'zh-TW' ) );

		$this->assertSame( 'We received your inquiry', MailSpy::last()['subject'] );
	}

	public function test_the_body_echoes_the_submitted_values(): void {
		$this->set_autoreply( true );

		$this->submit( array( 'fullName' => 'Echo Me' ) );

		$this->assertStringContainsString( 'Echo Me', MailSpy::last()['message'] );
	}

	public function test_the_site_locale_is_restored_after_rendering(): void {
		$before = get_locale();

		$this->set_autoreply(
			true,
			array(
				$this->group( '' ),
				$this->group( 'zh-hans', '中文主题' ),
			)
		);
		$this->submit( array( 'browserLanguage' => 'zh-CN' ) );

		$this->assertSame( $before, get_locale() );
	}

	public function test_the_autoreply_action_fires_with_the_delivery_result(): void {
		$captured = array();
		add_action(
			'ifi_autoreply_sent',
			static function ( $sent, $email, $fields ) use ( &$captured ) {
				$captured[] = array( $sent, $email, $fields );
			},
			10,
			3
		);

		$this->set_autoreply( true );
		$this->submit( array( 'email' => 'visitor@example.com' ) );

		$this->assertCount( 1, $captured );
		$this->assertTrue( $captured[0][0] );
		$this->assertSame( 'visitor@example.com', $captured[0][1] );
		$this->assertSame( 'Test Buyer', $captured[0][2]['fullName'] );
	}

	public function test_an_empty_default_group_produces_no_subject_override(): void {
		// The default group carries no language tag, so there is no locale to switch to and
		// the site language applies. Asserting that explicitly keeps the behaviour from
		// drifting into "the default group is always English".
		$this->set_autoreply( true );

		$this->submit( array( 'browserLanguage' => 'zh-CN' ) );

		$this->assertSame( 'We received your inquiry', MailSpy::last()['subject'] );
	}
}

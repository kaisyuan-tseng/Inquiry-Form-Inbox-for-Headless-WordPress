<?php
/**
 * Input normalisation: spam rule structures, multi-line lists, duplicate keys, env fields.
 *
 * Every one of these runs on data a visitor or an administrator typed, and the result is
 * what the matchers act on. A normaliser that quietly drops a rule makes the settings
 * screen lie to the site owner, so the assertions here are mostly about what survives.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Unit;

use Ifi\Tests\Reflect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers normalize_spam_rules(), spam_lines(), spam_duplicate_key(),
 * normalize_env_fields() and the read-only half of spam_verdict().
 */
final class SanitizeTest extends TestCase {

	/**
	 * List input / expected normalized list.
	 *
	 * @return array<string, array{0: mixed, 1: string[]}>
	 */
	public static function lineCases(): array {
		return array(
			'array of lines'    => array( array( 'a', 'b' ), array( 'a', 'b' ) ),
			'crlf string'       => array( "a\r\nb", array( 'a', 'b' ) ),
			'lf string'         => array( "a\nb", array( 'a', 'b' ) ),
			'cr string'         => array( "a\rb", array( 'a', 'b' ) ),
			'blank lines'       => array( "a\n\n\nb\n", array( 'a', 'b' ) ),
			'whitespace only'   => array( array( '  ', "\t" ), array() ),
			'trims each line'   => array( array( '  a  ', "\tb\t" ), array( 'a', 'b' ) ),
			'dedupes exact'     => array( array( 'a', 'a', 'b' ), array( 'a', 'b' ) ),
			'non scalar'        => array( array( 'a', array( 'nested' ), 3 ), array( 'a', '3' ) ),
			'empty array'       => array( array(), array() ),
		);
	}

	#[DataProvider( 'lineCases' )]
	public function test_spam_lines( $input, array $expected ): void {
		$this->assertSame( $expected, Reflect::call( 'spam_lines', array( $input ) ) );
	}

	public function test_normalize_spam_rules_keeps_invalid_rules_for_the_settings_screen(): void {
		// A rule that can never match is kept on purpose: the screen lists it as
		// "will never match" so the owner can see and fix the typo. Silently deleting it
		// would look like the rule was in effect.
		$rules = Reflect::call(
			'normalize_spam_rules',
			array(
				array(
					'ips'     => array( '300.1.1.1', '203.0.113.0/24' ),
					'domains' => array( 'gmail', 'example.com' ),
				),
			)
		);

		$this->assertContains( '300.1.1.1', $rules['ips'] );
		$this->assertContains( 'gmail', $rules['domains'] );
	}

	public function test_normalize_spam_rules_folds_checkboxes_to_one_or_zero(): void {
		$on  = Reflect::call( 'normalize_spam_rules', array( array( 'honeypot' => '1', 'archive' => 1 ) ) );
		$off = Reflect::call( 'normalize_spam_rules', array( array() ) );

		$this->assertSame( '1', $on['honeypot'] );
		$this->assertSame( '1', $on['archive'] );
		$this->assertSame( '0', $off['honeypot'] );
		$this->assertSame( '0', $off['archive'] );
	}

	public function test_normalize_spam_rules_dedupes_keywords_case_insensitively(): void {
		$rules = Reflect::call(
			'normalize_spam_rules',
			array( array( 'keywords' => array( 'Viagra', 'viagra', 'VIAGRA', 'casino' ) ) )
		);

		$this->assertSame( array( 'Viagra', 'casino' ), $rules['keywords'] );
	}

	public function test_normalize_spam_rules_lowercases_and_unprefixes_domains(): void {
		$rules = Reflect::call(
			'normalize_spam_rules',
			array( array( 'domains' => array( '@Example.com', 'MAIL.example.com', 'example.com' ) ) )
		);

		$this->assertSame( array( 'example.com', 'mail.example.com' ), $rules['domains'] );
	}

	public function test_normalize_spam_rules_clamps_numeric_limits(): void {
		$rules = Reflect::call(
			'normalize_spam_rules',
			array(
				array(
					'max_links' => -5,
					'duplicate' => '30',
				),
			)
		);

		$this->assertSame( 0, $rules['max_links'] );
		$this->assertSame( 30, $rules['duplicate'] );
	}

	public function test_normalize_spam_rules_accepts_textarea_strings(): void {
		$rules = Reflect::call(
			'normalize_spam_rules',
			array(
				array(
					'keywords' => "one\ntwo\n\n  three  ",
					'ips'      => "203.0.113.1\r\n10.0.0.0/8",
				),
			)
		);

		$this->assertSame( array( 'one', 'two', 'three' ), $rules['keywords'] );
		$this->assertSame( array( '203.0.113.1', '10.0.0.0/8' ), $rules['ips'] );
	}

	public function test_normalize_spam_rules_sanitizes_the_honeypot_field_name(): void {
		$clean = Reflect::call(
			'normalize_spam_rules',
			array( array( 'honeypot_field' => ' company Website! ' ) )
		);

		$this->assertSame( 'companyWebsite', $clean['honeypot_field'] );
	}

	public function test_normalize_spam_rules_falls_back_when_the_honeypot_field_is_unusable(): void {
		$empty = Reflect::call( 'normalize_spam_rules', array( array( 'honeypot_field' => '' ) ) );
		$junk  = Reflect::call( 'normalize_spam_rules', array( array( 'honeypot_field' => '///' ) ) );

		$this->assertSame( 'companyWebsite', $empty['honeypot_field'] );
		$this->assertSame( 'companyWebsite', $junk['honeypot_field'] );
	}

	public function test_normalize_spam_rules_rejects_a_form_field_as_the_honeypot(): void {
		// pageTitle 通常非空：拿它当蜜罐会把每一条正常询盘都判成机器人
		$reserved = Reflect::call( 'normalize_spam_rules', array( array( 'honeypot_field' => 'pageTitle' ) ) );
		$required = Reflect::call( 'normalize_spam_rules', array( array( 'honeypot_field' => 'email' ) ) );

		$this->assertSame( 'companyWebsite', $reserved['honeypot_field'] );
		$this->assertSame( 'companyWebsite', $required['honeypot_field'] );
	}

	public function test_normalize_spam_rules_always_returns_every_key(): void {
		$rules = Reflect::call( 'normalize_spam_rules', array( array() ) );

		foreach ( array( 'honeypot', 'honeypot_field', 'archive', 'keywords', 'domains', 'ips', 'max_links', 'duplicate' ) as $key ) {
			$this->assertArrayHasKey( $key, $rules );
		}
	}

	public function test_duplicate_key_ignores_email_case_and_message_whitespace(): void {
		$first  = Reflect::call(
			'spam_duplicate_key',
			array(
				array(
					'email'   => 'Someone@Example.com',
					'message' => 'Hello   there',
				),
			)
		);
		$second = Reflect::call(
			'spam_duplicate_key',
			array(
				array(
					'email'   => 'someone@example.com',
					'message' => "Hello\nthere",
				),
			)
		);

		$this->assertSame( $first, $second );
		$this->assertStringStartsWith( 'ifi_dup_', $first );
	}

	public function test_duplicate_key_is_empty_without_email_or_message(): void {
		$this->assertSame( '', Reflect::call( 'spam_duplicate_key', array( array( 'message' => 'hi' ) ) ) );
		$this->assertSame( '', Reflect::call( 'spam_duplicate_key', array( array( 'email' => 'a@b.com' ) ) ) );
	}

	public function test_duplicate_key_differs_for_different_messages(): void {
		$a = Reflect::call(
			'spam_duplicate_key',
			array(
				array(
					'email'   => 'a@example.com',
					'message' => 'one',
				),
			)
		);
		$b = Reflect::call(
			'spam_duplicate_key',
			array(
				array(
					'email'   => 'a@example.com',
					'message' => 'two',
				),
			)
		);

		$this->assertNotSame( $a, $b );
	}

	public function test_normalize_env_fields_keeps_valid_values(): void {
		$fields = Reflect::call(
			'normalize_env_fields',
			array(
				array(
					'browserLanguage' => 'zh-CN',
					'timezone'        => 'Asia/Shanghai',
					'fullName'        => 'Someone',
				),
			)
		);

		$this->assertSame( 'zh-CN', $fields['browserLanguage'] );
		$this->assertSame( 'Asia/Shanghai', $fields['timezone'] );
		$this->assertSame( 'Someone', $fields['fullName'] );
	}

	public function test_normalize_env_fields_drops_invalid_values(): void {
		$fields = Reflect::call(
			'normalize_env_fields',
			array(
				array(
					'browserLanguage' => 'not a language!!',
					'timezone'        => 'Asia/Shanghia',
					'fullName'        => 'Someone',
				),
			)
		);

		$this->assertArrayNotHasKey( 'browserLanguage', $fields );
		$this->assertArrayNotHasKey( 'timezone', $fields );
		$this->assertSame( 'Someone', $fields['fullName'] );
	}

	public function test_normalize_env_fields_always_discards_a_client_supplied_ip(): void {
		$fields = Reflect::call(
			'normalize_env_fields',
			array(
				array(
					'ip'       => '1.2.3.4',
					'fullName' => 'Someone',
				),
			)
		);

		$this->assertArrayNotHasKey( 'ip', $fields );
	}

	public function test_normalize_env_fields_truncates_overlong_language_tag(): void {
		$long   = str_repeat( 'en-', 20 ) . 'us';
		$fields = Reflect::call( 'normalize_env_fields', array( array( 'browserLanguage' => $long ) ) );

		$this->assertLessThanOrEqual( 35, strlen( $fields['browserLanguage'] ) );
	}

	/**
	 * Field set / expected spam reason.
	 *
	 * @return array<string, array{0: array, 1: string, 2: string}>
	 */
	public static function verdictCases(): array {
		return array(
			'keyword in name' => array(
				array(
					'fullName' => 'viagra seller',
					'email'    => 'a@example.com',
					'message'  => 'hello',
				),
				'keyword',
				'viagra',
			),
			'keyword case insensitive' => array(
				array(
					'email'   => 'a@example.com',
					'message' => 'Buy Cialis now',
				),
				'keyword',
				'cialis',
			),
			'blocked domain' => array(
				array(
					'email'   => 'a@mailinator.com',
					'message' => 'hello',
				),
				'domain',
				'mailinator.com',
			),
			'too many links' => array(
				array(
					'email'   => 'a@example.com',
					'message' => 'a http://x.example b http://y.example c http://z.example',
				),
				'links',
				'3',
			),
		);
	}

	#[DataProvider( 'verdictCases' )]
	public function test_spam_verdict_flags( array $fields, string $reason, string $hit ): void {
		$rules = Reflect::call(
			'normalize_spam_rules',
			array(
				array(
					'keywords'  => array( 'viagra', 'cialis' ),
					'domains'   => array( 'mailinator.com' ),
					'max_links' => 2,
					'duplicate' => 0,
				),
			)
		);

		$verdict = Reflect::call( 'spam_verdict', array( $fields, $rules ) );
		$this->assertSame( $reason, $verdict['reason'] );
		$this->assertSame( $hit, $verdict['hit'] );
	}

	public function test_spam_verdict_passes_a_clean_submission(): void {
		$rules = Reflect::call(
			'normalize_spam_rules',
			array(
				array(
					'keywords'  => array( 'viagra' ),
					'domains'   => array( 'mailinator.com' ),
					'max_links' => 2,
				),
			)
		);

		$verdict = Reflect::call(
			'spam_verdict',
			array(
				array(
					'fullName' => 'Real Buyer',
					'email'    => 'buyer@example.com',
					'message'  => 'Please quote 500 units. https://example.com/product',
				),
				$rules,
			)
		);

		$this->assertSame( '', $verdict['reason'] );
		$this->assertSame( '', $verdict['hit'] );
	}

	public function test_spam_verdict_does_not_scan_the_email_address_for_keywords(): void {
		// An "info" keyword rule must not block info@example.com: the address is matched by
		// domain only. This is the bug the email exclusion in spam_scanned_text() prevents.
		$rules = Reflect::call( 'normalize_spam_rules', array( array( 'keywords' => array( 'info' ) ) ) );

		$verdict = Reflect::call(
			'spam_verdict',
			array(
				array(
					'fullName' => 'Real Buyer',
					'email'    => 'info@example.com',
					'message'  => 'hello',
				),
				$rules,
			)
		);

		$this->assertSame( '', $verdict['reason'] );
	}

	public function test_spam_verdict_skips_environment_fields(): void {
		$rules = Reflect::call( 'normalize_spam_rules', array( array( 'keywords' => array( 'shop' ) ) ) );

		$verdict = Reflect::call(
			'spam_verdict',
			array(
				array(
					'email'     => 'buyer@example.com',
					'message'   => 'hello',
					'pageTitle' => 'Our shop',
					'pageUrl'   => 'https://example.com/shop/',
				),
				$rules,
			)
		);

		$this->assertSame( '', $verdict['reason'] );
	}
}

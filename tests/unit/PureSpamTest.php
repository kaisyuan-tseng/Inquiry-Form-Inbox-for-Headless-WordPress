<?php
/**
 * Spam rule matching: addresses, CIDR ranges, domains and link counting.
 *
 * These are the only rules that decide whether a submission is dropped before it reaches
 * the database, so the edge cases matter more than the happy path — a /33 that silently
 * matches everything, or a suffix comparison that treats `notexample.com` as
 * `example.com`, would be a data-loss bug rather than a cosmetic one.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Unit;

use Ifi\Tests\Reflect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers spam_ip_match(), spam_ip_valid(), spam_domain_match(), spam_domain_valid(),
 * spam_link_count() and spam_email_domain().
 */
final class PureSpamTest extends TestCase {

	/**
	 * IP / rule pairs that must match.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function matchingPairs(): array {
		return array(
			'ipv4 exact'            => array( '203.0.113.10', '203.0.113.10' ),
			'ipv4 slash 32'         => array( '203.0.113.10', '203.0.113.10/32' ),
			'ipv4 slash 24 inside'  => array( '203.0.113.10', '203.0.113.0/24' ),
			'ipv4 slash 16 inside'  => array( '203.0.113.10', '203.0.0.0/16' ),
			'ipv4 slash 25 low'     => array( '203.0.113.10', '203.0.113.0/25' ),
			'ipv4 slash 0'          => array( '203.0.113.10', '0.0.0.0/0' ),
			'ipv4 slash 1 low'      => array( '8.8.8.8', '0.0.0.0/1' ),
			'ipv6 exact'            => array( '2001:db8::1', '2001:db8::1' ),
			'ipv6 expanded form'    => array( '2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1' ),
			'ipv6 slash 32'         => array( '2001:db8:abcd::1', '2001:db8::/32' ),
			'ipv6 slash 128'        => array( '2001:db8::1', '2001:db8::1/128' ),
			'ipv6 slash 0'          => array( '2001:db8::1', '::/0' ),
		);
	}

	/**
	 * IP / rule pairs that must not match.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function nonMatchingPairs(): array {
		return array(
			'ipv4 different host'      => array( '203.0.113.10', '203.0.113.11' ),
			'ipv4 outside /24'         => array( '203.0.114.10', '203.0.113.0/24' ),
			'ipv4 just outside /25'    => array( '203.0.113.130', '203.0.113.0/25' ),
			'ipv4 outside /1'          => array( '203.0.113.10', '0.0.0.0/1' ),
			'mixed family'             => array( '203.0.113.10', '2001:db8::/32' ),
			'ipv6 outside /32'         => array( '2001:db9::1', '2001:db8::/32' ),
			'invalid address'          => array( 'not-an-ip', '203.0.113.0/24' ),
			'invalid rule'             => array( '203.0.113.10', 'not-a-rule' ),
			'empty rule'               => array( '203.0.113.10', '' ),
			'slash 33 is rejected'     => array( '203.0.113.10', '203.0.113.10/33' ),
			'slash 129 is rejected'    => array( '2001:db8::1', '2001:db8::1/129' ),
			'negative prefix'          => array( '203.0.113.10', '203.0.113.10/-1' ),
		);
	}

	#[DataProvider( 'matchingPairs' )]
	public function test_ip_match_accepts( string $ip, string $rule ): void {
		$this->assertTrue( Reflect::call( 'spam_ip_match', array( $ip, $rule ) ), "{$ip} should match {$rule}" );
	}

	#[DataProvider( 'nonMatchingPairs' )]
	public function test_ip_match_rejects( string $ip, string $rule ): void {
		$this->assertFalse( Reflect::call( 'spam_ip_match', array( $ip, $rule ) ), "{$ip} should not match {$rule}" );
	}

	/**
	 * Rules the settings screen should accept.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function validIpRules(): array {
		return array(
			'ipv4'          => array( '203.0.113.10' ),
			'ipv6'          => array( '2001:db8::1' ),
			'ipv4 cidr'     => array( '203.0.113.0/24' ),
			'ipv6 cidr'     => array( '2001:db8::/32' ),
			'slash zero'    => array( '0.0.0.0/0' ),
			'slash 32'      => array( '203.0.113.10/32' ),
		);
	}

	/**
	 * Rules the settings screen should flag as never-matching.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function invalidIpRules(): array {
		return array(
			'empty'              => array( '' ),
			'bare word'          => array( 'localhost' ),
			'out of range octet' => array( '300.1.1.1' ),
			'prefix too large'   => array( '203.0.113.0/33' ),
			'prefix not numeric' => array( '203.0.113.0/abc' ),
			'double slash'       => array( '203.0.113.0/24/8' ),
		);
	}

	#[DataProvider( 'validIpRules' )]
	public function test_ip_rule_is_valid( string $rule ): void {
		$this->assertTrue( Reflect::call( 'spam_ip_valid', array( $rule ) ) );
	}

	#[DataProvider( 'invalidIpRules' )]
	public function test_ip_rule_is_invalid( string $rule ): void {
		$this->assertFalse( Reflect::call( 'spam_ip_valid', array( $rule ) ) );
	}

	/**
	 * Domain / rule pairs that must match.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function matchingDomains(): array {
		return array(
			'exact'              => array( 'example.com', 'example.com' ),
			'subdomain'          => array( 'mail.example.com', 'example.com' ),
			'deep subdomain'     => array( 'a.b.example.com', 'example.com' ),
			'rule has at sign'   => array( 'example.com', '@example.com' ),
			'rule has spaces'    => array( 'example.com', '  example.com  ' ),
			'rule uppercase'     => array( 'example.com', 'EXAMPLE.COM' ),
		);
	}

	/**
	 * Domain / rule pairs that must not match.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function nonMatchingDomains(): array {
		return array(
			'different domain'      => array( 'example.org', 'example.com' ),
			'suffix without dot'    => array( 'notexample.com', 'example.com' ),
			'tld only'              => array( 'com', 'example.com' ),
			'empty rule'            => array( 'example.com', '' ),
			'empty domain'          => array( '', 'example.com' ),
		);
	}

	#[DataProvider( 'matchingDomains' )]
	public function test_domain_match_accepts( string $domain, string $rule ): void {
		$this->assertTrue( Reflect::call( 'spam_domain_match', array( $domain, $rule ) ) );
	}

	#[DataProvider( 'nonMatchingDomains' )]
	public function test_domain_match_rejects( string $domain, string $rule ): void {
		$this->assertFalse( Reflect::call( 'spam_domain_match', array( $domain, $rule ) ) );
	}

	/**
	 * Domain rules the settings screen should accept.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function validDomainRules(): array {
		return array(
			'simple'        => array( 'example.com' ),
			'with subdomain' => array( 'mail.example.com' ),
			'multi part tld' => array( 'example.co.uk' ),
			'with hyphen'   => array( 'my-domain.example' ),
			'at prefixed'   => array( '@example.com' ),
		);
	}

	/**
	 * Domain rules the settings screen should flag as never-matching.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function invalidDomainRules(): array {
		return array(
			'bare word'      => array( 'gmail' ),
			'leading hyphen' => array( '-example.com' ),
			'double dot'     => array( 'example..com' ),
			'empty'          => array( '' ),
		);
	}

	#[DataProvider( 'validDomainRules' )]
	public function test_domain_rule_is_valid( string $rule ): void {
		$this->assertTrue( Reflect::call( 'spam_domain_valid', array( $rule ) ) );
	}

	#[DataProvider( 'invalidDomainRules' )]
	public function test_domain_rule_is_invalid( string $rule ): void {
		$this->assertFalse( Reflect::call( 'spam_domain_valid', array( $rule ) ) );
	}

	/**
	 * Text / expected link count.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function linkSamples(): array {
		return array(
			'no links'            => array( 'Please send a quote.', 0 ),
			'one http'            => array( 'see http://example.com', 1 ),
			'one https'           => array( 'see https://example.com/a', 1 ),
			'one www'             => array( 'see www.example.com', 1 ),
			'two links'           => array( 'http://a.example and https://b.example', 2 ),
			'www and https'       => array( 'www.a.example then https://b.example', 2 ),
			'url in parentheses'  => array( '(https://example.com/x)', 1 ),
			'bare scheme no host' => array( 'https://', 0 ),
		);
	}

	#[DataProvider( 'linkSamples' )]
	public function test_link_count( string $text, int $expected ): void {
		$this->assertSame( $expected, Reflect::call( 'spam_link_count', array( $text ) ) );
	}

	/**
	 * Email / expected domain.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function emailSamples(): array {
		return array(
			'simple'          => array( 'someone@example.com', 'example.com' ),
			'uppercase'       => array( 'someone@Example.COM', 'example.com' ),
			'no at sign'      => array( 'someone.example.com', '' ),
			'last at wins'    => array( 'a@b@example.com', 'example.com' ),
			'trailing spaces' => array( 'someone@example.com  ', 'example.com' ),
		);
	}

	#[DataProvider( 'emailSamples' )]
	public function test_email_domain( string $email, string $expected ): void {
		$this->assertSame( $expected, Reflect::call( 'spam_email_domain', array( $email ) ) );
	}

	public function test_ip_rule_matching_is_byte_exact_for_ipv6_compression(): void {
		// The whole reason inet_pton() is used instead of string comparison: these two
		// spellings are the same address, and a naive string compare would miss it.
		$this->assertTrue(
			Reflect::call( 'spam_ip_match', array( '2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1' ) )
		);
		$this->assertTrue(
			Reflect::call( 'spam_ip_match', array( '2001:db8::1', '2001:0db8:0000:0000:0000:0000:0000:0001' ) )
		);
	}
}

<?php
/**
 * Text helpers: label formatting, plain-text conversion, source titles, byte sizes.
 *
 * These run on visitor-supplied strings and on values that end up in the admin table, the
 * email body and the mailto reply. Most of the risk here is cosmetic rather than
 * security-related, but `html_to_text()` feeds the text/plain alternative of every
 * notification, so a mistake shows up in a customer's inbox.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Unit;

use Ifi\Tests\Reflect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers readable_label(), size_label(), html_to_text(), plain_text() and
 * clean_source_title().
 */
final class TextTest extends TestCase {

	/**
	 * Field name / expected label.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function labelCases(): array {
		return array(
			'camel case'        => array( 'outerDiameter', 'Outer Diameter' ),
			'snake case'        => array( 'shaft_diameter', 'Shaft Diameter' ),
			'kebab case'        => array( 'first-name', 'First Name' ),
			'single word'       => array( 'name', 'Name' ),
			'already spaced'    => array( 'Full Name', 'Full Name' ),
			'trailing digit'    => array( 'material1', 'Material 1' ),
			'single letter'     => array( 'a', 'A' ),
			'all caps'          => array( 'SKU', 'SKU' ),
		);
	}

	#[DataProvider( 'labelCases' )]
	public function test_readable_label( string $key, string $expected ): void {
		$this->assertSame( $expected, Reflect::call( 'readable_label', array( $key ) ) );
	}

	/**
	 * Byte count / expected label.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function sizeCases(): array {
		return array(
			'zero'        => array( 0, '0 B' ),
			'one byte'    => array( 1, '1 B' ),
			'just under'  => array( 1023, '1023 B' ),
			'one kb'      => array( 1024, '1 KB' ),
			'round kb'    => array( 2048, '2 KB' ),
			'one mb'      => array( 1048576, '1 MB' ),
			'half mb'     => array( 1572864, '1.5 MB' ),
			'large'       => array( 20971520, '20 MB' ),
		);
	}

	#[DataProvider( 'sizeCases' )]
	public function test_size_label( int $bytes, string $expected ): void {
		$this->assertSame( $expected, Reflect::call( 'size_label', array( $bytes ) ) );
	}

	/**
	 * HTML / expected plain text fragments.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public static function htmlSamples(): array {
		return array(
			'paragraphs'        => array( '<p>one</p><p>two</p>', array( 'one', 'two' ) ),
			'line breaks'       => array( 'a<br>b', array( 'a', 'b' ) ),
			'table cells'       => array( '<table><tr><td>k</td><td>v</td></tr></table>', array( 'k', 'v' ) ),
			'entities decoded'  => array( '<p>Tom &amp; Jerry</p>', array( 'Tom & Jerry' ) ),
			'script dropped'    => array( '<p>keep</p><script>alert(1)</script>', array( 'keep' ) ),
			'style dropped'     => array( '<style>p{color:red}</style><p>keep</p>', array( 'keep' ) ),
			'no blank runs'     => array( "<p>a</p>\n\n\n<p>b</p>", array( 'a', 'b' ) ),
		);
	}

	#[DataProvider( 'htmlSamples' )]
	public function test_html_to_text( string $html, array $expected_fragments ): void {
		$text = Reflect::call( 'html_to_text', array( $html ) );
		foreach ( $expected_fragments as $fragment ) {
			$this->assertStringContainsString( $fragment, $text );
		}
	}

	public function test_html_to_text_drops_script_content_entirely(): void {
		$text = Reflect::call( 'html_to_text', array( '<script>var secret = 1;</script><p>ok</p>' ) );
		$this->assertStringNotContainsString( 'secret', $text );
		$this->assertStringContainsString( 'ok', $text );
	}

	public function test_html_to_text_collapses_repeated_blank_lines(): void {
		$text = Reflect::call( 'html_to_text', array( "<p>a</p><div></div><div></div><p>b</p>" ) );
		$this->assertStringNotContainsString( "\n\n\n", $text );
	}

	/**
	 * HTML fragment / expected plain text.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function plainTextSamples(): array {
		return array(
			'br to newline'   => array( 'a<br>b', "a\nb" ),
			'self closing br' => array( 'a<br/>b', "a\nb" ),
			'strips tags'     => array( '<strong>bold</strong>', 'bold' ),
			'decodes entity'  => array( 'a &amp; b', 'a & b' ),
			'trims'           => array( '  spaced  ', 'spaced' ),
		);
	}

	#[DataProvider( 'plainTextSamples' )]
	public function test_plain_text( string $html, string $expected ): void {
		$this->assertSame( $expected, Reflect::call( 'plain_text', array( $html ) ) );
	}

	/**
	 * Raw title / expected cleaned title.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function sourceTitleCases(): array {
		return array(
			'chinese prefix'     => array( '文章：How to choose a gooseneck', 'How to choose a gooseneck' ),
			'post prefix'        => array( 'Post: Hello world', 'Hello world' ),
			'article prefix'     => array( 'Article: Something', 'Something' ),
			'ascii colon'        => array( 'Post: Hello', 'Hello' ),
			'no prefix'          => array( 'Contact us', 'Contact us' ),
			'prefix as content'  => array( 'Postmortem: what happened', 'Postmortem: what happened' ),
			'leading whitespace' => array( '   Post:   Padded   ', 'Padded' ),
			'empty'              => array( '', '' ),
		);
	}

	#[DataProvider( 'sourceTitleCases' )]
	public function test_clean_source_title( string $title, string $expected ): void {
		$this->assertSame( $expected, Reflect::call( 'clean_source_title', array( $title ) ) );
	}

	public function test_clean_source_title_does_not_eat_a_title_that_merely_starts_with_a_similar_word(): void {
		// "Postage rates" must survive: the pattern requires the colon, so a word that
		// merely starts with "Post" is untouched.
		$this->assertSame(
			'Postage rates 2026',
			Reflect::call( 'clean_source_title', array( 'Postage rates 2026' ) )
		);
	}
}

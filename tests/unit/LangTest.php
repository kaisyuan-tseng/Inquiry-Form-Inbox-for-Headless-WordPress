<?php
/**
 * Language tag canonicalization and language-group matching.
 *
 * This is what decides which of the per-language templates a visitor gets. The interesting
 * cases are all mismatches: a browser reporting `zh-TW` against a `zh-hans` group, an
 * alias that has to fold to its primary language, a group list where the default group is
 * not first.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Unit;

use Ifi\Tests\Reflect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers canonical_lang(), match_lang_group() and group_list().
 */
final class LangTest extends TestCase {

	/**
	 * Raw browser tag / expected canonical tag.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function canonicalCases(): array {
		return array(
			'english us'            => array( 'en-US', 'en' ),
			'english gb'            => array( 'en-GB', 'en' ),
			'english bare'          => array( 'en', 'en' ),
			'english other region'  => array( 'en-AU', 'en' ),
			'chinese bare'          => array( 'zh', 'zh-hans' ),
			'chinese simplified'    => array( 'zh-CN', 'zh-hans' ),
			'chinese singapore'     => array( 'zh-SG', 'zh-hans' ),
			'chinese traditional'   => array( 'zh-TW', 'zh-hant' ),
			'chinese hong kong'     => array( 'zh-HK', 'zh-hant' ),
			'chinese macao'         => array( 'zh-MO', 'zh-hant' ),
			'chinese script tag'    => array( 'zh-Hant', 'zh-hant' ),
			'chinese script hans'   => array( 'zh-Hans', 'zh-hans' ),
			'underscore separator'  => array( 'zh_CN', 'zh-hans' ),
			'japanese'              => array( 'ja', 'ja' ),
			'japanese uppercase'    => array( 'JA', 'ja' ),
			'german region'         => array( 'de-AT', 'de-at' ),
			'empty'                 => array( '', '' ),
			'only punctuation'      => array( ':;', '' ),
			'strips illegal chars'  => array( 'zh CN', 'zhcn' ),
		);
	}

	#[DataProvider( 'canonicalCases' )]
	public function test_canonical_lang( string $raw, string $expected ): void {
		$this->assertSame( $expected, Reflect::call( 'canonical_lang', array( $raw ) ) );
	}

	/**
	 * Group list / expected order of language tags after normalization.
	 *
	 * @return array<string, array{0: array, 1: string[]}>
	 */
	public static function groupListCases(): array {
		return array(
			'default already first'   => array(
				array(
					array( 'lang' => '' ),
					array( 'lang' => 'zh-hans' ),
				),
				array( '', 'zh-hans' ),
			),
			'default moved to front'  => array(
				array(
					array( 'lang' => 'zh-hans' ),
					array( 'lang' => '' ),
					array( 'lang' => 'en' ),
				),
				array( '', 'zh-hans', 'en' ),
			),
			'default inserted'        => array(
				array( array( 'lang' => 'en' ) ),
				array( '', 'en' ),
			),
			'duplicate languages'     => array(
				array(
					array( 'lang' => '' ),
					array( 'lang' => 'en' ),
					array( 'lang' => 'EN' ),
					array( 'lang' => 'zh-hans' ),
					array( 'lang' => 'zh-CN' ),
				),
				array( '', 'en', 'zh-hans' ),
			),
			'non array entries'       => array(
				array(
					array( 'lang' => '' ),
					'not-a-group',
					array( 'lang' => 'ja' ),
				),
				array( '', 'ja' ),
			),
		);
	}

	#[DataProvider( 'groupListCases' )]
	public function test_group_list_normalises( array $groups, array $expected ): void {
		$normalized = Reflect::call( 'group_list', array( array( 'groups' => $groups ) ) );
		$this->assertSame( $expected, array_column( $normalized, 'lang' ) );
	}

	public function test_group_list_always_returns_a_default_group(): void {
		$normalized = Reflect::call( 'group_list', array( array( 'groups' => array() ) ) );
		$this->assertCount( 1, $normalized );
		$this->assertSame( '', $normalized[0]['lang'] );
	}

	/**
	 * Browser tag / group tags / index of the group that should be chosen.
	 *
	 * @return array<string, array{0: string, 1: string[], 2: int}>
	 */
	public static function matchCases(): array {
		return array(
			'exact match'                => array( 'en', array( '', 'en', 'zh-hans' ), 1 ),
			'exact match simplified'     => array( 'zh-CN', array( '', 'en', 'zh-hans' ), 2 ),
			'exact match traditional'    => array( 'zh-TW', array( '', 'zh-hant' ), 1 ),
			'falls back to default'      => array( 'fr-FR', array( '', 'en', 'zh-hans' ), 0 ),
			'no browser language'        => array( '', array( '', 'en' ), 0 ),
			'default group only'         => array( 'zh-CN', array( '' ), 0 ),
			// Deliberate: a zh-Hant visitor hitting a site that only configured zh-Hans
			// gets the Simplified template, not the default one. Same language, wrong
			// script beats right script, wrong language — so the primary-language pass is
			// allowed to cross the hans/hant boundary.
			'traditional falls to simplified' => array( 'zh-TW', array( '', 'zh-hans' ), 1 ),
		);
	}

	#[DataProvider( 'matchCases' )]
	public function test_match_lang_group( string $browser_language, array $langs, int $expected_index ): void {
		$groups = array_map(
			static function ( string $lang ): array {
				return array(
					'lang'    => $lang,
					'subject' => '',
					'html'    => '',
					'labels'  => array(),
				);
			},
			$langs
		);

		// group_list() first, so the fixture is in the same shape match_lang_group() sees
		// in production (default group at index 0, one entry per language).
		$normalized = Reflect::call( 'group_list', array( array( 'groups' => $groups ) ) );
		$matched    = Reflect::call(
			'match_lang_group',
			array(
				array( 'browserLanguage' => $browser_language ),
				$normalized,
			)
		);

		$this->assertSame( $normalized[ $expected_index ]['lang'], $matched['lang'] );
	}

	public function test_match_lang_group_falls_back_when_group_list_is_empty(): void {
		$matched = Reflect::call(
			'match_lang_group',
			array(
				array( 'browserLanguage' => 'zh-CN' ),
				array(),
			)
		);
		$this->assertSame( '', $matched['lang'] );
	}

	public function test_primary_language_is_matched_when_no_exact_group_exists(): void {
		// A site that configured `de` must still answer a visitor whose browser says
		// `de-AT`: canonical_lang() leaves the region in place, so the primary-language
		// pass is what catches it.
		$groups = Reflect::call(
			'group_list',
			array(
				array(
					'groups' => array(
						array( 'lang' => '' ),
						array( 'lang' => 'de' ),
					),
				),
			)
		);

		$matched = Reflect::call(
			'match_lang_group',
			array(
				array( 'browserLanguage' => 'de-AT' ),
				$groups,
			)
		);
		$this->assertSame( 'de', $matched['lang'] );
	}
}

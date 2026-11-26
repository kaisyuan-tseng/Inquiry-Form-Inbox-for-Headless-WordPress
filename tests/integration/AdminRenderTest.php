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
}

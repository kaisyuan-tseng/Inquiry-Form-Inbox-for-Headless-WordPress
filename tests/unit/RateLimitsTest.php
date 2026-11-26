<?php
/**
 * Rate-limit windows: the defaults the plugin ships with.
 *
 * The filter path runs in the integration suite, where real WordPress filters exist; this
 * file pins the shipped numbers, so an accidental edit to the constants fails a test
 * instead of quietly changing policy for every site.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Unit;

use Ifi\Tests\Reflect;
use PHPUnit\Framework\TestCase;

/**
 * Covers rate_limits().
 */
final class RateLimitsTest extends TestCase {

	public function test_the_default_windows_are_three_a_minute_and_ten_an_hour(): void {
		// The numbers live in spam_defaults() now — Block Rules edits them per site, and the
		// constants are only the shipped fallback. The empty option in the unit shim means
		// rate_limits() has to come back with those fallbacks.
		$rules = Reflect::call( 'spam_defaults' );

		$this->assertSame( 3, $rules['rate_minute'] );
		$this->assertSame( 10, $rules['rate_hour'] );

		$limits = Reflect::call( 'rate_limits' );

		$this->assertSame( 3, $limits['minute']['limit'] );
		$this->assertSame( 60, $limits['minute']['ttl'] );
		$this->assertSame( 10, $limits['hour']['limit'] );
		$this->assertSame( 3600, $limits['hour']['ttl'] );
	}
}

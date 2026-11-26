<?php
/**
 * Rate limiting: the two fixed windows, the 429 contract, and what a rejection costs.
 *
 * The subtle part is what a rejected request must *not* do. If a rejection extended the
 * window, a visitor who retries in a loop would lock themselves out forever; if it consumed
 * quota, the same would happen for the rest of the hour. Both are asserted here.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests\Integration;

use Ifi\Tests\IfiTestCase;
use Ifi\Tests\MailSpy;
use Ifi\Tests\Reflect;
use Ifi\Tests\Scratch;

/**
 * Covers rate_consume() through the REST endpoint.
 */
final class RateLimitTest extends IfiTestCase {

	/** Server values to restore after tests that rewrite the forwarding headers. */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();

		$this->server_backup = array(
			'REMOTE_ADDR'          => $_SERVER['REMOTE_ADDR'] ?? null,
			'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
			'HTTP_X_REAL_IP'       => $_SERVER['HTTP_X_REAL_IP'] ?? null,
		);
	}

	/**
	 * Current rate-limit identity, as the plugin computes it.
	 *
	 * @return string
	 */
	private function identity(): string {
		return (string) Reflect::call( 'rate_identity' );
	}

	/**
	 * Transient key for one window.
	 *
	 * @param string $window minute|hour.
	 * @return string
	 */
	private function transient_key( string $window ): string {
		return 'ifi_rl_' . $window . '_' . md5( $this->identity() );
	}

	/**
	 * Overwrite a window's stored state.
	 *
	 * @param string $window minute|hour.
	 * @param int    $count  Count.
	 * @param int    $start  Window start timestamp.
	 */
	private function set_window( string $window, int $count, int $start ): void {
		set_transient(
			$this->transient_key( $window ),
			array(
				'start' => $start,
				'count' => $count,
			),
			3600
		);
	}

	/**
	 * Expire the minute window without touching the hour window.
	 */
	private function expire_minute_window(): void {
		$this->set_window( 'minute', 3, time() - 61 );
	}

	public function test_three_submissions_in_a_minute_are_allowed(): void {
		$this->assert_sent( $this->submit() );
		$this->assert_sent( $this->submit() );
		$this->assert_sent( $this->submit() );

		$this->assertCount( 3, Scratch::inquiries() );
	}

	public function test_the_fourth_submission_in_a_minute_is_rejected(): void {
		$this->submit();
		$this->submit();
		$this->submit();

		$response = $this->submit();

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'inquiry_rate_limited', $response->get_data()['code'] );
	}

	public function test_a_rejected_submission_reports_when_to_retry(): void {
		$this->submit();
		$this->submit();
		$this->submit();

		$response = $this->submit();
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'retry_after', $data );
		$this->assertGreaterThan( 0, (int) $data['retry_after'] );
		$this->assertLessThanOrEqual( 60, (int) $data['retry_after'] );
	}

	public function test_a_rejected_submission_sets_the_retry_after_header(): void {
		$this->submit();
		$this->submit();
		$this->submit();

		$response = $this->submit();
		$headers  = $response->get_headers();

		$this->assertArrayHasKey( 'Retry-After', $headers );
		$this->assertSame( (string) $response->get_data()['retry_after'], (string) $headers['Retry-After'] );
	}

	public function test_a_rejected_submission_is_not_stored_and_not_emailed(): void {
		$this->submit();
		$this->submit();
		$this->submit();
		$records_before = count( Scratch::inquiries() );
		$mails_before   = MailSpy::count();

		$this->submit();

		$this->assertCount( $records_before, Scratch::inquiries() );
		$this->assertSame( $mails_before, MailSpy::count() );
	}

	public function test_a_rejected_submission_does_not_extend_the_window(): void {
		$this->submit();
		$this->submit();
		$this->submit();

		$first  = (int) $this->submit()->get_data()['retry_after'];
		$second = (int) $this->submit()->get_data()['retry_after'];

		// The window must keep draining while the visitor hammers it, not reset on each hit.
		$this->assertLessThanOrEqual( $first, $second );
	}

	public function test_submissions_are_allowed_again_once_the_minute_window_expires(): void {
		$this->submit();
		$this->submit();
		$this->submit();
		$this->assertSame( 429, $this->submit()->get_status() );

		$this->expire_minute_window();

		$this->assert_sent( $this->submit() );
	}

	public function test_the_hourly_window_blocks_after_ten_submissions(): void {
		// Ten already spent in this hour, minute window clear.
		$this->set_window( 'hour', 10, time() );
		$this->set_window( 'minute', 0, time() );

		$response = $this->submit();

		$this->assertSame( 429, $response->get_status() );
		$this->assertGreaterThan( 60, (int) $response->get_data()['retry_after'] );
	}

	public function test_the_hourly_window_is_independent_of_the_minute_window(): void {
		$this->set_window( 'hour', 2, time() );
		$this->set_window( 'minute', 0, time() );

		$this->assert_sent( $this->submit() );

		$state = get_transient( $this->transient_key( 'hour' ) );
		$this->assertSame( 3, (int) $state['count'] );
	}

	public function test_a_honeypot_submission_does_not_consume_rate_limit_quota(): void {
		update_option( 'ifi_spam_rules', array( 'honeypot' => '1' ), false );

		// Three bot submissions, all silently swallowed.
		$this->submit( array( 'companyWebsite' => 'filled' ) );
		$this->submit( array( 'companyWebsite' => 'filled' ) );
		$this->submit( array( 'companyWebsite' => 'filled' ) );

		// A real visitor must still get through.
		$this->assert_sent( $this->submit() );
	}

	public function test_an_ip_blocked_submission_does_not_consume_rate_limit_quota(): void {
		$this->set_spam_rules( array( 'ips' => array( '127.0.0.1' ) ) );

		$this->submit();
		$this->submit();
		$this->submit();

		$this->assertFalse( get_transient( $this->transient_key( 'minute' ) ) );
	}

	public function test_the_identity_falls_back_to_a_signed_cookie_off_the_public_internet(): void {
		// 127.0.0.1 is in a reserved range, so the identity must be the visitor cookie rather
		// than a shared IP bucket — otherwise every local visitor would share one quota.
		$this->assertStringStartsWith( 'vid_', $this->identity() );
	}

	public function test_the_limits_can_be_overridden_by_a_filter(): void {
		add_filter(
			'ifi_rate_limits',
			static function ( array $limits ): array {
				$limits['minute']['limit'] = 1;
				return $limits;
			}
		);

		$this->assert_sent( $this->submit() );

		$this->assertSame( 429, $this->submit()->get_status() );
	}

	public function test_the_limits_come_from_the_block_rules_screen(): void {
		$this->set_spam_rules(
			array(
				'rate_minute' => 1,
				'rate_hour'   => 10,
			)
		);

		$this->assert_sent( $this->submit() );

		$response = $this->submit();

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'inquiry_rate_limited', $response->get_data()['code'] );
	}

	public function test_zero_on_the_screen_turns_a_window_off(): void {
		$this->set_spam_rules(
			array(
				'rate_minute' => 0,
				'rate_hour'   => 0,
			)
		);

		// Five in a row: more than either shipped window would allow.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assert_sent( $this->submit() );
		}
	}

	public function test_a_filter_still_beats_the_screen(): void {
		$this->set_spam_rules(
			array(
				'rate_minute' => 10,
				'rate_hour'   => 10,
			)
		);
		add_filter(
			'ifi_rate_limits',
			static function ( array $limits ): array {
				$limits['minute']['limit'] = 1;
				return $limits;
			}
		);

		$this->assert_sent( $this->submit() );

		$this->assertSame( 429, $this->submit()->get_status() );
	}

	public function test_a_zero_limit_turns_that_window_off(): void {
		add_filter(
			'ifi_rate_limits',
			static function ( array $limits ): array {
				$limits['minute']['limit'] = 0;
				$limits['hour']['limit']   = 0;
				return $limits;
			}
		);

		// Five in a row: more than either default window would allow.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assert_sent( $this->submit() );
		}
	}

	public function test_a_broken_filter_falls_back_to_the_defaults(): void {
		add_filter( 'ifi_rate_limits', static fn() => 'nonsense' );

		$this->submit();
		$this->submit();
		$this->submit();

		$this->assertSame( 429, $this->submit()->get_status() );
	}

	protected function tearDown(): void {
		foreach ( $this->server_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		parent::tearDown();
	}

	public function test_forwarded_for_uses_the_hop_closest_to_the_server(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 8.8.8.8';
		add_filter( 'ifi_trusted_proxies', static fn() => array( '10.0.0.1' ) );

		$this->assertSame( 'ip_8.8.8.8', $this->identity() );
	}

	public function test_a_spoofed_leftmost_forwarded_entry_does_not_change_the_identity(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		add_filter( 'ifi_trusted_proxies', static fn() => array( '10.0.0.1' ) );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 8.8.8.8';
		$first                           = $this->identity();

		// Same real hop, different client-supplied leftmost entry.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '5.6.7.8, 8.8.8.8';
		$second                          = $this->identity();

		$this->assertSame( 'ip_8.8.8.8', $first );
		$this->assertSame( $first, $second );
	}

	public function test_forwarded_headers_from_an_untrusted_peer_are_ignored(): void {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1';

		// The peer is not a configured proxy, so the header is ignored and the identity
		// falls back to the signed visitor cookie instead of the spoofed IP.
		$this->assertStringStartsWith( 'vid_', $this->identity() );
	}
}

<?php
/**
 * Mail capture for the integration suite.
 *
 * `pre_wp_mail` short-circuits wp_mail() before PHPMailer is built, so no SMTP connection is
 * opened and no message leaves the machine. Returning true records a delivery; returning
 * false records a failure and fires `wp_mail_failed`, which is what the plugin listens on to
 * write `ifi_inquiry_mail_error`.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests;

use WP_Error;

/**
 * Records every wp_mail() call made during a test.
 */
final class MailSpy {

	/**
	 * Captured calls, oldest first.
	 *
	 * @var array<int, array{to: mixed, subject: string, message: string, headers: mixed, attachments: mixed}>
	 */
	private static array $sent = array();

	/** Whether the next call should fail. */
	private static bool $fail_next = false;

	/** 1-based call number that should fail, or 0 for none. */
	private static int $fail_on_call = 0;

	/** Number of calls seen since the last reset. */
	private static int $calls = 0;

	/** Message used for the simulated failure. */
	private static string $fail_message = 'Simulated SMTP failure.';

	/** Whether the filter is attached. */
	private static bool $attached = false;

	/**
	 * Attach the capture filter. Safe to call repeatedly.
	 */
	public static function attach(): void {
		if ( self::$attached ) {
			return;
		}
		self::$attached = true;

		add_filter(
			'pre_wp_mail',
			static function ( $return, array $atts ) {
				self::$calls++;
				self::$sent[] = array(
					'to'          => $atts['to'] ?? '',
					'subject'     => (string) ( $atts['subject'] ?? '' ),
					'message'     => (string) ( $atts['message'] ?? '' ),
					'headers'     => $atts['headers'] ?? array(),
					'attachments' => $atts['attachments'] ?? array(),
				);

				if ( self::$fail_next || ( self::$fail_on_call > 0 && self::$calls === self::$fail_on_call ) ) {
					self::$fail_next = false;
					// wp_mail() does not fire this itself when pre_wp_mail short-circuits,
					// and the plugin depends on it to record the reason.
					do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', self::$fail_message ) );
					return false;
				}

				return true;
			},
			10,
			2
		);
	}

	/**
	 * Forget everything captured so far.
	 */
	public static function reset(): void {
		self::$sent         = array();
		self::$fail_next    = false;
		self::$fail_on_call = 0;
		self::$calls        = 0;
		self::$fail_message = 'Simulated SMTP failure.';
	}

	/**
	 * Make the next wp_mail() call fail.
	 *
	 * @param string $message Error message the plugin should record.
	 */
	public static function fail_next( string $message = 'Simulated SMTP failure.' ): void {
		self::$fail_next    = true;
		self::$fail_message = $message;
	}

	/**
	 * Make one specific call fail.
	 *
	 * Used where a single request sends two emails and only the second one should fail —
	 * the notification and the auto-confirmation are deliberately independent, and that is
	 * exactly the case worth pinning down.
	 *
	 * @param int    $call_number 1-based call number.
	 * @param string $message     Error message the plugin should record.
	 */
	public static function fail_on_call( int $call_number, string $message = 'Simulated SMTP failure.' ): void {
		self::$fail_on_call = max( 1, $call_number );
		self::$fail_message = $message;
	}

	/**
	 * Every captured call.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function sent(): array {
		return self::$sent;
	}

	/**
	 * How many messages were attempted.
	 *
	 * @return int
	 */
	public static function count(): int {
		return count( self::$sent );
	}

	/**
	 * The most recent call, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last(): ?array {
		return self::$sent ? self::$sent[ count( self::$sent ) - 1 ] : null;
	}

	/**
	 * The most recent call addressed to a given recipient.
	 *
	 * @param string $email Recipient address.
	 * @return array<string, mixed>|null
	 */
	public static function last_to( string $email ): ?array {
		for ( $i = count( self::$sent ) - 1; $i >= 0; $i-- ) {
			$to = self::$sent[ $i ]['to'];
			$to = is_array( $to ) ? implode( ',', $to ) : (string) $to;
			if ( false !== strpos( $to, $email ) ) {
				return self::$sent[ $i ];
			}
		}
		return null;
	}
}

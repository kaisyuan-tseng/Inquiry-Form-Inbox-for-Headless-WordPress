<?php
/**
 * Bootstrap for the unit suite: real plugin code, no WordPress, no database.
 *
 * The plugin is one class composed of twenty traits. Loading the class file only *defines*
 * it — `Inquiry_Form_Inbox::init()` lives in the main plugin file and is never called here —
 * so the tests get the real methods, the real constants and the real trait wiring, with a
 * handful of WordPress functions stubbed out.
 *
 * Two rules keep this honest:
 *
 *   1. `ABSPATH` must be defined before any trait file is required, because every one of
 *      them opens with `defined( 'ABSPATH' ) || exit`.
 *   2. A stub must never grow behaviour the real function does not have. Where a stub is
 *      unavoidable it mirrors core's documented contract (sanitize_text_field strips tags
 *      and collapses whitespace), so a test cannot pass against a friendlier fake.
 *
 * Anything that needs options, transients, posts or i18n switching belongs in the
 * integration suite instead — see tests/bootstrap/wp-load-test.php.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

$ifi_root = dirname( __DIR__, 2 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $ifi_root . '/' );
}
if ( ! defined( 'IFI_PLUGIN_FILE' ) ) {
	define( 'IFI_PLUGIN_FILE', $ifi_root . '/inquiry-form-inbox.php' );
}
if ( ! defined( 'WP_DEBUG' ) ) {
	// Keeps t()'s _doing_it_wrong() path quiet; the fallback itself is still tested.
	define( 'WP_DEBUG', false );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// --- WordPress function stubs ---------------------------------------------

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub: the unit suite asserts on source strings, never on translations.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain, ignored.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Escaping translation stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain, ignored.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escaping stub mirroring core's output for the cases under test.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Filter stub: no filters are registered in the unit suite, so the value is returned.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * Locale stub: the unit suite runs in en_US.
	 *
	 * @return string
	 */
	function get_locale() {
		return 'en_US';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Mirrors core: strip tags, drop line breaks, collapse runs of whitespace, trim.
	 *
	 * @param string $str Input.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		$filtered = strip_tags( (string) $str );
		$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
		return trim( $filtered );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Mirrors core closely enough for the plain-text conversion: strip script/style bodies,
	 * then all remaining tags.
	 *
	 * @param string $text           Input.
	 * @param bool   $remove_breaks  Whether to drop line breaks.
	 * @return string
	 */
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( $text );
		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}
		return trim( $text );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Mirrors core's array/query-string merge.
	 *
	 * @param mixed $args     Args.
	 * @param array $defaults Defaults.
	 * @return array
	 */
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$parsed = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$parsed = $args;
		} else {
			$parsed = array();
		}
		return array_merge( $defaults, $parsed );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Transient stub backed by a process-local array.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	function get_transient( $key ) {
		global $ifi_unit_transients;
		return $ifi_unit_transients[ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Transient stub backed by a process-local array.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL, ignored.
	 * @return bool
	 */
	function set_transient( $key, $value, $ttl = 0 ) {
		global $ifi_unit_transients;
		$ifi_unit_transients[ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Option stub: everything is unset, which is the documented default state.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Minimal is_email() mirror for the cases the plugin exercises.
	 *
	 * @param string $email Candidate.
	 * @return bool
	 */
	function is_email( $email ) {
		return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
	}
}

// --- Load the plugin ------------------------------------------------------

$ifi_includes = array(
	'trait-ifi-core.php',
	'trait-ifi-i18n.php',
	'trait-ifi-manifest.php',
	'trait-ifi-rest.php',
	'trait-ifi-upload.php',
	'trait-ifi-rate-limiter.php',
	'trait-ifi-template.php',
	'trait-ifi-mailer.php',
	'trait-ifi-storage.php',
	'trait-ifi-cpt.php',
	'trait-ifi-cpt-actions.php',
	'trait-ifi-admin-view.php',
	'trait-ifi-admin-modal.php',
	'trait-ifi-dashboard.php',
	'trait-ifi-settings.php',
	'trait-ifi-settings-render.php',
	'trait-ifi-spam.php',
	'trait-ifi-spam-render.php',
	'trait-ifi-assets.php',
	'trait-ifi-endpoints.php',
	'trait-ifi-install-health.php',
);

foreach ( $ifi_includes as $ifi_include ) {
	require_once $ifi_root . '/includes/' . $ifi_include;
}

require_once $ifi_root . '/includes/class-inquiry-form-inbox.php';
require_once $ifi_root . '/includes/class-ifi-locale.php';
require_once $ifi_root . '/includes/class-ifi-capabilities.php';
require_once $ifi_root . '/includes/class-ifi-privacy.php';

require_once __DIR__ . '/../lib/Reflect.php';

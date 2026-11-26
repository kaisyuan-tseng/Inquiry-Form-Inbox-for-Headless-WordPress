<?php
/**
 * The single reflection seam for the test suite.
 *
 * Nearly every helper in this plugin is a private static method on one big class. Reaching
 * them from a test needs reflection, and reflection that silently returns null when a method
 * is renamed is worse than no test at all — the suite would stay green while covering
 * nothing. So every call goes through here, and a missing method is a hard failure naming
 * the method.
 *
 * @package Inquiry_Form_Inbox
 */

declare( strict_types = 1 );

namespace Ifi\Tests;

use ReflectionMethod;
use RuntimeException;

/**
 * Invoke private statics on Inquiry_Form_Inbox.
 */
final class Reflect {

	/**
	 * Reflection handles, keyed by "class::method".
	 *
	 * @var array<string, ReflectionMethod>
	 */
	private static array $methods = array();

	/**
	 * Call a private or protected static method.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 * @param string $class  Declaring class, defaults to the main plugin class.
	 * @return mixed
	 * @throws RuntimeException When the method no longer exists.
	 */
	public static function call( string $method, array $args = array(), string $class = \Inquiry_Form_Inbox::class ) {
		$key = $class . '::' . $method;
		if ( ! isset( self::$methods[ $key ] ) ) {
			if ( ! method_exists( $class, $method ) ) {
				throw new RuntimeException(
					sprintf(
						'%s::%s() does not exist. The test seam is stale — update the test rather than deleting it.',
						$class,
						$method
					)
				);
			}
			$reflection = new ReflectionMethod( $class, $method );
			// setAccessible() became a no-op in PHP 8.1 and is deprecated in 8.5.
			if ( PHP_VERSION_ID < 80100 ) {
				$reflection->setAccessible( true );
			}
			self::$methods[ $key ] = $reflection;
		}

		return self::$methods[ $key ]->invokeArgs( null, $args );
	}

	/**
	 * Read a private static property.
	 *
	 * @param string $property Property name.
	 * @return mixed
	 * @throws RuntimeException When the property no longer exists.
	 */
	public static function get_property( string $property ) {
		if ( ! property_exists( \Inquiry_Form_Inbox::class, $property ) ) {
			throw new RuntimeException(
				sprintf( 'Inquiry_Form_Inbox::$%s does not exist.', $property )
			);
		}
		$reflection = new \ReflectionProperty( \Inquiry_Form_Inbox::class, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		return $reflection->getValue();
	}
}

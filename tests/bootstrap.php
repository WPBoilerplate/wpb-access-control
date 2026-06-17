<?php
/**
 * PHPUnit bootstrap for the wpb-access-control test suite.
 *
 * Loads Composer's autoloader and defines ABSPATH so every source file
 * (guarded with `defined('ABSPATH') || exit`) can be loaded outside WordPress.
 *
 * WordPress function stubs required by BerlinDB 3.0 are defined in the global
 * namespace. Brain Monkey's namespace-scoped mocks still take precedence for
 * the code under test because PHP resolves namespace-local functions before
 * falling back to the global namespace.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$args = (array) $args;
		} elseif ( is_string( $args ) ) {
			parse_str( $args, $args );
		}
		return array_merge( (array) $defaults, (array) $args );
	}
}

if ( ! function_exists( 'wp_kses_data' ) ) {
	function wp_kses_data( $data ) {
		return $data;
	}
}

if ( ! function_exists( 'wp_validate_boolean' ) ) {
	function wp_validate_boolean( $var ) {
		return (bool) $var;
	}
}

if ( ! function_exists( 'remove_accents' ) ) {
	function remove_accents( $string ) {
		return $string;
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( $str );
	}
}

// ---------------------------------------------------------------------------
// MemberPress test stubs — required by MemberPressMembershipProviderTest.
//
// PHP constants and class declarations persist for the lifetime of the
// process, so they belong here (loaded once) rather than inside any test
// file's namespace block.
// ---------------------------------------------------------------------------
if ( ! defined( 'MEPR_VERSION' ) ) {
	define( 'MEPR_VERSION', '1.0.0-test' );
}
if ( ! class_exists( 'MeprUser' ) ) {
	/**
	 * Minimal stub of MemberPress's MeprUser model.
	 *
	 * Tests set `MeprUser::$next_subscriptions` before invoking
	 * `user_has_access()` to drive `active_product_subscriptions()`.
	 */
	class MeprUser {
		/** @var array<int, mixed> */
		public static $next_subscriptions = array();

		public $ID = 0;

		public function __construct( $user_id = 0 ) {
			$this->ID = (int) $user_id;
		}

		public function active_product_subscriptions(): array {
			return self::$next_subscriptions;
		}
	}
}

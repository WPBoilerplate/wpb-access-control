<?php
/**
 * Table slug validation.
 *
 * Every consumer plugin embeds the library and passes a slug into
 * `AccessControlManager` so its database table, object-cache group, and
 * REST routes don't collide with other consumers on the same WordPress
 * install. The slug is incorporated into raw SQL table names, so the
 * validation rules here are intentionally strict.
 *
 * @package WPBoilerplate\AccessControl
 * @since   2.0.0
 */

namespace WPBoilerplate\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared slug validator used by RuleTable, RuleQuery, and AccessControlManager.
 *
 * @since 2.0.0
 */
final class Slug {

	/**
	 * Slug pattern: lowercase ASCII letters, digits, and underscores;
	 * 1 to 32 characters. Rejects anything that could land in a raw
	 * SQL identifier without quoting.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public const PATTERN = '/^[a-z0-9_]{1,32}$/';

	/**
	 * Validate a slug and return it unchanged, or throw on invalid input.
	 *
	 * @since 2.0.0
	 *
	 * @param string $slug Consumer-supplied slug.
	 *
	 * @throws \InvalidArgumentException When the slug is empty or fails the pattern.
	 *
	 * @return string The validated slug.
	 */
	public static function sanitize( string $slug ): string {
		if ( '' === $slug || 1 !== preg_match( self::PATTERN, $slug ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Access control table slug must match %s. Got: %s',
					self::PATTERN,
					'' === $slug ? '(empty)' : "'" . $slug . "'"
				)
			);
		}

		return $slug;
	}
}

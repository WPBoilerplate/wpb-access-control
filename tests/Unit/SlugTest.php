<?php
/**
 * Unit tests for the Slug validator.
 *
 * The slug is folded directly into raw SQL table names and REST URL
 * segments, so anything outside `[a-z0-9_]{1,32}` is rejected up-front
 * with a clear `InvalidArgumentException`.
 */

namespace WPBoilerplate\AccessControl\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPBoilerplate\AccessControl\Slug;

final class SlugTest extends TestCase {

	// -------------------------------------------------------------------------
	// Accepting cases
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider valid_slugs
	 */
	public function test_sanitize_returns_valid_slug_unchanged( string $slug ): void {
		$this->assertSame( $slug, Slug::sanitize( $slug ) );
	}

	public function valid_slugs(): array {
		return array(
			'lowercase'              => array( 'mcp' ),
			'with_underscores'       => array( 'my_plugin' ),
			'with_digits'            => array( 'plugin_v2' ),
			'single_char'            => array( 'a' ),
			'exactly_32_chars'       => array( str_repeat( 'a', 32 ) ),
			'mixed_chars'            => array( 'abilities_manager_v3' ),
			'leading_underscore_ok'  => array( '_internal' ),
			'leading_digit_ok'       => array( '2nd_plugin' ),
		);
	}

	// -------------------------------------------------------------------------
	// Rejecting cases
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider invalid_slugs
	 */
	public function test_sanitize_throws_for_invalid_slug( string $slug ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/table slug/i' );
		Slug::sanitize( $slug );
	}

	public function invalid_slugs(): array {
		return array(
			'empty'                => array( '' ),
			'has_uppercase'        => array( 'Plugin' ),
			'has_dash'             => array( 'my-plugin' ),
			'has_space'            => array( 'my plugin' ),
			'has_dot'              => array( 'my.plugin' ),
			'has_slash'            => array( '../etc/passwd' ),
			'has_quote'            => array( "mcp';--" ),
			'has_backtick'         => array( '`mcp`' ),
			'too_long'             => array( str_repeat( 'a', 33 ) ),
			'utf8_emoji'           => array( '🔐' ),
			'whitespace_only'      => array( '   ' ),
		);
	}

	public function test_error_message_includes_pattern_for_diagnostics(): void {
		try {
			Slug::sanitize( 'BAD-slug' );
			$this->fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( '[a-z0-9_]', $e->getMessage() );
			$this->assertStringContainsString( "'BAD-slug'", $e->getMessage() );
		}
	}

	public function test_error_message_marks_empty_slug_explicitly(): void {
		try {
			Slug::sanitize( '' );
			$this->fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( '(empty)', $e->getMessage() );
		}
	}
}

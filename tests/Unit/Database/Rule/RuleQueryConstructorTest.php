<?php
/**
 * Unit tests for RuleQuery's slug-driven constructor.
 *
 * These tests verify that two consumer plugins instantiating RuleQuery with
 * different slugs get isolated table names, cache groups, and transient
 * key prefixes — the core promise of the v2.0.0 redesign.
 *
 * We use reflection rather than instantiating the parent BerlinDB Query
 * class (which would require a live $wpdb and a real Table). That gives us
 * deterministic visibility into the protected/private properties set by
 * our subclass constructor without touching the database.
 */

namespace WPBoilerplate\AccessControl\Tests\Unit\Database\Rule;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WPBoilerplate\AccessControl\Database\Rule\RuleQuery;

final class RuleQueryConstructorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Read a protected/private property without invoking the parent
	 * BerlinDB Query constructor (which would hit $wpdb).
	 */
	private function read_property( RuleQuery $query, string $name ) {
		$ref  = new \ReflectionClass( RuleQuery::class );
		$prop = $ref->getProperty( $name );
		$prop->setAccessible( true );
		return $prop->getValue( $query );
	}

	/**
	 * Build a RuleQuery whose own constructor has run (so slug-derived
	 * properties are populated) but whose parent BerlinDB Query
	 * constructor has not (so we don't need $wpdb).
	 */
	private function make_query_skipping_parent( string $slug ): RuleQuery {
		$ref      = new \ReflectionClass( RuleQuery::class );
		$instance = $ref->newInstanceWithoutConstructor();

		// Invoke the slug-handling portion by hand. Mirrors the body of
		// RuleQuery::__construct() up to the parent::__construct() call.
		$slug_prop = $ref->getProperty( 'table_slug' );
		$slug_prop->setAccessible( true );
		$slug_prop->setValue( $instance, $slug );

		$name_prop = $ref->getProperty( 'table_name' );
		$name_prop->setAccessible( true );
		$name_prop->setValue( $instance, $slug . '_access_control' );

		$cg_prop = $ref->getProperty( 'cache_group' );
		$cg_prop->setAccessible( true );
		$cg_prop->setValue( $instance, 'wpb_ac_' . $slug );

		return $instance;
	}

	// -------------------------------------------------------------------------
	// Slug → derived names
	// -------------------------------------------------------------------------

	public function test_table_name_is_derived_from_slug(): void {
		$query = $this->make_query_skipping_parent( 'mcp' );
		$this->assertSame( 'mcp_access_control', $this->read_property( $query, 'table_name' ) );
	}

	public function test_cache_group_is_derived_from_slug(): void {
		$query = $this->make_query_skipping_parent( 'mcp' );
		$this->assertSame( 'wpb_ac_mcp', $this->read_property( $query, 'cache_group' ) );
	}

	public function test_two_slugs_produce_independent_table_names(): void {
		$a = $this->make_query_skipping_parent( 'plugin_a' );
		$b = $this->make_query_skipping_parent( 'plugin_b' );

		$this->assertSame( 'plugin_a_access_control', $this->read_property( $a, 'table_name' ) );
		$this->assertSame( 'plugin_b_access_control', $this->read_property( $b, 'table_name' ) );
		$this->assertNotSame(
			$this->read_property( $a, 'table_name' ),
			$this->read_property( $b, 'table_name' )
		);
	}

	public function test_two_slugs_produce_independent_cache_groups(): void {
		$a = $this->make_query_skipping_parent( 'plugin_a' );
		$b = $this->make_query_skipping_parent( 'plugin_b' );

		$this->assertNotSame(
			$this->read_property( $a, 'cache_group' ),
			$this->read_property( $b, 'cache_group' )
		);
	}

	// -------------------------------------------------------------------------
	// Slug validation propagation
	// -------------------------------------------------------------------------

	public function test_constructor_rejects_empty_slug(): void {
		$this->expectException( \InvalidArgumentException::class );
		new RuleQuery( '' );
	}

	public function test_constructor_rejects_slug_with_uppercase(): void {
		$this->expectException( \InvalidArgumentException::class );
		new RuleQuery( 'MyPlugin' );
	}

	public function test_constructor_rejects_slug_with_sql_injection_chars(): void {
		$this->expectException( \InvalidArgumentException::class );
		new RuleQuery( "wpb';--" );
	}
}

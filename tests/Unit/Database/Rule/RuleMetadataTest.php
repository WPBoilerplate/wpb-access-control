<?php
/**
 * Unit tests for Rule table metadata classes.
 */

namespace WPBoilerplate\AccessControl\Tests\Unit\Database\Rule {

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPBoilerplate\AccessControl\Database\Rule\RuleRow;
use WPBoilerplate\AccessControl\Database\Rule\RuleSchema;
use WPBoilerplate\AccessControl\Database\Rule\RuleTable;

final class RuleMetadataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_key' )->returnArg();

		$GLOBALS['wpdb'] = (object) array(
			'charset' => 'utf8mb4',
			'collate' => 'utf8mb4_unicode_ci',
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// RuleSchema — columns
	// -------------------------------------------------------------------------

	public function test_rule_schema_defines_expected_columns(): void {
		$schema  = new RuleSchema();
		$columns = $schema->get_columns();
		$names   = array_map( static fn( $col ) => $col->name, $columns );

		$this->assertSame(
			array(
				'id',
				'namespace',
				'key',
				'access_control_key',
				'access_control_value',
				'created_at',
				'updated_at',
			),
			$names
		);
	}

	public function test_rule_schema_lengths_match_table_constants(): void {
		$schema  = new RuleSchema();
		$columns = array_column( $schema->get_columns(), null, 'name' );

		$this->assertSame( RuleTable::NAMESPACE_LENGTH, $columns['namespace']->length );
		$this->assertSame( RuleTable::KEY_LENGTH, $columns['key']->length );
		$this->assertSame( RuleTable::AC_KEY_LENGTH, $columns['access_control_key']->length );
		$this->assertSame( RuleTable::AC_VALUE_LENGTH, $columns['access_control_value']->length );
	}

	// -------------------------------------------------------------------------
	// RuleSchema — indexes
	// -------------------------------------------------------------------------

	public function test_rule_schema_defines_three_indexes(): void {
		$schema  = new RuleSchema();
		$indexes = $schema->get_indexes();

		$this->assertCount( 3, $indexes );
	}

	public function test_rule_schema_has_primary_key_on_id(): void {
		$schema = new RuleSchema();

		$this->assertTrue( $schema->has_index( 'primary' ) );

		$index = $schema->get_index( 'primary' );
		$this->assertSame( 'primary', strtolower( $index->type ) );
		$this->assertSame( array( 'id' ), $index->columns );
	}

	public function test_rule_schema_has_unique_key_on_namespace_key_value(): void {
		$schema = new RuleSchema();

		$this->assertTrue( $schema->has_index( 'ns_key_value' ) );

		$index = $schema->get_index( 'ns_key_value' );
		$this->assertSame( 'unique', strtolower( $index->type ) );
		$this->assertSame( array( 'namespace', 'key', 'access_control_value' ), $index->columns );
	}

	public function test_rule_schema_has_composite_key_on_namespace_key(): void {
		$schema = new RuleSchema();

		$this->assertTrue( $schema->has_index( 'ns_key' ) );

		$index = $schema->get_index( 'ns_key' );
		$this->assertSame( array( 'namespace', 'key' ), $index->columns );
	}

	public function test_rule_schema_passes_berlindb_validation(): void {
		$schema = new RuleSchema();

		$this->assertTrue( $schema->is_valid(), implode( '; ', $schema->get_validation_errors() ) );
	}

	public function test_rule_schema_generates_non_empty_create_table_sql(): void {
		$schema = new RuleSchema();
		$sql    = $schema->get_create_table_string();

		$this->assertNotEmpty( $sql, 'get_create_table_string() must return non-empty SQL' );
		$this->assertStringContainsString( '`namespace`', $sql );
		$this->assertStringContainsString( '`access_control_value`', $sql );
	}

	// -------------------------------------------------------------------------
	// RuleTable — schema wiring
	// -------------------------------------------------------------------------

	public function test_rule_table_schema_property_points_to_rule_schema_class(): void {
		$reflection = new \ReflectionClass( RuleTable::class );
		$defaults   = $reflection->getDefaultProperties();

		$this->assertSame(
			RuleSchema::class,
			$defaults['schema'],
			'RuleTable::$schema must be RuleSchema::class so BerlinDB 3.0 can instantiate it'
		);
	}

	public function test_rule_table_version_is_string(): void {
		$reflection = new \ReflectionClass( RuleTable::class );
		$defaults   = $reflection->getDefaultProperties();

		$this->assertIsString(
			$defaults['version'],
			'RuleTable::$version must be a string for version_compare() compatibility'
		);
	}

	public function test_rule_table_does_not_declare_set_schema(): void {
		$reflection = new \ReflectionClass( RuleTable::class );

		if ( $reflection->hasMethod( 'set_schema' ) ) {
			$declaring = $reflection->getMethod( 'set_schema' )->getDeclaringClass()->getName();
			$this->assertNotSame(
				RuleTable::class,
				$declaring,
				'RuleTable must not declare set_schema() — it is private in BerlinDB 3.0 and an override is dead code'
			);
		} else {
			$this->assertTrue( true );
		}
	}

	// -------------------------------------------------------------------------
	// RuleRow
	// -------------------------------------------------------------------------

	public function test_rule_row_defaults_match_empty_database_row_shape(): void {
		$row = new RuleRow();

		$this->assertSame( 0, $row->id );
		$this->assertSame( '', $row->namespace );
		$this->assertSame( '', $row->key );
		$this->assertSame( '', $row->access_control_key );
		$this->assertSame( '', $row->access_control_value );
		$this->assertSame( '', $row->created_at );
		$this->assertSame( '', $row->updated_at );
	}
}
}

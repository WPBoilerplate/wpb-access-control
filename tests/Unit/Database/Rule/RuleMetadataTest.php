<?php
/**
 * Unit tests for Rule table metadata classes.
 */

namespace BerlinDB\Database {
	if ( ! function_exists( __NAMESPACE__ . '\wp_parse_args' ) ) {
		function wp_parse_args( $args, array $defaults = array() ): array {
			return array_merge( $defaults, (array) $args );
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\wp_kses_data' ) ) {
		function wp_kses_data( $data ) {
			return $data;
		}
	}
}

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

	public function test_rule_schema_defines_expected_columns(): void {
		$schema = new RuleSchema();
		$names  = array_column( $schema->columns, 'name' );

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
		$columns = array_column( $schema->columns, null, 'name' );

		$this->assertSame( RuleTable::NAMESPACE_LENGTH, $columns['namespace']->length );
		$this->assertSame( RuleTable::KEY_LENGTH, $columns['key']->length );
		$this->assertSame( RuleTable::AC_KEY_LENGTH, $columns['access_control_key']->length );
		$this->assertSame( RuleTable::AC_VALUE_LENGTH, $columns['access_control_value']->length );
	}

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

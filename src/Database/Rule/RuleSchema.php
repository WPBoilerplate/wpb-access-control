<?php
/**
 * Rule table column schema for BerlinDB.
 *
 * @package WPBoilerplate\AccessControl\Database\Rule
 * @since   1.0.0
 */

namespace WPBoilerplate\AccessControl\Database\Rule;

use BerlinDB\Database\Kern\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the column layout for the {prefix}wpb_access_control table.
 *
 * Each entry maps to one physical column. Flags like `in`, `searchable`, and
 * `sortable` control which query parameters BerlinDB makes available on
 * Rule\Query.
 *
 * The $indexes array defines the UNIQUE KEY and composite KEY that enforce
 * uniqueness and support efficient lookups by (namespace, key). These moved
 * here from RuleTable::set_schema() when upgrading to BerlinDB 3.0, which
 * made Table::set_schema() private and introduced first-class Index support.
 *
 * @since 1.0.0
 */
class RuleSchema extends Schema {

	/**
	 * Column definitions.
	 *
	 * @var array[]
	 */
	public $columns = array(
		array(
			'name'     => 'id',
			'type'     => 'bigint',
			'length'   => 20,
			'unsigned' => true,
			'extra'    => 'auto_increment',
			'sortable' => true,
		),
		array(
			'name'       => 'namespace',
			'type'       => 'varchar',
			'length'     => 100,
			'searchable' => true,
			'sortable'   => true,
			'in'         => true,
			'not_in'     => true,
		),
		array(
			'name'       => 'key',
			'type'       => 'varchar',
			'length'     => 255,
			'searchable' => true,
			'sortable'   => true,
			'in'         => true,
			'not_in'     => true,
		),
		array(
			'name'       => 'access_control_key',
			'type'       => 'varchar',
			'length'     => 100,
			'searchable' => true,
			'in'         => true,
			'not_in'     => true,
		),
		array(
			'name'       => 'access_control_value',
			'type'       => 'varchar',
			'length'     => 255,
			'searchable' => true,
			'in'         => true,
			'not_in'     => true,
		),
		array(
			'name'    => 'created_at',
			'type'    => 'datetime',
			'created' => true,
		),
		array(
			'name'     => 'updated_at',
			'type'     => 'datetime',
			'modified' => true,
		),
	);

	/**
	 * Index definitions.
	 *
	 * Enforces uniqueness per (namespace, key, value) tuple and supports
	 * fast lookups by (namespace, key). WordPress requires MySQL 5.7+/
	 * MariaDB 10.2+ with InnoDB, which supports index key lengths up to
	 * 3072 bytes — sufficient for these VARCHAR columns in utf8mb4.
	 *
	 * @var array[]
	 */
	public $indexes = array(
		array(
			'name'    => 'primary',
			'type'    => 'primary',
			'columns' => array( 'id' ),
		),
		array(
			'name'    => 'ns_key_value',
			'type'    => 'unique',
			'columns' => array( 'namespace', 'key', 'access_control_value' ),
		),
		array(
			'name'    => 'ns_key',
			'type'    => 'key',
			'columns' => array( 'namespace', 'key' ),
		),
	);
}

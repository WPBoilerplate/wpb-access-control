<?php
/**
 * Rule database table definition for BerlinDB.
 *
 * Defines the schema for one consumer plugin's access-control table. The
 * physical table name is derived from the slug passed to the constructor:
 *   `{wp_prefix}{slug}_access_control`.
 *
 * `RuleQuery` instantiates this automatically on first use per slug —
 * consuming plugins do not need to manage it.
 *
 * @package WPBoilerplate\AccessControl\Database\Rule
 * @since   1.0.0
 * @since   2.0.0 Constructor accepts a required `$table_slug` argument so each
 *                consumer gets its own table.
 */

namespace WPBoilerplate\AccessControl\Database\Rule;

use BerlinDB\Database\Kern\Table;
use WPBoilerplate\AccessControl\Slug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the `{prefix}{slug}_access_control` table schema and BerlinDB upgrades.
 *
 * One flat row per option value — no JSON storage. See AGENTS.md for the
 * full schema and rule storage convention.
 *
 * @since 1.0.0
 */
class RuleTable extends Table {

	// -------------------------------------------------------------------------
	// Length constants — referenced by Rule\Query and AccessControlUI validation.
	// -------------------------------------------------------------------------

	const NAMESPACE_LENGTH  = 100;
	const KEY_LENGTH        = 255;
	const AC_KEY_LENGTH     = 100;
	const AC_VALUE_LENGTH   = 255;

	// -------------------------------------------------------------------------
	// BerlinDB Table properties
	// -------------------------------------------------------------------------

	/**
	 * Table name without the global `$wpdb->prefix`.
	 *
	 * Set per-instance in the constructor from the slug — left empty by
	 * default so BerlinDB never sees a shared name.
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * Schema class for BerlinDB to instantiate.
	 * BerlinDB 3.0 reads this property in its private set_schema() method.
	 *
	 * @var string
	 */
	protected $schema = RuleSchema::class;

	/**
	 * Schema version as a monotonically-increasing string.
	 * BerlinDB compares this to the stored option to decide which upgrades to run.
	 *
	 * @var string
	 */
	protected $version = '202605120001';

	/**
	 * WordPress option key used to store the installed schema version.
	 *
	 * Set per-instance in the constructor from the slug — `wpb_ac_{slug}_db_version`
	 * — so consumer plugins do not overwrite each other's version pointers.
	 *
	 * @var string
	 */
	protected $db_version_key = '';

	/**
	 * Version-to-method map for BerlinDB's upgrade runner.
	 * Each method must return true on success.
	 *
	 * @var array<string,string>
	 */
	protected $upgrades = array(
		202605120001 => 'upgrade_202605120001',
	);

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * @since 2.0.0
	 *
	 * @param string $table_slug Consumer-supplied slug. See {@see Slug::PATTERN}.
	 *
	 * @throws \InvalidArgumentException When the slug fails validation.
	 */
	public function __construct( string $table_slug ) {
		$slug                 = Slug::sanitize( $table_slug );
		$this->name           = $slug . '_access_control';
		$this->db_version_key = 'wpb_ac_' . $slug . '_db_version';

		parent::__construct();
	}

	// -------------------------------------------------------------------------
	// Upgrade methods
	// -------------------------------------------------------------------------

	/**
	 * First-time migration to the flat-row schema.
	 *
	 * The old TEXT + JSON column cannot transition to normalized VARCHARs via
	 * dbDelta alone, so the table is dropped and recreated. Existing rows are
	 * intentionally discarded — resources default to "no restriction" until
	 * an admin reconfigures them.
	 *
	 * This runs exactly once per slug (when the stored db_version <
	 * 202605120001). Future schema changes must NOT drop the table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on success.
	 */
	protected function upgrade_202605120001(): bool {
		$this->drop();
		return $this->create();
	}
}

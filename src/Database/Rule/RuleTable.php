<?php
/**
 * Rule database table definition for BerlinDB.
 *
 * Defines the {prefix}wpb_access_control schema. RuleQuery instantiates this
 * automatically on first use — consuming plugins do not need to manage it.
 *
 * @package WPBoilerplate\AccessControl\Database\Rule
 * @since   1.0.0
 */

namespace WPBoilerplate\AccessControl\Database\Rule;

use BerlinDB\Database\Kern\Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the {prefix}wpb_access_control table schema and BerlinDB upgrades.
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

	/** @var string Table name without the global $wpdb->prefix. */
	protected $name = 'wpb_access_control';

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
	 * @var string
	 */
	protected $db_version_key = 'wpb_access_control_db_version';

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
	 * This runs exactly once (when stored db_version < 202605120001). Future
	 * schema changes must NOT drop the table.
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

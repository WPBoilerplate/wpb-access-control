<?php
/**
 * Rule query class — CRUD entry point for the access-control table.
 *
 * Wraps BerlinDB's low-level Query API with the higher-level "rule" semantics
 * that the rest of the library needs: one logical rule per (namespace, key)
 * pair, stored as one or more flat rows in the underlying table.
 *
 * Usage
 * -----
 *   $q = new \WPBoilerplate\AccessControl\Database\Rule\Query();
 *
 *   $rule = $q->get_rule( 'procureco/v1', 'endpoints/list' );
 *   // → ['key' => 'wp_role', 'value' => ['editor', 'author']]
 *
 *   $q->set_rule( 'procureco/v1', 'endpoints/list', 'wp_role', ['editor'] );
 *   $q->clear_rule( 'procureco/v1', 'endpoints/list' );
 *   $q->purge_namespace( 'procureco/v1' );
 *
 * @package WPBoilerplate\AccessControl\Database\Rule
 * @since   1.0.0
 */

namespace WPBoilerplate\AccessControl\Database\Rule;

use BerlinDB\Database\Kern\Query;
use WPBoilerplate\AccessControl\Slug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access-control rule query.
 *
 * Each instance is bound to a single consumer slug — the physical table,
 * object-cache group, and transient prefix are all derived from it so two
 * plugins embedding the library never collide.
 *
 * @since 1.0.0
 * @since 2.0.0 Constructor accepts a required `$table_slug` argument.
 */
class RuleQuery extends Query {

	// -------------------------------------------------------------------------
	// BerlinDB Query configuration
	// -------------------------------------------------------------------------

	/**
	 * Table name without `$wpdb->prefix`.
	 *
	 * Set per-instance in the constructor from the slug; never shared
	 * across consumers.
	 *
	 * @var string
	 */
	protected $table_name = '';

	/** @var string Short alias used in SQL JOINs. */
	protected $table_alias = 'wpac';

	/** @var string Fully-qualified Schema class. */
	protected $table_schema = RuleSchema::class;

	/** @var string Singular item label (used internally by BerlinDB). */
	protected $item_name = 'rule';

	/** @var string Plural item label. */
	protected $item_name_plural = 'rules';

	/** @var string Row class to hydrate results into. */
	protected $item_shape = RuleRow::class;

	/**
	 * Object-cache group.
	 *
	 * Set per-instance in the constructor from the slug — `wpb_ac_{slug}`.
	 * Must not contain colons or spaces (BerlinDB restriction).
	 *
	 * @var string
	 */
	protected $cache_group = '';

	/**
	 * Transient TTL in seconds (7 days).
	 *
	 * Transients auto-expire after this period. Any write operation (set_rule,
	 * clear_rule, purge_namespace) invalidates the relevant transient
	 * immediately so stale data is never served.
	 */
	const TRANSIENT_TTL = 604800;

	/**
	 * Slug this Query instance is bound to. Used to scope the transient key
	 * prefix so two consumers do not share cached payloads.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	private $table_slug = '';

	/**
	 * Per-slug registry of already-instantiated RuleTables.
	 *
	 * BerlinDB's Table registers `$wpdb->{name}`, which Query reads via
	 * `get_table_name()`. We need exactly one Table per slug per request;
	 * additional instantiations are no-ops at the BerlinDB layer but waste
	 * cycles, so we guard with this registry.
	 *
	 * @since 2.0.0
	 *
	 * @var array<string,bool>
	 */
	private static $tables_setup_by_slug = array();

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
		$slug              = Slug::sanitize( $table_slug );
		$this->table_slug  = $slug;
		$this->table_name  = $slug . '_access_control';
		$this->cache_group = 'wpb_ac_' . $slug;

		if ( ! isset( self::$tables_setup_by_slug[ $slug ] ) ) {
			self::$tables_setup_by_slug[ $slug ] = true;
			new RuleTable( $slug );
		}

		parent::__construct();
	}

	// -------------------------------------------------------------------------
	// High-level rule API
	// -------------------------------------------------------------------------

	/**
	 * Return the stored access rule for a (namespace, key) pair.
	 *
	 * Aggregates all rows for the pair into the canonical shape
	 * `['key' => string, 'value' => string[]]` so callers do not need to know
	 * about the flat-row storage detail.
	 *
	 * Returns `['key' => '', 'value' => []]` when no rows exist for the pair,
	 * which AccessControlManager treats as "no restriction configured".
	 *
	 * @since 1.0.0
	 *
	 * @param string $namespace Resource namespace.
	 * @param string $key       Resource key within that namespace.
	 *
	 * @return array{key: string, value: string[]}
	 */
	public function get_rule( string $namespace, string $key ): array {
		$tk     = $this->transient_key( $namespace, $key );
		$cached = get_transient( $tk );

		if ( false !== $cached ) {
			return $cached;
		}

		/** @var RuleRow[] $rows */
		$rows = $this->query(
			array(
				'namespace' => $namespace,
				'key'       => $key,
				'orderby'   => 'id',
				'order'     => 'ASC',
				'number'    => 0,
			)
		);

		if ( empty( $rows ) ) {
			$result = array( 'key' => '', 'value' => array() );
		} else {
			$ac_key = (string) $rows[0]->access_control_key;
			$values = array();

			foreach ( $rows as $row ) {
				if ( '' !== $row->access_control_value ) {
					$values[] = (string) $row->access_control_value;
				}
			}

			$result = array( 'key' => $ac_key, 'value' => $values );
		}

		set_transient( $tk, $result, self::TRANSIENT_TTL );

		return $result;
	}

	/**
	 * Insert or replace the access rule for a (namespace, key) pair.
	 *
	 * Atomically replaces all existing rows for the pair with the new rule:
	 *   - `''`             → purge only (no rows = "no restriction configured").
	 *   - `'everyone'`     → one sentinel row with an empty value.
	 *   - `'authenticated'`→ one sentinel row with an empty value.
	 *   - anything else    → one row per option in $ac_options.
	 *
	 * $ac_key is sanitized with sanitize_key() (machine identifier). Each
	 * element of $ac_options is sanitized with sanitize_text_field() so that
	 * provider values containing uppercase letters, dots, or @ symbols (e.g.
	 * email-based identifiers) are preserved correctly.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $namespace  Resource namespace.
	 * @param string   $key        Resource key.
	 * @param string   $ac_key     Rule type slug ('', 'everyone', 'authenticated', 'wp_role', 'wp_user', …).
	 * @param string[] $ac_options Option values (role slugs, user ID strings, etc.).
	 *
	 * @return bool True on success, false if any write fails.
	 */
	public function set_rule(
		string $namespace,
		string $key,
		string $ac_key,
		array  $ac_options
	): bool {
		$normalized = self::normalize_input( $ac_key, $ac_options );
		$ac_key     = $normalized['key'];
		$ac_options = $normalized['value'];

		$this->purge_resource( $namespace, $key );
		delete_transient( $this->transient_key( $namespace, $key ) );

		if ( '' === $ac_key ) {
			return true;
		}

		if ( 'everyone' === $ac_key || 'authenticated' === $ac_key ) {
			return (bool) $this->add_item(
				array(
					'namespace'            => $namespace,
					'key'                  => $key,
					'access_control_key'   => $ac_key,
					'access_control_value' => '',
				)
			);
		}

		$ok = true;
		foreach ( $ac_options as $option ) {
			$inserted = $this->add_item(
				array(
					'namespace'            => $namespace,
					'key'                  => $key,
					'access_control_key'   => $ac_key,
					'access_control_value' => $option,
				)
			);
			if ( false === $inserted ) {
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Delete all rows for a (namespace, key) pair.
	 *
	 * After this call, get_rule() returns `['key' => '', 'value' => []]`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $namespace Resource namespace.
	 * @param string $key       Resource key.
	 *
	 * @return bool True on success (includes the case where no rows existed).
	 */
	public function clear_rule( string $namespace, string $key ): bool {
		$this->purge_resource( $namespace, $key );
		delete_transient( $this->transient_key( $namespace, $key ) );
		return true;
	}

	/**
	 * Delete all rows belonging to a given namespace.
	 *
	 * Call from your plugin's uninstall hook to remove only your rows without
	 * affecting rules stored by other plugins.
	 *
	 * @since 1.0.0
	 *
	 * @param string $namespace Resource namespace to purge.
	 *
	 * @return int Number of rows deleted.
	 */
	public function purge_namespace( string $namespace ): int {
		/** @var RuleRow[] $rows */
		$rows = $this->query(
			array(
				'namespace' => $namespace,
				'number'    => 0,
			)
		);

		$count    = 0;
		$seen_tks = array();

		foreach ( (array) $rows as $row ) {
			$tk = $this->transient_key( $namespace, (string) $row->key );
			if ( ! isset( $seen_tks[ $tk ] ) ) {
				delete_transient( $tk );
				$seen_tks[ $tk ] = true;
			}

			if ( $this->delete_item( (int) $row->id ) ) {
				$count++;
			}
		}

		return $count;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Delete every row for a given (namespace, key) pair.
	 *
	 * @since 1.0.0
	 *
	 * @param string $namespace Resource namespace.
	 * @param string $key       Resource key.
	 *
	 * @return void
	 */
	private function purge_resource( string $namespace, string $key ): void {
		$ids = $this->query(
			array(
				'namespace' => $namespace,
				'key'       => $key,
				'number'    => 0,
				'fields'    => 'ids',
			)
		);

		foreach ( (array) $ids as $id ) {
			$this->delete_item( (int) $id );
		}
	}

	/**
	 * Build a deterministic WordPress transient key for a (namespace, key) pair.
	 *
	 * The slug is included in the MD5 input so two consumers can never collide
	 * on a cached payload. The `wpbac_` prefix keeps every key well within
	 * WordPress's 172-character transient limit.
	 *
	 * @since 1.0.0
	 * @since 2.0.0 The slug is folded into the hash for per-consumer isolation.
	 *
	 * @param string $namespace Resource namespace.
	 * @param string $key       Resource key.
	 *
	 * @return string Transient key, e.g. "wpbac_a1b2c3…".
	 */
	private function transient_key( string $namespace, string $key ): string {
		return 'wpbac_' . md5( $this->table_slug . '|' . $namespace . '|' . $key );
	}

	/**
	 * Sanitize and normalize the rule type and options before storing.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $key     Rule type slug from user input.
	 * @param string[] $options Option values from user input.
	 *
	 * @return array{key: string, value: string[]}
	 */
	private static function normalize_input( string $key, array $options ): array {
		$key     = sanitize_key( $key );
		$options = array_values( array_map( 'sanitize_text_field', $options ) );

		if ( '' === $key || 'everyone' === $key || 'authenticated' === $key ) {
			$options = array();
		}

		return array( 'key' => $key, 'value' => $options );
	}
}

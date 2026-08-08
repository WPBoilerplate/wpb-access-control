<?php
/**
 * Access Control Manager.
 *
 * Provider registry and single entry-point for access decisions.
 * Answers one question: "Does this user have access to this resource?"
 *
 * Usage
 * -----
 *   $manager = new AccessControlManager(
 *       'my_plugin_access_control_providers', // filter tag for provider registration
 *       'my_plugin'                            // table slug — required, see Slug::PATTERN
 *   );
 *
 *   if ( ! $manager->user_has_access( get_current_user_id(), 'my-namespace', 'my-resource' ) ) {
 *       wp_die( 'Access denied.', 403 );
 *   }
 *
 * Provider registry
 * -----------------
 * Register providers via the WordPress filter tag passed to the constructor.
 * Always use a plugin-specific tag to avoid providers from one plugin leaking
 * into another.
 *
 *   add_filter( 'my_plugin_access_control_providers', function( array $providers ) {
 *       $providers[] = new My\Plugin\MembershipProvider();
 *       return $providers;
 *   } );
 *
 * Access hierarchy (evaluated by user_has_access)
 * ------------------------------------------------
 *   1. access_control_key empty or 'everyone'    → allow (public, no login required).
 *   2. access_control_key 'authenticated'        → allow iff user is logged in.
 *   3. User has manage_options (administrator)   → always allow.
 *   4. User not authenticated (id = 0)           → deny.
 *   5. No provider found for the configured key  → deny.
 *   6. provider->user_has_access()               → allow or deny.
 *
 * @package WPBoilerplate\AccessControl
 * @since   1.0.0
 */

namespace WPBoilerplate\AccessControl;

use WPBoilerplate\AccessControl\Database\Rule\RuleQuery;
use WPBoilerplate\AccessControl\RestApi\RulesController;
use WPBoilerplate\AccessControl\Slug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider registry and access decision engine.
 *
 * @since 1.0.0
 */
class AccessControlManager {

	const TYPE_EVERYONE      = 'everyone';
	const TYPE_AUTHENTICATED = 'authenticated';

	/**
	 * WordPress filter tag used to collect provider instances.
	 *
	 * @var string
	 */
	private $providers_filter;

	/**
	 * Consumer-supplied slug. Determines the table name, cache group, and
	 * REST route prefix for this manager instance. See {@see Slug::PATTERN}.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	private $table_slug;

	/**
	 * Registered provider instances, keyed by provider ID.
	 *
	 * @var array<string, AbstractProvider>
	 */
	private $providers = array();

	/**
	 * BerlinDB query instance for reading access rules.
	 *
	 * @var RuleQuery
	 */
	private $query;

	/**
	 * @since 1.0.0
	 * @since 2.0.0 `$table_slug` parameter added and made required so each
	 *              consumer plugin owns its own table, cache group, and REST
	 *              route prefix.
	 *
	 * @param string $providers_filter WordPress filter tag for provider registration.
	 * @param string $table_slug       Per-consumer slug. See {@see Slug::PATTERN}.
	 *
	 * @throws \InvalidArgumentException When the slug fails validation.
	 */
	public function __construct( string $providers_filter, string $table_slug ) {
		$this->providers_filter = $providers_filter;
		$this->table_slug       = Slug::sanitize( $table_slug );
		$this->query            = new RuleQuery( $this->table_slug );

		if ( did_action( 'init' ) ) {
			$this->load_providers();
		} else {
			add_action( 'init', array( $this, 'load_providers' ), 5 );
		}
	}

	/**
	 * Return the slug this manager (and its table / cache group / REST
	 * routes) is bound to.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_table_slug(): string {
		return $this->table_slug;
	}

	// -------------------------------------------------------------------------
	// Provider registry
	// -------------------------------------------------------------------------

	/**
	 * Resolve all enabled providers via the configured filter.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_providers(): void {
		$default_providers = array(
			new WpRoleProvider(),
			new WpUserProvider(),
			new WpCapabilityProvider(),
		);

		/**
		 * Register additional access-control providers globally.
		 *
		 * Extension plugins (e.g. AcrossAI User Access Pro) hook this filter
		 * once to make their providers available to every consumer of the
		 * library, without any per-consumer bootstrap code. Fires *before*
		 * the consumer-specific filter, so consumers can still veto or
		 * customise anything an extension registered.
		 *
		 * @since 3.0.0
		 *
		 * @param AbstractProvider[] $providers  Providers registered so far.
		 * @param string             $table_slug Per-consumer slug — extensions
		 *                                       can inspect this to opt out of
		 *                                       specific consumers if desired.
		 */
		$default_providers = (array) apply_filters(
			'wpb_access_control_register_providers',
			$default_providers,
			$this->table_slug
		);

		/**
		 * Filter the list of registered access-control providers for this
		 * consumer instance only.
		 *
		 * @since 1.0.0
		 *
		 * @param AbstractProvider[] $providers Providers after the global filter has run.
		 */
		$providers = (array) apply_filters( $this->providers_filter, $default_providers );

		$this->providers = array();
		foreach ( $providers as $provider ) {
			if ( $provider instanceof AbstractProvider ) {
				$this->providers[ $provider->get_id() ] = $provider;
			}
		}
	}

	/**
	 * Return all registered providers indexed by their ID.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, AbstractProvider>
	 */
	public function get_providers(): array {
		return $this->providers;
	}

	/**
	 * Return a single provider by its ID, or null if not found.
	 *
	 * @since 1.0.0
	 *
	 * @param string $provider_id Provider identifier (e.g. 'wp_role').
	 *
	 * @return AbstractProvider|null
	 */
	public function get_provider( string $provider_id ): ?AbstractProvider {
		return $this->providers[ $provider_id ] ?? null;
	}

	/**
	 * Return the RuleQuery instance for direct rule reads and writes.
	 *
	 * @since 1.0.0
	 *
	 * @return RuleQuery
	 */
	public function get_query(): RuleQuery {
		return $this->query;
	}

	/**
	 * Register the REST API controller for the wpb-ac/v1 namespace.
	 *
	 * Call this inside a `rest_api_init` hook. The consuming plugin decides
	 * whether to expose the REST API and is responsible for the hook timing.
	 *
	 * Example:
	 *   add_action( 'rest_api_init', function() use ( $manager ) {
	 *       $manager->register_rest_api();
	 *   } );
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_rest_api(): void {
		( new RulesController( $this, $this->table_slug ) )->register_routes();
	}

	// -------------------------------------------------------------------------
	// Access decision
	// -------------------------------------------------------------------------

	/**
	 * Determine whether a user may access a specific resource.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id   WordPress user ID (0 = unauthenticated).
	 * @param string $namespace Resource namespace.
	 * @param string $key       Resource key within that namespace.
	 *
	 * @return bool True when access is granted.
	 */
	public function user_has_access( int $user_id, string $namespace, string $key ): bool {
		$row     = $this->query->get_rule( $namespace, $key );
		$ac_key  = $row['key'];
		$options = $row['value'];

		if ( '' === $ac_key || self::TYPE_EVERYONE === $ac_key ) {
			return true;
		}

		if ( self::TYPE_AUTHENTICATED === $ac_key ) {
			if ( $user_id > 0 ) {
				return true;
			}
			do_action( 'wpb_access_control_denied', $user_id, $namespace, $key, $ac_key, $options );
			return false;
		}

		if ( $user_id && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		if ( ! $user_id ) {
			/**
			 * Fires when access is denied.
			 *
			 * @since 1.0.0
			 *
			 * @param int      $user_id   The requesting user ID (0 = unauthenticated).
			 * @param string   $namespace Resource namespace.
			 * @param string   $key       Resource key.
			 * @param string   $ac_key    Rule type slug.
			 * @param string[] $options   Rule options (role slugs, user IDs, etc.).
			 */
			do_action( 'wpb_access_control_denied', $user_id, $namespace, $key, $ac_key, $options );
			return false;
		}

		$provider = $this->get_provider( $ac_key );

		if ( null === $provider ) {
			do_action( 'wpb_access_control_denied', $user_id, $namespace, $key, $ac_key, $options );
			return false;
		}

		$allowed = $provider->user_has_access( $user_id, $options );

		if ( ! $allowed ) {
			do_action( 'wpb_access_control_denied', $user_id, $namespace, $key, $ac_key, $options );
		}

		return $allowed;
	}
}

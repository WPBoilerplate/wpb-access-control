<?php
/**
 * WordPress Capability Access Control Provider.
 *
 * Restricts access by one or more WordPress capability slugs (primitive or
 * meta caps such as `install_plugins`, `edit_posts`, `manage_options`).
 * Administrators always bypass this check (handled by AccessControlManager).
 *
 * Stored format
 * -------------
 * Capability slugs are stored as plain strings, one per row:
 *   access_control_key = 'wp_capability', access_control_value = 'install_plugins'
 *
 * Match semantics
 * ---------------
 * ANY (OR): a user is allowed when `user_can()` returns true for **any one**
 * of the selected capabilities. This mirrors `WpRoleProvider` so the role and
 * capability providers behave consistently.
 *
 * Discovery
 * ---------
 * `get_options()` walks every role returned by `wp_roles()->roles` and
 * collects the union of capability slugs whose value is truthy (denied caps
 * stored as `false` are skipped). This picks up custom capabilities added by
 * plugins like WooCommerce, Members, or User Role Editor automatically. Use
 * the `wpb_access_control_wp_capability_options` filter to curate, add, or
 * remove entries — e.g. to surface a cap that is not yet attached to any role.
 *
 * @package WPBoilerplate\AccessControl
 * @since   1.3.0
 */

namespace WPBoilerplate\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider that gates access by WordPress capability slug.
 *
 * @since 1.3.0
 */
class WpCapabilityProvider extends AbstractProvider {

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'wp_capability';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'WordPress Capability', 'wpb-access-control' );
	}

	/**
	 * Return the union of capability slugs across every registered role.
	 *
	 * Caps stored with a falsy value (explicitly denied on a role) are
	 * skipped. The list is deduplicated and alphabetically sorted for a
	 * stable UI order. Apply the `wpb_access_control_wp_capability_options`
	 * filter to inject or remove entries.
	 *
	 * @since 1.3.0
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	public function get_options(): array {
		$roles = \wp_roles();
		$caps  = array();

		if ( $roles && isset( $roles->roles ) && is_array( $roles->roles ) ) {
			foreach ( $roles->roles as $role ) {
				if ( ! isset( $role['capabilities'] ) || ! is_array( $role['capabilities'] ) ) {
					continue;
				}

				foreach ( $role['capabilities'] as $cap => $granted ) {
					if ( ! $granted ) {
						continue;
					}
					$caps[ $cap ] = true;
				}
			}
		}

		$cap_slugs = array_keys( $caps );
		sort( $cap_slugs, SORT_STRING );

		$options = array();
		foreach ( $cap_slugs as $slug ) {
			$options[] = array(
				'id'    => $slug,
				'label' => $slug,
			);
		}

		/**
		 * Filter the WordPress capability options shown in the access control UI.
		 *
		 * Each entry is `[ 'id' => 'cap_slug', 'label' => 'cap_slug' ]`. Use this
		 * filter to surface plugin capabilities that no role currently holds, or
		 * to hide caps that should not be selectable from the admin UI.
		 *
		 * @since 1.3.0
		 *
		 * @param array<int, array{id: string, label: string}> $options List of capability options.
		 */
		return (array) apply_filters( 'wpb_access_control_wp_capability_options', $options );
	}

	/**
	 * Return true when the user holds at least one of the selected capabilities.
	 *
	 * Administrators bypass this check via AccessControlManager and will never
	 * reach this method.
	 *
	 * @since 1.3.0
	 *
	 * @param int      $user_id          WordPress user ID.
	 * @param string[] $selected_options Capability slugs the admin has allowed.
	 *
	 * @return bool
	 */
	public function user_has_access( int $user_id, array $selected_options ): bool {
		if ( empty( $selected_options ) ) {
			// No caps selected means the rule is "nobody" (admins already passed).
			return false;
		}

		foreach ( $selected_options as $cap ) {
			if ( '' === $cap ) {
				continue;
			}
			if ( \user_can( $user_id, $cap ) ) {
				return true;
			}
		}

		/**
		 * Filter the final access decision for a WP-capability check.
		 *
		 * @since 1.3.0
		 *
		 * @param bool     $has_access       Result before the filter.
		 * @param int      $user_id          User being checked.
		 * @param string[] $selected_options Allowed capability slugs.
		 */
		return (bool) apply_filters( 'wpb_access_control_wp_capability_has_access', false, $user_id, $selected_options );
	}
}

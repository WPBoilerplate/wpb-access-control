<?php
/**
 * BuddyBoss Profile Type Access Control Provider.
 *
 * Restricts access to specific BuddyBoss profile types (member types).
 * Administrators always bypass this check (handled by AccessControlManager).
 *
 * Dependency
 * ----------
 * Requires the BuddyBoss Platform plugin to be active. When inactive,
 * `is_available()` returns false — the REST `/providers` payload surfaces
 * this to the React UI, which hides the option in the dropdown automatically.
 * Every method guards the BuddyBoss API behind `is_available()`, so no fatal
 * errors are possible when the plugin is missing.
 *
 * Stored format
 * -------------
 * Profile type slugs are stored as plain strings, one per row:
 *   access_control_key = 'bb_profile_type', access_control_value = 'customer'
 *
 * Match semantics
 * ---------------
 * ANY (OR): a user is allowed when at least one of their assigned profile
 * types appears in the selected list. Mirrors `WpRoleProvider` for
 * consistency.
 *
 * BuddyBoss APIs used
 * -------------------
 *  - `bp_get_member_types( $args, 'objects' )` — list all registered types
 *    (admin-created via Profile Types CPT + code-registered).
 *    Returns `[ slug => $type_object ]`. Human label lives in
 *    `$type_object->labels['singular_name']`.
 *  - `bp_get_member_type( int $user_id, false )` — return the array of
 *    profile-type slugs assigned to a user, or `false` when the user has
 *    none. Handles `$user_id = 0` gracefully.
 *
 * @package WPBoilerplate\AccessControl
 * @since   1.4.0
 */

namespace WPBoilerplate\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider that gates access by BuddyBoss profile type (member type).
 *
 * @since 1.4.0
 */
class BuddyBossProfileTypeProvider extends AbstractProvider {

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'bb_profile_type';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.4.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'BuddyBoss Profile Type', 'wpb-access-control' );
	}

	/**
	 * Return whether the provider should fire for the current request.
	 *
	 * Two conditions must hold:
	 *  1. BuddyBoss Platform exposes its member-types API
	 *     (`function_exists( 'bp_get_member_type'/'bp_get_member_types' )`).
	 *  2. The consumer plugin has opted in by hooking
	 *     `wpb_access_control_bb_profile_type_enabled` to return `true`.
	 *
	 * The opt-in default is `false` so plugins that embed this library do
	 * not silently expose a BuddyBoss option in their UI just because
	 * BuddyBoss happens to be active on the site.
	 *
	 * The REST `/providers` endpoint forwards this flag to the React UI,
	 * which hides the dropdown option when false; `get_options()` and
	 * `user_has_access()` short-circuit on the same gate.
	 *
	 * @since 1.4.0
	 * @since 1.6.0 Added the `wpb_access_control_bb_profile_type_enabled` opt-in filter.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! function_exists( 'bp_get_member_type' ) || ! function_exists( 'bp_get_member_types' ) ) {
			return false;
		}

		/**
		 * Filter whether the BuddyBoss Profile Type provider is enabled.
		 *
		 * Defaults to `false` — the consumer plugin must explicitly opt in
		 * by returning `true`. When `false`, the provider is hidden from
		 * the React dropdown, `get_options()` returns `[]`, and
		 * `user_has_access()` denies regardless of any stored rule.
		 *
		 * Example (in your plugin's bootstrap):
		 *
		 *   add_filter( 'wpb_access_control_bb_profile_type_enabled', '__return_true' );
		 *
		 * @since 1.6.0
		 *
		 * @param bool $enabled Whether the provider should be active. Default false.
		 */
		return (bool) apply_filters( 'wpb_access_control_bb_profile_type_enabled', false );
	}

	/**
	 * Return every available BuddyBoss profile type as a selectable option.
	 *
	 * Returns an empty list when BuddyBoss is inactive so the UI surfaces an
	 * empty checkbox panel instead of stale slugs. Options are
	 * alphabetically sorted by label for a stable UI order.
	 *
	 * @since 1.4.0
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	public function get_options(): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		$types = \bp_get_member_types( array(), 'objects' );

		$options = array();
		if ( is_array( $types ) ) {
			foreach ( $types as $slug => $type ) {
				$label = '';
				if ( is_object( $type ) && isset( $type->labels ) && is_array( $type->labels ) ) {
					if ( ! empty( $type->labels['singular_name'] ) ) {
						$label = (string) $type->labels['singular_name'];
					}
				}
				if ( '' === $label ) {
					$label = (string) $slug;
				}

				$options[] = array(
					'id'    => (string) $slug,
					'label' => $label,
				);
			}
		}

		usort(
			$options,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		/**
		 * Filter the BuddyBoss profile-type options shown in the access control UI.
		 *
		 * Each entry is `[ 'id' => 'type_slug', 'label' => 'Human Name' ]`. Use this
		 * filter to surface programmatically-registered types that should remain
		 * hidden from the admin UI, or to inject a synthetic option.
		 *
		 * @since 1.4.0
		 *
		 * @param array<int, array{id: string, label: string}> $options List of profile-type options.
		 */
		return (array) apply_filters( 'wpb_access_control_bb_profile_type_options', $options );
	}

	/**
	 * Return true when the user is assigned to at least one of the allowed profile types.
	 *
	 * Administrators bypass this check via AccessControlManager and will never
	 * reach this method.
	 *
	 * @since 1.4.0
	 *
	 * @param int      $user_id          WordPress user ID.
	 * @param string[] $selected_options Profile-type slugs the admin has allowed.
	 *
	 * @return bool
	 */
	public function user_has_access( int $user_id, array $selected_options ): bool {
		if ( ! $this->is_available() ) {
			return false;
		}

		if ( empty( $selected_options ) ) {
			return false;
		}

		$user_types = \bp_get_member_type( $user_id, false );

		$result = false;
		if ( is_array( $user_types ) && ! empty( $user_types ) ) {
			$result = ! empty( array_intersect( $selected_options, $user_types ) );
		}

		/**
		 * Filter the final access decision for a BuddyBoss profile-type check.
		 *
		 * @since 1.4.0
		 *
		 * @param bool     $has_access       Result before the filter.
		 * @param int      $user_id          User being checked.
		 * @param string[] $selected_options Allowed profile-type slugs.
		 */
		return (bool) apply_filters( 'wpb_access_control_bb_profile_type_has_access', $result, $user_id, $selected_options );
	}
}

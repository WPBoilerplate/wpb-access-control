<?php
/**
 * MemberPress Membership Access Control Provider.
 *
 * Restricts access to users who hold one or more selected MemberPress
 * memberships (the `memberpressproduct` CPT). Administrators always bypass
 * this check (handled by AccessControlManager).
 *
 * Dependency
 * ----------
 * Requires the MemberPress plugin to be active. When inactive,
 * `is_available()` returns false — the REST `/providers` payload surfaces
 * this to the React UI, which hides the option in the dropdown automatically.
 * Every method guards the MemberPress API behind `is_available()`, so no
 * fatal errors are possible when the plugin is missing.
 *
 * Stored format
 * -------------
 * Membership post IDs are stored as strings, one per row:
 *   access_control_key = 'mepr_membership', access_control_value = '42'
 *
 * Match semantics
 * ---------------
 * ANY (OR): a user is allowed when at least one of their active product
 * subscriptions matches the selected list. Mirrors `WpRoleProvider`.
 *
 * MemberPress APIs used
 * ---------------------
 *  - `defined( 'MEPR_VERSION' )` + `class_exists( 'MeprUser' )` — availability gate.
 *  - `get_posts(['post_type'=>'memberpressproduct', ...])` — list memberships.
 *    The CPT is registered by `MeprProductsCtrl::register_post_type()`; we use
 *    `get_posts()` directly instead of `MeprProduct::get_all()` so the call
 *    works even when MemberPress's autoloader hasn't loaded the model class.
 *  - `( new MeprUser( int $user_id ) )->active_product_subscriptions()` —
 *    returns the array of active membership post IDs the user holds; returns
 *    `[]` for `$user_id = 0` (unauthenticated).
 *
 * @package WPBoilerplate\AccessControl
 * @since   1.5.0
 */

namespace WPBoilerplate\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider that gates access by MemberPress membership.
 *
 * @since 1.5.0
 */
class MemberPressMembershipProvider extends AbstractProvider {

	/**
	 * MemberPress CPT slug for memberships ("products").
	 *
	 * @since 1.5.0
	 *
	 * @var string
	 */
	private const POST_TYPE = 'memberpressproduct';

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'mepr_membership';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'MemberPress Membership', 'wpb-access-control' );
	}

	/**
	 * Return whether the provider should fire for the current request.
	 *
	 * Two conditions must hold:
	 *  1. MemberPress is active (`defined( 'MEPR_VERSION' )` and
	 *     `class_exists( 'MeprUser' )`).
	 *  2. The consumer plugin has opted in by hooking
	 *     `wpb_access_control_mepr_membership_enabled` to return `true`.
	 *
	 * The opt-in default is `false` so plugins that embed this library do
	 * not silently expose a MemberPress option in their UI just because
	 * MemberPress happens to be active on the site.
	 *
	 * The REST `/providers` endpoint forwards this flag to the React UI,
	 * which hides the dropdown option when false; `get_options()` and
	 * `user_has_access()` short-circuit on the same gate.
	 *
	 * @since 1.5.0
	 * @since 1.6.0 Added the `wpb_access_control_mepr_membership_enabled` opt-in filter.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! defined( 'MEPR_VERSION' ) || ! class_exists( 'MeprUser' ) ) {
			return false;
		}

		/**
		 * Filter whether the MemberPress Membership provider is enabled.
		 *
		 * Defaults to `false` — the consumer plugin must explicitly opt in
		 * by returning `true`. When `false`, the provider is hidden from
		 * the React dropdown, `get_options()` returns `[]`, and
		 * `user_has_access()` denies regardless of any stored rule.
		 *
		 * Example (in your plugin's bootstrap):
		 *
		 *   add_filter( 'wpb_access_control_mepr_membership_enabled', '__return_true' );
		 *
		 * @since 1.6.0
		 *
		 * @param bool $enabled Whether the provider should be active. Default false.
		 */
		return (bool) apply_filters( 'wpb_access_control_mepr_membership_enabled', false );
	}

	/**
	 * Return every published MemberPress membership as a selectable option.
	 *
	 * Returns an empty list when MemberPress is inactive so the UI surfaces an
	 * empty checkbox panel instead of stale IDs. Memberships are ordered by
	 * title for a stable UI order.
	 *
	 * @since 1.5.0
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	public function get_options(): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		$memberships = \get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$options = array();
		if ( is_array( $memberships ) ) {
			foreach ( $memberships as $membership ) {
				if ( ! isset( $membership->ID ) ) {
					continue;
				}

				$title = isset( $membership->post_title ) ? (string) $membership->post_title : '';
				if ( '' === $title ) {
					$title = sprintf(
						/* translators: %d: membership post ID */
						__( 'Membership #%d', 'wpb-access-control' ),
						(int) $membership->ID
					);
				}

				$options[] = array(
					'id'    => (string) (int) $membership->ID,
					'label' => $title,
				);
			}
		}

		/**
		 * Filter the MemberPress membership options shown in the access control UI.
		 *
		 * Each entry is `[ 'id' => 'membership_id', 'label' => 'Membership Title' ]`.
		 * Use this filter to hide or rename memberships in the admin UI.
		 *
		 * @since 1.5.0
		 *
		 * @param array<int, array{id: string, label: string}> $options List of membership options.
		 */
		return (array) apply_filters( 'wpb_access_control_mepr_membership_options', $options );
	}

	/**
	 * Return true when the user has at least one of the allowed memberships.
	 *
	 * Administrators bypass this check via AccessControlManager and will never
	 * reach this method.
	 *
	 * @since 1.5.0
	 *
	 * @param int      $user_id          WordPress user ID.
	 * @param string[] $selected_options Membership post IDs (as strings) the admin has allowed.
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

		$result = false;

		// MeprUser::active_product_subscriptions() returns an array of int IDs
		// (matching memberpressproduct post IDs) and `[]` for $user_id = 0.
		$user                 = new \MeprUser( $user_id );
		$active_subscriptions = $user->active_product_subscriptions();

		if ( is_array( $active_subscriptions ) && ! empty( $active_subscriptions ) ) {
			// Normalize to strings for a strict intersect against the stored
			// option list (RuleQuery stores option values as sanitised strings).
			$active_ids = array_map( 'strval', $active_subscriptions );
			$result     = ! empty( array_intersect( $selected_options, $active_ids ) );
		}

		/**
		 * Filter the final access decision for a MemberPress membership check.
		 *
		 * @since 1.5.0
		 *
		 * @param bool     $has_access       Result before the filter.
		 * @param int      $user_id          User being checked.
		 * @param string[] $selected_options Allowed membership IDs as strings.
		 */
		return (bool) apply_filters( 'wpb_access_control_mepr_membership_has_access', $result, $user_id, $selected_options );
	}
}

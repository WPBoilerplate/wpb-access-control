<?php
/**
 * WordPress User Access Control Provider.
 *
 * Restricts access to a specific list of WordPress users.
 * Administrators always bypass this check (handled by AccessControlManager).
 *
 * Stored format
 * -------------
 * User IDs are stored as strings in the options array:
 *   { "type": "wp_user", "options": ["1", "42", "7"] }
 *
 * Why IDs and not usernames/emails
 * ---------------------------------
 * AccessControlTable::sanitize() runs sanitize_key() on every option value,
 * which strips @, dots, and other characters from email addresses. Storing the
 * integer user ID as a string ("42") is the only value that survives the
 * sanitization pipeline unchanged.
 *
 * Admin UI AJAX support
 * ----------------------
 * get_options() returns an empty array — there is no static list of users to
 * render as checkboxes. The consuming plugin must implement its own AJAX
 * handler for the search-as-you-type UI. Use the static helpers:
 *   - WpUserProvider::search_users( $term )  — for live search results
 *   - WpUserProvider::get_users_by_ids( $ids ) — to reload previously saved users
 *
 * @package WPBoilerplate\AccessControl
 * @since   1.1.0
 */

namespace WPBoilerplate\AccessControl;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider that gates access by specific WordPress user IDs.
 *
 * @since 1.1.0
 */
class WpUserProvider extends AbstractProvider {

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'wp_user';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Users', 'wpb-access-control' );
	}

	/**
	 * Users are selected dynamically via AJAX search — no static list.
	 *
	 * The consuming plugin must render the selected user IDs itself. Use
	 * WpUserProvider::get_users_by_ids() to hydrate IDs back into display data.
	 *
	 * @since 1.1.0
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	public function get_options(): array {
		return array();
	}

	/**
	 * Return true when the user's ID appears in the allowed list.
	 *
	 * IDs in $selected_options are compared as strings to match what is stored
	 * in the database after sanitize_key() processing.
	 *
	 * @since 1.1.0
	 *
	 * @param int      $user_id          WordPress user ID.
	 * @param string[] $selected_options User IDs (as strings) the admin has allowed.
	 *
	 * @return bool
	 */
	public function user_has_access( int $user_id, array $selected_options ): bool {
		if ( empty( $selected_options ) ) {
			// No users selected — nobody is permitted (admins already bypassed).
			return false;
		}

		$result = in_array( (string) $user_id, $selected_options, true );

		/**
		 * Filter the final access decision for a WP-user check.
		 *
		 * @since 1.1.0
		 *
		 * @param bool     $has_access       Result before the filter.
		 * @param int      $user_id          User being checked.
		 * @param string[] $selected_options Allowed user IDs as strings.
		 */
		return (bool) apply_filters( 'wpb_access_control_wp_user_has_access', $result, $user_id, $selected_options );
	}

	// -------------------------------------------------------------------------
	// Admin UI helpers — static so consuming plugins can call them from their
	// own AJAX handlers without needing a provider instance.
	// -------------------------------------------------------------------------

	/**
	 * Search for WordPress users by login, email, or display name.
	 *
	 * Use this inside your AJAX handler to power the live search UI.
	 * Always verify nonce and capability before calling.
	 *
	 * Example AJAX handler in your consuming plugin:
	 *
	 *   add_action( 'wp_ajax_my_plugin_search_users', function() {
	 *       check_ajax_referer( 'my_plugin_ac_nonce' );
	 *       if ( ! current_user_can( 'manage_options' ) ) {
	 *           wp_send_json_error( 'Forbidden', 403 );
	 *       }
	 *       $term = sanitize_text_field( $_GET['term'] ?? '' );
	 *       wp_send_json_success(
	 *           WpUserProvider::search_users( $term )
	 *       );
	 *   } );
	 *
	 * @since 1.1.0
	 *
	 * @param string $search Search term (partial login, email, or display name).
	 * @param int    $limit  Maximum number of results to return. Default 10.
	 *
	 * @return array<int, array{id: string, login: string, email: string, display_name: string}>
	 */
	public static function search_users( string $search, int $limit = 10 ): array {
		$search = sanitize_text_field( $search );

		if ( '' === $search ) {
			return array();
		}

		$users = get_users(
			array(
				'search'         => '*' . $search . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'number'         => max( 1, $limit ),
				'fields'         => array( 'ID', 'user_login', 'user_email', 'display_name' ),
				'orderby'        => 'display_name',
				'order'          => 'ASC',
			)
		);

		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'id'           => (string) $user->ID,
				'login'        => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
			);
		}

		return $results;
	}

	/**
	 * Hydrate a list of stored user ID strings back into display data.
	 *
	 * Use this when rendering the admin settings page to show who is currently
	 * allowed, rather than just their raw IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $user_ids Array of user ID strings (as stored in options).
	 *
	 * @return array<int, array{id: string, login: string, email: string, display_name: string}>
	 */
	public static function get_users_by_ids( array $user_ids ): array {
		$ids = array_filter( array_map( 'absint', $user_ids ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => array_values( $ids ),
				'fields'  => array( 'ID', 'user_login', 'user_email', 'display_name' ),
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'id'           => (string) $user->ID,
				'login'        => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
			);
		}

		return $results;
	}
}

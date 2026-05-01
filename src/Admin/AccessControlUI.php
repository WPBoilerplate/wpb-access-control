<?php
/**
 * Access Control Admin UI.
 *
 * Ships a complete, reusable access-control settings panel — type dropdown,
 * per-provider option rows, user search-as-you-type with multi-select tags —
 * so consuming plugins drop in a single render() call instead of implementing
 * the UI themselves.
 *
 * Quick-start for consuming plugins
 * -----------------------------------
 * 1. Instantiate once alongside your AccessControlManager (e.g. in plugins_loaded):
 *
 *      $ui = new \WPBoilerplate\AccessControl\Admin\AccessControlUI( $manager );
 *
 * 2. Call enqueue_assets() from your admin_enqueue_scripts hook:
 *
 *      add_action( 'admin_enqueue_scripts', function() use ( $ui ) {
 *          $ui->enqueue_assets();
 *      } );
 *
 * 3. Call render() wherever you want the panel (renders full <form> by default):
 *
 *      $ui->render( 'my-namespace', 'my-resource', [
 *          'form_action'  => admin_url( 'admin.php?page=my-plugin&action=save_ac' ),
 *          'nonce_action' => 'my_plugin_save_ac',
 *          'submit_label' => __( 'Save', 'my-plugin' ),
 *      ] );
 *
 * 4. In your POST save handler, extract the sanitized JSON and store it:
 *
 *      check_admin_referer( 'my_plugin_save_ac' );
 *      $json = \WPBoilerplate\AccessControl\Admin\AccessControlUI::extract_posted_config( $_POST );
 *      AccessControlTable::update( 'my-namespace', 'my-resource', $json );
 *
 * @package WPBoilerplate\AccessControl\Admin
 * @since   1.2.0
 */

namespace WPBoilerplate\AccessControl\Admin;

use WPBoilerplate\AccessControl\AccessControlManager;
use WPBoilerplate\AccessControl\AccessControlTable;
use WPBoilerplate\AccessControl\WpUserProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the access-control admin panel and handles the user-search AJAX action.
 *
 * @since 1.2.0
 */
class AccessControlUI {

	/**
	 * The manager instance that supplies the registered provider list.
	 *
	 * @var AccessControlManager
	 */
	private $manager;

	/**
	 * Explicit asset base URL. Null = auto-detect from WP_CONTENT_DIR/URL.
	 *
	 * @var string|null
	 */
	private $assets_url = null;

	/**
	 * Guard: AJAX action registered exactly once across all instances.
	 *
	 * @var bool
	 */
	private static $ajax_registered = false;

	/**
	 * Monotonic counter to generate unique per-form DOM IDs.
	 *
	 * @var int
	 */
	private static $instance_count = 0;

	/**
	 * Constructor.
	 *
	 * Registers the shared user-search AJAX action (idempotent — only once
	 * per request even if multiple plugins instantiate AccessControlUI).
	 *
	 * @since 1.2.0
	 *
	 * @param AccessControlManager $manager Provider registry from the consuming plugin.
	 */
	public function __construct( AccessControlManager $manager ) {
		$this->manager = $manager;

		if ( ! self::$ajax_registered ) {
			self::$ajax_registered = true;
			add_action( 'wp_ajax_wpb_access_control_search_users', array( $this, 'ajax_search_users' ) );
			add_action( 'wp_ajax_wpb_access_control_save', array( $this, 'ajax_save' ) );
		}
	}

	/**
	 * Override the auto-detected asset base URL.
	 *
	 * Use this when the package is installed in an unusual location (symlinked,
	 * outside wp-content, etc.) and the automatic WP_CONTENT_DIR/URL resolver
	 * produces a wrong URL.
	 *
	 * @since 1.2.0
	 *
	 * @param string $url Absolute URL pointing at the package's assets/ directory,
	 *                    without a trailing slash. Example: plugins_url( 'vendor/wpboilerplate/wpb-access-control/assets', __FILE__ )
	 *
	 * @return void
	 */
	public function set_assets_url( string $url ): void {
		$this->assets_url = untrailingslashit( $url );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Render the access-control settings panel.
	 *
	 * The form submits via AJAX — no form_action or nonce_action needed from
	 * the consumer. The library registers the save action and handles the
	 * response inline (success notice or error message). Namespace and key
	 * are embedded as hidden inputs so the handler knows which rule to update.
	 *
	 * @since 1.2.0
	 *
	 * @param string $namespace Resource namespace (e.g. 'mcp', 'procureco/v1').
	 * @param string $key       Resource key within that namespace.
	 * @param array  $args {
	 *     @type string $submit_label Submit button label. Default "Save Access Control".
	 *     @type string $description  Paragraph shown below the heading.
	 * }
	 *
	 * @return void
	 */
	public function render( string $namespace, string $key, array $args = array() ): void {
		self::$instance_count++;
		$form_id = 'wpb-ac-' . self::$instance_count;

		$submit_label = isset( $args['submit_label'] ) ? (string) $args['submit_label'] : __( 'Save Access Control', 'wpb-access-control' );
		$description  = isset( $args['description'] )
			? (string) $args['description']
			: __( 'Control which users are allowed to access this resource. Administrators always have access regardless of this setting.', 'wpb-access-control' );

		// Resolve current stored config.
		$raw_ac    = AccessControlTable::get( $namespace, $key );
		$ac_config = array( 'type' => 'everyone', 'options' => array() );
		if ( '' !== $raw_ac ) {
			$decoded = json_decode( $raw_ac, true );
			if ( is_array( $decoded ) ) {
				$ac_config['type']    = sanitize_key( $decoded['type'] ?? 'everyone' );
				$ac_config['options'] = array_map( 'sanitize_key', (array) ( $decoded['options'] ?? array() ) );
			}
		}

		$providers = $this->manager->get_providers();
		?>
		<div class="wpb-ac-panel" data-wpb-ac-form="<?php echo esc_attr( $form_id ); ?>">

			<!-- Inline notice shown after AJAX save (hidden until JS populates it). -->
			<div class="wpb-ac-notice" style="display:none;" aria-live="polite"></div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			      class="wpb-ac-form">

				<!-- Library-owned save action and nonce — consumer sets neither. -->
				<input type="hidden" name="action"       value="wpb_access_control_save">
				<input type="hidden" name="wpb_ac_nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpb_access_control_save' ) ); ?>">
				<input type="hidden" name="wpb_ac_ns"    value="<?php echo esc_attr( $namespace ); ?>">
				<input type="hidden" name="wpb_ac_key"   value="<?php echo esc_attr( $key ); ?>">

				<h2><?php esc_html_e( 'Access Control', 'wpb-access-control' ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>

						<!-- Type selector -->
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $form_id . '-type' ); ?>">
									<?php esc_html_e( 'Who can access', 'wpb-access-control' ); ?>
								</label>
							</th>
							<td>
								<select name="ac_type"
								        id="<?php echo esc_attr( $form_id . '-type' ); ?>"
								        class="regular-text wpb-ac-type-select">
									<option value="everyone" <?php selected( $ac_config['type'], 'everyone' ); ?>>
										<?php esc_html_e( 'Everyone (no restriction)', 'wpb-access-control' ); ?>
									</option>
									<?php foreach ( $providers as $provider_id => $provider ) : ?>
										<?php if ( $provider->is_available() ) : ?>
											<option value="<?php echo esc_attr( $provider_id ); ?>"
											        <?php selected( $ac_config['type'], $provider_id ); ?>>
												<?php echo esc_html( $provider->get_label() ); ?>
											</option>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>

						<!-- Per-provider option rows (shown/hidden by JS) -->
						<?php foreach ( $providers as $provider_id => $provider ) : ?>
							<?php if ( ! $provider->is_available() ) { continue; } ?>
							<tr class="wpb-ac-options-row wpb-ac-options-<?php echo esc_attr( $provider_id ); ?>"
							    style="<?php echo $ac_config['type'] === $provider_id ? '' : 'display:none'; ?>">
								<th scope="row"><?php echo esc_html( $provider->get_label() ); ?></th>
								<td>
									<?php $provider->render_options( $ac_config['options'], $form_id ); ?>
								</td>
							</tr>
						<?php endforeach; ?>

					</tbody>
				</table>

				<p class="submit">
					<?php submit_button( $submit_label, 'primary', 'submit', false ); ?>
				</p>
			</form>
		</div><!-- .wpb-ac-panel -->
		<?php
	}

	/**
	 * Enqueue the library's admin CSS and JS.
	 *
	 * Call this from your plugin's admin_enqueue_scripts hook. Safe to call
	 * multiple times — WordPress skips double-enqueues by handle.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$base = $this->resolve_assets_url();
		$ver  = filemtime( dirname( __DIR__, 2 ) . '/assets/css/admin.css' ) ?: '1.2.0';

		wp_enqueue_style(
			'wpb-access-control-admin',
			$base . '/css/admin.css',
			array(),
			$ver
		);

		wp_enqueue_script(
			'wpb-access-control-admin',
			$base . '/js/admin.js',
			array(),
			filemtime( dirname( __DIR__, 2 ) . '/assets/js/admin.js' ) ?: '1.2.0',
			true
		);

		wp_localize_script(
			'wpb-access-control-admin',
			'wpbAcAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpb_access_control_search_users' ),
				'i18n'    => array(
					'searching' => __( 'Searching…', 'wpb-access-control' ),
					'noResults' => __( 'No users found.', 'wpb-access-control' ),
					'placeholder' => __( 'Search by username or email…', 'wpb-access-control' ),
				),
			)
		);
	}

	/**
	 * Extract and return a sanitized access-control JSON string from POST data.
	 *
	 * Reads ac_type and ac_options[] from the supplied array (pass $_POST).
	 * Does NOT verify a nonce — that remains the caller's responsibility.
	 * Does NOT call sanitize_key() on options; AccessControlTable::sanitize()
	 * (called internally by update()) handles that to avoid double-processing.
	 *
	 * Usage:
	 *   check_admin_referer( 'my_nonce_action' );
	 *   $json = AccessControlUI::extract_posted_config( $_POST );
	 *   AccessControlTable::update( $ns, $key, $json );
	 *
	 * @since 1.2.0
	 *
	 * @param array $post Raw POST data (typically $_POST).
	 *
	 * @return string Sanitized JSON string, or '' for "everyone / no restriction".
	 */
	public static function extract_posted_config( array $post ): string {
		$ac_type = isset( $post['ac_type'] ) ? sanitize_key( wp_unslash( $post['ac_type'] ) ) : 'everyone';

		if ( 'everyone' === $ac_type ) {
			return '';
		}

		$ac_options = array();
		if ( isset( $post['ac_options'] ) && is_array( $post['ac_options'] ) ) {
			// Cast to string; AccessControlTable::sanitize() will sanitize_key() them.
			$ac_options = array_values( array_map( 'strval', wp_unslash( (array) $post['ac_options'] ) ) );
		}

		return wp_json_encode( array( 'type' => $ac_type, 'options' => $ac_options ) ) ?: '';
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	/**
	 * Handle wp_ajax_wpb_access_control_search_users.
	 *
	 * Returns an array of matching users for the live search UI. Never call
	 * this method directly — it is registered as a WP AJAX callback.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function ajax_search_users(): void {
		check_ajax_referer( 'wpb_access_control_search_users' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'wpb-access-control' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$term    = sanitize_text_field( wp_unslash( $_GET['term'] ?? '' ) );
		$results = WpUserProvider::search_users( $term, 10 );

		wp_send_json_success( $results );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the base URL of the library's assets/ directory.
	 *
	 * Auto-detection strips the absolute WP_CONTENT_DIR prefix from the
	 * package root and prepends WP_CONTENT_URL. Works whether the package is
	 * installed directly under wp-content/ or inside a plugin's vendor/.
	 * Override with set_assets_url() when the auto-detection is wrong.
	 *
	 * @since 1.2.0
	 *
	 * @return string Base URL without trailing slash.
	 */
	private function resolve_assets_url(): string {
		if ( null !== $this->assets_url ) {
			return $this->assets_url;
		}

		// Package root = two directories above this file (src/Admin → src → root).
		$pkg_root    = wp_normalize_path( dirname( __DIR__, 2 ) );
		$content_dir = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
		$content_url = untrailingslashit( WP_CONTENT_URL );

		if ( str_starts_with( $pkg_root, $content_dir ) ) {
			$relative = substr( $pkg_root, strlen( $content_dir ) );
			return set_url_scheme( $content_url . $relative . '/assets' );
		}

		// Fallback: caller should set_assets_url() explicitly.
		return set_url_scheme( $content_url . '/wpb-access-control/assets' );
	}
}

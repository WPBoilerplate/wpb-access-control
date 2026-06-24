/**
 * Entry point for the wpb-access-control React build.
 *
 * Exports
 * -------
 * The AccessControl component is the named and default export so consuming
 * plugins can import it when bundling together:
 *
 *   import { AccessControl } from '@wpb/access-control';
 *
 * Auto-render
 * -----------
 * When a `#wpb-access-control` DOM element is found on the page, the
 * component is mounted automatically using configuration from
 * `window.wpbAcConfig`:
 *
 *   wp_localize_script( 'wpb-access-control', 'wpbAcConfig', [
 *       'pluginSlug'  => 'my_plugin',  // required — matches the PHP table_slug
 *       'namespace'   => 'mcp',
 *       'resourceKey' => 'server',
 *       'restApiRoot' => get_rest_url(),
 *       'nonce'       => wp_create_nonce( 'wp_rest' ),
 *       // optional:
 *       'title'       => 'Access Control',
 *       'description' => '...',
 *       'saveLabel'   => 'Save Access Control',
 *   ] );
 *
 * Dependencies declared in assets/build/index.asset.php must be enqueued
 * before this script (wp-element, wp-api-fetch at minimum).
 */

import './AccessControl.scss';
import { render } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import AccessControl from './AccessControl';

export { AccessControl };
export default AccessControl;

if ( typeof window !== 'undefined' ) {
	const root = document.getElementById( 'wpb-access-control' );

	if ( root ) {
		const config = window.wpbAcConfig || {};

		if ( ! config.pluginSlug ) {
			// eslint-disable-next-line no-console
			console.error(
				'wpb-access-control: window.wpbAcConfig.pluginSlug is required. ' +
					'Pass the same slug you used in the PHP AccessControlManager constructor.'
			);
		} else {
			if ( config.nonce ) {
				apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
			}

			render(
				<AccessControl
					pluginSlug={ config.pluginSlug }
					namespace={ config.namespace || '' }
					resourceKey={ config.resourceKey || '' }
					restApiRoot={ config.restApiRoot || '/wp-json' }
					nonce={ config.nonce || '' }
					title={ config.title }
					description={ config.description }
					saveLabel={ config.saveLabel }
				/>,
				root
			);
		}
	}
}

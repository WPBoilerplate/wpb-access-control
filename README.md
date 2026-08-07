# wpb-access-control

Extensible per-resource access control library for WordPress plugins.

Answers one question: **"Does this user have access to this resource?"**

The library owns its own database table (managed by **BerlinDB**), ships WordPress role and user providers out of the box, exposes a REST API for managing rules from any client, and provides a ready-to-drop-in **React component** so consuming plugins get a full admin UI without writing any front-end code.

---

## Table of Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [PHP Setup](#php-setup)
4. [Complete Integration Example](#complete-integration-example)
5. [Two Plugins on One Site](#two-plugins-on-one-site)
6. [Checking Access](#checking-access)
7. [React Component UI](#react-component-ui)
8. [Reading & Writing Rules (PHP)](#reading--writing-rules-php)
9. [REST API](#rest-api)
10. [Events](#events)
11. [Custom Providers](#custom-providers)
12. [Built-in Providers](#built-in-providers)
13. [Important Notes](#important-notes)
14. [Database Table Reference](#database-table-reference)
15. [Upgrading from 1.x](#upgrading-from-1x)

---

## Requirements

| | |
|---|---|
| PHP | 7.4+ |
| WordPress | 5.9+ |
| Node.js | 18+ *(only needed if you rebuild the JS assets)* |
| `automattic/jetpack-autoloader` | **^5.0** (mandatory — see below) |
| `berlindb/core` | **^2.0** (DB layer) |

---

## Installation

```bash
composer require wpboilerplate/wpb-access-control
```

Your `composer.json` must include Jetpack Autoloader:

```json
{
    "require": {
        "automattic/jetpack-autoloader": "^5.0",
        "berlindb/core": "^2.0",
        "wpboilerplate/wpb-access-control": "^1.0"
    },
    "config": {
        "allow-plugins": {
            "automattic/jetpack-autoloader": true
        }
    }
}
```

> **Why Jetpack Autoloader is mandatory**
>
> If two plugins install this library at different versions, PHP throws a
> fatal "class already declared" error. Jetpack Autoloader scans every
> installed plugin, finds all copies, and loads only the newest one.

In your plugin's main file, require the Jetpack Autoloader entry point —
**not** the standard `vendor/autoload.php`:

```php
require_once __DIR__ . '/vendor/autoload_packages.php';
```

---

## PHP Setup

### 1. Boot the manager

Declare `$manager` at **file scope** (outside any closure) so every subsequent
hook can capture it via `use`. Pass two arguments:

1. A **plugin-specific provider filter tag** so your providers don't bleed
   into other plugins using the library.
2. A **per-plugin table slug** (must match `^[a-z0-9_]{1,32}$`) so your
   database table, object-cache group, and REST routes are isolated from
   every other plugin embedding the library.

```php
use WPBoilerplate\AccessControl\AccessControlManager;

// File scope — available to all hooks below via `use ( $manager )`.
$manager = new AccessControlManager(
    'my_plugin_access_control_providers', // provider filter tag
    'my_plugin'                            // table slug (required)
);
```

`AccessControlManager` owns a `RuleQuery` internally. Instantiating it
registers `RuleTable` via BerlinDB, which creates or upgrades the
`{prefix}my_plugin_access_control` table automatically on `admin_init`.

> **Need to wait for other plugins first?** Use a reference capture instead:
>
> ```php
> $manager = null;
> add_action( 'plugins_loaded', function () use ( &$manager ) {
>     $manager = new AccessControlManager(
>         'my_plugin_access_control_providers',
>         'my_plugin'
>     );
> } );
> // All subsequent hooks must also use `&$manager`.
> ```

### 2. Register the REST API

Call `register_rest_api()` from `rest_api_init` to expose the `wpb-ac/v1`
endpoints. The consuming plugin decides whether to enable them.

```php
add_action( 'rest_api_init', function () use ( $manager ) {
    $manager->register_rest_api();
} );
```

---

## Complete Integration Example

Below is a self-contained `my-plugin.php` showing **all pieces wired together**:
initialising the manager, registering the REST API, enqueueing the React UI,
rendering the mount point, and checking access.

```php
<?php
/**
 * Plugin Name: My Plugin
 */

use WPBoilerplate\AccessControl\AccessControlManager;

// 1. Require Composer autoloader.
require_once __DIR__ . '/vendor/autoload_packages.php';

// 2. Create the manager at file scope — captured by all hooks via `use ( $manager )`.
$manager = new AccessControlManager(
    'my_plugin_access_control_providers', // provider filter tag
    'my_plugin'                            // table slug (required)
);

// 3. Expose the REST API.
add_action( 'rest_api_init', function () use ( $manager ) {
    $manager->register_rest_api();
} );

// 4. Register an admin settings page and capture its hook suffix.
$settings_hook = null;
add_action( 'admin_menu', function () use ( &$settings_hook ) {
    // add_submenu_page() returns the hook suffix needed in admin_enqueue_scripts.
    $settings_hook = add_submenu_page(
        'options-general.php',         // parent menu slug
        'My Plugin Settings',          // page title
        'My Plugin',                   // menu title
        'manage_options',              // capability
        'my-plugin-settings',          // menu slug
        function () {
            echo '<div class="wrap">';
            echo '<h1>My Plugin Settings</h1>';
            // 5. Mount point — the React component attaches here automatically.
            echo '<div id="wpb-access-control"></div>';
            echo '</div>';
        }
    );
} );

// 6. Enqueue the built React UI assets only on the settings page.
add_action( 'admin_enqueue_scripts', function ( string $hook ) use ( &$settings_hook ) {
    if ( $hook !== $settings_hook ) {
        return;
    }

    $asset_file = require __DIR__ . '/vendor/wpboilerplate/wpb-access-control/assets/build/index.asset.php';

    wp_enqueue_script(
        'wpb-ac-ui',
        plugins_url( 'vendor/wpboilerplate/wpb-access-control/assets/build/index.js', __FILE__ ),
        $asset_file['dependencies'],
        $asset_file['version'],
        true
    );

    wp_enqueue_style(
        'wpb-ac-ui',
        plugins_url( 'vendor/wpboilerplate/wpb-access-control/assets/build/index.css', __FILE__ ),
        [],
        $asset_file['version']
    );

    // Pass config to the component via window.wpbAcConfig.
    wp_localize_script( 'wpb-ac-ui', 'wpbAcConfig', [
        'pluginSlug'  => 'my_plugin',     // required — must match the PHP table slug
        'namespace'   => 'my-plugin',
        'resourceKey' => 'settings-page',
        'restApiRoot' => get_rest_url(),
        'nonce'       => wp_create_nonce( 'wp_rest' ),
        'title'       => 'Access Control',
        'saveLabel'   => 'Save',
    ] );
} );

// 7. Gate a resource — call anywhere you need to check access.
add_action( 'template_redirect', function () use ( $manager ) {
    if ( is_page( 'protected' ) && ! $manager->user_has_access( get_current_user_id(), 'my-plugin', 'settings-page' ) ) {
        wp_die( 'Access denied.', '', [ 'response' => 403 ] );
    }
} );
```

> **Key points:**
> - `$manager` is declared once at file scope; all hooks capture it with `use ( $manager )`.
> - `add_submenu_page()` (or `add_menu_page()`) returns the hook suffix — store it and compare in `admin_enqueue_scripts` to load assets only on your page.
> - `vendor/autoload_packages.php` is the Jetpack Autoloader entry point, **not** the standard `vendor/autoload.php`.

---

## Two Plugins on One Site

The whole reason `$table_slug` exists: when two consumer plugins embed this
library on the same WordPress install, each gets its **own** table, cache
group, transient keys, and REST routes. They cannot collide. Pick a distinct
slug per plugin — that is the only coordination required between them.

### Plugin A — `mcp-server`

```php
// Bootstrap
$manager = new AccessControlManager(
    'mcp_server_access_control_providers',
    'mcp'                                  // → wp_mcp_access_control
);

// React config
wp_localize_script( 'wpb-ac-ui', 'wpbAcConfig', [
    'pluginSlug'  => 'mcp',
    'namespace'   => 'mcp/v1',
    'resourceKey' => 'server',
    'restApiRoot' => get_rest_url(),
    'nonce'       => wp_create_nonce( 'wp_rest' ),
] );
```

### Plugin B — `abilities-manager`

```php
// Bootstrap
$manager = new AccessControlManager(
    'abilities_manager_access_control_providers',
    'abilities'                            // → wp_abilities_access_control
);

// React config
wp_localize_script( 'wpb-ac-ui', 'wpbAcConfig', [
    'pluginSlug'  => 'abilities',
    'namespace'   => 'abilities/v1',
    'resourceKey' => 'editor-panel',
    'restApiRoot' => get_rest_url(),
    'nonce'       => wp_create_nonce( 'wp_rest' ),
] );
```

### What you actually get

| Surface | Plugin A (`mcp`) | Plugin B (`abilities`) |
|---|---|---|
| Table | `{prefix}mcp_access_control` | `{prefix}abilities_access_control` |
| Cache group | `wpb_ac_mcp` | `wpb_ac_abilities` |
| Schema version option | `wpb_ac_mcp_db_version` | `wpb_ac_abilities_db_version` |
| REST routes | `/wpb-ac/v1/mcp/...` | `/wpb-ac/v1/abilities/...` |
| Providers filter tag | `mcp_server_access_control_providers` | `abilities_manager_access_control_providers` |

Both managers can call `$manager->register_rest_api()` in the same request;
the slug-scoped paths mean WordPress registers two distinct sets of routes
instead of clobbering each other.

> **Picking a slug.** Must match `^[a-z0-9_]{1,32}$` — lowercase ASCII
> letters, digits, and underscores; 1 to 32 characters. Invalid slugs
> throw `\InvalidArgumentException` at construction time, never reaching
> SQL. Recommend using your plugin's `composer.json` name (or a short
> form of it) so the slug is stable and obviously yours.

---

## Checking Access

```php
$allowed = $manager->user_has_access(
    get_current_user_id(),   // int  — 0 = unauthenticated
    'my-namespace',          // string — your plugin's namespace
    'my-resource'            // string — the specific resource key
);

if ( ! $allowed ) {
    wp_die( 'Access denied.', 403 );
}
```

### Access hierarchy

| Step | Condition | Result |
|------|-----------|--------|
| 1 | `access_control_key` is empty or `'everyone'` | **Allow** (public — no login required) |
| 2 | `access_control_key` is `'authenticated'` | **Allow** iff `$user_id > 0`, else **Deny** |
| 3 | User has `manage_options` (administrator) | **Always allow** |
| 4 | User ID = 0 (unauthenticated) | **Deny** |
| 5 | No provider registered for the configured key | **Deny** |
| 6 | `provider->user_has_access()` | Allow or **Deny** |

---

## React Component UI

The library ships a pre-built React component that renders a complete
Access Control settings panel. Drop it into any WordPress admin page and it
wires itself to the `wpb-ac/v1` REST API automatically.

### What it looks like

The component is driven by a single **"Who can access"** dropdown:

| Dropdown option | Extra UI |
|---|---|
| **No user access added by admin** | Nothing — resource is locked (except admins) |
| **Public (no login required)** | Nothing — anyone can access, including anonymous visitors |
| **Any logged-in user** | Nothing — any authenticated WordPress user passes, no role/capability check |
| **WordPress Role** | Checkboxes for each WordPress role |
| **WordPress Capability** | Checkboxes for each WordPress capability (discovered across all roles) |
| **Users** | Search-as-you-type field + selected-user tags |

Custom providers registered via the filter also appear in the dropdown. If
they expose `options`, checkboxes are rendered automatically.

> **Third-party integrations (BuddyBoss, MemberPress, LearnDash, LifterLMS,
> Paid Memberships Pro, Restrict Content Pro, WooCommerce Memberships,
> s2Member, Wishlist Member, Memberium)** shipped in the library through
> v2.0.x have moved to a separate add-on plugin: **AcrossAI User Access Pro**
> (`acrossai/user-access-pro`). Install and activate it and the corresponding
> option appears in the dropdown automatically whenever the underlying plugin
> is active — no `add_filter` wiring required. See "Writing a third-party
> provider add-on" below to build your own.

### Enqueue the built assets

The compiled assets live in `assets/build/`. The `.asset.php` file declares
all required WordPress script dependencies so you never need to list them manually.

> **Getting the right hook suffix**: `add_menu_page()` and `add_submenu_page()`
> both **return** a hook suffix string (e.g. `"settings_page_my-plugin"`).
> Capture that return value and compare it in `admin_enqueue_scripts` so assets
> load only on your page.

```php
// Capture the hook suffix when registering the page.
$page_hook = add_submenu_page( /* … */ );

add_action( 'admin_enqueue_scripts', function ( string $hook ) use ( $page_hook ) {

    // Only load on the page where you need it.
    if ( $hook !== $page_hook ) {
        return;
    }

    $asset_file = require plugin_dir_path( __FILE__ )
        . 'vendor/wpboilerplate/wpb-access-control/assets/build/index.asset.php';

    wp_enqueue_script(
        'wpb-ac-ui',
        plugin_dir_url( __FILE__ )
            . 'vendor/wpboilerplate/wpb-access-control/assets/build/index.js',
        $asset_file['dependencies'],   // ['react-jsx-runtime', 'wp-api-fetch', 'wp-element']
        $asset_file['version'],
        true
    );

    wp_enqueue_style(
        'wpb-ac-ui',
        plugin_dir_url( __FILE__ )
            . 'vendor/wpboilerplate/wpb-access-control/assets/build/index.css',
        [],
        $asset_file['version']
    );

    // Pass configuration to the component via window.wpbAcConfig.
    wp_localize_script( 'wpb-ac-ui', 'wpbAcConfig', [
        'pluginSlug'  => 'my_plugin',  // required — must match the PHP table slug
        'namespace'   => 'my-namespace',
        'resourceKey' => 'my-resource',
        'restApiRoot' => get_rest_url(),
        'nonce'       => wp_create_nonce( 'wp_rest' ),
        // Optional overrides:
        'title'       => 'Access Control',
        'description' => 'Control which users may access this feature.',
        'saveLabel'   => 'Save Access Control',
    ] );
} );
```

### Render target

Add an empty `<div>` with the id `wpb-access-control` anywhere in your admin
page template. The component mounts itself automatically.

```php
add_action( 'my_plugin_settings_page', function () {
    echo '<div id="wpb-access-control"></div>';
} );
```

### Component props reference

| Prop | Type | Required | Default | Description |
|------|------|----------|---------|-------------|
| `pluginSlug` | `string` | ✅ | — | Consumer slug — must match the PHP `table_slug`. Used to build every REST URL (`/wpb-ac/v1/{pluginSlug}/...`). |
| `namespace` | `string` | ✅ | — | Resource namespace, e.g. `"mcp"` |
| `resourceKey` | `string` | ✅ | — | Resource key within the namespace |
| `restApiRoot` | `string` | ✅ | — | WP REST API root URL (`get_rest_url()`) |
| `nonce` | `string` | ✅ | — | `wp_create_nonce('wp_rest')` |
| `title` | `string` | | `"Access Control"` | Card heading |
| `description` | `string` | | *(MCP-server copy)* | Subtitle paragraph |
| `saveLabel` | `string` | | `"Save Access Control"` | Save button label |
| `onSave` | `Function` | | — | Callback `(acKey, acOptions)` after a successful save |

### Using the component as a JS import

If your plugin has its own webpack build, import the component directly:

```js
import apiFetch from '@wordpress/api-fetch';
import { AccessControl } from '@wpb/access-control'; // or relative path

// Set up the nonce once before rendering.
apiFetch.use( apiFetch.createNonceMiddleware( wpbAcConfig.nonce ) );

// Render into any DOM node.
import { createRoot } from '@wordpress/element';
createRoot( document.getElementById( 'my-ac-panel' ) ).render(
    <AccessControl
        pluginSlug="my_plugin"
        namespace="my-namespace"
        resourceKey="my-resource"
        restApiRoot={ wpbAcConfig.restApiRoot }
        nonce={ wpbAcConfig.nonce }
        onSave={ ( acKey, acOptions ) => console.log( 'Saved', acKey, acOptions ) }
    />
);
```

> **Note:** When importing directly the nonce middleware must be registered
> before the first `apiFetch` call. The auto-render path (`index.js`) handles
> this automatically.

### Namespace slashes

Namespaces containing slashes (e.g. `procureco/v1`) are handled automatically
by the component — each segment is `encodeURIComponent`-encoded so they reach
the REST API as `%2F`.

---

## Reading & Writing Rules (PHP)

Use `RuleQuery` when you need to read or write rules from PHP directly.

```php
use WPBoilerplate\AccessControl\Database\Rule\RuleQuery;

$query = new RuleQuery();

// Read the current rule.
$rule = $query->get_rule( 'my-namespace', 'my-resource' );
// → ['key' => 'wp_role', 'value' => ['editor', 'author']]
// → ['key' => '',        'value' => []]   when no rule is set

// Save a rule (inputs are sanitized internally).
$query->set_rule( 'my-namespace', 'my-resource', 'wp_role', ['editor', 'author'] );

// Allow everyone (public — no login required).
$query->set_rule( 'my-namespace', 'my-resource', 'everyone', [] );

// Require login (any authenticated user, no role/capability check).
$query->set_rule( 'my-namespace', 'my-resource', 'authenticated', [] );

// Clear a rule (reverts to "no restriction configured").
$query->clear_rule( 'my-namespace', 'my-resource' );

// Plugin uninstall — delete all rows for your namespace.
$query->purge_namespace( 'my-namespace' );
```

You can also access the same instance through the manager:

```php
$rule = $manager->get_query()->get_rule( 'my-namespace', 'my-resource' );
```

---

## REST API

REST namespace: **`wpb-ac/v1`**. Every route is scoped under the consumer's
table slug — `{slug}` in the paths below is the same string you pass to
`new AccessControlManager(...)`.

All endpoints require `manage_options` (administrator) by default.
Use the `wpb_access_control_rest_permission` filter to override.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/{slug}/rules/{namespace}/{key}` | Read the current rule |
| `PUT` | `/{slug}/rules/{namespace}/{key}` | Create or replace a rule |
| `DELETE` | `/{slug}/rules/{namespace}/{key}` | Clear a rule (revert to unrestricted) |
| `DELETE` | `/{slug}/namespaces/{namespace}` | Purge all rules for a namespace |
| `GET` | `/{slug}/providers` | List registered providers and their options |
| `GET` | `/{slug}/users?search=...&limit=10` | Search WordPress users |

> **Slashes in namespace**: The `{namespace}` URL segment cannot contain
> literal slashes — encode them as `%2F`:
> `.../my_plugin/rules/procureco%2Fv1/my-key`.
> The `{key}` segment allows literal slashes.

### Request / response shapes

**GET /rules/{namespace}/{key}**
```json
{ "key": "wp_role", "value": ["editor", "author"] }
{ "key": "", "value": [] }
```

**PUT /rules/{namespace}/{key}** — body:
```json
{ "ac_key": "wp_role", "ac_options": ["editor", "author"] }
```
Response:
```json
{ "success": true, "rule": { "key": "wp_role", "value": ["editor", "author"] } }
```

**DELETE /rules/{namespace}/{key}**
```json
{ "success": true }
```

**DELETE /namespaces/{namespace}**
```json
{ "deleted": 5 }
```

**GET /providers**
```json
[
  { "id": "wp_role", "label": "WordPress Role", "options": [{"id":"editor","label":"Editor"}, ...], "available": true },
  { "id": "wp_user", "label": "Users",          "options": [],                                       "available": true }
]
```

**GET /users?search=jane&limit=10**
```json
[
  { "id": "5", "login": "jane", "email": "jane@example.com", "display_name": "Jane Doe" }
]
```

---

### Authentication

**WordPress admin (nonce)**

Include the `wp_rest` nonce in the `X-WP-Nonce` header:

```php
$nonce = wp_create_nonce( 'wp_rest' );
```

**Application Passwords (external clients)**

```
Authorization: Basic base64(username:application_password)
```

---

### Code examples

#### cURL

```bash
# Read  (replace 'my_plugin' with your slug throughout)
curl -H "X-WP-Nonce: <nonce>" \
  https://example.com/wp-json/wpb-ac/v1/my_plugin/rules/my-namespace/my-resource

# Set
curl -X PUT \
  -H "X-WP-Nonce: <nonce>" \
  -H "Content-Type: application/json" \
  -d '{"ac_key":"wp_role","ac_options":["editor","author"]}' \
  https://example.com/wp-json/wpb-ac/v1/my_plugin/rules/my-namespace/my-resource

# Namespace with slashes
curl -X PUT \
  -H "X-WP-Nonce: <nonce>" \
  -H "Content-Type: application/json" \
  -d '{"ac_key":"wp_role","ac_options":["editor"]}' \
  https://example.com/wp-json/wpb-ac/v1/my_plugin/rules/procureco%2Fv1/endpoints%2Flist

# Clear
curl -X DELETE \
  -H "X-WP-Nonce: <nonce>" \
  https://example.com/wp-json/wpb-ac/v1/my_plugin/rules/my-namespace/my-resource
```

#### PHP (`wp_remote_request`)

```php
// Read
$response = wp_remote_get(
    rest_url( 'wpb-ac/v1/my_plugin/rules/my-namespace/my-resource' ),
    [ 'headers' => [ 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ] ]
);
$rule = json_decode( wp_remote_retrieve_body( $response ), true );

// Set
wp_remote_request(
    rest_url( 'wpb-ac/v1/my_plugin/rules/my-namespace/my-resource' ),
    [
        'method'  => 'PUT',
        'headers' => [
            'Content-Type' => 'application/json',
            'X-WP-Nonce'   => wp_create_nonce( 'wp_rest' ),
        ],
        'body' => wp_json_encode( [ 'ac_key' => 'wp_role', 'ac_options' => [ 'editor' ] ] ),
    ]
);
```

#### `@wordpress/api-fetch`

```js
import apiFetch from '@wordpress/api-fetch';

const slug = 'my_plugin'; // must match the PHP table slug

// Read
const rule = await apiFetch( { path: `/wpb-ac/v1/${slug}/rules/my-namespace/my-resource` } );

// Set
await apiFetch( {
    path:   `/wpb-ac/v1/${slug}/rules/my-namespace/my-resource`,
    method: 'PUT',
    data:   { ac_key: 'wp_role', ac_options: [ 'editor', 'author' ] },
} );

// Search users (for the wp_user provider UI)
const users = await apiFetch( { path: `/wpb-ac/v1/${slug}/users?search=jane&limit=10` } );

// List providers (for building a custom UI)
const providers = await apiFetch( { path: `/wpb-ac/v1/${slug}/providers` } );
```

#### Vanilla `fetch`

```js
const nonce  = document.querySelector( 'meta[name="wp-rest-nonce"]' )?.content;
const slug   = 'my_plugin';
const apiUrl = `/wp-json/wpb-ac/v1/${slug}`;

// Read
const rule = await fetch( `${apiUrl}/rules/my-namespace/my-resource`, {
    headers: { 'X-WP-Nonce': nonce },
} ).then( r => r.json() );

// Set
await fetch( `${apiUrl}/rules/my-namespace/my-resource`, {
    method:  'PUT',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    body:    JSON.stringify( { ac_key: 'wp_role', ac_options: [ 'editor' ] } ),
} );
```

---

### Permission filter

Override who may call any endpoint:

```php
add_filter( 'wpb_access_control_rest_permission', function ( bool $can, WP_REST_Request $request ): bool {
    // Allow editors to read rules, but only admins to write.
    if ( 'GET' === $request->get_method() ) {
        return current_user_can( 'edit_posts' );
    }
    return $can;
}, 10, 2 );
```

### Write authorization filter

Restrict which namespace/key pairs may be modified:

```php
add_filter( 'wpb_access_control_can_save', function ( bool $can, string $namespace, string $key, int $user_id ): bool {
    return 'my-namespace' === $namespace;
}, 10, 4 );
```

---

## Events

### `wpb_access_control_denied`

Fires whenever `user_has_access()` returns `false` (steps 3–5 of the hierarchy).

```php
add_action( 'wpb_access_control_denied', function (
    int    $user_id,
    string $namespace,
    string $key,
    string $ac_key,
    array  $options
): void {
    error_log( "Access denied — user:{$user_id} {$namespace}/{$key}" );
}, 10, 5 );
```

### `wpb_access_control_saved`

Fires after any successful write via the REST API (PUT rule, DELETE rule,
DELETE namespace). `$ac_key` is `''` on a clear.

```php
add_action( 'wpb_access_control_saved', function (
    string $namespace,
    string $key,
    string $ac_key,
    array  $ac_options,
    int    $user_id
): void {
    // Audit log, cache bust, etc.
}, 10, 5 );
```

---

## Custom Providers

### Register

```php
add_filter( 'my_plugin_access_control_providers', function ( array $providers ): array {
    $providers[] = new My\Plugin\MembershipProvider();
    return $providers;
} );
```

The filter tag must match the string passed to `AccessControlManager`.
Register at `init` priority ≤ 4 (the filter fires at priority 5).

### Contract (`AbstractProvider`)

| Method | Required | Description |
|--------|----------|-------------|
| `get_id(): string` | ✅ | Unique slug stored as `access_control_key` |
| `get_label(): string` | ✅ | Human-readable label shown in the UI dropdown |
| `get_options(): array` | ✅ | `[['id'=>'slug','label'=>'Name'], ...]`; return `[]` for dynamic providers |
| `user_has_access(int $user_id, array $selected): bool` | ✅ | Core access check |
| `is_available(): bool` | | Return `false` when a required dependency is inactive |

### Example provider

```php
namespace My\Plugin;

use WPBoilerplate\AccessControl\AbstractProvider;

class MembershipProvider extends AbstractProvider {

    public function get_id(): string    { return 'my_membership'; }
    public function get_label(): string { return __( 'Membership Level', 'my-plugin' ); }

    public function get_options(): array {
        return [
            [ 'id' => 'gold',   'label' => 'Gold'   ],
            [ 'id' => 'silver', 'label' => 'Silver' ],
        ];
    }

    public function user_has_access( int $user_id, array $selected_options ): bool {
        return in_array( my_get_membership_level( $user_id ), $selected_options, true );
    }

    public function is_available(): bool {
        return function_exists( 'my_get_membership_level' );
    }
}
```

Providers and their options are surfaced by `GET /wpb-ac/v1/providers`, so
any front-end UI (including the built-in React component) can render the
correct controls dynamically without hard-coding provider IDs.

---

## Built-in Providers

| Provider ID | Class | Description |
|-------------|-------|-------------|
| `wp_role` | `WpRoleProvider` | Restricts by WordPress user role. Administrator is always bypassed. |
| `wp_user` | `WpUserProvider` | Restricts to specific WordPress users by ID. |
| `wp_capability` | `WpCapabilityProvider` | Restricts by one or more WordPress capability slugs. Users holding **any** of the selected capabilities pass. |

> Providers for BuddyBoss, MemberPress, LearnDash, LifterLMS, Paid
> Memberships Pro, Restrict Content Pro, WooCommerce Memberships, s2Member,
> Wishlist Member, and Memberium ship in the **AcrossAI User Access Pro**
> add-on plugin — not in this library. See "Writing a third-party provider
> add-on" below for how to add your own.

### `WpRoleProvider` filters

| Filter | Signature | Description |
|--------|-----------|-------------|
| `wpb_access_control_wp_role_options` | `(array $options): array` | Add or remove selectable role options |
| `wpb_access_control_wp_role_has_access` | `(bool $result, int $user_id, array $selected): bool` | Override the final role-based decision |

### `WpUserProvider`

Options are **user IDs stored as strings** (`"42"`), not usernames or emails —
`sanitize_key()` strips `@` and `.`, so email addresses would be corrupted.

```php
use WPBoilerplate\AccessControl\WpUserProvider;

// Search by login, email, or display name.
$results = WpUserProvider::search_users( 'jane', 10 );
// → [['id'=>'5','login'=>'jane','email'=>'jane@example.com','display_name'=>'Jane Doe'], ...]

// Hydrate stored IDs → display data (useful for custom UIs).
$users = WpUserProvider::get_users_by_ids( ['5', '42'] );
```

| Filter | Signature | Description |
|--------|-----------|-------------|
| `wpb_access_control_wp_user_has_access` | `(bool $result, int $user_id, array $selected): bool` | Override the final per-user decision |

### `WpCapabilityProvider`

Options are WordPress capability slugs (e.g. `install_plugins`, `edit_posts`,
`manage_options`). The list is built dynamically from every role returned by
`wp_roles()` — capabilities added by plugins like WooCommerce, Members, or
User Role Editor appear automatically. Access is granted when `user_can()`
returns true for **any** selected capability.

| Filter | Signature | Description |
|--------|-----------|-------------|
| `wpb_access_control_wp_capability_options` | `(array $options): array` | Add or remove selectable capability options (e.g. to surface a custom cap that no role holds yet) |
| `wpb_access_control_wp_capability_has_access` | `(bool $result, int $user_id, array $selected): bool` | Override the final capability-based decision |

---

## Writing a third-party provider add-on

Anyone can ship a WordPress plugin that adds providers to every consumer of
`wpb-access-control` on the site, with no per-consumer bootstrap code. The
reference implementation is
[**AcrossAI User Access Pro**](https://github.com/acrossai-co/user-access-pro),
which packages 10 membership-plugin integrations this way.

The mechanism is one global filter registered by the library since v3.0.0:

```
apply_filters( 'wpb_access_control_register_providers', $providers, $table_slug )
```

It fires **once per consumer instance** during `load_providers()`, before
the consumer-specific filter, and receives the running providers array plus
the consumer's `$table_slug`. Any provider you append here is visible to
that consumer's REST `/providers` endpoint and its React dropdown.

### Minimal add-on skeleton

```php
<?php
/**
 * Plugin Name: My Access Control Providers
 */

add_action( 'plugins_loaded', function () {
    // Base library must already be loaded by a consumer plugin's autoloader.
    if ( ! class_exists( \WPBoilerplate\AccessControl\AbstractProvider::class ) ) {
        return;
    }
    require_once __DIR__ . '/vendor/autoload.php';

    add_filter(
        'wpb_access_control_register_providers',
        function ( array $providers /*, string $table_slug */ ) {
            $providers[] = new \MyPlugin\Providers\Widget();
            return $providers;
        }
    );
}, 20 );
```

Providers extend `\WPBoilerplate\AccessControl\AbstractProvider` and
implement `get_id()`, `get_label()`, `get_options()`, and
`user_has_access()`. Override `is_available()` when the provider depends on
an optional plugin — the library's REST layer forwards that flag to the
React UI so unavailable options are hidden automatically.

To scope an add-on to specific consumers, inspect the `$table_slug`
argument and short-circuit for consumers you don't want to inject into.

Consumers can still filter the resulting list per-instance via the
consumer-specific filter passed to `AccessControlManager`, so they retain
final say over what shows up in their own UI.

---

## Important Notes

### Filter tag isolation
Always pass a plugin-specific tag to `AccessControlManager`. Two plugins
sharing the same filter tag will bleed providers into each other's checks.

### Table management
BerlinDB handles all table creation and upgrades automatically on `admin_init`.
No activation hook is needed — instantiating `new AccessControlManager(...)` is sufficient.

### Caching
Always use `RuleQuery::set_rule()` and `clear_rule()`. Direct `$wpdb` writes
bypass BerlinDB's object cache and leave it stale.

### Administrator bypass is unconditional
Any user with `manage_options` always passes `user_has_access()` regardless of
the stored rule. This cannot be disabled.

### Uninstall cleanup
Each consuming plugin removes its own rows:

```php
// uninstall.php
( new \WPBoilerplate\AccessControl\Database\Rule\RuleQuery() )
    ->purge_namespace( 'my-namespace' );
```

### Multisite
The table uses `$wpdb->prefix` — each sub-site has its own
`{prefix}wpb_access_control` table. Network-wide rules must be handled by
the consuming plugin.

---

## Database Table Reference

Table: `{prefix}{slug}_access_control` — one per consumer (e.g.
`wp_mcp_access_control`, `wp_abilities_access_control`).
DB layer: BerlinDB `^3.0`  ·  Schema version: `202605120001`

Each consumer's table is created on the first `admin_init` after the
manager is instantiated. The schema is identical across consumers; only
the name and the `wpb_ac_{slug}_db_version` option differ.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED PK AI | |
| `namespace` | VARCHAR(100) NOT NULL | Plugin-scoped prefix, e.g. `mcp`, `procureco/v1` |
| `key` | VARCHAR(255) NOT NULL | Resource identifier within the namespace |
| `access_control_key` | VARCHAR(100) NOT NULL | Rule type slug — same for every row of a `(ns, key)` pair |
| `access_control_value` | VARCHAR(255) NOT NULL | One option per row; `''` for the `everyone` / `authenticated` sentinels |
| `created_at` | DATETIME | BerlinDB-managed on INSERT |
| `updated_at` | DATETIME | BerlinDB-managed on UPDATE |

Indexes: `PRIMARY KEY (id)` · `UNIQUE (namespace, key(191), access_control_value)` · `KEY (namespace, key(191))`

### Rule storage convention

| Logical state | Rows in table |
|---|---|
| No rule configured | **No rows** for that `(namespace, key)` |
| `everyone` | One row: `access_control_key='everyone'`, `access_control_value=''` |
| `authenticated` | One row: `access_control_key='authenticated'`, `access_control_value=''` |
| `wp_role` + `['editor','author']` | Two rows, both `access_control_key='wp_role'`; values `'editor'`, `'author'` |
| `wp_user` + `['1','42']` | Two rows, both `access_control_key='wp_user'`; values `'1'`, `'42'` |

---

## Upgrading from 1.x

v2.0.0 introduces a required `$table_slug` constructor argument. Each
consumer plugin now owns its own table, object-cache group, and REST
route prefix — fixing the silent collision when two plugins embed the
library on the same WordPress install.

### 1. Update the manager constructor

```diff
- $manager = new AccessControlManager( 'my_plugin_access_control_providers' );
+ $manager = new AccessControlManager(
+     'my_plugin_access_control_providers',
+     'my_plugin' // table slug: ^[a-z0-9_]{1,32}$
+ );
```

Invalid slugs throw `\InvalidArgumentException` immediately.

### 2. Update `wpbAcConfig` / React props

```diff
  wp_localize_script( 'wpb-ac-ui', 'wpbAcConfig', [
+     'pluginSlug'  => 'my_plugin',
      'namespace'   => 'my-namespace',
      'resourceKey' => 'my-resource',
      // …
  ] );
```

### 3. Update REST URLs

Every endpoint moves under `/wpb-ac/v1/{slug}/...`:

```diff
- GET    /wpb-ac/v1/rules/{namespace}/{key}
+ GET    /wpb-ac/v1/{slug}/rules/{namespace}/{key}

- GET    /wpb-ac/v1/providers
+ GET    /wpb-ac/v1/{slug}/providers

- GET    /wpb-ac/v1/users?search=...
+ GET    /wpb-ac/v1/{slug}/users?search=...

- DELETE /wpb-ac/v1/namespaces/{namespace}
+ DELETE /wpb-ac/v1/{slug}/namespaces/{namespace}
```

### 4. (Optional) Migrate existing rows

The library **does not** auto-migrate from `{prefix}wpb_access_control`.
Run a one-off SQL copy from your plugin's update routine, filtering by
the namespaces *your* plugin owns:

```sql
INSERT INTO {prefix}my_plugin_access_control
    ( namespace, `key`, access_control_key, access_control_value, created_at, updated_at )
SELECT
      namespace, `key`, access_control_key, access_control_value, created_at, updated_at
FROM  {prefix}wpb_access_control
WHERE namespace IN ( 'your-namespace-1', 'your-namespace-2' );
```

Drop the legacy table only after **every** consumer plugin on the site
has upgraded — otherwise an un-upgraded plugin will lose its rules.

# AGENTS.md — wpb-access-control

> Full reference for AI coding agents working on this repository.

---

## Package identity

| Field           | Value                                              |
|-----------------|----------------------------------------------------|
| Package name    | `wpboilerplate/wpb-access-control`                 |
| Type            | `library`                                          |
| PHP NS root     | `WPBoilerplate\AccessControl\`                     |
| PSR-4 root      | `src/`                                             |
| Current version | `3.0.0` (dev-main)                                 |
| Min PHP         | 7.4                                                |
| Min WP          | 5.9                                                |
| License         | GPL-2.0-or-later                                   |
| Repo            | `github.com/WPBoilerplate/wpb-access-control`      |

---

## Purpose

Answers one question: **"Does this user have access to this resource?"**

The library:
- Owns a standalone `{prefix}wpb_access_control` database table (BerlinDB-managed)
- Provides a provider registry (WordPress roles built-in; extensible for any back-end)
- Exposes `AccessControlManager::user_has_access(int $user_id, string $namespace, string $key): bool`

The library does **not**:
- Hook `rest_pre_dispatch` or any other WordPress action
- Do route matching
- Know about REST API, MCP, procurement, or any product
- Decide what to do when access is denied

All of that is the consuming plugin's responsibility.

---

## Repository layout

```
src/
  AccessControlManager.php     Provider registry + user_has_access().
                                Owns a RuleQuery instance; exposes get_query().
                                No REST hooks. No fetcher. No mapper.
                                Consuming plugin decides when/where to call it.

  AbstractProvider.php         Abstract base class for all providers.
                                Includes concrete render_options() with default
                                checkbox rendering; override for custom controls.

  WpRoleProvider.php           Built-in provider: restricts by WordPress user role.
                                Administrator role excluded (always bypassed in manager).
                                Uses default AbstractProvider::render_options().

  WpUserProvider.php           Built-in provider: restricts to specific WordPress users.
                                Overrides render_options() to emit AJAX search input +
                                multi-select user tags. Stores user IDs as strings.
                                Static helpers: search_users(), get_users_by_ids().

  Database/Rule/
    RuleTable.php              BerlinDB Table subclass. Defines the {prefix}wpb_access_control
                                schema (v3 flat rows), upgrade methods, and length constants.
                                Table + hooks are registered automatically when RuleQuery
                                is first instantiated.

    RuleSchema.php             BerlinDB Schema subclass. Column definitions consumed by
                                RuleQuery for filter/sort query vars.

    RuleRow.php                BerlinDB Row subclass. Typed property declarations.

    RuleQuery.php              Main CRUD entry point. Extends BerlinDB\Database\Query.
                                Exposes: get_rule(), set_rule(), clear_rule(),
                                purge_namespace(). Instantiating it registers RuleTable.

  Admin/
    AccessControlUI.php        Ready-to-use admin panel renderer. Ships CSS + JS.
                                Consuming plugin bootstraps it once, then calls
                                render() + enqueue_assets().
                                Registers the shared user-search + save AJAX actions.
                                extract_posted_config() is optional for custom saves.

assets/
  css/admin.css                Panel styles: search dropdown, user tags, remove button.
  js/admin.js                  Toggle logic + user search/save AJAX. Scoped per form via
                                data-wpb-ac-form attribute. No dependencies.

README.md                      Usage documentation for consuming plugins.
AGENTS.md                      This file.
composer.json                  Package manifest.
```

---

## Database table

Table: `{prefix}wpb_access_control`
DB layer: **BerlinDB** (`berlindb/core ^2.0`)
Schema version: `202605120001` (BerlinDB integer, stored in option `wpb_access_control_db_version`)

### Schema (v3 — flat rows)

| Column                 | Type                  | Notes                                                   |
|------------------------|-----------------------|---------------------------------------------------------|
| `id`                   | BIGINT UNSIGNED PK AI |                                                         |
| `namespace`            | VARCHAR(100) NOT NULL | Product-scoped prefix, e.g. `mcp`, `procureco/v1`       |
| `key`                  | VARCHAR(255) NOT NULL | Resource identifier within the namespace                |
| `access_control_key`   | VARCHAR(100) NOT NULL | Rule type slug — same for every row of a (ns,key) pair  |
| `access_control_value` | VARCHAR(255) NOT NULL | One option per row (role slug, user ID string, or `''`) |
| `created_at`           | DATETIME              | BerlinDB-managed on INSERT                              |
| `updated_at`           | DATETIME              | BerlinDB-managed on UPDATE                              |

Indexes:
- `PRIMARY KEY (id)`
- `UNIQUE KEY ns_key_value (namespace, key(191), access_control_value)`
- `KEY ns_key (namespace, key(191))`

### Rule storage convention

| Logical state               | Rows in table                                                              |
|-----------------------------|----------------------------------------------------------------------------|
| No rule configured (`''`)   | **No rows** for that `(namespace, key)`                                    |
| `everyone`                  | One row: `access_control_key='everyone'`, `access_control_value=''`        |
| `wp_role` + `[editor,author]` | Two rows, both `access_control_key='wp_role'`; values `'editor'`, `'author'` |
| `wp_user` + `["1","42"]`    | Two rows, both `access_control_key='wp_user'`; values `'1'`, `'42'`       |

### RuleQuery public API

| Method | Description |
|--------|-------------|
| `get_rule(ns, key): array` | Returns `['key'=>string, 'value'=>string[]]`; empty shape when no rows. |
| `set_rule(ns, key, ac_key, ac_options): bool` | Atomically replaces existing rows. Sanitizes inputs. |
| `clear_rule(ns, key): bool` | Deletes all rows for the pair. |
| `purge_namespace(ns): int` | Deletes all rows for a namespace; use in uninstall hooks. Returns deleted count. |

Constants (on `RuleTable`):
- `RuleTable::NAMESPACE_LENGTH` = `100`
- `RuleTable::KEY_LENGTH` = `255`

### Table lifecycle

BerlinDB's `RuleTable` registers an `admin_init` hook that runs `maybe_upgrade()`. Upgrades compare the stored option to `202605120001`. The first time the new library version runs:
- If an old JSON-schema table exists → `upgrade_202605120001()` drops and recreates it.
- If no table exists → BerlinDB creates it fresh.

Consuming plugins no longer need to call `maybe_create_table()`. Instantiating `new RuleQuery()` in `plugins_loaded` is sufficient.

---

## AccessControlManager

Constructor: `__construct( string $providers_filter = 'wpb_access_control_providers' )`

**Always pass a plugin-specific filter tag** to avoid provider leakage between plugins installed on the same site.

### Public API

| Method | Description |
|--------|-------------|
| `load_providers()` | Fires the providers filter and rebuilds the registry. Called on init:5 or immediately if init has fired. |
| `get_providers()` | Returns `array<string, AbstractProvider>` keyed by provider ID. |
| `get_provider(id)` | Returns one provider or null. |
| `get_query()` | Returns the `RuleQuery` instance (for direct rule reads/writes). |
| `user_has_access(user_id, namespace, key)` | Core method. Reads via `RuleQuery::get_rule()` and applies access hierarchy. |

### Access hierarchy

1. `access_control_key` empty or `'everyone'` → **allow**
2. User has `manage_options` (administrator) → **always allow**
3. User ID = 0 (unauthenticated) → **deny** + fires `wpb_access_control_denied`
4. No provider registered for the configured key → **deny** + fires `wpb_access_control_denied`
5. `provider->user_has_access()` returns false → **deny** + fires `wpb_access_control_denied`

### `wpb_access_control_denied` action

Fires on every denial (steps 3–5 above).

```php
do_action( 'wpb_access_control_denied', int $user_id, string $namespace, string $key, string $ac_key, string[] $options );
```

---

## Provider contract (`AbstractProvider`)

| Method | Required | Purpose |
|--------|----------|---------|
| `get_id(): string` | Yes | Unique machine-readable ID stored as `access_control_key` |
| `get_label(): string` | Yes | Human-readable label shown in admin UI dropdown |
| `get_options(): array` | Yes | Returns `[['id'=>'slug','label'=>'Name'], ...]` for checkboxes |
| `user_has_access(int $user_id, array $selected_options): bool` | Yes | Core access check |
| `is_available(): bool` | No | Return false when a required plugin is inactive |

### Registering a custom provider

```php
add_filter( 'my_plugin_access_control_providers', function( array $providers ) {
    $providers[] = new My\Plugin\MembershipProvider();
    return $providers;
} );
```

The filter tag **must** match the string passed to the `AccessControlManager` constructor.
The filter fires on `init` at priority 5. Providers added after that are ignored.

---

## Jetpack Autoloader — mandatory

**This library must be used with `automattic/jetpack-autoloader`.**

Without it, two plugins that both install this library at different versions will cause
a fatal "class already declared" error. Jetpack Autoloader scans all installed plugins,
finds every copy of the library, and loads only the newest version.

Every consuming plugin's `composer.json` must include:

```json
"require": {
    "automattic/jetpack-autoloader": "^2.0",
    "berlindb/core": "^2.0",
    "wpboilerplate/wpb-access-control": "dev-main"
},
"config": {
    "allow-plugins": {
        "automattic/jetpack-autoloader": true
    }
}
```

---

## Built-in providers

| Provider ID | Class | Since | Description |
|-------------|-------|-------|-------------|
| `wp_role` | `WpRoleProvider` | 1.0.0 | Restricts by WordPress user role. |
| `wp_user` | `WpUserProvider` | 1.1.0 | Restricts to specific users, multi-select, AJAX search. |

### `WpUserProvider` — storage rules

- Options are **user IDs stored as strings** (`"42"`, `"1"`), not usernames or emails.
  Reason: `sanitize_key()` strips `@` and `.` — email addresses would be corrupted.
- `get_options()` returns `[]` — no static checkbox list.
- `render_options()` emits the AJAX search input and selected-user tags.
  `AccessControlUI` registers the AJAX action and enqueues assets — consuming plugin
  does not need to add any user-search code.
- Static helpers (available for advanced use):
  - `WpUserProvider::search_users( string $search, int $limit = 10 ): array`
  - `WpUserProvider::get_users_by_ids( string[] $ids ): array`

## AccessControlUI

Class: `WPBoilerplate\AccessControl\Admin\AccessControlUI`
Since: 1.2.0

Ships the complete admin panel (PHP rendering, CSS, JS, AJAX) so consuming plugins
need zero UI code for access control.

### Public API

| Method | Description |
|--------|-------------|
| `__construct( AccessControlManager $manager )` | Registers the shared user-search and save AJAX actions (idempotent). |
| `static bootstrap()` | Registers the shared AJAX actions without needing a UI instance yet. |
| `set_assets_url( string $url )` | Override auto-detected asset base URL. |
| `render( string $ns, string $key, array $args )` | Render the panel. Always wraps in a `<form>` with library-owned AJAX save wiring. |
| `enqueue_assets()` | Enqueue library CSS + JS. Call from `admin_enqueue_scripts`. |
| `static extract_posted_config( array $post ): array` | Extract config shape from `$_POST`; used internally and reusable for custom flows. |

### `$args` for `render()`

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `submit_label` | string | "Save Access Control" | Submit button label. |
| `description` | string | Generic copy | Paragraph below heading. |

### AJAX actions

Action: `wpb_access_control_search_users` (shared, library-owned)
Nonce: `wpb_access_control_search_users`
Capability check: `manage_options`

Action: `wpb_access_control_save` (shared, library-owned)
Nonce field: `wpb_ac_nonce` using action `wpb_access_control_save`
Capability check: `manage_options`
Save authorization filter: `wpb_access_control_can_save`
Post-save action: `wpb_access_control_saved`

The action is registered exactly once per request via a static `$ajax_registered` flag,
even when multiple plugins instantiate `AccessControlUI`.

### Asset URL resolution

Auto-detection: computes the package root from `__DIR__`, strips `WP_CONTENT_DIR`,
prepends `WP_CONTENT_URL`. Works whether the package lives at
`wp-content/wpb-access-control/` or inside a plugin's `vendor/`.

Override when auto-detection is wrong (symlinks, non-standard layout):

```php
$ui->set_assets_url( plugins_url( 'vendor/wpboilerplate/wpb-access-control/assets', __FILE__ ) );
```

---

## Key invariants for agents

### AccessControlManager
- **No REST hooks inside the manager.** `rest_pre_dispatch` and all enforcement belong in the consuming plugin.
- **`user_has_access()` is the only entry point** for access decisions. Do not call `RuleQuery` directly inside the manager — use `$this->query`.
- **Administrator bypass is unconditional.** The `manage_options` check in `user_has_access()` must not be removed or made configurable.
- **Providers are loaded at `init` priority 5.** Third-party code must hook at priority 4 or earlier.
- **Filter tag isolation is mandatory.** Never use the default `'wpb_access_control_providers'` tag in a product plugin.

### Database (RuleQuery / RuleTable)
- **`set_rule()` always sanitizes.** Do not call `sanitize_key()` separately before calling `set_rule()`.
- **Never write via raw `$wpdb`.** Always use `RuleQuery::set_rule()` so BerlinDB's cache stays consistent.
- **`purge_namespace()` is for uninstall only.**
- **The table is per-site on multisite** (`$wpdb->prefix`). Network-wide rules must be handled by the consuming plugin.
- **BerlinDB handles table creation and upgrades** via the `admin_init` hook registered by `RuleTable`. The consuming plugin only needs to instantiate `new AccessControlManager(...)` (which creates `new RuleQuery()`, which creates `new RuleTable()`).

### AccessControlUI
- **Bootstrap AJAX handlers on requests that do not render the panel.** Either instantiate `AccessControlUI` once during plugin bootstrap or call `AccessControlUI::bootstrap()` early so `admin-ajax.php` requests have the shared callbacks registered.
- **Shared AJAX actions** `wpb_access_control_search_users` and `wpb_access_control_save` are registered exactly once via the `$ajax_registered` static flag. Do not add duplicate registrations.
- **The UI class owns its AJAX save path.** `ajax_save()` must persist through `RuleQuery::set_rule()`; consuming plugins do not need a separate save handler for the standard panel.
- **Validate submitted targets before writing.** `ajax_save()` must reject empty or overlong namespace/key values using `RuleTable::NAMESPACE_LENGTH` and `RuleTable::KEY_LENGTH`.
- **Built-in save extensibility lives in hooks.** Use `wpb_access_control_can_save` to authorize a submitted namespace/key and `wpb_access_control_saved` for post-save side effects.
- **`extract_posted_config()` does NOT call `sanitize_key()` on options** — `RuleQuery::set_rule()` / `normalize_input()` does that on write. Avoid double-processing.
- **JS scopes by `data-wpb-ac-form` attribute, never `getElementById`.** Required so two panels can coexist on one page.
- **Ignore stale live-search responses.** User search requests may return out of order; the active query must win.
- **Asset URL auto-detection uses `WP_CONTENT_DIR`/`WP_CONTENT_URL`.** Call `set_assets_url()` when the package is in a non-standard location.

### WpUserProvider
- **Stores user IDs as strings, not usernames or emails.** `sanitize_key()` strips `@` and `.` — email addresses would be corrupted.

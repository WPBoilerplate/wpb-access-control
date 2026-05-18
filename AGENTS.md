# AGENTS.md — wpb-access-control

> Concise reference for AI coding agents. Read this before touching any file.

---

## Package identity

| | |
|---|---|
| Composer package | `wpboilerplate/wpb-access-control` — type `library` |
| PHP namespace root | `WPBoilerplate\AccessControl\` (PSR-4 from `src/`) |
| Version / PHP / WP | 1.0.0 / min PHP 7.4 / min WP 5.9 |
| PHP autoloader | `automattic/jetpack-autoloader ^5.0` (mandatory) |
| JS build tool | `@wordpress/scripts ^32` (devDep only) |

---

## Repository layout

```
src/
  AccessControlManager.php     Provider registry + user_has_access(). No REST hooks.
  AbstractProvider.php         Abstract base; subclasses implement the 5 methods below.
  WpRoleProvider.php           Built-in: restricts by WP role. Admin always bypassed.
  WpUserProvider.php           Built-in: restricts to user IDs (stored as strings).
  Database/Rule/
    RuleTable.php              BerlinDB Table. Defines schema v3, runs maybe_upgrade().
    RuleSchema.php             BerlinDB Schema. Column definitions for RuleQuery.
    RuleRow.php                BerlinDB Row. Typed property declarations.
    RuleQuery.php              CRUD entry point. Wraps BerlinDB + transient cache.
  RestApi/
    RulesController.php        WP_REST_Controller for wpb-ac/v1.

js/
  index.js                     Webpack entry. Auto-renders into #wpb-access-control.
  AccessControl.js             Main component: state, API calls, layout.
  AccessControl.scss           All BEM styles (.wpb-ac__*).
  components/
    ProviderDropdown.js        <select> with 2 static options + API-loaded providers.
    RoleOptionsPanel.js        Checkbox list for providers with static options[].
    UserSearchPanel.js         Debounced search (300 ms) + autocomplete + user tags.

assets/build/                  Compiled output — do NOT edit. Committed to the repo.
  index.js / index.css         Minified bundle + styles.
  index.asset.php              WP dependency manifest (required by wp_enqueue_script).
```

---

## Commands

```bash
# PHP
composer install
composer test          # PHPUnit

# JS
npm install
npm run build          # production → assets/build/
npm run start          # watch (dev)
```

Run `npm run build` and commit `assets/build/` after every change to `js/`.

---

## Code flow

### PHP: access check

```
consuming plugin
  └─ AccessControlManager::user_has_access($user_id, $ns, $key)
       └─ RuleQuery::get_rule($ns, $key)
            ├─ get_transient("wpbac_" . md5($ns."|".$key))   ← hit: return cached
            └─ BerlinDB query → aggregate rows → set_transient(TTL 7 days)
       └─ access hierarchy (see below) → true / false
            └─ do_action('wpb_access_control_denied', …) on deny
```

**Access hierarchy** (first match wins):
1. `ac_key` empty or `'everyone'` → **allow**
2. `user_can($id, 'manage_options')` → **always allow** (unconditional; do not change)
3. `$user_id === 0` → **deny**
4. No provider registered for `ac_key` → **deny**
5. `provider->user_has_access()` → **allow or deny**

### PHP: rule writes

```
RuleQuery::set_rule($ns, $key, $ac_key, $ac_options)
  └─ normalize_input()       sanitize_key() on $ac_key AND every $ac_options value
  └─ purge_resource()        delete_item() for every existing row of ($ns, $key)
  └─ delete_transient()      invalidate cache immediately
  └─ add_item() × N          one row per option (or sentinel row for 'everyone')
```

### PHP: table lifecycle

`RuleQuery::__construct()` → static guard → `new RuleTable()` (once per request).
`RuleTable` registers `admin_init` → `maybe_upgrade()` → creates or upgrades to schema v3.
Consuming plugin needs no activation hook; instantiating `new AccessControlManager()` is enough.

### JS: component flow

```
index.js (webpack entry)
  ├─ registers apiFetch nonce middleware (auto-render path only)
  └─ render(<AccessControl …/>, #wpb-access-control)
       └─ AccessControl.js  (mount useEffect)
            ├─ GET /wpb-ac/v1/providers          → setProviders([])
            ├─ GET /wpb-ac/v1/rules/{ns}/{key}   → setSelectedKey / setSelectedOptions
            └─ if wp_user rule: GET /wp/v2/users?include[]=… → setSelectedUsers (hydrate IDs)
       └─ render
            ├─ <ProviderDropdown>   2 static opts + available providers from API
            ├─ <RoleOptionsPanel>   shown when provider has options[] and is not wp_user
            └─ <UserSearchPanel>    shown when selectedKey === 'wp_user'
       └─ handleSave
            ├─ selectedKey === ''   → DELETE /wpb-ac/v1/rules/{ns}/{key}
            └─ else                 → PUT  /wpb-ac/v1/rules/{ns}/{key}
                                         body: {ac_key, ac_options: string[]}
```

Namespace slashes encoded: `ns.split('/').map(encodeURIComponent).join('%2F')`.

---

## Database schema (v3)

Table `{prefix}wpb_access_control`:

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `namespace` | VARCHAR(100) | Max `RuleTable::NAMESPACE_LENGTH = 100` |
| `key` | VARCHAR(255) | Max `RuleTable::KEY_LENGTH = 255` |
| `access_control_key` | VARCHAR(100) | Rule type slug |
| `access_control_value` | VARCHAR(255) | One option per row; `''` for `everyone` sentinel |
| `created_at` / `updated_at` | DATETIME | BerlinDB-managed |

Storage: no rows = no rule (`''`); one row = `everyone`; N rows = N options.

---

## REST API (wpb-ac/v1)

| Method | Path | Description |
|---|---|---|
| GET | `/rules/{ns}/{key}` | Read rule |
| PUT | `/rules/{ns}/{key}` | Create/replace — body: `{ac_key, ac_options[]}` |
| DELETE | `/rules/{ns}/{key}` | Clear rule |
| DELETE | `/namespaces/{ns}` | Purge all rows for namespace |
| GET | `/providers` | List providers + options |
| GET | `/users?search=` | Search WP users by login/email/name |

Filters: `wpb_access_control_rest_permission` (all, default `manage_options`),
`wpb_access_control_can_save` (writes, `$key='*'` for namespace purge).
Hook fired after every write: `wpb_access_control_saved`.
Call `$manager->register_rest_api()` inside `rest_api_init`. Manager does not auto-register.

---

## Key invariants — do not break

**AccessControlManager**
- Administrator bypass (`manage_options`) is unconditional — never remove or conditionalize.
- No REST hooks or enforcement logic belongs inside this class.
- `user_has_access()` is the only access-decision entry point.
- Providers fire at `init` priority 5; third-party filters must hook at priority ≤ 4.
- Always pass a plugin-specific filter tag to the constructor.

**RuleQuery / database**
- Never write raw `$wpdb`. Use `set_rule()` / `clear_rule()` so the transient cache stays consistent.
- `set_rule()` calls `sanitize_key()` on options — never pass emails (`.` and `@` are stripped).
- `purge_namespace()` is for uninstall only.
- Table is per-site (`$wpdb->prefix`); multisite network rules are the consuming plugin's concern.

**React component**
- `assets/build/` is generated — never edit it directly.
- `@wordpress/element` and `@wordpress/api-fetch` are WP externals — not bundled.
- All styles belong in `AccessControl.scss` only. No inline styles.
- `apiFetch.use(nonce middleware)` is called once in `index.js` (auto-render) or by the consuming plugin before first render. Never call it inside a render cycle.
- User IDs in `selectedOptions` are always **strings**, matching the sanitized DB storage format.

**REST controller**
- Base permission filter fires before write-authorization filter.
- `{namespace}` URL segment cannot contain literal slashes — clients must encode as `%2F`.
- `wpb_access_control_saved` fires after every write including clears — do not fire it elsewhere.

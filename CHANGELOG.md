# Changelog

## 2.0.0

**BREAKING.** Each consumer plugin now owns its own database table, object-cache group, and REST route prefix. Previously every consumer wrote into the single shared `{prefix}wpb_access_control` table — two plugins on the same WordPress install collided on storage, cache, and routes.

### Required code changes for v1.x → 2.0

1. **Add a slug to the manager constructor.** Both args are now required:

   ```php
   // Before
   $manager = new AccessControlManager( 'my_plugin_access_control_providers' );

   // After (slug must match ^[a-z0-9_]{1,32}$)
   $manager = new AccessControlManager(
       'my_plugin_access_control_providers',
       'my_plugin'
   );
   ```

2. **Add `pluginSlug` to `wpbAcConfig`** (or pass it as a prop when importing the component directly):

   ```php
   wp_localize_script( 'wpb-ac-ui', 'wpbAcConfig', [
       'pluginSlug'  => 'my_plugin',  // NEW: must match the PHP slug
       // …existing fields…
   ] );
   ```

3. **REST URLs gain a slug segment.** Every endpoint is now under `/wpb-ac/v1/{slug}/...`. cURL / api-fetch callers must update their paths:

   ```diff
   - GET  /wpb-ac/v1/rules/{namespace}/{key}
   + GET  /wpb-ac/v1/{slug}/rules/{namespace}/{key}
   - GET  /wpb-ac/v1/providers
   + GET  /wpb-ac/v1/{slug}/providers
   - GET  /wpb-ac/v1/users?search=...
   + GET  /wpb-ac/v1/{slug}/users?search=...
   - DELETE /wpb-ac/v1/namespaces/{namespace}
   + DELETE /wpb-ac/v1/{slug}/namespaces/{namespace}
   ```

4. **Optional one-off data migration.** Existing rows stay in `{prefix}wpb_access_control` until each consumer copies them out:

   ```sql
   INSERT INTO {prefix}my_plugin_access_control
   SELECT * FROM {prefix}wpb_access_control
   WHERE namespace IN ( 'your-namespace-1', 'your-namespace-2' );
   ```

   The library does NOT auto-migrate — it doesn't know which consumer owns which namespace.

### Why

Plugins embedding the library via Composer were silently sharing one table and one REST surface. Two plugins on the same site would collide on rules, on cached payloads (single `wpb_access_control` cache group), and on registered REST routes (last-loaded-wins).

### Changes

- feat(db): per-consumer table — `{prefix}{slug}_access_control` driven by the new required `$table_slug` constructor argument
- feat(db): per-consumer object-cache group (`wpb_ac_{slug}`) and transient prefix
- feat(db): per-slug `db_version_key` option (`wpb_ac_{slug}_db_version`)
- feat(rest): slug-scoped routes under `/wpb-ac/v1/{slug}/...`
- feat(ui): React component requires a new `pluginSlug` prop; `wpbAcConfig.pluginSlug` is required by the auto-render path
- feat(slug): new `WPBoilerplate\AccessControl\Slug` helper validates `^[a-z0-9_]{1,32}$` and throws `\InvalidArgumentException` on invalid input — applied consistently across `RuleTable`, `RuleQuery`, and `AccessControlManager`

## 1.6.0

**BREAKING (BuddyBoss / MemberPress providers):** the `BuddyBossProfileTypeProvider` and `MemberPressMembershipProvider` are now opt-in. Each provider's `is_available()` consults a new filter that defaults to `false`, so the provider is hidden from the React dropdown and denies on every check until the consumer plugin explicitly opts in. Existing rules saved against either provider deny by default after upgrading until the corresponding filter is hooked.

Migration — in your consumer plugin's bootstrap:

```php
// Enable BuddyBoss Profile Type as an access-control option.
add_filter( 'wpb_access_control_bb_profile_type_enabled', '__return_true' );

// Enable MemberPress Membership as an access-control option.
add_filter( 'wpb_access_control_mepr_membership_enabled', '__return_true' );
```

Rationale: plugins that embed `wpb-access-control` via Composer were inheriting both third-party-provider options as soon as those plugins were active on the site, regardless of whether the embedding plugin intended to surface them. Defaulting to `false` keeps the library quiet by default; consumers opt in per provider.

- feat(providers): add `wpb_access_control_bb_profile_type_enabled` filter — default false, must return true for the BuddyBoss provider to fire
- feat(providers): add `wpb_access_control_mepr_membership_enabled` filter — default false, must return true for the MemberPress provider to fire

## 1.5.0

- feat(providers): add `MemberPressMembershipProvider` — gate a resource by one or more MemberPress memberships. Options come from the `memberpressproduct` CPT and curated via the `wpb_access_control_mepr_membership_options` filter
- feat(AccessControl): the React UI's "Who can access" dropdown lists **MemberPress Membership** when MemberPress is active; the entry is hidden automatically when the plugin is inactive (existing `available` flag wired through `RulesController` and `ProviderDropdown`)

## 1.4.0

- feat(providers): add `BuddyBossProfileTypeProvider` — gate a resource by one or more BuddyBoss profile types (member types). Options are listed via `bp_get_member_types()` and curated via the `wpb_access_control_bb_profile_type_options` filter
- feat(AccessControl): the React UI's "Who can access" dropdown lists **BuddyBoss Profile Type** when the BuddyBoss Platform plugin is active; the entry is hidden automatically when BuddyBoss is inactive (existing `available` flag wired through `RulesController` and `ProviderDropdown`)

## 1.3.0

- feat(providers): add `WpCapabilityProvider` — gate a resource by one or more WordPress capability slugs; options are discovered dynamically across every role returned by `wp_roles()` and curated via the `wpb_access_control_wp_capability_options` filter
- feat(AccessControl): the React UI's "Who can access" dropdown now lists **WordPress Capability** alongside Role / Users — no consumer-side changes required

## 1.2.3

- chore(dist): remove `js/` from `.gitattributes` export-ignore so `git archive` and composer dist include the JS source

## 1.2.2

- chore(dist): include `js/` source in composer archive so consuming plugins can webpack-bundle `AccessControl` as a named ESM export

## 1.2.1

- fix(Database): add PRIMARY KEY index to RuleSchema — BerlinDB v3 requires an explicit `primary` Index entry for the `id` column

## 1.2.0

- fix(Database): upgrade BerlinDB to 3.0 — fixes DB table never being created due to `set_schema()` becoming private; switches to `BerlinDB\Database\Kern\*` canonical namespace and native index support
- chore(dist): sync `.gitattributes` and archive exclusions for clean dist installs
- ci: expand PHPUnit matrix to cover PHP 8.1 – 8.5
- chore(deps): update `doctrine/instantiator` 1.5.0 → 2.0.0

## 1.1.1

- feat(AccessControl): add `hideHeader` prop

## 1.1.0

- feat(AccessControl): add `hideSaveButton` and `onChange` props

## 1.0.2

- fix(AccessControl): separate SCSS from component and guard empty `resourceKey`

## 1.0.1

- fix: ensure `get_editable_roles()` is loaded in non-admin REST context
- fix: missing global function prefix in `WpRoleProvider`

## 1.0.0

- Mark this package as the stable release baseline.

# Changelog

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

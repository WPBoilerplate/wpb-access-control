# Changelog

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

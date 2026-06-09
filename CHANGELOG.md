# Changelog

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

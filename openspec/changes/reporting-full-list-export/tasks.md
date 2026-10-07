## 0. Dependency

- [ ] 0.1 Wait for nextcloud-vue `index-export-follows-the-page` to be released, and raise
      humaniq's `@conduction/nextcloud-vue` range to that release.

## 1. Pages

- [ ] 1.1 Add `allowExport: true` to the `indexScaffold` template body in
      `src/manifest.d/00-templates.json` and to the three concrete index pages; regenerate
      `src/manifest.effective.json`. Verify: `npm run check:manifest` exits 0 and every
      `type: index` page in the effective manifest carries `allowExport`.

## 2. Schemas

- [ ] 2.1 Add `configuration.exportable: true` to every schema in
      `lib/Settings/register.d/*.json` except `SickLeaveCase` and `DsrRequest`; bump the
      register version. Verify: `occ maintenance:repair` imports it and
      `GET /api/schemas/{id}` returns `configuration.exportable` for `Employee`.
- [ ] 2.2 Add a unit test over the register fragments that fails when a schema in
      `hr-verzuim.json` or `hr-dsr.json` is exportable (REQ-HLE-002).

## 3. Tests and hand-over

- [ ] 3.1 e2e: export the Employees list filtered to active, and a `Mijn*` list, and count the
      rows in each file (REQ-HLE-001); assert no Export menu on the sick leave list.
- [ ] 3.2 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.

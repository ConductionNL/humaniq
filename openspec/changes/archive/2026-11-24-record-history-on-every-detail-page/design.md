# Design: every record shows who viewed or changed it

## Context

- OpenRegister `lib/Service/Object/GetObject.php` calls `ReadHistoryService::registerAuditRead()`
  on every user-facing read unless the caller passes `_audit: false`; changes are always
  recorded. The instance setting `retention.auditTrailsEnabled` switches the trail off.
- OpenRegister serves one object's history (`/api/objects/{register}/{schema}/{id}/audit-trails`)
  and a readable feed (`/api/audit-trails/readable`), both scoped to what the caller may read.
- humaniq detail pages set `sidebar.showMetadata: true` (for example `EmployeeDetail` in
  `src/manifest.d/hr-objects.json`), which shows nextcloud-vue's audit sidebar tab.
- `src/views/settings/AdminSettings.vue` renders sections with `CnSettingsSection`.

## Decisions

- **D1. One source.** The Audittrail section reads OpenRegister's readable feed filtered to the
  humaniq register, newest first, ten entries. humaniq writes no audit entry itself.
- **D2. Sentences.** Each entry renders as "<person> <verb> <field or record> van <employee>",
  with the verb from the action (bekeek, wijzigde, maakte aan, verwijderde).
- **D3. Full trail and download.** "Volledige audittrail" opens OpenRegister's audit trail page
  filtered to the humaniq register; "Downloaden" uses OpenRegister's audit export with the same
  filter.
- **D4. Every detail page.** A manifest check fails when a `type: "detail"` page lacks
  `sidebar.showMetadata: true`.
- **D5. Machine reads stay quiet.** humaniq services that read records inside one operation (a
  payroll run) pass `_audit: false`, so the trail lists people, not jobs.

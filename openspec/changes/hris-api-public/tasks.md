# Tasks — hris-api-public

> Verify against HEAD, not this brief — OpenRegister's `ObjectsController` route table
> (`/api/objects/{register}/{schema}` + verbs), its `@NoAdminRequired`/`@NoCSRFRequired` posture,
> its RBAC enforcement (`SEC-CTRL-1`) and `RenderHandler::redactWriteOnlyFromRows()`, and
> `humaniq-mcp-adoption`'s six-schema allow-list are already merged/live at HEAD; this change
> documents and catalogues them, it does not build them.

- [ ] 1. Schema: NEW fragment `lib/Settings/register.d/hr-integrations.json` — `IntegrationAccount`
  (name, purpose, nextcloudUserId plain string, grantedSchemas string array, status enum
  actief/ingetrokken, reviewedBy, reviewedAt, createdAt; each property's description states its
  audit/governance role, `grantedSchemas`' description explicitly states it does NOT enforce
  access per design.md D2) per REQ-HRIS-003
- [ ] 2. Register: `lib/Settings/humaniq_register.json` `info.version` bump (new fragment)
- [ ] 3. Manifest: `IntegrationAccounts` index (name/purpose/status/reviewedAt columns) +
  `IntegrationAccountDetail` (data + audit sidebar, no lifecycleActions) under `Configuratie ›
  Integraties` (the previously-unclaimed ADR-001 slot), admin-only per REQ-HRIS-005; `npm run
  check:manifest` passes
- [ ] 4. Seed: 1 `IntegrationAccount` seed (fictitious payroll-partner integration,
  `grantedSchemas: ["Vacancy", "OrgUnit"]`) in `hr-seed.json` per design.md Seed Data
- [ ] 5. Docs: README/docs "Public HRIS API" section — the real `/api/objects/{register}/{schema}`
  endpoint pattern + 5 CRUD verbs (verified against the live `openregister` route table, not
  invented), Nextcloud app-password auth (Settings › Personal › Security), the RBAC/writeOnly-
  redaction model, and the recommended six-schema default subset citing `humaniq-mcp-adoption`
  directly per REQ-HRIS-001/-002/-004
- [ ] 6. Docs: explicit statement that this change adds zero humaniq routes/controllers/services —
  the diff itself should have none outside `hr-integrations.json`/`hr-seed.json`/manifest/docs, per
  REQ-HRIS-001
- [ ] 7. Tests: schema validation test for `IntegrationAccount` (required fields, enum values,
  status default) — no controller/service test needed since none is added
- [ ] 8. Quality gates: `composer check:strict` all green (schema-only change, no PHP class added
  or modified outside tests); `npm run check:manifest` PASS; `npm run build` green; gate-28
  (title+description, `grantedSchemas`' non-enforcement note present)

Acceptance criteria (plain reminders, not tasks):
- No `lib/Controller/`, `lib/Service/`, or `appinfo/routes.php` file appears in this change's diff
  — verify by inspecting the actual diff at implementation time; any such file means scope crept
  back toward rebuilding what OpenRegister already provides
- `IntegrationAccount.grantedSchemas`' schema description states plainly that it is informational,
  not an access-control mechanism — verify the exact wording lands in the JSON, not only in
  design.md
- the documented endpoint pattern and verbs match the ACTUAL `openregister` route table at
  implementation time (re-verify against HEAD — the sibling app may have changed routes since this
  spec was written; do not copy this document's citations blindly)
- the recommended six-schema subset is stated as guidance with a named override path ("any wider
  grant requires a documented reason"), never as a hard-coded enforced list
- i18n keys ENGLISH (ADR-007); Dutch display strings only in manifest labels/schema descriptions
  per existing convention

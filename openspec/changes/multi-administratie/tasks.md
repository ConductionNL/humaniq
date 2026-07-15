# Tasks — multi-administratie

> Verify against HEAD, not this brief. Coordinates with the active `hrmq-ia-navigation-alignment`
> change (owns the `Configuratie` menu-9 drawer) — a clean union, neither re-declares the group.

- [ ] 1. New fragment `lib/Settings/register.d/hr-administratie.json`: `Administration` schema
  (administrationId/name/kvkNumber/loonheffingennummer/active) per REQ-MULTI-002
- [ ] 2. Same fragment: `AdministrationAccess` schema (userId/administrationId/role
  accountant|hr|employee) — the accountant multi-client membership per REQ-MULTI-002
- [ ] 3. Denormalize an optional nullable plain-string `administrationId` (NOT `$ref`) onto every
  schema that lacks it — Employee, EmploymentContract, Payslip, Timesheet, Expense, LeaveRequest,
  LeaveBalance, SickLeaveCase, Onboarding, Vacancy, Application, OrgUnit, OrgAssignment,
  AttendanceRecord, Asset, AssetAssignment, ReviewCycle, PerformanceReview, PensionFiling,
  LoonaangifteFiling — version bump each, per REQ-MULTI-001
- [ ] 4. `lib/Service/AdministrationService.php`: per-user active pointer via `IConfig` user value,
  access resolution against `AdministrationAccess`, ObjectService reads, per REQ-MULTI-003
- [ ] 5. `lib/Controller/AdministrationController.php::setActive` (`POST /api/administration/active`,
  `#[NoAdminRequired]`): resolve posted administrationId to a caller access row BEFORE storing —
  unknown/not-accessible → 404, per REQ-MULTI-003/-MULTI-006
- [ ] 6. `AdministrationController::context` (`GET /api/administration/context`): active id + the
  caller's accessible administraties for the switcher/topbar, per REQ-MULTI-004
- [ ] 7. Routes in `appinfo/routes.php` BEFORE the SPA catch-all per REQ-MULTI-003
- [ ] 8. Backend stamps `runtime.user.activeAdministrationId` into the served manifest from the
  per-user pointer, per REQ-MULTI-005
- [ ] 9. Upstream nc-vue: add `@administration` to the CLOSED `filter` vocabulary in
  `utils/sentinelTokens.js` + the mirrored `$defs` pattern in `app-manifest-v2.schema.json` (update
  the byte-equality test) per REQ-MULTI-005
- [ ] 10. Upstream nc-vue: resolve `@administration` / `@administration?` in
  `utils/resolveFilterTokens.js` from `runtime.user.activeAdministrationId`; beta publish + hrmq
  consumes, per REQ-MULTI-005
- [ ] 11. `src/manifest.json`: add fixed base `filter: { "administrationId": "@administration?" }` to
  every index and detail page, ANDed with existing `@me`/`status`/`@objectId` filters, per
  REQ-MULTI-004
- [ ] 12. `src/manifest.json`: `Configuratie › Administraties` page with the switcher (backed by
  `GET/POST /api/administration/*`) + catalog list; NO new top-level menu (ADR-001 Rule 3), per
  REQ-MULTI-006
- [ ] 13. `src/manifest.json`: Dashboard administratie-context widget (active administratie +
  switch); `runtime.user` visibleIf wiring; `npm run check:manifest` passes, per REQ-MULTI-006
- [ ] 14. `lib/Standards/Checks/NlAdministratieChecks.php` + rule statement
  `nl-administratie-scope-consistency` (recommended severity, child vs parent administrationId,
  vacuous on absence) per REQ-MULTI-007
- [ ] 15. `lib/Service/RuleAuditService.php`: related-context enrichment (`administratie.byId` +
  parent index) — one degrade-to-empty pre-pass, per REQ-MULTI-007
- [ ] 16. Seeds in `hr-seed.json`: two `Administration` rows (ADM-001/ADM-002, `example-` prefixed),
  `AdministrationAccess` granting `admin` accountant access to both, per REQ-MULTI-008
- [ ] 17. Seeds: backfill `administrationId: "ADM-001"` on every existing core-entity seed + a small
  isolated ADM-002 set (Employee/Timesheet/Payslip) per REQ-MULTI-008
- [ ] 18. Tests: `AdministrationServiceTest` + `AdministrationControllerTest` (access-guarded setter:
  accessible id stored, inaccessible/unknown → 404; context returns only accessible) per
  REQ-MULTI-003
- [ ] 19. Tests: `NlAdministratieChecksTest` (violating mismatch, each vacuous path) +
  fixture proving `@administration?` composes with an existing base filter, per REQ-MULTI-007
- [ ] 20. README: the scoping-≠-security caveat + the OpenRegister-organisation isolation hardening
  fast-follow, stated plainly; quality gates green (`composer lint`, PHPUnit, `check:manifest`,
  `npm run build`) per REQ-MULTI-007

Acceptance criteria (plain reminders, not tasks):
- `administrationId` stays a plain string everywhere (never a `$ref`) — the PayrollRun convention
- the `@administration` token is a real closed-vocabulary addition, never an app-side invented token
- the setter refuses any administratie the caller has no `AdministrationAccess` row for (guard-first)
- the optional `?` form preserves single-administratie / fresh-install behaviour (show-all on unset)
- SPDX + `@spec` on every new/changed PHP method; i18n keys ENGLISH; Dutch only in manifest labels
- no top-level menu is added anywhere (ADR-001 Rule 3); scoping is documented as NOT a security boundary

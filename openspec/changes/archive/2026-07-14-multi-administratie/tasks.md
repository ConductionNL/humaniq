# Tasks — multi-administratie

> Verify against HEAD, not this brief. Coordinates with the active `hrmq-ia-navigation-alignment`
> change (owns the `Configuratie` menu-9 drawer) — a clean union, neither re-declares the group.

- [x] 1. New fragment `lib/Settings/register.d/hr-administratie.json`: `Administration` schema
  (administrationId/name/kvkNumber/loonheffingennummer/active) per REQ-MULTI-002
- [x] 2. Same fragment: `AdministrationAccess` schema (userId/administrationId/role
  accountant|hr|employee) — the accountant multi-client membership per REQ-MULTI-002
- [x] 3. Denormalize an optional nullable plain-string `administrationId` (NOT `$ref`) onto every
  schema that lacks it — Employee, EmploymentContract, Payslip, Timesheet, Expense, LeaveRequest,
  LeaveBalance, SickLeaveCase, Onboarding, Vacancy, Application, OrgUnit, OrgAssignment,
  AttendanceRecord, Asset, AssetAssignment, ReviewCycle, PerformanceReview, PensionFiling,
  LoonaangifteFiling — version bump each, per REQ-MULTI-001 (verified: all named schemas carry the
  field; 17 register.d fragments touched)
- [x] 4. `lib/Service/AdministrationService.php`: per-user active pointer via `IConfig` user value,
  access resolution against `AdministrationAccess`, ObjectService reads, per REQ-MULTI-003
- [x] 5. `lib/Controller/AdministrationController.php::setActive` (`POST /api/administration/active`,
  `#[NoAdminRequired]`): resolve posted administrationId to a caller access row BEFORE storing —
  unknown/not-accessible → 404, per REQ-MULTI-003/-MULTI-006 (verified: `hasAccess()` guard-first)
- [x] 6. `AdministrationController::context` (`GET /api/administration/context`): active id + the
  caller's accessible administraties for the switcher/topbar, per REQ-MULTI-004
- [x] 7. Routes in `appinfo/routes.php` BEFORE the SPA catch-all per REQ-MULTI-003
- [ ] 8. Backend stamps `runtime.user.activeAdministrationId` into the served manifest from the
  per-user pointer, per REQ-MULTI-005 — ⚠️ UPSTREAM-BLOCKED (see note): the value is delivered via
  `GET /api/administration/context` instead; stamping it into the served manifest only pays off once
  the `@administration` token below resolves it. Deferred to the nc-vue follow-up.
- [ ] 9. Upstream nc-vue: add `@administration` to the CLOSED `filter` vocabulary in
  `utils/sentinelTokens.js` + the mirrored `$defs` pattern in `app-manifest-v2.schema.json` (update
  the byte-equality test) per REQ-MULTI-005 — ⚠️ UPSTREAM-BLOCKED: lives in the nextcloud-vue repo,
  out of this hrmq worktree. Filed as a follow-up.
- [ ] 10. Upstream nc-vue: resolve `@administration` / `@administration?` in
  `utils/resolveFilterTokens.js` from `runtime.user.activeAdministrationId`; beta publish + hrmq
  consumes, per REQ-MULTI-005 — ⚠️ UPSTREAM-BLOCKED (nextcloud-vue). Filed as a follow-up.
- [ ] 11. `src/manifest.json`: add fixed base `filter: { "administrationId": "@administration?" }` to
  every index and detail page, ANDed with existing `@me`/`status`/`@objectId` filters, per
  REQ-MULTI-004 — ⚠️ UPSTREAM-BLOCKED: deliberately NOT added, because `@administration` is not yet
  in the closed token vocabulary and `check:manifest` would (correctly) reject an invented token.
  Lands with tasks 9/10.
- [x] 12. `src/manifest.json`: `Configuratie › Administraties` page with the switcher (backed by
  `GET/POST /api/administration/*`) + catalog list; NO new top-level menu (ADR-001 Rule 3), per
  REQ-MULTI-006 (verified: `Administraties` page + `AdministrationSwitcher.vue`)
- [ ] 13. `src/manifest.json`: Dashboard administratie-context widget (active administratie +
  switch); `runtime.user` visibleIf wiring; `npm run check:manifest` passes, per REQ-MULTI-006 —
  PARTIAL: the switcher exists on the Configuratie page; the Dashboard widget's `runtime.user`
  visibleIf wiring is coupled to the task-8 manifest stamping, so it is deferred with that cluster.
  (`check:manifest` passes.)
- [x] 14. `lib/Standards/Checks/NlAdministratieChecks.php` + rule statement
  `nl-administratie-scope-consistency` (recommended severity, child vs parent administrationId,
  vacuous on absence) per REQ-MULTI-007 (verified: implements CheckProvider → auto-discovered)
- [x] 15. `lib/Service/RuleAuditService.php`: related-context enrichment (`administratie.byId` +
  parent index) — one degrade-to-empty pre-pass, per REQ-MULTI-007
- [x] 16. Seeds in `hr-seed.json`: two `Administration` rows (ADM-001/ADM-002, `example-` prefixed),
  `AdministrationAccess` granting `admin` accountant access to both, per REQ-MULTI-008
- [x] 17. Seeds: backfill `administrationId: "ADM-001"` on every existing core-entity seed + a small
  isolated ADM-002 set (Employee/Timesheet/Payslip) per REQ-MULTI-008
- [x] 18. Tests: `AdministrationServiceTest` + `AdministrationControllerTest` (access-guarded setter:
  accessible id stored, inaccessible/unknown → 404; context returns only accessible) per
  REQ-MULTI-003
- [x] 19. Tests: `NlAdministratieChecksTest` (violating mismatch, each vacuous path) per REQ-MULTI-007
  (the `@administration?` filter-composition fixture is deferred with the upstream token, task 11)
- [x] 20. README: the scoping-≠-security caveat + the OpenRegister-organisation isolation hardening
  fast-follow, stated plainly; quality gates green (`composer lint`, PHPUnit 526/526,
  `check:manifest` PASS, `npm run build` exit 0) per REQ-MULTI-007

> **Upstream-blocked cluster (tasks 8–11, 13).** Automatic per-page filtering by the active
> administratie requires an `@administration` filter token in the shared nextcloud-vue manifest
> vocabulary — a CLOSED, schema-validated set that cannot be invented app-side (doing so fails
> `check:manifest`). This change ships the entire hrmq side (Administration/AdministrationAccess
> schemas, the denormalized `administrationId` rollout, the guarded active-administratie
> endpoints, the switcher UI, the consistency rule, and seeds). Wiring `@administration` into
> `sentinelTokens.js`/`resolveFilterTokens.js`, stamping `runtime.user.activeAdministrationId` into
> the served manifest, and adding the per-page `filter` is a named nextcloud-vue follow-up (filed as
> a tracking issue). Until it lands, the safe `?`-optional default shows all accessible administraties
> together — no regression for single-administratie installs.

Acceptance criteria (plain reminders, not tasks):
- `administrationId` stays a plain string everywhere (never a `$ref`) — the PayrollRun convention
- the `@administration` token is a real closed-vocabulary addition, never an app-side invented token
- the setter refuses any administratie the caller has no `AdministrationAccess` row for (guard-first)
- the optional `?` form preserves single-administratie / fresh-install behaviour (show-all on unset)
- SPDX + `@spec` on every new/changed PHP method; i18n keys ENGLISH; Dutch only in manifest labels
- no top-level menu is added anywhere (ADR-001 Rule 3); scoping is documented as NOT a security boundary

# Tasks — single-person-modes

> Verify against HEAD, not this brief — `dga-payroll-mode`, `proforma-payslip`, and `multi-administratie` are
> already merged at HEAD; this change composes/extends them, it does not depend on any pending change.

- [ ] 1. Schema: `lib/Settings/register.d/hr-administratie.json` — `Administration.mode` enum
  (`standard`/`dga_single_person`/`eenmanszaak_no_payroll`, default `standard`) per REQ-SPM-001
- [ ] 2. Service: `AdministrationService` — resolve the active administratie's `mode` (default `standard` when
  unresolved, the `activeAdministrationId` no-regression precedent) per REQ-SPM-002
- [ ] 3. Controller: `PageController::index()` — `provideInitialState('activeAdministrationMode', $mode)` per
  REQ-SPM-002
- [ ] 4. Controller: `AdministrationController::context()` — include `mode` per administratie entry in the
  response per REQ-SPM-002
- [ ] 5. Frontend: wire the seeded `activeAdministrationMode` initial state into `manifest.runtime.user.
  administrationMode` (verify against HEAD exactly which `CnAppRoot` boot contract accepts this — design.md D2)
  per REQ-SPM-002
- [ ] 6. Manifest: `visibleIf` on `OrgUnits`/`OrgAssignments`/`TimesheetApproval`/`TeamUrengoedkeuring`/
  `LeaveApproval`/`TeamVerlofgoedkeuring`/`ExpenseApproval`/`TeamDeclaratiegoedkeuring`/`PlanningGroup` (hidden
  when `mode` is not `standard`) per REQ-SPM-003
- [ ] 7. Manifest: `visibleIf` on `PayrollGroup`/`ProformaPayslipMenu` (hidden only when `mode:
  eenmanszaak_no_payroll`) per REQ-SPM-004
- [ ] 8. Standards: NEW `lib/Standards/Checks/NlSinglePersonChecks.php` — `nl-single-person-mode-employee-count`
  (recommended severity, auto-discovered, vacuous unless `mode: dga_single_person`) per REQ-SPM-005
- [ ] 9. Controller: NEW `PayrollController::dgaStatus()` — resolve caller's own `Employee` via
  `nextcloudUserId`, 404 when absent or not a DGA, call `NlDgaChecks`'s existing predicate, return
  `{isDga, grossAnnualSalaryCents, jaarnormCents, met, justification}`, no persistence per REQ-SPM-006
- [ ] 10. Routes: `appinfo/routes.php` — `GET /api/payroll/dga-status` before the SPA catch-all per REQ-SPM-006
- [ ] 11. Manifest: NEW `MijnGebruikelijkLoon` self-service page (`MijnHrGroup`, `visibleIf mode:
  dga_single_person`) with a `banner` widget bound to the new endpoint (design.md D5 fallback documented if
  `visibleWhen.endpoint` cannot be used as designed) per REQ-SPM-006; `npm run check:manifest` passes
- [ ] 12. Seed: `ADM-003` (`dga_single_person`, one below-norm unjustified DGA employee) + `ADM-004`
  (`eenmanszaak_no_payroll`, zero employees) per design.md Seed Data
- [ ] 13. Tests: `NlSinglePersonChecksTest` — vacuous under `standard`/`eenmanszaak_no_payroll`, satisfied at
  exactly 1 DGA employee, violated at 0/2+/1-non-DGA per REQ-SPM-005
- [ ] 14. Tests: `PayrollControllerTest::testDgaStatus*` — 404 no-Employee, 404 non-DGA, correct `met` for
  below/at/above-norm and justified cases, zero writes per REQ-SPM-006
- [ ] 15. Tests: `AdministrationServiceTest`/`AdministrationControllerTest` — `mode` resolution + response shape,
  default `standard` when unresolved per REQ-SPM-002
- [ ] 16. README: the mode toggle, exactly which menus it hides (design.md D3 table), and the explicit
  FOR/lijfrente/IB-pakket/accountant-delegation/KilometerLog/urencriterium non-goals list per REQ-SPM-001/-006
- [ ] 17. Quality gates: `composer check:strict` ALL CHECKS PASSED; `npm run check:manifest` PASS (0 errors);
  `npm run build` green

Acceptance criteria (plain reminders, not tasks):
- `PayrollCalculator`, `NlDgaChecks`'s predicate, and `TaxTables::gebruikelijkloon()` have zero new call sites
  beyond the new `dgaStatus()` self-service wrapper — no reimplementation of the norm comparison
- an administratie whose `mode` stays `standard` (the default) sees no menu-visibility change and no new
  self-service page — verify by loading the existing `ADM-001`/`ADM-002` fixtures after this change
- `dgaStatus()` never distinguishes "no Employee" from "Employee, not a DGA" in its response (both 404) — verify
  by tracing D5, not by assuming it from this brief
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH (ADR-007); Dutch strings only
  in manifest labels/messages per existing convention
- no new schema for FOR/lijfrente/box-2/KilometerLog/TaxContext/IB-pakket exists anywhere in the diff — verify by
  grepping `lib/Settings/register.d/*.json` for those names after implementation; their absence is intentional

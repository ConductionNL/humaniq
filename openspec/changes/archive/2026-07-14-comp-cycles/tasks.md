# Tasks — comp-cycles

> Placement is fixed by ADR-001 Rule 6: comp is a detail surface on the personnel dossier + a
> reference sub-page under `Personeel` — NO new top-level menu. Verify against HEAD, not this brief.

- [x] 1. Schema: `lib/Settings/register.d/hr-comp.json` — `SalaryBand`
  (`schema:MonetaryAmountDistribution`, bandId natural key, min/reference/max salary in integer cents,
  currency, optional cao/caoSchaal, effectiveFrom, active) per REQ-COMP-001
- [x] 2. Schema: `CompReviewCycle` (`schema:Action`, name/period/effectiveDate/status open|closed)
  per REQ-COMP-002
- [x] 3. Schema: `CompAdjustment` (`schema:Action`, cycleId/employeeId/contractId/currentSalary/
  proposedSalary/targetBandId/effectiveDate/proposedBy/approvedBy/rationale/appliedAt) per REQ-COMP-003
- [x] 4. Schema: `CompAdjustment.configuration.x-openregister-lifecycle` — field status, initial
  draft, terminal [effective], transitions propose/approve/reject/effectuate in the hr-leave shape
  per REQ-COMP-003
- [x] 5. Register wiring: reference `hr-comp.json` in the import order (`lib/Settings/hrmq_register.json`);
  `npm run check:manifest` / register import stays green per REQ-COMP-001/-002/-003
  — verified against HEAD: fragments are auto-globbed by `SettingsService::loadConfigurationForced()`
  (`register.d/*.json`), so `hrmq_register.json` itself needed no edit; all four register.d JSON
  fragments touched by this change parse cleanly.
- [x] 6. Guard reuse: bind `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard` to the `approve` (and `reject`)
  transition — no new guard code for separation of duties per REQ-COMP-004
- [x] 7. Guard: `lib/Lifecycle/CompEffectiveDateGuard.php` — read-only, fail-closed
  `LifecycleGuardInterface` (PayrollRunApprovedGuard shape), denies effectuate unless effectiveDate
  present and ≤ today; bound via `transitions.effectuate.requires` per REQ-COMP-005
  — deviation from the design.md prose: constructed with NO ContainerInterface/IAppConfig
  (the NoSelfApprovalGuard shape, not PayrollRunApprovedGuard's), since the date check needs neither a
  load nor the register — injecting unused collaborators would itself be the orphaned/unused-dependency
  anti-pattern the fleet's gates flag.
- [x] 8. Service: `lib/Service/CompAdjustmentService.php` — resolve approved+due adjustment, refuse
  non-approved / not-yet-due, validate within-band, write `grossMonthlySalary` onto the Employee,
  stamp appliedAt, drive the effectuate transition; idempotent, ObjectService idiom per REQ-COMP-006
  — verified against HEAD: `Employee.grossMonthlySalary` is a plain euro float (not integer cents), so
  the write converts `proposedSalary` cents -> euros (`PayrollRunService::euros()` idiom).
- [x] 9. Command: `lib/Command/CompEffectuateCommand.php`
  (`hrmq:comp:effectuate --cycle [--date] [--dry-run]`, per-employee outcome) + register in
  `appinfo/info.xml` per REQ-COMP-006
- [x] 10. Controller: `lib/Controller/CompController.php` (`effectuate`, `#[NoAdminRequired]`,
  RBAC-resolve-first → 404, non-approved/not-due → 400, delegate) + route in `appinfo/routes.php`
  BEFORE the SPA catch-all per REQ-COMP-006
- [x] 11. Check: `lib/Standards/Checks/CompChecks.php` — `comp-adjustment-within-band` predicate
  (vacuous when targetBandId null; band loaded via audit context) + the rule statement in the corpus
  per REQ-COMP-007
  — rule added to `lib/Standards/rules/labour.json` (framework `hr-comp-core`); `RuleAuditService`
  gained `buildCompContext()` (`comp.salaryBandsById`); `RuleCatalogue::VERSION` bumped
  `2026-07.15` → `2026-07.16`.
- [x] 12. Manifest: `EmployeeDetail` `emp-comp-adjustments` object-list (filter employeeId,
  rowRoute CompAdjustmentDetail) — the Rule 6 detail surface per REQ-COMP-008
- [x] 13. Manifest: `CompAdjustmentDetail` `lifecycleActions` widget + "Effectueren" `api-call`
  action to `/api/comp/effectuate`; `CompReviewCycleDetail` "Aanpassing voorstellen" open-form action
  per REQ-COMP-008
  — `lifecycleActions` deliberately exposes ONLY propose/approve/reject, NOT effectuate: a bare
  declarative transition would flip status to effective without ever writing the salary (guards are
  read-only); the api-call action is the only effectuate path.
  — deviation from design.md's "seeding a draft CompAdjustment scoped to the cycle": verified this
  manifest version has no field-prefill primitive on `open-form` actions anywhere in the codebase, so
  the action opens a plain create form (the `create-run`/`create-roster` shape); cycleId is entered on
  the form rather than auto-seeded.
- [x] 14. Manifest: `SalaryBands`/`SalaryBandDetail` + `CompReviewCycles`/`CompReviewCycleDetail`
  sub-pages under the existing `Personeel` menu (siblings of CAO's), SalaryBand `allowCreate:false`,
  NO new top-level menu; `npm run check:manifest` passes per REQ-COMP-008
  — verified: top-level menu count unchanged (10 before/after); `npm run check:manifest` PASS (0 errors).
- [x] 15. Seed: `hr-seed.json` two SalaryBands, one open CompReviewCycle, one draft CompAdjustment for
  the seeded employee (proposedSalary within band) per REQ-COMP-002/-003
  — also added `contract-jansen-vast`, a new EmploymentContract seed for `employee-jansen` (the
  designated seeded employee), since no EmploymentContract was previously seeded for it — needed as the
  CompAdjustment's `contractId` anchor.
- [x] 16. Tests: `CompEffectiveDateGuardTest` (present+past date allows, future/empty denies, fail-closed)
  + `CompAdjustmentServiceTest` (mocked ObjectService: approved+due writes grossMonthlySalary + moves
  to effective; non-approved/not-due/out-of-band refused; idempotent) + `CompChecksTest` (within-band,
  vacuous null band) per REQ-COMP-004/-005/-006/-007
  — CompChecksTest drives the predicate through the REAL RuleEngine + RuleCatalogue corpus (not the raw
  closure), proving the corpus rule is reachable, not orphaned.
- [x] 17. Quality gates: `composer lint` green, full PHPUnit suite green, `npm run check:manifest`
  PASS, `npm run build` green; SPDX + `@spec` on every new PHP method (gate-16); i18n keys ENGLISH,
  Dutch only in manifest labels/messages per REQ-COMP-001..008
  — `composer lint`: 0 syntax errors. PHPUnit: 483/483 green (22 new). `npm run check:manifest`:
  PASS (0 errors). `npm run build`: compiled with 2 pre-existing bundle-size warnings, 0 errors.

Acceptance criteria (plain reminders, not tasks):
- market-data benchmarking stays OUT (no external survey feed introduced) — bands are internal
  min/reference/max only
- guards are read-only (no salary write in CompEffectiveDateGuard); the effective-dated write is the
  service's job, targeting `Employee.grossMonthlySalary` (the field payroll reads)
- separation of duties is the reused `NoSelfApprovalGuard` — no duplicated approver≠proposer logic
- placement obeys ADR-001 Rule 6: detail surface + `Personeel` sub-pages, never a 10th top-level menu
- endpoint params match the manifest action exactly as `{adjustmentId: "@objectId"}`

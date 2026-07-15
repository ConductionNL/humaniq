# Tasks — comp-cycles

> Placement is fixed by ADR-001 Rule 6: comp is a detail surface on the personnel dossier + a
> reference sub-page under `Personeel` — NO new top-level menu. Verify against HEAD, not this brief.

- [ ] 1. Schema: `lib/Settings/register.d/hr-comp.json` — `SalaryBand`
  (`schema:MonetaryAmountDistribution`, bandId natural key, min/reference/max salary in integer cents,
  currency, optional cao/caoSchaal, effectiveFrom, active) per REQ-COMP-001
- [ ] 2. Schema: `CompReviewCycle` (`schema:Action`, name/period/effectiveDate/status open|closed)
  per REQ-COMP-002
- [ ] 3. Schema: `CompAdjustment` (`schema:Action`, cycleId/employeeId/contractId/currentSalary/
  proposedSalary/targetBandId/effectiveDate/proposedBy/approvedBy/rationale/appliedAt) per REQ-COMP-003
- [ ] 4. Schema: `CompAdjustment.configuration.x-openregister-lifecycle` — field status, initial
  draft, terminal [effective], transitions propose/approve/reject/effectuate in the hr-leave shape
  per REQ-COMP-003
- [ ] 5. Register wiring: reference `hr-comp.json` in the import order (`lib/Settings/hrmq_register.json`);
  `npm run check:manifest` / register import stays green per REQ-COMP-001/-002/-003
- [ ] 6. Guard reuse: bind `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard` to the `approve` (and `reject`)
  transition — no new guard code for separation of duties per REQ-COMP-004
- [ ] 7. Guard: `lib/Lifecycle/CompEffectiveDateGuard.php` — read-only, fail-closed
  `LifecycleGuardInterface` (PayrollRunApprovedGuard shape), denies effectuate unless effectiveDate
  present and ≤ today; bound via `transitions.effectuate.requires` per REQ-COMP-005
- [ ] 8. Service: `lib/Service/CompAdjustmentService.php` — resolve approved+due adjustment, refuse
  non-approved / not-yet-due, validate within-band, write `grossMonthlySalary` onto the Employee,
  stamp appliedAt, drive the effectuate transition; idempotent, ObjectService idiom per REQ-COMP-006
- [ ] 9. Command: `lib/Command/CompEffectuateCommand.php`
  (`hrmq:comp:effectuate --cycle [--date] [--dry-run]`, per-employee outcome) + register in
  `appinfo/info.xml` per REQ-COMP-006
- [ ] 10. Controller: `lib/Controller/CompController.php` (`effectuate`, `#[NoAdminRequired]`,
  RBAC-resolve-first → 404, non-approved/not-due → 400, delegate) + route in `appinfo/routes.php`
  BEFORE the SPA catch-all per REQ-COMP-006
- [ ] 11. Check: `lib/Standards/Checks/CompChecks.php` — `comp-adjustment-within-band` predicate
  (vacuous when targetBandId null; band loaded via audit context) + the rule statement in the corpus
  per REQ-COMP-007
- [ ] 12. Manifest: `EmployeeDetail` `emp-comp-adjustments` object-list (filter employeeId,
  rowRoute CompAdjustmentDetail) — the Rule 6 detail surface per REQ-COMP-008
- [ ] 13. Manifest: `CompAdjustmentDetail` `lifecycleActions` widget + "Effectueren" `api-call`
  action to `/api/comp/effectuate`; `CompReviewCycleDetail` "Aanpassing voorstellen" open-form action
  per REQ-COMP-008
- [ ] 14. Manifest: `SalaryBands`/`SalaryBandDetail` + `CompReviewCycles`/`CompReviewCycleDetail`
  sub-pages under the existing `Personeel` menu (siblings of CAO's), SalaryBand `allowCreate:false`,
  NO new top-level menu; `npm run check:manifest` passes per REQ-COMP-008
- [ ] 15. Seed: `hr-seed.json` two SalaryBands, one open CompReviewCycle, one draft CompAdjustment for
  the seeded employee (proposedSalary within band) per REQ-COMP-002/-003
- [ ] 16. Tests: `CompEffectiveDateGuardTest` (present+past date allows, future/empty denies, fail-closed)
  + `CompAdjustmentServiceTest` (mocked ObjectService: approved+due writes grossMonthlySalary + moves
  to effective; non-approved/not-due/out-of-band refused; idempotent) + `CompChecksTest` (within-band,
  vacuous null band) per REQ-COMP-004/-005/-006/-007
- [ ] 17. Quality gates: `composer lint` green, full PHPUnit suite green, `npm run check:manifest`
  PASS, `npm run build` green; SPDX + `@spec` on every new PHP method (gate-16); i18n keys ENGLISH,
  Dutch only in manifest labels/messages per REQ-COMP-001..008

Acceptance criteria (plain reminders, not tasks):
- market-data benchmarking stays OUT (no external survey feed introduced) — bands are internal
  min/reference/max only
- guards are read-only (no salary write in CompEffectiveDateGuard); the effective-dated write is the
  service's job, targeting `Employee.grossMonthlySalary` (the field payroll reads)
- separation of duties is the reused `NoSelfApprovalGuard` — no duplicated approver≠proposer logic
- placement obeys ADR-001 Rule 6: detail surface + `Personeel` sub-pages, never a 10th top-level menu
- endpoint params match the manifest action exactly as `{adjustmentId: "@objectId"}`

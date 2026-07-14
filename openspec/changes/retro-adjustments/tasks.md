# Tasks — retro-adjustments

> Consumes the merged `payroll-core-engine` / `payroll-core-schema` at HEAD (`PayrollCalculator`,
> `TaxTables`, `PayrollRunService`, Payslip/PayrollRun stamps). Verify against HEAD, not this brief.

- [ ] 1. Schema: new fragment `lib/Settings/register.d/hr-retro.json` — `PayrollAdjustment` with
  `employeeId`/`originalPayrollRunId`/`originalPayslipId`/`settlementPayrollRunId` `$ref`s,
  `originalPeriod`/`settlementPeriod`, `correctionType`/`correctedGrossMonthlySalary`/`correctionRef`,
  the `delta*` euro number fields, `engineVersion`, `settlementLine`, `status` (draft|applied),
  `calculatedAt`; no `x-openregister-lifecycle` per REQ-RETRO-001
- [ ] 2. Schema: add nullable `retroAdjustment` component field to `Payslip` in
  `lib/Settings/register.d/hr-objects.json` + version bump per REQ-RETRO-004
- [ ] 3. Service: `lib/Service/RetroAdjustmentService.php` — `adjustFor()` resolving the original
  sealed Payslip via its `payrollRunId`, recompute + cents-exact delta diff, upsert keyed
  `(originalPeriod, employeeId, correctionRef)` via ObjectService (never write the sealed
  payslip/run) per REQ-RETRO-001/-RETRO-003
- [ ] 4. Service: derive `nl-{originalYear}` and `TaxTables::load()` for the recompute; refuse with
  the `historical-tables-missing` message when the historical table file is absent (same-tax-year
  MVP boundary) per REQ-RETRO-002
- [ ] 5. Service: refuse when the original run's `status` is `draft`
  (`refused-original-draft` — recalculate the draft run directly) per REQ-RETRO-005
- [ ] 6. Service: `settlementPeriod`/`settlementLine` (nabetaling/terugvordering) resolution + apply
  mode (`status: draft → applied`, stamp `settlementPayrollRunId`) per REQ-RETRO-004
- [ ] 7. Engine integration: extend `PayrollRunService.generate()` to sum `applied` PayrollAdjustments
  with `settlementPeriod == run.period` into each payslip's `retroAdjustment` component and
  `nettoPay` — current run only, sealed history untouched per REQ-RETRO-004
- [ ] 8. Command: `lib/Command/PayrollAdjustCommand.php` (`hrmq:payroll:adjust --original-period
  --employee --correction-ref [--gross] [--settlement-period] [--apply]`, delta + outcome output) +
  register in `appinfo/info.xml` per REQ-RETRO-007
- [ ] 9. Command: `lib/Command/PayrollYearTransitionCommand.php` (`hrmq:payroll:year-transition
  --year YYYY` preflight: asserts `nl-YYYY.json`, reports period-derived design + immutable stamp) +
  register per REQ-RETRO-006
- [ ] 10. Controller: `lib/Controller/PayrollController.php` `adjust` (`#[NoAdminRequired]`,
  RBAC-resolve adjustmentId + original run first → 404, applied → 400, delegate) + route in
  `appinfo/routes.php` BEFORE the SPA catch-all per REQ-RETRO-007
- [ ] 11. Manifest: `PayrollRunDetail` `open-form` "Correctie boeken (TWK)" action (prefill
  `originalPayrollRunId: @objectId`); new `PayrollAdjustmentDetail` page with the `api-call`
  "Herrekenen" action + delta stat block; `npm run check:manifest` passes per REQ-RETRO-007
- [ ] 12. Checks: `lib/Standards/Checks/NlRetroChecks.php` predicate for
  `nl-retro-adjustment-consistency` (vacuous when `engineVersion` null; else delta = recomputed −
  stored cents-exact) + context enrichment per REQ-RETRO-001
- [ ] 13. Rule: add `nl-retro-adjustment-consistency` (PayrollAdjustment, ONE static `mandatory`
  severity) to `lib/Standards/rules/payroll.json` + bump `RuleCatalogue::VERSION` per REQ-RETRO-001
- [ ] 14. Tests: `RetroAdjustmentServiceTest` (mocked ObjectService: recompute against the original
  tax year, cents-exact delta, idempotency by correctionRef, sealed-payslip untouched,
  draft-original refusal, same-tax-year-boundary refusal) per REQ-RETRO-002/-RETRO-003/-RETRO-005
- [ ] 15. Tests: `NlRetroChecksTest` (consistent delta passes, tampered delta fails, null
  `engineVersion` vacuous) + engine-integration test that an applied adjustment folds into the
  current run's payslip without touching the sealed original per REQ-RETRO-001/-RETRO-004
- [ ] 16. README: TWK section — same-tax-year boundary, multi-year follow-up, sealed-payslip
  immutability, period-derived immutable taxYear, bijzonder-tarief follow-up per REQ-RETRO-002/-RETRO-006
- [ ] 17. Quality gates: `composer lint` green, full PHPUnit suite green (php:8.3-cli), `npm run
  check:manifest` PASS, `npm run build` green; SPDX + `@spec` tags on every new PHP method

Acceptance criteria (plain reminders, not tasks):
- an adjustment is a DELTA object — the sealed original Payslip/PayrollRun is never passed to
  saveObject/deleteObject
- recompute always uses `nl-{originalYear}`; a missing historical table refuses, never recomputes
  against the wrong year
- the delta surfaces only in the CURRENT run's payslip (`retroAdjustment` + `nettoPay`), and only for
  `applied` adjustments
- `lib/Payroll/` stays pure (reused, not modified); service/controller own all NC wiring
- i18n keys ENGLISH (ADR-007); Dutch strings only in manifest labels/messages

# Tasks — sick-pay-calc

> Consumes the merged `payroll-core-engine` (PayrollCalculator/TaxTables/PayrollRunService,
> nl-2026 tables) and `leave-verzuim-mvp` (SickLeaveCase, nl-loondoorbetaling-minimum,
> NlVerzuimChecks). Verify against HEAD, not this brief.

- [x] 1. Value objects: `lib/Payroll/SickPayInput.php` (referenceWageCents, aangepastLoonCents,
  loondoorbetalingPercentage, yearOne, wachtdag, firstSickDayInPeriod, contractHoursPerWeek,
  fulltimeHoursPerWeek) + `lib/Payroll/SickPayResult.php` (doorbetaaldLoonCents,
  wachtdagDeductionCents, payableGrossCents, minimumWageFloorCents, floorApplied, appliedPercentage,
  yearOne, referenceWageCents) — immutable, zero NC deps (the CalculationInput/Result idiom) per
  REQ-SICK-001
- [x] 2. Calculator: `lib/Payroll/SickPayCalculator.php::compute()` — the D2 chain (non-worked base,
  continuation `round(B×p/100)`, `L0 = A + C`), pure PHP, integer cents, half-up rounding, zero NC
  deps; anchor verified digit-for-digit against the D2 hand computation (payableGross 266000 =
  €2.660,00) per REQ-SICK-001
- [x] 3. Calculator: the year-1 WML floor — `floor = max(round(W×70/100), yearOne ? min(W,M) : 0)`,
  `M` from `TaxTables.wml().referentiemaandloon` × part-time factor (design.md D3); `floorApplied`
  flag per REQ-SICK-002. Added `TaxTables::wml()` accessor (was missing at HEAD — design.md D1/D3
  already referenced it as if it existed; additive, PayrollCalculator itself untouched).
- [x] 4. Calculator: `yearOne` derivation from firstSickDay vs the run period (first 52 weeks;
  `maxWeeks: 104` boundary) and the year-2 no-WML-floor switch per REQ-SICK-002. Derivation lives in
  `PayrollRunService::isYearOne()` (the service owns date/period math per the pure-calculator
  constraint — SickPayInput.yearOne is a precomputed bool, mirroring firstSickDayInPeriod).
- [x] 5. Calculator: the wachtdag deduction — `wd = (wachtdag AND firstSickDayInPeriod) ?
  round(L/workingDaysPerMonth) : 0`, once per case at its start; `payableGross = L − wd` per
  REQ-SICK-003
- [x] 6. Calculator: the CAO uplift percentage parameter (`p = loondoorbetalingPercentage`, e.g.
  90/100) and samengesteld/aangepast loon composition (`A` worked wage at 100% + continuation on
  `W−A`) per REQ-SICK-004
- [x] 7. Schema: `SickLeaveCase` (`lib/Settings/register.d/hr-verzuim.json`) gains optional
  `aangepastLoon` (adjusted wage from partial work, title+description) per REQ-SICK-004
- [x] 8. Schema: `Payslip` (`lib/Settings/register.d/hr-objects.json`) gains `sickLeaveCaseId`
  ($ref SickLeaveCase, nullable), `doorbetaaldLoon`, `wachtdagDeduction`, `sickPayReferenceWage`,
  `sickPayPercentage`, `sickPayMinimumWageFloor`, `sickPayYearOne` (description only, matching the
  Payslip file's own local convention — no other Payslip property carries `title` either;
  SickLeaveCase's `aangepastLoon` DOES carry `title`, matching hr-verzuim.json's convention).
  Register JSON validated (parses, both files); `npm run check:manifest` NOT run — this change never
  touches `src/manifest.json` (no task/spec requires a UI change; the existing generic `ps-data`
  widget auto-surfaces new Payslip fields). Hydra numbered gates 28/30/51/52 NOT run — that script
  lives outside this worktree/repo and was not in this change's GATES list; only JSON-parse + the
  PHPUnit schema-adjacent suite were exercised per REQ-SICK-005.
- [x] 9. Service: `PayrollRunService::generate()` — open (gemeld) SickLeaveCase lookup for the
  employee covering the period; when present build SickPayInput, `compute()`, and feed
  `payableGrossCents` as `CalculationInput.grossMonthlySalaryCents` (no open case → full-salary path
  unchanged) per REQ-SICK-005
- [x] 10. Service: `payslipPayload()` stamps the sick-pay fields (sickLeaveCaseId, doorbetaaldLoon,
  wachtdagDeduction, sickPayReferenceWage, sickPayPercentage, sickPayMinimumWageFloor,
  sickPayYearOne); null/absent on a non-sick slip; idempotency/orphan/roll-up inherited per
  REQ-SICK-005. Implemented as `generate()` merging a new `sickPayFields()` helper onto the payload
  (payslipPayload() itself untouched — minimal-touch).
- [x] 11. Rule: `nl-loondoorbetaling-floor` in `lib/Standards/rules/labour.json` (framework bw7-10,
  mandatory, machineCheckable, source BW 7:629 lid 1 jo. WML art. 12, `parameters`
  {statutoryPercentage: 70, year1MinimumWageFloor: true, workingDaysPerMonth: 21.75, maxWeeks: 104})
  per REQ-SICK-006. `RuleCatalogue::VERSION` bumped 2026-07.14 -> 2026-07.15.
- [x] 12. Check: `nl-loondoorbetaling-floor` predicate under `Payslip` in
  `lib/Standards/Checks/NlVerzuimChecks.php` — vacuous when `sickLeaveCaseId` null; else independent
  cents-exact recompute `doorbetaaldLoon ≥ max(round(sickPayReferenceWage×70/100), sickPayYearOne ?
  sickPayMinimumWageFloor : 0)` per REQ-SICK-006. Live-verified auto-discovered + reachable via
  `RuleEngine::evaluate('Payslip', ...)` in
  `NlVerzuimChecksTest::testLoondoorbetalingFloorFiresThroughTheRealRuleEngine()` (not just callable
  in isolation) — no orphaned capability.
- [x] 13. Fixture: `tests/fixtures/sick-pay-2026/anchor.json` — the D2 anchor (must byte-match the
  design.md worked example: payableGross 266000, floorApplied false) per REQ-SICK-007
- [x] 14. Tests: `tests/Unit/Payroll/PayrollSickPayCalculatorTest.php` — anchor + the four D2
  cross-check rows (floor-binding 229440, wachtdag 253770, CAO-100% 380000, aangepast-loon 296000) +
  the year-2 switch (210000) per REQ-SICK-007
- [x] 15. Tests: `tests/Unit/Service/PayrollRunServiceSickPayTest.php` (mocked ObjectService) — an
  open-case employee's payslip grossPay = doorbetaald loon (not full salary) + stamped fields; a
  second run for the same period without recalculate = no dup, no change (idempotent) per
  REQ-SICK-007. Also updated the pre-existing `PayrollRunServiceTest.php`'s `service()` builder for
  the new `SickPayCalculator` constructor argument (required — the constructor signature changed).
- [x] 16. Quality gates: `composer lint` green (0 syntax errors, php:8.3-cli), full PHPUnit suite
  green (436 tests, 1615 assertions, 0 failures/errors, php:8.3-cli); `npm run check:manifest` N/A —
  not run, `src/manifest.json` untouched (see task 8 note); SPDX + `@spec` present on every new/changed
  PHP method (targeting canonical `openspec/specs/sick-pay-calc/spec.md#REQ-SICK-*`, not the change
  dir, so the tags survive archiving); i18n: all new schema descriptions/titles and the new rule's
  `statement`/`source` are English, matching every sibling field in both files.

Acceptance criteria (plain reminders, not tasks):
- the chain is D2's exactly — non-worked base, `round(B×p/100)`, `L0=A+C`, `floor=max(70%, year1
  WML)`, wachtdag `round(L/D)` once at case start, `payableGross = L − wd`; every rounding half-up
- `lib/Payroll/SickPayCalculator.php` (+ its two value objects) has zero OCP/OCA imports (pure) —
  the service owns the SickLeaveCase read and all Nextcloud wiring
- `PayrollCalculator` is NOT modified — sick pay only substitutes the gross fed into it
- `nl-loondoorbetaling-floor` is ONE mandatory rule (the one-static-severity-per-rule engine
  constraint); a sub-floor payslip is a mandatory violation
- a non-sick employee's payslip is byte-identical to today (the full-salary path is untouched)

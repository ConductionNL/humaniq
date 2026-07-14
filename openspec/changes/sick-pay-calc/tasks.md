# Tasks — sick-pay-calc

> Consumes the merged `payroll-core-engine` (PayrollCalculator/TaxTables/PayrollRunService,
> nl-2026 tables) and `leave-verzuim-mvp` (SickLeaveCase, nl-loondoorbetaling-minimum,
> NlVerzuimChecks). Verify against HEAD, not this brief.

- [ ] 1. Value objects: `lib/Payroll/SickPayInput.php` (referenceWageCents, aangepastLoonCents,
  loondoorbetalingPercentage, yearOne, wachtdag, firstSickDayInPeriod, contractHoursPerWeek,
  fulltimeHoursPerWeek) + `lib/Payroll/SickPayResult.php` (doorbetaaldLoonCents,
  wachtdagDeductionCents, payableGrossCents, minimumWageFloorCents, floorApplied, appliedPercentage,
  yearOne, referenceWageCents) — immutable, zero NC deps (the CalculationInput/Result idiom) per
  REQ-SICK-001
- [ ] 2. Calculator: `lib/Payroll/SickPayCalculator.php::compute()` — the D2 chain (non-worked base,
  continuation `round(B×p/100)`, `L0 = A + C`), pure PHP, integer cents, half-up rounding, zero NC
  deps; anchor verified digit-for-digit against the D2 hand computation (payableGross 266000 =
  €2.660,00) per REQ-SICK-001
- [ ] 3. Calculator: the year-1 WML floor — `floor = max(round(W×70/100), yearOne ? min(W,M) : 0)`,
  `M` from `TaxTables.wml().referentiemaandloon` × part-time factor (design.md D3); `floorApplied`
  flag per REQ-SICK-002
- [ ] 4. Calculator: `yearOne` derivation from firstSickDay vs the run period (first 52 weeks;
  `maxWeeks: 104` boundary) and the year-2 no-WML-floor switch per REQ-SICK-002
- [ ] 5. Calculator: the wachtdag deduction — `wd = (wachtdag AND firstSickDayInPeriod) ?
  round(L/workingDaysPerMonth) : 0`, once per case at its start; `payableGross = L − wd` per
  REQ-SICK-003
- [ ] 6. Calculator: the CAO uplift percentage parameter (`p = loondoorbetalingPercentage`, e.g.
  90/100) and samengesteld/aangepast loon composition (`A` worked wage at 100% + continuation on
  `W−A`) per REQ-SICK-004
- [ ] 7. Schema: `SickLeaveCase` (`lib/Settings/register.d/hr-verzuim.json`) gains optional
  `aangepastLoon` (adjusted wage from partial work, title+description) per REQ-SICK-004
- [ ] 8. Schema: `Payslip` (`lib/Settings/register.d/hr-objects.json`) gains `sickLeaveCaseId`
  ($ref SickLeaveCase, nullable), `doorbetaaldLoon`, `wachtdagDeduction`, `sickPayReferenceWage`,
  `sickPayPercentage`, `sickPayMinimumWageFloor`, `sickPayYearOne` (all with title+description);
  register gates 28/30/51/52 + `npm run check:manifest` pass per REQ-SICK-005
- [ ] 9. Service: `PayrollRunService::generate()` — open (gemeld) SickLeaveCase lookup for the
  employee covering the period; when present build SickPayInput, `compute()`, and feed
  `payableGrossCents` as `CalculationInput.grossMonthlySalaryCents` (no open case → full-salary path
  unchanged) per REQ-SICK-005
- [ ] 10. Service: `payslipPayload()` stamps the sick-pay fields (sickLeaveCaseId, doorbetaaldLoon,
  wachtdagDeduction, sickPayReferenceWage, sickPayPercentage, sickPayMinimumWageFloor,
  sickPayYearOne); null/absent on a non-sick slip; idempotency/orphan/roll-up inherited per
  REQ-SICK-005
- [ ] 11. Rule: `nl-loondoorbetaling-floor` in `lib/Standards/rules/labour.json` (framework bw7-10,
  mandatory, machineCheckable, source BW 7:629 lid 1 jo. WML art. 12, `parameters`
  {statutoryPercentage: 70, year1MinimumWageFloor: true, workingDaysPerMonth: 21.75, maxWeeks: 104})
  per REQ-SICK-006
- [ ] 12. Check: `nl-loondoorbetaling-floor` predicate under `Payslip` in
  `lib/Standards/Checks/NlVerzuimChecks.php` — vacuous when `sickLeaveCaseId` null; else independent
  cents-exact recompute `doorbetaaldLoon ≥ max(round(sickPayReferenceWage×70/100), sickPayYearOne ?
  sickPayMinimumWageFloor : 0)` per REQ-SICK-006
- [ ] 13. Fixture: `tests/fixtures/sick-pay-2026/anchor.json` — the D2 anchor (must byte-match the
  design.md worked example: payableGross 266000, floorApplied false) per REQ-SICK-007
- [ ] 14. Tests: `tests/Unit/Payroll/PayrollSickPayCalculatorTest.php` — anchor + the four D2
  cross-check rows (floor-binding 229440, wachtdag 253770, CAO-100% 380000, aangepast-loon 296000) +
  the year-2 switch (210000) per REQ-SICK-007
- [ ] 15. Tests: `tests/Unit/Service/PayrollRunServiceSickPayTest.php` (mocked ObjectService) — an
  open-case employee's payslip grossPay = doorbetaald loon (not full salary) + stamped fields; a
  second run for the same period without recalculate = no dup, no change (idempotent) per
  REQ-SICK-007
- [ ] 16. Quality gates: `composer lint` green, full PHPUnit suite green (php:8.3-cli),
  `npm run check:manifest` PASS; SPDX + `@spec` on every new/changed PHP method (gate-16); i18n keys
  ENGLISH, Dutch only in schema labels/rule statements per convention

Acceptance criteria (plain reminders, not tasks):
- the chain is D2's exactly — non-worked base, `round(B×p/100)`, `L0=A+C`, `floor=max(70%, year1
  WML)`, wachtdag `round(L/D)` once at case start, `payableGross = L − wd`; every rounding half-up
- `lib/Payroll/SickPayCalculator.php` (+ its two value objects) has zero OCP/OCA imports (pure) —
  the service owns the SickLeaveCase read and all Nextcloud wiring
- `PayrollCalculator` is NOT modified — sick pay only substitutes the gross fed into it
- `nl-loondoorbetaling-floor` is ONE mandatory rule (the one-static-severity-per-rule engine
  constraint); a sub-floor payslip is a mandatory violation
- a non-sick employee's payslip is byte-identical to today (the full-salary path is untouched)

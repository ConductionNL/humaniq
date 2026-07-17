# Tasks — 30-procent-regeling

> Spec-only change (openspec-ff): no code has been written yet. Verify every referenced file
> against HEAD before implementing, not against this brief — `jurisdiction-packs` is merged, but
> line numbers/exact JSON shapes in `nl-2026.pack.json`/`PayrollRunService.php` may have shifted
> since 2026-07-17.

- [ ] 1. Table data: `lib/Standards/tables/nl-2026.json` — `parameters.dertigProcentRegeling`
  (`percent` 30, `maxDurationMonths` 60, `aftoppingsgrens` `{jaar: 262000, maand: 21833.33}`,
  `salarisnormAlgemeen` 48013, `salarisnormMasterOnder30` 36497), each leaf `{value, source,
  verified: true}` per design.md D3's citations; bump `RuleCatalogue::VERSION` per REQ-30P-001
- [ ] 2. Schema: `lib/Settings/register.d/hr-objects.json` `Employee` — add
  `thirtyPercentRulingStartDate`, `thirtyPercentRulingEndDate` (date, nullable),
  `thirtyPercentRulingReducedNormApplies` (boolean, default `false`); clarify the description of
  the 3 existing 30%-ruling fields per D5; version bump; every property titled + described
  (gate-28) per REQ-30P-002
- [ ] 3. Schema: `lib/Settings/register.d/hr-objects.json` `Payslip` — add
  `thirtyPercentRulingExemption` (nullable number); version bump per REQ-30P-003
- [ ] 4. `lib/Payroll/CalculationInput.php` — add 11th named constructor parameter
  `thirtyPercentRulingRate` (float, default `0.0`); update the class docblock per REQ-30P-003
- [ ] 5. `lib/Payroll/CalculationInputMapper.php` — `toPackInputs()`: map
  `thirtyPercentRulingRate` through per REQ-30P-003
- [ ] 6. Pack: `lib/Standards/packs/nl-2026.pack.json` — declare the `thirtyPercentRulingRate`
  input (`percent`, default `0`); add the `thirtyPercentExemption` binding (`cappedRate` over
  `@binding.tvl`/`@input.thirtyPercentRulingRate`/`@table.dertigProcentRegeling.aftoppingsgrens.
  maand:cents`) and the `belastbaarLoon` binding (`expr`, `max(0, tvl - exemption)`); repoint the
  `annualised` binding and the `vakantiegeld`/`zvw`/`awf`/`aof`/`wko`/`whk` steps' `base` from
  `@binding.tvl` to `@binding.belastbaarLoon`; leave `grossRef` as `@binding.tvl`; add this
  change's D4 anchor as a NEW `selfTest` vector; bump `packVersion` (design.md D2/D1) per
  REQ-30P-003 (verify: `dslVersion` and every `lib/Payroll/Dsl/Ops/*.php` file are untouched)
- [ ] 7. Service: `lib/Service/PayrollRunService.php` — `CalculationInput` construction:
  `thirtyPercentRulingRate: (($employee['thirtyPercentRulingGranted'] ?? false) === true) ?
  (float) ($employee['thirtyPercentRulingRate'] ?? 0.0) : 0.0`; NEW
  `thirtyPercentExemptionCentsFor(Employee, tables)` helper (the same `cappedRate` formula, in
  PHP, the `bijtelling` D3 precedent); `payslipPayload()`: stamp
  `thirtyPercentRulingExemption` (null when not applicable) per REQ-30P-003
- [ ] 8. Golden fixture: `tests/fixtures/payroll-2026/thirty-percent-ruling-anchor.json` — the
  design.md D4 hand-computed case (exemption €1.140,00, loonheffing €251,17, nettoPay €3.548,83),
  byte-matching every figure in D4 (also pinned digit-for-digit by a dedicated
  `PayrollCalculatorTest` method) per REQ-30P-003
- [ ] 9. Tests: `PayrollCalculatorTest` — add the thirty-percent-ruling-anchor fixture to the
  table-driven run; add an explicit no-ruling regression assertion (the pre-existing anchor is
  byte-identical with `thirtyPercentRulingRate` omitted/`0.0`); add the €25.000,00 high-earner
  WNT-cap scenario per REQ-30P-003
- [ ] 10. Tests: `BalancingInvariantTest` — extend to cover the thirty-percent-ruling-anchor
  fixture; the net equation must hold as `grossPay - loonheffing` with `grossPay` UNCHANGED
  (design.md D2 — this is the regression guard that would catch a future `grossRef` mistake) per
  REQ-30P-003
- [ ] 11. Tests: `PayrollRunServiceTest` — a granted-ruling employee produces a Payslip with
  `thirtyPercentRulingExemption` matching D4, `nettoPay` HIGHER than the same-gross non-ruling
  case (never lower); a non-granted employee's payslip is byte-identical to today per REQ-30P-003
- [ ] 12. Checks: `lib/Standards/Checks/NlPayrollChecks.php` — add `nl-30-regeling-looptijd-5jaar`
  (Employee), `nl-30-regeling-aftoppingsgrens-bedrag` (Payslip, re-derive + cents-compare via
  `RuleAuditService` context enrichment), `nl-30-regeling-salarisnorm` (Employee); `seedObjects()`
  unchanged (verify the existing seeded Employee still audits clean on all 5 30%-ruling checks)
  per REQ-30P-004
- [ ] 13. Context: `lib/Service/RuleAuditService.php` — ensure a Payslip-scoped 30%-ruling check
  can resolve the referenced Employee's `thirtyPercentRulingRate` (reuse or extend
  `employeesById`, the `cao.employeesById`/`payroll.runsById` precedent) per REQ-30P-004
- [ ] 14. Rule corpus: `lib/Standards/rules/payroll.json` — add the 3 new entries (domain `tax`,
  framework `nl-loonheffingen`, source `Wet LB 1964 art. 31a`, `machineCheckable: true`, severity
  `conditional`); fix `nl-30-regeling-aftoppingsgrens`'s placeholder `statement`/`sourceUrl` to
  cite the verified €262.000 WNT-norm per REQ-30P-004
- [ ] 15. Tests: NEW `tests/Unit/Standards/Checks/` cases (or extend the existing
  `NlPayrollChecks` test file) — all 8 spec.md scenarios for REQ-30P-004: term-exceeded,
  end-date-beyond-60-months, tampered cap amount, correctly-capped high earner, below general
  norm, below general but above reduced norm, non-granted vacuous per REQ-30P-004
- [ ] 16. Manifest: `src/manifest.json` — extend the Employee detail page's existing "Employment &
  compliance" widget field list with the 3 new fields (no new page/widget — the 30%-ruling group
  already exists there); `npm run check:manifest` passes
- [ ] 17. Quality gates: `composer lint`, full PHPUnit suite, `npm run check:manifest`,
  `npm run build` all green; confirm `lib/Payroll/PayrollCalculator.php`,
  `lib/Payroll/Dsl/PackInterpreter.php` and every `lib/Payroll/Dsl/Ops/*.php` file are
  byte-identical to HEAD (diff, not just "no PR comment touches them")

Acceptance criteria (plain reminders, not tasks):
- `PackInterpreter.php` and every `Dsl/Ops/*.php` file are NEVER edited by this change — the
  exemption lives entirely in `nl-2026.pack.json` pack data (2 new bindings, 7 repointed refs),
  the design.md D1 verdict
- `grossRef` stays `@binding.tvl`; `nettoPay` for a granted ruling is HIGHER than for the same
  gross without one, never lower or unchanged — the design.md D2 fix, and the single most
  important regression guard in this change
- `thirtyPercentRulingRate` defaults to `0.0` and every pre-existing golden fixture
  (anchor/aow-age/bracket-3/groen/min-wage/no-korting/part-time/bijtelling-anchor/dga-anchor)
  reproduces byte-for-byte unmodified
- the D4 anchor is hand-computed AND cross-checked against a faithful port of the real DSL ops
  BEFORE implementation (design.md) — the fixture must match it, not the other way around
- the stepped 30/20/10 rate schedule is NOT implemented (design.md D3 — it was reversed before
  taking effect); implementing it would be a regression against verified 2026 law, not a feature
- the netto-operation inverse solve, partial non-resident status, ET-regeling election,
  partial-year proration, multi-BV aggregation and the alert/intrekking/bewijspakket workflows are
  OUT of scope — named follow-ups, not silently dropped
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH; Dutch strings
  only in manifest labels/messages (existing convention)

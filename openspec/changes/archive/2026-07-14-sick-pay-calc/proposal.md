---
kind: code
---

# Sick-pay (loondoorbetaling bij ziekte) computation over the payroll engine

## Why

**Verified against HEAD 2026-07-14.** hrmq already models the *administrative* side of sickness and
already **checks** the sick-pay rate — but it does not yet **compute** a euro of it.

- `leave-verzuim-mvp` (PR #22) shipped the `SickLeaveCase` schema
  (`lib/Settings/register.d/hr-verzuim.json`): `firstSickDay`, `wachtdag`,
  `loondoorbetalingPercentage` (default 70), the gemeld↔hersteld lifecycle and the samengesteld
  ziektegeval relapse rule — plus the corpus rule `nl-loondoorbetaling-minimum` (BW 7:629 lid 1)
  and its `NlVerzuimChecks` predicate, which only assert that an open case *carries*
  `loondoorbetalingPercentage ≥ 70`. Nothing turns that percentage into a paid amount.
- `payroll-core-engine` (archived 2026-07-14) then shipped the actual calculator:
  `lib/Payroll/PayrollCalculator.php` (pure, integer-cents, table-driven gross-to-net over
  `lib/Payroll/TaxTables.php`), `CalculationInput`/`CalculationResult`, and
  `lib/Service/PayrollRunService.php` (idempotent draft runs, one Payslip per active NL employee).
  That service today feeds each employee's **full** `grossMonthlySalary` into the calculator — a
  sick employee's payslip therefore over-pays them the full wage, silently ignoring the
  loondoorbetaling regime the verzuim module already records.

The payroll engine is the producer this feature was waiting for. This change computes
loondoorbetaling bij ziekte: the statutory **70%** of the reference wage (BW 7:629 lid 1), floored
to the **statutory minimum wage in year 1** (first 52 weeks, WML art. 12 — the tables already carry
`parameters.wml.referentiemaandloon` = €2.294,40 and `hourly21Plus` = €14,71), minus the
**wachtdag(en)** where the case configures one, with the **CAO uplift** (`loondoorbetalingPercentage`
= 90/100 in many CAOs) as a parameter and **samengesteld/aangepast loon** (partial-work adjusted
wage) composed in — then feeds the resulting *doorbetaald loon* into `PayrollRunService` so a
sick employee's payslip reflects the continued wage, not the full salary. A new machine-checkable
corpus rule (`nl-loondoorbetaling-floor`) and its check provider make "sick pay below the 70%/WML
floor" a mandatory audit violation, closing the loop the verzuim module opened.

## What Changes

- **NEW `lib/Payroll/SickPayCalculator.php`** — pure, stateless, integer-cents, zero Nextcloud
  dependencies (mirrors `PayrollCalculator` exactly): `compute(SickPayInput $in, TaxTables $t):
  SickPayResult`. Implements the design.md D2 chain: non-worked base `B = W − aangepastLoon`,
  continuation `C = round(B × p/100)`, doorbetaald `L0 = aangepastLoon + C`, the statutory floor
  `max(round(W × 70/100), yearOne ? min(W, wmlMonthly) : 0)`, `L = max(L0, floor)`, the wachtdag
  deduction `round(L / workingDaysPerMonth)` once per case at its start, `payableGross = L − wd`.
  All money integer cents; `p`, the WML floor and `workingDaysPerMonth` are parameters/tables.
- **NEW `lib/Payroll/SickPayInput.php` / `lib/Payroll/SickPayResult.php`** — immutable value
  objects (the `CalculationInput`/`CalculationResult` idiom): input carries reference wage cents,
  `aangepastLoonCents`, `loondoorbetalingPercentage`, `yearOne`, `wachtdag`, `firstSickDayInPeriod`,
  `contractHoursPerWeek`/`fulltimeHoursPerWeek`; result carries `doorbetaaldLoonCents`,
  `wachtdagDeductionCents`, `payableGrossCents`, `minimumWageFloorCents`, `floorApplied`,
  `appliedPercentage`, `yearOne`, `referenceWageCents`.
- **`lib/Service/PayrollRunService.php`** — before building `CalculationInput`, look up an **open
  (gemeld) `SickLeaveCase`** for the employee covering the run period; when present, compute sick
  pay via `SickPayCalculator` (reference = `grossMonthlySalary`, WML floor from
  `tables.wml.referentiemaandloon` × part-time factor) and feed `payableGrossCents` as the
  calculator's `grossMonthlySalaryCents` — so loonheffing/net are computed on the doorbetaald loon —
  then stamp the sick-pay component fields onto the Payslip. No open case → the existing full-salary
  path is unchanged.
- **Schema deltas** — `Payslip` (`lib/Settings/register.d/hr-objects.json`) gains
  `sickLeaveCaseId` ($ref SickLeaveCase, nullable — marks a sick-pay slip), `doorbetaaldLoon`,
  `wachtdagDeduction`, `sickPayReferenceWage`, `sickPayPercentage`, `sickPayMinimumWageFloor`,
  `sickPayYearOne`; `SickLeaveCase` (`lib/Settings/register.d/hr-verzuim.json`) gains the optional
  `aangepastLoon` (the adjusted wage still earned from partial work — samengesteld/aangepast loon).
- **NEW corpus rule `nl-loondoorbetaling-floor`** (`lib/Standards/rules/labour.json`, framework
  `bw7-10`, mandatory, machineCheckable) with `parameters` `{statutoryPercentage: 70,
  year1MinimumWageFloor: true, workingDaysPerMonth: 21.75, maxWeeks: 104}`, plus the
  `nl-loondoorbetaling-floor` predicate in **`lib/Standards/Checks/NlVerzuimChecks.php`** on
  `Payslip`: vacuous when `sickLeaveCaseId` is null; else cents-exact `doorbetaaldLoon ≥
  max(round(sickPayReferenceWage × 70/100), sickPayYearOne ? sickPayMinimumWageFloor : 0)` —
  independent recomputation of the floor from the payslip's own recorded reference + WML figures.
- **GOLDEN FIXTURE** — `tests/fixtures/sick-pay-2026/anchor.json` (input → expected, byte-matching
  the design.md D2 worked example) + `PayrollSickPayCalculatorTest` (anchor + floor-binding,
  wachtdag, CAO-100%, aangepast-loon, year-2 cases) and a `PayrollRunServiceSickPayTest`
  (idempotent second run = no dup/no change, sick employee's payslip carries the doorbetaald loon).

### Non-goals (named fast-follows and exclusions)

- **Day-level intra-month proration beyond the composite `aangepastLoon`** — the MVP treats a case
  open across the run period at the period rate and composes worked vs sick wage through the case's
  `aangepastLoon` field; hour/day timesheet-driven proration is a named fast-follow.
- **Second-year percentage tapering / no-loondoorbetaling after 104 weeks / WGA/IVA hand-off,
  vangnet (ZW) reimbursement, no-risk polis, ziekengeld** — out of scope; `maxWeeks: 104` is
  carried as a parameter, applied only as the year-1 vs year-2 floor switch.
- **Bijzonder tarief on doorbetaald loon, CAO-specific staffels beyond a flat percentage** — the
  percentage is the flat `loondoorbetalingPercentage`; CAO staffel tables are a fast-follow.
- **Write-time guards / lifecycle on Payslip** — enforcement stays the read-only audit path
  (`occ hrmq:rules:audit`); guard wiring remains `hrmq-rule-compliance-enforcement`'s scope.

## Capabilities

### New Capabilities

- `sick-pay-calc`: the pure integer-cents `SickPayCalculator` (70% + year-1 WML floor + wachtdag +
  CAO uplift + samengesteld/aangepast loon), its `PayrollRunService` integration so a sick
  employee's payslip reflects doorbetaald loon, the `nl-loondoorbetaling-floor` corpus rule +
  `NlVerzuimChecks` below-floor predicate, and the golden fixture + idempotency tests.

### Modified Capabilities

<!-- none — payroll-core-engine and leave-verzuim-mvp are consumed, not modified: PayrollCalculator
     stays untouched (sick pay is a pre-processing step on its gross input); the SickLeaveCase
     schema gains one optional field; NlVerzuimChecks gains one predicate. -->

## Impact

- `lib/Payroll/SickPayCalculator.php`, `lib/Payroll/SickPayInput.php`,
  `lib/Payroll/SickPayResult.php` — NEW (pure PHP, zero OCP/OCA imports → unit-testable without
  stubs, the `lib/Payroll/` convention).
- `lib/Service/PayrollRunService.php` — open-case lookup + sick-pay pre-processing + payslip
  stamping (the `generate()`/`payslipPayload()` methods).
- `lib/Settings/register.d/hr-objects.json` (Payslip +7 fields),
  `lib/Settings/register.d/hr-verzuim.json` (SickLeaveCase +`aangepastLoon`); `npm run
  check:manifest` and the register gates (28/30/51/52) pass.
- `lib/Standards/rules/labour.json` — +1 rule `nl-loondoorbetaling-floor`;
  `lib/Standards/Checks/NlVerzuimChecks.php` — +1 Payslip predicate.
- `tests/fixtures/sick-pay-2026/anchor.json`,
  `tests/Unit/Payroll/PayrollSickPayCalculatorTest.php`,
  `tests/Unit/Service/PayrollRunServiceSickPayTest.php` — NEW.
- Consumes (already merged, no `depends_on`): `payroll-core-engine`
  (`PayrollCalculator`/`TaxTables`/`PayrollRunService`, `nl-2026.json` tables) and
  `leave-verzuim-mvp` (`SickLeaveCase`, `nl-loondoorbetaling-minimum`, `NlVerzuimChecks`).

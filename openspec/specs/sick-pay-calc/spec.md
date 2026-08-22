---
capability: sick-pay-calc
status: done
built_by: openspec/changes/archive/2026-07-14-sick-pay-calc
---

# sick-pay-calc Specification

**Status**: done
**Scope**: humaniq (consumes the merged `payroll-core-engine` and `leave-verzuim-mvp`, no `depends_on`)
**OpenSpec changes**:
- [sick-pay-calc](../../changes/archive/2026-07-14-sick-pay-calc/) _(archived 2026-07-15)_ — pure
  integer-cents `SickPayCalculator` (statutory 70%, year-1 minimum-wage floor, wachtdag deduction,
  CAO uplift percentage, samengesteld/aangepast loon), its `PayrollRunService` integration so a sick
  employee's payslip reflects doorbetaald loon, the `nl-loondoorbetaling-floor` corpus rule + check,
  and a hand-computed golden fixture with idempotency (kind: code)

## Purpose

humaniq already modelled the *administrative* side of sickness (`leave-verzuim-mvp`'s `SickLeaveCase`
+ the `nl-loondoorbetaling-minimum` check) and already had the actual gross-to-net engine
(`payroll-core-engine`'s `PayrollCalculator`/`PayrollRunService`) — but the two were never
connected: a sick employee's payslip fed the engine the full `grossMonthlySalary`, silently
ignoring the loondoorbetaling regime the verzuim module recorded. This change computes
loondoorbetaling bij ziekte (BW 7:629 lid 1): 70% of the reference wage, floored to the statutory
minimum wage in year 1 (WML art. 12), minus the wachtdag(en) where configured, with the CAO uplift
percentage and samengesteld/aangepast loon composed in — then feeds the resulting doorbetaald loon
into `PayrollRunService` so a sick employee's payslip reflects the continued wage, not the full
salary. A new machine-checkable corpus rule (`nl-loondoorbetaling-floor`) makes "sick pay below the
70%/WML floor" a mandatory audit violation, closing the loop the verzuim module opened.

## Requirements

### Requirement: A pure, stateless calculator SHALL compute loondoorbetaling as 70% of the reference wage in integer cents (REQ-SICK-001)

`lib/Payroll/SickPayCalculator.php::compute(SickPayInput, TaxTables): SickPayResult` SHALL compute
the design.md D2 chain in integer cents: non-worked base `B = W − aangepastLoon`, continuation
`C = round(B × p / 100)` (`p` = the case's `loondoorbetalingPercentage`), doorbetaald
`L0 = aangepastLoon + C`, then the floor and wachtdag steps (REQ-SICK-002/-003). The calculator and
its value objects (`lib/Payroll/SickPayInput.php`, `lib/Payroll/SickPayResult.php`) SHALL have zero
Nextcloud dependencies (no container, no clock, no IO beyond the passed `TaxTables`), and all
monetary arithmetic SHALL be integer cents with half-up rounding at the percentage-multiply and
wachtdag-divide steps — never accumulated floats. `PayrollCalculator` SHALL NOT be modified.

#### Scenario: The anchor case reproduces the hand-computed doorbetaald loon
- **GIVEN** a fully-sick employee with reference wage €3.800,00, `aangepastLoon` €0,00,
  `loondoorbetalingPercentage` 70, year 1, full-time, no wachtdag, and the `nl-2026` tables
- **WHEN** `compute()` runs
- **THEN** the continuation is €2.660,00, `doorbetaaldLoon` is €2.660,00, `floorApplied` is false and
  `payableGross` is €2.660,00 (`266000` cents — design.md D2 worked example)

#### Scenario: The reference wage is untouched when no sick case applies
- **GIVEN** an employee with no open `SickLeaveCase` covering the run period
- **WHEN** `PayrollRunService` generates their payslip
- **THEN** `SickPayCalculator` is not invoked, the full `grossMonthlySalary` is fed to
  `PayrollCalculator`, and the payslip is byte-identical to the pre-change full-salary result

### Requirement: The doorbetaald loon SHALL be floored to the statutory minimum wage in year 1 (REQ-SICK-002)

The calculator SHALL apply `floor = max( round(W × 70/100), (yearOne ? min(W, M) : 0) )`, where `M`
is the WML monthly floor derived from `TaxTables.wml().referentiemaandloon` (the verified full-time
figure) scaled by the part-time factor `contractHoursPerWeek / fulltimeHoursPerWeek`, and `yearOne`
is true when the run period falls within the first 52 weeks of `firstSickDay`. It SHALL set
`doorbetaaldLoon = max(L0, floor)` and `floorApplied = (floor > L0)`. Past 52 weeks (year 2) the WML
term SHALL drop out, leaving the bare 70%-of-wage floor.

#### Scenario: Year-1 sub-WML 70% is raised to the minimum wage
- **GIVEN** a fully-sick full-time employee, reference wage €3.000,00, 70%, year 1
  (`M` = €2.294,40)
- **WHEN** `compute()` runs
- **THEN** the raw 70% is €2.100,00, the floor raises `doorbetaaldLoon` to €2.294,40 (`229440`
  cents), `floorApplied` is true and `payableGross` is €2.294,40

#### Scenario: Year-2 sickness gets no minimum-wage floor
- **GIVEN** the same €3.000,00 employee but the run period is past 52 weeks of `firstSickDay`
  (year 2)
- **WHEN** `compute()` runs
- **THEN** the WML floor no longer applies, `doorbetaaldLoon` is the bare 70% €2.100,00
  (`210000` cents) and `floorApplied` is false

### Requirement: A wachtdag SHALL be deducted once per case at its start (REQ-SICK-003)

The calculator SHALL deduct one wachtdag valued at the doorbetaald daily rate when the
`SickLeaveCase` has `wachtdag: true` AND `firstSickDayInPeriod` is true (the case's `firstSickDay`
falls in the run period): `wd = round(doorbetaaldLoon / workingDaysPerMonth)` (`workingDaysPerMonth`
= the `nl-loondoorbetaling-floor` rule parameter, `21.75`); otherwise `wd = 0`. `payableGross` SHALL
equal `doorbetaaldLoon − wd`. In continuation months (the first sick day not in this period) no
wachtdag SHALL be deducted.

#### Scenario: The waiting day is deducted in the starting month
- **GIVEN** the anchor employee (`doorbetaaldLoon` €2.660,00) with `wachtdag: true` and
  `firstSickDay` inside the run period
- **WHEN** `compute()` runs
- **THEN** `wachtdagDeduction` is €122,30 (`round(266000 / 21.75) = 12230` cents) and `payableGross`
  is €2.537,70 (`253770` cents)

#### Scenario: No wachtdag deduction in a continuation month
- **GIVEN** the same employee whose `firstSickDay` was in an earlier period (case still open)
- **WHEN** `compute()` runs for the later period
- **THEN** `wachtdagDeduction` is €0,00 and `payableGross` equals `doorbetaaldLoon`

### Requirement: The CAO uplift percentage and samengesteld/aangepast loon SHALL be parameters (REQ-SICK-004)

The applied percentage SHALL be the `SickLeaveCase.loondoorbetalingPercentage` (default 70, `≥ 70`
by `nl-loondoorbetaling-minimum`; many CAOs set 90 or 100). The optional `SickLeaveCase.aangepastLoon`
(the wage still earned from partial work) SHALL be composed as `doorbetaaldLoon = aangepastLoon +
round((W − aangepastLoon) × p/100)` before the floor — the worked wage at 100% plus continuation on
the non-worked remainder (samengesteld/aangepast loon). `aangepastLoon` defaults to €0,00
(fully sick).

#### Scenario: A 100% CAO pays the full wage
- **GIVEN** the anchor employee with `loondoorbetalingPercentage` 100
- **WHEN** `compute()` runs
- **THEN** the continuation equals the full wage, `doorbetaaldLoon` and `payableGross` are €3.800,00
  (`380000` cents), floor non-binding

#### Scenario: Adjusted wage composes worked and sick pay
- **GIVEN** the anchor employee, 70%, with `aangepastLoon` €1.000,00 from partial work
- **WHEN** `compute()` runs
- **THEN** the continuation is €1.960,00 (`round(280000 × 0,70)`), `doorbetaaldLoon` is €2.960,00
  (`296000` cents = €1.000,00 + €1.960,00) and `payableGross` is €2.960,00

### Requirement: A sick employee's payslip SHALL reflect doorbetaald loon, not the full salary (REQ-SICK-005)

`PayrollRunService::generate()` SHALL, for each active employee, look up an open (gemeld)
`SickLeaveCase` covering the run period; when present it SHALL build `SickPayInput` from the case +
employee (reference = `grossMonthlySalary`, `aangepastLoon`, `loondoorbetalingPercentage`,
`yearOne`, WML floor `M`, `wachtdag`, `firstSickDayInPeriod`), call `compute()`, and feed
`payableGrossCents` as the `CalculationInput.grossMonthlySalaryCents` — so loonheffing and net are
computed on the doorbetaald loon. When absent the full-salary path SHALL be unchanged. The generated
Payslip SHALL be stamped with `sickLeaveCaseId`, `doorbetaaldLoon`, `wachtdagDeduction`,
`sickPayReferenceWage`, `sickPayPercentage`, `sickPayMinimumWageFloor` and `sickPayYearOne`
(null/absent on a non-sick slip). The `Payslip` schema (`hr-objects.json`) SHALL add these fields and
`SickLeaveCase` (`hr-verzuim.json`) SHALL add optional `aangepastLoon`; register gates 28/30/51/52
and `npm run check:manifest` MUST pass.

#### Scenario: The sick payslip carries the continued wage, not the salary
- **GIVEN** an open `SickLeaveCase` (70%, year 1, no wachtdag) for a €3.800,00 full-time employee
- **WHEN** `humaniq:payroll:run --period 2026-06` generates the run
- **THEN** the employee's Payslip `grossPay` is €2.660,00 (the doorbetaald loon, not €3.800,00),
  `sickLeaveCaseId` references the case, `doorbetaaldLoon` is €2.660,00 and loonheffing/net are
  computed on €2.660,00

### Requirement: A machine-checkable rule SHALL flag doorbetaald loon below the 70%/WML floor (REQ-SICK-006)

`lib/Standards/rules/labour.json` SHALL add `nl-loondoorbetaling-floor` (domain labour, jurisdiction
NL, framework bw7-10, `severity: mandatory`, `machineCheckable: true`, source BW 7:629 lid 1 jo. WML
art. 12, `parameters: {statutoryPercentage: 70, year1MinimumWageFloor: true, workingDaysPerMonth:
21.75, maxWeeks: 104}`). `lib/Standards/Checks/NlVerzuimChecks.php` SHALL register its predicate
under `Payslip`: vacuous when `sickLeaveCaseId` is null; else it SHALL **independently recompute**
`floor = max( round(sickPayReferenceWage × statutoryPercentage/100), (sickPayYearOne ?
sickPayMinimumWageFloor : 0) )` from the payslip's own recorded fields + the corpus parameter and
assert cents-exact `doorbetaaldLoon ≥ floor`. Because `RuleEngine` takes a Violation's severity from
the catalogue rule (never the call site) and the severity enum has no `advisory` level, the rule
SHALL emit exactly one **mandatory** violation for any sub-floor payslip (the one-static-severity-per-rule
engine constraint).

#### Scenario: A sub-floor payslip is a mandatory violation
- **GIVEN** a generated sick-pay Payslip whose `doorbetaaldLoon` was hand-edited to €2.000,00 while
  `sickPayReferenceWage` €3.800,00 (70% floor €2.660,00) still stands
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** a mandatory `nl-loondoorbetaling-floor` violation is reported for that payslip

#### Scenario: A correctly-floored payslip and a non-sick payslip both pass
- **GIVEN** a freshly generated sick-pay Payslip at €2.294,40 (year-1 floor binding) and a normal
  payslip with null `sickLeaveCaseId`
- **WHEN** the audit runs
- **THEN** neither reports an `nl-loondoorbetaling-floor` violation (the first meets its floor, the
  second is out of scope)

### Requirement: A hand-computed golden fixture SHALL pin the calculator and idempotency SHALL be asserted (REQ-SICK-007)

`tests/fixtures/sick-pay-2026/anchor.json` SHALL carry the design.md D2 anchor (input → expected)
byte-matching the hand computation (`payableGross` `266000`, `floorApplied` false).
`tests/Unit/Payroll/PayrollSickPayCalculatorTest.php` SHALL run the anchor plus the four D2
cross-check rows — floor-binding (`229440`), wachtdag (`253770`), CAO-100% (`380000`), aangepast-loon
(`296000`) — and the year-2 switch (`210000`). `tests/Unit/Service/PayrollRunServiceSickPayTest.php`
(mocked ObjectService) SHALL assert that an open-case employee's payslip `grossPay` equals the
doorbetaald loon (not the full salary) with the stamped fields, and that a second `runFor()` for the
same (period, administrationId) without `--recalculate` yields no second run and no changed payslip
(idempotent).

#### Scenario: The anchor fixture reproduces its expected figures exactly
- **WHEN** `composer test` (or `vendor/bin/phpunit`) runs `PayrollSickPayCalculatorTest`
- **THEN** the anchor fixture reproduces `doorbetaaldLoon` €2.660,00, `wachtdagDeduction` €0,00,
  `floorApplied` false and `payableGross` €2.660,00, and every cross-check row matches its
  design.md value

#### Scenario: Re-running a sick payroll is idempotent
- **GIVEN** a draft run generated for (2026-06, ADM-001) containing an open-case employee's
  doorbetaald-loon payslip
- **WHEN** `humaniq:payroll:run --period 2026-06` runs again without `--recalculate`
- **THEN** no second PayrollRun and no duplicate Payslip exist, and the existing sick-pay payslip is
  unchanged

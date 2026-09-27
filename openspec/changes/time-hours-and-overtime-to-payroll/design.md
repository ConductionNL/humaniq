# Design: pay approved hours and overtime through the payroll run

## Context

Read at `development` af702f78.

- `lib/Service/PayrollRunService.php`: `generate()` (line 371) loops over every `Employee`,
  finds the covering contract, and at line 439 skips an employee whose `grossMonthlySalary` is
  missing or zero with `no-monthly-salary (hourly path: fast-follow)`. Gross adjustments that
  are engine inputs happen before `new CalculationInput(` (line 492): the sick-pay substitution
  and the vehicle bijtelling, which is added to `grossMonthlySalaryCents`. `payslipPayload()`
  (line 660) sets `hoursWorked` to the contracted monthly hours, `hoursPerWeek x 52 / 12`.
- `EmploymentContract` (`lib/Settings/register.d/hr-objects.json:59`): `hoursPerWeek`
  (line 78), `hourlyWage` (line 79), `cao` (line 80), `overtimeMultiplier` (line 85, described
  as the United States rate for hours over 40 a week). `lib/Settings/register.d/hr-cost-rate.json:158`
  adds `overtimeToeslagPercentages` and `overtimeTermsOverrideReason` to the contract.
- `lib/Service/EmploymentTermsResolver.php`: `resolveOvertimeToeslag()` (line 115) resolves
  the contract override first and the CAO second, and returns null for an unconfirmed CAO;
  `overtimeAdditionFor(contract, category)` (line 248) answers the surcharge for one category
  (`doordeweeks`, `zaterdag`, `zondag`, `feestdag`). `git grep EmploymentTermsResolver lib/`
  finds no caller outside the class itself; only `tests/Unit/Service/EmploymentTermsResolverTest.php`
  calls it.
- `lib/Standards/CaoRegistry.php:223` `overtimeToeslagPercentages()`; the CAO overtime leaf
  also carries `compensationPreference` (`lib/Standards/cao/SCHEMA.md`, `overtime`).
- `Timesheet` (`lib/Settings/register.d/hr-timesheet.json:136`): monthly `period` (line 147),
  `hours` (line 230), `entryCount`, `status` with `submit`, `approve` and `reject` guarded by
  `NoSelfApprovalGuard` (lines 176 to 205), `approvedAt`. `TimeEntry` (line 5): `date`,
  `startedAt`, `endedAt`, `hours`, `timesheetId`, `costCenter`. No overtime field anywhere.
- `lib/Service/TimesheetAggregationService.php:138` `computeAggregates()` sums entry hours
  onto the timesheet.
- `lib/Service/WorkingCalendarReader.php:111` `nonWorkingDates(from, to)` reads the
  organisation's public holidays from OpenRegister and returns null when it cannot.
- `LeaveBalance.leaveType` (`lib/Settings/register.d/hr-leave.json:176`) is an enum of
  `holiday`, `sick`, `unpaid`, `special`, `care`, `parental`. No time-off-in-lieu type.
- Seed: `employee-visser` has no monthly salary and a BBL contract of 32 hours at 16.00 an
  hour (`lib/Settings/register.d/hr-seed.json`); she is skipped by every run today.

## Goals / Non-Goals

**Goals**

- Pay approved hours for hourly-paid employees, each timesheet exactly once.
- Register overtime on the timesheet and settle it in pay or in time.
- Use the surcharge terms that already exist, and show when they do not resolve.

**Non-Goals**

- Automatic overtime detection from clocks or patterns.
- Irregular-hours premiums (ORT) and other CAO components.
- Weekly or four-weekly periods.

## Decisions

### D1. Hours and overtime are a pre-calculation gross fold

`HoursPayService::grossFor(employee, contract, period, runId)` returns the hourly gross, the
overtime gross and the timesheets it used. `generate()` adds both to `grossMonthlySalaryCents`
before building `CalculationInput`, exactly where the bijtelling fold sits, so the calculator
sees the full period wage and stays untouched. For an employee without a monthly salary the
hourly gross replaces the skip; when the contract has no `hourlyWage` either, the skip stays
with the reason `no-salary-and-no-hourly-wage`.

Alternative considered: a post-tax fold like `leaveBuySell`. Rejected: worked hours are wage
and must be taxed and insured in the period, so they belong in the engine's gross.

### D2. Unpaid approved timesheets, stamped with the run that pays them

The service selects the employee's `approved` timesheets with `period` on or before the run's
period and either no `payrollRunId` or this run's id. When the run is saved, each used
timesheet gets `payrollRunId` and `paidInPeriod`. A recalculation of the same draft run
re-selects the same set. A timesheet that is reopened after being stamped by a draft run is
unstamped on the next recalculation. A timesheet stamped by a non-draft run is never paid
again.

Alternative considered: pay the timesheet whose period equals the run's period. Rejected: a
timesheet approved a day after the run closes would never be paid.

### D3. Overtime is registered on the entry and approved with the timesheet

`TimeEntry` gains `overtime` (boolean) and `overtimeCompensation` (`pay`, `time`, or null for
the default). `computeAggregates()` adds `overtimeHours`. The manager approves the timesheet
as today; no new approval step. The default compensation is the resolved terms'
`compensationPreference`: `tijd-voor-tijd` means `time`, anything else means `pay`.

Alternative considered: a separate `OvertimeRequest` object with its own approval. Rejected:
overtime is hours worked; a second approval of the same hours is duplicate work for the
manager.

### D4. The surcharge comes from the existing resolver, per day category

For each overtime entry the category is `feestdag` when the entry's date is in
`nonWorkingDates()`, else `zaterdag` or `zondag` by weekday, else `doordeweeks`.
`overtimeAdditionFor(contract, category)` gives the percentage. Pay is
`hours x hourlyRate x (100 + percentage) / 100`, rounded to the cent. The hourly rate is the
contract's `hourlyWage` for an hourly employee, and `grossMonthlySalary / (hoursPerWeek x 52 / 12)`
for a salaried one. When the resolver returns null, the percentage is 0, the hours are still
paid or credited, and `Payslip.overtimeSurchargeUnresolved` is true.

Alternative considered: `EmploymentContract.overtimeMultiplier`. Rejected: its own description
limits it to the United States rule, and a single multiplier cannot tell a Sunday from a
weekday.

### D5. Time off in lieu is a leave balance

A new leave type `compensation` is added to `LeaveBalance.leaveType` and seeded in the
`LeaveType` catalogue. When a run that settles time-off overtime is approved,
`OvertimeCreditService` adds `hours x (100 + percentage) / 100` to the employee's
`compensation` balance for the year, once per timesheet, keyed on the timesheet id.

Alternative considered: credit the balance at timesheet approval. Rejected: the surcharge and
the rate are resolved by the run; crediting earlier would use different terms than pay does.

Crediting on run approval needs a signal. Approval is a plain status edit today, and the open
change `payroll-run-as-a-flow` adds an approve step that writes the same field. Both are object
updates, so a new `PayrollRunApprovedListener` on OpenRegister's `ObjectUpdatedEvent` (the
`TimesheetApprovalListener` precedent) calls `OvertimeCreditService::creditForRun(runId)` when a
run's status crosses from `draft` to `approved`. The credit is idempotent per timesheet, so a
replayed event credits nothing twice.

### D6. The payslip says what it paid

`Payslip` gains `hoursPaid`, `hourlyPay`, `overtimeHours`, `overtimePay`,
`overtimeSurchargeUnresolved` and `timesheetIds`. For an hourly employee `hoursWorked` becomes
the hours paid rather than the contracted estimate. The engine input snapshot already records
the gross the calculator received.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| overtime hours on the timesheet | imperative, the existing aggregation listener | the aggregate is already computed there |
| timesheet approval | declarative lifecycle, unchanged | no new state |
| hours and overtime gross | imperative `HoursPayService` inside the run | engine input across schemas |
| time-off credit | imperative `OvertimeCreditService` | a guarded, idempotent balance write |
| payslip and timesheet fields on pages | declarative manifest | data widgets already render every field |

## Seed data

- `employee-visser` gains an approved timesheet for 2026-05 with 128 hours, so a 2026-05 run
  pays her 128 x 16.00 = 2048.00 gross instead of skipping her.
- `employee-jansen` gains one approved Saturday overtime entry of 4 hours with
  `overtimeCompensation: pay` in 2026-05.

## Risks / Trade-offs

- [A timesheet stamped by a draft run that is then deleted] -> the stamp names a run that no
  longer exists; the selection treats a stamp pointing at a missing run as unpaid.
- [Surcharge unknown for most bundled CAOs, which are placeholders] -> the flag on the payslip
  makes it visible, and the run check can list it.
- [Hourly employees with no approved timesheet] -> they are skipped with the reason
  `no-approved-hours`, never paid zero silently.

## Open Questions

- Some CAOs credit time off one for one and pay the surcharge in money. This design credits at
  the surcharge factor. Should the split become a CAO leaf?

# Design: the unemployment premium for apprentices, young part-timers and the review

## Context

Read at `development` after #509.

- `PayrollRunService::awfTariffFor()` (`lib/Service/PayrollRunService.php:1611`): the contract's
  `awfTariff` when set, otherwise `low` for `type: permanent` with `writtenContract: true`, else
  `high`; passed into the calculation (:498). `RetroAdjustmentService` has its own copy (:508).
- The pack (`lib/Standards/packs/nl-2026.pack.json:284`) derives `awfRate` by matching the tariff
  to `werknemersverzekeringen.awf.laag` (2.74) or `.hoog` (7.74) from
  `lib/Standards/tables/nl-2026.json:103`.
- Audit: `nl-awf-laag-hoog-tarief` (`lib/Standards/rules/payroll.json:42`), predicate
  `NlPayrollChecks::expectedAwfTariff()` (`lib/Standards/Checks/NlPayrollChecks.php:383`), same
  permanent-and-written rule.
- `EmploymentContract` (`hr-objects.json`): `type` includes `bbl`; `hoursPerWeek`,
  `startDate`, `endDate`, `awfTariff`, `writtenContract`. `Employee.dateOfBirth`.
  `Payslip.hoursWorked`, `werknemersverzekeringen`, `engineInputSnapshot`.
- `PayrollAdjustment` (`lib/Settings/register.d/hr-retro.json`) carries a recomputed period's
  deltas, including `deltaWerknemersverzekeringen`, settled in a current run
  (`retro-adjustments`); `correctionType` is free text.

## Goals / Non-Goals

**Goals**

- The premium follows the Wab rule for BBL and young part-timers.
- A premium that turned out wrong is corrected once, in the run where the review applies, with
  the recomputation on record.

**Non-Goals**

- Changing the employee's net pay. The Awf is an employer charge only.

## Decisions

### D1. One resolver

`AwfTariffResolver::for(contract, employee, period, paidHours)` replaces both private copies and
the audit helper: `low` when the contract's explicit `awfTariff` says so; else `low` for
permanent and written, for `bbl`, and for an employee under 21 on the first day of the period
whose paid hours average twelve a week or less; else `high`. The audit rule's statement is
widened to the same text.

### D2. Early ending

`AwfReviewService::reviewEarlyEnd(contract)` runs when a contract that was charged low gets an
`endDate` within two calendar months of its `startDate`: for each period of the contract it
recomputes the employer charges with `high` and writes a `PayrollAdjustment` (`correctionType:
awf-herziening`) whose only non-zero delta is `deltaWerknemersverzekeringen`, settled in the
next run.

### D3. Extra hours

At the run for the last period of a year, the service sums `hoursWorked` per low-premium contract
under 35 hours a week and compares with `hoursPerWeek` times the weeks the contract ran; above
130 percent it recomputes the year's periods with `high` and writes the adjustments as in D2.
`nl-awf-herziening-uren-signaal` (recommended) flags, in any period, a contract whose year-to-date
paid hours already exceed that line.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| which tariff applies | imperative resolver feeding the pack's declarative `awfRate` match | a rule over contract, age and paid hours |
| review settlements | imperative service writing `PayrollAdjustment` | recomputation of past periods, the retro precedent |
| early warning | corpus rule | the audit family |

## Seed data

- A BBL contract and an employee aged 19 on 10 hours a week, both now charged low.
- A part-timer on 24 hours paid 34 hours a week on average, flagged by the signal and reviewed at
  December.

## Risks / Trade-offs

- [Paid hours missing on hand-entered payslips] → the young-worker test falls back to contract
  hours and says so on the payslip snapshot.

## Open Questions

- None.

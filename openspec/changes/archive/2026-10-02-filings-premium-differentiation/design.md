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

At the first run of the next year, the service applies the Handboek's two calculations
(paragraaf 7.2.3): the contracted hours of the low periods (hours a week x 13/3 a month, part
months by calendar days / 7) divided by the weeks employed, rounded up, must be 30 or less; and
the paid hours (`hoursWorked` plus `overtimeHours`) must run more than 30 percent above the
contracted hours of every contract, rounded down. Then it recomputes the year's sealed low
periods with `high` and writes the adjustments as in D2.
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

## Build notes (2026-10-02)

The thresholds were taken from the Handboek Loonheffingen 2026, versie maart 2026
(https://download.belastingdienst.nl/belastingdienst/docs/handboek-loonheffingen-lh0221t61fd.pdf),
paragraaf 7.2 (p. 122-125), 7.2.2 and 7.2.3 (p. 126-131). Three points differ from the design as
first written:

- **30 hours, not 35.** From 2025 the extra-hours review applies at an average of 30 contracted
  hours a week or less ("Tot en met 2024 was herzien niet van toepassing bij gemiddeld 35 uur of
  meer per week. Vanaf 2025 is dat gemiddeld meer dan 30 uur per week"). The 130 percent line is
  "more than 30 percent, rounded down to whole percent", so 30,9 percent is not reviewed.
- **After the year, not in December.** The December payslip is not sealed while the December run
  calculates, and the Handboek says the calculation can usually only be made after the year. The
  review runs at the first run of the next year and settles there, one adjustment per original
  period (the losse correcties route); the signal rule covers the year itself.
- **The exceptions are never reviewed, and BBL needs a signed contract without an uitzendbeding.**
  Let op 3 and 9 exclude the BBL and under-21 exceptions from the review; paragraaf 7.2 makes the
  BBL low rate depend on a signed praktijkovereenkomst and, from 2023, no uitzendbeding. The
  resolver reads `bpvOvereenkomstOndertekend` and `uitzendbedingVanToepassing`.

An early end is also charged high in the run of its last period directly (`AwfTariffResolver`
basis `early-end`), so only the sealed earlier periods need an adjustment. A rate set explicitly on
the contract wins, but the under-21 exception still applies, because the Handboek says the low
rate is "always" applied there. Each payslip records `awfTariff` and `awfTariffBasis`; with the
review wired it also records the year-to-date figures `nl-awf-herziening-uren-signaal` reads.
A four-week period uses the 48-hour norm and hours a week x 4; part four-week periods are not
prorated (humaniq runs monthly).

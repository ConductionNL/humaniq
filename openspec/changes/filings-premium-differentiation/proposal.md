---
kind: code
---

# The unemployment premium: apprentices, young part-timers and the review

## Why

humaniq already charges the low unemployment premium (Awf) for a permanent written contract and
the high premium for every other contract: `EmploymentContract.awfTariff`, resolved by
`PayrollRunService::awfTariffFor()` and chosen in the pack's `awfRate` binding, audited by
`nl-awf-laag-hoog-tarief` (`payroll-core-engine` REQ, `openspec/specs/payroll-core-engine/spec.md:41`).
The matrix read this row as "no" because its search looked for words the pack does not use
(`laag`, `hoog`); this pass corrects it to partial.

What is missing is the rest of the Wab rule the payroll suites apply. Two groups get the low
premium whatever their contract: apprentices in the beroepsbegeleidende leerweg (BBL), and
employees under 21 who work on average twelve hours a week or less. humaniq charges both the high
premium. And the low premium is reviewed afterwards (herziening): it becomes high over the whole
contract when a permanent contract ends within two months of starting, and high over the whole
year when a part-timer on less than 35 hours is paid more than 30 percent above the contracted
hours in the calendar year. humaniq has no review at all, so an employer either overpays or
underpays the premium without knowing.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `fil-premium-differentiation` | Apply the lower unemployment premium for permanent contracts and the higher one for flex. | corrected in this pass to `partial`, built: low and high by contract type are applied; BBL, young part-timers and the review are not |

### Competitors rated yes

- `fil-premium-differentiation`, AFAS Profit: "Profit applies the low WW premium for qualifying
  permanent contracts and the high WW premium for flexible contracts"
  (https://help.afas.nl/help/NL/SE/Pay_WgImpl_WW.htm).
- `fil-premium-differentiation`, Visma Raet Youforce: 2026-02 notes "adjust the signal on possible
  overrun of the hours norm for the lage AWf premium when WW herzien is set"
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/2).
- `fil-premium-differentiation`, HR2day: "record fixed-term, open-ended and on-call contracts for
  premium differentiation and the WAB WW situation"
  (https://data.maglr.com/1697/issues/64613/763867/index.html).
- `fil-premium-differentiation`, Loket.nl: "records per employment with a start and end period
  and a type of deviating AWF contribution"
  (https://developer.loket.nl/ApiDocs#tag/Deviating-AWF-Contribution).

## What Changes

- **The low premium for BBL and young part-timers.** The tariff resolution and the audit rule give
  the low premium to a `bbl` contract, and to an employee under 21 whose paid hours in the period
  average twelve a week or less.
- **Review when a permanent contract ends early.** When a low-premium contract ends within two
  months of its start, humaniq recomputes the premium at the high rate over the contract's
  periods and settles the difference in the current run.
- **Review of extra hours.** At the last period of the year, for every low-premium contract under
  35 hours a week, humaniq compares the paid hours with the contracted hours; above 30 percent more,
  it recomputes the year at the high rate and settles the difference in that run.
- **A signal before it happens.** A corpus rule warns during the year when a part-timer's paid
  hours are running above the 30 percent line.

## Capabilities

### New Capabilities

- `awf-premium-review`: the low premium for BBL and young part-timers, and the review of the
  low premium on early ending and extra hours, settled through a payroll adjustment.

## Impact

- `lib/Service/PayrollRunService.php` (`awfTariffFor()`) and
  `lib/Service/RetroAdjustmentService.php` (its own `awfTariffFor()`, :508): one shared resolver.
- `lib/Standards/Checks/NlPayrollChecks.php` (`expectedAwfTariff()`, :383) and
  `lib/Standards/rules/payroll.json` (`nl-awf-laag-hoog-tarief`): the two groups; a new rule
  `nl-awf-herziening-uren-signaal`.
- `lib/Service/AwfReviewService.php` (new), using `PayrollAdjustment` with correction type
  `awf-herziening` and the existing `deltaWerknemersverzekeringen` field.

## Out of scope

- Filing the review in a correction message; the review is settled in the current period's
  return, as the Belastingdienst prescribes for herziening.
- The small-employer Aof rate, which is a separate per-employer choice.

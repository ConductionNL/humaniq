---
kind: code
---

# Port what only shillinq's payroll engine has into humaniq

## Why

Shillinq carries a dormant Dutch payroll engine (`bookkeeping-payroll-engine-nl`: seven
schemas, `PayrollService`, `PayrollCalculator`, `PayrollJaaropgaveService`, three routes,
twelve pages). Ruben decided on 5 Oct 2026 (build-all DECISIONS row 65 b) that it moves to
humaniq: port it, do not just remove it. Humaniq already has its own payroll calculation, so
the port starts from a written comparison per calculation and per schema:
`for-ruben/payroll-engine-move-comparison.md` (build-all, lane 29).

That comparison finds humaniq ahead on every calculation both sides have. Shillinq's 2026
rates are stale on six counts, and nothing in its engine stores a payslip. Three things exist
only in shillinq:

1. A pension premium. Humaniq writes `pensionContribution: 0.0` on every payslip
   (`lib/Service/PayrollRunService.php:1063`), so anyone in a pension scheme is paid too much
   net.
2. A pro-rata gross for a partial period. Humaniq's `coversPeriod()` includes or excludes a
   whole employee, so a starter on the 15th gets a full month.
3. A `sectorcode` on the employer, which the sector fund part of the Whk premium needs.

This change builds those three in humaniq. Shillinq's copy is removed in shillinq, and only
after this change is built (row 65 b: "shillinq's copy is removed only after humaniq covers
it").

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `pay-pension-premium` | Withhold and charge pension premiums on each payslip per pension scheme. | `no`: added by this change; `pensionContribution` is always 0 |
| `pay-partial-period` | Pay a starter or leaver only for the part of the period they were employed. | `no`: added by this change; a partial month pays in full |

### Recorded non-goal this change reverses

- payroll-core-engine and abp-aansluiting record "pension premie calculation" as a non-goal:
  "no shared pension-premium computation capability exists for any fund yet". Row 65 b moves
  that calculation here, so this change supersedes the non-goal for the premium only. Filing
  the premium to the fund stays with `filings-pension-upa-message`.

## What changes

- **A pension premium step in the pack.** Per pension scheme: the pensionable salary, minus a
  franchise, times the employer and the employee percentage. The employee part reduces net
  pay. The employer part is an employer charge. Both are stored on the payslip and feed the
  payroll journal.
- **A pension scheme the payroll can read.** Where the scheme parameters live is an open
  choice (design O1).
- **A participation per employee.** Which scheme an employee is in, from when, and at what
  part-time factor.
- **A partial-period factor on the run.** An employee who starts or leaves inside the period
  gets the gross components multiplied by a factor. Calendar days or working days is an open
  choice (design O2).
- **`sectorcode` on `hrAdministration`**, only if Ruben wants the Whk sector fund premium
  (design O3).

## What does not change

- Every calculation the comparison names "humaniq" stays as it is. No shillinq rate, table or
  formula is copied.
- None of shillinq's seven schemas moves. Each has a humaniq counterpart that holds more
  (comparison, "Schemas").
- The running year-to-date on each payslip is dropped, not ported (comparison,
  `stampCumulatieven`).
- The journal's premium line is a separate humaniq defect, tracked in
  `payroll-wage-tax-remittance-shillinq` design Risks and
  `for-ruben/humaniq-payroll-journal-premiums.md`. This change adds the pension lines to the
  journal and leaves the premium line alone.

## Capabilities

### New capabilities

- `payroll-pension-premium`: compute, withhold and book the pension premium per scheme.
- `payroll-partial-period`: pay a partial first or last period pro rata.

## Impact

- `lib/Standards/packs/nl-2026.pack.json` (new steps), `lib/Standards/tables/nl-2026.json`
  or a new schema (O1), `lib/Settings/register.d/hr-objects.json` (`Payslip`,
  `hrAdministration`), a new fragment `hr-pension-scheme.json`, `PayrollRunService`,
  `PayrollGLPostService`, manifest pages under payroll.
- shillinq: removal of `bookkeeping-payroll-engine-nl` once this is built, as a shillinq
  change, after the schema-retirement checklist on each instance.

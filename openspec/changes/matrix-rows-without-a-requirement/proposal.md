---
kind: code
---

# Write the requirements thirteen matrix rows still lack

## Why

Every row in `openspec/parity/capabilities.json` should name the requirement that says it. For
most rows a shipped spec already does, and the row now records that requirement. For the thirteen
rows below, the cited spec covers only part of the row, or states the rule in prose with no
scenario. This change writes the missing half as requirements with scenarios.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | what the cited spec leaves out |
|---|---|
| `ppl-contract-expiry-signal` | a signal when a probation period ends (hr-signals left it out on purpose) |
| `tal-comp-cycle` | a budget on the review cycle |
| `lve-leave-in-pay` | paying the open leave balance; offboarding only flags a missing payout |
| `tim-timesheet-not-empty` | a scenario for refusing an empty timesheet |
| `tim-reopen` | a scenario for reopening, and a stop once payroll has used the hours |
| `tim-billable-rates` | a rate on a billable booking |
| `tim-attendance-overview` | a today view of who is present, absent or late |
| `tal-review-forms` | the employee signing the review |
| `tim-clock-in` | a clock in and clock out by the employee; the spec only walks the record's lifecycle |
| `plt-public-api` | an integrator reading and writing a record; the spec only says what humaniq does not build |
| `dm-expense-vat` | the VAT to reclaim; the spec only adds the field |
| `cmp-rule-engine` | a page with the violations; the audit is reachable through `occ` only |
| `cmp-coverage` | the same page shows the corpus coverage; today only `occ` prints it |

## What changes

- New requirements in seven capabilities, listed below. No code in this change.
- Each row records the new requirement next to the shipped one it already had.

## Capabilities

### Modified capabilities

- `hr-signals`: probation end signal.
- `comp-cycles`: a cycle budget.
- `offboarding-completion`: the open leave balance is paid on the final payslip.
- `humaniq-timesheet-approval`: empty timesheets refused, reopening until payroll uses them.
- `time-entry-capture`: a billable rate on the booking.
- `time-attendance`: today's presence overview, and clocking in and out.
- `performance-reviews`: the employee signs off.
- `hris-api-public`: an integrator reads and writes through the objects API.
- `receipt-ocr`: the VAT to reclaim per period.
- `hrm-rule-engine`: a page with the audit's violations and coverage.

## Impact

Specs only. The build of each requirement follows in its own change.

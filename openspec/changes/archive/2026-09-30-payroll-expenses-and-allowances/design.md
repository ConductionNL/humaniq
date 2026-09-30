# Design: pay approved expenses and fixed allowances through the payslip

## Context

Read at `development` af702f78.

- `Expense` (`lib/Settings/register.d/hr-expense.json:5`): lifecycle `reimburse` (approved to
  reimbursed, terminal, lines 75 to 81) carries no guard and only stamps `reimbursedAt`
  (line 187). `grep -n Expense lib/Service/PayrollRunService.php` finds nothing.
- `lib/Service/PayrollRunService.php` `generate()`:
  - pre-calculation gross folds sit before `new CalculationInput(` (line 492): the sick-pay
    substitution and the vehicle bijtelling, which is added to `grossMonthlySalaryCents`;
  - post-tax folds follow the calculation: `retroAdjustment` and `leaveBuySell` are added to
    net at line 536, then the wage garnishment is deducted; `totals['net']` (line 570) takes the
    folded net, and the run stores `totalNet` (line 603).
- `lib/Service/PayrollGLPostService.php:242` `buildLines()`: debit gross and employer charges,
  credit wage tax and a net-wages liability computed as `totalNet` plus the remainder of
  `totalGross + totalEmployerCharges - totalLoonheffing - totalNet`. A negative remainder
  fails the post with "Inconsistente runtotalen". A post-tax addition to net that has no debit
  of its own shrinks that remainder.
- `WkrDeclaration` (`hr-objects.json:455`): `administrationId`, `year`, `date`,
  `description`, `amount`, `wkrCategory` (`gericht-vrijgesteld`, `vrije-ruimte`,
  `eindheffing`), `employeeId`, `sourceReference`. `lib/Service/WkrService.php` sums the
  free-margin use from these rows and the wage bill from `Payslip.grossPay`.
- `lib/Standards/tables/nl-2026.json` `parameters.wkr` holds the free-margin percentages and
  the final-levy rate; no home-working norm.
- The tax-free rate per kilometre is the corpus parameter `rateEurPerKm` of
  `nl-reiskosten-onbelast-tarief` (`lib/Standards/rules/payroll.json:1358`).
- `lib/Service/PayrollNetPayService.php` pays `Payslip.nettoPay` per employee through a
  shillinq payment run (payroll-sepa-netpay-shillinq).
- The sibling change `expenses-travel-calculation` adds `Expense.taxFreeAmount`,
  `Expense.taxableAmount` and `CommuteArrangement.monthlyAllowance`; this change reads them
  when present and works without them.

## Goals / Non-Goals

**Goals**

- An approved claim is paid once, on a payslip, and booked in the payroll journal.
- A fixed allowance pays itself every period with the right split between taxed and untaxed.
- The WKR ledger receives the allowance payments without typing.

**Non-Goals**

- Taxing a single claim's excess over the tax-free rate (needs the bijzonder tarief).
- WKR final levy payment and filing.
- Per-employee or per-cost-centre journal lines.

## Decisions

### D1. A claim takes one route, payroll or direct

`Expense.reimbursementRoute` (`payroll` or `direct`) is set at approval from an employer
setting, and an HR adviser may change it while the claim is approved and unpaid. A claim with
`taxableAmount > 0` is refused for the payroll route with a message. The `reimburse`
transition stays for the direct route. For the payroll route the run is the only writer of
`reimbursed`.

Alternative considered: always pay through payroll. Rejected: a claim approved on the 28th can
wait a month in payroll, and some employers reimburse weekly.

### D2. Claims are a post-tax fold, paid once

`PayrollExpenseFoldService::claimsFor(employeeId, period, runId)` selects the employee's claims
with route `payroll`, status `approved`, `approvedAt` on or before the period's last day, and
no `payrollRunId` or this run's id. Their amounts are summed into `Payslip.reimbursements` and
added to net after the leave fold and before the garnishment, so the garnishment's protected
amount is computed on the employee's real take-home, the loonbeslag design's own rule. When the
run is saved, each claim gets `payrollRunId` and `paidInPeriod`. When the run's status crosses
from `draft` to `approved`, a `PayrollRunApprovedListener` on OpenRegister's
`ObjectUpdatedEvent` (the `TimesheetApprovalListener` precedent) moves each claim to
`reimbursed` with `reimbursedAt`, whether the approval came from the Loonrun flow or a direct
edit. Recalculating a draft re-selects the same claims. If `time-hours-and-overtime-to-payroll`
lands first, this change adds its step to the same listener instead of a second one.

Alternative considered: add the claim to the gross. Rejected: a reimbursement of costs within
a tax exemption is not wage and must not be taxed or insured.

### D3. A recurring allowance is its own object with a declared tax treatment

`RecurringAllowance`: `employeeId`, `kind` (`thuiswerk`, `reiskosten`, `telefoon`, `other`),
`amountPerMonth` or `amountPerDay` with `daysPerMonth`, `taxTreatment` (`gericht-vrijgesteld`,
`vrije-ruimte`, `belast`), `commuteArrangementId` (optional), `startDate`, `endDate`,
`status` (`draft`, `active`, `ended`), `administrationId`. Lifecycle `activate` (guarded by
`NoSelfApprovalGuard`) and `end`. A `reiskosten` allowance with a commuting arrangement takes
its monthly amount and tax-free part from the arrangement.

Alternative considered: extend `WkrDeclaration` with a recurrence. Rejected: a declaration is
a ledger row of something paid; an allowance is a standing order that produces those rows.

### D4. Taxed parts go into the gross, untaxed parts into net

For each active allowance covering the period:

- `belast`: the whole amount is added to the gross before the calculator, like the
  bijtelling fold, so it is taxed and insured as regular periodic wage.
- `gericht-vrijgesteld`: the untaxed part is capped at the norm (per day for `thuiswerk`, from
  a new sourced tables leaf; per kilometre for `reiskosten`, from the corpus rate) and folded
  into net; any excess is added to the gross. For `telefoon` and `other` the declared amount is
  untaxed as declared.
- `vrije-ruimte`: the whole amount is folded into net; the WKR assessment decides whether a
  final levy is due.

When the norm leaf is unverified or a placeholder, the allowance is not paid that period and
the payslip lists it as `norm-unverified`. Nothing is paid with a guessed norm.

Alternative considered: treat every allowance as taxed. Rejected: it overtaxes the most common
allowance, the home-working allowance, which is exempt up to its norm.

### D5. The run writes the WKR rows

For every allowance payment the run upserts one `WkrDeclaration` keyed on
`sourceReference = allowance:{allowanceId}:{period}`, with `wkrCategory` equal to the tax
treatment for the untaxed part. A recalculation rewrites the same row. Taxed parts write no
row: they are wage, not a WKR cost.

### D6. The journal carries the reimbursements

`PayrollRun.totalReimbursements` sums the claims' reimbursements and the untaxed allowance
parts. `buildLines()` adds a debit line on a new setting
`getGlPostAccountReimbursements()` and includes it in the balance:
`gross + charges + reimbursements = loonheffing + net + remainder`. A run without
reimbursements produces the same four lines as today.

Alternative considered: leave the journal alone. Rejected: the net rises by the
reimbursements with nothing on the debit side, the remainder shrinks, and a run with enough
reimbursements fails the post.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| allowance lifecycle | declarative `x-openregister-lifecycle` with `NoSelfApprovalGuard` | the engine's state machine |
| route on a claim | a plain field, set at approval | no new state |
| claim and allowance folds | imperative, inside `generate()` | engine input and net fold across schemas |
| WKR rows | imperative, idempotent upsert | written as part of the run |
| journal line | imperative, `PayrollGLPostService` | the journal is built there |

## Seed data

- `expense-devries-train` (approved, 27.40) gains `reimbursementRoute: payroll`, so a
  recalculated run pays it on `employee-devries`'s payslip.
- A new `allowance-jansen-thuiswerk`: 8 days a month, `gericht-vrijgesteld`, active from
  2026-01.

## Risks / Trade-offs

- [The existing retro and leave folds already raise net without a debit line] -> this change
  books its own reimbursements explicitly and does not widen that gap; the existing gap is
  reported, not fixed here.
- [A claim approved after the run was approved] -> it is picked up by the next draft run,
  exactly once.
- [Days per month for home working vary] -> the allowance carries a fixed day count; paying
  on actual office days is a later change.

## Open Questions

- Should an allowance follow the contract's part-time factor automatically? This design keeps
  the amount as HR enters it.

## As built (2026-09-30)

- D1: the employer default is the app config key `expense_reimbursement_route` (`payroll` or
  `direct`); unset or unknown means `direct`, so nothing changes for an employer until it opts
  in. `ExpenseRouteListener` stamps the route on the approval edge (direct for a claim with a
  taxable part), refuses the payroll route to a claim with a taxable part, refuses a route
  change once a run holds the claim, and refuses a manual `reimburse` of a payroll-route
  claim. humaniq's own writes pass (the `InternalWriteMarker`).
- D2: `PayrollRunApprovedListener` (added by time-hours-and-overtime-to-payroll) gained the
  claim step as an optional third argument, as the design foresaw. The claim fold is added to
  net before the garnishment; a payslip without claims or allowances gains no field at all.
- D3: `RecurringAllowanceStampListener` stamps `proposedBy`, the employee's `userId` and
  `administrationId`, so `NoSelfApprovalGuard` refuses both the drafter and the employee. An
  update keeps the original drafter.
- D4: the split lives in a pure `AllowanceSplitter`. An allowance that pays nothing is listed
  on the payslip with its reason: `norm-unverified`, `days-missing`, `no-amount`,
  `arrangement-missing` or `treatment-unknown`. The norm is the new leaf
  `parameters.wkr.thuiswerkNormPerDag` (2.45 a day, Belastingdienst, Tarieven, bedragen en
  percentages loonheffingen vanaf 1 januari 2026, table 13); a placeholder leaf counts as
  unverified. A travel allowance with a targeted exemption takes the commuting arrangement's
  `taxFreeMonthly` and `taxableMonthly`; the per-kilometre corpus rate is not read directly.
- D5: WKR rows are upserted on `sourceReference`. Known limit: when a recalculation no longer
  pays an allowance (it ended, or was deactivated), the row written by the earlier
  calculation of that draft stays; nothing deletes it.
- D6: the reimbursement account is the app config key `glpost_account_reimbursements`
  (placeholder `4010`, like the other journal accounts). The debit line is only built when
  the run has reimbursements, so every other journal keeps its four lines.
- Register 0.48.0: Expense 0.10.0, Payslip 0.15.0, PayrollRun 0.4.0, RecurringAllowance 0.1.0.


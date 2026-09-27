---
kind: code
---

# Pay approved expenses and fixed allowances through the payslip

## Why

A manager approves an employee's train ticket in humaniq. Then someone presses "reimburse" on
the claim, which only flips its status and stamps a date; the money is paid somewhere else, by
hand. No payslip ever shows the reimbursement and no journal ever books it. The payroll engine
reads sick pay, lease-car bijtelling, retro corrections, leave buy and sell and wage garnishment,
but not a single expense.

A fixed allowance, such as a home-working allowance per day or a monthly travel allowance, is
worse off. humaniq has the WKR ledger (`WkrDeclaration`), so HR can record each payment for the
free-margin budget, but every row is typed by hand and nothing pays the allowance. Every month
HR types the same rows again and pays them outside payroll.

This change pays approved claims and fixed allowances through the payslip, keeps the tax-free
and the taxed part apart, writes the WKR rows itself, and books the reimbursements in the
payroll journal that goes to the ledger.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `exp-reimburse-in-pay` | Reimburse approved expenses through the payslip. | `no`, built.state `built`: `Expense.reimburse` is a bare status flip with `reimbursedAt`; no run or payslip reads an expense |
| `exp-fixed-allowances` | Pay fixed allowances such as a home working or travel allowance. | `partial`, built.state `built`: `WkrDeclaration` is a hand-entered ledger row per payment; no recurring allowance pays itself |
| `plt-accounting-link` | Connect to accounting software so payroll and expenses post themselves. | `partial`, built.state `built`: the payroll journal posts to shillinq through occ or the Loonrun flow; reimbursed expenses reach no ledger or payslip |

### Competitors rated yes

- `exp-reimburse-in-pay`, AFAS Profit: "costs are booked on a cost type linked to a wage
  component and passed to Profit Payroll after Accorderen voor Payroll, paid with the salary"
  (https://help.afas.nl/help/NL/SE/Hrm_Declrs.htm).
- `exp-reimburse-in-pay`, Visma Raet Youforce: "after manager approval the claim is processed
  directly in the salary administration" (https://youforce.nl/product/app).
- `exp-reimburse-in-pay`, HR2day: "expenses are included directly on the payslip with correct
  tax treatment" (https://www.hr2day.com/features/declaraties/).
- `exp-reimburse-in-pay`, Loket.nl: "declarations are linked automatically to the right wage
  components" (https://loket.nl/functionaliteiten/declareren/).
- `exp-fixed-allowances`, AFAS Profit: "fixed wage components such as a travel allowance can be
  given a value directly at onboarding"
  (https://help.afas.nl/help/NL/SE/Ins_Config_Profil_Lc.htm).
- `exp-fixed-allowances`, Visma Raet Youforce: "Gewerkte dagen lets employees mark home and
  office days so travel and home-working allowances are calculated and paid via salary"
  (https://apps.apple.com/nl/app/youforce/id1541134359).
- `exp-fixed-allowances`, HR2day: "a fixed allowance is added to the employment relation as a
  wage component" (https://data.maglr.com/1697/issues/24692/328959/index.html).
- `exp-fixed-allowances`, Loket.nl: "home working days or fixed allowances are submitted in
  one go and linked to wage components" (https://loket.nl/functionaliteiten/declareren/).
- `exp-fixed-allowances`, Personio: "recurring compensations are salary components paid
  monthly, quarterly, half-yearly or yearly at a constant amount"
  (https://support.personio.de/hc/en-us/articles/360000401925-Create-and-manage-recurring-payments).
- `plt-accounting-link`, AFAS Profit: "payroll journals and approved claims post into Profit
  Financieel in the same system" (https://help.afas.nl/help/NL/SE/143329.htm).
- `plt-accounting-link`, Visma Raet Youforce: "connect Youforce with your financial package so
  payroll and bookkeeping stay updated" (https://youforce.nl/product/hr-core-salaris).
- `plt-accounting-link`, HR2day: "payroll journal for Microsoft Dynamics 365 Business Central"
  (https://data.maglr.com/1697/issues/68666/808497/index.html).
- `plt-accounting-link`, Loket.nl: "wage journal entries from Loket are posted automatically in
  the bookkeeping administration" (https://loket.nl/koppelingen/exact-online/).

### How this relates to open and recorded changes

- The payroll half of `plt-accounting-link` is the open change `payroll-run-as-a-flow`: its
  `humaniq.payroll-glpost` step posts every approved run. This change specifies the expenses
  half only: approved claims paid through the payslip, and so into that journal and the SEPA
  net-pay handoff.
- mileage-rules (`openspec/changes/archive/2026-07-14-mileage-rules/design.md`, named
  follow-ups): "loonheffing gross-up of the bovenmatige vergoeding onto a Payslip/PayrollRun;
  vaste (fixed monthly) reiskostenvergoeding / 214-dagenregeling." This change pays the fixed
  travel allowance through the payslip and taxes its taxable part as wage. A single claim's
  taxable excess waits for the bijzonder tarief, see Out of scope.
- payroll-sepa-netpay-shillinq: "Expense-reimbursement / bonus aggregation into the batch: the
  batch pays `Payslip.nettoPay` only." That stays true. A reimbursement folded into
  `nettoPay` is paid by the unchanged batch.
- wkr-administration: "Gerichte-vrijstelling normbedrag validation stays judgemental; this
  change trusts the declared category." Unchanged: HR declares each allowance's tax treatment.
  humaniq applies the numeric norm only to cap the untaxed part of the two allowances whose
  norm is a number (home working per day, travel per kilometre).

## What Changes

- **A route per claim.** An approved claim goes either through payroll or directly, as today.
  An employer setting chooses the default. A claim with a taxable part cannot take the payroll
  route yet.
- **Claims on the payslip.** The run folds every approved, unpaid payroll-route claim of the
  employee into the payslip as a tax-free reimbursement added to net pay, lists the claims,
  and marks each claim reimbursed with the run that paid it.
- **Recurring allowances.** A new `RecurringAllowance` holds an allowance per employee: kind
  (home working, travel, telephone, other), amount per month or per day with a day count, tax
  treatment (untaxed under a gerichte vrijstelling, charged to the WKR free margin, or taxed as
  wage), and a period. HR activates it with separation of duties. A travel allowance can take
  its amount from an approved commuting arrangement.
- **Taxed and untaxed kept apart.** Each period the run adds the taxed part of every active
  allowance to the gross, before the calculator, and folds the untaxed part into net pay. For
  home working and travel the untaxed part is capped at the norm and the excess is taxed.
- **The WKR ledger fills itself.** Every allowance payment writes one `WkrDeclaration` row,
  keyed on the allowance and the period, so the WKR assessment sees it without typing.
- **Reimbursements in the journal.** `PayrollRun` gains `totalReimbursements`, and the journal
  gains a debit line on a reimbursement account, so a run with reimbursements stays balanced
  and the claims reach the ledger through the existing journal post.

## Capabilities

### New Capabilities

- `payroll-expenses-and-allowances`: approved claims and recurring allowances paid through the
  payslip, with their tax treatment, WKR rows and journal line.

## Impact

- `lib/Settings/register.d/hr-expense.json`: `Expense` gains `reimbursementRoute`,
  `payrollRunId`, `paidInPeriod`; new schema `RecurringAllowance` with a lifecycle.
- `lib/Settings/register.d/hr-objects.json`: `Payslip` gains `reimbursements`,
  `reimbursedExpenseIds`, `allowancesTaxed`, `allowancesUntaxed`, `allowanceLines`;
  `PayrollRun` gains `totalReimbursements`.
- `lib/Standards/tables/nl-2026.json`: a sourced leaf for the home-working norm per day.
- `lib/Service/PayrollExpenseFoldService.php` (new) and `lib/Service/PayrollRunService.php`:
  the claim fold, the allowance folds and the stamps.
- `lib/Listener/PayrollRunApprovedListener.php` (new, or extended when it already exists):
  marks paid claims reimbursed when a run is approved.
- `lib/Service/WkrService.php`: the allowance rows it writes.
- `lib/Service/PayrollGLPostService.php` and `lib/Service/SettingsService.php`: the
  reimbursement line and its account.
- `src/manifest.d/hr-expense.json`, `src/manifest.d/hr-objects.json`, `05-menu.json`: the
  allowance pages and the new payslip fields.
- `lib/Settings/register.d/hr-seed.json`: one payroll-route claim and one home-working
  allowance.

## Out of scope

- Taxing the excess of a single claim above the tax-free rate. That is a special payment
  taxed at the bijzonder tarief, which `payroll-reservation-payouts` adds; until then such a
  claim keeps the direct route.
- Paying the WKR final levy (`wkr-eindheffing-filing`, the wkr-administration fast-follow).
- Card feeds and expense policy limits.
- Splitting the journal per employee or cost centre: `payroll-cost-allocation`.

## Cross-app dependencies

- shillinq: an account in the administration's chart for employee expense reimbursements, so
  the journal's new debit line posts. The net-pay batch needs no change: it pays
  `Payslip.nettoPay`, which now includes the reimbursements.

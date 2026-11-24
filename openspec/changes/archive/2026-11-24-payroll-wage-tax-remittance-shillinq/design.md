# Design: payroll-wage-tax-remittance-shillinq

## Context

**humaniq side (read on development 97c4e7f5).** A payable `PayrollRun` has status `approved` or
`posted` (`payroll-sepa-netpay-shillinq` D4). The wage tax return for a period is a
`LoonaangifteFiling` (0.5.0). `LoonaangifteMessageService` stores on it the run it was made from
(`messageRunId`) and the return's totals in whole euros (`collectiveTotals`, from
`LoonaangifteMessageBuilder`). There `TotTeBet` is the sum of the payable parts and `TotGen` is
`TotTeBet` plus the balances of corrections the return carries (Gegevensspecificaties 2026
p55-57, conditions 2315 and 0011). The filing also carries `deadline` (last day of the next
month), `betalingskenmerk` (the Belastingdienst payment reference), `aangiftenummer`, `status`
(`concept`, `klaargezet`, `bevestigd`, `verzonden`) and `administrationId`.

**shillinq side (read on ConductionNL/shillinq development e079c43d).** Shillinq merges its
`lib/Settings/register.d/*.json` fragments onto `shillinq_register.json` at load
(`SettingsService::deepMergeConfig`). The merged `APTransaction` (0.3.0) requires
`invoiceNumber`, `vendorId`, `invoiceDate`, `dueDate`, `currency`, `totalAmount`, `lines`
(minItems 1; each `description`, `accountNumber`, `amount` >= 0), `state` and `administrationId`.
`invoiceNumber` is required but nullable. `state` starts at `draft`; `receive` (draft to
received) runs `APGuard::isInvoiceNumberUnique`, which dedupes per payee and passes an empty
number. `issue` runs `BalanceGuard::isInvoiceBalanced` (lines plus `taxAmount` equal
`totalAmount`) and `MaterialiseGlTransactionAction`, which debits each line's account and credits
the creditors control account. `PaymentRunProposalService::lineFor()` turns a due, issued
invoice into a payment line whose `remittanceInfo` is the invoice's `invoiceNumber`, falling back
to the object id. `Payee` (0.2.0) has `payeeType` `government` and `bankAccount.iban`.

So shillinq can take a draft payable to a creditor without an invoice: an `APTransaction` in
`draft`. No new shillinq schema or route is needed.

## Decisions

### D1: humaniq writes a draft APTransaction and pays nothing

The remittance is one `APTransaction` in state `draft`, written through OpenRegister's
ObjectService on the same instance, never over HTTP. Receiving, issuing, the payment run and the
bank file stay shillinq's. humaniq cannot skip them: it writes `draft` only and drives no
transition.

### D2: the amount is the return's TotGen, and it includes the premiums

The amount is `collectiveTotals.TotGen` of the return made from the run. That is what the
Belastingdienst collects for the period. It is not recomputed from payslips.

`TotTeBet` in humaniq's builder sums the withheld wage tax (`IngLbPh`) with every premium the
employer declares: `TotPrAofLg/Hg/Uit`, `TotOpslWko`, `TotPrGediffWhk`, `TotPrAwfLg/Hg/Hz/Uit`,
`PrUFO` and `IngBijdrZvw` (`LoonaangifteMessageBuilder::PAYABLE`). humaniq declares the employee
insurance premiums and the Zvw contribution in the same return, so they are paid to the same
creditor in the same payment. There is no UWV payee. Shillinq's deleted
`PayrollApArHandoffService` split them into a UWV payment, which was wrong.

Source, checked on 5 Oct 2026 against the Handboek Loonheffingen 2026 (March 2026): section 1
lists the loonheffingen as loonbelasting/premie volksverzekeringen, the premies
werknemersverzekeringen and the inkomensafhankelijke bijdrage Zvw, and section 13.4.1 says to pay
the end amount of the return (`te betalen loonheffingen`) to the Belastingdienst's account with
the return's betalingskenmerk. The Belastingdienst moves to a new account (NL04 RABO 0200 1122 44)
from 1 May 2026; the old one is accepted for every 2026 return. That account belongs on the payee
in shillinq, not in humaniq.

A `TotGen` of zero or less means nothing to pay (a negative one is a refund the Belastingdienst
pays out). No payable is written; the hand-off is logged as `nothing-to-pay`.

### D3: which return, and when

For each payable run, the service takes the `LoonaangifteFiling` whose `messageRunId` is the
run, with `jurisdiction` `NL` and `filingType` `loonaangifte`, in status `bevestigd` or `verzonden`. Those two
states mean the return's amount will not change. A run with no such return gets no record and
the outcome `no-return`, so the next `occ` call tries again. A correction sent as its own message
(`correctionRoute` `correctiebericht`, closed years) is out of scope; a correction carried by a
later return is already in that return's `TotGen`.

### D4: the payload (semantic reuse of AP fields, documented)

| APTransaction field | Value | Why |
|---|---|---|
| `vendorId` | app setting `wagetax_payee_id` | shillinq's Belastingdienst `Payee`, created there by the administrator |
| `invoiceNumber` | the filing's `betalingskenmerk` | shillinq's payment proposal sends `invoiceNumber` as the remittance info, so the bank transfer carries the payment reference. It also makes `APGuard` refuse a second payable with the same reference for this payee |
| `invoiceReference` | the filing's `aangiftenummer` | the return this pays, readable for the bookkeeper |
| `invoiceDate` | last day of the wage period | the period the liability belongs to; shillinq derives the fiscal period from it on `issue` |
| `dueDate` | the filing's `deadline` | the statutory payment date |
| `currency` | `EUR` | |
| `totalAmount` | `TotGen` | D2 |
| `taxAmount` | `0` | no VAT |
| `lines` | one line: `Loonheffingen {period}, aangifte {aangiftenummer}`, account `glpost_account_wage_tax_liability`, amount `TotGen` | issuing debits the wage tax liability that `payroll-glpost-shillinq` credited, and credits creditors |
| `state` | `draft` | D1 |
| `administrationId` | the run's `administrationId`, verbatim | same vocabulary rule as the journal entry and the net pay batch |

A filing without a `betalingskenmerk`, or an unset or unknown `wagetax_payee_id`, fails closed:
nothing is written to shillinq and the record says what to fix. A payment to the Belastingdienst
without its reference is booked against nothing.

### D5: idempotency and crash recovery

- humaniq: at most one `WageTaxRemittance` with status `created` per filing. A second call for
  the same filing is a no-op that returns the existing record. `failed`, `skipped-no-shillinq` and
  `nothing-to-pay` are retried on the next call.
- shillinq: before writing, the service looks for an `APTransaction` with this `vendorId` and
  `invoiceNumber`. If one exists (a crash after the write, before the record), it is adopted, not
  written twice.

### D6: duck-typed, never a dependency

The same probe as the net pay hand-off: `IAppManager::isInstalled('shillinq')` and a guarded read
of register `shillinq`, schema `APTransaction`. Any miss writes a `skipped-no-shillinq` record.
humaniq keeps no composer or info.xml dependency on shillinq, and never names another app id.

### D7: the trigger is an occ command

`humaniq:wagetax:remit [--period YYYY-MM]`, registered in `appinfo/info.xml`, like
`humaniq:netpay:run` and `humaniq:glpost:run`. Exit 0 when nothing failed, 1 otherwise.

### Declarative versus imperative (ADR-031)

| Behaviour | Path | Why |
|---|---|---|
| `WageTaxRemittance` data model and statuses | declarative schema | ADR-031 default |
| Reading the return and writing into shillinq | imperative `WageTaxRemittanceService` | ADR-031 exception for a cross-app write, the same class as `PayrollNetPayService` |
| Trigger | occ command | no lifecycle on `PayrollRun` to hang an action on |
| Pages | declarative manifest | ADR-031 default |

## Schema

**New fragment `lib/Settings/register.d/hr-remittance.json`, `WageTaxRemittance` 0.1.0**,
required `payrollRunId`, `filingId`, `period`, `status`:

| Field | Type | Notes |
|---|---|---|
| `payrollRunId` | uuid, `$ref` PayrollRun | the run |
| `filingId` | uuid, `$ref` LoonaangifteFiling | the return paid |
| `period` | string | YYYY-MM |
| `administrationId` | string, nullable | from the run |
| `status` | enum `created`, `nothing-to-pay`, `skipped-no-shillinq`, `failed` | `created` = draft payable in shillinq, not paid |
| `amount` | number, nullable | `TotGen` in euros |
| `paymentReference` | string, nullable | the `betalingskenmerk` sent |
| `dueDate` | date, nullable | the return's deadline |
| `shillinqPayableRef` | string, nullable | the shillinq `APTransaction` id; a plain string, cross-register |
| `errorMessage` | string, nullable | why it failed or was skipped |
| `createdAt` | date-time, nullable | when the payable was written or adopted |

## Risks

- **The journal books employer premiums on the net wages account.** `PayrollGLPostService`
  credits `glpost_account_wage_tax_liability` with `totalLoonheffing` only and puts the rest of
  the employer charges into the net wages liability (the remainder line). Issuing this payable
  debits the wage tax liability with the whole `TotGen`, premiums included. After issue the wage
  tax liability goes negative by the premiums and the net wages liability stays too high by the
  same amount. This is a defect in the journal, not here; it is reported for its own change.
- **Whole euros against cents.** `TotGen` is in whole euros, the journal in cents. The
  difference stays on the wage tax liability; the bookkeeper clears it.
- **AP-shaped fields carry tax data (D4).** If shillinq starts treating `invoiceNumber` as a
  purchase invoice for VAT checks, this reuse needs a source discriminator on `APTransaction`.
- **The payee is configuration.** A wrong `wagetax_payee_id` sends the payable to the wrong
  creditor. The service checks that the payee exists, not that it is the Belastingdienst.

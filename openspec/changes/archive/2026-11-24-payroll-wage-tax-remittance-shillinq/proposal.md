---
kind: code
---

# Pay the wage tax return from shillinq

## Why

After a payroll run, humaniq already puts two drafts into shillinq: the payroll journal entry
and the net pay batch for the employees. The third payment of every pay period has no hand-off.
The amount on the wage tax return (loonaangifte) is due to the Belastingdienst by the last day
of the next month. Today a bookkeeper types it into shillinq by hand, payment reference included.

Ruben decided on 5 Oct 2026 (build-all DECISIONS row 65 c) that humaniq hands this remittance
into shillinq as a draft payable to the Belastingdienst. It sits next to the journal entry and
the net pay batch, modelled on `payroll-sepa-netpay-shillinq`. Shillinq's own four
`Payroll*HandoffService` classes are deleted (row 65 a); this change replaces the one idea in
them worth keeping, without their UWV payee error.

## What changes

- A new imperative `WageTaxRemittanceService` reads, for each payable payroll run, the wage tax
  return made from that run once it is confirmed or sent. It writes one draft `APTransaction`
  into shillinq for the return's total to pay (`TotGen`), to the shillinq payee the
  administrator names, due on the return's deadline, with the Belastingdienst payment reference.
- A new schema `WageTaxRemittance` (fragment `hr-remittance.json`) logs each hand-off: what was
  sent, when, and the outcome. It is never the payable itself.
- A new occ command `humaniq:wagetax:remit [--period YYYY-MM]` is the trigger, like
  `humaniq:netpay:run`.
- A new app setting `wagetax_payee_id` names shillinq's Belastingdienst payee.
- Two manifest pages list and show the hand-offs under payroll.

## What does not change

- humaniq never pays. Approval, the payment run and the bank file stay shillinq's.
- No payee is created in shillinq. The administrator creates the Belastingdienst payee there
  and names it in humaniq.
- No separate payee for UWV. The employee insurance premiums and the Zvw contribution are part
  of the return's total to pay, and the Belastingdienst collects them (see design D2).

## Capabilities

### New Capabilities

- `payroll-wage-tax-remittance-shillinq`: the hand-off of a confirmed wage tax return's amount to
  shillinq as a draft payable.

## Impact

- `lib/Service/WageTaxRemittanceService.php` (new), `lib/Command/WageTaxRemitCommand.php` (new),
  `appinfo/info.xml` (command), `lib/Service/SettingsService.php` (one getter).
- `lib/Settings/register.d/hr-remittance.json` (new schema and seed object), register 0.57.0.
- `src/manifest.d/hr-remittance.json` (two pages, one menu entry), l10n en and nl.
- `tests/fixtures/shillinq/ap-transaction-schema.json`: shillinq's real `APTransaction` schema,
  deep-merged from its register fragments, so the payload is validated against what shillinq
  stores.

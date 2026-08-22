---
sidebar_position: 5
description: How an approved payroll run posts to the general ledger and hands off net-pay to SEPA, both via shillinq.
---

# GL posting & SEPA net-pay

Humaniq stays a **leaf app** for both bookkeeping and payments: it never
touches the general ledger directly and never emits SEPA XML. Instead,
every approved `PayrollRun` produces a draft object in
[shillinq](https://shillinq.conduction.nl) — Conduction's bookkeeping
app — via same-instance OpenRegister calls. shillinq owns everything from
there: approval, journal posting, the pain.001.001.03 SEPA export, and
CAMT.053 reconciliation.

Both integrations are **duck-typed**: Humaniq checks whether shillinq is
installed and its expected register/schema resolve, and degrades to a
recorded `skipped-no-shillinq` outcome rather than throwing when it isn't
— no `info.xml` or composer dependency on shillinq is added. A run that
was skipped stays eligible for a later retry once shillinq is installed.

## GL posting

`PayrollGLPostService` builds one **balanced 4-line journal** per approved
run and posts it as a draft `JournalEntry` into shillinq's bookkeeping
register:

| Line | Side | Amount |
| --- | --- | --- |
| Bruto loonkosten | debit | `totalGross` |
| Werkgeverslasten | debit | `totalEmployerCharges` |
| Loonheffing-schuld | credit | `totalLoonheffing` |
| Netto-loonschuld | credit | `totalNet + R` |

`R` is the balancing remainder (`(totalGross + totalEmployerCharges) −
(totalLoonheffing + totalNet)`), so debits equal credits by construction.
Accounts are configurable via `SettingsService` getters (placeholder
defaults `4001`/`4002`/`1701`/`1702`). If any total is missing or the
remainder would be negative, the attempt fails closed — nothing is written
to shillinq, and the `PayrollGLPost` record carries a diagnostic
`errorMessage`.

The journal lands as a **draft** `JournalEntry` — Humaniq never drives
shillinq's own posting/approval lifecycle. Posting is idempotent per run.

```bash
occ humaniq:glpost:run --period=2026-06
```

## SEPA net-pay

`PayrollNetPayService` collects one payment line per payslip on a payable
run (`status` approved or posted) and hands them to shillinq as a draft
`PaymentRun`:

- `payeeName` — the employee's `tenaamstelling`, or first + last name
- `creditorIban` — the `Employee.iban` on file
- `amount` — the payslip's `nettoPay`, rounded to cents
- `remittanceInfo` — `"Salaris {period}"`

**Fail-closed on missing IBANs**: if any payslip's employee has no IBAN,
or an unresolvable employee, or an invalid net amount, **no** shillinq
object is created — the whole batch fails with every line's diagnostic
recorded, so a partial salary run can never go out. An empty line set
(no payslips, or all zero) likewise fails.

The resulting `PaymentRun` is created as `draft` in shillinq — Humaniq never
generates SEPA pain.001 XML itself and never advances shillinq's approval,
export, or reconciliation lifecycle. Batch creation is idempotent per run
with crash recovery: a deterministic `runNumber`
(`HRMQ-NETPAY-{period}-{administrationId}`) lets a re-run adopt an
already-created `PaymentRun` instead of double-creating one — duplicate
salary payment is the top-severity failure this guards against, with
shillinq's own mandatory human approval gate as the final backstop.

```bash
occ humaniq:netpay:run --period=2026-06
```

## Employee bank details

`Employee` carries two nullable fields feeding the net-pay handoff:

- `iban` — shape-checked against the IBAN pattern (no mod-97 checksum;
  that's explicitly out of scope)
- `tenaamstelling` — the account-holder name as the bank knows it

Both fields are additive and optional — existing Employee records without
them stay valid.

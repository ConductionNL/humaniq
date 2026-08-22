---
sidebar_position: 10
description: Court/deurwaarder-ordered wage garnishment — a floor-clamped, post-tax payslip fold that can never push net pay below the beslagvrije voet.
---

# Loonbeslag (wage garnishment)

A court or deurwaarder can order a periodic deduction from an
employee's net pay to satisfy a debt. Dutch law (Wetboek van
Burgerlijke Rechtsvordering art. 475b–475e, and from 2021 the Wet
vereenvoudiging beslagvrije voet) guarantees the employee keeps at
least the **beslagvrije voet** — the protected minimum. `Loonbeslag`
models the order; the deduction folds into the payslip as a fourth
current-run, post-tax component — alongside sick pay, retro
adjustments, and leave buy/sell — never a fifth code path inside
`PayrollCalculator`, which stays pure and untouched.

## The floor is hard, by construction

The deduction is computed as:

```
deduction = min(orderedAmount, max(0, nettoPay − beslagvrijeVoet))
```

against `nettoPay` **after** every other current-run fold (sick-pay
substitution, retro-adjustment, leave-buy-sell) is already applied — by
construction it can never push net pay below the voet. A machine-checked
rule, `nl-loonbeslag-beslagvrije-voet-floor`, independently re-derives
this and flags any Payslip whose recorded `nettoPay` falls below its
referenced Loonbeslag's `beslagvrijeVoet` — catching a tampered or
otherwise inconsistent figure that the fold formula itself would
otherwise prevent.

## The beslagvrije voet is a stored input, not a computed figure

`Loonbeslag.beslagvrijeVoet` is a required, HR-entered field, trusted
as the authoritative figure the deurwaarder is legally required to
state on the garnishment order. Humaniq does **not** compute the protected
minimum from income and household composition per the Wet
vereenvoudiging beslagvrije voet (partner income, co-residents, housing
costs, health-insurance premium) — that computation is a named
fast-follow. The stored value is used as-is.

## Single-active-beslag is the MVP scope

Humaniq selects at most **one** `actief` Loonbeslag per employee per
period. Priority/preferente-vordering ordering across multiple
simultaneous garnishments for the same employee (BW art. 475d) is a
named fast-follow, not silently glossed over: `nl-loonbeslag-single-active`
flags any employee with more than one overlapping `actief` Loonbeslag.
Should selection ever encounter more than one active match despite the
check, the earliest `effectiveFrom` wins deterministically — never a
silent drop, never a double deduction.

## Admin/HR-only, guarded transitions

A garnishment order is legally sensitive — it exposes an employee's
debt situation, and a wrong floor computation is a labour-law
violation. `Loonbeslag.status` (`concept`/`actief`/`voldaan`/
`ingetrokken`) carries **no** bare lifecycle-button surface: activating,
settling, and withdrawing a garnishment are sensitive, caller-role-gated
writes through a guarded controller endpoint only.

## Machine-checked audit

```bash
occ humaniq:rules:audit
```

Reports both `nl-loonbeslag-beslagvrije-voet-floor` (the floor never
breached) and `nl-loonbeslag-single-active` (no overlapping active
garnishments per employee).

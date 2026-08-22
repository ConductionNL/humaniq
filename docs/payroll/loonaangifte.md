---
sidebar_position: 2
description: The wage-tax filing (loonaangifte) lifecycle, tijdvakcodes, and statutory deadlines.
---

# Loonaangifte filing

`LoonaangifteFiling` turns the Dutch wage-tax filing obligation into a real
workflow inside Humaniq: a declarative lifecycle, first-class tijdvakcode
data per Belastingdienst LH 210, statutory deadline derivation, and
deadline alerting — all as versioned machine-checkable corpus rules.
Digipoort wire transport (actually submitting the filing to the
Belastingdienst) is explicitly out of scope; Humaniq manages the filing
record and its lifecycle, not the transmission channel.

## The filing lifecycle

`LoonaangifteFiling` carries a declarative `x-openregister-lifecycle` on
its `status` field:

```
concept → klaargezet → bevestigd → verzonden
```

| Transition | From → to | Notes |
| --- | --- | --- |
| `klaarzetten` | `concept` → `klaargezet` | |
| `bevestigen` | `klaargezet` → `bevestigd` | |
| `verzenden` | `bevestigd` → `verzonden` | Stamps `submittedDate` and `verzondenDoor` on the carrying write |
| `heropenen` | `klaargezet` or `bevestigd` → `concept` | |
| `corrigeren` | `verzonden` → `concept` | The only way back once sent |

Illegal jumps — for example `verzenden` straight from `concept` — are
rejected by OpenRegister's lifecycle engine; there is no `concept →
verzonden` edge.

## Tijdvakcode, aangiftenummer, and response fields

Each filing carries:

- `tijdvakcode` — a 4-digit code matching pattern `6` or `7` followed by
  three digits (per Belastingdienst LH 210); malformed codes are rejected
  by schema validation
- `aangiftenummer`, `betalingskenmerk` — filing reference numbers
- `responseStatus` — `geen`, `ontvangen-ok`, or `afgekeurd`
- `responseMessage` — free-text response detail
- `verzondenDoor` — who executed the `verzenden` transition

## Machine-checked rules

Three rules in the payroll corpus (`lib/Standards/rules/payroll.json`,
domain `reporting`, framework `nl-loonheffingen`, sourced to Belastingdienst
LH 210 2026 / AWR art. 19) are enforced by `NlWageTaxFilingChecks`:

1. **`nl-loonaangifte-tijdvakcode`** — the tijdvakcode must match the
   filing's `period` + `tijdvak` per the 2026 code table (for example,
   period `2026-01` with tijdvak `maand` must carry tijdvakcode `6010`).
   The rule's `parameters` carry the full 2026 code table — the maand
   prefix rule (`60MM`), the thirteen vierweken codes, and the jaar code
   `6400` — so an annual re-issue is a data-only change, not a code
   change.
2. **`nl-loonaangifte-deadline-derivation`** — `deadline` must equal the
   last day of the calendar month following the period end, with **no**
   weekend or holiday extension (period `2026-01` derives deadline
   `2026-02-28`, even though that Saturday would normally roll to the
   following Monday in other contexts).
3. **`nl-loonaangifte-deadline-alert`** — a filing not yet `verzonden`
   violates when its deadline is in the past, or within 14 days of the
   audit run date.

Run the audit with:

```bash
occ humaniq:rules:audit --jurisdiction=NL
```

## Filing pages

The `LoonaangifteFilings` index lists `period`, `tijdvakcode`, `status`,
`deadline`, `submittedDate`, and `responseStatus`, sorted by deadline
ascending. `LoonaangifteFilingDetail` surfaces Status and Deadline in a
prominent widget and exposes the lifecycle actions — Klaarzetten,
Bevestigen, Verzenden, Heropenen, Corrigeren — the same pattern used
throughout Humaniq's approval-driven objects.

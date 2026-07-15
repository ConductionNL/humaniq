---
sidebar_position: 3
description: The Uniforme Pensioenaangifte (UPA) sector-pension filing lifecycle, gated on payroll-run approval.
---

# Pension filing (UPA)

`PensionFiling` gives HRMQ its sector-pension filing surface — the
Uniforme Pensioenaangifte (UPA) — one filing per period × fund, for the
APG-administered funds: ABP, SPW, bpfBOUW, Schoonmaak, PFAB, and PWRI.

UPA XML generation, APG wire delivery, and scheduled auto-dispatch are
explicitly out of scope; HRMQ manages the filing record and its
review/confirm/send lifecycle, gated on the payroll it derives from.

## The filing lifecycle

```
concept → gecontroleerd → bevestigd → verzonden
```

| Transition | From → to | Notes |
| --- | --- | --- |
| `controleren` | `concept` → `gecontroleerd` | Gated by `PayrollRunApprovedGuard` — see below |
| `bevestigen` | `gecontroleerd` → `bevestigd` | |
| `verzenden` | `bevestigd` → `verzonden` | Stamps `submittedDate` and `verzondenDoor` |
| `heropenen` | `gecontroleerd` or `bevestigd` → `concept` | |
| `corrigeren` | `verzonden` → `concept` | |

## Gated on payroll-run approval

The `controleren` transition is server-side gated by
`PayrollRunApprovedGuard`, which loads the `PayrollRun` referenced by the
filing's `payrollRunId` and allows the transition only when that run's
status is `approved`, `posted`, or `paid`. The guard fails closed: an
empty `payrollRunId`, a dangling reference, or a run still in any other
status all deny the transition — never allow-on-error. A filing on a
still-`draft` run simply cannot progress past `concept`.

## Machine-checked rules

Three rules under the `nl-pensioenaangifte` framework (`domain:
reporting`, `jurisdiction: NL`, sourced to SIVI's UPA standard) are
enforced by `NlPensionFilingChecks`:

1. **`nl-upa-payrollrun-approved`** — a filing violates when its
   `payrollRunId` doesn't resolve, or resolves to a run whose status
   isn't approved/posted/paid (mirrors the guard, fail-closed).
2. **`nl-upa-monthly-completeness`** — an NL payroll run in
   approved-or-later status violates when its period has **no**
   `PensionFiling` at all (an MVP fund-blind check — the full
   per-configured-fund obligation is recorded in the rule statement).
3. **`nl-upa-deadline-alert`** — a filing not yet `verzonden` violates
   when its deadline is in the past, or within 14 days of the audit run
   date.

```bash
occ hrmq:rules:audit --jurisdiction=NL
```

## Filing pages

`PensionFilings` ("Pensioenaangiftes") lists `period`, `fund`, `status`,
`deadline`, and `responseStatus`, filterable by fund and status, sorted by
deadline ascending — alongside `LoonaangifteFilings` in the
Loonadministratie menu. `PensionFilingDetail` shows Status and Deadline,
resolves the related `PayrollRun`, and exposes the lifecycle actions
Controleren / Bevestigen / Verzenden / Heropenen / Corrigeren.

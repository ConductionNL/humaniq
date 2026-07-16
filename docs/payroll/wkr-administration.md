---
sidebar_position: 13
description: Werkkostenregeling administration — the vrije-ruimte ledger, cross-object assessment, and eindheffing exposure alerting.
---

# Werkkostenregeling (WKR)

WKR is administered from the employer's ledger of allowances/benefits
for the **whole administration and fiscal year**, not from one payslip
in isolation: the vrije ruimte is a percentage of the entire fiscal
wage bill, and "used" sums every payslip's designated allowances plus
every stand-alone declaration.

## Recording a vergoeding/verstrekking

`WkrDeclaration` records one allowance or benefit against an
administration and year, with a `wkrCategory`:

- `gericht-vrijgesteld` — a statutory targeted exemption; excluded from
  the vrije-ruimte used-total entirely
- `vrije-ruimte` — consumes the vrije ruimte
- `eindheffing` — already designated eindheffingsloon; also excluded
  from the vrije-ruimte used-total

## The 2026 percentages

| Parameter | 2026 value |
| --- | --- |
| Tranche 1 (up to €400.000 loonsom) | 2,00% |
| Tranche 2 (above €400.000) | 1,18% |
| Eindheffing rate on the excess | 80% |

These are sourced, versioned table data — a future tax year is a
data-only re-issue with no PHP change.

## The assessment

`WkrService` rolls the cross-object fiscale loonsom (the sum of every
`Payslip.grossPay` for the administration and year — **never
recomputed payroll**) plus every `vrije-ruimte`-category declaration
into an idempotent `WkrAssessment` keyed `(administrationId, year)`:
available vrije ruimte from the tranche table, `excess = max(0, used −
available)`, `eindheffingDue = round2(excess × 80%)`, and a status
(`binnen-vrije-ruimte` or `eindheffing-verschuldigd`). Recomputing for
the same administration/year upserts in place.

## Exposure is machine-checked

`nl-wkr-eindheffing-exposure` reads the same cross-object aggregate and
flags any assessment where used exceeds available vrije ruimte **but
the assessment did not record the exposure** — an administration that
correctly flagged `eindheffing-verschuldigd` with the matching `excess`/
`eindheffingDue` is compliant; one that stayed `binnen-vrije-ruimte`
while actually over budget is a violation.

```bash
occ hrmq:rules:audit
occ hrmq:wkr:assess --administration ADM-001 --year 2026
occ hrmq:wkr:assess --all
```

## Pages

`WkrDeclarations`/`WkrDeclarationDetail` and `WkrAssessments`/
`WkrAssessmentDetail` live under the existing Loonadministratie menu —
no new top-level menu group. The assessment detail surfaces fiscale
loonsom, vrije ruimte, vrije-ruimte used, and eindheffing due as stat
KPIs, plus a "Beoordelen" action to recompute.

## Scope

This is administration, reporting, and exposure alerting only.
Automated eindheffing payment or filing is a named fast-follow.

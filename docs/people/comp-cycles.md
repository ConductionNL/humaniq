---
sidebar_position: 8
description: Compensation review cycles — salary bands, proposal/approval separation of duties, and effective-dated salary changes.
---

# Compensation cycles

Before this capability, a salary change was a bare hand-edit of
`Employee.grossMonthlySalary` — no band, no proposal trail, no
separation of duties, no effective-dating, no record of who proposed
what against which scale. Compensation cycles add the recurring
management ritual: a periodic review round where managers propose
adjustments against a band, a second person approves them, and the
approved figure lands on the employee's compensation on a chosen
effective date.

This lives as a personnel-dossier detail surface (`Medewerkers ›
Functie & comp`), not a tenth top-level menu.

## Salary bands

`SalaryBand` models the employer's internal min/reference/max structure
for a role or grade — optionally linked into the maintained
[CAO corpus](/docs/compliance/rule-engine). Bands are authored reference
data (not hand-typed per adjustment) and may not be created ad hoc from
the UI.

**External market-data benchmarking (positioning a band against
industry P25/P50/P75 salary-survey percentiles) is explicitly out of
scope** — it needs a licensed external feed that does not exist in the
fleet today.

## Review cycles and proposals

A `CompReviewCycle` groups a review round (a period, a default effective
date). It is a grouping container only — it does not carry an approval
lifecycle itself; a cycle may close while adjustments inside it are
still in any state.

Each proposed change is a `CompAdjustment` running a declarative
lifecycle:

```
draft → proposed → approved → effective
           ↕
         (reject → draft)
```

**Separation of duties**: `approve` reuses the same `NoSelfApprovalGuard`
used everywhere else in HRMQ — the approver may never be the proposer.

**Effective-dating is enforced, not just labelled**: `effectuate`
(`approved → effective`) is gated by a read-only guard that denies the
transition unless the adjustment's `effectiveDate` has actually
arrived. The salary write itself happens in an imperative service, not
the guard — the guard only decides *when*, never *what*.

## What happens on effectuation

For a due, approved adjustment, the service validates `proposedSalary`
is within the target band, writes it onto
`Employee.grossMonthlySalary` — the field the [payroll
engine](/docs/payroll/payroll-engine) reads — and drives the
`effectuate` transition. An out-of-band proposal is flagged by a
machine-checkable rule (vacuous when no target band is set); an
already-effective adjustment is a no-op on retry.

```bash
occ hrmq:comp:effectuate --cycle <cycleId>
occ hrmq:comp:effectuate --cycle <cycleId> --date 2026-07-01 --dry-run
```

The endpoint that backs the "Effectueren" detail-page action resolves
the target adjustment through the caller's ambient RBAC before any
write — an unauthorized or unknown adjustment id never reaches the
salary write.

## Non-goals

Market-data benchmarking (above), effective-dating an hourly wage onto
an `EmploymentContract`, and bulk/matrix comp-planning UI are named
fast-follows, not silently missing scope.

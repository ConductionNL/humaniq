---
sidebar_position: 3
description: Dossier-anchored performance reviews, review cycles, and the BW 7:669 dossiervorming audit rule.
---

# Performance reviews

Performance in HRMQ is **dossier-anchored** — there is no standalone
"performance" module and no 10th top-level menu entry. Per ADR-001 Rule
6, the review surface hangs off the personnel dossier (`EmployeeDetail`)
and the existing Personeel menu group.

## Review cycles: the container

`ReviewCycle` (jaargesprek / beoordeling / tussentijds) is a generic
container with a simple lifecycle:

```
concept → open → gesloten
```

There is no re-open edge — a closed cycle is history; corrections mean a
new cycle. `ReviewCycles` and `ReviewCycleDetail` live as sub-pages under
the existing Personeel group, listing the reviews that reference each
cycle. `ReviewCycle` is deliberately generic so a future compensation-cycle
capability can reference cycles instead of reinventing them.

## Reviews: one dossier, goals inside

`PerformanceReview` carries `rating`, `sterktes`, `ontwikkelpunten`,
`afspraken`, and a `goals` array — **inside** the review object. There is
no separate `Goal` entity anywhere in the register: goals freeze with the
dossier under one lifecycle, one audit trail, and one retention context.
A future OKR capability owns cross-cycle goal tracking.

```
concept → ingediend → besproken → vastgesteld
                          ↑___________|
                        (heropenen)
```

`vaststellen` requires the **same `NoSelfApprovalGuard`** already used on
Timesheets and Expenses, reused unchanged: the employee under review
cannot vaststellen their own beoordeling. `heropenen` returns a
`vastgesteld` review to `besproken` for correction — every path back to
`vastgesteld` passes the guard again.

## Machine-checked dossier completeness

A `vastgesteld` review without a `rating` or `afspraken` is no
ontslagdossier — BW art. 7:669 lid 3 sub d requires a documented,
substantiated basis for a disfunctioneren dismissal. HRMQ makes this a
machine-checkable, `recommended`-severity rule:

**`nl-performance-dossiervorming`** — a `vastgesteld` `PerformanceReview`
must carry a non-null `rating` and non-empty `afspraken`. Reviews in any
earlier status pass vacuously — an unfinished review legitimately lacks a
rating yet.

```bash
occ hrmq:rules:audit
```

## Where reviews show up

- `EmployeeDetail` gains a "Beoordelingen" row listing the employee's
  reviews, linking to `PerformanceReviewDetail`
- `PerformanceReviewDetail` (not a menu child — reached only from the
  dossier or `MijnBeoordelingen`) exposes exactly the lifecycle actions
  indienen / bespreken / vaststellen / heropenen
- `MijnBeoordelingen` under Mijn HR shows each employee their own
  reviews, via the same denormalized `userId` `@me` pattern as
  [Self-service](/docs/hr/self-service)

OKR / 9-box / kalibratie, compensation cycles, and career frameworks are
explicitly out of scope — separate future capabilities.

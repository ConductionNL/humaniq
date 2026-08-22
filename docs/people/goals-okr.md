---
sidebar_position: 9
description: Measurable OKR tracking — objectives with key results, tracked independently of and between formal review cycles.
---

# Goals & OKRs

[Performance reviews](/docs/people/performance) already carry a
lightweight, frozen, free-text `goals` list *inside* one review dossier
— the right shape for a jaargesprek afspraak, but unable to express a
measurable, independently-updatable target tracked between reviews.
Goals & OKRs is the cross-review-tracking sibling: an `Objective` with
several measurable `KeyResult`s, each carrying its own numeric
start/target/current value, optionally rolled up under a review cycle.

## Objectives and key results

An `Objective` is owned by an employee or a team, with a simple
lifecycle:

```
concept → actief → afgesloten
```

A closed objective is never reopened — a correction is a new objective,
the same no-resurrection precedent used elsewhere. The scored outcome
(`behaald`/`deels-behaald`/`niet-behaald`) is a **separate** field
stamped when the objective closes, kept apart from `status` exactly as
a performance review's rating is kept apart from its own status.

Each `KeyResult` is a **separate object referencing its Objective**
(not an array embedded inside it), so each one has its own audit trail:
a `unit` (`percentage`/`aantal`/`euro`/`boolean`), a required
`targetValue`, and `startValue`/`currentValue`.

An Objective can run entirely without a review cycle — `cycleId` is
optional.

## Progress is writer-maintained, on purpose

`progress` on both Objective and KeyResult is a plain stored number,
maintained by whoever updates it — **not** a declarative calculation.
The underlying arithmetic (a key result's `(current − start) / (target
− start) × 100`, and an objective's average across its key results)
needs division and cross-row aggregation, both outside the declarative
calculation vocabulary Humaniq's schema layer supports for same-object
arithmetic. The measurable source values (`startValue`/`targetValue`/
`currentValue`) are always stored regardless, so `progress` never
fabricates a number it can't be recomputed from — a server-computed
progress rollup is a named fast-follow, not a silent gap.

## Where it lives

No new top-level menu: an employee's personnel dossier gains a "Doelen
& OKR's" row, an Objective's detail page lists its own Key Results, and
a review cycle's detail page lists the objectives tied to it. Employees
also see their own objectives on a `MijnDoelen` self-service page.

## Non-goals

9-box/kalibratie grids, alignment/cascading objective trees, check-in
history, and weighting are explicitly out of scope for this MVP —
named fast-follows, tracked as the other half of a broader
performance-management-advanced capability.

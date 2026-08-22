---
sidebar_position: 6
description: Company property custody — laptops, phones, vehicles, tools — with a declarative lifecycle and audit-time integrity rules.
---

# Assets

`Asset` tracks company property — laptops, phones, vehicles, tools,
access passes, clothing — with a declarative custody lifecycle, and
`AssetAssignment` records effective-dated uitgiftes linking assets to
employees. Both live under the Onkosten (expenses) menu group.

## Asset custody lifecycle

```
beschikbaar → uitgegeven → ingenomen → beschikbaar
     ↓                          ↓
     └──────── afschrijven ─────┘
```

Four transitions, no guards: `uitgeven` (issue), `innemen` (return
pending processing), `vrijgeven` (back into stock), `afschrijven` (write
off — reachable from `beschikbaar` or `ingenomen`, terminal). An asset
currently `uitgegeven` cannot be written off directly — it must be taken
back in first.

Vehicles are modeled as `Asset` records with a `kenteken` (Dutch licence
plate) field; **fiscal bijtelling is an explicit non-goal** for this MVP
— the vehicle record carries no fiscal semantics.

## AssetAssignment: the uitgifte record

`AssetAssignment` is a plain effective-dated record — no lifecycle of its
own, the same pattern as `OrgAssignment`. `uitgifteDatum` is required;
`innameDatum` stays null while the asset is out.

## Two custody-integrity rules

Two machine-checkable rules (domain `labour`, framework
`hr-assets-core`) cross-check assignments against actual asset state:

1. **`nl-asset-assignment-consistency`** (severity `mandatory`) — flags an
   assignment whose `innameDatum` precedes its `uitgifteDatum`, and flags
   an **open** assignment (no `innameDatum`) whose referenced asset
   doesn't resolve, or resolves to an asset that isn't actually in status
   `uitgegeven`. A closed assignment referencing a re-stocked or
   written-off asset is not flagged — that's a legitimate history.
2. **`nl-asset-inname-bij-offboarding`** (severity `recommended`) — flags
   an open assignment for an employee whose
   [offboarding](/docs/people/onboarding-offboarding) case has a planned
   completion date already in the past — company property that should
   have come back. This rule is written defensively: it passes vacuously
   whenever no `Offboarding` data exists, so it never false-flags simply
   because the offboarding capability isn't present or hasn't run yet.

```bash
occ humaniq:rules:audit
```

## Pages

`Assets` lists `name`, `category`, `status`, `serienummer`, filterable
by category and status. `AssetDetail` exposes exactly the four lifecycle
transitions, resolves the related assignments, and carries a files widget
for signed uitgiftebonnen. `AssetAssignments` and `AssetAssignmentDetail`
show the uitgifte history with both ends — asset and employee — resolved
through the related widget.

Per-item barcode/CSV tooling, the `LeaseCarTaxRecord`/fiscal-bijtelling
follow-up, and GDPR-driven bulk asset-data deletion are explicitly out of
scope for this MVP.

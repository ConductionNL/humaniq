---
sidebar_position: 4
description: A self-referencing organisation hierarchy and effective-dated employee placements, with cycle-free and consistency checks.
---

# Org chart

Humaniq's organisational model is deliberately simple: a self-referencing
`OrgUnit` hierarchy and effective-dated `OrgAssignment` placements linking
employees to units. Neither schema carries a lifecycle — both are plain
records, no workflow.

## OrgUnit

`OrgUnit` — afdeling, team, or kostenplaats — has an optional
`parentUnitId` (self-referencing, null for roots), an optional
`costCenter`, an optional `managerId` pointing at an `Employee`, and an
`active` flag (default `true`).

## OrgAssignment

`OrgAssignment` links an `Employee` to an `OrgUnit` for a period:
`startDate` is required, `endDate` is nullable — null means the placement
is current and open-ended.

Both `OrgUnit.parentUnitId`/`managerId` and `OrgAssignment`'s two
references are canonical `$ref` relations, so they resolve through the
`related` widget on detail pages, and inbound relations surface as
filtered object-lists — child units and assignments on `OrgUnitDetail`,
an employee's assignments on `EmployeeDetail`.

## Two integrity rules

Two machine-checkable rules (domain `labour`, framework `hr-org-core`,
severity `mandatory`) audit the structure:

1. **`nl-org-assignment-consistency`** — flags an assignment whose
   `endDate` precedes its `startDate`, and flags an active assignment
   (no `endDate`, or `endDate` in the future) that references an
   `OrgUnit` with `active: false`. A **historical** assignment that ended
   before a unit was retired is not flagged — past placements may
   legitimately point at retired units.
2. **`nl-org-unit-cycle`** — walks each unit's `parentUnitId` chain and
   flags every unit in a cycle (including a unit parented to itself). A
   dangling parent reference simply ends the walk — a missing node is not
   a cycle.

```bash
occ humaniq:rules:audit
```

## Pages

`OrgUnits` and `OrgUnitDetail` (with child-unit and assignment lists),
plus `OrgAssignments` and `OrgAssignmentDetail`, live under the Personeel
menu group. `EmployeeDetail` lists each employee's assignments directly
on the personnel dossier.

The seeded example is a small Directie → Consultancy/Backoffice
hierarchy, including one deliberately inconsistent placement (an
`endDate` before its `startDate`) so the consistency rule has something
real to flag out of the box.

## Out of scope

The interactive D3 tree visualisation, org-chart exports, point-in-time
snapshots, and reorg tooling are explicitly out of scope for this MVP —
tracked as follow-up capabilities (`org-chart-visualization`,
snapshot materialisation).

---
kind: code
---

# The manager climb stays inside one administration

## Why

`manager-climbs-to-parent-unit` lets manager resolution walk up the unit tree past a unit the
employee manages themself. Every `OrgUnit` carries an `administrationId`, but nothing requires
a parent unit to be in the same administration. So the walk can reach a manager in another
administration.

That manager then gets the `managerUserId` stamp. The stamp grants read access to relations
cases and the decision on change requests. A manager in another administration has no part in
those records.

Ruben decided on 2 November 2026: stop at the administration boundary.

## What changes

- The walk never leaves the administration of the placement's unit. When that unit has no
  administration, the employee's own administration is the boundary.
- A unit above the placement unit in another administration ends the walk with no manager.
  So does a unit above it without an administration.
- Only when neither the placement unit nor the employee has an administration is there no
  boundary, so the walk climbs as before.
- No manager keeps the existing behaviour: no stamp, so the record shows in no team queue.
- The audit's OrgUnit and Employee indexes carry `administrationId`, so the
  `nl-mss-manager-consistency` audit stops where the stamp does.

## Out of scope

- Records stamped before this change keep their stamp until their next server-side write.
- Which units a manager leads (`UnitMembership::managedUnitIds`). That reads the tree
  downwards and is unchanged.

## Matrix rows

Rows: `ess-manager-team` (humaniq `openspec/parity/capabilities.json`).

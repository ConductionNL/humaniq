---
kind: code
---

# The approver belongs to the employee's administration

## Why

`manager-climb-stays-in-administration` stops the manager climb at a unit outside the
boundary administration. It compares units only. Two edges stay open:

- A unit in the employee's administration can name a manager whose own Employee record is in
  another administration. That manager still resolves.
- An employee placed directly in a unit of another administration resolves that unit's
  manager.

Both give the stamp to a manager outside the employee's administration. The stamp grants read
access to relations cases and the decision on change requests.

Ruben decided on 3 November 2026: block both edges. The approver must belong to the
employee's administration, judged by the manager's own Employee record as well as by the unit.

## What changes

- The boundary administration stays as it is: the placement unit's, or the employee's own
  when that unit has none.
- A placement in a unit of another administration than the employee's own resolves no manager.
  This applies only when both administrations are set.
- A manager whose own Employee `administrationId` differs from the boundary, or is empty,
  resolves no manager. The walk ends there, as at a unit in another administration. This
  holds for the placement unit's own manager and for a manager above it.
- Without a boundary, neither check applies.
- The `nl-mss-manager-consistency` audit follows the same rule. Its Employee index already
  carries `administrationId`.

## Out of scope

- Records stamped before this change keep their stamp until their next server-side write. A
  separate repair step restamps open records.

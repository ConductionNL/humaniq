---
kind: code
---

# Restamp the manager on open records

## Why

The `managerUserId` stamp on a change request, a relations case, a side activity and a
commuting arrangement is written when the record is created. Since
`manager-climbs-to-parent-unit`, `manager-climb-stays-in-administration` and
`approver-in-employee-administration`, the shared resolution gives a different manager in
three cases: a unit manager's own records, a climb out of the administration, and an
approver outside the employee's administration.

Records stamped before those changes keep the old stamp. A record stamped with the employee's
own account sits in a queue nobody may act on. A record stamped with a manager in another
administration gives that manager access.

Ruben decided on 3 November 2026: repair the stamp on open records only.

## What changes

- A repair pass reads change requests awaiting a decision (`ingediend`), relations cases that
  are not `afgesloten`, side activities that are `gemeld`, and commuting arrangements and
  travel claims that are `submitted`.
- It compares each record's stamp with what the shared resolution gives for the record's own
  date: the case's `openedOn`, the arrangement's `startDate`, the claim's `expenseDate`, and
  otherwise the date the record was created.
- It writes the resolved manager, or null when none resolves, only when the stamp differs. A
  record without a stamp is left alone.
- Each change is logged with the record id, the old stamp and the new one. A second run
  changes nothing.
- The pass runs as a repair step after `MigrateHoursProcess`, on every upgrade and every
  `occ maintenance:repair`.
- `occ humaniq:manager:restamp --dry-run` prints per schema how many records it would change,
  without writing. Without `--dry-run` it writes.

## Out of scope

- Decided and closed records keep their stamp: it records who was the manager then.
- Timesheets: `MigrateHoursProcess` already recomputes their stamp.

---
kind: code
---

# Restamp the manager on open records, and stamp expense claims like hours

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

Ruben decided on 3 November 2026: repair the stamp on open records only, once.

Expense claims have no server-side stamp at all. The schema calls `Expense.managerUserId`
"HR-populated", and nothing on the server writes it. Ruben decided on 3 November 2026 that
expense claims are approved the same way as hours. The term is broader than travel: an
employee can claim other costs too, when the employer allows them.

## What changes

- An expense claim of any category gets its approver the way a timesheet does. A pre-save
  listener stamps `managerUserId` through the same resolver as hours, for the claim's
  `expenseDate`, else today. It also copies `userId` and `administrationId` from the
  employee. Client input to the three is ignored.
- The stamp is renewed on every write of an open claim, so a submit and a resubmit after a
  rejection go to today's manager. Approved and reimbursed claims keep their stamp.
  humaniq's own writes pass untouched.
- A repair pass reads change requests awaiting a decision (`ingediend`), relations cases that
  are not `afgesloten`, side activities that are `gemeld`, and commuting arrangements and
  expense claims of every category that are `submitted`.
- It compares each record's stamp with what the shared resolution gives for the record's own
  date: the case's `openedOn`, the arrangement's `startDate`, the claim's `expenseDate`, and
  otherwise the date the record was created.
- It writes the resolved manager, or null when none resolves, only when the stamp differs. A
  record without a stamp is left alone.
- It reads every page of every schema, and reads the org lookups once per run. When a lookup
  read was cut off, it never clears a stamp to null.
- A record that fails is logged with its schema, id and error, and counted as failed. The
  pass carries on with the next record.
- Each change is logged with the record id, the old stamp and the new one. A dry run logs
  what would change, in its own words. A second run changes nothing.
- The pass runs as a repair step after `MigrateHoursProcess`. After a pass with no failures
  it stores a done marker in the app config, and later upgrades skip it.
- `occ humaniq:manager:restamp --dry-run` prints per schema how many records it would change,
  without writing. Without `--dry-run` it writes. The command runs whatever the marker says,
  and exits 1 when a record failed.

## Out of scope

- Decided and closed records keep their stamp: it records who was the manager then.
- Timesheets: `MigrateHoursProcess` already recomputes their stamp.
- Leave requests: their stamp stays HR-populated until their own process redesign.
- Which categories an employer allows: the schema's category list is the allowed set today.
  A setting that narrows it is follow-up work.

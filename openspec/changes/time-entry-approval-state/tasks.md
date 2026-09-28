## 1. Properties

- [ ] 1.1 Add `approvalState` and `approvedAt` to `TimeEntry` in `lib/Settings/register.d/hr-timesheet.json`, inert to client input, `approvalState` filterable. Verify: `npm run check:schema-l10n` exits 0; a unit test that a client-sent `approvalState` is overwritten.

## 2. Stamping

- [ ] 2.1 `TimeEntryStampListener::stamp()` sets `approvalState` from the parent timesheet. Verify: unit test for a new entry on a `draft` and on a `rejected` timesheet.
- [ ] 2.2 `TimesheetEntryStateListener` carries every status edge to the entries under `InternalWriteMarker`, registered for the `Timesheet` schema. Verify: unit tests for submit, approve (with `approvedAt`), reject and reopen, and that the aggregate listener does not recompute.
- [ ] 2.3 Repair step that re-stamps every entry from its timesheet. Verify: unit test with a drifted entry.

## 3. Verification

- [ ] 3.1 Live: approve a seeded timesheet and read `TimeEntry?billable=true&approvalState=approved&projectId=...` through the objects API; the two billable entries come back. Record the call in the PR body.
- [ ] 3.2 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16), `openspec validate time-entry-approval-state --strict`, `composer check:strict` once before push.

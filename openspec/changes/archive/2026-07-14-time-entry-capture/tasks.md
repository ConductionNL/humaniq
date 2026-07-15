# Tasks — time-entry-capture

- [x] 1. Verify-first: confirm at HEAD that the `Timesheet` schema, its `x-openregister-lifecycle` (submit→approve/reject/reopen) and `NoSelfApprovalGuard` already exist, and that hrmq emits no approval event (no `WebhookService`/`dispatchEvent`/`nl.conduction` under `hrmq/lib`) — record in design.md (REQ-TEC-001)
- [x] 2. Add `lib/Service/TimeEntryEventService.php` — CloudEvents 1.0 builder + fire-and-forget dispatch via OpenRegister `WebhookService`, with the schema gate (`timesheet`), the approval-edge gate (`isApprovalTransition`, idempotent) and the payload contract (REQ-TEC-002, REQ-TEC-003; design D1/D2/D5)
- [x] 3. Add `lib/Listener/TimesheetApprovalListener.php` — thin `ObjectUpdatedEvent` adapter: resolve schema slug via `SchemaMapper`, extract old/new `getObject()` arrays, delegate to the service, swallow every `Throwable` (REQ-TEC-002; design D3/D4)
- [x] 4. Register the listener on `ObjectUpdatedEvent` in `lib/AppInfo/Application.php` (REQ-TEC-002)
- [x] 5. Unit tests `tests/Unit/Service/TimeEntryEventServiceTest.php` — submitted→approved emits one event with hours/project/billable; draft→submitted emits nothing; already-approved does not re-emit; non-Timesheet schema does not emit; `isApprovalTransition` edge table; CloudEvents 1.0 envelope + `time` fallback (REQ-TEC-002, REQ-TEC-003)
- [x] 6. Quality gates: `composer lint` green on the new files; full PHPUnit suite green in `oc-phpunit-83:local` (589 tests, was 583 — +6, no regressions); SPDX + `@spec` tags on new PHP; no `x-openregister-notifications` introduced (gate-18)
- [x] 7. Name the shillinq consume-side follow-up in the proposal (register a listener for `nl.conduction.hrmq.timeentry.approved`, project onto invoice-from-time / WBSO, closing the dangling `bookkeeping-time-tracking` dep) — do NOT modify shillinq here

Acceptance criteria (plain reminders, not tasks):
- capture + approval are NOT rebuilt — only the approval event is added
- the emit is bound to the approval edge (idempotent), fire-and-forget, and never breaks the save
- shillinq is not modified by this change

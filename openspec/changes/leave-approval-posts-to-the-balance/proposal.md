---
kind: code
---

## Why

Approving leave does not move the leave balance. `LeaveBalance.usedHours` is written in exactly one
place, `LeaveAccrualJob.php:262`, and the value it writes is `0.0`. Nothing else in the app ever
writes it. There is no listener on `LeaveRequest` crossing into `approved`.

Timesheets already have this wiring. `TimesheetApprovalListener` watches `ObjectUpdatedEvent`,
detects the transition into `approved`, and delegates the consequence to a service. Leave has the
same lifecycle, the same `NoSelfApprovalGuard`, the same four pages, and no listener.

Three things follow from it:

1. `remainingHours` is a declarative `x-openregister-calculations` field over
   `entitledHours + bovenwettelijkHours - usedHours`. The arithmetic is correct and the input is
   permanently zero, so every employee sees their full entitlement forever, however much leave they
   have taken and had approved.
2. Three rule checks read `usedHours` and can therefore never fire: `nl-verlof-saldo-niet-negatief`
   (`NlLeaveChecks.php:222`), `nl-verlof-vervaltermijn`, and the offboarding payout check
   (`NlOffboardingChecks.php:153`).
3. That last one costs money. Final settlement pays out
   `max(0, entitledHours + bovenwettelijkHours - usedHours)` for every leaver. With `usedHours` at
   zero it pays the full annual entitlement, ignoring the leave the employee already took.

`openspec/specs/leave-accrual-job/spec.md` says as much at line 87: accrual "never touches
`usedHours`", so `nl-verlof-saldo-niet-negatief` "SHALL never be" violated. The gap is recorded and
nothing acts on it.

## What changes

A `LeaveApprovalListener` on `ObjectCreatedEvent` and `ObjectUpdatedEvent` resolves the changed
object's schema slug and, for a `LeaveRequest`, hands the row to a new
`LeaveBalanceProjectionService`. The service recomputes `LeaveBalance.usedHours` as the sum of
approved requests for that employee, year and leave type.

Recompute, not increment. An increment drifts permanently when an event is missed or replayed, and
it cannot repair the balances that are already wrong. A recompute is idempotent: editing a request,
approving it twice, or moving it back out of `approved` all land on the same answer, and every
balance corrects itself the first time any request touching it changes.

`LeaveRequest.hours` is optional, so a multi-day request often carries only `startDate` and
`endDate`. When `hours` is empty the service counts the Monday to Friday days in the range and
multiplies by `contractHoursPerWeek / 5`, using the snapshot the balance already stores. Public
holidays are not subtracted, which overstates usage in a week containing one. That is the safer
direction to be wrong in, and the schema description says so.

## What does not change

`LeaveTransaction` is not touched. Despite the name it is the buy and sell schema: it requires
`hourlyRate`, carries `settledAmount` and `settlementPayrollRunId`, and models a payroll
settlement rather than leave taken. Posting a leave request through it would mean inventing an
hourly rate. `LeaveRequest` is already the audit trail for leave taken, so the ledger this needs
exists.

`LeaveBuySellSettlementService` keeps writing `bovenwettelijkHours` and only
`bovenwettelijkHours`. This change adds a writer for `usedHours` and leaves the buy and sell path
alone.

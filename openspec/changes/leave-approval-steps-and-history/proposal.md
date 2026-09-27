---
kind: code
---

# A second approval step for leave, and who decided when

## Why

A leave request in humaniq has one approver. The manager presses `Approve` and the request is
approved, whatever kind of leave it is. An organisation that wants HR to confirm unpaid
leave, parental leave or anything longer than two weeks has no way to say so: HR finds out
afterwards, if at all.

The request also forgets who decided. `LeaveRequest` has `approvedBy` and `approvedAt`, shown
read-only on `LeaveRequestDetail`, but nothing ever fills them. The timesheet workflow stamps
the same fields server-side in `TimesheetProcessStampListener`; the leave workflow has no such
listener, so both fields stay empty and the only trace is the generic audit history in the
sidebar. An employee asking "who rejected this?" gets an empty field.

This change stamps every decision on the request itself, keeps each step's decision in order,
and adds an optional HR step after the manager for the leave types that need one.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `dm-leave-multi-step-approval` | Have HR approve a leave request as a second step after the manager. | `no`: one `approve` transition by a manager |
| `dm-leave-approval-history` | See who approved or rejected a leave request and when. | `partial`, built: `approvedBy` exists but nothing stamps it; the audit trail sidebar records status changes |

### Demand

- `dm-leave-multi-step-approval`, feature request: https://github.com/orangehrm/orangehrm/issues/1676
- `dm-leave-approval-history`, feature request: https://github.com/orangehrm/orangehrm/issues/1770

### Competitors rated yes

- `dm-leave-multi-step-approval`, Personio: "If your approval process has multiple steps,
  they trigger in the order you set. Approvers only receive tasks after the previous step is
  approved"
  (https://support.personio.de/hc/en-us/articles/26385271786653-Set-up-time-off-approval-rules).
- `dm-leave-approval-history`, AFAS Profit: "In de workflowhistorie zie je een chronologisch
  overzicht van alle acties die bij een dossieritem zijn uitgevoerd"
  (https://help.afas.nl/help/NL/SE/Ins_Wrkflw_Hystry.htm).
- `dm-leave-approval-history`, HR2day: for a verlofboeking the approver shows in the progress
  bar and "in de goedkeuringshistorie"
  (https://data.maglr.com/1697/issues/49954/605654/index.html).
- `dm-leave-approval-history`, Loket.nl: the leave request "returns submittedBy,
  submittedOn, leaveRequestStatus, handledBy, handledTime and commentHandler"
  (https://developer.loket.nl/ApiDocs).
- `dm-leave-approval-history`, Personio: shows "who requested the time off and who approved
  it" and "the approval timeline including assignees and timestamps"
  (https://support.personio.de/hc/en-us/articles/360006849378-Request-time-off).

### Recorded follow-ups this change picks up

- `2026-07-13-mss-team-scope` keeps `NoSelfApprovalGuard` semantics untouched and grants
  nothing through `managerUserId`. This change keeps both: the new guard delegates to
  `NoSelfApprovalGuard` first, the `LeaveBuySellApprovalGuard` shape.
- No recorded non-goal names a second approval step or the stamping of `approvedBy` on leave.

## What Changes

- **Decisions are stamped by the server.** A pre-save listener on `LeaveRequest`, the
  `TimesheetProcessStampListener` shape, makes `submittedAt`, `approvedBy`, `approvedAt` and
  the new `decisions` list inert to client input, and stamps them on each status edge from
  the signed-in user and the clock.
- **Every step is kept.** `decisions` holds one entry per decision, in order: the step
  (manager or HR), approved or rejected, by whom, when and the remark. A request rejected and
  resubmitted keeps its earlier decisions. `LeaveRequestDetail` shows them as a timeline.
- **An HR step where the leave type asks for one.** `LeaveType` gains `requiresHrApproval`
  and `hrApprovalAboveHours`. For such a request the manager's approval moves it to a new
  status, `manager-approved`, and HR's approval makes it `approved`. A request that needs no
  HR step is approved by the manager as today.
- **A queue for HR.** A `HR-verlofgoedkeuring` page lists requests at `manager-approved`.
  Only approved requests draw from the balance, as now.

## Capabilities

### New Capabilities

- `leave-approval-steps`: server-stamped decisions with an ordered history per leave request,
  and an optional HR approval step after the manager.

## Impact

- `lib/Settings/register.d/hr-leave.json`: `LeaveRequest` 0.5.0 gains `decisions` and the
  `manager-approved` status, an `approve-manager` transition, and `approve` and `reject` from
  `manager-approved`.
- `lib/Settings/register.d/hr-leave-types.json`: `LeaveType` gains `requiresHrApproval` and
  `hrApprovalAboveHours`.
- `lib/Listener/LeaveRequestProcessStampListener.php` (new), registered in
  `lib/AppInfo/Application.php`.
- `lib/Lifecycle/LeaveApprovalStepGuard.php` (new), delegating to `NoSelfApprovalGuard`.
- `src/manifest.d/hr-leave.json`: a decisions timeline on `LeaveRequestDetail`, the extra
  lifecycle actions; a `HR-verlofgoedkeuring` page and menu entry.

## Out of scope

- Approval chains longer than two steps, or chosen per org unit.
- Deputy approvers when a manager is away. That is `self-service-approvals-inbox`.
- Notifying the next approver. `platform-notifications` owns notification rules.

---
sidebar_position: 20
description: The events humaniq sends when someone joins, leaves or changes job, when leave is approved or withdrawn, and when sickness is reported or ends.
---

# HR events for other systems

When someone joins, leaves or changes job, other systems need to know. An identity manager creates or blocks an account. A badge system follows. A rostering tool plans around an absence.

humaniq sends an event for each of these moments. You subscribe an endpoint in OpenRegister's webhook settings, per event type. humaniq has no receiver and no subscription list of its own.

## The events

| Event type | Sent when |
|---|---|
| `nl.conduction.hrmq.employee.joined` | A first contract is created for someone without earlier contracts, or an onboarding case is completed. |
| `nl.conduction.hrmq.employee.left` | An offboarding case is completed, or the last contract gets an end date in the past. |
| `nl.conduction.hrmq.employee.jobchanged` | A contract's job profile changes, or a new placement is added for someone who already had one. |
| `nl.conduction.hrmq.leave.approved` | A leave request is approved. |
| `nl.conduction.hrmq.leave.withdrawn` | An approved leave request is no longer approved. |
| `nl.conduction.hrmq.sickness.reported` | A sickness case is registered. |
| `nl.conduction.hrmq.sickness.recovered` | A sickness case is closed as recovered. |

Each event goes out once, on the change that causes it. Saving a record again sends nothing.

## What an event carries

Every event is a CloudEvent 1.0. Its `subject` is the employee id. Its `data` holds:

- `employeeId`, `nextcloudUserId` and `administrationId`;
- `occurredOn`, the day of the moment;
- the moment's own dates:

| Event | Extra fields |
|---|---|
| `employee.joined` | `startDate`, `orgUnitId` |
| `employee.left` | `lastWorkingDay` |
| `employee.jobchanged` | `from` and `to`, each with `orgUnitId` and `normfunctieId` |
| `leave.approved`, `leave.withdrawn` | `startDate`, `endDate`, `hours` |
| `sickness.reported`, `sickness.recovered` | `from`, `to` |

Nothing else. A leave event never says which kind of leave or why. A sickness event never holds a reason, a percentage or a note. No event holds salary, a BSN or medical data. A system that needs more reads the record through the OpenRegister API, under its own rights.

Joining can be signalled twice: by the first contract and by the completed onboarding case. Both events carry the same `id` when they name the same day, so a receiver can drop the second.

## Inside Nextcloud

Another Nextcloud app can listen without a webhook. humaniq dispatches a typed event on the same moment: `EmployeeJoinedEvent`, `EmployeeLeftEvent`, `EmployeeJobChangedEvent`, `LeaveApprovedEvent` (with `isWithdrawn()`) and `SicknessReportedEvent` (with `isRecovered()`), all in `OCA\Humaniq\Event`. Each carries the same id and fields as the CloudEvent.

Delivery is best effort. If the typed event fails, the webhook is still sent, and the other way round.

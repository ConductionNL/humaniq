---
kind: code
---

# Events for joining, leaving, job change, leave and sickness

## Why

When someone joins, leaves, changes job, takes leave or reports sick, other systems need to
know: the identity manager creates or blocks an account, the access badge system follows,
the learning platform enrols, the rostering tool plans around an absence. humaniq sends one
event to the outside world today, `nl.conduction.hrmq.timeentry.approved`, for finance. The
five HR moments a municipal tender asks for by name produce nothing, so every connected
system has to poll humaniq or be told by hand. `2026-09-07-hris-api-public` named webhooks a
change that "would need its own design"; this is that design.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `td-event-webhooks` | Send an event to other systems when someone joins, leaves, changes job, takes leave or reports sick. | `partial`: only the timesheet-approved CloudEvent exists |

### Demand

- `td-event-webhooks`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415227
  (Sudwest-Fryslan W0.21, signalen via events of webhooks bij indienst, uitdienst,
  functiewijziging, verlof, verzuim).

### Competitors rated yes

- `td-event-webhooks`, AFAS Profit: "Profit sends real-time webhooks when a dossier item is
  added, changed or deleted and when a workflow reaches a task or action; joining, leaving,
  job changes, leave and sick reports all run as such workflows"
  (https://help.afas.nl/help/NL/SE/140869.htm).
- `td-event-webhooks`, HR2day: "HR2day publishes insert, update and delete messages for
  employee, employment, leave, sick leave and department to an endpoint you choose"
  (https://hr2day-6087.my.site.com/hr2daydeveloper/s/event-based-api).
- `td-event-webhooks`, Personio: "webhooks for Person.created, updated and deleted,
  Employment.created, updated and deleted (including terminations) and absence events"
  (https://developer.personio.de/docs/event-driven-data-from-personio).

## What Changes

- **Five domain events as CloudEvents.** humaniq emits, through OpenRegister's
  `WebhookService` like the existing time-entry event:
  - `nl.conduction.hrmq.employee.joined`: a first employment contract starts, or an
    onboarding case is completed;
  - `nl.conduction.hrmq.employee.left`: an offboarding case is completed, or the employee's
    last contract ends;
  - `nl.conduction.hrmq.employee.jobchanged`: a contract's function or the employee's org
    placement changes;
  - `nl.conduction.hrmq.leave.approved`, and `leave.withdrawn` when an approved request is
    no longer approved;
  - `nl.conduction.hrmq.sickness.reported` and `sickness.recovered`.
- **Minimal payloads.** Each event carries the employee id, the Nextcloud user id, the
  administration, the dates and, for a job change, the old and new unit and function. A
  sickness event never carries a reason or percentage beyond "absent from, to"; a leave event
  never carries the leave type (the `leave-calendar-nc` AVG boundary).
- **Typed events inside Nextcloud.** The same moments are dispatched as typed Nextcloud events
  (the ADR-041 recipe `TimesheetApprovedEvent` follows), so a sibling app can listen without an
  HTTP receiver.
- **Subscribing is configuration.** An administrator subscribes an endpoint to an event type
  in OpenRegister's webhook settings; humaniq ships no receiver and no subscription store.

## Capabilities

### New Capabilities

- `hr-lifecycle-events`: CloudEvents and typed events for joining, leaving, job change, leave
  and sickness, with data-minimised payloads.

## Impact

- `lib/Service/HrLifecycleEventService.php` (new), sharing the envelope builder with
  `lib/Service/TimeEntryEventService.php`.
- `lib/Listener/HrLifecycleEventListener.php` (new) on OpenRegister's object events for
  `EmploymentContract`, `Onboarding`, `Offboarding`, `OrgAssignment`, `LeaveRequest`,
  `SickLeaveCase`; registration in `lib/AppInfo/Application.php`.
- `lib/Event/*Event.php` (new, five typed events).
- `docs/` event catalogue page listing the types and payloads.

## Out of scope

- A humaniq webhook admin page. OpenRegister's webhook settings are where endpoints are set.
- Guaranteed delivery and replay. `WebhookService` retries; humaniq stays fire-and-forget as
  for the time-entry event.

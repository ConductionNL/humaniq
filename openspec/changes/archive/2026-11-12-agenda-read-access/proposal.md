---
kind: code
---

# The agenda shows each person only to the people allowed to see it

## Why

`GET /api/agenda` answers for any signed-in user and any subject. An employee's agenda
holds sickness markers, interviews, secondments with their hours per week, bookings and
busy time. The spec says absences are "read by anyone", which no longer matches who
should see them.

Ruben decided on 2 November 2026 who may read an agenda.

## What changes

- An employee's agenda opens for the employee, their managers, HR and colleagues placed
  in the same org unit today. Everyone else gets 403.
- An org unit's agenda opens for its members, its manager and HR. Everyone else gets 403.
- A colleague sees the agenda without interview entries and without the secondment's
  `hoursPerWeek`. The employee, their managers and HR see both.
- The viewer is the session user. Managers come from the shared org resolution
  (`OrgResolutionService::resolveManagerUserIds`), HR from `HumaniqRoles`, placements from
  `OrgAssignment` active today. Nothing is read from the request.
- The check runs before any planned item is read, so a refused request reads no
  interview, secondment, sick leave, roster or booking row.
- A resource's agenda stays open to every signed-in user, as before.

## Impact

- `AgendaController::agenda()` answers 403 with a short Dutch reason.
- New `AgendaReadAccess` service; the controller's unit-member lookup moves into it.
- The agenda leaf (`CnAgendaWidget`) shows its general read error on a 403. A follow-up
  in the frontend can say why.

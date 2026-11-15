# Tasks

### Task 1: The week per person
- **spec_ref**: `openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person`
- [x] `HoursRegisterGateway::findFiltered(..., asCaller: true)` reads with OpenRegister's access rules
- [x] `ObjectWeekHours::forObject` and `GET /api/time-entries/week` (`ObjectHoursController::week`), session checked first
- [x] `CnHoursWidget`: week header, rows with bars, inline form

### Task 2: The host's title
- **spec_ref**: `openspec/changes/hours-per-person-week/specs/hours-leaf/spec.md#requirement-the-hours-surface-takes-its-hosts-title`
- [x] `CnHoursWidget` `title` prop, "Hours booked" without one

---
kind: code
---

# Availability and capacity follow the agenda's access rule

## Why

`agenda-read-access` decided who may read an agenda. The availability and capacity
answers show the same people's free and planned hours, and were still open to every
signed-in user. A unit's agenda opened only for its own manager, so the manager of a
department above it, and a deputy standing in for a manager, could not plan the unit.

Ruben decided on 2 November 2026:

- availability and capacity follow the agenda's rule, and a unit's capacity follows the
  unit agenda;
- a unit's agenda, and its capacity, also open for the managers of the units above it
  and for a registered deputy of a manager who has access.

Review of `agenda-read-access` found three gaps, fixed here: an agenda opened for a
subject with no Employee record through an orphan placement, a placement in a unit
nobody registered made two people colleagues, and a placement with an unreadable date
counted as active.

## What changes

- `GET /api/availability` and `GET /api/capacity` answer 403 to a caller who may not
  read the unit's agenda. Without a unit, HR gets everybody and anyone else the
  employees whose agenda they may read; a caller left with nobody gets 403.
- A unit's agenda opens for the managers of the units above it, and for a deputy
  standing in today (`ManagerDeputies::managersCoveredBy`) for a manager with access.
- An employee agenda without an Employee record opens for HR only. A placement in an
  unregistered unit, or with an unreadable start or end date, does not count.
- A refused resource agenda says why, like the other refusals.

## Impact

- `AgendaController::availability()` and `capacity()` can answer 403.
- New `AgendaOrgView`, one request's view of the organisation; `AgendaReadAccess`
  gains `employeeScope()` and the deputies lookup.

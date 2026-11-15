# hours-per-person-week Delta

**Status**: proposed
**Scope**: humaniq

## ADDED Requirements

### Requirement: The hours surface shows the week per person

`GET /api/time-entries/week` SHALL answer, for a signed-in caller, the ISO week holding `date` (`YYYY-MM-DD`, today when left out) on the object named by `domainObjectType` and `domainObjectRef`: `week`, `weekStart`, `weekEnd`, `total` and `people`, each person with `userId`, `displayName`, `team` and `hours`, most hours first. It SHALL count only the time entries OpenRegister lets the caller read, only those dated in the week and only those with numeric hours. Without a session it SHALL answer 401, without the object or with a date that is not a day 400, both before anything is read, and on a failed read 500, never a zero. The hours surface SHALL draw the week header, one row per person with a bar, and an inline booking form.

#### Scenario: a week on a case
- **GIVEN** time entries on case-1 in week 41 of 2026: Pieter 4 and 2.5 hours, Lars 3, Marloes 2, and entries outside the week or without hours
- **WHEN** a signed-in caller who may read them asks for the week of 8 October
- **THEN** the answer is week 41, 5 to 11 October, 11.5 hours, Pieter 6.5, Lars 3, Marloes 2, each with a name and the team of their active placement

#### Scenario: refused before reading
- **WHEN** a request has no session, names no object, or names a day that is not a day
- **THEN** it is refused and no entry is read

### Requirement: The hours surface takes its host's title

`CnHoursWidget` SHALL title its card with the `title` its host passes and SHALL fall back to "Hours booked" without one.

#### Scenario: dossiq titles the card
- **GIVEN** dossiq places the leaf with the title "Record hours"
- **THEN** the card reads "Uren registreren" in Dutch, not "Geboekte uren"

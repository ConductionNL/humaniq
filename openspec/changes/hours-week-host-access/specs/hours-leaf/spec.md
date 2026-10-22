# hours-week-host-access Delta

**Status**: proposed
**Scope**: humaniq

## ADDED Requirements

### Requirement: The week is answered only to a reader of the object

`GET /api/time-entries/week` SHALL answer 404 to a signed-in caller who may not read the host object named by `domainObjectType` and `domainObjectRef`, as OpenRegister decides with the caller's access rules and tenancy, and SHALL read no time entry for that caller. A type that is not `<register>:<schema>`, or an OpenRegister that cannot answer, SHALL be treated as not readable. For a reader it SHALL count every time entry on the object in the week, however many the object holds.

#### Scenario: a user who cannot open the case
- **GIVEN** a case the caller may not read
- **WHEN** they ask for the week's hours on it
- **THEN** the answer is 404 and no entry is read

#### Scenario: a busy object
- **GIVEN** 1,203 time entries on one object
- **WHEN** a reader's week is read
- **THEN** every entry is read, not the first 500

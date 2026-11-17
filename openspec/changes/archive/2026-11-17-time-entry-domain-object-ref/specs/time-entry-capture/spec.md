# time-entry-capture (delta)

## ADDED Requirements

### Requirement: TimeEntry declares the host object it was booked on (REQ-TEC-006)

The shipped register SHALL declare on `TimeEntry` the optional properties
`domainObjectType` (string, pattern `^[a-z0-9-]+:[a-zA-Z0-9-]+$`, the
`<app>:<schema>` literal) and `domainObjectRef` (string, uuid), both
indexed, with English titles and Dutch labels through l10n only. An entry
without them SHALL remain valid.

#### Scenario: A filter on the pair finds the case's entries

- **GIVEN** a fresh instance with the shipped register imported and two entries carrying `dossiq:case` and one case uuid
- **WHEN** `TimeEntry` is filtered on that `domainObjectType` and `domainObjectRef`
- **THEN** both entries are returned and the filter runs on the index

#### Scenario: A wrongly cased type is refused

- **GIVEN** a write with `domainObjectType = Dossiq:Case`
- **WHEN** OpenRegister validates it
- **THEN** the write is refused with a structured validation error

@e2e exclude register import and schema validation have no browser surface; covered by PHPUnit against the imported register

### Requirement: The booking surfaces carry the pair from the leaf (REQ-TEC-007)

The booking dialog and the timer SHALL accept `domainObjectType` and
`domainObjectRef` as seed values and write them on the entry unchanged. An
entry that carries them SHALL show them read-only. Humaniq's own timesheet
page SHALL NOT ask a worker to type them.

#### Scenario: Hours booked from a case carry the case

- **GIVEN** the `humaniq-hours` leaf opens the booking dialog on a case
- **WHEN** the worker saves two hours
- **THEN** the entry carries the case's `domainObjectType` and `domainObjectRef` and the leaf's total rises by two

e2e: `tests/e2e/hours-leaf.spec.ts`

### Requirement: The mock register cannot declare what the shipped one lacks (REQ-TEC-008)

A unit test SHALL assert that every property the mock register declares on
`TimeEntry` exists in the shipped register. The test SHALL run in the normal
PHPUnit suite.

#### Scenario: A property added only to the mock fails the suite

- **GIVEN** the mock register declares a `TimeEntry` property the shipped register does not
- **WHEN** PHPUnit runs
- **THEN** `RegisterParityTest` fails naming the property

@e2e exclude a unit-level guard on two JSON files; no UI

# employer-hourly-cost-rate — delta for humaniq-hours-process-redesign

## ADDED Requirements

### Requirement: Cost-allocation references live on the time entry and are never employee-typed

The ADR-081 cost-allocation reference properties (`domainObjectRef`, `domainObjectType`,
`allocationKey`), currently deep-merge-extended onto `Timesheet` by
`lib/Settings/register.d/hr-cost-rate.json`, SHALL move to the **`TimeEntry`** schema in that
same extension file — allocation describes what a specific booking was worked on, which is
per-entry, not per-period. None of the three SHALL ever appear on an employee-facing or HR
create/edit form: `allocationKey` is an opaque ledger dimension written by the consuming
bookkeeping integration (shillinq domain), and the domain-object pair is written by the
originating domain app. `costCenter` on a TimeEntry SHALL be a derived value (the employee's
active `OrgAssignment` unit's `OrgUnit.costCenter`), stamped server-side and never hand-typed;
`projectId` remains the only allocation-adjacent field an employee may choose on the booking
form. The Timesheet-level `projectId`/`costCenter` denormalized aggregates exist solely for the
approved-time-entry event contract (time-entry-capture REQ-TEC-003) and are server-recomputed.

#### Scenario: The booking form offers no ledger fields

- **GIVEN** the `MijnUren` create dialog
- **WHEN** an employee books hours
- **THEN** the form offers `projectId` but neither `costCenter`, `allocationKey`,
  `domainObjectRef` nor `domainObjectType`

#### Scenario: Cost centre derives from the org structure

@e2e exclude Derivation is a backend write-path behaviour verified by TimeEntryStampListener + OrgResolutionService unit tests; the UI shows the derived value read-only on TimeEntryDetail, covered by the manifest-pages smoke suite
- **GIVEN** an employee whose active `OrgAssignment` unit carries `costCenter: "CC-100"`
- **WHEN** that employee's booking is persisted
- **THEN** the TimeEntry carries `costCenter: "CC-100"` without any form input

#### Scenario: An integration-written allocation key survives employee edits

@e2e exclude Backend write-path behaviour; verified by the stamping listener unit tests (allocation properties are outside every includeFields allowlist and outside the client-writable set)
- **GIVEN** a TimeEntry whose `allocationKey` was written by the bookkeeping integration
- **WHEN** the employee edits the entry's description via the booking form
- **THEN** `allocationKey` is unchanged

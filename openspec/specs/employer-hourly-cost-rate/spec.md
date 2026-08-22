# Employer hourly cost rate

## Purpose

Expose what an hour of an employee's time costs the employer, so another app
can compose it into a total. This is the humaniq half of hydra ADR-081's

    hourlyCost = wageCost + Σ additions

humaniq owns `wageCost` and derives it from the **contract**, never from a
payslip. Shillinq owns the ledger-derived additions (overhead, equipment),
because it owns the general ledger those pools live in.

`EmployeeCostRateService` has computed this since #68 and its `resolve()`
already accepts `extraAdditions` for exactly that caller. What was missing was
a way to reach it from outside the app: without an HTTP surface the bridge
described by ADR-081 did not exist.

## Requirements

### Requirement: The cost rate is served over HTTP and never persisted

humaniq SHALL expose the resolved employer cost per hour at
`POST /api/employees/cost-rate`, taking `employeeId`, an optional `period`
(`YYYY-MM`, defaulting to the current month) and an optional `additions[]`
array supplied by the caller.

The endpoint SHALL NOT write. A rate is derived on read from the contract, so
storing it would create a second copy that goes stale the moment a contract or
a CLA changes. `POST` is used because the caller SENDS its additions in the
body, not because anything is created.

Caller-supplied additions SHALL be passed to the service unaltered and merged
with the employee's own stored additions, so the composition rules the service
enforces — a fixed amount OR a percentage of the wage base and never both, a
percentage resolving against the wage base rather than a running total, and no
overtime addition on a wage base that already blends overtime — apply to them
equally.

#### Scenario: A caller composes the total from both halves
- **GIVEN** an employee with a costable active contract
- **WHEN** a caller POSTs `employeeId` together with its own ledger-derived
  `additions[]`
- **THEN** the response carries `totalCentsPerHour`, the `wageCostCents` half,
  the wage `source`/`basis`, and every addition with its own key, amount,
  source and basis — and nothing has been written
- @e2e exclude backend read API — asserted by PHPUnit

### Requirement: A rate is never produced for an employee the caller cannot read

The employee SHALL be resolved through OpenRegister's `ObjectService` under the
CALLER's RBAC before any figure is produced (ADR-005 Rule 3).

Because the response is derived from salary, an employee the caller may not
read MUST be indistinguishable from one that does not exist: both answer
`404`, the body MUST NOT echo why the lookup failed, and it MUST NOT carry any
wage field.

The active `EmploymentContract` SHALL be resolved by the endpoint and passed
in, rather than left for the service to choose, so a rate cannot be quietly
costed against a different contract than the caller believes is in force.

#### Scenario: An unauthorised id leaks nothing
- **GIVEN** an employee id outside the caller's RBAC
- **WHEN** the caller requests its cost rate
- **THEN** the response is `404` with no wage figure and no upstream error text
- @e2e exclude backend authorization — asserted by PHPUnit

### Requirement: An hour with no wage base is refused, not costed as zero

Where neither a reasoned override nor a costable contract yields a wage base,
the endpoint SHALL answer `409` naming the absence.

It MUST NOT answer `0`. A zero rate reads as a free hour, and additions alone
are not a cost — an hour carrying overhead but no wage is not an hour anyone
worked.

Where the service refuses the composition itself — an override with no reason,
an addition with no basis, overtime stacked on an overtime-blended base — the
endpoint SHALL answer `400` carrying the service's reason. Those are caller
errors and MUST NOT surface as a `500`.

#### Scenario: Missing wage base and indefensible additions are distinguished
- **GIVEN** an employee that exists but has no override and no costable contract
- **WHEN** its cost rate is requested
- **THEN** the response is `409` and carries no `totalCentsPerHour`
- **AND GIVEN** an addition with no basis
- **WHEN** a rate is requested with it
- **THEN** the response is `400` and names the offending addition
- @e2e exclude backend refusal semantics — asserted by PHPUnit

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

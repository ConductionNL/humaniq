# payroll-run-pay-date-and-schedule Specification

## Purpose
`payroll-run-as-a-flow` shipped the monthly payroll run as an OpenRegister flow (calculate, review, approve, post to the ledger, prepare net pay) and named three follow-ups it deliberately left out (its design D5 and D7): the run posts the moment it is approved, because humaniq stores no pay date to wait for; the flow ships manual-triggered, because a schedule needs a service account humaniq cannot choose for a customer; and the raw `PayrollRun.status` edit still approves a run outside the flow, because that edit is the production path until adopted flows carry the approval everywhere. This capability carries those three; they were split out of `payroll-run-as-a-flow` on 2026-11-24.

## Requirements

### Requirement: An approved run SHALL wait for its pay date before it is posted (REQ-PRD-001)

An administration SHALL carry a pay date as a day of the month (`paymentDayOfMonth`), and the
shipped Loonrun flow SHALL wait between the approval and the ledger posting until that day of the
run's period. An administration without a pay date SHALL post on approval, as the flow does
today.

#### Scenario: A run approved on the 20th posts on the 25th
@e2e exclude a flow wait inside OpenRegister's engine with no screen of its own; the wait node and its date are to be pinned by the flow declaration test
- **GIVEN** an administration with `paymentDayOfMonth` 25 and the Loonrun flow enabled
- **WHEN** the 2026-11 run is approved on 20 November
- **THEN** the run is posted to the ledger on 25 November, not on the 20th

### Requirement: The admin surface SHALL document how to schedule the flow (REQ-PRD-002)

The admin documentation SHALL give the recipe for a monthly cadence: add an
`openregister.trigger-schedule` node with a cron expression, set `runAs` to the payroll
administration's service account, and enable the flow. humaniq SHALL NOT ship a schedule with an
empty or assumed `runAs`.

#### Scenario: An administrator schedules the monthly run
@e2e exclude documentation; read in the live pass of the admin surface
- **GIVEN** the Loonrun flow arrives disabled and manual-triggered
- **WHEN** an administrator follows the recipe on the admin surface
- **THEN** the flow runs monthly as the service account they named

### Requirement: The flow SHALL be the only approver of a run once adopted (REQ-PRD-003)

Once adopted flows carry the approval everywhere, a raw edit of `PayrollRun.status` to `approved`
SHALL be refused, so the recorded review decision is the only approval. On an instance the flow counts as
adopted once the shipped Loonrun flow is enabled; while it is disabled the raw edit stays the
production path.

#### Scenario: A direct status edit is refused
@e2e exclude a server-side guard, to be pinned by its unit test
- **GIVEN** the Loonrun flow is adopted
- **WHEN** a user edits a draft run's `status` to `approved` directly
- **THEN** the edit is refused and names the flow's review step

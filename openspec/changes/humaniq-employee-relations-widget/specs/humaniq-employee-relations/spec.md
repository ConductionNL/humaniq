## ADDED Requirements

### Requirement: Timesheet and Expense claims declare a resolvable Employee relation

The system SHALL declare `Timesheet.employeeId` and `Expense.employeeId` as OpenRegister object
references to an `Employee` object in the `hrmq` register — consuming OpenRegister's Relations
abstraction (ADR-022) — rather than an untyped, unresolvable business-key string. Seed data for
`Timesheet` and `Expense` objects SHALL reference real, seeded `Employee` objects so the relation
graph is resolvable end to end in a fresh environment.

**Feature tier**: MVP

#### Scenario: A Timesheet's employeeId resolves to a real Employee object

- GIVEN a seeded `Timesheet` object with an `employeeId` reference
- WHEN OpenRegister's relation graph (`/uses`/`/used`) is queried for that Timesheet
- THEN it MUST resolve to the referenced `Employee` object, not an empty result

#### Scenario: An Expense claim's employeeId resolves to a real Employee object

- GIVEN a seeded `Expense` object with an `employeeId` reference
- WHEN OpenRegister's relation graph is queried for that Expense
- THEN it MUST resolve to the referenced `Employee` object, not an empty result

### Requirement: The detail-page related widget surfaces the linked Employee

The system MUST ensure the `related` widget declared on the `TimesheetDetail` and `ExpenseDetail`
manifest pages actually lists the claiming Employee for a seeded record, matching what the
widget's own manifest documentation claims it shows.

**Feature tier**: MVP

#### Scenario: The related widget lists the Employee on a Timesheet detail page

- GIVEN a user opens the `TimesheetDetail` page for a seeded Timesheet
- WHEN the `ts-related` widget loads
- THEN its Objects section MUST list the linked Employee object
- AND MUST NOT render an empty "no related objects" state

#### Scenario: The related widget lists the Employee on an Expense detail page

- GIVEN a user opens the `ExpenseDetail` page for a seeded Expense claim
- WHEN the `ex-related` widget loads
- THEN its Objects section MUST list the linked Employee object
- AND MUST NOT render an empty "no related objects" state

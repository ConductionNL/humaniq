# secondment-and-side-activities

## ADDED Requirements

### Requirement: HR SHALL record an outward secondment (REQ-SEC-001)

humaniq SHALL hold a `Secondment` per employee with the receiving organisation, the period,
the hours per week worked there, the agreed rate and the signed agreement, and SHALL show it
on the employee's page.

Rows: `td-secondment` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: HR records a secondment
- **GIVEN** an HR adviser on `EmployeeDetail` for Pieter Jansen
- **WHEN** they add a secondment to Veiligheidsregio Fryslan for 16 hours a week from
  1 September with the signed agreement attached, and activate it
- **THEN** the secondment appears in the employee's Secondments list with status active

### Requirement: Seconded hours SHALL count as committed time (REQ-SEC-002)

The agenda SHALL show an active secondment as its own entry, and the availability and
capacity reads SHALL count its hours as committed.

Rows: `td-secondment` (humaniq matrix).

#### Scenario: A seconded employee is not planned at home
- **GIVEN** a 36-hour employee with an active 16-hour secondment
- **WHEN** a planner asks `GET /api/availability` for that employee for a week
- **THEN** at most 20 hours are reported available, and the agenda shows the secondment

### Requirement: Side activities SHALL be reported and decided (REQ-SEC-003)

An employee SHALL be able to report a side activity, or report that there is none, from
Mijn HR. The manager or HR SHALL approve it or reject it with a conflict reason, and the
employee SHALL NOT decide on their own report. `Employee.nevenwerkzaamhedenGemeld` SHALL
follow the register instead of being ticked by hand.

Rows: `td-side-activities` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: A civil servant reports a board seat
- **GIVEN** Ingrid de Boer under the Ambtenarenwet with no report on file
- **WHEN** she reports a paid board seat on `MijnNevenwerkzaamheden`
- **THEN** her manager sees it on `SideActivities` to decide, and her employee record now
  counts as having reported, so `nl-ambtenaar-nevenwerkzaamheden-melding` passes

#### Scenario: A conflict is recorded with its reason
- **GIVEN** a reported side activity at a supplier of the municipality
- **WHEN** the manager rejects it
- **THEN** the rejection is refused without a conflict reason, and saved with one

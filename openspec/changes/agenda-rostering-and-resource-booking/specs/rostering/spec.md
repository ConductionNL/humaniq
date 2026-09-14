# rostering

## ADDED Requirements

### Requirement: A shift SHALL declare the competences it needs (REQ-ROST-C01)

The `Shift` schema in register `hrmq` SHALL carry `requiredCompetences`, a set of
competence codes. A new `EmployeeCompetence` schema SHALL record that one employee
holds one competence, with an `issuedOn` and an optional `validUntil`. A competence
whose `validUntil` has passed SHALL NOT count as held.

Candidate C-tasks-and-phases-28 (`tasks-and-phases.tsv:42`), relevance `should`.
**No driven passer.** The only evidence is atabix's documented Roostersysteem on
`/gestandaardiseerde-modules`, admitted under decision D21 and labelled documented
here so no reader takes it for a measurement.

#### Scenario: A shift names what it needs
- **GIVEN** a `Shift` "Nachtcontrole horeca" with `requiredCompetences` `["boa-domein-1"]`
- **WHEN** the shift is read
- **THEN** the competence set travels with the shift, and every assignment that
  references the shift inherits it without restating it

#### Scenario: An expired qualification stops counting on its own date
- **GIVEN** an `EmployeeCompetence` for `boa-domein-1` with `validUntil` yesterday
- **WHEN** the holder is evaluated for a shift requiring `boa-domein-1`
- **THEN** the competence does not count as held, without anybody editing the record

### Requirement: A roster check SHALL refuse an assignment the person is not competent for (REQ-ROST-C02)

`RosterCheckService` SHALL gain a competence cross-check beside the three
Arbeidstijdenwet rules it already runs. For every `RosterAssignment` on the roster,
the check SHALL compare the shift's `requiredCompetences` against the competences the
assigned employee holds on the assignment's date. A missing or expired competence
SHALL produce a finding of kind `competence`, distinct from the working-time findings,
naming the employee, the date and the competence.

The check SHALL keep the never-throw posture `rostering` already specifies, and SHALL
run in the same act as the working-time check, so `occ humaniq:roster:check` and
`POST /api/roster/check` still answer the whole question in one call.

#### Scenario: A roster with an unqualified assignment does not publish clean
- **GIVEN** a concept roster with one assignment putting an employee without
  `boa-domein-1` on a shift that requires it
- **WHEN** `occ humaniq:roster:check` runs
- **THEN** it reports one `competence` finding naming the employee, the date and
  `boa-domein-1`

#### Scenario: The two kinds of finding stay apart
- **GIVEN** a roster that breaks both the daily-rest rule and a competence requirement
- **WHEN** the check runs
- **THEN** the working-time finding and the competence finding are reported
  separately, each with its own kind, so a reader can act on one without the other

### Requirement: Planned capacity SHALL be read forward against each person's own hours (REQ-ROST-C03)

humaniq SHALL answer, for a period and an org unit, the planned hours per employee
against that employee's own contracted hours for the same period, forward from a
given date. Planned hours SHALL be the sum of rostered assignments and resource
bookings that hold the employee, minus approved leave and open sick leave. The
contracted hours SHALL come from the working hours specified by
`a-working-calendar-per-person`; when a person has none, the answer SHALL say so
rather than substitute the instance default.

Candidate C-reporting-15 (`reporting.tsv:8`), relevance `should`, driven passer
openproject (Team planners, `modules/resource_management/`), documented
easy-redmine and jira-service-management.

#### Scenario: A part-timer is measured against their own week
- **GIVEN** an employee contracted for 24 hours a week and rostered for 20
- **WHEN** the capacity read runs over that week
- **THEN** it reports 20 planned against 24 available, not against the instance's
  full-time week

#### Scenario: A missing contract is said, not guessed
- **GIVEN** an employee with no working hours recorded
- **WHEN** the capacity read runs
- **THEN** the answer marks that employee as having no contracted hours, and no
  percentage is computed for them

### Requirement: humaniq SHALL NOT propose dates for other apps' work (REQ-ROST-C04)

Candidate C-deadlines-19 (`deadlines.tsv:30`) asks a product to propose dates for a
set of records from their dependencies and the capacity available. humaniq SHALL
supply the capacity half through REQ-ROST-C03 and SHALL NOT schedule another app's
records. The dependency graph belongs to the app that owns the records.

This is recorded as a requirement rather than left out, so the decision is findable.
The candidate has **no driven passer**: the evidence is Jira Data Center's
documented Advanced Roadmaps auto-schedule, admitted under decision D21.

#### Scenario: The capacity answer is offered, the schedule is not
- **WHEN** a consuming app asks humaniq for a proposed set of dates
- **THEN** humaniq answers with available capacity per person per period, and the
  consuming app composes the schedule from its own dependencies

# org-chart-view Specification

## Purpose
The organisation as a chart on a date: units, their managers and headcounts, shown as an accessible tree and as a drawn chart, without widening access to people. Built by people-org-chart-view (archived 2026-09-29).

## Requirements

### Requirement: humaniq SHALL show the organisation as a chart on a date (REQ-OCV-001)

humaniq SHALL compose, for a chosen root unit and date, the tree of active units under it
with each unit's manager and the number of people placed in it on that date, and SHALL show
it on an `Organogram` page both as an expandable tree and as a drawn top-down chart. Clicking
a unit SHALL open its `OrgUnitDetail`.

Rows: `ppl-org-chart` (humaniq matrix).

#### Scenario: An employee finds where a team sits
- **GIVEN** a municipality with directorates, departments and teams
- **WHEN** an employee opens `Organogram`
- **THEN** they see the directorates with their managers and headcounts, can expand down to
  a team, and can switch to the drawn chart of the same tree

@e2e exclude the tree and headcounts are composed server-side and rendered by the library's CnTreeView; covered by OrgChartServiceTest::testAThreeLevelTreeWithManagersAndHeadcounts and OrgChartControllerTest::testTheChartCoversTheCallersAdministration

#### Scenario: The chart on a past date
- **GIVEN** an employee who moved from one team to another last month
- **WHEN** an HR adviser reads the chart with people for a date two months ago
- **THEN** the employee is shown in the old team

@e2e exclude placements on a date are resolved server-side; covered by OrgChartServiceTest::testAnEndedPlacementCountsOnlyBeforeItsEnd

### Requirement: The chart SHALL NOT widen access to people (REQ-OCV-002)

Units and their managers SHALL be visible to every signed-in user; the people placed in a
unit SHALL be listed only when the caller may read those employees.

Rows: `ppl-org-chart` (humaniq matrix).

#### Scenario: People stay private to those who may see them
- **GIVEN** an employee without read access to another directorate's staff
- **WHEN** they request `GET /api/org/chart?withPeople=true` for that directorate
- **THEN** the units, managers and counts are returned and the names of the people are not

@e2e exclude access is decided server-side; covered by OrgChartControllerTest::testWithPeopleListsOnlyReadableEmployees and OrgChartServiceTest::testPeopleAreListedOnlyWhenAskedAndReadable

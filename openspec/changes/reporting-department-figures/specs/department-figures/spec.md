# department-figures

## ADDED Requirements

### Requirement: Absence and wage cost SHALL be available per org unit (REQ-DPF-001)

The analytics trends SHALL accept an org unit and SHALL compute the absence rate, the absence
frequency and the wage cost for the employees placed in that unit and its children in each
period. The units of an administration SHALL add up to the administration's total wage cost.

Rows: `abs-rate`, `rep-wage-costs` (humaniq matrix).

#### Scenario: HR compares two teams
- **GIVEN** Team Burgerzaken with an absence rate of 7 percent and Team Belastingen with 3
  percent over the last six months
- **WHEN** an HR adviser opens the unit comparison on `AbsenceReport`
- **THEN** both teams are shown with their rate and their frequency of sick reports per
  employee per year

#### Scenario: A controller splits the wage cost
- **GIVEN** an administration whose June wage cost is 400,000
- **WHEN** a user with the accountant role reads the June wage cost per unit
- **THEN** each unit shows its share and the shares add up to 400,000

### Requirement: A manager SHALL see the totals of the units they lead (REQ-DPF-002)

humaniq SHALL offer a `MijnAfdeling` dashboard showing a manager, for each unit they manage, the
absence rate and frequency, the net FTE against formation, the vacant FTE, the open vacancies and
the wage cost, as unit totals only. A manager SHALL NOT receive figures for a unit they do not
manage, and SHALL NOT receive figures for a unit too small to keep individuals unrecognisable.

Rows: `td-manager-dashboard` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: A team leader reads their department
- **GIVEN** the manager of Team Burgerzaken
- **WHEN** they open `MijnAfdeling`
- **THEN** they see the team's absence rate and frequency, net FTE against budget, vacancies and
  wage cost for the current period, and no individual's figure

#### Scenario: Another team stays closed
- **GIVEN** the same manager
- **WHEN** they request the trends for Team Belastingen
- **THEN** the request is refused

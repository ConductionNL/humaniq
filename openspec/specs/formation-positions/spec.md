---
capability: formation-positions
status: done
built_by: openspec/changes/archive/2026-09-28-people-formation-positions
---

# formation-positions Specification

**Status**: done
**Scope**: humaniq

**OpenSpec changes**: [people-formation-positions](../../changes/archive/2026-09-28-people-formation-positions/) _(archived 2026-09-28)_

## Purpose

A unit's formation: budgeted places per unit and function, which contracts fill them, and the
budgeted, filled, vacant and net FTE computed on read. Net FTE leaves out parental leave,
pregnancy and childbirth leave and long-term sickness, as a municipal tender asks.

## Requirements

### Requirement: A unit SHALL carry budgeted formation places (REQ-FRM-001)

humaniq SHALL hold `Formatieplaats` objects, each belonging to an `OrgUnit`, optionally
naming a `Normfunctie`, with a budgeted FTE and a validity period. An employment contract
SHALL be able to name the place it fills, and a vacancy SHALL be able to name the place it
recruits for.

Rows: `ppl-position-management` (humaniq matrix).

#### Scenario: HR records the budget of a team
@e2e exclude a formation place is a plain register object created through the generic index page; the vacancy it opens with is covered by FormationOccupancyServiceTest::testANewPlaceIsFullyVacant, and the page itself mounts in the generic manifest-pages spec
- **GIVEN** an HR adviser on `Formatieplaatsen`
- **WHEN** they add a place "Medewerker burgerzaken" of 4.0 FTE to Team Burgerzaken
- **THEN** the place is listed on the unit with 4.0 budgeted FTE and 4.0 vacant FTE until
  a contract names it

### Requirement: Occupancy SHALL be computed per place and per unit on a date (REQ-FRM-002)

For a unit and a date, or averaged over the working days of a period, humaniq SHALL show, per place and in total, the budgeted FTE, the
FTE filled by contracts active on that date, the vacant FTE and whether the place is
overfilled. The figures SHALL be computed from the records on read and SHALL NOT be stored.

Rows: `ppl-position-management` (humaniq matrix).

#### Scenario: A partly filled place shows its vacancy
@e2e exclude the figures come from the endpoint the widget renders verbatim; covered by FormationOccupancyServiceTest::testAPartlyFilledPlaceShowsItsVacancy and ::testTheEndpointCountsAReadableUnitsActivePlaces
- **GIVEN** a 4.0 FTE place filled by contracts totalling 3.6 FTE
- **WHEN** a manager opens `OrgUnitDetail` for the unit
- **THEN** the formation block shows 3.6 filled and 0.4 vacant for that place

#### Scenario: An overfilled place is shown, not refused
@e2e exclude covered by FormationOccupancyServiceTest::testAnOverfilledPlaceIsShownNotRefused
- **GIVEN** a 1.0 FTE place and two active contracts of 0.8 FTE naming it
- **WHEN** the occupancy is read through `GET /api/formation/occupancy`
- **THEN** filled is 1.6, vacant is 0 and the place is marked overfilled

### Requirement: Net FTE SHALL leave out long absence (REQ-FRM-003)

The net FTE of a unit SHALL be its filled FTE minus, for each occupant, their FTE times
the fraction of the day they are away on an approved leave of a type in the net-FTE list
(by default parental leave and pregnancy and childbirth leave) or on a sickness case open
longer than the long-term threshold (by default six weeks). Both the list and the threshold
SHALL be settings.

Rows: `td-net-fte` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: Parental leave and long sickness lower the net figure
@e2e exclude needs leave and sickness fixtures on seeded people; covered by FormationOccupancyServiceTest::testParentalLeaveAndLongSicknessLowerTheNetFigure
- **GIVEN** a unit with 4.6 filled FTE, one 1.0 FTE occupant on parental leave two of five
  days, and one 0.8 FTE occupant sick for ten weeks at 50 percent
- **WHEN** an HR adviser reads the unit's occupancy averaged over a full week in that period
- **THEN** the net FTE is 4.6 minus 0.4 minus 0.4, which is 3.8

#### Scenario: A short sickness does not count
@e2e exclude covered by FormationOccupancyServiceTest::testAShortSicknessDoesNotCount
- **GIVEN** an occupant sick for two weeks
- **WHEN** the net FTE is read with the default threshold
- **THEN** that occupant's FTE is not subtracted

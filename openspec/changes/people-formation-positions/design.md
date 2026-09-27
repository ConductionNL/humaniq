# Design: formation places with their occupancy and net FTE

## Context

Read at `development` af702f78.

- `OrgUnit` (`lib/Settings/register.d/hr-org.json`): `name`, `type`, `parentUnitId`,
  `costCenter`, `managerId`, `active`, per-weekday `minimumPresent*`, `administrationId`.
  No headcount or budget fields. `OrgUnitDetail` walks children one level at a time.
- `Normfunctie` (`lib/Settings/register.d/hr-hr21.json`): `functiecode`, `naam`,
  `functiegroep`, `caoSchaal`.
- `EmploymentContract` (`hr-objects.json`): `employeeId`, `type`, `startDate`, `endDate`,
  `hoursPerWeek`, `normfunctieId`. FTE is derived from `hoursPerWeek` against the full-time
  week `AbsenceRateService` uses (`DEFAULT_FULL_TIME_HOURS_PER_WEEK` 40, and
  `fteByEmployee()` sums overlapping contracts).
- `Vacancy` (`lib/Settings/register.d/hr-ats.json`): `title`, `department`, `status`,
  `publishedDate`, `closingDate`, `administrationId`.
- Absence: `LeaveRequest.leaveType` holds the code of an administered `LeaveType`
  (`hr-leave-types.json` seeds `holiday`, `sick`, `unpaid`, `special`, `care`,
  `parental`). `SickLeaveCase` has `firstSickDay`, `recoveredDate`, `absenceProgression`,
  `currentAbsencePercentage`; `lib/Service/AbsenceProgression.php` turns a progression into
  the fraction absent on a day.
- `OrgResolutionService` resolves an employee's unit from `OrgAssignment`.

## Goals / Non-Goals

**Goals**

- A budget per formation place and its occupancy on any date.
- A net FTE per unit that leaves out parental leave, pregnancy and childbirth leave and
  long-term sickness, weighted by the fraction absent.

**Non-Goals**

- Money. Costs belong to the personnel budget change.
- Position hierarchy (a place reporting to a place). Units carry the hierarchy.

## Decisions

### D1. `Formatieplaats` schema

`orgUnitId` ($ref OrgUnit, required), `normfunctieId` ($ref Normfunctie, nullable),
`title`, `budgetedFte` (number, minimum 0), `validFrom`, `validUntil` (nullable), `status`
(`actief`, `opgeheven`), `administrationId`. Alternative considered: budget fields on
`OrgUnit`. Rejected: a unit holds several functions with separate budgets, and the tender
asks per function.

### D2. Filling a place is a field on the contract

`EmploymentContract.formatieplaatsId` ($ref Formatieplaats, nullable). One contract fills
at most one place, which covers the Dutch practice of one dienstverband per formatieplaats;
a person with two jobs has two contracts. Alternative considered: an allocation object
(Visma's PositionAllocation). Deferred: it adds a join nobody needs until a contract splits
over places.

### D3. Occupancy is computed, never stored

`FormationOccupancyService::forUnit(orgUnitId, from, to)` returns, averaged over the
working days from `from` to `to` (a single date when they are equal), per place and summed
for the unit (including child units when `includeChildren=true`): `budgetedFte`, `filledFte`,
`vacantFte = max(0, budgeted - filled)`, `overfilled` (filled above budget), and `netFte`.
`netFte = filledFte - sum(fte of occupant * fraction away)`, where "away" is an approved
`LeaveRequest` whose type code is in the setting `formation.netFteLeaveTypes` (default
`parental`, `zwangerschap`) covering the day, or an open `SickLeaveCase` older than
`formation.longTermSickWeeks` (default 6) weighted by `AbsenceProgression` on that day.
Both settings live in `SettingsService`, so an employer can change the threshold.

### D4. Pregnancy and childbirth leave as data

`LeaveType` became an administered object in `leave-against-a-department-schedule`; this
change seeds `zwangerschap` ("Pregnancy and childbirth leave", `drawsFromBalance` false). No
schema change.

### D5. Surfaces

- `OrgUnitDetail` gains a `bodyWidgets` host section `FormationSection` bound to
  `GET /api/formation/occupancy`.
- `Formatieplaatsen` (index) and `FormatieplaatsDetail` (detail, with an `object-list` of
  the contracts on the place) as declarative pages; the detail page also shows the
  occupancy figures for that one place through the same section.
- The endpoint is `#[NoAdminRequired]` and answers only for units the caller may read
  through `RbacObjectReader`.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| budgeted FTE per unit (sum) | declarative `stat` widget over `Formatieplaats.budgetedFte` | a single-schema sum |
| filled, vacant and net FTE | imperative `FormationOccupancyService` | cross-schema, date-windowed, weighted by absence |
| place status | plain enum, no lifecycle | two states, no guarded transition |

## Seed data

- Unit "Team Burgerzaken" of a municipality: places "Medewerker burgerzaken" 4.0 FTE and
  "Teamleider" 1.0 FTE. Four contracts fill 3.6 FTE of the first place (so 0.4 vacant);
  one occupant is on parental leave two days a week; one is sick for ten weeks at 50 percent.
- The `zwangerschap` leave type.

## Risks / Trade-offs

- [Contracts without a place] → they count nowhere in formation; the unit block shows how
  many active contracts in the unit have no place, so the gap is visible.
- [Threshold for long-term sickness differs per employer] → a setting, default 6 weeks,
  named on the page.

## Open Questions

- Should net FTE also leave out unpaid leave? The default list is the tender's three
  categories; the setting allows more.

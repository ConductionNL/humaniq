---
kind: code
---

# Formation places with their occupancy and net FTE

## Why

A municipality or a school board plans its staff in formation places (formatieplaatsen):
a budgeted number of FTE per unit and function, filled by people or vacant. humaniq knows
units (`OrgUnit`), functions (`Normfunctie`) and contracts, but not the budget between them.
Nobody can ask humaniq how many FTE a team is allowed, how many are filled and how many are
open, and nobody can see the occupancy that is left once people on parental leave,
pregnancy leave or long-term sickness are taken out. A municipal tender asks for that net
figure by name.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ppl-position-management` | Manage formation: budgeted positions and which are filled or vacant. | `no`, none: neither `OrgUnit` nor `Normfunctie` holds a budget or an occupancy |
| `td-net-fte` | See net occupancy in FTE after subtracting parental, pregnancy and long-term sick leave. | `no`, none: `AbsenceRateService` gives a percentage, there is no formation to measure against |

### Demand

- `td-net-fte`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415227
  (Sudwest-Fryslan E12.9 and W0.14, netto bezetting in FTE).

### Competitors rated yes

- `ppl-position-management`, AFAS Profit: "formatiebeheer records a budget in FTE per
  formation place and compares it with actual occupancy"
  (https://help.afas.nl/help/NL/SE/Hrm_Format.htm).
- `ppl-position-management`, Visma Raet Youforce: "Position (formatieplaats with indicator
  formatiefunctie) and PositionAllocation (FTE and hours per formatieplaats, reason and
  status of allocation)"
  (https://vr-api-integration.github.io/youforce-api-documentation/data_api_tables.html).
- `ppl-position-management`, Personio: "the Positions tab oversees all open and filled
  positions with target job, start date and FTE"
  (https://support.personio.de/hc/en-us/articles/18704769097629-The-Positions-tab-in-the-Planning-area).
- `td-net-fte`, Personio: "the FTE template prorates FTE for days an employee's status is
  leave, such as parental leave or a sabbatical, giving actual working capacity rather than
  headcount" (https://support.personio.de/hc/en-us/articles/19300731976093-Summary-of-templates-available-for-reporting).

## What Changes

- **A formation place.** A new `Formatieplaats` belongs to an `OrgUnit`, optionally names a
  `Normfunctie`, and carries a budgeted FTE and a validity period.
- **Contracts fill places.** `EmploymentContract` gains `formatieplaatsId`, and a
  `Vacancy` can name the place it recruits for.
- **Occupancy per place and per unit.** humaniq computes, on a date or averaged over a
  period, the budgeted FTE, the
  filled FTE (active contracts on the place), the vacant FTE (budget minus filled, never
  below zero, with an overfilled flag) and the net FTE (filled FTE minus the FTE of people
  on parental leave, pregnancy and childbirth leave, or sick for longer than a threshold,
  weighted by how much of the day they are away).
- **Where HR sees it.** `OrgUnitDetail` gains a formation block with these four figures for
  the unit and its places; a `Formatieplaatsen` index and detail page list every place with
  its occupants.
- **A pregnancy and childbirth leave type.** The administered leave types gain
  `zwangerschap` (WAZO), so the net figure can recognise it.

## Capabilities

### New Capabilities

- `formation-positions`: formation places, their occupancy on a date, and the net FTE per
  unit.

## Impact

- `lib/Settings/register.d/hr-formation.json` (new): `Formatieplaats`.
- `lib/Settings/register.d/hr-objects.json`: `EmploymentContract.formatieplaatsId`.
  `lib/Settings/register.d/hr-ats.json`: `Vacancy.formatieplaatsId`.
  `lib/Settings/register.d/hr-leave-types.json`: seeded `LeaveType` `zwangerschap`.
- `lib/Service/FormationOccupancyService.php` (new), `lib/Controller/FormationController.php`
  (new), `appinfo/routes.php`: `GET /api/formation/occupancy?orgUnitId&from&to`.
- `src/manifest.d/hr-formation.json` (new) and a formation section on `OrgUnitDetail`
  (`src/manifest.d/hr-org.json`).

## Out of scope

- The personnel budget and formation scenarios. They build on this in
  `reporting-personnel-budget-and-scenarios`.
- Hard refusal of a contract on a full place. Overfilling is shown, not blocked.

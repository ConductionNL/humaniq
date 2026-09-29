---
kind: code
depends_on: [people-formation-positions]
---

# Next year's personnel budget and formation scenarios

## Why

Every autumn a municipality draws up next year's personnel budget: the cost of the people
it employs and of the formation places it means to fill, per team, per function and per
cost centre, and then tries a few variants (grow the service desk by two FTE, cut a team
by one) before the council decides. humaniq holds every figure this needs (salaries,
contracts, units with their cost centre, the CAO scales and, with
`people-formation-positions`, the budgeted places) but cannot put them together. Two
municipal tenders ask for both the budget and the scenarios.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `td-personnel-budget` | Draw up next year's personnel budget from the formation and current staff, per team, job and cost centre. | `no`, none: no budget object and no formation to budget from |
| `td-formation-scenarios` | Model formation scenarios such as growth or cuts in FTE per department. | `no`, none: no formation model exists |

### Demand

- `td-personnel-budget`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415705
  (Delft Support E12, personeelsbegroting per medewerker, functie, team, kostenplaats; also
  Sudwest-Fryslan E8.14).
- `td-formation-scenarios`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415227
  (Sudwest-Fryslan E12.10, formatieplanningstool met scenario's).

### Competitors rated yes

- `td-personnel-budget`, HR2day: "plan formations per department, team or function and
  calculate wage costs per scenario, including future raises and collective agreement
  changes" (https://www.hr2day.com/features/formatie-begroting/).
- `td-formation-scenarios`, HR2day: "copy the organisation, simulate mutations and compare
  several formation and budget scenarios" (https://www.hr2day.com/features/formatie-begroting/).

## What Changes

- **A scenario.** A new `FormationScenario` names a budget year and holds its assumptions:
  the expected CAO raise, the employer charges percentage when no payroll history exists,
  and a status (`concept`, `vastgesteld`). The baseline is a scenario with no mutations.
- **Mutations inside a scenario.** A new `ScenarioMutation` adds or removes FTE on a
  formation place, or adds a new place, from an effective date. Mutations never touch the
  live formation.
- **A computed budget.** For a scenario and a year humaniq computes the annual cost per
  unit, per function and per cost centre: occupied FTE priced at the occupant's own salary,
  vacant FTE priced at the CAO scale of the place's function, both with holiday allowance
  and employer charges, and scaled for the months each FTE counts in the year.
- **Scenarios side by side.** A comparison shows two scenarios and the baseline per unit,
  with the difference in FTE and in cost, and exports to CSV.

## Capabilities

### New Capabilities

- `personnel-budget`: a computed personnel budget per year from staff and formation, and
  formation scenarios compared against it.

## Impact

- `lib/Settings/register.d/hr-formation.json`: `FormationScenario`, `ScenarioMutation`
  (added to the fragment `people-formation-positions` creates).
- `lib/Service/PersonnelBudgetService.php` (new), `lib/Controller/FormationController.php`
  (the controller from `people-formation-positions`), `appinfo/routes.php`:
  `GET /api/formation/budget?scenarioId&year`, `GET /api/formation/compare?a&b&year`.
- `src/manifest.d/hr-formation.json`: `FormationScenarios`, `FormationScenarioDetail` with
  a budget section.

## Out of scope

- Booking the budget into shillinq's ledger. The CSV is the hand-off for now.
- Individual pay steps (periodieken) within a scale. A vacant place is priced at the
  scale's minimum from `CaoRegistry` plus the scenario's raise, and the page says so.

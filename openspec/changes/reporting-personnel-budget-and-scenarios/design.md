# Design: next year's personnel budget and formation scenarios

## Context

Read at `development` af702f78, plus the change it depends on.

- `people-formation-positions` adds `Formatieplaats` (`orgUnitId`, `normfunctieId`,
  `budgetedFte`, validity), `EmploymentContract.formatieplaatsId` and
  `FormationOccupancyService`.
- Pay: `Employee.grossMonthlySalary` is the monthly salary the payroll engine reads;
  `EmploymentContract.hoursPerWeek` gives the FTE. `PayrollRun` carries `totalGross` and
  `totalEmployerCharges` per period and administration; `Payslip` carries
  `vakantiegeldRate` (8 percent from `lib/Standards/packs/nl-2026.pack.json:259`).
- CAO: `lib/Standards/CaoRegistry.php::minMaandloonCents(caoId, schaal)` gives a scale's
  monthly minimum; `Normfunctie.caoSchaal` names the scale of a function.
- Cost centre: `OrgUnit.costCenter` (`hr-org.json`).
- Comp: `CompAdjustment` with `effectiveDate` and `status` records approved raises not yet
  applied; the budget counts them from their effective date.

## Goals / Non-Goals

**Goals**

- A budget per year that a finance officer can reproduce: every line names the FTE, the
  monthly amount, the months and the surcharges it multiplies.
- Scenarios that never change the live formation.

**Non-Goals**

- Forecasting beyond one budget year per scenario.
- Replacing the payroll engine's net-pay arithmetic. The budget is a gross cost model.

## Decisions

### D1. Two schemas

`FormationScenario`: `name`, `year`, `caoRaisePercentage` (default 0),
`employerChargesPercentage` (nullable, fallback), `status` (`concept`, `vastgesteld`),
`administrationId`. `ScenarioMutation`: `scenarioId` ($ref), `formatieplaatsId` (nullable,
$ref), `orgUnitId` ($ref, required when a new place is proposed), `normfunctieId`
(nullable), `fteDelta` (number, may be negative), `effectiveDate`, `note`. Alternative
considered: copying the formation into the scenario (HR2day's "copy the organisation").
Rejected: a copy drifts from the live formation the moment a contract changes; a delta
list always applies to the current formation.

### D2. The cost line

For each place in the scenario, per month of the year:

- occupied FTE: each occupant's `grossMonthlySalary` times the share of their contract on
  the place, raised by `caoRaisePercentage` from 1 January and by any approved
  `CompAdjustment` from its `effectiveDate`;
- vacant FTE: `minMaandloonCents(cao, caoSchaal)` of the place's function times the vacant
  FTE, raised by `caoRaisePercentage`; a place without a function or a sourced scale is
  priced at zero and flagged `unpriced`;
- surcharges: holiday allowance at the pack's `vakantiegeldRate`, and employer charges at
  the ratio `totalEmployerCharges / totalGross` of the administration's last twelve
  finalised runs, or the scenario's `employerChargesPercentage` when there is no history.

Lines roll up per unit, per function and per `costCenter`. Contracts ending within the year
stop counting at their `endDate`; mutations start at their `effectiveDate`.

### D3. Comparison

`GET /api/formation/compare?a&b&year` returns, per unit, FTE and cost for both scenarios
and the baseline (the formation with no mutations), with the differences. CSV export uses
the same payload.

### D4. Access

Both endpoints are `#[NoAdminRequired]` but refuse a caller who does not hold the `hr` or
`accountant` role in the administration (`AdministrationAccess`); salary figures are not
for managers. A `vastgesteld` scenario refuses new mutations (a lifecycle guard is not
needed; the mutation's create is refused by a pre-save listener in the
`ResourceBookingOverlapListener` shape).

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| scenario and mutation records | declarative schemas and manifest pages | plain data |
| status `concept` to `vastgesteld` | declarative `x-openregister-lifecycle` on `FormationScenario` | a one-way state |
| budget and comparison | imperative `PersonnelBudgetService` | cross-schema arithmetic over salaries, CAO tables and runs |
| refusing mutations on a fixed scenario | pre-save listener | a write-time rule on another schema |

## Seed data

- Scenario "Begroting 2027 basis" (no mutations, CAO raise 3 percent) and "Begroting 2027
  groei dienstverlening" (+2.0 FTE "Medewerker burgerzaken" in Team Burgerzaken from
  2027-03-01), on the Burgerzaken seed from `people-formation-positions`.

## Risks / Trade-offs

- [Salaries without history] → employer charges fall back to the scenario percentage and
  the line says which basis it used.
- [Unsourced CAO scales] → vacant FTE on such a place is flagged `unpriced` rather than
  priced from a placeholder figure, the `caoSchaalVerified` discipline the CAO library
  already keeps.

## Open Questions

- Should a scenario be able to copy another scenario's mutations? Not in this change.

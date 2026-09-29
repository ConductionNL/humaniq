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

## Build-time changes (2026-09-29)

Read against `development` 58668840 while building.

- **FTE norm.** An FTE is `hoursPerWeek` over `AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK`
  (40), the norm the formation occupancy already uses.
- **Month rule.** A contract or a change counts in a month when it applies on the first of
  that month; the answer states the rule in `basis.monthRule`.
- **Salary share.** An occupant's salary is split over their contracts in proportion to
  hours, so a person on two places is not counted twice. An approved, not yet applied
  `CompAdjustment` (status `approved`, `proposedSalary` in cents) replaces the salary from
  its effective date; an effective one is already in `grossMonthlySalary`.
- **Scales.** Reference jobs are HR21, so a vacant place is priced from `cao-gemeenten`
  through `CaoScaleLookup`. No CAO in the library carries a verified pay-scale leaf today,
  so every vacant FTE is flagged `unpriced` until one does; occupied cost is unaffected.
- **Holiday allowance** is the BW 7:634 minimum of 8 percent, a constructor default.
- **Access** reuses `AnalyticsAccess::fullReaderAdministration()` (hr or accountant in the
  active administration); every source is limited to that administration.
- **An unknown scenario on a mutation** is left to the register's own reference check; the
  listener only refuses a change to a fixed scenario. The demo import writes mutations on
  placeholder scenarios, which a stricter rule would drop.
- **Seeds** use the seed's own units: "Begroting 2027 basis" and "Begroting 2027 groei
  backoffice" (+2.0 FTE Medewerker backoffice from 2027-03-01); there is no Burgerzaken seed.
- **CSV export** is not built here: the export of an index or table belongs to the shared
  library (`rep-export`, owned by nextcloud-vue `index-export-follows-the-page`). The budget
  and comparison endpoints are plain JSON a spreadsheet can read.

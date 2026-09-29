# personnel-budget

## ADDED Requirements

### Requirement: humaniq SHALL compute a personnel budget for a year (REQ-PBS-001)

For a scenario and a year humaniq SHALL compute the annual personnel cost of the formation
per unit, per function and per cost centre: occupied FTE at the occupant's own salary,
vacant FTE at the minimum of the place's CAO scale, each raised by the scenario's CAO raise
and by approved compensation changes from their effective date, plus holiday allowance and
employer charges, counted for the months each FTE applies. Every line SHALL name its basis,
and a vacant place without a sourced scale SHALL be flagged unpriced instead of priced.

Rows: `td-personnel-budget` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415705.

#### Scenario: A finance officer reads next year's cost per cost centre
- **GIVEN** Team Burgerzaken with cost centre 4100, 4.6 occupied FTE and 0.4 vacant FTE on
  a function in schaal 8
- **WHEN** a user with the accountant role opens the 2027 baseline scenario
- **THEN** the budget shows the 2027 cost for cost centre 4100 split into occupied and
  vacant, with the salaries, the scale minimum, the months and the surcharge percentages
  it used

@e2e exclude the budget is computed server-side and the refusal is a pre-save listener; covered by PersonnelBudgetServiceTest::testAHandComputedBudget and FormationBudgetControllerTest::testTheBudgetShowsTheCostCentre

#### Scenario: A manager cannot read salaries through the budget
- **GIVEN** a manager without the hr or accountant role
- **WHEN** they request `GET /api/formation/budget` for their unit
- **THEN** the request is refused

@e2e exclude the budget is computed server-side and the refusal is a pre-save listener; covered by FormationBudgetControllerTest::testOnlyHrAndAccountantsReadTheBudget

### Requirement: Scenarios SHALL change FTE without touching the live formation (REQ-PBS-002)

humaniq SHALL let HR create formation scenarios holding mutations (FTE added to or removed
from a place, or a new place) from an effective date, and SHALL compare two scenarios and
the baseline per unit in FTE and cost. Mutations SHALL NOT change any `Formatieplaats` or
contract, and a scenario that is fixed SHALL accept no new mutations.

Rows: `td-formation-scenarios` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: Growing the service desk
- **GIVEN** a scenario adding 2.0 FTE "Medewerker burgerzaken" from 1 March 2027
- **WHEN** an HR adviser compares it with the baseline on `FormationScenarioDetail`
- **THEN** Team Burgerzaken shows 2.0 FTE more from March and the cost of ten months of
  that FTE, and the live place still shows its original budget

@e2e exclude the budget is computed server-side and the refusal is a pre-save listener; covered by PersonnelBudgetServiceTest::testTheGrowthScenarioDiffersFromTheBaselineFromMarch

#### Scenario: A fixed scenario is closed
- **GIVEN** a scenario in status `vastgesteld`
- **WHEN** someone adds a mutation to it
- **THEN** the write is refused

@e2e exclude the budget is computed server-side and the refusal is a pre-save listener; covered by ScenarioMutationListenerTest::testAFixedScenarioAcceptsNoMutation

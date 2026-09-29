# expenses-travel-calculation

## ADDED Requirements

### Requirement: A mileage claim SHALL compute its amount from the distance (REQ-TRV-001)

When a travel claim carries a positive distance, humaniq SHALL set the claim amount to the
distance times the employer's rate per kilometre, SHALL record the rate used, and SHALL split
the amount into a tax-free part at the corpus's tax-free rate and a taxable remainder. A claim
without a distance SHALL keep the amount the employee entered.

Rows: `exp-mileage` (humaniq matrix).

#### Scenario: An employee enters only the distance
@e2e exclude the amount is stamped server-side on save; covered by TravelAmountListenerTest::testAClaimWithOnlyADistanceGetsItsAmount and TravelAllowanceCalculatorTest::testAClaimAtTheTaxFreeRateIsAllTaxFree
- **GIVEN** the tax-free rate of 0.23 per kilometre and an employer rate equal to it
- **WHEN** an employee saves a business travel claim of 150 km on `ExpenseDetail` without an
  amount
- **THEN** the claim shows an amount of 34.50, all of it tax free

#### Scenario: The employer pays above the tax-free rate
@e2e exclude the employer rate is instance app config a browser run cannot set; covered by TravelAmountListenerTest::testAnEmployerRateAboveTheTaxFreeRateSplitsTheClaim and TravelAllowanceCalculatorTest::testAClaimAboveTheTaxFreeRateSplitsOffATaxablePart
- **GIVEN** an employer rate of 0.30 per kilometre
- **WHEN** an employee saves a business travel claim of 150 km
- **THEN** the claim shows 45.00, of which 34.50 is tax free and 10.50 is taxable

### Requirement: A commuting arrangement SHALL compute a fixed monthly allowance with the 214-day rule (REQ-TRV-002)

humaniq SHALL let an employee record a commuting arrangement with a one-way distance, days per
week, transport mode and period, approved by someone other than the employee. It SHALL compute
the monthly allowance as the one-way distance times two, times the rate per kilometre, times
214 working days scaled by days per week over five, divided by twelve, and SHALL split it into
tax-free and taxable parts.

Rows: `td-commute-allowance` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415705.

#### Scenario: An HR adviser approves a four-day commute
@e2e exclude the allowance is stamped server-side on save; covered by TravelAmountListenerTest::testAnArrangementGetsItsMonthlyAllowanceAndTheEmployeesAccount and TravelAllowanceCalculatorTest::testTheSeededCommuteIs11813AMonth
- **GIVEN** an employee who submits an arrangement of 18 km one way, 4 days a week, by car
- **WHEN** an HR adviser approves it
- **THEN** the arrangement shows a monthly allowance of 118.13, all of it tax free

#### Scenario: The employee cannot approve their own arrangement
@e2e exclude the refusal is NoSelfApprovalGuard on the userId the listener stamps; covered by TravelAmountListenerTest::testAnArrangementGetsItsMonthlyAllowanceAndTheEmployeesAccount (userId stamped) and the guard's own NoSelfApprovalGuardTest
- **GIVEN** a submitted arrangement
- **WHEN** the employee who submitted it tries to approve it
- **THEN** the transition is refused

### Requirement: The commuting distance SHALL be obtainable from a route planner through integriq (REQ-TRV-003)

humaniq SHALL offer to fill the one-way distance of an arrangement from a route-distance
source that integriq provides, SHALL record that the distance came from a route planner and
from which provider, and SHALL keep manual entry working when integriq or the source is
absent.

Rows: `td-commute-allowance` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415705.

#### Scenario: Distance from the route planner
@e2e exclude needs integriq with a configured route planner source; covered by RouteDistanceServiceTest::testTheRoutePlannerAnswersTheDistance and TravelControllerTest::testTheRoutePlannerDistanceIsSavedWithItsSource
- **GIVEN** integriq with a configured route-distance source and an arrangement from postcode
  2611 to postcode 2628
- **WHEN** an HR adviser chooses "Calculate distance" on the arrangement
- **THEN** the arrangement carries the returned distance, source `routeplanner`, and a
  recomputed allowance

#### Scenario: No route planner installed
@e2e exclude a 409 is asserted at the endpoint; covered by RouteDistanceServiceTest::testWithoutIntegriqThereIsNoRoutePlanner and TravelControllerTest::testWithoutARoutePlannerTheTypedDistanceIsKept
- **GIVEN** an instance without integriq
- **WHEN** the HR adviser chooses "Calculate distance"
- **THEN** the page says no route planner is available and the typed distance is kept

### Requirement: Trips SHALL carry a transport mode and humaniq SHALL compile the yearly WPM kilometres (REQ-TRV-004)

Travel claims and commuting arrangements SHALL carry a transport mode and, for motor vehicles,
a fuel type. For an administration and a year, humaniq SHALL compile business kilometres from
approved and reimbursed claims and commuting kilometres from approved arrangements, per
transport mode and fuel type, SHALL state whether the administration employed 100 or more
people in that year, and SHALL list the trips that carry no transport mode.

Rows: `td-wpm-co2` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: An HR adviser compiles the 2026 report
@e2e exclude needs approved claims and arrangements in one year; covered by WpmReportServiceTest::testTheReportSumsBusinessAndCommutingKilometresPerModeAndFuel and TravelControllerTest::testOnlyHrCompilesTheMobilityReport
- **GIVEN** administration ADM-001 with 150 km of approved business claims by petrol car and
  one approved commuting arrangement of 18 km one way, 4 days a week, by petrol car, active all
  of 2026
- **WHEN** an HR adviser runs "Compile WPM report" for ADM-001 and 2026
- **THEN** the report shows 150 business kilometres and 6163.2 commuting kilometres for car
  and gasoline, and states the employee count against the 100-employee threshold

#### Scenario: A trip without a mode is listed, not dropped
@e2e exclude covered by WpmReportServiceTest::testATripWithoutAModeIsListedAndNotCounted
- **GIVEN** an approved business claim with a distance and no transport mode
- **WHEN** the report is compiled
- **THEN** its kilometres are not counted under any mode and the claim is listed as missing a
  mode

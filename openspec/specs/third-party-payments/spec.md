# third-party-payments Specification

## Purpose
An employer that pays people who are neither employees nor entrepreneurs (a guest lecturer, a committee member, a volunteer paid above the tax-free amount) records those payees and payments in humaniq, assembles the yearly report of payments to third parties (UBD, formerly IB47) in the Belastingdienst's delivery format, and is told when the report is late or a payee cannot be identified. Built by filings-ib47 (archived 2026-10-01).

## Requirements

### Requirement: Payments to third parties SHALL be recorded and reported yearly (REQ-UBD-001)

humaniq SHALL record payees who are neither employees nor entrepreneurs and each payment to
them, and SHALL assemble per administration and year the report of payments to third parties
(UBD, formerly IB47) with one line per payee, validated against the year's format and attached
to a report that moves from concept to ready to sent. A line SHALL hold the payee's payments
and expense allowances of the year summed and rounded down to whole euros, dated on the last
payment. A payee without BSN, date of birth or address, or an administration without a payroll
tax number or postal address, SHALL be a blocking finding, and a report with a blocking finding
SHALL NOT be made ready.

Rows: `fil-ib47` (humaniq matrix).

#### Scenario: A school reports its guest lecturers
- **GIVEN** two guest lecturers paid three times in 2026
- **WHEN** a payroll officer makes the 2026 report ready
- **THEN** the report carries a file with two lines holding each lecturer's total, ready to
  upload

@e2e exclude the message is assembled and validated server-side; covered by ThirdPartyReportServiceTest::testTwoPayeesThreePaymentsMakeTwoLines and the golden file ThirdPartyReportServiceTest::testTheMessageMatchesTheGoldenFile (tests/fixtures/ubd/ubd-2026-adm-001.xml)

#### Scenario: A payee without a BSN stops the report
- **GIVEN** a payee paid in 2026 without a BSN
- **WHEN** a payroll officer assembles the 2026 report and tries to make it ready
- **THEN** the report lists a blocking finding naming the payee and making it ready is refused

@e2e exclude the finding and the refusal are server-side; covered by ThirdPartyReportServiceTest::testAPayeeWithoutABsnIsABlockingFinding, UbdReportReadyGuardTest::testAPayeeWithoutABsnRefusesWithTheFinding and LifecycleGuardRegistrationTest

### Requirement: A late report and an unidentified payee SHALL be flagged (REQ-UBD-002)

The rules audit SHALL flag a year with payments whose report is not sent by 31 January of the
next year, and a payee with payments but no BSN or date of birth.

Rows: `fil-ib47` (humaniq matrix).

#### Scenario: February without a report
- **GIVEN** payments in 2026 and no 2026 report sent
- **WHEN** the rules audit runs on 1 February 2027
- **THEN** the missing report is flagged as a mandatory violation

@e2e exclude the rules audit runs under occ; covered by NlThirdPartyChecksTest::testFebruaryWithoutAReport and NlThirdPartyChecksTest::testBothRulesAreDeclaredMandatory

### Requirement: A payee SHALL be able to get a yearly statement (REQ-UBD-003)

humaniq SHALL generate for every payee a report covers a yearly statement (document type
`ubd-jaaropgaaf`) of the payments and the amount reported, through filinq's template store,
and SHALL store it with the payee. Without filinq, or without exactly one template for the
type, it SHALL say so and generate nothing.

Rows: `fil-ib47` (humaniq matrix).

#### Scenario: Statements for a sent report
- **GIVEN** a sent 2026 report covering two payees and one filinq template for `ubd-jaaropgaaf`
- **WHEN** a payroll officer presses Statements on the report
- **THEN** each payee gets a statement with the year's payments and the reported total

@e2e exclude rendering needs filinq and its template (cross-app); covered by ThirdPartyStatementServiceTest::testTheVariableContract, ThirdPartyStatementServiceTest::testGenerationThroughFilinq, ThirdPartyStatementServiceTest::testGenerationForAReport and ThirdPartyReportControllerTest::testTheStatementsResolveTheReportFirst

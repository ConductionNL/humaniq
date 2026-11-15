# third-party-payments

## ADDED Requirements

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

#### Scenario: A payee without a BSN stops the report
- **GIVEN** a payee paid in 2026 without a BSN
- **WHEN** a payroll officer assembles the 2026 report and tries to make it ready
- **THEN** the report lists a blocking finding naming the payee and making it ready is refused

### Requirement: A late report and an unidentified payee SHALL be flagged (REQ-UBD-002)

The rules audit SHALL flag a year with payments whose report is not sent by 31 January of the
next year, and a payee with payments but no BSN or date of birth.

Rows: `fil-ib47` (humaniq matrix).

#### Scenario: February without a report
- **GIVEN** payments in 2026 and no 2026 report sent
- **WHEN** the rules audit runs on 1 February 2027
- **THEN** the missing report is flagged as a mandatory violation

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


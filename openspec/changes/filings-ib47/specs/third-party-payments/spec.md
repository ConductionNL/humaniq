# third-party-payments

## ADDED Requirements

### Requirement: Payments to third parties SHALL be recorded and reported yearly (REQ-UBD-001)

humaniq SHALL record payees who are neither employees nor entrepreneurs and each payment to
them, and SHALL assemble per administration and year the report of payments to third parties
(UBD, formerly IB47) with one line per payee, validated against the year's format and attached
to a report that moves from concept to ready to sent.

Rows: `fil-ib47` (humaniq matrix).

#### Scenario: A school reports its guest lecturers
- **GIVEN** two guest lecturers paid three times in 2026
- **WHEN** a payroll officer makes the 2026 report ready
- **THEN** the report carries a file with two lines holding each lecturer's total, ready to
  upload

### Requirement: A late report and an unidentified payee SHALL be flagged (REQ-UBD-002)

The rules audit SHALL flag a year with payments whose report is not sent by 31 January of the
next year, and a payee with payments but no BSN or date of birth.

Rows: `fil-ib47` (humaniq matrix).

#### Scenario: February without a report
- **GIVEN** payments in 2026 and no 2026 report sent
- **WHEN** the rules audit runs on 1 February 2027
- **THEN** the missing report is flagged as a mandatory violation

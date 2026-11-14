# hr-signals

## ADDED Requirements

### Requirement: HR SHALL be signalled before a probation period ends (REQ-SIG-007)

A contract with a `proeftijdEndDate` SHALL raise an advisory signal from 14 days before that
date until it passes. The signal SHALL name the employee, the contract and the end date. It
SHALL show on the Dashboard obligations list beside expiring contracts.

Rows: `ppl-contract-expiry-signal` (humaniq matrix).

#### Scenario: A probation period ends next week
- **GIVEN** a contract whose `proeftijdEndDate` is 10 days from today
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** the contract carries an advisory probation-end signal with that date
- **AND** the Dashboard obligations list shows it

#### Scenario: A probation period that ended raises nothing
- **GIVEN** a contract whose `proeftijdEndDate` was yesterday
- **WHEN** the audit runs
- **THEN** no probation-end signal is reported for that contract

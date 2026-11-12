# hris-api-public

## ADDED Requirements

### Requirement: An integrator SHALL read and write HR records through the objects API with an app password (REQ-HRIS-006)

An external system SHALL read and write humaniq records through OpenRegister's objects API at
`/api/objects/humaniq/{schema}`, signed in with a Nextcloud app password of a service account.
Every call SHALL get exactly the access that account has in the app: the same role checks, the
same field access and the same administration scope as a person signed in with that account.
humaniq SHALL document the calls an integrator needs for the employee and the contract.

Rows: `plt-public-api` (humaniq matrix).

#### Scenario: A payroll bureau reads an employee
- **GIVEN** a service account in the payroll group with an app password
- **WHEN** the bureau calls `GET /api/objects/humaniq/Employee/{id}` with that app password
- **THEN** the response holds the employee with the fields the payroll role may read

#### Scenario: An identity manager updates a work e-mail address
- **GIVEN** a service account in the HR group with an app password
- **WHEN** it calls `PATCH /api/objects/humaniq/Employee/{id}` with a new work e-mail address
- **THEN** the employee carries the new address and the audit trail names the service account

#### Scenario: An account without the role gets nothing
- **GIVEN** a service account in no humaniq group
- **WHEN** it calls `GET /api/objects/humaniq/Employee`
- **THEN** no employee is returned

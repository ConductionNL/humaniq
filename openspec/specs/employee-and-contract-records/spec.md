---
capability: employee-and-contract-records
status: done
built_by: none (the Employee and EmploymentContract records predate OpenSpec in humaniq; written after the fact on 2026-10-07)
---

# employee-and-contract-records Specification

**Status**: done
**Scope**: humaniq

**OpenSpec changes**: none. This spec describes what the code on `development` does today. Later
changes that extend these two schemas (payroll, flex contracts, CAO components, formation
positions and others) keep their own specs and are not repeated here.

## Purpose

Every HR and payroll feature in humaniq hangs off two records: the employee and the employment
contract. An HR adviser keeps one record per person with personal, contact and employment
details, and one record per contract with its type, hours, wage and dates. Both are OpenRegister
schemas in `lib/Settings/register.d/hr-objects.json`, shown through the manifest pages
`Employees`, `EmployeeDetail`, `EmploymentContracts` and `EmploymentContractDetail`.

This spec also fixes two properties of the employee record that the matrix asks about: which
values are checked on entry, and that the citizen service number (BSN) is never used as a key.

## Requirements

### Requirement: humaniq SHALL keep one record per employee (REQ-ECR-001)

The `Employee` schema (`lib/Settings/register.d/hr-objects.json:5`) SHALL hold the person's
identity (`employeeNumber`, `bsn`, `firstName`, `lastName`, `dateOfBirth`), contact and address
(`privateEmail`, `phone`, `straat`, `huisnummer`, `postcode`, `woonplaats`, `land`), employment
(`startDate`, `endDate`, `endReason`, `grossMonthlySalary`, `taxTableColor`) and payment
(`iban`, `tenaamstelling`) details. `lastName` and `startDate` MUST be present. The `Employees`
page (`/employees`, menu entry in `src/manifest.d/05-menu.json:173`) SHALL list employees by
`employeeNumber`, `firstName`, `lastName`, `startDate`, `endDate` and `taxTableColor`, sorted by
last name and filtered to the active administration. The `EmployeeDetail` page
(`/employees/:id`, `src/manifest.d/hr-objects.json:4`) SHALL show the person's data with the
records that point at them (contracts, timesheets, payslips, expenses and the later additions).

Rows: `ppl-employee-record` (humaniq matrix).

#### Scenario: An HR adviser opens the employee list
@e2e exclude spec written after the fact and this round changes no test; the behaviour is exercised by tests/e2e/spec-coverage/core-journeys.spec.ts, which does not yet carry this scenario's tag
- **GIVEN** an HR adviser
- **WHEN** they open `/employees`
- **THEN** the page shows the employee list, or its empty state, and an add button that opens
  the create dialog

#### Scenario: An HR adviser adds an employee
@e2e exclude the create dialog is nextcloud-vue's generic form; the e2e journey opens and cancels it, the save itself is OpenRegister's object API
- **GIVEN** an HR adviser on the Employees page
- **WHEN** they open the create dialog, enter a last name and a start date and save
- **THEN** a new Employee object exists in the humaniq register and appears in the list

#### Scenario: A record without a last name is refused
@e2e exclude enforced by OpenRegister schema validation on the `required` list; no humaniq code runs
- **GIVEN** the create dialog on the Employees page
- **WHEN** the adviser saves without a last name
- **THEN** OpenRegister refuses the object and nothing is stored

#### Scenario: The detail page shows the person and their records
@e2e exclude spec written after the fact and this round changes no test; the behaviour is exercised by tests/e2e/spec-coverage/core-journeys.spec.ts, which does not yet carry this scenario's tag
- **GIVEN** a seeded employee
- **WHEN** an HR adviser opens `/employees/{id}`
- **THEN** the page shows the seeded field values

### Requirement: humaniq SHALL keep one record per employment contract (REQ-ECR-002)

The `EmploymentContract` schema SHALL reference its employee through `employeeId` (`$ref`
Employee) and SHALL hold the contract `type` (permanent, fixed-term, agency, mini-job, BBL
apprenticeship, on-call), `writtenContract`, `startDate`, `endDate`, `hoursPerWeek`,
`hourlyWage`, the CAO and pay scale, and the applied Awf tariff. `employeeId`, `type` and
`startDate` MUST be present. A person with two jobs SHALL have two contracts. The
`EmploymentContracts` page (`/contracts`) SHALL list contracts by employee, type, start, end,
hourly wage and Awf tariff, newest start first, filterable on type and Awf tariff. The
`EmploymentContractDetail` page (`/contracts/:id`) SHALL show the contract with a link to its
employee.

Rows: `ppl-contracts` (humaniq matrix).

#### Scenario: An HR adviser records a fixed-term contract
@e2e exclude the contract pages are generic manifest index and detail pages; their mount is covered by the manifest-pages spec and the schema by OpenRegister validation
- **GIVEN** an existing employee
- **WHEN** an HR adviser creates a contract with type fixed-term, 32 hours a week and an end date
- **THEN** the contract appears on the Contracts page and in the contract list on that
  employee's detail page

#### Scenario: A contract without an employee is refused
@e2e exclude enforced by OpenRegister schema validation on the `required` list; no humaniq code runs
- **GIVEN** the contract create dialog
- **WHEN** the adviser saves without choosing an employee
- **THEN** OpenRegister refuses the object and nothing is stored

### Requirement: Entered identity and bank values SHALL be checked for shape only (REQ-ECR-003)

On every create and update, OpenRegister schema validation SHALL check `Employee.iban` against
the pattern `^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$` and `Employee.dateOfBirth` as an ISO 8601
date. The IBAN check MUST NOT be read as a mod-97 checksum: the schema's own note makes the
checksum a non-goal. `Employee.bsn` carries no pattern, so neither its length nor the eleven
test (elfproef) is checked today, and no plausibility or age check runs on the date of birth.
A missing IBAN SHALL block the employee from a net-pay batch (`nl-netpay-iban-present`), and a
missing BSN SHALL make payroll apply the anonymous rate (`nl-anoniementarief`).

Rows: `ppl-bsn-iban-validation` (humaniq matrix, rated partial for the missing BSN and checksum
checks).

#### Scenario: A malformed IBAN is refused
@e2e exclude enforced by OpenRegister schema validation on the declared pattern; no humaniq code runs
- **GIVEN** an employee record being edited
- **WHEN** the adviser enters `NL12 3456` as the IBAN and saves
- **THEN** OpenRegister refuses the save because the value does not match the pattern

#### Scenario: A well-formed IBAN with a wrong checksum is accepted
@e2e exclude documents a known gap rather than behaviour humaniq adds; see the row's note
- **GIVEN** an employee record being edited
- **WHEN** the adviser enters an IBAN with the right shape and a wrong check digit
- **THEN** the save succeeds, because only the shape is checked

### Requirement: The BSN SHALL NOT be a key or identifier (REQ-ECR-004)

`Employee.bsn` SHALL be an ordinary property (`lib/Settings/register.d/hr-objects.json:19`).
Employee records SHALL be keyed by their OpenRegister UUID, with `employeeNumber` as the
human-readable number, and every other schema SHALL point at an employee through
`employeeId`, never through the BSN. Reading the BSN SHALL be limited to the HR group, the
payroll group and the employee themselves, and changing it to the HR group (see
`humaniq-roles-and-field-access` REQ-RFA-002). A record that has to name a person without
holding the BSN, such as `DsrRequest`, SHALL store the employee link only (see `avg-dsr`
REQ-DSR-002). Because no key or identifier carries the BSN, there is nothing to clear from
existing keys.

Rows: `dm-bsn-not-key` (humaniq matrix).

#### Scenario: A contract points at its employee by UUID
@e2e exclude a schema property, checked by reading `lib/Settings/register.d/hr-objects.json`; no runtime path to drive
- **GIVEN** an employee with a BSN
- **WHEN** a contract is created for that employee
- **THEN** the contract stores the employee's UUID in `employeeId` and no copy of the BSN

#### Scenario: A manager does not see the BSN
@e2e exclude OpenRegister strips the field on read; pinned by FieldAuthorizationDeclarationTest::testEmployeeFieldsAreReadByHrPayrollAndTheEmployee
- **GIVEN** a manager who may open an employee in their team
- **WHEN** they open that employee
- **THEN** the BSN is not returned

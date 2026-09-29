# register-prefill Specification

## Purpose
Filling employee and vehicle records from the BRP and the RDW through integriq, never overwriting a stored value silently. Built by people-register-prefill (archived 2026-09-29).

## Requirements

### Requirement: A company car SHALL be filled from the vehicle register (REQ-RPF-001)

On a vehicle `Asset` with a licence plate, humaniq SHALL offer to fill make, model, first
admission date, catalogue value and fuel type from the RDW vehicle register through a
declared integriq connection. It SHALL write only empty fields and SHALL list every field
where the register differs from the stored value without changing it.

Rows: `dm-plate-lookup` (humaniq matrix).

#### Scenario: A new lease car is filled from its plate
- **GIVEN** a vehicle asset with only its licence plate entered
- **WHEN** a fleet administrator chooses "Fill from RDW" on `AssetDetail`
- **THEN** make, model, first admission date, catalogue value and fuel type are filled from
  the register

@e2e exclude the RDW answer comes through integriq from an outside register; covered by RegisterPrefillServiceTest::testANewLeaseCarIsFilledFromItsPlate and RegisterPrefillControllerTest::testHrFillsACarAndTheFilledFieldsAreSaved

#### Scenario: A stored catalogue value is not overwritten
- **GIVEN** a vehicle asset whose catalogue value was entered as 41,000 and the register says
  42,500
- **WHEN** the lookup runs
- **THEN** the stored 41,000 stays, and the result lists the catalogue value as differing
  with both amounts

@e2e exclude the merge is decided server-side; covered by RegisterPrefillServiceTest::testAStoredCatalogueValueIsNotOverwritten

### Requirement: An employee SHALL be filled from the BRP only on a legal basis (REQ-RPF-002)

When the administration records a legal basis for BRP use, humaniq SHALL offer to fill an
employee's name, date of birth and address from the BRP by BSN through a declared integriq
connection, with the same fill-empty and report-different rule. Without a recorded basis the
action SHALL NOT be offered and the endpoint SHALL refuse.

Rows: `ppl-brp-lookup` (humaniq matrix).

#### Scenario: A municipality fills a new employee's address
- **GIVEN** an administration with a recorded BRP basis and a new employee with a BSN and no
  address
- **WHEN** an HR adviser chooses "Fill from BRP" on `EmployeeDetail`
- **THEN** the address fields are filled from the BRP

@e2e exclude the BRP needs a certificate held by the credential broker; covered by RegisterPrefillServiceTest::testAMunicipalityFillsANewEmployeesAddress and RegisterPrefillControllerTest::testWithARecordedBasisTheEmployeeIsFilled

#### Scenario: No basis, no lookup
- **GIVEN** an administration without a recorded BRP basis
- **WHEN** a caller posts to `POST /api/prefill/employee/{employeeId}`
- **THEN** the request is refused and nothing is sent to the BRP

@e2e exclude the refusal is decided server-side; covered by RegisterPrefillControllerTest::testWithoutARecordedBasisNothingIsSentToTheBrp

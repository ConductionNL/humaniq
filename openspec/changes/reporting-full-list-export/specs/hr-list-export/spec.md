# hr-list-export

## ADDED Requirements

### Requirement: Every humaniq list SHALL offer an export of the filtered list (REQ-HLE-001)

Every index page in humaniq SHALL show the Export menu. Choosing CSV or Excel SHALL download
every row that matches the page's current filters, search and quick filter, not only the
fetched page. Who may export SHALL be decided by OpenRegister's export right.

Rows: `rep-export` (humaniq matrix).

#### Scenario: HR exports all active employees
- **GIVEN** 340 employees, of whom 300 are active, and an HR adviser on the Employees list
  filtered to active
- **WHEN** the adviser chooses Export, then Excel
- **THEN** the downloaded file holds the 300 active employees

#### Scenario: A self-service list exports only the user's own rows
- **GIVEN** an employee on their own expenses list
- **WHEN** they choose Export, then CSV
- **THEN** the file holds only their own expense claims

### Requirement: Health data and data subject requests SHALL NOT be exportable in bulk (REQ-HLE-002)

The schemas `SickLeaveCase` and `DsrRequest` SHALL NOT be marked exportable. Their lists
SHALL NOT show the Export menu. Every other humaniq schema SHALL be marked exportable.

#### Scenario: The sick leave list has no export
- **WHEN** a case manager opens the sick leave cases list
- **THEN** the Export menu is not shown

#### Scenario: A test guards the exceptions
- **WHEN** a schema in `hr-verzuim.json` or `hr-dsr.json` is marked exportable
- **THEN** the register unit test fails and names the schema

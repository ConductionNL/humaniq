# hr-list-export Specification

## Purpose
Every humaniq list offers a mass action called Export. It exports only the rows already fetched or selected. The full export, which exports the whole filtered list as CSV or Excel through OpenRegister, is off on every page: no humaniq page sets `allowExport` and no schema sets `exportable` (`grep -rn 'allowExport|exportable' src/manifest.d lib/Settings/register.d` finds nothing).

## Requirements

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
- @e2e tests/e2e/spec-coverage/hr-list-export.spec.ts

#### Scenario: A self-service list exports only the user's own rows
- **GIVEN** an employee on their own expenses list
- **WHEN** they choose Export, then CSV
- **THEN** the file holds only their own expense claims
- @e2e tests/e2e/spec-coverage/hr-list-export.spec.ts

### Requirement: Health data and data subject requests SHALL NOT be exportable in bulk (REQ-HLE-002)

The schemas `SickLeaveCase` and `DsrRequest` SHALL NOT be marked exportable. Their lists
SHALL NOT show the Export menu. Every other humaniq schema SHALL be marked exportable.

#### Scenario: The sick leave list has no export
- **WHEN** a case manager opens the sick leave cases list
- **THEN** the Export menu is not shown
- @e2e tests/e2e/spec-coverage/hr-list-export.spec.ts

#### Scenario: A test guards the exceptions
- **WHEN** a schema in `hr-verzuim.json` or `hr-dsr.json` is marked exportable
- **THEN** the register unit test fails and names the schema

@e2e exclude a property of the shipped register files, not of a running instance; covered by ListExportDeclarationTest::testSickLeaveAndDsrSchemasAreNeverExportable and ::testEveryOtherSchemaIsExportable

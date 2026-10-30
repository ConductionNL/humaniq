# employee-and-contract-records

## ADDED Requirements

### Requirement: A field an administrator adds to the Employee schema SHALL show and save on the employee page (REQ-ECR-005)

An administrator SHALL add their own property to the Employee schema through OpenRegister's
schema screens, without a humaniq release. The employee page SHALL show every such property in
an Eigen velden card, and an HR adviser SHALL edit and save its value there like any other field.
The card SHALL show only properties humaniq does not ship. An app update SHALL keep the added
property and its values. Administrators SHALL see a Velden beheren link that opens the Employee
schema in OpenRegister.

Rows: `ppl-custom-fields` (humaniq matrix). Board: `humaniq/HuMedewerker`, card Eigen velden.

#### Scenario: An added field shows and saves
- **GIVEN** an administrator added a text property "Rijbewijs" to the Employee schema
- **WHEN** an HR adviser opens an employee page, enters "B" in Rijbewijs and saves
- **THEN** the Eigen velden card shows Rijbewijs "B" after a reload

#### Scenario: Shipped fields do not show twice
- **GIVEN** no property added to the Employee schema
- **WHEN** an HR adviser opens an employee page
- **THEN** the Eigen velden card lists no field and the shipped fields show only in their own cards

#### Scenario: An update keeps the added field
- **GIVEN** an employee with a value in the added property "Rijbewijs"
- **WHEN** the administrator updates humaniq and the register is imported again
- **THEN** the property and the employee's value are still there

#### Scenario: Only an administrator sees the manage link
- **GIVEN** an HR adviser who is not an administrator
- **WHEN** they open an employee page
- **THEN** the Eigen velden card shows no Velden beheren link

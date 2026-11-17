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

@e2e exclude Needs an administrator to change the shipped Employee schema on the shared e2e instance, which every other run would then see. That the card shows exactly the properties humaniq does not ship is pinned by tests/Unit/Manifest/EmployeeCustomFieldsManifestTest.php; the field is saved through the object API like any other; the live check is task 3.2.

#### Scenario: Shipped fields do not show twice
- **GIVEN** no property added to the Employee schema
- **WHEN** an HR adviser opens an employee page
- **THEN** the Eigen velden card lists no field and the shipped fields show only in their own cards

@e2e exclude What each card shows follows from the manifest's include and exclude lists alone; tests/Unit/Manifest/EmployeeCustomFieldsManifestTest.php asserts Own fields excludes exactly the shipped properties and the other cards name theirs, with no field in two cards.

#### Scenario: An update keeps the added field
- **GIVEN** an employee with a value in the added property "Rijbewijs"
- **WHEN** the administrator updates humaniq and the register is imported again
- **THEN** the property and the employee's value are still there

@e2e exclude An app update cannot run inside an e2e session. ConductionNL/openregister tests/Unit/Service/Configuration/ImportHandlerKeepsAddedPropertyTest.php (openregister#10564) imports, adds a property, imports the update and reads the property back.

#### Scenario: Only an administrator sees the manage link
- **GIVEN** an HR adviser who is not an administrator
- **WHEN** they open an employee page
- **THEN** the Eigen velden card shows no Velden beheren link

@e2e exclude The e2e instance logs in as an administrator only. tests/Unit/Manifest/EmployeeCustomFieldsManifestTest.php asserts the Manage fields action carries the manifest's adminOnly flag.

# Specs: payroll-cao-mvp — CAO Library

## ADDED Requirements

---

### REQ-CAO-001: Pre-built CAO library completeness

The system MUST ship exactly 10 pre-built CAO rulesets at install time: Schoonmaak, Horeca, Kappersbedrijf, Detailhandel non-food, Metaal & Techniek, Bouw, ICT, Zorg VVT, Beveiliging, and Algemeen (geen CAO). Each CAO MUST include salary scales, working hours, leave entitlements, and allowance rules encoded as a JSON ruleset.

#### Scenario: Fresh install contains all 10 CAOs

- **GIVEN** a Nextcloud instance with HRMQ freshly installed
- **WHEN** the OpenRegister `hrmq/cao` schema is queried
- **THEN** exactly 10 CAO objects are returned, one per sector identifier: `schoonmaak`, `horeca`, `kappers`, `detailhandel-non-food`, `metaal-techniek`, `bouw`, `ict`, `zorg-vvt`, `beveiliging`, `algemeen`
- **AND** each CAO object contains a non-empty `rules.salaryScales` array with at least one scale and one step

#### Scenario: Upgrade is idempotent

- **GIVEN** HRMQ is already installed with CAO rulesets present
- **WHEN** a Nextcloud upgrade runs the `InstallCaoRulesets` repair step again
- **THEN** no duplicate CAO objects are created
- **AND** existing `isActive` flags are preserved

---

### REQ-CAO-002: CAO listing and detail retrieval

Administrators MUST be able to retrieve a paginated list of all available CAOs and fetch full detail for any individual CAO including its complete JSON ruleset.

#### Scenario: List all CAOs

- **GIVEN** a Nextcloud admin user is authenticated
- **WHEN** `GET /index.php/apps/hrmq/api/caos` is called
- **THEN** the response has HTTP 200 with a JSON body containing `total`, `page`, `pages`, and a `results` array
- **AND** every item in `results` includes `id`, `name`, `identifier`, `version`, `schema:startDate`, `minimumHourlyRate`, `standardWeeklyHours`, and `isActive`

#### Scenario: Retrieve single CAO with full ruleset

- **GIVEN** a Nextcloud admin user is authenticated
- **WHEN** `GET /index.php/apps/hrmq/api/caos/{id}` is called with a valid CAO id
- **THEN** the response has HTTP 200 with the complete CAO object including `rules.salaryScales`, `rules.workingHours`, `rules.leaveEntitlements`, and `rules.allowances`

#### Scenario: Unknown CAO returns 404

- **GIVEN** a Nextcloud admin user is authenticated
- **WHEN** `GET /index.php/apps/hrmq/api/caos/nonexistent-id` is called
- **THEN** the response has HTTP 404 with a `message` field and NO stack trace

---

### REQ-CAO-003: CAO activation per organisation

An administrator MUST be able to activate or deactivate a CAO for their organisation. Only active CAOs are selectable when linking an employee contract to a CAO.

#### Scenario: Activate a CAO

- **GIVEN** a Nextcloud admin user is authenticated
- **AND** the CAO with identifier `bouw` has `isActive: false` for this organisation
- **WHEN** `PUT /index.php/apps/hrmq/api/caos/{id}/activate` is called with body `{"isActive": true}`
- **THEN** the response has HTTP 200
- **AND** a subsequent `GET /index.php/apps/hrmq/api/caos/{id}` returns `isActive: true` for this organisation

#### Scenario: Non-admin cannot activate CAOs

- **GIVEN** a regular (non-admin) Nextcloud user is authenticated
- **WHEN** `PUT /index.php/apps/hrmq/api/caos/{id}/activate` is called
- **THEN** the response has HTTP 403
- **AND** no CAO activation state is changed

---

### REQ-CAO-004: Employee-contract CAO assignment

An administrator MUST be able to assign an active CAO to an employee's contract. The assigned CAO is stored as an OpenRegister relation on the `Contract` object.

#### Scenario: Assign CAO to a contract

- **GIVEN** an active contract exists for employee "Jan de Vries" with contractId `abc-123`
- **AND** the CAO `horeca` is active for this organisation
- **WHEN** the admin selects CAO "CAO Horeca" on the contract edit screen and saves
- **THEN** the `Contract` object's `cao` relation field is set to the UUID of the Horeca CAO object
- **AND** the contract detail screen displays "CAO: CAO Horeca 2026"

#### Scenario: Only active CAOs appear in the contract CAO selector

- **GIVEN** the CAO `kappers` has `isActive: false` for this organisation
- **WHEN** an admin opens the CAO selector on an employee's contract form
- **THEN** "CAO Kappersbedrijf" does NOT appear in the dropdown
- **AND** only CAOs with `isActive: true` are listed

#### Scenario: Contract without CAO is valid (Algemeen)

- **GIVEN** an employee works under no specific CAO
- **WHEN** the admin assigns the `algemeen` CAO (identifier: `algemeen`) to the contract
- **THEN** the contract is saved successfully
- **AND** the `rules` for `algemeen` contain only the statutory WML minimums with no sector additions

---

### REQ-CAO-005: CAO minimum salary enforcement (payroll integration)

When payroll-core-basic runs a bruto-netto calculation, it MUST fetch the linked CAO's rules and validate that the employee's gross salary meets or exceeds the CAO minimum for their salary scale and step.

#### Scenario: Salary below CAO minimum is flagged

- **GIVEN** an employee is linked to "CAO Schoonmaak" scale A step 1 (€13.68/hour)
- **AND** the employee's contract specifies a gross hourly rate of €13.00
- **WHEN** the payroll run is executed for this employee
- **THEN** the payroll result includes a `warnings` array with an entry `"Bruto uurloon €13.00 is lager dan CAO-minimum €13.68 (Schoonmaak schaal A stap 1)"`
- **AND** the payroll run does NOT abort — it continues with the entered salary and flags the discrepancy

#### Scenario: Salary at or above CAO minimum passes without warning

- **GIVEN** an employee is linked to "CAO Horeca" scale II step 2 (€14.55/hour)
- **AND** the employee's contract specifies a gross hourly rate of €15.00
- **WHEN** the payroll run is executed for this employee
- **THEN** the payroll result `warnings` array does NOT contain any CAO minimum salary warning

---

### REQ-CAO-006: CAO working hours rules retrieval

The payroll engine and admin UI MUST be able to retrieve working hours rules (standard week hours, overtime premium, maximum overtime) from the linked CAO for use in overtime calculation.

#### Scenario: Overtime premium applied from CAO rules

- **GIVEN** an employee is linked to "CAO Bouw" which specifies `overtimePremiumPercent: 50`
- **AND** the employee worked 5 hours overtime in the pay period
- **WHEN** the payroll engine retrieves working hours rules via `CaoService::getWorkingHoursRules($caoId)`
- **THEN** the returned object contains `overtimePremiumPercent: 50`
- **AND** the payroll calculation applies a 50% premium on the 5 overtime hours

---

### REQ-CAO-007: Admin UI — CAO list and detail view

The Nextcloud admin panel MUST include a CAO management section with a list view showing all available CAOs and a detail view showing the full ruleset in a readable format.

#### Scenario: CAO list renders with activation toggle

- **GIVEN** an admin opens the HRMQ settings and navigates to the CAO section
- **WHEN** the CAO list view loads
- **THEN** all 10 CAOs are shown in a table or list with columns: name, sector, version, effective date, minimum hourly rate, and an active/inactive toggle
- **AND** toggling the activation state calls `PUT /api/caos/{id}/activate` with appropriate body

#### Scenario: CAO detail shows salary scales

- **GIVEN** an admin clicks on "CAO Horeca" in the list
- **WHEN** the CAO detail view loads
- **THEN** the salary scales table displays all scales (I, II, III) with their steps and hourly rates
- **AND** working hours, leave entitlements, and allowances sections are also rendered

#### Scenario: UI strings are translated (NL/EN)

- **GIVEN** the Nextcloud interface language is set to Dutch
- **WHEN** any CAO UI screen is displayed
- **THEN** all labels, headings, and action buttons are rendered in Dutch using `l10n/nl.json` translations
- **AND** no hardcoded Dutch strings appear in Vue templates

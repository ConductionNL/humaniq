# Delta — uitzend-flexpool

Modernises the 2026-05 `spec/uitzend-flexpool-integration` draft against current HEAD, reversing
its central design choice: hrmq serves the **uitzendbureau** (the uitzendkracht's actual employer
under WAADI), not the inlener, because hrmq's shipped product is a payroll engine and an inlener
has no payroll relationship to a temp worker at all. The existing `EmploymentContract.type: agency`
enum value — present in the schema but almost entirely unimplemented — gains the three fields and
two rules needed to make ABU/NBBU fasensysteem, uitzendbeding and inlenersbeloning tracking real,
reusing the existing CAO mechanism for wage data and confirming, not rebuilding, the already-
delivered rostering/time-attendance overlap.

## ADDED Requirements

### Requirement: hrmq SHALL model the uitzendkracht as the agency's own Employee, never a separate inlener-side entity (REQ-UITZ-001)

An uitzendkracht placed via an uitzendbureau SHALL be represented as an `Employee` with an `EmploymentContract` of `type: agency`, scoped to the administratie of the uitzendbureau running this hrmq instance. This change SHALL NOT introduce a `Bureau`, `InhuurOpdracht`, or any other schema representing a third-party vendor relationship or an inlener's booking of external labour.

#### Scenario: An agency-type contract is an ordinary Employee/EmploymentContract pair
- **GIVEN** an uitzendkracht placed at a client site
- **WHEN** their record is created in hrmq
- **THEN** it is one `Employee` object plus one `EmploymentContract` object with `type: agency` — no `Bureau`/`InhuurOpdracht` object is created, because neither schema exists

#### Scenario: No inlener-side schema exists in the register
- **GIVEN** the hrmq register after this change
- **WHEN** its schema list is enumerated
- **THEN** no schema named `Bureau`, `InhuurOpdracht`, or equivalent exists

### Requirement: EmploymentContract SHALL track fasensysteem stage and uitzendbeding applicability, with the beding structurally limited to fase A (REQ-UITZ-002)

`EmploymentContract` (`hr-objects.json`) SHALL gain `uitzendFase` (nullable enum `A`/`B`/`C`) and `uitzendbedingVanToepassing` (nullable boolean), both HR-entered. The corpus rule `nl-uitzendbeding-alleen-fase-a` (mandatory) SHALL flag any `agency`-type `EmploymentContract` where `uitzendbedingVanToepassing` is `true` and `uitzendFase` is not `A`, citing BW art. 7:691 lid 2 as the statutory basis. This change SHALL NOT assert a specific number of weeks defining fase A's duration.

#### Scenario: Beding true in fase A passes
- **GIVEN** an `agency`-type `EmploymentContract` with `uitzendFase: A` and `uitzendbedingVanToepassing: true`
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** no `nl-uitzendbeding-alleen-fase-a` violation is reported

#### Scenario: Beding true past fase A is flagged
- **GIVEN** the same contract with `uitzendFase: B` and `uitzendbedingVanToepassing: true`
- **WHEN** the audit runs
- **THEN** an `nl-uitzendbeding-alleen-fase-a` violation at severity `mandatory` is reported

#### Scenario: Beding false in any fase passes
- **GIVEN** a contract with `uitzendbedingVanToepassing: false` (any `uitzendFase` value, including null)
- **WHEN** the audit runs
- **THEN** no `nl-uitzendbeding-alleen-fase-a` violation is reported

#### Scenario: Non-agency contracts are never evaluated
- **GIVEN** a `permanent`-type `EmploymentContract` with `uitzendbedingVanToepassing` populated to `true`
- **WHEN** the audit runs
- **THEN** `nl-uitzendbeding-alleen-fase-a` does not evaluate it — the rule's guard is `type === 'agency'` only

### Requirement: An agency contract with a set hourly wage SHALL carry an inlenersbeloning reference (REQ-UITZ-003)

`EmploymentContract` SHALL gain `inlenersbeloningReferentie` (nullable string) — a free-text reference to the documentation backing the contracted `hourlyWage` against the inlener's comparable-function beloning (WAADI art. 8). The corpus rule `nl-inlenersbeloning-onderbouwing-vereist` (mandatory) SHALL flag any `agency`-type `EmploymentContract` with a populated `hourlyWage` and an empty or null `inlenersbeloningReferentie`. This change SHALL NOT validate the correctness of the referenced figure — only its presence.

#### Scenario: A populated reference passes
- **GIVEN** an `agency`-type `EmploymentContract` with `hourlyWage: 16.50` and `inlenersbeloningReferentie: "Klant loonschaal referentie, functie magazijnmedewerker, schaal 3"`
- **WHEN** the audit runs
- **THEN** no `nl-inlenersbeloning-onderbouwing-vereist` violation is reported

#### Scenario: A missing reference with a set wage is flagged
- **GIVEN** the same contract type and `hourlyWage` but `inlenersbeloningReferentie: null`
- **WHEN** the audit runs
- **THEN** an `nl-inlenersbeloning-onderbouwing-vereist` violation at severity `mandatory` is reported

#### Scenario: No wage set is vacuous
- **GIVEN** an `agency`-type `EmploymentContract` with `hourlyWage: null`
- **WHEN** the audit runs
- **THEN** no `nl-inlenersbeloning-onderbouwing-vereist` violation is reported — nothing is decidable without a wage to substantiate

### Requirement: ABU/NBBU CAO wage data SHALL be added as a placeholder CAO file through the existing cao-library mechanism, with no new code (REQ-UITZ-004)

`lib/Standards/cao/cao-abu.json` SHALL be a new CAO data file following the `{value, source, verified, placeholder, checkAgainst}` leaf discipline (`cao-metaal-techniek.json`'s shape), with every `payScales`/`allowances` leaf marked `verified: false, placeholder: true`. Neither `CaoRegistry.php` nor `NlCaoChecks.php` SHALL be modified — an `agency`-type `EmploymentContract` setting `cao: "cao-abu"` and a `caoSchaal` SHALL be evaluated by the existing `nl-cao-minimumloon-schaal` check exactly as any other CAO-referencing contract is.

#### Scenario: The placeholder CAO loads and resolves
- **GIVEN** the imported hrmq register with `cao-abu.json` present
- **WHEN** `CaoRegistry::availableCaos()` is called
- **THEN** `cao-abu` is listed alongside `cao-generiek`/`cao-metaal-techniek`/`cao-horeca`

#### Scenario: nl-cao-minimumloon-schaal evaluates an agency contract with no code change
- **GIVEN** an `agency`-type `EmploymentContract` with `cao: "cao-abu"` and a `caoSchaal` naming a placeholder-marked scale
- **WHEN** the audit runs
- **THEN** `nl-cao-minimumloon-schaal` passes vacuously (the resolved figure is `verified: false`/`placeholder: true`, `CaoRegistry::minMaandloonCents()` returns null per the existing REQ-CAO-003 degradation) — proving the check evaluates the contract at all, without asserting a real compliance figure

### Requirement: Rostering and time-attendance SHALL continue covering agency-type employees with no change (REQ-UITZ-005)

`rostering` (`Shift`/`Roster`/`RosterAssignment`) and `time-attendance` (`AttendanceRecord`) SHALL remain scoped by `employeeId` only, with no `EmploymentContract.type` filter added by this change. An `agency`-type `Employee` SHALL be schedulable and able to clock in/out exactly as any other `Employee`.

#### Scenario: An agency employee appears on the roster
- **GIVEN** an `Employee` with an `agency`-type `EmploymentContract`
- **WHEN** a `RosterAssignment` is created for that employee
- **THEN** it validates and behaves identically to a `RosterAssignment` for a `permanent`-type employee — no additional field or check is required

#### Scenario: This change touches neither capability's files
- **GIVEN** this change's full diff
- **WHEN** it is searched for any file under a rostering- or attendance-related path
- **THEN** none is present — the overlap required zero code

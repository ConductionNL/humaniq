# functiehuis-hr21

## ADDED Requirements

### Requirement: A new Normfunctie reference schema SHALL map standard municipal job functions to a Cao Gemeenten schaal (REQ-HR21-001)

A new fragment `lib/Settings/register.d/hr-hr21.json` (`x-hrmq-fragment: hr-hr21`) SHALL declare
`Normfunctie` (`allowCreate: false`, `x-schema-org: schema:Occupation`) with `functiecode`, `naam`,
`functiegroep` (the HR21 hoofdproces), `caoSchaal` (string, in the same key space
`cao-gemeenten.payScales` uses), `caoSchaalVerified` (boolean, default `false`), and
`caoSchaalSource` (string, nullable, naming the authority to confirm the mapping against). The seed
of `Normfunctie` rows SHALL be an illustrative subset only; neither this requirement nor its seed
data SHALL assert or imply a claimed-complete function library.

#### Scenario: A normfunctie is defined with its schaal mapping

- **GIVEN** the `hr-hr21` fragment is imported
- **WHEN** a `Normfunctie` row with `functiecode: "HR21-001"`, `caoSchaal: "8"`,
  `caoSchaalVerified: false` is read
- **THEN** it resolves with its `functiegroep`, `caoSchaal`, and unverified provenance intact

#### Scenario: The reference page carries no create affordance

- **GIVEN** the `Normfuncties` index page
- **WHEN** it is rendered
- **THEN** no create action is offered (`allowCreate: false`), consistent with the `Caos` reference
  page precedent

### Requirement: EmploymentContract SHALL link to its assigned normfunctie (REQ-HR21-002)

`lib/Settings/register.d/hr-objects.json`'s `EmploymentContract` schema SHALL gain `normfunctieId`
(nullable, `$ref: Normfunctie`), mirroring the existing `cao`/`caoSchaal` link fields. A contract MAY
carry no `normfunctieId`; the value SHALL NOT be derived or computed from any other field — HR sets
it directly.

#### Scenario: A contract is linked to a normfunctie

- **GIVEN** an `EmploymentContract` with `normfunctieId` unset
- **WHEN** HR sets `normfunctieId` to a `Normfunctie` id
- **THEN** the reference persists and resolves through the register the same way `cao` does

#### Scenario: An unlinked contract is unaffected

- **GIVEN** an `EmploymentContract` with `normfunctieId` null
- **WHEN** the record is read
- **THEN** `nl-hr21-schaal-consistentie` (REQ-HR21-003) stays vacuous for it

### Requirement: A corpus rule SHALL flag a contract's schaal that disagrees with its assigned normfunctie's mapped schaal (REQ-HR21-003)

`lib/Standards/rules/payroll.json` SHALL gain `nl-hr21-schaal-consistentie` under a new framework
slug `hr21` (added to `lib/Standards/rules/SCHEMA.md`'s framework-examples list), anchored on
`EmploymentContract`, `severity: mandatory`, `machineCheckable: true`, sourced to VNG HR21 / Cao
Gemeenten. The predicate SHALL be a vacuous pass when `normfunctieId` is null, when it does not
resolve to a `Normfunctie`, or when the resolved `Normfunctie.caoSchaalVerified` is `false`.
Otherwise it SHALL violate when the contract's own `caoSchaal` does not equal the resolved
`Normfunctie.caoSchaal`. `RuleCatalogue::VERSION` SHALL be bumped.

#### Scenario: A mismatched schaal is flagged

- **GIVEN** an `EmploymentContract` with `caoSchaal: "6"`, `normfunctieId` resolving to a
  `Normfunctie` with `caoSchaal: "8"` and `caoSchaalVerified: true`
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** an `nl-hr21-schaal-consistentie` violation is reported for that contract

#### Scenario: A matching schaal passes

- **GIVEN** an `EmploymentContract` with `caoSchaal: "8"`, `normfunctieId` resolving to a
  `Normfunctie` with `caoSchaal: "8"` and `caoSchaalVerified: true`
- **WHEN** the audit runs
- **THEN** no `nl-hr21-schaal-consistentie` violation is reported for that contract

#### Scenario: An unlinked contract never violates

- **GIVEN** an `EmploymentContract` with `normfunctieId` null
- **WHEN** the audit runs
- **THEN** no `nl-hr21-schaal-consistentie` violation is reported for it, regardless of its
  `caoSchaal`

#### Scenario: A placeholder mapping is advisory, not enforced

- **GIVEN** an `EmploymentContract` whose `normfunctieId` resolves to a `Normfunctie` with
  `caoSchaalVerified: false` and a `caoSchaal` that disagrees with the contract's own `caoSchaal`
- **WHEN** the audit runs
- **THEN** no `nl-hr21-schaal-consistentie` violation is reported (the mapping is unverified,
  therefore advisory)

### Requirement: The Normfuncties pages SHALL land as a sub-page of the existing Personeel menu, with no new top-level menu (REQ-HR21-004)

`src/manifest.json` SHALL gain a read-only `Normfuncties` index page (`allowCreate: false`) and a
`NormfunctieDetail` detail page, landing as a sibling sub-page of the existing `Caos`/`SalaryBands`
entries in the `Personeel` menu. `EmploymentContractDetail` SHALL surface the assigned
`normfunctieId`. `npm run check:manifest` MUST pass.

#### Scenario: No new top-level menu is added

- **WHEN** the manifest menu is read after this change
- **THEN** the top-level menu count is unchanged and `Normfuncties` appears as a sibling sub-page in
  the existing `Personeel` menu

#### Scenario: A contract detail shows its assigned normfunctie

- **GIVEN** an `EmploymentContract` with `normfunctieId` set
- **WHEN** its `EmploymentContractDetail` page is opened
- **THEN** the assigned normfunctie is displayed

### Requirement: Seed data SHALL prove the mapping, the clean pass, and the violation branch (REQ-HR21-005)

`lib/Settings/register.d/hr-seed.json` SHALL gain 4-6 illustrative `Normfunctie` rows spanning 2-3
hoofdprocessen (each `caoSchaalVerified: false` with a `caoSchaalSource`), one `EmploymentContract`
with `normfunctieId` set and a matching `caoSchaal` against a `caoSchaalVerified: true` normfunctie
(clean pass), and one `EmploymentContract` with `normfunctieId` set and a mismatched `caoSchaal`
against a `caoSchaalVerified: true` normfunctie (the violation branch). Every pre-existing seeded
`EmploymentContract` SHALL keep `normfunctieId` unset (null).

#### Scenario: Idempotent seed

- **WHEN** the register Repair import runs twice
- **THEN** the `Normfunctie` rows and both new `EmploymentContract` seeds exist exactly once

#### Scenario: The seed reproduces exactly one violation

- **GIVEN** the seeded normfuncties and the two seeded contracts
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** exactly one `nl-hr21-schaal-consistentie` violation is reported

#### Scenario: The pre-existing contract population stays silent

- **GIVEN** every pre-existing seeded `EmploymentContract` (all `normfunctieId` null)
- **WHEN** the audit runs
- **THEN** no `nl-hr21-schaal-consistentie` violation is reported for any of them

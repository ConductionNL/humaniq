# Delta — cao-library

The maintained CAO (collectieve arbeidsovereenkomst) ruleset library: the versioned CAO corpus, the
`CaoRegistry` loader, the contract→CAO reference, the two machine-checkable below-CAO-minimum audit
checks (salary and leave) with placeholder-aware predicates, the idempotent seed of the read-only
`Cao` display objects, and the read-only CAO reference page. Depends on `payroll-core-engine`.

## ADDED Requirements

### Requirement: The CAO corpus SHALL be loadable, versioned code data with sourced leaves (REQ-CAO-001)

CAOs SHALL ship as versioned static data — one JSON file per CAO under `lib/Standards/cao/` — loaded
and merged by a read-only `lib/Standards/CaoRegistry.php` (pure PHP, zero Nextcloud dependencies,
memoised glob, no per-object IO), mirroring `TaxTables` / `RuleCatalogue`. Each CAO SHALL carry `id`,
`name`, `sector`, `version`, `effectiveDate` and the leaf groups `payScales` (schaal → minimum
maandloon), `allowances`, `leaveEntitlement` (statutory + bovenwettelijk vakantiedagen) and
`workingTime`. Every value SHALL be a `{value, source, verified}` leaf (the `nl-2026.json` discipline);
a figure not confirmed against the CAO-tekst SHALL carry `verified: false` + `placeholder: true` +
`checkAgainst`. `CaoRegistry` SHALL expose `availableCaos()`, `get(caoId)`, and the resolvers
`minMaandloonCents(caoId, schaal)` and `minLeaveHours(caoId, contractHoursPerWeek)` — both returning
`null` when the CAO/scale is unknown OR the underlying leaf is `verified:false`/`placeholder:true`.
`CaoRegistry::VERSION` SHALL be bumped on any corpus change. At least the MVP CAOs `cao-generiek`
(statutory-floor baseline, verified), `cao-metaal-techniek` and `cao-horeca` (concrete sectors,
placeholder-marked where unverified) SHALL ship.

#### Scenario: The corpus loads and resolves a verified minimum
- **GIVEN** the `cao-generiek` corpus file with a `verified: true` `payScales` leaf
- **WHEN** `CaoRegistry::minMaandloonCents('cao-generiek', <scale>)` is called
- **THEN** it returns the scale's minimum maandloon in integer cents, and `availableCaos()` lists
  `cao-generiek`, `cao-metaal-techniek` and `cao-horeca` with their `version`/`effectiveDate`

#### Scenario: A placeholder figure resolves to null (advisory)
- **GIVEN** the `cao-metaal-techniek` corpus with a `payScales` leaf marked `verified: false` /
  `placeholder: true`
- **WHEN** `CaoRegistry::minMaandloonCents('cao-metaal-techniek', <scale>)` is called
- **THEN** it returns `null` so the pay-scale check treats the scale as advisory, and the leaf still
  carries a `checkAgainst` naming the official loontabel to confirm

### Requirement: An employment contract SHALL reference its CAO (REQ-CAO-002)

`EmploymentContract` (register.d `hr-objects.json`) SHALL carry a `cao` field referencing a CAO `id`
in the library (the existing free-text `cao` string redefined, description pointing at the corpus) and
a nullable `caoSchaal` field naming the pay scale within that CAO. A contract MAY name a CAO without a
scale; the reference SHALL be a plain scalar link (no new schema), resolvable through `CaoRegistry`.

#### Scenario: A contract links to a library CAO and scale
- **GIVEN** an `EmploymentContract` with `cao: "cao-metaal-techniek"` and `caoSchaal: "B"`
- **WHEN** the contract is read
- **THEN** `cao` resolves through `CaoRegistry::get()` to the CAO ruleset and `caoSchaal` selects the
  scale used by the pay-scale check

#### Scenario: A contract with no CAO is out of scope
- **GIVEN** an `EmploymentContract` with a null `cao`
- **WHEN** the CAO checks run
- **THEN** neither below-CAO check reports a violation for it (vacuous pass)

### Requirement: Salary below the contract's CAO minimum scale SHALL be a violation (REQ-CAO-003)

The system SHALL enforce the corpus rule `nl-cao-minimumloon-schaal` (`lib/Standards/rules/payroll.json`,
EmploymentContract, `severity: mandatory`, `machineCheckable: true`) via `NlCaoChecks`. The predicate
SHALL be a vacuous pass when `cao` is null/unknown, `caoSchaal` is null, or
`CaoRegistry::minMaandloonCents` returns `null` (unverified/placeholder scale). Otherwise it SHALL
resolve the owning employee's `grossMonthlySalary` via the `cao.employeesById` audit context (enriched
in `RuleAuditService::audit()`, the `payroll.runsById` precedent) and require
`round(grossMonthlySalary × 100) ≥ minMaandloonCents`. A salary below the CAO minimum SHALL raise the
violation.

#### Scenario: Below the verified CAO minimum raises a mandatory violation
- **GIVEN** a contract `cao: "cao-generiek"`, `caoSchaal` set, whose employee's `grossMonthlySalary`
  is below the CAO's verified minimum maandloon for that scale
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** a mandatory `nl-cao-minimumloon-schaal` violation is reported for that contract

#### Scenario: At or above the minimum passes; a placeholder scale is advisory
- **GIVEN** one contract at/above its `cao-generiek` verified minimum and one contract on a
  `placeholder`-marked `cao-metaal-techniek` scale
- **WHEN** the audit runs
- **THEN** neither contract reports a `nl-cao-minimumloon-schaal` violation (the first meets the
  minimum; the second is advisory because the scale figure is unverified)

### Requirement: Leave entitlement below the contract's CAO minimum SHALL be a violation (REQ-CAO-004)

The system SHALL enforce the corpus rule `nl-cao-verlof-minimum` (`lib/Standards/rules/labour.json`,
LeaveBalance, `severity: mandatory`, `machineCheckable: true`) via `NlCaoChecks`. The predicate
SHALL be a vacuous pass when `leaveType` is not the statutory annual type (`vakantie`), when no CAO
resolves for the employee (`cao.caoByEmployeeId` audit context), or when `CaoRegistry::minLeaveHours`
returns `null` (unverified). Otherwise it SHALL require
`entitledHours + bovenwettelijkHours ≥ minLeaveHours(cao, contractHoursPerWeek)`. Entitlement below
the CAO minimum SHALL raise the violation.

#### Scenario: Leave below the verified CAO minimum raises a violation
- **GIVEN** a `LeaveBalance` (`leaveType: "vakantie"`) whose employee's active contract is
  `cao: "cao-generiek"`, and whose `entitledHours + bovenwettelijkHours` is below the CAO's verified
  minimum for the contract's `contractHoursPerWeek`
- **WHEN** the audit runs
- **THEN** a mandatory `nl-cao-verlof-minimum` violation is reported for that balance

#### Scenario: A non-annual balance is out of scope
- **GIVEN** a `LeaveBalance` with `leaveType` other than `vakantie` (e.g. a special-leave type)
- **WHEN** the audit runs
- **THEN** no `nl-cao-verlof-minimum` violation is reported (vacuous pass)

### Requirement: A read-only CAO reference page SHALL list available CAOs and the contract SHALL show its CAO (REQ-CAO-005)

`src/manifest.json` SHALL add a read-only `Caos` index page and a `CaoDetail` detail page bound to the
register.d `Cao` schema (`lib/Settings/register.d/hr-cao.json`), both `allowCreate: false` (reference
only), plus a menu entry. `CaoDetail` SHALL surface the CAO's scales, allowances, leave entitlement and
working-time norms. The selected CAO (`cao` + `caoSchaal`) SHALL render on `EmploymentContractDetail`
(the `ct-data` widget excludes only `employeeId`, so both fields appear), with its `_note` updated.
`npm run check:manifest` MUST pass.

#### Scenario: The CAO reference page lists the seeded CAOs read-only
- **GIVEN** the seeded `Cao` display objects
- **WHEN** a user opens the `Caos` page
- **THEN** it lists `cao-generiek`, `cao-metaal-techniek` and `cao-horeca` with their sector/version,
  offers no create affordance (`allowCreate: false`), and `CaoDetail` shows a CAO's scales, allowances,
  leave and working-time

#### Scenario: A contract detail shows its CAO
- **GIVEN** an `EmploymentContract` with `cao` and `caoSchaal` set
- **WHEN** its `EmploymentContractDetail` page is opened
- **THEN** the CAO reference and scale are displayed in the contract data widget

### Requirement: The read-only Cao display objects SHALL be seeded idempotently from the corpus (REQ-CAO-006)

`NlCaoChecks` SHALL implement `SeedsObjects` and project one `Cao` object per
`CaoRegistry::availableCaos()` entry, keyed on cao id, reading all values from the corpus. The seed
SHALL be idempotent: re-seeding SHALL NOT create duplicate `Cao` objects (upsert on `id`) and SHALL
converge object values to the corpus (the corpus is authoritative; the objects are a derived
projection). Seeded `Cao` objects SHALL satisfy `NlCaoChecks`' own checks vacuously.

#### Scenario: Re-seeding creates no duplicates and converges values
- **GIVEN** the `Cao` display objects already seeded from the corpus
- **WHEN** the seed runs again after a corpus figure changed
- **THEN** no duplicate `Cao` object exists for any cao id and each object reflects the current corpus
  value

---
capability: cao-library
status: done
built_by: openspec/changes/archive/2026-07-14-cao-library
---

# cao-library Specification

**Status**: done
**Scope**: hrmq (kind: config+code; `depends_on: [payroll-core-engine]`)
**OpenSpec changes**:
- [cao-library](../../changes/archive/2026-07-14-cao-library/) _(archived 2026-07-14)_ — the
  versioned CAO (collectieve arbeidsovereenkomst) corpus (`lib/Standards/cao/{cao-id}.json`,
  `{value, source, verified}` leaves) + `CaoRegistry` loader (mirroring `TaxTables` /
  `RuleCatalogue`), the contract→CAO reference (`EmploymentContract.cao` redefined + new
  `caoSchaal`), two machine-checkable below-CAO-minimum audit checks (`nl-cao-minimumloon-schaal`
  on salary, `nl-cao-verlof-minimum` on leave) enforced by the auto-discovered `NlCaoChecks`
  provider with placeholder-aware predicates, the `RuleAuditService` `cao.*` cross-object context
  enrichment, the idempotent seed of the read-only `Cao` display objects (via `SeedsObjects` +
  the new `UpsertsObjects` upsert-by-`caoId` capability), and the read-only `Caos` / `CaoDetail`
  reference pages — three MVP CAOs (`cao-generiek` verified anchor, `cao-metaal-techniek` /
  `cao-horeca` placeholder-marked).
- [cao-sector-datasets](../../changes/archive/2026-07-17-cao-sector-datasets/) _(archived
  2026-07-17)_ — six additional sector CAOs in the existing corpus leaf shape (`cao-rijk`,
  `cao-gemeenten`, `cao-onderwijs-po`, `cao-onderwijs-vo`, `cao-ziekenhuizen`, `cao-zorg-vvt`),
  exercising the data-only extension path `cao/SCHEMA.md` already documents: `CaoRegistry::VERSION`
  bumped, zero lines changed in `CaoRegistry`, `NlCaoChecks`, `RuleAuditService`, `RuleCatalogue`,
  `hr-objects.json`, `hr-cao.json` or `src/manifest.json`. `cao-rijk`'s IKB (16.50%/min
  €452/mo/64u) and 36u/week `workingTime` are the one directly-fetched, `verified: true` anchor in
  this batch (`caorijk.nl`, retrieved 2026-07-17); every other leaf across all six CAOs ships
  `verified: false` + `placeholder: true` + `checkAgainst`, per the `cao-metaal-techniek`/
  `cao-horeca` precedent. Named, explicitly out-of-scope gaps: IKB accrual/spend ledgers, an ORT
  (onregelmatigheidstoeslag) time-segmentation engine, FWG-3.0-score-to-schaal derivation,
  periodiek/trede auto-progression, and multi-version CAO history with retroactive
  recalculation — none built here (see the archived change's design.md "Named gaps").

## Purpose

Turns the payroll engine into a *compliance* engine by giving the audit something sector-specific
to check a contract against. A maintained CAO library is table-stakes in every NL payroll
competitor (AFAS 200+, Employes 35+, Nmbrs, Loket.nl); the 2026-07-12/13 market research ranked
"no CAO support" as a headline gap. A CAO ships the same way tax-year parameters do — as a
versioned, source-cited corpus in code — because a CAO is the same kind of fact (a sector ruleset,
identical for every tenant, changing only with the CAO-tekst). Placeholder figures are advisory
(never a false mandatory violation) until a maintainer confirms them against the official
CAO-tekst; the `cao-generiek` WML-derived anchor is verified and proves the check path end-to-end.

## Requirements

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
`CaoRegistry::VERSION` SHALL be bumped on any corpus change (adding a further sector CAO — e.g. a
seventh CAO beyond the current nine — follows the identical data-only path: a new `cao/{id}.json`
file plus a `VERSION` bump, no other file changed). `schaal` (the `payScales.value` key) is an
opaque string never parsed by the loader, so it MAY use any sector-specific naming convention
(numeric BBRA/VNG schalen, onderwijs letter-schalen, FWG-functiegroep ids) without a loader change.
At least the MVP CAOs `cao-generiek` (statutory-floor baseline, verified), `cao-metaal-techniek`
and `cao-horeca` (concrete sectors, placeholder-marked where unverified), plus the six sector CAOs
`cao-rijk`, `cao-gemeenten`, `cao-onderwijs-po`, `cao-onderwijs-vo`, `cao-ziekenhuizen` and
`cao-zorg-vvt` (cao-sector-datasets, placeholder-marked except `cao-rijk`'s IKB/`workingTime`
leaves) SHALL ship.

#### Scenario: The corpus loads and resolves a verified minimum
- **GIVEN** the `cao-generiek` corpus file with a `verified: true` `payScales` leaf
- **WHEN** `CaoRegistry::minMaandloonCents('cao-generiek', <scale>)` is called
- **THEN** it returns the scale's minimum maandloon in integer cents, and `availableCaos()` lists all
  nine CAOs (`cao-generiek`, `cao-metaal-techniek`, `cao-horeca`, `cao-rijk`, `cao-gemeenten`,
  `cao-onderwijs-po`, `cao-onderwijs-vo`, `cao-ziekenhuizen`, `cao-zorg-vvt`) with their
  `version`/`effectiveDate`

#### Scenario: A placeholder figure resolves to null (advisory)
- **GIVEN** the `cao-metaal-techniek` corpus with a `payScales` leaf marked `verified: false` /
  `placeholder: true`
- **WHEN** `CaoRegistry::minMaandloonCents('cao-metaal-techniek', <scale>)` is called
- **THEN** it returns `null` so the pay-scale check treats the scale as advisory, and the leaf still
  carries a `checkAgainst` naming the official loontabel to confirm

#### Scenario: A sector-specific scale identifier resolves like any other (cao-sector-datasets)
- **GIVEN** `cao-ziekenhuizen.json` with a `payScales.value` keyed by FWG-functiegroep ids (e.g.
  `"FWG-40"`), placeholder-marked
- **WHEN** `CaoRegistry::minMaandloonCents('cao-ziekenhuizen', 'FWG-40')` is called
- **THEN** the lookup succeeds exactly as it would for a letter or numeric schaal key (no
  schaal-format validation) and returns `null` because the leaf is unverified/placeholder

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

#### Scenario: A verified minimum on any new sector CAO is enforced without a code change (cao-sector-datasets)
- **GIVEN** a maintainer confirms `cao-rijk`'s `payScales` leaf against the official BBRA loontabel
  and flips it to `verified: true`, and a contract `cao: "cao-rijk"`, `caoSchaal` set, whose
  employee's `grossMonthlySalary` is below that confirmed minimum
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** a mandatory `nl-cao-minimumloon-schaal` violation is reported, with zero lines of
  `NlCaoChecks`/`RuleAuditService`/rule-catalogue code changed to make this happen

### Requirement: Leave entitlement below the contract's CAO minimum SHALL be a violation (REQ-CAO-004)

The system SHALL enforce the corpus rule `nl-cao-verlof-minimum` (`lib/Standards/rules/labour.json`,
LeaveBalance, `severity: mandatory`, `machineCheckable: true`) via `NlCaoChecks`. The predicate
SHALL be a vacuous pass when `leaveType` is not the statutory annual type (`holiday` on the
LeaveBalance schema — the Dutch `vakantie`), when no CAO resolves for the employee
(`cao.caoByEmployeeId` audit context), or when `CaoRegistry::minLeaveHours` returns `null`
(unverified). Otherwise it SHALL require
`entitledHours + bovenwettelijkHours ≥ minLeaveHours(cao, contractHoursPerWeek)`. Entitlement below
the CAO minimum SHALL raise the violation.

#### Scenario: Leave below the verified CAO minimum raises a violation
- **GIVEN** a `LeaveBalance` (`leaveType: "holiday"`) whose employee's active contract is
  `cao: "cao-generiek"`, and whose `entitledHours + bovenwettelijkHours` is below the CAO's verified
  minimum for the contract's `contractHoursPerWeek`
- **WHEN** the audit runs
- **THEN** a mandatory `nl-cao-verlof-minimum` violation is reported for that balance

#### Scenario: A non-annual balance is out of scope
- **GIVEN** a `LeaveBalance` with `leaveType` other than `holiday` (e.g. a special-leave type)
- **WHEN** the audit runs
- **THEN** no `nl-cao-verlof-minimum` violation is reported (vacuous pass)

#### Scenario: A placeholder leave minimum on any new sector CAO never raises a violation (cao-sector-datasets)
- **GIVEN** a `LeaveBalance` (`leaveType: "holiday"`) whose employee's active contract is
  `cao: "cao-zorg-vvt"`, whose `leaveEntitlement` leaf is `verified: false` / `placeholder: true`
- **WHEN** the audit runs regardless of `entitledHours + bovenwettelijkHours`
- **THEN** no `nl-cao-verlof-minimum` violation is reported for that balance

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
- **THEN** it lists all nine CAOs (`cao-generiek`, `cao-metaal-techniek`, `cao-horeca`, `cao-rijk`,
  `cao-gemeenten`, `cao-onderwijs-po`, `cao-onderwijs-vo`, `cao-ziekenhuizen`, `cao-zorg-vvt`) with
  their sector/version, offers no create affordance (`allowCreate: false`), and `CaoDetail` shows a
  CAO's scales, allowances, leave and working-time

#### Scenario: Re-seeding after adding sector CAOs surfaces them with no manifest edit (cao-sector-datasets)
- **GIVEN** the six `cao-sector-datasets` corpus files exist and `occ hrmq:rules:seed-test-data` has
  been run
- **WHEN** a user opens the `Caos` page
- **THEN** all six appear alongside the original three, with zero lines changed in
  `src/manifest.json`, `lib/Settings/register.d/hr-cao.json` or the menu entry

#### Scenario: A contract detail shows its CAO
- **GIVEN** an `EmploymentContract` with `cao` and `caoSchaal` set
- **WHEN** its `EmploymentContractDetail` page is opened
- **THEN** the CAO reference and scale are displayed in the contract data widget

### Requirement: The read-only Cao display objects SHALL be seeded idempotently from the corpus (REQ-CAO-006)

`NlCaoChecks` SHALL implement `SeedsObjects` and project one `Cao` object per
`CaoRegistry::availableCaos()` entry, keyed on cao id, reading all values from the corpus. The seed
SHALL be idempotent: re-seeding SHALL NOT create duplicate `Cao` objects (upsert on `id` — realised
via the `UpsertsObjects` capability declaring the natural `caoId` key, honoured by
`RuleTestDataSeeder`) and SHALL converge object values to the corpus (the corpus is authoritative;
the objects are a derived projection). Seeded `Cao` objects SHALL satisfy `NlCaoChecks`' own checks
vacuously.

#### Scenario: Re-seeding creates no duplicates and converges values
- **GIVEN** the `Cao` display objects already seeded from the corpus
- **WHEN** the seed runs again after a corpus figure changed
- **THEN** no duplicate `Cao` object exists for any cao id and each object reflects the current corpus
  value

#### Scenario: Seeding covers all nine CAOs with no duplicates (cao-sector-datasets)
- **GIVEN** the three original CAOs plus the six `cao-sector-datasets` sector CAOs in the corpus
- **WHEN** `NlCaoChecks::seedObjects()` runs
- **THEN** it projects exactly nine `Cao` rows, one per corpus id, with no duplicate `caoId`

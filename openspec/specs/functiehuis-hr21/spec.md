---
capability: functiehuis-hr21
status: done
built_by: openspec/changes/archive/2026-07-17-functiehuis-hr21
---

# functiehuis-hr21 Specification

**Status**: done
**Scope**: hrmq (`kind: config+code` — a new register-backed `Normfunctie` reference schema, one new
field on `EmploymentContract`, one new corpus rule + executable check, and two read-only manifest
pages; reuses `cao-gemeenten`'s existing schaal key space and `comp-cycles`'/`cao-library`'s existing
salary/CAO machinery without modification)
**OpenSpec changes**:
- [functiehuis-hr21](../../changes/archive/2026-07-17-functiehuis-hr21/) _(archived 2026-07-17)_ — a
  `Normfunctie` reference schema (`functiecode`/`naam`/`functiegroep`/`caoSchaal` +
  `caoSchaalVerified`/`caoSchaalSource` provenance, `allowCreate:false`, seeded illustrative subset),
  `EmploymentContract.normfunctieId` (nullable `$ref: Normfunctie`, HR-set), the corpus rule
  `nl-hr21-schaal-consistentie` (new `hr21` framework, `EmploymentContract`, mandatory,
  machine-checkable) enforced by `NlHr21Checks` (auto-discovered `CheckProvider` + `SeedsObjects`),
  and read-only `Normfuncties`/`NormfunctieDetail` pages as a sibling sub-page of `CAO's`/
  `Salarisschalen` in the existing `Personeel` menu (ADR-001 Rule 1, no new top-level menu).

## Purpose

HR21 is the VNG-owned functiewaarderingssysteem for the Dutch municipal sector (Cao Gemeenten / Cao
SGO name it as the applicable job-evaluation system unless an employer has chosen another
union-recognized one; roughly 80% of Dutch municipalities hold an HR21 license). It organizes municipal
work into a library of standard job functions ("normfuncties"), each of which maps to a salary scale
("schaal") under Cao Gemeenten. hrmq already ships everything HR21's compensation side needs —
`cao-library`/`cao-sector-datasets` carry `cao-gemeenten`'s schaal key space, `comp-cycles` owns the
employer's own internal `SalaryBand`/`CompAdjustment` lifecycle, and `EmploymentContract` already
carries `cao`/`caoSchaal` — so this change adds only the one thing none of those provide: a library
recording *which* schaal a given standard function maps to, so a contract's `caoSchaal` can be checked
for consistency against the function the employee actually performs. It does not invent a second
salary-band mechanism, does not build the functietoekenning approval workflow, maatwerkfunctie
governance, Awb bezwaarrecht procedure, decision-letter generation, OR instemmingsrecht notification,
loopbaanpaden, or automatic salary-consequence calculation on reclassification — all named fast-follows
blocked on case-management/multi-step-approval/cross-app objection-procedure capabilities hrmq does not
have. HR21's exact ~150-normfunctie count could not be independently verified from a primary VNG source
in this pass, so this change ships only a small illustrative seed subset, explicitly not a
claimed-complete library, with every seeded mapping `caoSchaalVerified: false` except one documented
proof-case exception used solely to demonstrate the consistency check.

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

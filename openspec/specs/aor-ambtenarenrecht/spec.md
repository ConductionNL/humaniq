# aor-ambtenarenrecht Specification

## Purpose
TBD - created by archiving change aor-ambtenarenrecht. Update Purpose after archive.

## Requirements

### Requirement: The Employee schema SHALL record whether an employee is an ambtenaar and under which legal regime (REQ-AOR-001)

`lib/Settings/register.d/hr-objects.json`'s `Employee` schema SHALL gain `publicSectorRegime`
(nullable enum `genormaliseerd` \| `ambtenarenwet`, default null), `ambtseedAfgelegdOp` (date,
nullable), and `nevenwerkzaamhedenGemeld` (boolean, default `false`), mirroring the existing `isDga`
mode-switch shape. `genormaliseerd` SHALL mean the employee is a Wnra-normalized ambtenaar on an
ordinary BW7 `arbeidsovereenkomst`. `ambtenarenwet` SHALL mean the employee remains on the
Ambtenarenwet 2017 public-law footing (politie, defensie, rechterlijke macht, political
office-holders). `null` SHALL mean an ordinary private-sector employee, for whom
`ambtseedAfgelegdOp` and `nevenwerkzaamhedenGemeld` carry no meaning. None of the three fields SHALL
be derived or computed from any other field — an HR user sets them explicitly.

#### Scenario: An employee is marked as a Wnra-normalized ambtenaar

- **GIVEN** an `Employee` with `publicSectorRegime` unset (default null)
- **WHEN** HR sets `publicSectorRegime: "genormaliseerd"`
- **THEN** the field persists and no other `Employee` field is affected

#### Scenario: An employee is marked under the special-status regime

- **GIVEN** an `Employee` representing a police officer
- **WHEN** HR sets `publicSectorRegime: "ambtenarenwet"`
- **THEN** the field persists distinctly from `genormaliseerd`

#### Scenario: A private-sector employee is unaffected

- **GIVEN** an ordinary private-sector `Employee` with `publicSectorRegime` null
- **WHEN** the record is read
- **THEN** `ambtseedAfgelegdOp` and `nevenwerkzaamhedenGemeld` carry no compliance meaning for that
  employee (REQ-AOR-002 / REQ-AOR-003 both stay vacuous)

### Requirement: A corpus rule SHALL require every ambtenaar to have taken the ambtseed (REQ-AOR-002)

`lib/Standards/rules/labour.json` SHALL gain `nl-ambtenaar-eed-vereist` under a new framework slug
`ambtenarenwet-2017` (added to `lib/Standards/rules/SCHEMA.md`'s framework-examples list), anchored
on `Employee`, `severity: mandatory`, `machineCheckable: true`, sourced to Ambtenarenwet 2017 art. 5.
The rule SHALL be a vacuous pass when `publicSectorRegime` is null. Otherwise it SHALL violate when
`ambtseedAfgelegdOp` is null — a presence-only check (the `gebruikelijkloonJustification` MVP
precedent; the ceremony's content is never validated). `RuleCatalogue::VERSION` SHALL be bumped.

#### Scenario: A missing ambtseed is flagged

- **GIVEN** an `Employee` with `publicSectorRegime: "ambtenarenwet"` and `ambtseedAfgelegdOp: null`
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** an `nl-ambtenaar-eed-vereist` violation is reported for that employee

#### Scenario: A recorded ambtseed passes

- **GIVEN** an `Employee` with `publicSectorRegime: "genormaliseerd"` and `ambtseedAfgelegdOp` set to
  a date
- **WHEN** the audit runs
- **THEN** no `nl-ambtenaar-eed-vereist` violation is reported for that employee

#### Scenario: A private-sector employee never violates

- **GIVEN** an `Employee` with `publicSectorRegime` null and `ambtseedAfgelegdOp` null
- **WHEN** the audit runs
- **THEN** no `nl-ambtenaar-eed-vereist` violation is reported for that employee

### Requirement: A corpus rule SHALL require every ambtenaar's nevenwerkzaamheden disclosure to be on file (REQ-AOR-003)

`lib/Standards/rules/labour.json` SHALL gain `nl-ambtenaar-nevenwerkzaamheden-melding` under the same
`ambtenarenwet-2017` framework, anchored on `Employee`, `severity: mandatory`,
`machineCheckable: true`, sourced to Ambtenarenwet 2017 art. 9. The rule SHALL be a vacuous pass when
`publicSectorRegime` is null. Otherwise it SHALL violate when `nevenwerkzaamhedenGemeld` is `false` —
a presence-only attestation check; the content of what was disclosed is never validated.

#### Scenario: A missing disclosure attestation is flagged

- **GIVEN** an `Employee` with `publicSectorRegime: "genormaliseerd"` and
  `nevenwerkzaamhedenGemeld: false`
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** an `nl-ambtenaar-nevenwerkzaamheden-melding` violation is reported for that employee

#### Scenario: An on-file disclosure passes

- **GIVEN** an `Employee` with `publicSectorRegime: "ambtenarenwet"` and
  `nevenwerkzaamhedenGemeld: true`
- **WHEN** the audit runs
- **THEN** no `nl-ambtenaar-nevenwerkzaamheden-melding` violation is reported for that employee

#### Scenario: A private-sector employee never violates

- **GIVEN** an `Employee` with `publicSectorRegime` null and `nevenwerkzaamhedenGemeld: false`
- **WHEN** the audit runs
- **THEN** no `nl-ambtenaar-nevenwerkzaamheden-melding` violation is reported for that employee

### Requirement: Seed data SHALL prove both regimes and both rules' violation and pass branches (REQ-AOR-004)

`lib/Settings/register.d/hr-seed.json` SHALL gain one `genormaliseerd` `Employee` with both
`ambtseedAfgelegdOp` and `nevenwerkzaamhedenGemeld` satisfied, one `ambtenarenwet` `Employee` with
both satisfied, and one `ambtenarenwet` `Employee` with `ambtseedAfgelegdOp: null` (both other seeds'
`nevenwerkzaamhedenGemeld` values SHALL be `true`). Every pre-existing seeded `Employee` SHALL keep
`publicSectorRegime` unset (null).

#### Scenario: Idempotent seed

- **WHEN** the register Repair import runs twice
- **THEN** all three new `Employee` seeds exist exactly once

#### Scenario: The seed reproduces exactly one violation

- **GIVEN** the three new seeded employees
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** exactly one `nl-ambtenaar-eed-vereist` violation is reported (the third seed) and zero
  `nl-ambtenaar-nevenwerkzaamheden-melding` violations are reported among the three

#### Scenario: The pre-existing seed population stays silent

- **GIVEN** every pre-existing seeded `Employee` (all `publicSectorRegime: null`)
- **WHEN** the audit runs
- **THEN** neither new rule reports a violation for any of them

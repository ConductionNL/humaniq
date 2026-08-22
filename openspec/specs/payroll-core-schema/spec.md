---
capability: payroll-core-schema
status: done
built_by: openspec/changes/archive/2026-07-14-payroll-core-schema
---

# payroll-core-schema Specification

**Status**: done
**Scope**: humaniq (chain head, spec 1 of 2 — ADR-032; consumed by `payroll-core-engine`)
**OpenSpec changes**:
- [payroll-core-schema](../../changes/archive/2026-07-14-payroll-core-schema/) _(archived 2026-07-14)_ — versioned
  `lib/Standards/tables/nl-2026.json` NL tax-year parameter corpus (Rekenvoorschriften 2026
  schijven + heffingskorting formulas, premies, maxima, WML — every value sourced + verified,
  placeholders flagged) with `tables/SCHEMA.md`, Employee `loonheffingskortingToegepast`,
  PayrollRun `calculatedAt`/`engineVersion`, Payslip `arbeidskorting`/`payrollRunId`, and the two
  engine-contract corpus rules `nl-engine-table-version` / `nl-engine-output-consistency`
  (checks implemented by spec 2) (kind: config)

## Purpose

Chain head for the open-source Dutch payroll engine (Spectr `hrmq-canon-payroll-engine`,
P1-strategic; no OSS NL payroll engine exists — Odoo NL is Enterprise-only): the verified,
versioned 2026 tax-year parameter file (annual re-issue = data-only change, the rule-corpus
philosophy), the minimal schema deltas the engine reads/writes, and the corpus rules that pin the
engine's traceability + output-consistency contract. `payroll-core-engine` (kind: code,
`depends_on: [payroll-core-schema]`) consumes this capability.

## Requirements

### Requirement: A versioned NL tax-year parameter file SHALL ship as static corpus data (REQ-PCS-001)

`lib/Standards/tables/nl-2026.json` SHALL carry the complete verified 2026 NL payroll parameter
set exactly as specified in design.md D3 — loonheffing schijventarief for the below-AOW and both
AOW-age variants with `Lv`/`Lmax`/tijdvakfactoren (Rekenvoorschriften 2026 tabellen 1a/1b/2),
algemene heffingskorting, arbeidskorting, ouderenkorting and alleenstaande-ouderenkorting piecewise
parameter sets (tabellen 3–6), premie volksverzekeringen composition (17,90/0,10/9,65),
AOW-leeftijd 67, Zvw 6,10/4,85 + maximumbijdrageloon 79.409, Awf 2,74/7,74, Aof 6,27/7,63 +
Wko 0,50 + Whk gemiddeld 1,52 (placeholder-flagged), maximumpremieloon 79.409 (6.617,41/maand),
WML 14,71 (1 jan) / 14,99 (1 jul) + referentiemaandloon 2.294,40, and vakantiebijslag minimum 8%.
Every parameter leaf SHALL carry `source` and `verified` fields; any value not confirmed against a
primary source SHALL carry `verified: false` plus a `checkAgainst` note naming the official
document (in this issue only the employer-specific Whk value, shipped as the flagged national
average). The file SHALL declare `id: nl-2026` and list its source documents under `basedOn`.

#### Scenario: The tables file is valid, versioned JSON with sourced values
- **GIVEN** the repository at HEAD after this change
- **WHEN** `lib/Standards/tables/nl-2026.json` is decoded
- **THEN** it parses as JSON with `id: "nl-2026"`, `jurisdiction: "NL"`, `year: 2026`
- **AND** every parameter leaf carries `value`, `source` and `verified` fields

#### Scenario: Placeholder values are explicit, never silent
- **WHEN** the `werknemersverzekeringen.whk` parameter is read
- **THEN** it carries `placeholder: true` and a `checkAgainst` note naming the employer-specific
  Whk-beschikking / UWV nota as the document to substitute

### Requirement: The tables directory SHALL document its shape and re-issue discipline (REQ-PCS-002)

`lib/Standards/tables/SCHEMA.md` SHALL document, mirroring `lib/Standards/rules/SCHEMA.md`: tax-year
parameters are versioned static data (universal facts, NOT OpenRegister per-tenant config); one
file per jurisdiction-year named `{jurisdiction}-{year}.json` (lowercase); the required top-level
keys (`id`, `jurisdiction`, `year`, `issued`, `basedOn`, `parameters`); the
`{value, source, verified[, placeholder, checkAgainst]}` leaf shape; amounts in euros with two
decimals (engine converts to integer cents on load); and the rule that an annual re-issue is a new
data file + `RuleCatalogue::VERSION` bump with no engine code change.

#### Scenario: A new year is a data-only change
- **GIVEN** a developer preparing the 2027 tables
- **WHEN** they follow `lib/Standards/tables/SCHEMA.md`
- **THEN** the documented procedure is: add `nl-2027.json` with sourced values, bump the catalogue
  version — no PHP changes

### Requirement: Employee SHALL carry the loonheffingskorting election (REQ-PCS-003)

`lib/Settings/register.d/hr-objects.json` SHALL add `loonheffingskortingToegepast` (boolean,
default `true`) to `Employee` with a description naming the Rekenvoorschriften §2.2.3 basis and
its distinction from the existing `loonheffingenVerklaringOnFile`, and SHALL bump the Employee
schema version to `0.4.0`. No other Employee property changes.

#### Scenario: Existing employees keep the korting applied by default
- **GIVEN** the re-imported hrmq register
- **WHEN** an existing seeded Employee is validated
- **THEN** `loonheffingskortingToegepast` defaults to `true` and the object remains valid

### Requirement: PayrollRun SHALL carry the engine traceability pair (REQ-PCS-004)

`PayrollRun` SHALL gain `calculatedAt` (string, date-time, nullable) and `engineVersion` (string,
nullable), both defaulting to null with descriptions naming `nl-engine-table-version`, and its
schema version SHALL bump to `0.2.0`. Hand-entered runs (all existing seeds) keep both fields null
and remain valid.

#### Scenario: A hand-entered run is untouched by the engine contract
- **GIVEN** the existing seeded PayrollRun objects
- **WHEN** the register re-imports and `occ humaniq:rules:audit` runs
- **THEN** the seeds validate with null `calculatedAt`/`engineVersion` and no new violations appear

### Requirement: Payslip SHALL gain exactly the arbeidskorting record and the run association (REQ-PCS-005)

`Payslip` SHALL gain `arbeidskorting` (number, nullable — the applied ark tijdvakbedrag the
Rekenvoorschriften §2.2.3.4 loonstaat obligation requires recording) and `payrollRunId` (string,
format uuid, `$ref: PayrollRun`, nullable — the association driving idempotent recalculation and
run-scoped verification in `payroll-core-engine`), and its schema version SHALL bump to `0.3.0`.
No other Payslip property changes: every other engine output component maps to an existing field
(design.md Context).

#### Scenario: Hand-entered payslips stay valid
- **GIVEN** the existing seeded Payslip
- **WHEN** the register re-imports
- **THEN** the object validates with `arbeidskorting` and `payrollRunId` null

#### Scenario: The run reference resolves as a relation
- **WHEN** a Payslip is written with `payrollRunId` set to an existing PayrollRun id
- **THEN** OpenRegister accepts it and the `$ref` resolves to the PayrollRun (related-surface
  convention, ADR-062 rule 7)

### Requirement: The corpus SHALL pin the engine-output contract as two machine-checkable rules (REQ-PCS-006)

`lib/Standards/rules/payroll.json` SHALL add `nl-engine-table-version` and
`nl-engine-output-consistency` exactly per design.md D5 (domain `tax`, jurisdiction `NL`,
framework `payroll-core`, severity `mandatory`, `machineCheckable: true`, `effectiveDate:
2026-01-01`, source/sourceUrl = Rekenvoorschriften 2026), and `RuleCatalogue::VERSION` SHALL bump
(this change bumped it from the HEAD-current `2026-07.11` to `2026-07.12`; the design's originally
stated `2026-07.5` → `2026-07.6` was written against a stale HEAD — see the archived change's
DEVIATIONS note). The declared net equation of `nl-engine-output-consistency` is
`nettoPay = grossPay − loonheffing − pensionContribution(null→0) − (zvw when zvwMode=inhouding)`,
cents-exact; both rules apply only to engine-produced records (runs carrying `engineVersion` and
their payslips) and are vacuous otherwise. The `NlEngineChecks.php` check provider that enforces
them is explicitly deferred to `payroll-core-engine` (chain split, ADR-032): after this change the
audit reports both rules as machine-checkable but not yet enforced.

#### Scenario: Corpus stays loadable and versioned
- **WHEN** `occ humaniq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads without error, reports two more machine-checkable rules, and no
  existing rule regresses

#### Scenario: The unenforced gap is honest
- **WHEN** the audit coverage summary is printed before `payroll-core-engine` lands
- **THEN** `nl-engine-table-version` and `nl-engine-output-consistency` count in
  machine-checkable but NOT in enforced (no vacuous always-true predicate is registered)

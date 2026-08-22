---
capability: abp-aansluiting
status: done
built_by: openspec/changes/archive/2026-07-17-abp-aansluiting
---

# abp-aansluiting Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [abp-aansluiting](../../changes/archive/2026-07-17-abp-aansluiting/) _(archived 2026-07-17)_ — `Administration.abpAansluitingsplichtig` mandatory-affiliation determination field, a new fund- and tenant-scoped `nl-abp-fund-required` corpus rule additive alongside the shipped `nl-upa-monthly-completeness`, a new auto-discovered `NlAbpChecks` provider, `RuleAuditService` context enrichment, and seed data proving both branches in one audit run (kind: config+code)

## Purpose

Record which client administraties are legally obligated (Wet Privatisering
ABP, 1996) to affiliate with ABP, and close the one compliance gap the
shipped, deliberately fund-blind and tenant-blind `nl-upa-monthly-completeness`
check (`pension-filing-upa-mvp`) cannot catch: an obligated administratie
whose own payroll was approved but never actually filed with ABP for that
period. Reuses the entire shipped UPA delivery mechanism (`PensionFiling`
schema, lifecycle, guard, `nl-pensioenaangifte` framework) unchanged — ABP was
already one of the six modelled funds; this change only adds the
determination field and a narrower, additive completeness rule.

ABP premium computation (27,1% total 2026 tariff, employer/employee split,
franchise, VPL) is an explicit non-goal: no shared pension-premium computation
capability exists for any fund yet, so no premium figure is computed, stored,
or enforced anywhere by this change.

## Requirements

### REQ-ABP-001: The Administration catalog SHALL record which client administraties are legally obligated to affiliate with ABP

`lib/Settings/register.d/hr-administratie.json`'s `Administration` schema
gained `abpAansluitingsplichtig` (boolean, default `false`), an admin-set
determination that this client administratie is obligated under the Wet
Privatisering ABP (1996, BWBR0007791) to affiliate with ABP and file its
pension-bearing payroll there. The field is never derived or computed from
any sector, function, or CAO field — humaniq carries no employer-sector taxonomy
today, so an admin sets it explicitly. `lib/Settings/register.d/hr-objects.json`'s
`PayrollRun.administrationId` description, which previously stated no
Administration schema is modeled in humaniq, was corrected to reflect the
shipped `multi-administratie` `Administration` schema.

#### Scenario: An administratie is marked ABP-obligated
- **GIVEN** an `Administration` row with `abpAansluitingsplichtig` unset (default `false`)
- **WHEN** an admin sets `abpAansluitingsplichtig: true`
- **THEN** the row persists the flag and no other field on `Administration` is affected

#### Scenario: The flag is never auto-derived
- **GIVEN** an `Administration` row whose linked contracts reference an overheid-sector CAO
- **WHEN** the row is read
- **THEN** `abpAansluitingsplichtig` remains whatever an admin explicitly set — no automatic derivation from the CAO or any other field occurs

### REQ-ABP-002: A new corpus rule SHALL require an ABP filing for every approved run of an ABP-obligated administratie

`lib/Standards/rules/payroll.json` gained `nl-abp-fund-required` under the
existing `nl-pensioenaangifte` framework (the same framework the three
shipped UPA rules use), anchored on `PayrollRun`, `severity: mandatory`,
`machineCheckable: true`, sourced to the Wet Privatisering ABP (1996,
`https://wetten.overheid.nl/BWBR0007791`). The rule is additive alongside the
shipped `nl-upa-monthly-completeness` rule — it does not replace, edit, or
narrow that rule's existing fund-blind behaviour. `RuleCatalogue::VERSION`
was bumped from `2026-07.26` to `2026-07.27`.

#### Scenario: The corpus stays loadable and versioned
- **GIVEN** the corpus edit adding `nl-abp-fund-required`
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** the RuleCatalogue loads without error and reports `nl-abp-fund-required` as enforced (a `CheckProvider` predicate exists for it)
- **AND** `nl-upa-monthly-completeness` still reports exactly the same coverage it did before this change

### REQ-ABP-003: A new auto-discovered NlAbpChecks provider SHALL enforce nl-abp-fund-required, fund- and tenant-scoped

`lib/Standards/Checks/NlAbpChecks.php` (implements `CheckProvider`) registers
the `nl-abp-fund-required` predicate on `PayrollRun`, reading three indexes
`RuleAuditService::buildRelatedContext()` was extended with (built once per
audit run, no per-object IO): an `Administration.abpPlichtigByAdministrationId`
map keyed on the `administrationId` business key; the existing
`PayrollRun.byId` entries gaining an `administrationId` field; and a new
`PensionFiling.abpFiledPeriodsByAdministrationId` map (each administratie's
set of periods with at least one `fund: "abp"` filing), kept separate from
the existing, unchanged, fund-blind global `filedPeriods` set. The predicate
is a vacuous pass when the run's `jurisdiction` is not `NL`, when its
`status` is not `approved`, `posted`, or `paid`, when its `period` is empty,
or when its `administrationId` does not resolve to an `Administration` whose
`abpPlichtigByAdministrationId` entry is `true`. Otherwise it violates when
the run's own `(period, administrationId)` pair is absent from
`abpFiledPeriodsByAdministrationId`.

#### Scenario: An obligated administratie's unfiled period is flagged
- **GIVEN** an NL `PayrollRun` in status `approved`, `administrationId: "ADM-003"`, period `"2026-06"`, where `Administration` `ADM-003` has `abpAansluitingsplichtig: true` and no `PensionFiling` with `fund: "abp"` exists for `("2026-06", "ADM-003")`
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** a `nl-abp-fund-required` violation is reported for that run

#### Scenario: A non-obligated administratie never violates
- **GIVEN** an NL `PayrollRun` in status `approved` whose `administrationId` resolves to an `Administration` with `abpAansluitingsplichtig: false` (or absent)
- **WHEN** the audit runs
- **THEN** no `nl-abp-fund-required` violation is reported for that run, regardless of whether an ABP `PensionFiling` exists

#### Scenario: An obligated administratie with its own ABP filing passes
- **GIVEN** an NL `PayrollRun` in status `approved`, `administrationId: "ADM-001"` (`abpAansluitingsplichtig: true`), whose period has a `fund: "abp"` `PensionFiling` scoped to `ADM-001`
- **WHEN** the audit runs
- **THEN** no `nl-abp-fund-required` violation is reported for that run

#### Scenario: A draft run is out of scope
- **GIVEN** an NL `PayrollRun` in status `draft` belonging to an `abpAansluitingsplichtig` administratie
- **WHEN** the audit runs
- **THEN** no `nl-abp-fund-required` violation is reported for it

#### Scenario: The global fund-blind rule stays silent for the same run the new rule flags
- **GIVEN** the `ADM-003` run from the first scenario, whose period `"2026-06"` already has a global `fund: "abp"` filing from `ADM-001` (a different administratie)
- **WHEN** the audit runs
- **THEN** `nl-upa-monthly-completeness` reports no violation for the `ADM-003` run (the period is globally filed), while `nl-abp-fund-required` still reports its violation (the `ADM-003` administratie itself never filed) — demonstrating the two rules are not redundant

### REQ-ABP-004: Seed data SHALL prove both the satisfied and violated branches of the new rule

`lib/Settings/register.d/hr-seed.json` flipped the existing `ADM-001`
`Administration` row to `abpAansluitingsplichtig: true` (its two existing
approved NL `PayrollRun`s for 2026-05/2026-06 already each carry a
`fund: "abp"` `PensionFiling` — no new filing seeds were needed to prove the
happy path). It gained a new, minimal `ADM-003` (`"Gemeente Voorbeeld"`)
`Administration` row (`abpAansluitingsplichtig: true`) with one
`AdministrationAccess` row (`admin`, role `accountant`) and one new approved
NL `PayrollRun` scoped to `ADM-003` for period `"2026-06"`, with no
`PensionFiling` seeded for `ADM-003`.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** `ADM-001`'s flag, `ADM-003`, its `AdministrationAccess` row, and its `PayrollRun` all exist exactly once

#### Scenario: Seeded data reproduces both branches in one audit
- **GIVEN** the seeded `ADM-001` and `ADM-003` data
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** exactly one `nl-abp-fund-required` violation is reported (for the `ADM-003` run) and none for either `ADM-001` run

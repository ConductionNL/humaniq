# abp-aansluiting

## ADDED Requirements

### Requirement: The Administration catalog SHALL record which client administraties are legally obligated to affiliate with ABP (REQ-ABP-001)

`lib/Settings/register.d/hr-administratie.json`'s `Administration` schema SHALL gain
`abpAansluitingsplichtig` (boolean, default `false`), an admin-set determination that this client
administratie is obligated under the Wet Privatisering ABP (1996) to affiliate with ABP and file its
pension-bearing payroll there. The field SHALL NOT be derived or computed from any sector, function,
or CAO field — hrmq carries no employer-sector taxonomy today, so an admin sets it explicitly. The
existing `PayrollRun.administrationId` description in `lib/Settings/register.d/hr-objects.json`,
which still states no Administration schema is modeled in hrmq, SHALL be corrected to reflect the
shipped `multi-administratie` `Administration` schema.

#### Scenario: An administratie is marked ABP-obligated

- **GIVEN** an `Administration` row with `abpAansluitingsplichtig` unset (default `false`)
- **WHEN** an admin sets `abpAansluitingsplichtig: true`
- **THEN** the row persists the flag and no other field on `Administration` is affected

#### Scenario: The flag is never auto-derived

- **GIVEN** an `Administration` row whose linked contracts reference an overheid-sector CAO
- **WHEN** the row is read
- **THEN** `abpAansluitingsplichtig` remains whatever an admin explicitly set (default `false`) — no
  automatic derivation from the CAO or any other field occurs

### Requirement: A new corpus rule SHALL require an ABP filing for every approved run of an ABP-obligated administratie (REQ-ABP-002)

`lib/Standards/rules/payroll.json` SHALL gain `nl-abp-fund-required` under the existing
`nl-pensioenaangifte` framework (the same framework the three shipped UPA rules use), anchored on
`PayrollRun`, `severity: mandatory`, `machineCheckable: true`, sourced to the Wet Privatisering ABP
(1996). This rule SHALL be additive alongside the shipped `nl-upa-monthly-completeness` rule — it
SHALL NOT replace, edit, or narrow that rule's existing fund-blind behaviour.
`RuleCatalogue::VERSION` SHALL be bumped.

#### Scenario: The corpus stays loadable and versioned

- **GIVEN** the corpus edit adding `nl-abp-fund-required`
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** the RuleCatalogue loads without error and reports `nl-abp-fund-required` as enforced
  (a `CheckProvider` predicate exists for it)
- **AND** `nl-upa-monthly-completeness` still reports exactly the same coverage it did before this
  change

### Requirement: A new auto-discovered NlAbpChecks provider SHALL enforce nl-abp-fund-required, fund- and tenant-scoped (REQ-ABP-003)

`lib/Standards/Checks/NlAbpChecks.php` (implements `CheckProvider`) SHALL register the
`nl-abp-fund-required` predicate on `PayrollRun`, reading three indexes
`RuleAuditService::buildRelatedContext()` SHALL be extended with (built once per audit run, no
per-object IO): an `Administration.abpPlichtigByAdministrationId` map keyed on the
`administrationId` business key; the existing `PayrollRun.byId` entries gaining an
`administrationId` field; and a new `PensionFiling.abpFiledPeriodsByAdministrationId` map (each
administratie's set of periods with at least one `fund: "abp"` filing), kept separate from the
existing, unchanged, fund-blind global `filedPeriods` set. The predicate SHALL be a vacuous pass
when the run's `jurisdiction` is not `NL`, when its `status` is not `approved`, `posted`, or `paid`,
when its `period` is empty, or when its `administrationId` does not resolve to an `Administration`
whose `abpPlichtigByAdministrationId` entry is `true`. Otherwise it SHALL violate when the run's own
`(period, administrationId)` pair is absent from `abpFiledPeriodsByAdministrationId`.

#### Scenario: An obligated administratie's unfiled period is flagged

- **GIVEN** an NL `PayrollRun` in status `approved`, `administrationId: "ADM-003"`, period
  `"2026-06"`, where `Administration` `ADM-003` has `abpAansluitingsplichtig: true` and no
  `PensionFiling` with `fund: "abp"` exists for `("2026-06", "ADM-003")`
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** a `nl-abp-fund-required` violation is reported for that run

#### Scenario: A non-obligated administratie never violates

- **GIVEN** an NL `PayrollRun` in status `approved` whose `administrationId` resolves to an
  `Administration` with `abpAansluitingsplichtig: false` (or absent)
- **WHEN** the audit runs
- **THEN** no `nl-abp-fund-required` violation is reported for that run, regardless of whether an
  ABP `PensionFiling` exists

#### Scenario: An obligated administratie with its own ABP filing passes

- **GIVEN** an NL `PayrollRun` in status `approved`, `administrationId: "ADM-001"`
  (`abpAansluitingsplichtig: true`), whose period has a `fund: "abp"` `PensionFiling` scoped to
  `ADM-001`
- **WHEN** the audit runs
- **THEN** no `nl-abp-fund-required` violation is reported for that run

#### Scenario: A draft run is out of scope

- **GIVEN** an NL `PayrollRun` in status `draft` belonging to an `abpAansluitingsplichtig`
  administratie
- **WHEN** the audit runs
- **THEN** no `nl-abp-fund-required` violation is reported for it

#### Scenario: The global fund-blind rule stays silent for the same run the new rule flags

- **GIVEN** the `ADM-003` run from the first scenario, whose period `"2026-06"` already has a global
  `fund: "abp"` filing from `ADM-001` (a different administratie)
- **WHEN** the audit runs
- **THEN** `nl-upa-monthly-completeness` reports no violation for the `ADM-003` run (the period is
  globally filed), while `nl-abp-fund-required` still reports its violation (the `ADM-003`
  administratie itself never filed) — demonstrating the two rules are not redundant

### Requirement: Seed data SHALL prove both the satisfied and violated branches of the new rule (REQ-ABP-004)

`lib/Settings/register.d/hr-seed.json` SHALL flip the existing `ADM-001` `Administration` row to
`abpAansluitingsplichtig: true` (its two existing approved NL `PayrollRun`s for 2026-05/2026-06
already each carry a `fund: "abp"` `PensionFiling` — no new filing seeds are needed to prove the
happy path). It SHALL gain a new, minimal `ADM-003` (`"Gemeente Voorbeeld"`) `Administration` row
(`abpAansluitingsplichtig: true`) with one `AdministrationAccess` row (`admin`, role `accountant`)
and one new approved NL `PayrollRun` scoped to `ADM-003` for period `"2026-06"`, with no
`PensionFiling` seeded for `ADM-003`.

#### Scenario: Idempotent seed

- **WHEN** the register Repair import runs twice
- **THEN** `ADM-001`'s flag, `ADM-003`, its `AdministrationAccess` row, and its `PayrollRun` all
  exist exactly once

#### Scenario: Seeded data reproduces both branches in one audit

- **GIVEN** the seeded `ADM-001` and `ADM-003` data
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** exactly one `nl-abp-fund-required` violation is reported (for the `ADM-003` run) and none
  for either `ADM-001` run

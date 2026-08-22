# wnt-disclosure

## ADDED Requirements

### Requirement: The Employee schema SHALL mark topfunctionarissen and record a transitional-exemption ground (REQ-WNT-001)

`lib/Settings/register.d/hr-objects.json`'s `Employee` schema SHALL gain `wntTopfunctionaris`
(boolean, default `false`) and `wntUitzonderingReden` (nullable enum `overgangsrecht` \|
`ontheffing-minister`, default null). `wntTopfunctionaris` SHALL mark that the employee is a
topfunctionaris under the Wet normering topinkomens (WNT). `wntUitzonderingReden` SHALL record which
valid transitional-exemption ground applies, when one does; it SHALL carry no meaning for an
employee whose `wntTopfunctionaris` is `false`. Neither field SHALL be derived or computed from any
other field.

#### Scenario: An employee is marked as topfunctionaris

- **GIVEN** an `Employee` with `wntTopfunctionaris` unset (default `false`)
- **WHEN** HR sets `wntTopfunctionaris: true`
- **THEN** the field persists and `wntUitzonderingReden` remains null until explicitly set

#### Scenario: A transitional exemption is recorded

- **GIVEN** a topfunctionaris `Employee` whose contract predates a norm reduction
- **WHEN** HR sets `wntUitzonderingReden: "overgangsrecht"`
- **THEN** the field persists distinctly from `ontheffing-minister` and from null

### Requirement: A new WntDisclosure schema SHALL record the annual WNT-verantwoording per topfunctionaris (REQ-WNT-002)

A new fragment `lib/Settings/register.d/hr-wnt.json` (`x-humaniq-fragment: hr-wnt`) SHALL declare
`WntDisclosure` (`x-schema-org: schema:Report`) with `employeeId` (`$ref: Employee`), `year` (string,
YYYY), `totalCompensation` (number), and `status` (enum `concept` \| `gepubliceerd`, default
`concept`), required `[employeeId, year, totalCompensation, status]`. Its
`configuration.x-openregister-lifecycle` SHALL declare `field: status`, `initial: concept`, and one
transition `publiceren` (concept → gepubliceerd), with no guard. The set of a given year's
`WntDisclosure` rows SHALL constitute that year's WNT-verantwoording; no separate aggregate report
schema SHALL be introduced.

#### Scenario: A disclosure is recorded and published

- **GIVEN** a topfunctionaris `Employee` and a `WntDisclosure` for `year: "2026"` in status `concept`
- **WHEN** the `publiceren` transition is executed
- **THEN** the disclosure's `status` becomes `gepubliceerd`

#### Scenario: The annual report is the filtered set, not a separate object

- **GIVEN** three `WntDisclosure` rows for `year: "2026"` across three topfunctionarissen
- **WHEN** the `WntDisclosures` index page is filtered on `year: "2026"`
- **THEN** all three rows appear and no separate aggregate "annual report" object exists anywhere in
  the register

### Requirement: A corpus rule SHALL flag total compensation above the WNT norm without a valid transitional exemption (REQ-WNT-003)

`lib/Standards/rules/payroll.json` SHALL gain `nl-wnt-norm-overschrijding` under a new framework
slug `wnt-2013` (added to `lib/Standards/rules/SCHEMA.md`'s framework-examples list), anchored on
`WntDisclosure`, `severity: mandatory`, `machineCheckable: true`, sourced to WNT art. 2.3
(BWBR0032249). The predicate SHALL read the WNT-norm figure via the `TaxTables` accessor
`30-procent-regeling` adds for `parameters.dertigProcentRegeling.aftoppingsgrens` — it SHALL NOT
re-declare that figure in a second corpus file. The rule SHALL be a vacuous pass when the norm
accessor or leaf does not yet exist (the `30-procent-regeling` dependency has not landed), when the
referenced `Employee.wntTopfunctionaris` is not `true`, or when that employee's
`wntUitzonderingReden` is non-null. Otherwise it SHALL violate when `totalCompensation` exceeds the
norm's annual (`jaar`) figure. `RuleAuditService::buildRelatedContext()`'s existing `Employee.byId`
map SHALL gain `wntUitzonderingReden`. `RuleCatalogue::VERSION` SHALL be bumped.

#### Scenario: Compensation above norm without exemption is flagged

- **GIVEN** a topfunctionaris `Employee` with `wntUitzonderingReden: null` and a `WntDisclosure`
  whose `totalCompensation` exceeds the WNT norm's annual figure
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** an `nl-wnt-norm-overschrijding` violation is reported for that disclosure

#### Scenario: A valid transitional exemption clears the flag

- **GIVEN** a topfunctionaris `Employee` with `wntUitzonderingReden: "overgangsrecht"` and a
  `WntDisclosure` whose `totalCompensation` exceeds the WNT norm's annual figure
- **WHEN** the audit runs
- **THEN** no `nl-wnt-norm-overschrijding` violation is reported for that disclosure

#### Scenario: Compensation at or below norm passes

- **GIVEN** a topfunctionaris `Employee` with `wntUitzonderingReden: null` and a `WntDisclosure`
  whose `totalCompensation` is at or below the WNT norm's annual figure
- **WHEN** the audit runs
- **THEN** no `nl-wnt-norm-overschrijding` violation is reported for that disclosure

#### Scenario: A non-topfunctionaris employee's disclosure never violates

- **GIVEN** a `WntDisclosure` whose referenced `Employee` has `wntTopfunctionaris: false`
- **WHEN** the audit runs
- **THEN** no `nl-wnt-norm-overschrijding` violation is reported for that disclosure, regardless of
  `totalCompensation`

#### Scenario: An unavailable norm degrades to a vacuous pass, never a fabricated figure

- **GIVEN** the `30-procent-regeling` `TaxTables` accessor for `dertigProcentRegeling` does not yet
  exist (that change has not landed)
- **WHEN** the audit runs
- **THEN** `nl-wnt-norm-overschrijding` reports no violations for any `WntDisclosure` — it SHALL NOT
  substitute a hardcoded or estimated norm figure

### Requirement: The WntDisclosures pages SHALL land as a sibling in the existing payroll menu group, with no new top-level menu (REQ-WNT-004)

`src/manifest.json` SHALL gain a `WntDisclosures` index page (columns `year`, `employeeId`,
`totalCompensation`, `status`) and a `WntDisclosureDetail` detail page with a `lifecycleActions`
widget exposing "Publiceren", both landing as sibling entries inside the existing `PayrollGroup`
menu alongside `PensionFilings` and `LoonaangifteFilings` — not a new top-level menu. `npm run
check:manifest` MUST pass.

#### Scenario: No new top-level menu is added

- **WHEN** the manifest menu is read after this change
- **THEN** the top-level menu count is unchanged and `WntDisclosures` appears as a sibling entry in
  the existing `PayrollGroup` menu

#### Scenario: The manifest stays valid

- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

### Requirement: Seed data SHALL prove the exemption gate and the violation branch (REQ-WNT-005)

`lib/Settings/register.d/hr-seed.json` SHALL gain three topfunctionaris `Employee` seeds with
corresponding `WntDisclosure` rows for `year: "2026"`: one at or under the WNT norm with no
exemption (clean pass), one over the norm with `wntUitzonderingReden: "overgangsrecht"` (clean pass
via the exemption gate), and one over the norm with `wntUitzonderingReden: null` (the violation
branch). Every pre-existing seeded `Employee` SHALL keep `wntTopfunctionaris: false`.

#### Scenario: Idempotent seed

- **WHEN** the register Repair import runs twice
- **THEN** all three new `Employee` and `WntDisclosure` seed pairs exist exactly once

#### Scenario: The seed reproduces exactly one violation

- **GIVEN** the three seeded topfunctionarissen and their disclosures
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** exactly one `nl-wnt-norm-overschrijding` violation is reported

#### Scenario: The pre-existing seed population stays silent

- **GIVEN** every pre-existing seeded `Employee` (all `wntTopfunctionaris: false`)
- **WHEN** the audit runs
- **THEN** no `nl-wnt-norm-overschrijding` violation is reported for any of them

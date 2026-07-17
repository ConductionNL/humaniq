# cao-sector-datasets Specification

## ADDED Requirements

### Requirement: Six sector CAOs SHALL ship as corpus data in the existing leaf shape with no loader change (REQ-CAOS-001)

`cao-rijk`, `cao-gemeenten`, `cao-onderwijs-po`, `cao-onderwijs-vo`, `cao-ziekenhuizen` and `cao-zorg-vvt` SHALL each ship as one `lib/Standards/cao/{cao-id}.json` file carrying the existing top-level shape (`id`, `name`, `sector`, `version`, `effectiveDate`, `basedOn`) and the four existing `{value, source, verified}` leaf groups (`payScales`, `allowances`, `leaveEntitlement`, `workingTime`) documented in `cao/SCHEMA.md`. The `schaal` keys within a CAO's `payScales.value` MAY use any sector-specific naming convention (numeric BBRA schalen, letter onderwijsschalen, FWG-functiegroep ids) since `CaoRegistry` treats `schaal` as an opaque string. No change SHALL be made to `lib/Standards/CaoRegistry.php`'s loading, merging, glob, or validation logic — only `CaoRegistry::VERSION` is bumped.

#### Scenario: All nine CAOs load through the unmodified loader

- **GIVEN** the three existing corpus files (`cao-generiek`, `cao-metaal-techniek`, `cao-horeca`) plus the six new files added by this change
- **WHEN** `CaoRegistry::availableCaos()` is called
- **THEN** it returns all nine CAO ids with their `name`/`sector`/`version`/`effectiveDate`, and `CaoRegistry::get()` resolves the full record for each of the six new ids

#### Scenario: A sector-specific scale identifier resolves like any other

- **GIVEN** `cao-ziekenhuizen.json` with a `payScales.value` keyed by FWG-functiegroep ids (e.g. `"FWG-40"`)
- **WHEN** `CaoRegistry::minMaandloonCents('cao-ziekenhuizen', 'FWG-40')` is called
- **THEN** the lookup succeeds exactly as it would for a letter or numeric schaal key — `CaoRegistry` performs no schaal-format validation

### Requirement: Placeholder leaves on the six new CAOs SHALL be advisory, never a false mandatory violation (REQ-CAOS-002)

Every leaf on the six new CAOs that is not backed by a directly-fetched primary source SHALL carry `verified: false`, `placeholder: true`, and a `checkAgainst` pointer naming a real, current, sector-specific source (the CAO's own publication, the sector employer association, or caoloon.nl) — never a bare, unsourced number. `CaoRegistry::minMaandloonCents()` and `CaoRegistry::minLeaveHours()` SHALL resolve `null` for every such placeholder leaf, exactly as they already do for `cao-metaal-techniek`/`cao-horeca`, so `NlCaoChecks` treats the scale/leave figure as advisory (vacuous pass) until a maintainer confirms it and flips `verified: true`.

#### Scenario: A placeholder pay scale on any of the six new CAOs never raises a violation

- **GIVEN** an `EmploymentContract` with `cao: "cao-onderwijs-vo"`, `caoSchaal: "LB"`, whose `payScales` leaf is `verified: false` / `placeholder: true`
- **WHEN** `occ hrmq:rules:audit` runs regardless of the employee's `grossMonthlySalary`
- **THEN** no `nl-cao-minimumloon-schaal` violation is reported for that contract (the figure is advisory, not enforced)

#### Scenario: A placeholder leave minimum on any of the six new CAOs never raises a violation

- **GIVEN** a `LeaveBalance` (`leaveType: "holiday"`) whose employee's active contract is `cao: "cao-zorg-vvt"`, whose `leaveEntitlement` leaf is `verified: false` / `placeholder: true`
- **WHEN** the audit runs regardless of `entitledHours + bovenwettelijkHours`
- **THEN** no `nl-cao-verlof-minimum` violation is reported for that balance

### Requirement: The existing below-CAO checks SHALL cover the six new CAOs with no predicate change (REQ-CAOS-003)

`nl-cao-minimumloon-schaal` and `nl-cao-verlof-minimum`, implemented by `NlCaoChecks` (unchanged by this proposal), SHALL enforce against any of the six new CAOs the moment a leaf is confirmed and flipped to `verified: true`, with no edit to `NlCaoChecks`, `RuleAuditService::audit()`'s `cao.*` context enrichment, or the rule definitions in `lib/Standards/rules/payroll.json` / `labour.json`. The predicates read `cao`/`caoSchaal` from the object under audit and resolve through `CaoRegistry`; they do not name a fixed set of CAO ids.

#### Scenario: A verified minimum on a new CAO is enforced without a code change

- **GIVEN** a maintainer confirms `cao-rijk`'s `payScales` leaf against the official BBRA loontabel and flips it to `verified: true`, and a contract `cao: "cao-rijk"`, `caoSchaal` set, whose employee's `grossMonthlySalary` is below that confirmed minimum
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** a mandatory `nl-cao-minimumloon-schaal` violation is reported for that contract, with zero lines of `NlCaoChecks`/`RuleAuditService`/rule-catalogue code changed to make this happen

#### Scenario: Cross-object salary/CAO resolution already covers employees on the new CAOs

- **GIVEN** an `Employee` with an active `EmploymentContract` naming `cao-onderwijs-po`
- **WHEN** `RuleAuditService::audit()` builds its `cao.employeesById` / `cao.caoByEmployeeId` context
- **THEN** that employee and their CAO resolve through the same context-enrichment code path used for `cao-generiek`/`cao-metaal-techniek`/`cao-horeca` employees, with no CAO-id-specific branching

### Requirement: A contract SHALL reference any of the six new CAOs through the existing scalar fields (REQ-CAOS-004)

`EmploymentContract.cao` and `EmploymentContract.caoSchaal` (register.d `hr-objects.json`, already free-text scalar fields referencing a corpus id) SHALL resolve the six new CAO ids exactly as they resolve the three existing ones, with no schema change to `hr-objects.json`.

#### Scenario: A contract links to a newly added sector CAO and scale

- **GIVEN** an `EmploymentContract` with `cao: "cao-ziekenhuizen"` and `caoSchaal: "FWG-40"`
- **WHEN** the contract is read
- **THEN** `cao` resolves through `CaoRegistry::get()` to the `cao-ziekenhuizen` ruleset and `caoSchaal` selects the `FWG-40` scale used by the pay-scale check, with no field added or redefined on `EmploymentContract`

### Requirement: The read-only reference pages SHALL list the six new CAOs with no manifest surgery beyond seed data (REQ-CAOS-005)

The existing `Caos` index page and `CaoDetail` detail page (`src/manifest.json`, register+schema-backed on the existing `Cao` schema, `allowCreate: false`) SHALL render the six new CAOs once `NlCaoChecks::seedObjects()` (unchanged, iterates `CaoRegistry::availableCaos()`) has been re-run via the existing `occ hrmq:rules:seed-test-data` seeding hook. No edit to `src/manifest.json`, `lib/Settings/register.d/hr-cao.json`, or the menu entry SHALL be required.

#### Scenario: Re-seeding after adding the six CAOs surfaces them on the reference page with no manifest edit

- **GIVEN** the six new corpus files exist and `occ hrmq:rules:seed-test-data` has been run
- **WHEN** a user opens the `Caos` page
- **THEN** it lists all nine CAOs (the three existing plus the six new ones) with their sector/version, `allowCreate: false` unchanged, and `CaoDetail` shows each new CAO's scales, allowances, leave and working-time — with zero lines changed in `src/manifest.json`

### Requirement: Annual/sector-CAO maintenance SHALL be a dataset change plus a `CaoRegistry::VERSION` bump, nothing else (REQ-CAOS-006)

Confirming a placeholder leaf against its primary source, or updating a CAO to a new CAO-akkoord year, SHALL require only editing the relevant `lib/Standards/cao/{cao-id}.json` file(s) and bumping `CaoRegistry::VERSION` — no change to `NlCaoChecks`, `RuleAuditService`, the rule catalogue, `RuleCatalogue::VERSION`, register.d, or the manifest, per `cao/SCHEMA.md`'s documented re-issue discipline. Adding a further new sector CAO beyond these six follows the identical path.

#### Scenario: Flipping a placeholder to verified is a data-only edit

- **GIVEN** a maintainer has confirmed `cao-gemeenten`'s `payScales` leaf against the official VNG salaristabel
- **WHEN** they edit `lib/Standards/cao/cao-gemeenten.json` to set `verified: true` on that leaf and bump `CaoRegistry::VERSION`
- **THEN** `nl-cao-minimumloon-schaal` begins enforcing that scale on the next audit run, with no PHP, register.d, or manifest file touched

#### Scenario: A seventh sector CAO added later needs no rule or loader change

- **GIVEN** a future maintainer adds a seventh CAO (e.g. `cao-bouw`) as `lib/Standards/cao/cao-bouw.json` and bumps `CaoRegistry::VERSION`
- **WHEN** `occ hrmq:rules:seed-test-data` next runs
- **THEN** the CAO appears in `CaoRegistry::availableCaos()`, is seeded as a `Cao` object, and is enforceable by the existing checks once verified — with `RuleCatalogue::VERSION` unchanged

### Requirement: This change SHALL exclude automatic CAO-text ingestion and per-CAO bespoke calculators, naming what remains a gap (REQ-CAOS-007)

This change SHALL NOT introduce automatic CAO-text ingestion/scraping, nor any per-CAO bespoke calculator. Where the shipped `cao-library` mechanism cannot express something the six old draft changes assumed — IKB accrual/spend ledgers, an ORT (onregelmatigheidstoeslag) time-segmentation engine, FWG-score-to-schaal derivation, periodiek/trede auto-progression, and multi-version CAO history with retroactive recalculation — this SHALL be documented as a named gap (design.md "Named gaps") rather than partially or silently built.

#### Scenario: IKB spend is out of scope and named, not silently omitted

- **GIVEN** `cao-rijk`'s corpus carries the IKB rate (`allowances.ikb`, 16.50%, verified)
- **WHEN** a reviewer looks for per-employee IKB budget accrual, spend transactions, or a remaining-budget figure anywhere in `hrmq`
- **THEN** none exists, and design.md's "Named gaps" section documents this explicitly as gap 1 (a fast-follow), rather than the audit silently ignoring IKB or a partial/undocumented ledger having been added

#### Scenario: ORT rates are reference data only; no computation is claimed

- **GIVEN** `cao-ziekenhuizen.json` and `cao-zorg-vvt.json` carry ORT percentage reference rates in their `allowances` leaf
- **WHEN** a payroll run is audited for an employee under either CAO
- **THEN** no check or calculator computes an ORT amount against actual worked shifts (no shift/roster object exists in `hrmq`'s schema), and design.md's "Named gaps" section documents this explicitly as gap 2

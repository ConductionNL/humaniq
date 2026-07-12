---
capability: loonaangifte-filing-lifecycle
status: done
built_by: openspec/changes/archive/2026-07-12-loonaangifte-filing-lifecycle
---

# loonaangifte-filing-lifecycle Specification

**Status**: done
**Scope**: hrmq
**OpenSpec changes**:
- [loonaangifte-filing-lifecycle](../../changes/archive/2026-07-12-loonaangifte-filing-lifecycle/) _(archived 2026-07-12)_ — declarative concept→klaargezet→bevestigd→verzonden lifecycle on `LoonaangifteFiling`, tijdvakcode + response fields, 3 new machine-checkable NL deadline/tijdvakcode rules, lifecycle actions + deadline KPIs on the filing pages (kind: config)

## Purpose

Turn the passive `LoonaangifteFiling` record into a real Dutch wage-tax filing
workflow: a declarative create→review→confirm→send state machine, first-class
tijdvakcode data per Belastingdienst LH 210, statutory deadline derivation
(period end + one calendar month, no weekend extension) and alerting as
versioned machine-checkable corpus rules, and filing pages that drive the
lifecycle. #1-ranked missing feature from the 2026-07-12 market deep-research
(Spectr insights `hrmq-insight-tijdvak-deadlines`, `hrmq-insight-ranked-buildlist`).
Digipoort wire transport is explicitly out of scope.

## Requirements

### REQ-LFL-001: `LoonaangifteFiling` SHALL carry a declarative lifecycle `concept → klaargezet → bevestigd → verzonden`

`lib/Settings/register.d/hr-objects.json` declares `x-openregister-lifecycle` on `LoonaangifteFiling` with `field: status`, `initial: concept`, and transitions `klaarzetten` (concept→klaargezet), `bevestigen` (klaargezet→bevestigd), `verzenden` (bevestigd→verzonden), `heropenen` (klaargezet|bevestigd→concept), `corrigeren` (verzonden→concept). The `verzenden` description documents that `submittedDate` and `verzondenDoor` are stamped on the carrying write (Timesheet `approvedAt`/`approvedBy` pattern). No guard classes are referenced in this change.

#### Scenario: Filing walks the happy path
- **GIVEN** a `LoonaangifteFiling` created for period `2026-06` (status defaults to `concept`)
- **WHEN** the actions `klaarzetten`, `bevestigen`, `verzenden` are executed in order via the OpenRegister lifecycle endpoint
- **THEN** the object's `status` ends as `verzonden` and the write carrying `verzenden` records `submittedDate`

#### Scenario: Illegal jump is rejected
- **GIVEN** a filing in status `concept`
- **WHEN** the `verzenden` action is attempted
- **THEN** OpenRegister rejects the transition (no `concept→verzonden` edge)

#### Scenario: Sent filing reopened only via corrigeren
- **GIVEN** a filing in status `verzonden`
- **WHEN** `corrigeren` is executed
- **THEN** status returns to `concept`; **AND** `heropenen` from `verzonden` is not a declared edge

### REQ-LFL-002: The schema SHALL model tijdvakcode, aangiftenummer, betalingskenmerk and response fields

New properties on `LoonaangifteFiling`: `status` (enum `concept|klaargezet|bevestigd|verzonden`, default `concept`), `tijdvakcode` (string, pattern `^[67][0-9]{3}$`, nullable), `aangiftenummer` (string, nullable), `betalingskenmerk` (string, nullable), `responseStatus` (enum `geen|ontvangen-ok|afgekeurd`, default `geen`), `responseMessage` (string, nullable), `verzondenDoor` (string, nullable). Schema `version` bumped to `0.2.0`. Existing properties and `required` list unchanged.

#### Scenario: Schema validates a complete NL filing
- **GIVEN** the imported hrmq register
- **WHEN** an object `{period: "2026-06", jurisdiction: "NL", filingType: "loonaangifte", tijdvak: "maand", tijdvakcode: "6060", deadline: "2026-07-31"}` is created
- **THEN** creation succeeds and `status` is `concept`, `responseStatus` is `geen`

#### Scenario: Malformed tijdvakcode rejected
- **WHEN** an object is written with `tijdvakcode: "ABC1"`
- **THEN** OpenRegister schema validation rejects it (pattern mismatch)

### REQ-LFL-003: The rule corpus SHALL gain three machine-checkable NL filing rules with the tijdvakcode table as rule data

`lib/Standards/rules/payroll.json` gains `nl-loonaangifte-tijdvakcode`, `nl-loonaangifte-deadline-derivation`, `nl-loonaangifte-deadline-alert` (all `domain: reporting`, `jurisdiction: NL`, `framework: nl-loonheffingen`, `machineCheckable: true`, `severity: mandatory`, sourced to Belastingdienst LH 210 2026 / AWR art. 19 with sourceUrl). `nl-loonaangifte-tijdvakcode` carries a `parameters` object with the 2026 code tables (`maand` prefix rule 60MM, the thirteen `vierweken` codes, `jaar: 6400`) so the annual re-issue is a data-only change.

#### Scenario: Corpus stays loadable and versioned
- **WHEN** `occ hrmq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads without error and reports the three new rules as enforced (each has a CheckProvider method)

### REQ-LFL-004: `NlWageTaxFilingChecks` SHALL enforce tijdvakcode consistency, deadline derivation, and deadline alerting

Three new check methods, each auto-discovered by the RuleEngine and jurisdiction-guarded to `NL` filings of `filingType: loonaangifte`:
1. **Tijdvakcode consistency** — `tijdvakcode` must match `period` + `tijdvak` per the rule's `parameters` table (e.g. `2026-01`+`maand` → `6010`); missing tijdvakcode on a non-`concept` filing is a violation.
2. **Deadline derivation** — `deadline` must equal the last day of the calendar month following the period end, with **no** weekend/holiday extension (e.g. period `2026-01` → `2026-02-28`).
3. **Deadline alert** — a filing whose `status` is not `verzonden` violates when `deadline` is in the past (mandatory) or within 14 days (advisory), evaluated against the audit run date.

> **Implementation note**: the shared `RuleEngine`/`Violation` model (lib/Standards/RuleEngine.php, lib/Standards/Violation.php) attaches one static `severity` to a rule id from the catalogue — it has no per-violation-instance severity. `nl-loonaangifte-deadline-alert` is catalogued as `severity: mandatory`, so every reported violation (both overdue and approaching-within-14-days) currently surfaces as `mandatory`; the prose distinction between "mandatory" (overdue) and "advisory" (approaching) is not yet differentiated in the emitted `Violation`. Splitting this would require either a fourth corpus rule (breaking this spec's three-rule cardinality) or a per-violation severity extension to the shared engine (used by all ~89 corpus rules) — tracked as a follow-up, not blocking.

#### Scenario: Wrong tijdvakcode flagged
- **GIVEN** a seeded filing `period: 2026-04, tijdvak: maand, tijdvakcode: 6050`
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** a `nl-loonaangifte-tijdvakcode` violation is reported for that object

#### Scenario: Weekend deadline is NOT extended
- **GIVEN** a filing for period `2026-01` with `deadline: 2026-03-02` (the Monday after Sat 28-02)
- **WHEN** the audit runs
- **THEN** a `nl-loonaangifte-deadline-derivation` violation is reported (expected `2026-02-28`)

#### Scenario: Overdue unfiled filing raises mandatory violation
- **GIVEN** the seed filing for period `2026-03` in status `concept` with `deadline: 2026-04-30`
- **WHEN** the audit runs on any date after 2026-04-30
- **THEN** a `nl-loonaangifte-deadline-alert` violation with mandatory severity is reported

### REQ-LFL-005: The filing pages SHALL surface lifecycle actions and deadline KPIs

`src/manifest.json`: the `LoonaangifteFilings` index page lists `period`, `tijdvakcode`, `status`, `deadline`, `submittedDate`, `responseStatus` sorted by `deadline` ascending; the `LoonaangifteFilingDetail` page gains (a) a prominent widget surfacing at least Status and Deadline (implemented as a top-of-page `data` widget rather than a `stats-block`, since the vendored app-manifest-v2 schema's stat/stats-block `metric` only supports `count`/`sum` numeric aggregation and cannot echo a non-numeric enum/date field value — documented in the page's `_note`) and (b) a `lifecycleActions` widget exposing Klaarzetten / Bevestigen / Verzenden / Heropenen / Corrigeren, structured identically to `TimesheetDetail`'s lifecycleActions (real labels, no placeholder text). The manifest validates against app-manifest-v2 (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: Detail page drives the lifecycle
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** a filing in status `concept` opened on `LoonaangifteFilingDetail`
- **WHEN** the user executes Klaarzetten
- **THEN** the page reflects status `klaargezet` and offers Bevestigen

### REQ-LFL-006: Seed data SHALL exercise every lifecycle state and both alert branches

`lib/Settings/register.d/hr-seed.json` gains the four filings from design.md (verzonden+ok, verzonden+geen response, bevestigd approaching, concept overdue), using placeholder identifiers only (loonheffingennummer `000000000L01`).

#### Scenario: Idempotent seed
- **WHEN** `occ hrmq:rules:seed-testdata` (or the register Repair import) runs twice
- **THEN** the four filings exist exactly once

---
capability: wkr-administration
status: done
built_by: openspec/changes/archive/2026-07-15-wkr-administration
---

# wkr-administration Specification

**Status**: done
**Scope**: hrmq (`depends_on: []`)
**OpenSpec changes**:
- [wkr-administration](../../changes/archive/2026-07-15-wkr-administration/) _(archived 2026-07-15)_
  — the werkkostenregeling (WKR) administration + reporting layer above the pre-existing per-payslip
  WKR fields: a `WkrDeclaration` ledger of vergoedingen/verstrekkingen/terbeschikkingstellingen
  (`wkrCategory` enum `gericht-vrijgesteld`/`vrije-ruimte`/`eindheffing`), the vrije-ruimte tranche +
  eindheffing percentages as sourced versioned table data (`lib/Standards/tables/nl-2026.json` `wkr`
  group — a new tax year is a data-only re-issue, never a PHP change), a `WkrService` rolling the
  cross-object fiscale loonsom (Σ `Payslip.grossPay`) + declarations into an idempotent
  `WkrAssessment` keyed `(administrationId, year)`, the RuleEngine-reachable administration-level
  rule `nl-wkr-eindheffing-exposure` (`lib/Standards/Checks/NlWkrChecks.php`, fed by
  `RuleAuditService::buildWkrContext()` — the `buildPayrollContext()` cross-object pre-pass idiom,
  injected into both `audit()` and `auditPayrollRunScope()`), the `hrmq:wkr:assess` occ command
  (`--administration`/`--year`/`--all`), one admin/HR-guarded `POST /api/payroll/wkr-assess`
  endpoint, and the `WkrDeclarations`/`WkrDeclarationDetail`/`WkrAssessments`/`WkrAssessmentDetail`
  manifest pages filed under the existing `PayrollGroup` (Loonadministratie) menu — no new
  top-level menu (kind: code+config, ADR-001). `WkrService` reads only already-persisted `grossPay`;
  it never invokes `lib/Payroll/`, so the assessment cannot drift from the payroll engine. Automated
  eindheffing payment/filing is a named fast-follow — this MVP is administration + reporting +
  exposure alert only.

## Purpose

WKR is administered from the employer's ledger of allowances/benefits for the WHOLE administration
and fiscal year, not from one payslip in isolation: the vrije ruimte is a percentage of the entire
fiscal wage bill, and "used" sums every payslip's designated allowances plus every stand-alone
declaration. Before this change there was no object to record a vergoeding/verstrekking, the
vrije-ruimte percentages were trapped in rule prose (a "verify percentage" TODO), and the only WKR
checks were per-payslip — so an administration could silently blow past its vrije ruimte with nothing
to notice, record, or check it. This change closes that gap: a recorded ledger, sourced table data,
a deterministic cross-object roll-up into a persisted, idempotent, RuleEngine-reachable assessment,
a trigger, and a reporting surface — while leaving the existing per-payslip `NlPayrollChecks`
predicates (`nl-wkr-vrije-ruimte`, `nl-wkr-eindheffing-80`) untouched.

## Requirements

### Requirement: A WkrDeclaration object SHALL record each vergoeding/verstrekking with a WKR category (REQ-WKR-001)

`lib/Settings/register.d/hr-objects.json` SHALL define a `WkrDeclaration` object recording one recorded allowance/benefit for an administration.

The object SHALL carry `administrationId`, `year`, `date`, `description`, `amount` (euro), a
`wkrCategory` enum of `gericht-vrijgesteld` | `vrije-ruimte` | `eindheffing`, an optional
`employeeId` `$ref` (null for a collective allowance) and a `sourceReference`. Only
`vrije-ruimte`-category amounts SHALL consume the vrije ruimte; `gericht-vrijgesteld` (the statutory
gerichte vrijstellingen, `nl-wkr-gerichte-vrijstelling`) and `eindheffing` (already designated
eindheffingsloon) amounts SHALL be excluded from the vrije-ruimte used-total.

#### Scenario: A collective allowance is recorded against the vrije ruimte
- **GIVEN** an administration `ADM-001` in year 2026
- **WHEN** a staff-outing allowance of €300,00 is recorded as a `WkrDeclaration` with
  `wkrCategory: "vrije-ruimte"` and `employeeId: null`
- **THEN** the declaration persists with that category and amount and is available to the
  administration-level vrije-ruimte roll-up

#### Scenario: A gerichte vrijstelling stays outside the vrije ruimte
- **GIVEN** a travel-cost reimbursement recorded as a `WkrDeclaration` with
  `wkrCategory: "gericht-vrijgesteld"`
- **WHEN** the vrije-ruimte used-total is computed for that administration and year
- **THEN** the reimbursement amount is not counted in `vrijeRuimteUsed`

### Requirement: The vrije-ruimte and eindheffing percentages SHALL be sourced versioned table data (REQ-WKR-002)

`lib/Standards/tables/nl-2026.json` SHALL carry a `wkr` parameter group whose sourced leaves hold the 2026 figures.

The group SHALL contain `vrijeRuimteTranche1Percent` (2,00), `vrijeRuimteTranche1Grens` (€400.000),
`vrijeRuimteTranche2Percent` (1,18) and `eindheffingPercent` (80), each a `{value, source, verified}`
leaf citing the Belastingdienst werkkostenregeling page (added to `basedOn`). The
`nl-wkr-vrije-ruimte` corpus rule statement SHALL be tightened to cite the `wkr` table group as the
authoritative figure and drop the inline "(verify percentage)". A subsequent tax year SHALL be a
data-only re-issue — a new `{jurisdiction}-{year}.json` with the year's figures plus a
`RuleCatalogue::VERSION` bump — with no PHP change.

#### Scenario: The 2026 percentages are read from the table, not from code
- **GIVEN** the `nl-2026` table
- **WHEN** the WKR roll-up computes the available vrije ruimte
- **THEN** it reads `vrijeRuimteTranche1Percent` 2,00, `vrijeRuimteTranche1Grens` €400.000,
  `vrijeRuimteTranche2Percent` 1,18 and `eindheffingPercent` 80 from the `wkr` group

#### Scenario: A new tax year is a data-only change
- **GIVEN** a hypothetical `nl-2027.json` with `vrijeRuimteTranche1Percent: 2.16`
- **WHEN** it is added and `RuleCatalogue::VERSION` is bumped
- **THEN** an assessment stamped `engineVersion: "nl-2027"` uses 2,16% with no PHP code change

### Requirement: WkrService SHALL roll the cross-object loonsom and declarations into an idempotent WkrAssessment (REQ-WKR-003)

`lib/Service/WkrService.php` SHALL compute a `WkrAssessment` per (administrationId, year) from the cross-object aggregate of the administration's payslips and declarations.

The service SHALL take the fiscale loonsom as the sum of `Payslip.grossPay` over the administration
and year (never recomputing payroll), take `vrijeRuimteUsed` as `Σ Payslip.wkrUsed +
Σ WkrDeclaration.amount where wkrCategory = 'vrije-ruimte'`, compute the available `vrijeRuimte` from
the `wkr` table group as `loonsom ≤ grens ? loonsom × t1% : grens × t1% + (loonsom − grens) × t2%`,
derive `excess = max(0, vrijeRuimteUsed − vrijeRuimte)`, `eindheffingDue = round2(excess × 80%)` and
`status` (`binnen-vrije-ruimte` when `excess = 0`, else `eindheffing-verschuldigd`), and upsert the
`WkrAssessment` keyed on (administrationId, year) via ObjectService — stamping `engineVersion` and
`assessedAt`. Recomputing for the same (administrationId, year) SHALL upsert in place, never
duplicate. All monetary arithmetic SHALL be integer cents.

#### Scenario: An administration within its vrije ruimte is assessed clean
- **GIVEN** `ADM-001` 2026 with a fiscale loonsom of €200.000 (→ vrije ruimte €4.000,00) and
  vrije-ruimte declarations + payslip `wkrUsed` totalling €300,00
- **WHEN** `WkrService::assess("ADM-001", 2026)` runs
- **THEN** the WkrAssessment records `vrijeRuimte` €4.000,00, `vrijeRuimteUsed` €300,00,
  `excess` €0,00, `eindheffingDue` €0,00 and `status` `binnen-vrije-ruimte`

#### Scenario: Re-assessing the same administration and year is idempotent
- **GIVEN** an assessment already exists for `(ADM-001, 2026)`
- **WHEN** `WkrService::assess("ADM-001", 2026)` runs again
- **THEN** the existing WkrAssessment is updated in place and no second assessment for that pair
  exists

### Requirement: A machine-checkable rule SHALL flag eindheffing exposure across the administration via the RuleEngine (REQ-WKR-004)

`lib/Standards/rules/payroll.json` SHALL define the machine-checkable rule `nl-wkr-eindheffing-exposure` and `lib/Standards/Checks/NlWkrChecks.php` SHALL register its predicate keyed to `WkrAssessment`.

The predicate SHALL be a pure `fn(array $assessment, array $context): bool` that reads the
cross-object aggregate from `$context['wkr'][administrationId][year]` — the sum of `Payslip.grossPay`
(the fiscale loonsom), the sum of `Payslip.wkrUsed` and the sum of `vrije-ruimte` `WkrDeclaration`
amounts — built by `RuleAuditService::buildWkrContext()` and injected into `audit()` and
`auditPayrollRunScope()` (the `buildPayrollContext()` precedent). It SHALL recompute the available
vrije ruimte from the `wkr` table group; SHALL be vacuously satisfied when the aggregate is absent;
SHALL be satisfied when `used ≤ available`; and SHALL be a violation when `used > available` unless
the assessment recorded the exposure (`status: eindheffing-verschuldigd`, `excess` cents-equal to
`used − available`, `eindheffingRate` 80, `eindheffingDue` cents-equal to `round2(excess × 80%)`).
Because the predicate is keyed to a persisted, audit-loaded object, it SHALL be reached by
`occ hrmq:rules:audit` with no bespoke caller.

#### Scenario: An over-budget administration that flagged the eindheffing is compliant
- **GIVEN** a WkrAssessment whose aggregate shows `used €5.000,00` against `available €4.000,00`, with
  `status: eindheffing-verschuldigd`, `excess €1.000,00`, `eindheffingRate 80` and
  `eindheffingDue €800,00`
- **WHEN** `occ hrmq:rules:audit` evaluates it
- **THEN** `nl-wkr-eindheffing-exposure` reports no violation for that assessment

#### Scenario: An over-budget administration that did not flag the eindheffing is a violation
- **GIVEN** the same aggregate (`used €5.000,00` > `available €4.000,00`) but a WkrAssessment left
  `status: binnen-vrije-ruimte` with `eindheffingDue €0,00`
- **WHEN** `occ hrmq:rules:audit` evaluates it
- **THEN** an `nl-wkr-eindheffing-exposure` violation is reported for that assessment

#### Scenario: An administration with no payslips is out of scope
- **GIVEN** a WkrAssessment for an administration and year with no payslips in the register (empty
  aggregate)
- **WHEN** the audit runs
- **THEN** the rule is vacuously satisfied and no violation is reported

### Requirement: An occ command SHALL compute and persist the WKR assessment (REQ-WKR-005)

`lib/Command/WkrAssessCommand.php` SHALL register `hrmq:wkr:assess --administration ADM --year YYYY [--all]` in `appinfo/info.xml`.

The command SHALL invoke `WkrService::assess` for the given administration and year (or, with
`--all`, for every distinct (administrationId, year) pair found across the payslips) and print the
per-administration outcome: fiscale loonsom, available vrije ruimte, used, remaining, excess and
eindheffing due.

#### Scenario: The command computes an assessment from live data
- **GIVEN** payslips and vrije-ruimte declarations for `ADM-001` in 2026
- **WHEN** `occ hrmq:wkr:assess --administration ADM-001 --year 2026` runs
- **THEN** a WkrAssessment for `(ADM-001, 2026)` is persisted and the outcome prints the loonsom,
  vrije ruimte, used, remaining, excess and eindheffing figures

### Requirement: The WKR reporting surface SHALL live under Loonadministratie with no new top-level menu (REQ-WKR-006)

`src/manifest.json` SHALL add the `WkrDeclarations`/`WkrDeclarationDetail` and `WkrAssessments`/`WkrAssessmentDetail` pages under the existing `PayrollGroup` menu.

The `WkrDeclarations` index SHALL allow hand-entry of declarations (`allowCreate: true`); the
`WkrAssessmentDetail` page SHALL surface the headline figures (fiscale loonsom, vrije ruimte,
vrije-ruimte used, eindheffing due) as stat KPIs plus a "Beoordelen" action that (re)assesses the
administration/year. No new top-level menu group SHALL be added (ADR-001: the menu groups are
frozen; WKR is an administration/reporting surface inside Loonadministratie). `npm run check:manifest`
MUST pass.

#### Scenario: WKR pages appear under Loonadministratie
@e2e exclude declarative manifest/menu wiring is covered by the shared CnPageRenderer library tests; the app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** the manifest after this change
- **WHEN** the menu is rendered
- **THEN** `WkrDeclarations` and `WkrAssessments` appear as pages under the existing `PayrollGroup`
  group and no new top-level menu group has been introduced

#### Scenario: The assessment detail shows the eindheffing headline
@e2e exclude declarative manifest/action wiring is covered by the shared CnPageRenderer library tests; the app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** a WkrAssessment for `(ADM-001, 2026)` with `status: eindheffing-verschuldigd`
- **WHEN** `WkrAssessmentDetail` renders it
- **THEN** the fiscale loonsom, vrije ruimte, vrije-ruimte used and eindheffing due are shown as stat
  KPIs and a "Beoordelen" action is available to recompute it

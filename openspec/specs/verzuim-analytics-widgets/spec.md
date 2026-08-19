---
capability: verzuim-analytics-widgets
status: done
built_by: openspec/changes/archive/2026-07-13-verzuim-analytics-widgets
---

# verzuim-analytics-widgets Specification

**Status**: done
**Scope**: hrmq
**Kind**: config (manifest + seeds only — no schemas, no PHP, no corpus changes)
**OpenSpec changes**:
- [verzuim-analytics-widgets](../../changes/archive/2026-07-13-verzuim-analytics-widgets/) _(archived 2026-07-13)_ — four absence-analytics stat widgets on the Dashboard (open ziektegevallen, langdurig verzuim past the WVP 42-weken horizon via `@today-294d`, verlofaanvragen in behandeling, this-month approved verlofuren sum) plus the `VerzuimOverzicht` open-cases werkvoorraad sorted by the UWV 42-weken deadline; manifest + seeds only, with Bradford/trend charts named non-goals for verified technical reasons (kind: config)
- [hrmq-dashboard-steering-indicators](../../changes/hrmq-dashboard-steering-indicators/) — **Status**: in-progress — removes REQ-VZA-001 (the four Dashboard stat widgets), superseded by the `absence-rate`-backed Absence rate trend widget; `VerzuimOverzicht` (REQ-VZA-002) and seed data (REQ-VZA-003) are unchanged

## Purpose

Give HR the absence analytics the stored data already supports: four
demonstrably-supported `stat` widgets on the Dashboard (open ziektegevallen,
langdurig verzuim past the WVP 42-weken horizon via an `@today`-relative
date-threshold filter, verlofaanvragen in behandeling, and this-month
approved verlofuren as a windowed sum) plus a `VerzuimOverzicht` open-cases
werkvoorraad sorted by the UWV 42-weken deadline (the round-1
`LoonaangifteFilings` deadline-queue pattern). Every widget shape was
verified at HEAD against the vendored manifest schema, `CnStatWidget`'s
`/value` aggregation fetch (metric count/sum, operator-aware
`filter[field][op]` serialization) and OpenRegister's filter/token handling;
Bradford factor and frequency/duration trend charts are named non-goals with
the precise technical reason each is out (see the archived proposal).
Spectr canon: `hrmq-canon-verzuim-analytics` (3/9 competitive coverage).

## Requirements

### Requirement: The Dashboard SHALL gain four absence-analytics stat widgets using only verified aggregation shapes (REQ-VZA-001)

The existing `Dashboard` page in `src/manifest.json` SHALL gain one new row of four `stat` widgets (existing widgets untouched — the sibling change `mss-team-scope` owns the approver-widget re-scope; the recent-hours object-table shifts down):

1. `dash-verzuim-open` — count of `SickLeaveCase` with `filter: { "status": "gemeld" }`, route `VerzuimOverzicht`;
2. `dash-verzuim-langdurig` — count of `SickLeaveCase` with `filter: { "status": "gemeld", "firstSickDay": { "lte": "@today-294d" } }` (42 weeks = 294 days, matching the corpus's `milestoneWeeks.uwv42WeekMelding`; the operator-map filter and the `@today±Nd` token are both verified-supported shapes), route `VerzuimOverzicht`;
3. `dash-leave-pending` — count of `LeaveRequest` with `filter: { "status": "submitted" }`, route `LeaveApproval` (the app's only global leave widget — the sibling change deliberately adds none);
4. `dash-leave-hours-month` — `metric: "sum"`, `field: "hours"` over `LeaveRequest` with `filter: { "status": "approved", "startDate": { "gte": "@monthStart" } }`, route `LeaveRequests`; its `_note` documents that `hours` is nullable so unhoured approved requests contribute nothing.

All four keep the proven `content.source` contract (`register: "hrmq"`, `metric`, token-resolved `filter`, `format: { "style": "number", "decimals": 0 }`); no chart, delta, or endpoint-bound widget is used.

#### Scenario: Long-term widget counts only open cases past the 42-weken horizon
@e2e exclude declarative widget wiring is covered by the shared CnStatWidget/resolveFilterTokens library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** the seeded data including `sickcase-degroot-wvp42` (firstSickDay 2025-06-02, gemeld) and `sickcase-devries-week7` (firstSickDay 2026-05-25, gemeld)
- **WHEN** the Dashboard renders
- **THEN** `dash-verzuim-langdurig` shows 1 (only the degroot case satisfies `firstSickDay <= @today-294d`) while `dash-verzuim-open` counts every gemeld case

#### Scenario: Monthly sum aggregates approved hours only
@e2e exclude declarative widget wiring is covered by the shared CnStatWidget library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** the seeded `leave-jansen-juli` (approved, 16 hours, startDate 2026-07-16) and `leave-jansen-zomer` (submitted, 80 hours, startDate 2026-08-03)
- **WHEN** the Dashboard renders during July 2026
- **THEN** `dash-leave-hours-month` shows 16 (the submitted request and out-of-window dates are excluded by the filter)

### Requirement: A `VerzuimOverzicht` page SHALL surface the open-case werkvoorraad sorted by the UWV 42-weken deadline (REQ-VZA-002)

`src/manifest.json` SHALL gain an index page `VerzuimOverzicht` (route `/verzuim`, register `hrmq`, schema `SickLeaveCase`) with fixed base `filter: { "status": "gemeld" }` (the `MijnUren` base-filter mechanism — the unfiltered `SickLeaveCases` index stays byte-identical as the full-history surface), columns `employeeId`, `firstSickDay`, `probleemanalyseDue`, `planVanAanpakDue`, `uwv42WeekMeldingDue`, `eerstejaarsevaluatieDue`, and default sort `uwv42WeekMeldingDue` ascending (the `LoonaangifteFilings` deadline-asc werkvoorraad pattern; ZW art. 38 makes the 42-wekenmelding the most consequential open-case deadline). Rows navigate to the existing `SickLeaveCaseDetail`. The page `description` SHALL repeat the administrative-only stance (no medical data). Menu: a `VerlofVerzuimGroup` child after `SickLeaveCases`, label `Verzuimoverzicht`, icon `ClipboardPulseOutline`. No new detail page, no deepLinks change. The manifest MUST validate (`npm run check:manifest`).

#### Scenario: Werkvoorraad lists open cases deadline-first
@e2e exclude declarative page filtering/sorting is covered by the shared CnIndexPage library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** the seeded open cases and the recovered `sickcase-jansen-flu`
- **WHEN** `VerzuimOverzicht` renders
- **THEN** only gemeld cases are listed, ordered by `uwv42WeekMeldingDue` ascending, and the recovered case is absent

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

### Requirement: Seed data SHALL feed every widget non-empty without adding any rule violation (REQ-VZA-003)

`lib/Settings/register.d/hr-seed.json` SHALL be extended only where a widget would render empty (verified fresh: open-cases and pending-leave are already fed):

1. `sickcase-degroot-wvp42` — employee slug `employee-degroot` (a new obvious-placeholder slug following the file's established dangling-slug convention; the seed-hygiene cleanup stays owned by the previously-flagged `hrmq-test-coverage-baseline`-era work), `firstSickDay: 2025-06-02`, `status: gemeld`, `wachtdag: false`, `loondoorbetalingPercentage: 70`, with all four WVP milestones derived AND done so no existing rule fires: dues exactly firstSickDay + 6/8/42/52 weeks (`probleemanalyseDue: 2025-07-14`, `planVanAanpakDue: 2025-07-28`, `uwv42WeekMeldingDue: 2026-03-23`, `eerstejaarsevaluatieDue: 2026-06-01` — `nl-wvp-milestone-derivation` green) and done dates before each due (`2025-07-10`, `2025-07-24`, `2026-03-19`, `2026-05-28` — `nl-wvp-milestone-overdue` green).
2. `leave-jansen-juli` — `employeeId: employee-jansen`, `leaveType: holiday`, `startDate: 2026-07-16`, `endDate: 2026-07-17`, `hours: 16`, `status: approved`, `submittedAt`/`approvedBy: manager-pietersen`/`approvedAt` per the existing approved-seed convention, `userId: "admin"`. NO `managerUserId` (that field belongs to the sibling `mss-team-scope`; this change is independent in both landing orders). Leave-rule safety verified at HEAD: `NlLeaveChecks` evaluates `LeaveBalance` objects only (fires nothing for a new `LeaveRequest`), and the sibling `mss-team-scope`'s `nl-mss-manager-consistency` predicate (registered on `LeaveRequest` too, via `NlOrgChecks`) passes vacuously on an unstamped `managerUserId` — both confirmed by a standalone `RuleEngine::evaluate()` run against the two new seed objects (zero violations either way, independent of landing order).

Time-rot is accepted and documented: the month-window sum is demonstrably non-empty during July 2026 and legitimately zero later — the inherent property of every dated seed in this file.

#### Scenario: No new audit violations from the seeds
- **GIVEN** the extended seed data
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** the degroot case and the July leave request contribute zero violations, and no pre-existing check regresses

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** the new case and the new leave request exist exactly once

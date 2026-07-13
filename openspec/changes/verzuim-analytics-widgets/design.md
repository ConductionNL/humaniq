# Design — verzuim-analytics-widgets

## Context

leave-verzuim-mvp gave hrmq the data for absence analytics without any analytics surface: `SickLeaveCase` stores the four WVP milestone Due dates (probleemanalyse +6w, plan van aanpak +8w, UWV 42-wekenmelding +42w, eerstejaarsevaluatie +52w — derived-but-stored, enforced by `nl-wvp-milestone-derivation`), and `LeaveRequest` carries status + nullable `hours`. The Dashboard (mijn-hr-self-service, adjusted by the sibling `mss-team-scope`) is a grid of `stat` KPIs + one object-table; the round-1 `LoonaangifteFilings` page established the deadline-sorted statutory werkvoorraad pattern (`sort: { field: "deadline", order: "asc" }`). Spectr `hrmq-canon-verzuim-analytics` (3/9 competitive coverage) names absence analytics as a differentiating gap.

## Widget capability (investigated)

What analytics shapes are demonstrably supported at HEAD — verified against `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`, the nc-vue widget sources, and the OpenRegister checkout (read-only):

- **`stat` widget, OpenRegister source** — `CnStatWidget` reads ONE scalar from `/apps/openregister/api/objects/aggregations/{register}/{schema}/value` given `content.source: { register, schema, metric, field?, filter }`. The endpoint's `AggregationController::value()` accepts `metric` (count | sum | avg | min | max | count_distinct, default count; `field` required for non-count) and operator-aware `filter[...]`. `CnStatWidget.flattenFilter()` serializes operator maps as `filter[key][op]=value` and OpenRegister's search handlers map `gte`/`lte` (and `gt`/`lt`) to SQL comparisons — so a **date-threshold count** (`filter: { "firstSickDay": { "lte": "@today-294d" } }`) and a **windowed sum** (`metric: "sum", field: "hours", filter: { "startDate": { "gte": "@monthStart" } }`) are both fully supported shapes.
- **Filter tokens** — `sentinelFilterToken`: `@me`, `@now`, `@today`, `@today±Nd` (day-granular arithmetic, any N), `@monthStart`, `@quarterStart`, `@yearStart`, resolved by `resolveFilterTokens` at fetch time. `@today-294d` (42 weeks) and `@monthStart` match the pattern verbatim.
- **Charts** — `CnChartWidget` supports two server-aggregated forms: categorical `aggregate: { groupBy, metric: count|sum, ... }` via `/grouped`, and time buckets `dataSource.bucket: { field, interval: minute…year, metric, metricField }` via `/timeseries`. The bucket form **requires a from/to range** ("range comes from the page's dateRange … or `bucket.staticRange`; if neither is available **no fetch**") — and the hrmq Dashboard has no `dateRange` wiring. So time-trend charts are possible-but-blocked (follow-up), and categorical group-by over `SickLeaveCase` has no useful axis (status has 2 values; grouping a raw date field gives one bucket per day).
- **What no source can express** — computed per-group expressions (Bradford S²×D), date-difference metrics (duration = `recoveredDate - firstSickDay`), and cross-schema denominators (verzuimpercentage). `x-openregister-aggregations` (the schema-annotation DSL, checked in the OpenRegister checkout: intra-schema `{metric, field, filter, groupBy}` + cross-schema `{from, …, @self refs}`) has the same metric vocabulary — count/sum/avg/min/max/count_distinct over stored fields — so annotating the schema would not unlock any of the three either.

Conclusion: the MVP is four `stat` widgets + one pre-filtered deadline-sorted index page. Nothing else is honest.

## Goals / Non-Goals

**Goals:** open-cases count; past-42-weeks (WVP long-term) count with an `@today`-relative threshold; pending-leave count; this-month approved leave hours (sum); a `VerzuimOverzicht` open-cases werkvoorraad sorted by the UWV 42-weken deadline; seeds proving every widget non-empty.

**Non-Goals:** Bradford factor (per-employee computed S²×D — no aggregation DSL can square or combine metrics; would need a bespoke endpoint + `endpointSource` = new PHP); frequency trend charts (library-supported via `dataSource.bucket`/`timeseries` but blocked on Dashboard dateRange wiring — hardcoded `staticRange` dates would rot); duration trends (date-difference metric does not exist); verzuimpercentage (cross-schema denominator); schema/PHP/corpus changes of any kind.

## Decisions

### D1 — Four `stat` widgets, one new row, existing widgets untouched

All four use the proven `content.source` shape (`register: hrmq`, `metric`, `filter` with tokens) and route to the page that answers the click:

| id | schema | metric | filter | route |
|---|---|---|---|---|
| `dash-verzuim-open` | SickLeaveCase | count | `{ "status": "gemeld" }` | `VerzuimOverzicht` |
| `dash-verzuim-langdurig` | SickLeaveCase | count | `{ "status": "gemeld", "firstSickDay": { "lte": "@today-294d" } }` | `VerzuimOverzicht` |
| `dash-leave-pending` | LeaveRequest | count | `{ "status": "submitted" }` | `LeaveApproval` |
| `dash-leave-hours-month` | LeaveRequest | sum of `hours` | `{ "status": "approved", "startDate": { "gte": "@monthStart" } }` | `LeaveRequests` |

Notes: 42 weeks = 294 days, matching the corpus's `milestoneWeeks.uwv42WeekMelding: 42` (the widget threshold and the stored `uwv42WeekMeldingDue` express the same statutory horizon — the widget filters on `firstSickDay` because the threshold must be `@today`-relative, and the token grammar offers day arithmetic on `@today` only). `hours` is nullable on LeaveRequest — sum aggregations skip absent values, so unhoured approved requests simply don't contribute (documented in the widget `_note`). `dash-leave-pending` is the app's only *global* leave widget: the sibling `mss-team-scope` re-scopes the hours/expenses approver widgets and deliberately adds no global leave count, so there is no duplication in either landing order. Layout: one new row of 4 × width-3 stats appended after the existing KPI rows; the recent-hours object-table shifts down; no existing widget's id, filter or content changes.

### D2 — `VerzuimOverzicht` is a pre-filtered scoped queue, not a replacement index

The `TimesheetApproval`-vs-`Timesheets` precedent: the unfiltered `SickLeaveCases` index remains the full-history surface; `VerzuimOverzicht` (`/verzuim`) is the operational werkvoorraad — base `filter: { "status": "gemeld" }` (fixed, the `MijnUren` mechanism), columns `employeeId`, `firstSickDay`, `probleemanalyseDue`, `planVanAanpakDue`, `uwv42WeekMeldingDue`, `eerstejaarsevaluatieDue`, sorted `uwv42WeekMeldingDue` ascending — the single most consequential statutory deadline on an open case (ZW art. 38: the melding UWV will fine you for missing), mirroring `LoonaangifteFilings`' deadline-asc queue. Rows navigate to the existing `SickLeaveCaseDetail` (index default row behaviour — no new detail page). Menu: child in `VerlofVerzuimGroup` after `SickLeaveCases`, label `Verzuimoverzicht`, icon `ClipboardPulseOutline`. The description repeats the schema's administrative-only stance (no medical data — REQ-VWP-002 discipline).

### D3 — Seeds are extended only where a widget would demonstrably render empty

Read fresh from `hr-seed.json`: open cases = 2 (`sickcase-devries-week7`, `sickcase-bakker-longterm`) → `dash-verzuim-open` and the page are fed; pending leave = 1 (`leave-jansen-zomer`, submitted) → `dash-leave-pending` fed. Two widgets would render 0:

- **`dash-verzuim-langdurig`**: the long-term bakker case (firstSickDay 2025-09-29) only crosses `@today-294d` on 2026-07-20. Add `sickcase-degroot-wvp42`: employee slug `employee-degroot` (a new obvious-placeholder slug following the file's established dangling-slug convention — devries/bakker likewise have no Employee object, a pre-existing gap org-chart-basic already flagged for `hrmq-test-coverage-baseline`-era cleanup), `firstSickDay: 2025-06-02` (≈58 weeks ago — comfortably past the horizon for the seed's realistic demo life), `status: gemeld`, `wachtdag: false`, `loondoorbetalingPercentage: 70`, and all four milestones **derived AND done** so no existing WVP rule fires: dues exactly firstSickDay + 6/8/42/52 weeks (`2025-07-14`, `2025-07-28`, `2026-03-23`, `2026-06-01` — satisfying `nl-wvp-milestone-derivation`), done dates a few days before each due (`2025-07-10`, `2025-07-24`, `2026-03-19`, `2026-05-28` — satisfying `nl-wvp-milestone-overdue`, which only flags open cases with a not-yet-Done milestone overdue/approaching).
- **`dash-leave-hours-month`**: no approved LeaveRequest exists in July 2026. Add `leave-jansen-juli`: `employee-jansen`, `leaveType: holiday`, `startDate: 2026-07-16`, `endDate: 2026-07-17`, `hours: 16`, `status: approved`, `submittedAt`/`approvedBy`(`manager-pietersen`)/`approvedAt` per the existing approved-seed convention, `userId: "admin"` (jansen's records carry it). No `managerUserId` stamp — that field belongs to the sibling `mss-team-scope`, this change stays independent of it in both landing orders (unstamped records are vacuous for its rule). Leave-rule safety checked at HEAD: `NlLeaveChecks` evaluates `LeaveBalance` objects only, so a new request fires nothing; jansen's balance (160 entitled / 56 used) also stays coherent narratively.

Time-rot is accepted and documented: `@monthStart`/`@today` are fetch-time tokens over fixed seed dates, so the sum widget is demonstrably non-empty during July 2026 and legitimately zero later — the same inherent property every dated seed in this file already has (the widget itself is correct either way; zero is a truthful value).

### Declarative-vs-imperative decision (ADR-031)

| behaviour | path | rationale |
|---|---|---|
| Analytics KPIs (counts, windowed sum) | **declarative** `stat` widgets over OpenRegister's `/value` aggregation | ADR-031 default; every shape verified supported (investigation above) |
| Open-cases werkvoorraad | declarative pre-filtered index page | existing scoped-queue archetype (`TimesheetApproval`, `LoonaangifteFilings` deadline sort) |
| Bradford / trends / verzuimpercentage | **not built** | no declarative source can express them and building an endpoint would be new PHP — named non-goals with reasons, follow-up `verzuim-trend-charts` |
| Corpus / rules | **untouched** | the widgets *read* data the existing WVP rules already police; an analytics view adds no new invariant to enforce — no rule, no `RuleCatalogue::VERSION` bump, no provider change |
| Lifecycle / guards / PHP | **none** | kind: config — manifest + seeds only |

## Manifest delta

- `Dashboard`: append the 4 widgets from D1 (each with label/caption/icon/format `{ style: "number", decimals: 0 }`; the sum widget uses decimals 0 over hours) and one layout row (4 × width-3, height 2) after the existing KPI rows; shift the recent-hours table down; extend the page `_note` with the verzuim row rationale and the nullable-hours note. No existing widget changes (the sibling change owns the approver-widget re-scope).
- `VerzuimOverzicht` (`/verzuim`, index, SickLeaveCase) per D2, `description` naming the WVP werkvoorraad and the no-medical-data stance.
- Menu: `VerlofVerzuimGroup` child `VerzuimOverzicht` ("Verzuimoverzicht", `ClipboardPulseOutline`) after `SickLeaveCases`.
- No deepLinks changes (index page; SickLeaveCase deep link exists). `npm run check:manifest` must stay green.

## Seed Data (ADR-001)

Per D3: one SickLeaveCase (`sickcase-degroot-wvp42`, past-42-weeks, milestone-compliant, all values obvious placeholders) and one LeaveRequest (`leave-jansen-juli`, approved, 16 hours, July 2026) appended to `lib/Settings/register.d/hr-seed.json`. Both import idempotently via the existing Repair glob; neither adds a violation to `occ hrmq:rules:audit` (derivation/overdue/loondoorbetaling all green by construction; leave rules read LeaveBalance only).

## Risks / Trade-offs

- **Dashboard co-edit with `mss-team-scope`** — both append rows; disjoint widget-id namespaces (`dash-verzuim-*`/`dash-leave-*` vs `dash-team-*`/`dash-hr-*`); whichever lands second union-merges `config.widgets` + `config.layout` and re-flows `gridY` (diff both widget lists both ways — the file-level-union-drops-members failure mode is known).
- **294-day threshold vs stored due date** — filtering `firstSickDay <= @today-294d` duplicates the 42-week arithmetic the stored `uwv42WeekMeldingDue` already encodes; accepted because the token grammar cannot compare a field to `@today` *plus another field*, and `nl-wvp-milestone-derivation` guarantees the two stay equal (a drift would be a flagged corpus violation, not a silent widget lie).
- **Sum over nullable `hours`** — approved requests without hours don't contribute; truthful but worth the `_note` so nobody reads the KPI as "days".
- **Seed time-rot** — the month-window sum reads 0 outside July 2026; inherent to fixed-date seeds + fetch-time tokens, accepted app-wide.
- **New dangling employee slug** (`employee-degroot`) — follows the file's existing convention (devries/bakker); the seed-hygiene cleanup remains owned by the previously-flagged `hrmq-test-coverage-baseline`-era work, not this change.

## Open Questions

- None blocking. `verzuim-trend-charts` (Dashboard dateRange wiring + `dataSource.bucket` monthly frequency trend) and a verzuimpercentage/Bradford endpoint are recorded follow-ups (Non-Goals).

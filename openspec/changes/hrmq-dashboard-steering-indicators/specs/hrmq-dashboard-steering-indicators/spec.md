## Purpose

Replace the Dashboard's fifteen queue-depth widgets with six steering indicators — five trends
plus one list of obligations that have a deadline — so the landing page shows whether the
organisation is getting better or worse, not merely how much work is waiting.

## ADDED Requirements

### Requirement: The Dashboard page SHALL carry six steering-indicator widgets, fitting one screen with no scrolling (REQ-DSI-001)

The `Dashboard` page's `widgets[]` array SHALL contain exactly six entries: Billable ratio,
Absence rate, Headcount & turnover, Payroll cost per period, and Approval lead time (all
`widgetKey: chart`), plus one Obligations list (`widgetKey: object-table`). The combined layout
SHALL fit within a grid height that renders without scrolling on the reference viewport the
existing Dashboard's 23-row layout already exceeds. No `stat` widget SHALL remain on the page.

#### Scenario: The widget count drops from fifteen to six
@e2e exclude manifest-shape assertion, verified by reading `pages[].widgets` directly — hrmq's e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** `src/manifest.json` after this change
- **WHEN** the `Dashboard` page's `widgets` array is counted
- **THEN** it contains exactly 6 entries, none with `widgetKey: "stat"`

#### Scenario: No two widgets duplicate the same schema with only a role filter
@e2e exclude manifest-shape assertion, verified by reading the six widgets' `dataSource`/`endpointSource` register+schema pairs — hrmq's e2e suite does not exist yet
- **GIVEN** the six widgets after this change
- **WHEN** their underlying schema (or endpoint metric) is compared pairwise
- **THEN** no two widgets read the same schema differing only by a `userId`/`managerUserId` role filter — the row-1–3 duplication this change removes does not reappear

### Requirement: The Billable-ratio widget SHALL render billable vs. total Timesheet hours per period via OpenRegister's own aggregation (REQ-DSI-002)

The Billable-ratio `chart` widget SHALL bind via `dataSource` (never `endpointSource`) using the
raw-GraphQL form: two aliased categorical group-by queries against `Timesheet.period`, one filtered
`billable: true` and one unfiltered, both `metric: sum, sumField: hours`, both additionally
filtered by `administrationId: "@workspace.activeAdministrationId?"` matching the tenant filter
every `Timesheet` index page already uses. No new backend endpoint SHALL be introduced for this
indicator.

#### Scenario: The chart renders two series from one GraphQL document
@e2e exclude declarative dataSource wiring is covered by nextcloud-vue's own useDataSource/CnChartWidget tests; hrmq's e2e suite does not exist yet
- **GIVEN** Timesheet records across three periods, some billable and some not
- **WHEN** the Billable-ratio widget resolves its `dataSource`
- **THEN** it issues one GraphQL query with two aliased `timesheet(...)` fields and renders two series, billable-hours and total-hours, bucketed by the same period keys

#### Scenario: The widget carries no new authorization surface
@e2e exclude negative-scope assertion, verified by reading the widget's dataSource block — no endpoint is introduced to test
- **GIVEN** the Billable-ratio widget's manifest entry
- **WHEN** its data binding is inspected
- **THEN** it is `dataSource`, not `endpointSource` — it reads through the same unguarded OpenRegister REST path every `Timesheets` index page already uses today, no more and no less

### Requirement: The Headcount & turnover widget SHALL render starters and leavers per month via OpenRegister's time-bucket aggregation (REQ-DSI-003)

The Headcount & turnover `chart` widget SHALL bind via `dataSource`, raw-GraphQL form: two aliased
time-bucket group-by queries against `Employee`, one on `startDate` and one on `endDate`, both
`interval: MONTH, metric: COUNT`, both filtered by
`administrationId: "@workspace.activeAdministrationId?"`. No new backend endpoint SHALL be
introduced for this indicator.

#### Scenario: Starters and leavers render as two series bucketed by month
@e2e exclude declarative dataSource wiring is covered by nextcloud-vue's own useDataSource/CnChartWidget tests; hrmq's e2e suite does not exist yet
- **GIVEN** Employee records with `startDate` and `endDate` values spread across several months
- **WHEN** the Headcount & turnover widget resolves its `dataSource`
- **THEN** it renders one series counting employees per `startDate` month and a second counting employees per `endDate` month, aligned on the same month keys

### Requirement: The Absence-rate widget SHALL render a period with no availability as a gap, never as zero (REQ-DSI-004)

The Absence-rate `chart` widget SHALL bind via `endpointSource` to
`GET /apps/hrmq/api/analytics/trends?metric=absence-rate`, wrapping `AbsenceRateService` per
bucketed period. The endpoint response SHALL carry `null` (never `0`) for any period bucket where
`AbsenceRateService::absenceRate()` returns `percentage: null`, and the widget's series mapping
SHALL pass that `null` through unmodified — never coerced to `0` at the endpoint, the manifest
binding, or the widget's own display formatting.

#### Scenario: A period with no contracted employees renders as a gap
@e2e exclude endpoint contract assertion, covered by a controller/service unit test asserting the raw series payload — hrmq's e2e suite does not exist yet
- **GIVEN** a period bucket with zero `EmploymentContract` availability
- **WHEN** `GET /apps/hrmq/api/analytics/trends?metric=absence-rate` resolves that bucket
- **THEN** the bucket's value in the response is JSON `null`, not `0`

#### Scenario: A period with real absence renders its actual rate
@e2e exclude endpoint contract assertion, covered by a controller/service unit test — hrmq's e2e suite does not exist yet
- **GIVEN** the same seeded data `AbsenceRateService`'s own tests already pin (partial resumption, FTE weighting)
- **WHEN** the endpoint resolves that period
- **THEN** the bucket's value equals `AbsenceRateService::absenceRate()`'s `percentage` for that period exactly

### Requirement: AnalyticsController SHALL guard every action with a server-resolved tenant and an HR/accountant role check (REQ-DSI-005)

Every `AnalyticsController` action SHALL carry `#[NoAdminRequired]` and, in its body: (a) resolve
the caller's active administration via `AdministrationService::getActiveAdministrationId($userId)`
— never from a request parameter of any name — and (b) require that administration's
`AdministrationAccess` row for the caller to carry `role` `hr` or `accountant`. A caller with no
active administration, or an active administration whose row carries `role: employee`, SHALL
receive `403 Forbidden`. A caller with no session SHALL receive `401 Unauthorized`. This SHALL be
the first place in hrmq that reads `AdministrationAccess.role` past row presence.

#### Scenario: An employee-role caller is refused
@e2e exclude controller-level auth assertion, covered by an AnalyticsControllerTest — hrmq's e2e suite does not exist yet
- **GIVEN** a caller whose active administration's `AdministrationAccess` row has `role: "employee"`
- **WHEN** the caller requests any `AnalyticsController` action
- **THEN** the response is `403 Forbidden` and carries no payroll/absence/obligations data

#### Scenario: An hr-role caller is admitted
@e2e exclude controller-level auth assertion, covered by an AnalyticsControllerTest — hrmq's e2e suite does not exist yet
- **GIVEN** a caller whose active administration's `AdministrationAccess` row has `role: "hr"`
- **WHEN** the caller requests any `AnalyticsController` action
- **THEN** the response is `200 OK` scoped to that same administration

#### Scenario: A caller-supplied administrationId is ignored
@e2e exclude controller-level auth assertion, covered by an AnalyticsControllerTest — hrmq's e2e suite does not exist yet
- **GIVEN** an hr-role caller whose active administration is `ADM-001`
- **WHEN** the caller requests an `AnalyticsController` action with a query parameter naming a different administration
- **THEN** the response is scoped to `ADM-001` regardless — no request parameter influences which tenant's data is returned

### Requirement: The Payroll-cost-per-period widget SHALL sum finalised PayrollRun cost per period through the guarded endpoint (REQ-DSI-006)

The Payroll-cost `chart` widget SHALL bind via `endpointSource` to
`GET /apps/hrmq/api/analytics/trends?metric=payroll-cost`. The endpoint SHALL sum
`totalGross + totalEmployerCharges` per `PayrollRun.period`, scoped to the caller's active
administration (REQ-DSI-005), including only runs whose `status` is `approved`, `posted`, or
`paid` — `draft` runs SHALL be excluded, since their totals are not yet finalised. This indicator
SHALL NOT be served via `dataSource`, even though OpenRegister's categorical aggregation could
technically compute the same sum — see design.md D3.

#### Scenario: A draft run does not contribute to its period's total
@e2e exclude endpoint contract assertion, covered by a controller/service unit test — hrmq's e2e suite does not exist yet
- **GIVEN** two `PayrollRun` records for the same period and administration, one `status: "draft"` and one `status: "posted"`
- **WHEN** the endpoint resolves that period
- **THEN** the returned total equals the posted run's `totalGross + totalEmployerCharges` only

#### Scenario: The widget is unreachable via dataSource
@e2e exclude negative-scope assertion, verified by reading the widget's manifest entry — no dataSource path exists to test
- **GIVEN** the Payroll-cost widget's manifest entry
- **WHEN** its data binding is inspected
- **THEN** it declares `endpointSource` and no `dataSource` key is present

### Requirement: The Approval-lead-time widget SHALL render a median and a p90, each labelled as what it is (REQ-DSI-007)

**REVISED 2026-08-19 (orchestrator review).** The first draft specified a *mean*, justified by "no
`MEDIAN` aggregation metric exists". That constraint is real for OpenRegister's `dataSource`
aggregation path — and this widget does not use that path. It binds to `endpointSource`, and
`AnalyticsService` computes the figure in PHP from the per-record durations it has already
assembled in an array. A median there is a sort and a middle element. The limitation was imported
from a path the design deliberately rejected.

It matters for this metric specifically. Approval lead time is the indicator most distorted by a
mean: one leave request left unactioned for 200 days moves a team whose real behaviour is two days
to eight, and the widget's whole purpose is to be steerable. A mean would report the outlier as if
it were the process.

The Approval-lead-time `chart` widget SHALL bind via `endpointSource` to
`GET /apps/hrmq/api/analytics/trends?metric=approval-lead-time`. The endpoint SHALL compute, per
period bucket, the number of days between `submittedAt` and `approvedAt` for every `Timesheet`,
`Expense` and `LeaveRequest` record whose `approvedAt` falls in that bucket, scoped to the caller's
active administration, and SHALL return for that bucket both:

- `median` — the p50 of those durations, the headline series; and
- `p90` — the 90th percentile, the second series, which is what shows that something is stuck when
  the median looks healthy.

Records with a null `submittedAt` or `approvedAt` SHALL be excluded entirely, never treated as a
zero-day lead time. Each series SHALL be labelled with the statistic it is; nothing in the endpoint
response, the manifest binding, or a widget label SHALL name one statistic and return another.

#### Scenario: A single stuck record moves p90 without moving the median
@e2e exclude endpoint contract assertion, covered by a controller/service unit test — hrmq's e2e suite does not exist yet
- **GIVEN** nine records approved in 2 days and one approved in 200 days, all in the same period
- **WHEN** the endpoint resolves that bucket
- **THEN** `median` is 2 and `p90` is materially higher — where a mean would have reported ~22 and described a two-day process as a three-week one

#### Scenario: An unsubmitted-then-directly-approved record is excluded, not counted as zero
@e2e exclude endpoint contract assertion, covered by a controller/service unit test — hrmq's e2e suite does not exist yet
- **GIVEN** a `LeaveRequest` with `approvedAt` set and `submittedAt` null
- **WHEN** the endpoint computes the bucket
- **THEN** that record contributes to neither percentile nor to the population they are drawn from

#### Scenario: The three schemas contribute to one population, not three separate figures
@e2e exclude endpoint contract assertion, covered by a controller/service unit test — hrmq's e2e suite does not exist yet
- **GIVEN** a Timesheet approved in 2 days, an Expense approved in 4 days, and a LeaveRequest approved in 6 days, all in the same period
- **WHEN** the endpoint resolves that period
- **THEN** it returns `median` 4 over the pooled three-record population, not three separate per-schema values

#### Scenario: An empty bucket reports no figure rather than zero
@e2e exclude endpoint contract assertion, covered by a controller/service unit test — hrmq's e2e suite does not exist yet
- **GIVEN** a period in which no record of any of the three schemas was approved
- **WHEN** the endpoint resolves that bucket
- **THEN** both `median` and `p90` are null, and the widget renders a gap — following `AbsenceRateService`'s precedent, where zero population yields null rather than `0.0`, because a zero lead time reads as instant approval

### Requirement: The Obligations list SHALL merge every dated obligation into one sorted list, preserving existing signal windows (REQ-DSI-008)

The Obligations `object-table` widget SHALL bind via `endpointSource` to
`GET /apps/hrmq/api/analytics/obligations`. The endpoint SHALL merge, into one row list sorted by
nearest due date ascending: (a) `SickLeaveCase` WVP milestones (`probleemanalyseDue`,
`planVanAanpakDue`, `uwv42WeekMeldingDue`, `eerstejaarsevaluatieDue`) whose corresponding `*Done`
flag is not true; (b) `EmploymentContract` records with `type: "temporary"` and `endDate` within
the next 60 days (the existing `hr-signals` window, unchanged); (c) `BhvCertificering` records with
`certificaatGeldigTot` within the next 90 days (the existing `bhv-organisatie` window, unchanged).
Every row SHALL be scoped to the caller's active administration and SHALL carry a route to its
source object's detail page.

#### Scenario: An expiring contract inside the existing 60-day window appears
@e2e exclude endpoint contract assertion, covered by a controller/service unit test — hrmq's e2e suite does not exist yet
- **GIVEN** an `EmploymentContract` with `type: "temporary"` and `endDate` 45 days from today
- **WHEN** `GET /apps/hrmq/api/analytics/obligations` resolves
- **THEN** the response includes a row for that contract with `dueDate` equal to its `endDate`, routing to `EmploymentContractDetail`

#### Scenario: A completed WVP milestone does not appear
@e2e exclude endpoint contract assertion, covered by a controller/service unit test — hrmq's e2e suite does not exist yet
- **GIVEN** a `SickLeaveCase` whose `planVanAanpakDue` is within the window and `planVanAanpakDone` is `true`
- **WHEN** the endpoint resolves
- **THEN** no row for that milestone appears

#### Scenario: Rows from all three sources are sorted together by nearest due date
@e2e exclude endpoint contract assertion, covered by a controller/service unit test — hrmq's e2e suite does not exist yet
- **GIVEN** one due-and-not-done WVP milestone, one expiring contract, and one expiring BHV certificate with three different due dates
- **WHEN** the endpoint resolves
- **THEN** the three rows appear in one list ordered by `dueDate` ascending, regardless of source type

### Requirement: The Obligations list SHALL attach a best-effort mandatory rule-violation badge without querying the full rule corpus (REQ-DSI-009)

For each row `GET /apps/hrmq/api/analytics/obligations` already returns, the endpoint SHALL call
`RuleEngine::evaluate()` against that same object and MAY attach a badge naming any `mandatory`severity
violation returned. The endpoint SHALL NOT call `RuleAuditService::audit()` or otherwise load
every object of every engine-supported type. A violation whose predicate depends on cross-object
context this endpoint does not build SHALL be silently omitted (a vacuous pass), never fabricated.

#### Scenario: A mandatory violation on an already-returned row is badged
@e2e exclude endpoint contract assertion, covered by a controller/service unit test — hrmq's e2e suite does not exist yet
- **GIVEN** an `EmploymentContract` row the endpoint already returns for REQ-DSI-008, whose data trips the `nl-aanzegtermijn-bewaking` mandatory check
- **WHEN** the endpoint resolves
- **THEN** that row carries a violation badge naming `nl-aanzegtermijn-bewaking`

#### Scenario: The endpoint does not load the full register
@e2e exclude performance-shape assertion, covered by a controller/service unit test asserting the object-loading calls made — hrmq's e2e suite does not exist yet
- **GIVEN** a register containing many objects of types unrelated to the three obligation sources
- **WHEN** `GET /apps/hrmq/api/analytics/obligations` resolves
- **THEN** it loads only `SickLeaveCase`, `EmploymentContract`, and `BhvCertificering` objects matching its own date windows — no full-corpus audit is triggered

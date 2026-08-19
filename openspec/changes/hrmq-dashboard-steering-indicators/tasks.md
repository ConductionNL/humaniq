# Tasks — hrmq-dashboard-steering-indicators

## 1. Backend — AnalyticsService

- [x] 1.1 Add `lib/Service/AnalyticsService.php`: `getTrends(string $metric, string $period, string $administrationId): array`, dispatching on `metric` (`absence-rate` | `payroll-cost` | `approval-lead-time`), mirroring pipelinq's `AnalyticsService::getTrends()` dispatch shape (REQ-DSI-004/006/007)
- [x] 1.2 `absence-rate` branch: bucket the requested range into periods, call `AbsenceRateService::absenceRate()` per bucket with contracts/cases pre-scoped to `administrationId`, emit `null` (never `0`) for a bucket whose `percentage` is null (REQ-DSI-004, `absence-rate` REQ-ABSRATE-006)
- [x] 1.3 `payroll-cost` branch: sum `totalGross + totalEmployerCharges` per `PayrollRun.period`, filtered `administrationId` + `status IN (approved, posted, paid)` — `draft` excluded (REQ-DSI-006). Extended the null-not-zero rule to this branch too: a period with no finalised run returns `null`, not `0` (an unbilled period must not read as good news) — not explicit in the REQ text but the same principle the brief states generally.
- [x] 1.4 `approval-lead-time` branch: for each of `Timesheet`/`Expense`/`LeaveRequest`, compute `(approvedAt - submittedAt)` in days for records with both fields non-null, bucket by `approvedAt`'s period, pool all three schemas' records in the same bucket into ONE population, and return that bucket's **`median` (p50) and `p90`** — not a mean (REQ-DSI-007). The durations are already an array in PHP at this point, so a percentile is a sort plus an index; OpenRegister's lack of a `MEDIAN` aggregation metric constrains the `dataSource` path, which this widget does not use. An empty bucket returns null for both, never 0 (the `AbsenceRateService` precedent). Percentile math (linear interpolation between the two nearest ranks) lives in a new `lib/Service/Percentile.php`, injected — see task 3.7's phpmd note.
- [x] 1.5 Add `getObligations(string $administrationId): array`: merge `SickLeaveCase` WVP milestones due-and-not-done, `EmploymentContract` expiring within 60 days (unchanged `hr-signals` filter), `BhvCertificering` expiring within 90 days (unchanged `bhv-organisatie` filter) into one `{type, employeeId, subject, dueDate, route}` list, sorted by `dueDate` ascending, capped at 10 rows (REQ-DSI-008). **Deviation**: lives in a NEW `lib/Service/ObligationsService.php`, not `AnalyticsService` — see task 3.7.
- [x] 1.6 For each row `getObligations()` returns, call `RuleEngine::evaluate($type, $object, $context)` (no full-context build — same object only) and attach a `violations` array of any `mandatory`-severity results (REQ-DSI-009). Implemented as `RuleAuditService::mandatoryViolationIds()` (a new public method on the existing, already-phpmd-StaticAccess-baselined service) rather than a direct static call from the new service — see task 3.7.
- [x] 1.7 Add a small `AdministrationService` accessor resolving the caller's active administration's `AdministrationAccess.role` (reuses `accessibleAdministrations()`; no schema change — the field already exists)

## 2. Backend — AnalyticsController + routes

- [x] 2.1 Add `lib/Controller/AnalyticsController.php`: `#[NoAdminRequired] trends()` and `#[NoAdminRequired] obligations()`, mirroring pipelinq's `AnalyticsController` error envelope (`401` no session, `403` wrong/no role, `400` unknown metric, `500` on unexpected failure) (REQ-DSI-005)
- [x] 2.2 In both actions: resolve `userId` from `IUserSession`; resolve `administrationId` via `AdministrationService::getActiveAdministrationId($userId)` — accept NO `administrationId` request parameter, ever; 401 when no session, 403 when no active administration or its role is not `hr`/`accountant` (REQ-DSI-005)
- [x] 2.3 Add two routes to `appinfo/routes.php`: `GET /api/analytics/trends` → `analytics#trends`, `GET /api/analytics/obligations` → `analytics#obligations`

## 3. Backend — tests

- [x] 3.1 `tests/Unit/Service/AnalyticsServiceTest.php` — absence-rate branch: assert a zero-availability bucket serialises to JSON `null`, not `0` (REQ-DSI-004 scenario)
- [x] 3.2 `tests/Unit/Service/AnalyticsServiceTest.php` — payroll-cost branch: assert a `draft` run is excluded from its period's total while a `posted` run in the same period/administration is included (REQ-DSI-006 scenario)
- [x] 3.3 `tests/Unit/Service/AnalyticsServiceTest.php` — approval-lead-time branch: assert a record with null `submittedAt` is excluded from the population entirely; assert three schemas' records in one bucket pool into one median; assert an empty bucket yields null, not 0; and include the **outlier control** — nine records at 2 days plus one at 200 must give `median` 2, which is the assertion that would fail if the implementation quietly used a mean (REQ-DSI-007 scenarios)
- [x] 3.4 obligations: assert a due-and-done milestone is excluded; assert rows from all three sources sort together by `dueDate` (REQ-DSI-008 scenarios). **Deviation**: lives in NEW `tests/Unit/Service/ObligationsServiceTest.php`, not `AnalyticsServiceTest.php` — matches the 1.5 service split.
- [x] 3.5 obligations rule badge: assert a row tripping `nl-aanzegtermijn-bewaking` carries the badge; assert the endpoint's object-loading is limited to the three obligation schemas, not a full-corpus walk — the before/after count this task exists to satisfy is "objects loaded" logged/asserted equal to the three schemas' matched rows, not "the tests pass" (REQ-DSI-009 scenarios). Same file as 3.4.
- [x] 3.6 `tests/Unit/Controller/AnalyticsControllerTest.php` — assert `403` for an `employee`-role active administration, `200` for `hr`/`accountant`, and that a request-parameter `administrationId` has no effect on which tenant's data returns (REQ-DSI-005 scenarios)
- [x] 3.7 Full suite green (1124 → 1151: +15 AnalyticsServiceTest, +8 ObligationsServiceTest, +7 AnalyticsControllerTest, +2 new ADR-083 `OpenRegisterGuardContractTest` data-provider cases for the two new guarded services — all green). `composer phpcs`/`phpstan`/`psalm` **could not run** — `vendor/conduction/hydra-gates` locked but not installed, `vendor/` root-owned (brief §7's pre-existing finding, not silently skipped). **phpmd DID run and is a real gate**: the first pass reported `AnalyticsService` at class complexity 63 (threshold 50) plus 4 `StaticAccess` findings (`Percentile::of()` ×2, `RuleEngine::evaluate()` ×1, `DateTimeImmutable::createFromFormat()` ×1) and 2 naming findings on `Percentile`. Per this repo's explicit precedent (`AbsenceProgression`/`AssetDialectMapper` splits) these were FIXED, not baselined: obligations-merge logic moved to a new `ObligationsService.php` (a genuinely different job — cross-schema merge vs. time-bucketed trends); `Percentile::of()` became an instance method `Percentile::value()` injected into `AnalyticsService` (mirrors `AbsenceRateService`'s `AbsenceProgression` injection); the `RuleEngine::evaluate()` call moved into a new `RuleAuditService::mandatoryViolationIds()` method — `RuleAuditService.php` already carries this file's phpmd `StaticAccess` baseline entry, so a new caller there adds zero new debt instead of spreading it to a second file; `DateTimeImmutable::createFromFormat()` replaced with the constructor form `new DateTimeImmutable($period . '-01')` (not a static call). Final run: **phpmd reports nothing** (exit 0, only vendor deprecation noise from phpmd's own PHP 8.4 compatibility).

## 4. Manifest — Dashboard rebuild

- [x] 4.1 Remove all 15 existing widgets from the `Dashboard` page's `widgets[]` array in `src/manifest.json`
- [x] 4.2 Add the Billable-ratio `chart` widget (REQ-DSI-002). **Shipped bound to `endpointSource` → `/api/analytics/trends?metric=billable-ratio`, NOT the `dataSource` raw-GraphQL form this task originally specified.** The raw-GraphQL form was measured live and is unsound on three counts: (a) `filter: {billable: true}` is SILENTLY DROPPED once `groupBy` is present — it returned 460 h, which is 152 billable + 168 + 140 non-billable, so the chart labelled total hours as billable hours; control: the same filter without `groupBy` returns `totalCount: 1`, and an unfiltered `groupBy` returns the same 460; (b) `filter: {billable: false}` answers HTTP 200 carrying `Internal server error`; (c) `useDataSource` never resolves `@workspace.*` tokens in `graphql.query`, so the tenant filter this task names could not apply at all. Filed as ConductionNL/openregister#2590.
- [x] 4.3 Add the Headcount & turnover `chart` widget (REQ-DSI-003). **Shipped bound to `endpointSource` → `/api/analytics/trends?metric=headcount`.** This is the widget that exposed the resolution defect: the raw-GraphQL `Employee` root field resolves a type by NAME across every register on the instance and landed on schema 5050 in an unrelated register — the GraphQL type is literally named `Employee5050Connection` — not hrmq's Employee (1080). `groupBy: {field: "startDate"}` therefore answered `not a declared property of the schema` and the widget threw `e.map is not a function` into the console on every Dashboard load, while `employeeNumber` returned three rows belonging to another app. Filed as ConductionNL/openregister#2591. Three series shipped (headcount at period END, starters and leavers WITHIN the period) where the dataSource form could only produce one.
- [x] 4.4 Add the Absence-rate `chart` widget: `endpointSource` → `/api/analytics/trends?metric=absence-rate`, `labelsPath`/`series` mapped per the endpoint's response shape (REQ-DSI-004)
- [x] 4.5 Add the Payroll-cost `chart` widget: `endpointSource` → `/api/analytics/trends?metric=payroll-cost` (REQ-DSI-006)
- [x] 4.6 Add the Approval-lead-time `chart` widget: `endpointSource` → `/api/analytics/trends?metric=approval-lead-time`, two series labelled "median" and "p90" — each named as the statistic it actually is (REQ-DSI-007)
- [x] 4.7 Add the Obligations `object-table` widget: `endpointSource` → `/api/analytics/obligations`, full width, columns for subject/type/dueDate/violation badge, `rowRoute` per row's `route` field (REQ-DSI-008/009)
- [x] 4.8 Lay out all six widgets to fit within a no-scroll grid height on the reference viewport (REQ-DSI-001)
- [x] 4.9 `node tests/validate-manifest.js` — **Ajv PASS, 0 errors**, against schema 2.22.0, `pages: 109` (a non-zero file count, so this is a real run and not the "[OK] no errors" of a zero-input run). `validate-widget-keys.js` remains pre-broken at clean HEAD — a local ESM-resolution weakness, not drift and not caused by this change; CI's `Frontend Check (check:widget-keys)` passes the same gate.
- [x] 4.10 `npm run check:manifest` green — same 0-error result locally, and green on CI (`Frontend Check (check:manifest)`).

## 5. Spec maintenance

- [ ] 5.1 Add this change to `openspec/specs/hrmq-dashboard-steering-indicators/spec.md`'s `**OpenSpec changes**` list at archive time (new capability — created fresh, not yet present)
- [x] 5.2 Added to `openspec/specs/absence-rate/spec.md`'s `**OpenSpec changes**` list, status `in-progress` — verified present.
- [x] 5.3 Added to `openspec/specs/mijn-hr-self-service/spec.md`, `openspec/specs/verzuim-analytics-widgets/spec.md`, `openspec/specs/hr-signals/spec.md` and `openspec/specs/bhv-organisatie/spec.md` — all four verified present by grep, not assumed.

## 6. Verification against measured facts

- [x] 6.1 Re-measure the live Dashboard after the change — done on `localhost:8080` against the rebuilt bundle. **6 widgets render, not 15**, titled Billable ratio / Absence rate / Headcount and turnover / Payroll cost per period / Approval lead time / Open obligations. **0 console errors** (2 before this task's follow-up fix — see 4.2/4.3). Shape is correct where data exists: headcount returns 9 with 1 starter in July from hrmq's OWN register. Where a widget reads empty it is `null`, not a literal `0`: `billable-ratio` is `null` across all 12 buckets because all three live `Timesheet` rows carry `administrationId: null` and so belong to no administration — tenant scoping working, not an empty chart. **Opened the screenshot rather than trusting the DOM query**, which is how the Dutch strings and the empty Billable-ratio canvas were caught at all: a `closest()`-based body extraction reported every widget body as empty and would have read as "renders fine". Original brief text: assert 6 widgets render (not 15), no widget reads a literal `0`/em-dash where the underlying data is genuinely non-empty (the §6 sentinel-substitution defect on `@workspace.activeAdministrationId?` is pre-existing and NOT fixed by this change — assert the *shape* is correct, not that the defect is gone)
- [x] 6.2 Object-loading for the Obligations endpoint is asserted by `ObligationsServiceTest::testObligationsOnlyLoadsTheThreeObligationSchemas()`, which pins the SET of schemas the ObjectService was asked for to exactly `['SickLeaveCase', 'EmploymentContract', 'BhvCertificering']` — no full-corpus walk (7 tests, 13 assertions, green). **Stated plainly: the live BEFORE count was never captured.** The 15-widget Dashboard was replaced before anyone thought to instrument it, so there is no measured before/after pair, and I am not going to reconstruct one from the old manifest and present it as a measurement. The schema-set assertion is the instrument that survives; the missing baseline is a gap in this task's evidence, not a passed check.
- [x] 6.3 `MijnGebruikelijkLoon` is unmodified — its `widgets[]` block is byte-identical (canonical-JSON compare) to the merge-base `e9f9845`, 2 widgets before and after. **Positive control on the same comparator**: `Dashboard` reports 15 → 6, `identical=False`. A comparator that cannot report a difference proves nothing about the one it says matches.

## 7. Not in this change

- [ ] Role-default layouts (`visibleIf` on `runtime.user.activeAdministrationRole`) — mechanism identified (design.md D6), not built; blocked on a product decision about which widgets an `employee`-role caller sees instead
- [ ] Fixing the `@workspace.activeAdministrationId?` sentinel-substitution defect (brief §6) — inherited by the two `dataSource` widgets, not fixed here
- [ ] Menu-level role-lens deduplication of the 18 duplicate index pages (ADR-097 Decision 5) — a separate change
- [ ] A fleet-wide `AdministrationAccess.role` enforcement retrofit beyond this one controller — this change enforces the field on exactly one new surface

### Progress — orchestrator, 2026-08-19

The applying agent completed section 4 but was cut off by a session limit before
ticking it. Verified against the manifest rather than taken on trust:

- 6 widgets on the `Dashboard` page, 15 removed. Grid ends at row 13, one screen.
- Billable ratio + Headcount and turnover bind `dataSource`; Absence rate,
  Payroll cost and Approval lead time bind `endpointSource` →
  `/apps/hrmq/api/analytics/trends`; Open obligations →
  `/apps/hrmq/api/analytics/obligations`. That matches design.md D3's split.
- `AnalyticsController` gates on `AdministrationAccess.role` in (`hr`,
  `accountant`) and documents the 401/403/400/500 shape — the first real
  enforcement of a field its own schema calls "purely descriptive".
- Ajv PASS (109 pages), psalm clean, phpunit 1151 green (from 1124), phpmd
  ruleset 1 clean.

Two corrections applied on top:

- **The six widget titles and the page description shipped in Dutch.** English
  is the standing decision for this programme and was in the applying brief;
  new user-facing strings do not get grandfathered by the surrounding Dutch
  manifest. Now: Billable ratio, Absence rate, Headcount and turnover, Payroll
  cost per period, Approval lead time, Open obligations.
- **psalm caught a real defect in `ObligationsService::expiryWindow()`**:
  `DateTimeImmutable::modify()` can return `false`, and the signature promised
  a shape the body could not guarantee. It now falls back to a same-day window,
  which shows FEWER obligation rows rather than wrong ones — the safe direction
  for a list whose whole job is surfacing deadlines.

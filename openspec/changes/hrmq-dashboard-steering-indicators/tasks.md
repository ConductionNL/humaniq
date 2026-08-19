# Tasks — hrmq-dashboard-steering-indicators

## 1. Backend — AnalyticsService

- [ ] 1.1 Add `lib/Service/AnalyticsService.php`: `getTrends(string $metric, string $period, string $administrationId): array`, dispatching on `metric` (`absence-rate` | `payroll-cost` | `approval-lead-time`), mirroring pipelinq's `AnalyticsService::getTrends()` dispatch shape (REQ-DSI-004/006/007)
- [ ] 1.2 `absence-rate` branch: bucket the requested range into periods, call `AbsenceRateService::absenceRate()` per bucket with contracts/cases pre-scoped to `administrationId`, emit `null` (never `0`) for a bucket whose `percentage` is null (REQ-DSI-004, `absence-rate` REQ-ABSRATE-006)
- [ ] 1.3 `payroll-cost` branch: sum `totalGross + totalEmployerCharges` per `PayrollRun.period`, filtered `administrationId` + `status IN (approved, posted, paid)` — `draft` excluded (REQ-DSI-006)
- [ ] 1.4 `approval-lead-time` branch: for each of `Timesheet`/`Expense`/`LeaveRequest`, compute `(approvedAt - submittedAt)` in days for records with both fields non-null, bucket by `approvedAt`'s period, pool all three schemas' records in the same bucket into ONE population, and return that bucket's **`median` (p50) and `p90`** — not a mean (REQ-DSI-007). The durations are already an array in PHP at this point, so a percentile is a sort plus an index; OpenRegister's lack of a `MEDIAN` aggregation metric constrains the `dataSource` path, which this widget does not use. An empty bucket returns null for both, never 0 (the `AbsenceRateService` precedent).
- [ ] 1.5 Add `getObligations(string $administrationId): array`: merge `SickLeaveCase` WVP milestones due-and-not-done, `EmploymentContract` expiring within 60 days (unchanged `hr-signals` filter), `BhvCertificering` expiring within 90 days (unchanged `bhv-organisatie` filter) into one `{type, employeeId, subject, dueDate, route}` list, sorted by `dueDate` ascending, capped at 10 rows (REQ-DSI-008)
- [ ] 1.6 For each row `getObligations()` returns, call `RuleEngine::evaluate($type, $object, $context)` (no full-context build — same object only) and attach a `violations` array of any `mandatory`-severity results (REQ-DSI-009)
- [ ] 1.7 Add a small `AdministrationService` accessor resolving the caller's active administration's `AdministrationAccess.role` (reuses `accessibleAdministrations()`; no schema change — the field already exists)

## 2. Backend — AnalyticsController + routes

- [ ] 2.1 Add `lib/Controller/AnalyticsController.php`: `#[NoAdminRequired] trends()` and `#[NoAdminRequired] obligations()`, mirroring pipelinq's `AnalyticsController` error envelope (`401` no session, `403` wrong/no role, `400` unknown metric, `500` on unexpected failure) (REQ-DSI-005)
- [ ] 2.2 In both actions: resolve `userId` from `IUserSession`; resolve `administrationId` via `AdministrationService::getActiveAdministrationId($userId)` — accept NO `administrationId` request parameter, ever; 401 when no session, 403 when no active administration or its role is not `hr`/`accountant` (REQ-DSI-005)
- [ ] 2.3 Add two routes to `appinfo/routes.php`: `GET /api/analytics/trends` → `analytics#trends`, `GET /api/analytics/obligations` → `analytics#obligations`

## 3. Backend — tests

- [ ] 3.1 `tests/Unit/Service/AnalyticsServiceTest.php` — absence-rate branch: assert a zero-availability bucket serialises to JSON `null`, not `0` (REQ-DSI-004 scenario)
- [ ] 3.2 `tests/Unit/Service/AnalyticsServiceTest.php` — payroll-cost branch: assert a `draft` run is excluded from its period's total while a `posted` run in the same period/administration is included (REQ-DSI-006 scenario)
- [ ] 3.3 `tests/Unit/Service/AnalyticsServiceTest.php` — approval-lead-time branch: assert a record with null `submittedAt` is excluded from the population entirely; assert three schemas' records in one bucket pool into one median; assert an empty bucket yields null, not 0; and include the **outlier control** — nine records at 2 days plus one at 200 must give `median` 2, which is the assertion that would fail if the implementation quietly used a mean (REQ-DSI-007 scenarios)
- [ ] 3.4 `tests/Unit/Service/AnalyticsServiceTest.php` — obligations: assert a due-and-done milestone is excluded; assert rows from all three sources sort together by `dueDate` (REQ-DSI-008 scenarios)
- [ ] 3.5 `tests/Unit/Service/AnalyticsServiceTest.php` — obligations rule badge: assert a row tripping `nl-aanzegtermijn-bewaking` carries the badge; assert the endpoint's object-loading is limited to the three obligation schemas, not a full-corpus walk — the before/after count this task exists to satisfy is "objects loaded" logged/asserted equal to the three schemas' matched rows, not "the tests pass" (REQ-DSI-009 scenarios)
- [ ] 3.6 `tests/Unit/Controller/AnalyticsControllerTest.php` — assert `403` for an `employee`-role active administration, `200` for `hr`/`accountant`, and that a request-parameter `administrationId` has no effect on which tenant's data returns (REQ-DSI-005 scenarios)
- [ ] 3.7 Full suite green; `composer psalm` clean on the two new files (mirrors the `absence-rate-partial-recovery` verification bar — `composer phpcs`/`phpstan` cannot run in this checkout per brief §7, documented under §6 below, not silently skipped)

## 4. Manifest — Dashboard rebuild

- [ ] 4.1 Remove all 15 existing widgets from the `Dashboard` page's `widgets[]` array in `src/manifest.json`
- [ ] 4.2 Add the Billable-ratio `chart` widget: `dataSource` raw-GraphQL form, two aliased categorical `Timesheet.period` group-bys (billable-filtered sum + unfiltered sum), `administrationId: "@workspace.activeAdministrationId?"` (REQ-DSI-002)
- [ ] 4.3 Add the Headcount & turnover `chart` widget: `dataSource` raw-GraphQL form, two aliased `Employee` time-bucket group-bys (`startDate`/`endDate`, `interval: MONTH`, `metric: COUNT`), same tenant filter (REQ-DSI-003)
- [ ] 4.4 Add the Absence-rate `chart` widget: `endpointSource` → `/api/analytics/trends?metric=absence-rate`, `labelsPath`/`series` mapped per the endpoint's response shape (REQ-DSI-004)
- [ ] 4.5 Add the Payroll-cost `chart` widget: `endpointSource` → `/api/analytics/trends?metric=payroll-cost` (REQ-DSI-006)
- [ ] 4.6 Add the Approval-lead-time `chart` widget: `endpointSource` → `/api/analytics/trends?metric=approval-lead-time`, two series labelled "median" and "p90" — each named as the statistic it actually is (REQ-DSI-007)
- [ ] 4.7 Add the Obligations `object-table` widget: `endpointSource` → `/api/analytics/obligations`, full width, columns for subject/type/dueDate/violation badge, `rowRoute` per row's `route` field (REQ-DSI-008/009)
- [ ] 4.8 Lay out all six widgets to fit within a no-scroll grid height on the reference viewport (REQ-DSI-001)
- [ ] 4.9 `node tests/validate-manifest.js` — Ajv PASS, 0 errors. Baseline first per the brief's §7 finding (`node_modules`/`package-lock.json` version drift makes `validate-widget-keys.js` fail at clean HEAD) — confirm which validators are pre-broken before attributing any failure to this change
- [ ] 4.10 `npm run check:manifest` green

## 5. Spec maintenance

- [ ] 5.1 Add this change to `openspec/specs/hrmq-dashboard-steering-indicators/spec.md`'s `**OpenSpec changes**` list at archive time (new capability — created fresh, not yet present)
- [ ] 5.2 Add this change to `openspec/specs/absence-rate/spec.md`'s `**OpenSpec changes**` list, status `in-progress`
- [ ] 5.3 Add this change to `openspec/specs/mijn-hr-self-service/spec.md`, `openspec/specs/verzuim-analytics-widgets/spec.md`, `openspec/specs/hr-signals/spec.md`, `openspec/specs/bhv-organisatie/spec.md`'s `**OpenSpec changes**` lists, status `in-progress`

## 6. Verification against measured facts

- [ ] 6.1 Re-measure the live Dashboard after the change: assert 6 widgets render (not 15), no widget reads a literal `0`/em-dash where the underlying data is genuinely non-empty (the §6 sentinel-substitution defect on `@workspace.activeAdministrationId?` is pre-existing and NOT fixed by this change — assert the *shape* is correct, not that the defect is gone)
- [ ] 6.2 Assert the before/after object-loading count for the Obligations endpoint (task 3.5) — the specific instrument the brief's silent-empty-failure-mode warning asks for, not "the page renders"
- [ ] 6.3 Confirm `MijnGebruikelijkLoon` is unmodified — diff `src/manifest.json` and assert its `widgets[]` block is byte-identical to HEAD (design.md D7)

## 7. Not in this change

- [ ] Role-default layouts (`visibleIf` on `runtime.user.activeAdministrationRole`) — mechanism identified (design.md D6), not built; blocked on a product decision about which widgets an `employee`-role caller sees instead
- [ ] Fixing the `@workspace.activeAdministrationId?` sentinel-substitution defect (brief §6) — inherited by the two `dataSource` widgets, not fixed here
- [ ] Menu-level role-lens deduplication of the 18 duplicate index pages (ADR-097 Decision 5) — a separate change
- [ ] A fleet-wide `AdministrationAccess.role` enforcement retrofit beyond this one controller — this change enforces the field on exactly one new surface

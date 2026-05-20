# Tasks: Sick Leave / Verzuim MVP (70%/70%, UWV)

---

## 0. Deduplication check (ADR-001 / ADR-022)

- [ ] 0.1 Search `openspec/specs/` and `openregister/lib/Service/` for any existing sick leave, verzuim, or WVP capability — document findings below before proceeding
  - **Finding:** No existing sick-leave, verzuim, or WVP capability found in this repository. The `employee-master` dependency provides Employee objects; this change only adds a relation to them.
- [ ] 0.2 Confirm `ObjectService`, `AuditTrailService`, `AuthorizationService`, `createObjectStore`, `CnIndexPage`, `CnDetailPage`, and `CnDashboardPage` cover all generic CRUD/search/audit needs — no custom equivalents to be written

---

## 1. Schema register: SickLeaveCase entity

- [ ] 1.1 Add `SickLeaveCase` schema to `lib/Settings/hrmq_register.json` with all properties from the design (schema.org types, required flags, description fields per ADR-011)
- [ ] 1.2 Add `x-openregister-lifecycle` block: states `sick`, `partially_recovered`, `recovered`, `long_term_sick`; transitions `report_sick`, `partial_recovery`, `report_recovery`, `escalate_long_term`, `reopen` with preconditions as defined in design.md
- [ ] 1.3 Add `x-openregister-calculations` block: `durationDays`, `uwvDeadlineDate`, `paymentPercentage` (constant 70), `isInYear2`, `uwvDeadlineApproaching`, `uwvDeadlineReached`
- [ ] 1.4 Add `x-openregister-notifications` block: `uwv_42week_advance` (trigger at day 280, recipients `hr-managers`) and `uwv_42week_due` (trigger at day 294, recipients `hr-managers`)
- [ ] 1.5 Add `x-openregister-relations` entry linking `employee` property to the Employee schema in the `hrmq` register

---

## 2. Seed data

- [ ] 2.1 Add 5 SickLeaveCase seed objects to `lib/Settings/hrmq_register.json` under `components.objects[]` using `@self` envelopes (slugs, register, schema as defined in design.md seed-data section)
- [ ] 2.2 Verify seed data covers all five statuses: `recovered`, `long_term_sick` (×2), `sick`, `partially_recovered`
- [ ] 2.3 Verify `importFromApp()` idempotency: running import twice does not create duplicate objects (match by slug)

---

## 3. Backend — Controller and authorization

- [ ] 3.1 Create `lib/Controller/SickLeaveCaseController.php` with methods: `index()`, `show()`, `create()`, `update()`, `destroy()`, `transition()`
  - Each method ≤10 lines; delegates all logic to `ObjectService` (ADR-003)
  - `index()` / `show()`: `#[NoAdminRequired]`
  - `create()` / `update()` / `destroy()` / `transition()`: `#[NoAdminRequired]` + `$this->actionAuth->requireAction()` call per ADR-023
  - Every mutation: per-object auth check (fetch object → verify ownership or hr-managers group or admin → throw `OCSForbiddenException` if none) per ADR-005 Rule 3
- [ ] 3.2 Create `lib/Service/ActionAuthService.php` with `requireAction(IUser $user, string $action): void`
  - Reads action matrix from `IAppConfig` under key `hrmq.actions`
  - Throws `OCSForbiddenException` with static message `"Not authorized"` when user's groups do not match
  - **No** stack traces or internal paths in error response per ADR-005
- [ ] 3.3 Add action matrix seed to `IAppConfig` on first install (repair step or `SettingsLoadService`): `sick-leave.report-sick`, `sick-leave.report-recovery`, `sick-leave.mark-uwv-sent`, `sick-leave.delete-case`, `settings.write` — all defaulting to `["admin", "hr-managers"]` except `settings.write` which defaults to `["admin"]`
- [ ] 3.4 Register all routes in `appinfo/routes.php` (specific routes before wildcard per ADR-016):
  ```
  GET    /api/sick-leave-cases
  GET    /api/sick-leave-cases/{id}
  POST   /api/sick-leave-cases
  PUT    /api/sick-leave-cases/{id}
  DELETE /api/sick-leave-cases/{id}
  POST   /api/sick-leave-cases/{id}/transitions/{transition}
  ```
- [ ] 3.5 Add `@spec openspec/changes/sick-leave-mvp/tasks.md#task-3` PHPDoc tag to every new class and public method per ADR-003

---

## 4. Frontend — Pinia store

- [ ] 4.1 Create `src/store/modules/sickLeaveCase.js` using `createObjectStore('sickLeaveCase')` with plugins: `lifecyclePlugin`, `auditTrailsPlugin`, `relationsPlugin`, `filesPlugin`
- [ ] 4.2 Register the store in `src/store/store.js` via `objectStore.registerObjectType('sickLeaveCase', 'SickLeaveCase', 'hrmq')`
- [ ] 4.3 Wrap every `await store.action()` in `try/catch` with user-facing error feedback (NcDialog or toast) per ADR-004

---

## 5. Frontend — src/manifest.json pages

- [ ] 5.1 Add three pages to `src/manifest.json`:
  - `sick-leave-dashboard` (type: `dashboard`, route: `/`, component: `SickLeaveDashboard`)
  - `sick-leave-cases` (type: `index`, route: `/sick-leave-cases`, component: `SickLeaveCaseIndex`)
  - `sick-leave-detail` (type: `detail`, route: `/sick-leave-cases/:id`, component: `SickLeaveCaseDetail`)
- [ ] 5.2 Add navigation entry for "Verzuim" in `src/manifest.json` pointing to `sick-leave-cases` page
- [ ] 5.3 Run `npm run check:manifest` and confirm the manifest validates against the canonical schema (ADR-024)

---

## 6. Frontend — Dashboard view

- [ ] 6.1 Create `src/views/SickLeaveDashboard.vue` using `CnDashboardPage` with `useDashboardView` composable
- [ ] 6.2 Add four `CnStatsBlock` KPI cards:
  1. Open cases (filter: status in [sick, partially_recovered, long_term_sick])
  2. UWV deadline approaching (filter: uwvDeadlineApproaching = true)
  3. Long-term sick (filter: isInYear2 = true)
  4. Recovered this month (filter: status = recovered AND endDate in current month)
- [ ] 6.3 Fetch all four counts in parallel via `Promise.all` on `created()`
- [ ] 6.4 Ensure dashboard renders correctly with zero cases (all KPI cards show 0, no crash)

---

## 7. Frontend — Index (list) view

- [ ] 7.1 Create `src/views/SickLeaveCaseIndex.vue` using `CnIndexPage` and `useListView('sickLeaveCase', { sidebarState, objectStore })`
- [ ] 7.2 Inject `sidebarState` from `App.vue`; row click navigates to `SickLeaveCaseDetail`
- [ ] 7.3 Configure `CnFilterBar` with filters: status (multiselect enum), uwvDeadlineApproaching (boolean), wachtdagApplies (boolean), startDate range
- [ ] 7.4 Add button opens new case detail page with `id: 'new'`
- [ ] 7.5 Column definitions from `columnsFromSchema()` — no hardcoded columns

---

## 8. Frontend — Detail view

- [ ] 8.1 Create `src/views/SickLeaveCaseDetail.vue` using `CnDetailPage` + `useDetailView` composable; props: `entityId` from route; `isNew = entityId === 'new'`
- [ ] 8.2 Show `CnTimelineStages` component displaying lifecycle progression (`sick` → `partially_recovered` | `recovered` | `long_term_sick`)
- [ ] 8.3 Add `CnDetailCard` sections:
  - "Ziekmelding details" — startDate, reportedDate, reportedBy, wachtdagApplies, causeCategory
  - "Status en duur" — status, durationDays, paymentPercentage, isInYear2
  - "UWV melding" — uwvDeadlineDate, uwvDeadlineApproaching, uwvNotificationSent, uwvNotificationSentDate
  - "Medewerker" — linked Employee CnDetailCard via `fetchUses`
- [ ] 8.4 Edit mode: `CnFormDialog` (schema-driven, auto-generated fields)
- [ ] 8.5 Header action buttons: "Bewerken" (Edit) + "Verwijderen" (Delete) using `CnDeleteDialog`
- [ ] 8.6 Add `CnObjectSidebar` with Files, Notes, Audit Trail tabs
- [ ] 8.7 Lifecycle transition buttons rendered for valid transitions from current status (e.g. "Hersteld melden", "UWV melding afgerond")
- [ ] 8.8 Every modal/dialog MUST live in its own `.vue` file under `src/modals/` or `src/dialogs/` — no inline modal markup in this component (ADR-004)

---

## 9. Translations

- [ ] 9.1 Add all new translation keys to `l10n/en.json` (English, sentence case per ADR-007)
  - Keys: `"Sick leave cases"`, `"Report sick"`, `"Report recovery"`, `"Waiting day applies"`, `"No waiting day"`, `"Payment percentage"`, `"Year 1"`, `"Year 2"`, `"UWV notification sent"`, `"UWV deadline"`, `"Open cases"`, `"UWV deadline approaching"`, `"Long-term sick"`, `"Recovered this month"`, `"Cause category"`, and all status labels, transition button labels, and field descriptions
- [ ] 9.2 Add Dutch translations for every key to `l10n/nl.json`
- [ ] 9.3 Verify `en.json` and `nl.json` have exactly the same keys (zero gaps per ADR-007)
- [ ] 9.4 Confirm no hardcoded Dutch strings remain in any `.vue` or `.php` file — all user-visible strings use `t()` / `$this->l10n->t()`

---

## 10. Admin settings

- [ ] 10.1 Create `src/views/AdminSettings.vue` (rendered via `AdminSettings.php`) with `CnVersionInfoCard` as the first section (ADR-004)
- [ ] 10.2 Add action matrix editor section: table with rows = actions, columns = available NC groups, checkboxes = allowed; save via `POST /api/settings`
- [ ] 10.3 Register `AdminSettings.php` as `\OCP\Settings\ISettings` in `Application::register()`
- [ ] 10.4 Settings controller method annotated with `#[AuthorizedAdminSetting(Application::APP_ID)]` per ADR-023 Rule 3

---

## 11. Tests

- [ ] 11.1 Create `tests/Unit/Controller/SickLeaveCaseControllerTest.php` with ≥3 test methods per public controller method
  - Cover happy path (200), missing field (400), unauthorized (403), not found (404)
- [ ] 11.2 Create `tests/Unit/Service/ActionAuthServiceTest.php`
  - Test: user in allowed group → no exception
  - Test: user NOT in allowed group → `OCSForbiddenException`
  - Test: action not in matrix → `OCSForbiddenException`
- [ ] 11.3 Create `tests/integration/sick-leave.postman_collection.json` covering all endpoints including at least one error path per endpoint
  - Use env variable placeholders for credentials — no hardcoded defaults (ADR-008)
- [ ] 11.4 Verify `composer check:strict` passes with no failures

---

## 12. Smoke testing (ADR-008)

- [ ] 12.1 Call `GET /api/sick-leave-cases` — verify 200, paginated list with `total`/`page`/`pages` fields
- [ ] 12.2 Call `POST /api/sick-leave-cases` with a valid body — verify 200, returned object has `id` and `status: sick`
- [ ] 12.3 Call `POST /api/sick-leave-cases` without `startDate` — verify 400 response
- [ ] 12.4 Call `POST /api/sick-leave-cases/{id}/transitions/report_recovery` without `endDate` — verify 422
- [ ] 12.5 Call any mutation endpoint as a user without action permission — verify 403 with static message
- [ ] 12.6 Navigate to the verzuim dashboard in a browser — verify all 4 KPI cards render with seed data values
- [ ] 12.7 Navigate to the sick leave case list — verify seed data rows appear with correct status labels in Dutch
- [ ] 12.8 Open a sick leave case detail — verify `CnTimelineStages` shows the correct active state

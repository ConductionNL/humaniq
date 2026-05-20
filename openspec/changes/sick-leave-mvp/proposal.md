---
kind: code
depends_on: []
---

# Sick Leave / Verzuim MVP (70%/70%, UWV)

## Why

Dutch employers are legally required under the Wet Verbetering Poortwachter (WVP) to register sick leave from day one, apply the 70% wage-continuation obligation in year 1 and year 2, and notify UWV by week 42 for every long-term sick employee. All 12 tracked competitors ship a dedicated verzuim module (AFAS, Centric, Loket, Visma Raet, Employes, and others). Without a built-in module, HRMQ users must manage statutory obligations in spreadsheets — creating compliance risk, administrative overhead, and a material market gap against every tracked competitor.

## What Changes

- **Ziekmelding / hersteldmelding** — HR can register a sick report and a recovery report for any employee, with the case tracked from first sick day to full recovery
- **Wachtdag tracking** — the first sick day can be flagged as an unpaid waiting day (wachtdag) per case
- **70%/70% payment display** — the applicable wage-continuation percentage (70% year 1, 70% year 2) is calculated and shown on every sick leave case
- **UWV 42e-weekmelding** — automatic notifications to HR managers at 40 weeks (advance warning) and 42 weeks (statutory deadline); UWV submission status tracked per case
- **Verzuim dashboard** — KPI widgets showing open cases, approaching UWV deadlines, long-term cases (>1 year), and recovery statistics

## Capabilities

### New Capabilities

- `sick-leave-registration`: create, view, edit, and close sick leave cases (ziekmelding and hersteldmelding) per employee; full lifecycle from first sick day to recovery
- `wachtdag-tracking`: record and display whether the first sick day is an unpaid waiting day per case
- `payment-calculation`: display the statutory 70% wage-continuation percentage, segmented by year 1 (≤365 days) versus year 2 (>365 days)
- `uwv-42-week-notification`: alert HR managers 2 weeks before the UWV 42-week deadline and again when the deadline is reached; track whether the UWV notification has been submitted per case
- `sick-leave-dashboard`: summary view with KPI cards (open cases, deadlines approaching, long-term cases) and a filterable list of cases requiring action

## Impact

- `lib/Settings/hrmq_register.json`: SickLeaveCase schema with `x-openregister-lifecycle`, `x-openregister-calculations`, `x-openregister-notifications`, and 5 seed objects
- `src/manifest.json`: three new pages — sick leave dashboard, sick leave index, sick leave detail
- `appinfo/routes.php`: REST routes for SickLeaveCaseController
- `lib/Controller/SickLeaveCaseController.php`: thin REST controller (CRUD + lifecycle transitions)
- `lib/Service/ActionAuthService.php`: action-level authorization matrix per ADR-023
- `src/store/modules/sickLeaveCase.js`: Pinia object store (`createObjectStore` + `lifecyclePlugin`)
- `src/views/SickLeaveDashboard.vue`: `CnDashboardPage` with `CnStatsBlock` KPI cards
- `src/views/SickLeaveCaseIndex.vue`: `CnIndexPage` list view with filter bar
- `src/views/SickLeaveCaseDetail.vue`: `CnDetailPage` + `CnObjectSidebar` detail view
- `l10n/en.json` + `l10n/nl.json`: all new user-visible strings (English keys, Dutch translations)
- `tests/Unit/Controller/SickLeaveCaseControllerTest.php`: PHPUnit unit tests
- `tests/integration/sick-leave.postman_collection.json`: integration tests (happy path + error paths)

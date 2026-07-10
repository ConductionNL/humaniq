---
kind: code
---

## Why

HR/labour timesheet approval belongs in hrmq, the HR/labour app. Today it lives nowhere
real: pipelinq's "Timesheet approval" menu entry is just a configurable deep-link to
shillinq (`/index.php/apps/shillinq/`), and shillinq's `UrenRegistratie` schema is an
append-only **billing** time-log with no approval state at all — there is no
submitted→approved/rejected lifecycle, no approver, no separation-of-duties anywhere in the
fleet. The "approval" the menu promises does not exist.

This change builds the genuine feature in hrmq: a `Timesheet` object with a declarative
approval lifecycle, surfaced through declarative manifest pages (a list and a pending-approval
queue) rendered generically by the `@conduction/nextcloud-vue` library — no bespoke Vue views.
The one rule the declarative state machine cannot express — separation of duties (an employee
may not approve their own timesheet) — is a small OpenRegister `LifecycleGuardInterface`.

This is also the first frontend hrmq ships: until now hrmq was a config + occ-commands app
(the payroll compliance engine) with no SPA shell. This change adds the minimal manifest-renderer
bootstrap (CnAppRoot) and a PageController, matching the rest of the fleet.

## What Changes

- **New `Timesheet` schema** in the OpenRegister `hrmq` register
  (`lib/Settings/register.d/hr-timesheet.json`) with a declarative
  `x-openregister-lifecycle` state machine: `draft → submitted → approved/rejected`, with a
  `reopen` back to draft. `submit`/`approve`/`reject`/`reopen` transitions; `approve` and
  `reject` declare `requires: OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`.
- **New `NoSelfApprovalGuard`** (`lib/Lifecycle/NoSelfApprovalGuard.php`) implementing
  OpenRegister's `LifecycleGuardInterface` — denies approving/rejecting a timesheet when the
  acting user is the claiming employee (separation of duties). Registered keyed by its FQCN in
  `Application.php` so OpenRegister's `LifecycleGuardRegistry` resolves the `requires` tag.
  Fails closed. (Shared with the `hrmq-expenses` change, which reuses the same guard.)
- **Declarative manifest pages** (`src/manifest.json`): a `Timesheets` `type:"index"` list, a
  `TimesheetApproval` `type:"index"` queue defaulting its filter to `status == submitted`, and a
  `TimesheetDetail` `type:"detail"` page — all rendered generically from `{register, schema}`
  config by the library, with menu entries under an "Uren" group. No `type:"custom"` page, no
  bespoke component.
- **Minimal SPA shell** (first hrmq frontend): `src/App.vue`, `src/main.js`, `src/registry.js`
  (empty — no custom pages), `src/pinia.js`, `src/icons.js`, `src/assets/app.css`,
  `package.json`, `webpack.config.js`; `lib/Controller/PageController.php`, `appinfo/routes.php`,
  `templates/index.php`, and an `<navigations>` entry + a `hrmq.page.index` route so HRMQ appears
  in the Nextcloud app menu.
- **Seed data** (`lib/Settings/register.d/hr-seed.json`): three realistic `Timesheet` objects
  (submitted / approved / rejected) with `@self` envelopes.

## Capabilities

### New Capabilities
- `hrmq-timesheet-approval`: review and approve/reject submitted employee timesheets, with a
  declarative approval lifecycle and a separation-of-duties guard.

## Impact

- **`lib/Settings/register.d/hr-timesheet.json`** (new) — `Timesheet` schema + lifecycle.
- **`lib/Settings/register.d/hr-seed.json`** (new, shared with `hrmq-expenses`) — seed objects.
- **`lib/Lifecycle/NoSelfApprovalGuard.php`** (new, shared) — separation-of-duties guard.
- **`lib/AppInfo/Application.php`** — register the guard service.
- **`src/manifest.json`, `src/App.vue`, `src/main.js`, `src/registry.js`, `src/pinia.js`,
  `src/icons.js`, `src/assets/app.css`, `package.json`, `webpack.config.js`** (new) — SPA shell.
- **`lib/Controller/PageController.php`, `appinfo/routes.php`, `templates/index.php`,
  `appinfo/info.xml`** — SPA route + navigation entry.
- **Re-homing**: replaces pipelinq's "Timesheet approval" deep-link to shillinq. Pipelinq
  Phase-2 deletes the `BillingApproval` menu entry, its `menu-layout.json` relocation, the
  `applyRegistryBillingHref()` resolver in `src/main.js`, and the `shillinq_app_url` config +
  Settings field; the entry is replaced by a deep-link to `/index.php/apps/hrmq/timesheets/approval`.
- **No new external dependency, no DB table, no direct SQL** (ADR-022) — the Timesheet objects
  live in the OpenRegister `hrmq` register and the pages read/write them via the library's object
  store.

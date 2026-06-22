## 1. Timesheet schema + lifecycle (config)

- [x] 1.1 Add `Timesheet` schema to `lib/Settings/register.d/hr-timesheet.json` (employeeId, period, hours, description, projectId, costCenter, billable, status, submittedAt, approvedBy, approvedAt, rejectionReason; schema:Action)
- [x] 1.2 Declare the `x-openregister-lifecycle` state machine under `configuration`: field `status`, initial `draft`, transitions `submit` (draft/rejected→submitted), `approve` (submitted→approved), `reject` (submitted→rejected), `reopen` (approved→draft)
- [x] 1.3 Reference the separation-of-duties guard on `approve` and `reject` via `requires: OCA\\Hrmq\\Lifecycle\\NoSelfApprovalGuard`

## 2. Separation-of-duties guard (code)

- [x] 2.1 Implement `lib/Lifecycle/NoSelfApprovalGuard.php` as `LifecycleGuardInterface`: deny when `$userId === $object['employeeId']`; fail closed when the actor or claimant is unknown
- [x] 2.2 Register the guard keyed by its FQCN in `lib/AppInfo/Application.php::register()` so OpenRegister's `LifecycleGuardRegistry` resolves the `requires` tag
- [x] 2.3 `php -l` the guard + Application.php

## 3. Declarative manifest pages + menu (config)

- [x] 3.1 Add `Timesheets` (`type:"index"`, columns + filters + sort), `TimesheetApproval` (`type:"index"`, `defaultFilters.status=submitted`), and `TimesheetDetail` (`type:"detail"`) pages to `src/manifest.json` — all generic, no `type:"custom"`
- [x] 3.2 Add the "Uren" menu group with `Urenregistratie` + `Urengoedkeuring` entries
- [x] 3.3 Validate `src/manifest.json` against the library's app-manifest-v2 schema

## 4. Minimal SPA shell (code)

- [x] 4.1 Add `src/App.vue` (mounts CnAppRoot), `src/main.js` (manifest→router bootstrap), `src/registry.js` (empty), `src/pinia.js`, `src/icons.js`, `src/assets/app.css`
- [x] 4.2 Add `package.json` + `webpack.config.js` (single `hrmq-main` entry; local-lib alias for monorepo dev)
- [x] 4.3 Add `lib/Controller/PageController.php` (SPA shell + bundled-manifest endpoint), `appinfo/routes.php`, `templates/index.php`, and the `<navigations>` entry in `appinfo/info.xml`
- [x] 4.4 `npm install` + `npm run build`; confirm `js/hrmq-main.js` emits

## 5. Seed + verify

- [x] 5.1 Add three `Timesheet` seed objects (submitted/approved/rejected) to `lib/Settings/register.d/hr-seed.json` with `@self` envelopes
- [x] 5.2 Deploy to :8080 (docker cp into custom_apps), `occ maintenance:repair`, and confirm the `Timesheet` schema + lifecycle annotation land in the OpenRegister `hrmq` register and the seed objects are queryable
- [x] 5.3 `composer check:strict` on the PHP (lint + phpcs + the guard)

- Tier: MVP. ADR-022 (consume OR), ADR-031/ADR-001 (declarative-first), ADR-036 (manifest pages). The approval state machine is fully declarative; only the cross-actor self-approval rule is PHP.

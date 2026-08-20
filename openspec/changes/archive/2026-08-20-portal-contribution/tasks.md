# Tasks: portal-contribution

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10.
     Acceptance criteria are plain bullets, not checkboxes. -->

## Implementation Tasks

### Task 1: Ship the plain PortalContributionProvider class

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-provider-is-a-plain-dependency-free-class-req-port-001`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN the new class WHEN inspected THEN it is namespace `OCA\Hrmq\Portal` (PSR-4 `OCA\Hrmq\ → lib/` per composer.json), has NO `use` of any portaliq symbol, NO `implements` clause, NO constructor dependencies, and carries the repo-standard EUPL-1.2/SPDX docblock header plus `@spec` tags
  - GIVEN portaliq is absent WHEN the app runs THEN nothing references the class (no DI registration in `Application.php`, no route) — it is inert
- [x] Implement
- [x] Test

### Task 2: Implement the v2+v1 audience contract

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-provider-declares-both-v2-and-v1-audience-methods-req-port-002`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN the provider WHEN `getAudiences()` / `getAudience()` are called THEN they return `['external-employee', 'client']` / `'external-employee'`
  - GIVEN an unknown, empty or missing audience WHEN `getContribution()` is called THEN it returns `null` (fail-closed)
- [x] Implement
- [x] Test

### Task 3: Declare both audience manifests with the contracted scoping map

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-external-employee-manifest-is-uuid-scoped-self-service-req-port-003`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN an external-employee subject WHEN `getContribution()` is called THEN the manifest carries the six collections (Employee own record via `id` non-listable; Payslip/EmploymentContract/Timesheet/Expense/LeaveRequest via `employeeId`), all `scopeClaim: employeeId`, `minTrust: low`, register `hrmq`
  - GIVEN the same manifest WHEN actions are inspected (REQ-PORT-004) THEN exactly createTimesheet/createExpense/createLeaveRequest exist with the contracted whitelists and no `employeeId`/status/approval-stamp field anywhere
  - GIVEN a client subject WHEN `getContribution()` is called (REQ-PORT-005) THEN only `clientTimesheets` (Timesheet, `scopeField: clientRef`, `scopeClaim: clientId`) is declared, read-only (empty actions/notifications)
- [x] Implement
- [x] Test

### Task 4: Unit-test the full provider contract with working suite wiring

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-create-actions-carry-conservative-field-whitelists-req-port-004`
- **files**: `tests/Unit/Portal/PortalContributionProviderTest.php`, `tests/bootstrap.php`, `phpunit.xml`
- **acceptance_criteria**:
  - GIVEN the test class WHEN it constructs the provider THEN it does so directly (`new`, no mocks/container) — the repo had no `tests/` dir, so the standalone wiring (`phpunit.xml` auto-discovered by `composer test:unit` + `tests/bootstrap.php` following the app-template pattern) is created here without breaking any existing script
  - GIVEN the suite WHEN run via `vendor/bin/phpunit` in the php:8.3-cli container THEN it pins plainness, audiences, the full scoping map for both audiences, the exact whitelists incl. forbidden fields, and fail-closed null — and passes
- [x] Implement
- [x] Test

### Task 5: Register the capability spec and pass the quality gates

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md`
- **files**: `openspec/specs/portal-contribution/spec.md`, `openspec/changes/portal-contribution/*`
- **acceptance_criteria**:
  - GIVEN the declared capability WHEN the change is in flight THEN `openspec/specs/portal-contribution/spec.md` exists with status `in-progress` pointing at this change
  - GIVEN the gates WHEN run in the php:8.3-cli container THEN `php -l`, the unit suite, phpstan (level 8, new files) and the canonical phpcs standard (new provider) pass; `openspec validate` passes for both chained changes
- [x] Implement
- [x] Test

## Quality checklist

- All new business logic covered by PHPUnit unit tests (`tests/Unit/`, direct construction)
- No new API endpoints → no Newman collection; no hrmq UI change → no Playwright (the portal renders in portaliq)
- Unit suite green in the php:8.3-cli container (`vendor/bin/phpunit`)
- Manifest labels are ENGLISH source (portal-side translation per i18n policy)
- `openspec validate` passes; `depends_on: [portal-schemas]` recorded in the proposal frontmatter

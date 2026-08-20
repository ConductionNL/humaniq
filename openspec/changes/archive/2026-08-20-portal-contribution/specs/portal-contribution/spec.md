# portal-contribution Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- `openspec/changes/portal-contribution/`

## Purpose

hrmq contributes an external-employee self-service section and a client
billable-hours review section to portaliq, the shared external portal for
people without Nextcloud accounts (hydra ADR-046 + 2026-07-06 amendment,
contribution contract v2). The contribution is one plain, dependency-free
provider class over the register surface shipped by the chained
`portal-schemas` change.

## ADDED Requirements

### Requirement: Provider is a plain, dependency-free class (REQ-PORT-001)

The app MUST ship `OCA\Hrmq\Portal\PortalContributionProvider` as a plain
PHP class at `lib/Portal/PortalContributionProvider.php` (matching the
repo's PSR-4 mapping `OCA\Hrmq\ → lib/`): no imports from portaliq, no
`implements` clause, no `info.xml` dependency on portaliq, no constructor
dependencies, and no DI registration in `Application.php`. Portaliq
discovers it by convention FQCN and duck-types it via `method_exists`
(never `instanceof`), so without portaliq installed the class MUST be inert
and MUST NOT change any app behaviour (ADR-046 amendment A1).

#### Scenario: Provider constructs standalone

- GIVEN a PHP runtime where portaliq is not installed and no portaliq class is autoloadable
- WHEN `new PortalContributionProvider()` is called
- THEN the class instantiates without error
- AND it declares no interfaces, no parent class and no constructor
- @e2e exclude backend-only contract class with no hrmq UI surface; the portal renders inside portaliq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Provider declares both v2 and v1 audience methods (REQ-PORT-002)

The provider MUST implement `getAudiences(): array` returning exactly
`['external-employee', 'client']` (contract v2, preferred by the registry)
AND `getAudience(): string` returning `'external-employee'` (v1 fallback —
the primary audience; a v1 registry sees only the external-employee
contribution), so it works against both registry generations (amendment
A2).

#### Scenario: Audience methods agree

- GIVEN a constructed provider
- WHEN `getAudiences()` and `getAudience()` are called
- THEN `getAudiences()` returns exactly `['external-employee', 'client']`
- AND `getAudience()` returns `'external-employee'`, which is contained in `getAudiences()`
- @e2e exclude backend-only contract methods with no hrmq UI surface — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: External-employee manifest is UUID-scoped self-service (REQ-PORT-003)

`getContribution(array $subject)` MUST return, for a subject whose
`audience` is `'external-employee'`, a declarative manifest labelled
`'HRMQ'` whose collections are all scoped via `scopeClaim: 'employeeId'`
(resolving to `claims.hrmq.employeeId` — the UUID of the subject's Employee
domain object, never a Nextcloud user id, amendment A4) with `minTrust:
'low'`: `myEmployeeRecord` (schema `Employee`, `scopeField: 'id'`, not
listable), `payslips` (`Payslip`), `employmentContracts`
(`EmploymentContract`), `timesheets` (`Timesheet`), `expenses` (`Expense`)
and `leaveRequests` (`LeaveRequest`) — the latter five with `scopeField:
'employeeId'`, listable. The manifest MUST be pure data — no callbacks, no
service calls; all subject identity is server-derived by portaliq and MUST
NOT be echoed back or trusted from the client.

#### Scenario: External-employee subject receives the six scoped collections

- GIVEN a subject with `audience` `'external-employee'`, a `subjectRef`, an organisation, trust `'low'` and a server-managed claim map
- WHEN `getContribution($subject)` is called
- THEN the manifest contains exactly the six collections above, each with register `hrmq`, the contracted schema/scopeField pair, `scopeClaim` `'employeeId'` and `minTrust` `'low'`
- AND `myEmployeeRecord` is the only non-listable collection
- @e2e exclude manifest is consumed and rendered by portaliq, not by any hrmq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Create actions carry conservative field whitelists (REQ-PORT-004)

The external-employee manifest MUST expose exactly three `create` actions —
`createTimesheet` (fields exactly `period`, `hours`, `description`,
`projectId`, `costCenter`, `billable`, `clientRef`), `createExpense`
(fields exactly `title`, `description`, `amount`, `currency`, `category`,
`expenseDate`) and `createLeaveRequest` (fields exactly `leaveType`,
`startDate`, `endDate`, `hours`, `reason`) — and no whitelist may contain
the scoping property `employeeId` (portaliq stamps it server-side) nor any
status/approval field (`status`, `submittedAt`, `approvedBy`, `approvedAt`,
`rejectionReason`, `reimbursedAt`): the declarative x-openregister-lifecycle
owns every transition (ADR-031) and an external must never self-approve.

#### Scenario: Whitelists exclude scoping and approval fields

- GIVEN the external-employee manifest
- WHEN its actions are inspected
- THEN exactly `createTimesheet`, `createExpense` and `createLeaveRequest` exist, each `type: 'create'` on register `hrmq` with the exact contracted field list
- AND no field list contains `employeeId`, `status`, `submittedAt`, `approvedBy`, `approvedAt`, `rejectionReason` or `reimbursedAt`
- @e2e exclude backend-only declarative whitelist enforced server-side by portaliq's writer — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Client manifest is read-only clientRef-scoped timesheets (REQ-PORT-005)

For a subject whose `audience` is `'client'`, `getContribution()` MUST
return a manifest labelled `'HRMQ'` with exactly one collection —
`clientTimesheets`: register `hrmq`, schema `Timesheet`, `scopeField:
'clientRef'`, `scopeClaim: 'clientId'` (resolving to
`claims.hrmq.clientId`), `minTrust: 'low'`, listable — and empty `actions`
and `notifications`. The approve/reject of billable hours MUST NOT be
declared this wave: it requires the bearer-forwarded endpoint action type
(amendment A6) whose receiver-side verification hrmq does not implement
yet. For any other or missing audience `getContribution()` MUST return
`null` (fail-closed).

#### Scenario: Client subject receives the read-only view

- GIVEN a subject with `audience` `'client'` and a claim map carrying `clientId`
- WHEN `getContribution($subject)` is called
- THEN the manifest contains only the `clientTimesheets` collection scoped by `clientRef` via the `clientId` claim
- AND `actions` and `notifications` are empty
- @e2e exclude manifest is consumed and rendered by portaliq, not by any hrmq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

#### Scenario: Unknown audience receives null

- GIVEN a subject whose `audience` is `'supplier'`, empty, or absent
- WHEN `getContribution($subject)` is called
- THEN it returns `null`
- @e2e exclude backend-only fail-closed filter logic with no hrmq UI surface — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

## Non-Functional Requirements

- **Performance:** `getContribution()` is pure data assembly — no I/O, no
  container access; sub-millisecond by construction.
- **Security:** server-derived subject only (ADR-005/A6); UUID domain-object
  scoping (A4); fail-closed audience filter; strict create whitelists;
  `minTrust` explicit so the substantial-raise for payslips/contracts/own
  record is a reviewed one-line edit when the gov-IdP broker lands.
- **Accessibility / i18n:** N/A in hrmq — the rendering surface is
  portaliq's SPA; manifest labels ship in English source per fleet i18n
  policy, translated portal-side.

## Acceptance Criteria

- Unit suite proves: plainness (reflection), both audience methods, the full
  scoping map for both audiences, the exact whitelists incl. forbidden-field
  absence, and fail-closed null.
- `php -l` passes on the new files; the new provider is clean against the
  canonical fleet phpcs standard; phpstan level 8 clean on the new files.
- `openspec validate` passes for this change.

## Notes

- Deliberately NOT registered in `lib/AppInfo/Application.php` — discovery
  is by FQCN from portaliq's side.
- Related: hydra ADR-046 (+A1–A7), ADR-022 (apps consume OR abstractions —
  portaliq reads/writes OR directly, hrmq adds no portal CRUD), ADR-031
  (lifecycles own transitions), ADR-005 (server-derived scope, fail-closed).

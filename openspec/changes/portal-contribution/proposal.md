---
kind: code
depends_on:
  - portal-schemas   # config head: ships the LeaveRequest schema and the Timesheet clientRef scoping property this provider's manifest references
---

# Proposal: portal-contribution

## Summary

Ship hrmq's Wave-1 ADR-046 portal contribution: one plain, dependency-free
class (`lib/Portal/PortalContributionProvider.php`, PSR-4 namespace
`OCA\Hrmq\Portal`) that declares — for two audiences — what an external
subject may see and do in hrmq through portaliq, the shared external portal
for people WITHOUT Nextcloud accounts. `external-employee` reads their own
employee record, payslips, employment contracts, timesheets, expenses and
leave requests, and may create timesheets, expenses and leave requests
through strict field whitelists; `client` gets a read-only view over the
timesheets whose billable hours they review (`clientRef`). Plus PHPUnit unit
tests and the repo's first working standalone PHPUnit wiring. Tracking
issue: Conduction/hrmq#4.

**Chain (ADR-032):** depends on `portal-schemas` (kind: config), which ships
the `LeaveRequest` schema and the `Timesheet.clientRef` property this
manifest references.

## Motivation

The ADR-046 amendment (2026-07-06, contribution contract v2) names hrmq a
Wave-1 contributor: the "HR area" case in the fleet review — external
employees and contractors without NC accounts need payslip/contract/
timesheet/expense/leave self-service, and clients need billable-hours
review. hrmq's `employeeId` domain-object scoping is called out as the
fleet's best-practice pattern (amendment A4): the Employee schema
deliberately has no Nextcloud-user link, so scoping is a pure UUID
domain-object reference. Contributing requires exactly one duck-typed class
— no portaliq import, no info.xml dependency — so portal support stays
optional and hrmq is inert without portaliq (amendment A1).

## Affected Projects

- [x] Project: `hrmq` — new `lib/Portal/PortalContributionProvider.php`, new `tests/Unit/Portal/PortalContributionProviderTest.php` + `tests/bootstrap.php` + `phpunit.xml` (first working unit-suite wiring), OpenSpec capability `portal-contribution`.

## Scope

### In Scope

- A plain `OCA\Hrmq\Portal\PortalContributionProvider` (no portaliq imports,
  no `implements`, no constructor deps) exposing `getAudiences()` (v2:
  `['external-employee', 'client']`), `getAudience()` (v1 fallback:
  `'external-employee'`), and `getContribution(array $subject): ?array`
  branching on `$subject['audience']`, null otherwise (fail-closed).
- external-employee manifest: six collections scoped via the `employeeId`
  claim (`claims.hrmq.employeeId` — the UUID of the subject's Employee
  object): Employee own record (scopeField `id`, non-listable), Payslip /
  EmploymentContract / Timesheet / Expense / LeaveRequest (scopeField
  `employeeId`); three `create` actions with conservative whitelists
  (Timesheet, Expense, LeaveRequest).
- client manifest: one read-only collection — Timesheet scoped by
  `clientRef` via the `clientId` claim.
- `minTrust: low` on every collection (Wave-1 reality: employer-issued
  password accounts; the raise plan is documented in design.md).
- PHPUnit unit tests (direct construction) + the standalone
  `phpunit.xml`/`tests/bootstrap.php` wiring that makes
  `composer test:unit` actually execute (previously no tests existed and
  phpunit had no config).

### Out of Scope

- **Endpoint actions** (amendment A6) — the client's approve/reject of
  billable hours and the external employee's `submit` lifecycle transition
  need the bearer-forwarded endpoint action type; hrmq implements no
  receiver-side subject-assertion verification this wave, so no `endpoint`
  actions are declared. Documented as the follow-up in design.md.
- Any portal UI, auth edge, inbox or rendering — portaliq owns the entire
  external surface; hrmq ships zero portal frontend.
- `notifications` — empty until hrmq adopts the x-openregister-notifications
  dialect (none of its schemas declare notifications today).
- Schema changes — all in the `portal-schemas` dependency.
- Repo-wide QA-config adoption (phpcs.xml/psalm.xml/phpstan.neon are absent
  at HEAD; adopting the canonical fleet configs surfaces ~586 pre-existing
  violations in the compliance corpus — a dedicated cleanup change).

## Approach

Duck-typed discovery per amendment A1: portaliq's registry resolves
`OCA\{App}\Portal\PortalContributionProvider` by FQCN and probes it with
`method_exists` — hrmq ships a plain class with the three contract methods
and nothing else, mirroring the petstore reference provider. The
contribution is a declarative manifest (pure data); scoping follows
amendment A4 (UUID domain refs resolved from the server-managed claim map,
never NC uids); portaliq stamps the scope field server-side on create, so no
scoping property appears in any whitelist. Details in design.md.

## New Dependencies

None. The provider is dependency-free by contract; the class is inert when
portaliq is not installed. (dev-only: the existing `nextcloud/ocp` +
`phpunit/phpunit` dev dependencies now actually get exercised.)

## Impact

- `lib/Portal/PortalContributionProvider.php` — new, self-contained; no DI
  registration in `Application.php` (discovery is pull-based from portaliq).
- `tests/Unit/Portal/PortalContributionProviderTest.php`,
  `tests/bootstrap.php`, `phpunit.xml` — new; first working unit suite.
- No routes, controllers, services, frontend, register or info.xml changes.

## Cross-Project Dependencies

None at build or install time (the point of amendment A1). At runtime,
portaliq — when installed — discovers and renders the contribution;
portaliq's contract-v2 registry (getAudiences/scopeClaim/minTrust
enforcement) lands in its own repo in parallel, which is why the provider
implements both the v1 and v2 audience methods.

## Risks

### Risk 1: Contract v2 drift while portaliq lands in parallel

**Severity:** Medium — **Mitigation:** implement both `getAudiences()` (v2)
and `getAudience()` (v1 fallback) and use only manifest keys fixed by the
ADR-046 amendment (`label`, `collections` with
`id`/`register`/`schema`/`scopeField`/`scopeClaim`/`minTrust`/`label`/
`listable`, create-action `fields`, `notifications`). Unit tests pin the
exact shape so any later contract change is a visible, reviewed edit.

### Risk 2: `scopeField: id` for the own-Employee-record collection

**Severity:** Low — **Mitigation:** OpenRegister serialises every object
with its UUID at top level (`ObjectEntity::jsonSerialize` sets
`$object['id'] = $this->uuid`), so portaliq's per-row scope verification
(`$normalised[$scopeField] === $subjectRef`) holds when the `employeeId`
claim carries the Employee object UUID. The design records this as the
contract for "own record" collections.

## Rollback Strategy

Delete `lib/Portal/` and `tests/Unit/Portal/` (optionally `phpunit.xml` +
`tests/bootstrap.php`, though they are generally useful). Without the
provider class portaliq discovery finds nothing and the portal shows no HRMQ
section — the app itself is unaffected. The `portal-schemas` dependency is
independently revertible per its own rollback plan.

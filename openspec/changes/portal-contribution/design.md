# Design: portal-contribution

## Context

Portaliq (hydra ADR-046 + 2026-07-06 amendment) is the one shared external
portal for people without Nextcloud accounts. Domain apps contribute by
shipping a single plain class at a convention FQCN; portaliq's registry
resolves `OCA\{App}\Portal\PortalContributionProvider` per installed app and
duck-types it (`method_exists`, never `instanceof`). hrmq adds exactly one
runtime file under `lib/Portal/` and touches nothing else in the app:

```
portaliq (if installed)
  └─ registry resolves OCA\Hrmq\Portal\PortalContributionProvider (FQCN,
     matches composer PSR-4 "OCA\\Hrmq\\": "lib/" and info.xml <namespace>Hrmq)
       └─ getAudiences() → ['external-employee', 'client']   (v2, preferred)
       └─ getAudience()  → 'external-employee'               (v1 fallback)
       └─ getContribution($subject) → manifest (pure data) or null
            ├─ external-employee: 6 read collections + 3 create actions,
            │    scoped by claims.hrmq.employeeId (Employee object UUID)
            └─ client: 1 read-only collection (Timesheet.clientRef),
                 scoped by claims.hrmq.clientId
```

Without portaliq the class is never instantiated — inert by design
(amendment A1). There is deliberately **no** DI registration in
`lib/AppInfo/Application.php`: portal discovery is entirely pull-based from
portaliq's side. Chain: depends on `portal-schemas` (ADR-032 config head),
which ships `LeaveRequest` and `Timesheet.clientRef`. Tracking:
Conduction/hrmq#4.

## Goals / Non-Goals

**Goals** — declare the external-employee self-service surface and the
client billable-hours review surface, UUID-scoped, fail-closed, unit-tested;
make `composer test:unit` actually run.

**Non-Goals** — portal UI/auth/inbox (portaliq's), endpoint actions (A6,
deferred), notifications (no dialect adopted yet), schema work (dependency),
repo-wide QA-config adoption.

## Decisions

### Declarative-vs-imperative: the contribution is pure data

`getContribution()` returns a declarative manifest — no behaviour, no I/O,
no callbacks — the same philosophy as the ADR-024 app manifest and ADR-031
declarative lifecycles (which own ALL status transitions; that is exactly
why no status field appears in any create whitelist). A provider *class*
(rather than a JSON file) is used only because ADR-046 mandates it as the
delivery vehicle: autoloadable cross-app without file-path coupling, and
able to branch on the server-derived `$subject['audience']` without
portaliq parsing app-private config. The imperative surface is that single
branch.

### Scoping map (verified against the register at HEAD)

| Audience | Collection id | Schema (slug) | scopeField | scopeClaim | listable |
|---|---|---|---|---|---|
| external-employee | myEmployeeRecord | `Employee` | `id` | `employeeId` | false |
| external-employee | payslips | `Payslip` | `employeeId` | `employeeId` | true |
| external-employee | employmentContracts | `EmploymentContract` | `employeeId` | `employeeId` | true |
| external-employee | timesheets | `Timesheet` | `employeeId` | `employeeId` | true |
| external-employee | expenses | `Expense` | `employeeId` | `employeeId` | true |
| external-employee | leaveRequests | `LeaveRequest` | `employeeId` | `employeeId` | true |
| client | clientTimesheets | `Timesheet` | `clientRef` | `clientId` | true |

Property names quoted from the fragments at HEAD: `employeeId` exists on
Payslip/EmploymentContract (`hr-objects.json`), Timesheet
(`hr-timesheet.json`), Expense (`hr-expense.json`) and LeaveRequest
(`hr-leave.json`); `clientRef` on Timesheet (`hr-timesheet.json`, dependency
change). Register slug `hrmq` (SettingsService::getRegisterSlug default);
schema identifiers are the fragments' capitalised slugs.

**Own record via `scopeField: id`:** the `employeeId` claim *is* the UUID of
the subject's Employee object, and OpenRegister serialises every object with
its UUID as top-level `id` (`ObjectEntity::jsonSerialize`), so portaliq's
query filter and per-row verification both resolve. This is the contract-v2
way to expose "the domain object that represents the subject" without adding
a self-referential property.

### Claim-names contract (ADR-046 A4)

Bare `scopeClaim` names resolve under `claims.hrmq.<name>` in portaliq's
server-managed `portalAccount` claim map — never client-supplied:

- `employeeId` → UUID of the subject's `Employee` object (the fleet's
  exemplar: Employee has **no** NC-user link, externals need no NC account).
- `clientId` → UUID of the client contact/organisation domain object
  referenced by `Timesheet.clientRef`.

### Conservative create whitelists

Portaliq stamps the collection's scope field server-side on every create
(`PortalObjectWriter`: "server-side ownership stamps ALWAYS win"), so
`employeeId` is excluded from all whitelists. Status and approval-stamp
fields (`status`, `submittedAt`, `approvedBy`, `approvedAt`,
`rejectionReason`, `reimbursedAt`) are excluded everywhere: the declarative
lifecycle owns transitions, `status` defaults to `draft` per schema, and an
external must never self-approve. `receiptFile` is excluded from
createExpense because Wave-1 portaliq has no file-upload channel (revisit
with the docudesk upload action work). `clientRef` IS whitelisted on
createTimesheet: declaring which client the billable hours belong to is
data entry, not approval — and a client only ever sees rows scoped to their
own `clientId` claim, so mis-pointing it leaks nothing.

### Trust levels (ADR-046 A3): low now, raise plan documented

Every collection declares `minTrust: 'low'` explicitly — Wave-1 subjects are
employer-issued password accounts (the JWT edge; no gov-IdP dependency).
**Raise plan:** when the eHerkenning/DigiD broker lands (openconnector
ADR-017 adapters), `payslips`, `employmentContracts` and `myEmployeeRecord`
(it carries BSN and salary data) SHOULD be raised to `minTrust:
'substantial'` — a one-line-per-collection reviewed edit, which is why the
value is explicit rather than defaulted. Timesheets/expenses/leave remain
`low` (operational self-service).

### Deferred: endpoint actions (A6)

The client's approve/reject of billable hours and the external employee's
`submit` transition are *domain actions* requiring the bearer-forwarded
`endpoint` action type with receiver-side verification of the
`X-Portal-Subject` assertion. hrmq implements no such receiver this wave, so
the client surface is read-only and portal-created objects rest in `draft`
until back-office submission. Adopting endpoint actions is the natural next
hrmq portal change once portaliq's A6 forwarding is proven.

### Test wiring

`phpunit.xml` (auto-discovered by the existing `composer test:unit` script)
+ `tests/bootstrap.php` (composer autoload + OCP PSR-4 from the
`nextcloud/ocp` dev dependency + opportunistic server `base.php`), following
the petstore/app-template standalone pattern. The provider test constructs
the class directly — no mocks, no container — because the contract forbids
dependencies. This is the repo's first executing unit suite; previously
`composer test:unit` fell through to its `|| echo` fallback.

## Seed Data

None. This change ships no register objects (the schemas ship in
`portal-schemas`, which also documents why no LeaveRequest demo objects go
into `register.d` fragments — fragment objects import LIVE). Portal demo
subjects (a portalAccount with `claims.hrmq.employeeId` /
`claims.hrmq.clientId` pointing at nil-UUID placeholders
`00000000-0000-0000-0000-000000000000`) are portaliq-side fixtures, seeded
in the demo environment when the tutorial lands.

## Risks / Trade-offs

- [v1 registries only see the external-employee contribution] → acceptable:
  the client surface is new functionality that only exists under contract
  v2 anyway; both methods ship so nothing breaks either way.
- [Manifest keys drift while portaliq v2 lands in parallel] → keys
  restricted to the amendment's fixed vocabulary; unit tests pin the shape.
- [`format: uuid` claims vs slug-style legacy seeds (`employee-jansen`)] →
  demo claim maps must point at real object UUIDs, not slugs; recorded for
  the tutorial fixtures.

## Migration Plan

Pure additive code. Deploy = app update; no repair, no import, no data
change. Rollback = delete the files.

## Open Questions

- When portaliq's A6 endpoint-action forwarding ships, should the client
  approve/reject ride hrmq's lifecycle transitions directly (portaliq →
  OR lifecycle write) or a dedicated hrmq endpoint verifying the subject
  assertion? Tracked on Conduction/hrmq#4.

# Design: portal-schemas

## Context

hrmq is a thin OpenRegister client: every HR/labour object lives in the
`hrmq` register, defined by `lib/Settings/hrmq_register.json` plus modular
`lib/Settings/register.d/*.json` fragments (ADR-037), deep-merged and
imported version-gated by `SettingsService` via
`ConfigurationService::importFromApp()` (repair step
`OCA\Hrmq\Repair\InitializeRegister`). The portaliq fleet review flagged two
schema gaps blocking hrmq's Wave-1 portal contribution: no Leave/Absence
schema, and no client reference on Timesheet. This change closes both,
declaratively, as the config head of the `portal-schemas` →
`portal-contribution` chain (ADR-032). Tracking: Conduction/hrmq#4.

## Goals / Non-Goals

**Goals**

- A `LeaveRequest` object external employees can create and track through
  portaliq, with the same declarative approval workflow the repo already
  uses for Timesheet and Expense.
- A `clientRef` scoping property so the `client` audience can be scoped to
  "timesheets whose billable hours are mine to review" (ADR-046 A4).
- Version-gated import correctness and gate-28 (ADR-011) metadata on
  everything touched.

**Non-Goals**

- Leave balances / statutory entitlement engines (rule-corpus work, later).
- Back-office leave UI pages in hrmq's own manifest.
- The provider class (chained `portal-contribution` change).

## Decisions

### Declarative-vs-imperative (ADR-031): lifecycle = declarative

The entire LeaveRequest workflow is expressed as a declarative
`x-openregister-lifecycle` on the schema — **no PHP transition code**:

```
field: status, initial: draft, terminal: []
submit  : draft|rejected → submitted
approve : submitted → approved   (requires NoSelfApprovalGuard)
reject  : submitted → rejected   (requires NoSelfApprovalGuard)
```

The one rule the state machine cannot express — separation of duties (the
approver may not be the requesting employee) — reuses the existing
`OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`, which reads `$object['employeeId']`
generically and therefore works for LeaveRequest unchanged. This mirrors the
Timesheet/Expense shape exactly (same fragment style as
`hr-timesheet.json` / `hr-expense.json`; the decidesk register carries the
same ADR-031 lifecycle dialect). Alternative considered: an imperative
LeaveService with approve/reject endpoints — rejected, hrmq owns no backend
CRUD (ADR-022) and the repo's two sibling workflows are already declarative.

`terminal` is deliberately empty (Timesheet-style, not Expense-style): no
leave state is irreversible the way a paid-out `reimbursed` expense is, and
keeping `approved` non-terminal leaves room to add a `cancel`/`reopen`
transition later as a pure additive fragment edit.

### Scoping properties are UUID domain refs (ADR-046 A4)

- `LeaveRequest.employeeId` — `type: string`, `format: uuid` — references
  the **Employee domain object** (the fleet's exemplar pattern; the Employee
  schema deliberately has no Nextcloud-user link).
- `Timesheet.clientRef` — `type: string`, `format: uuid`, optional +
  nullable — references the **client contact/organisation domain object**.
  Never a Nextcloud user id: externals have no NC account by premise.
- Existing `employeeId` properties on Timesheet/Expense/Payslip/
  EmploymentContract keep their plain-string shape: the live `hr-seed.json`
  objects use slug-style refs (`employee-jansen`), and tightening the format
  on live data is a data-migration concern, not a portal-wave concern.

### No Project schema — `clientRef` lands on Timesheet only

Verified at HEAD: the register defines Employee, EmploymentContract,
Payslip, PayrollRun, LoonaangifteFiling, Expense, Timesheet — no Project
schema (`Timesheet.projectId` is a plain string). The proposal's "add to the
project schema too IF one exists" clause therefore resolves to: Timesheet
only.

### Claim-names contract (consumed by the chained change)

The portal subject's per-app claim map resolves bare claim names under
`claims.hrmq.<name>` (ADR-046 A4). This change fixes the two claim names the
provider will declare:

| Claim (bare name) | Resolves to | Scopes |
|---|---|---|
| `employeeId` | `claims.hrmq.employeeId` = UUID of the subject's Employee object | Payslip/EmploymentContract/Timesheet/Expense/LeaveRequest `employeeId`; Employee own record (`id`) |
| `clientId` | `claims.hrmq.clientId` = UUID of the client contact/organisation object | `Timesheet.clientRef` |

Claims are server-managed by portaliq — never client-supplied.

## Seed Data

**Decision: no LeaveRequest seed objects in this change.** hrmq's only seed
convention is `register.d/hr-seed.json` — a fragment whose
`components.objects` go **LIVE** on every import (fragment objects are real
register objects, not apply-time demo data; there is no apply-time
`_registers.json`-style seed path in this repo). Baking demo leave requests
into a fragment would ship fake HR data to every production install, so the
demo objects below are documented for the demo/tutorial environment only
(nil-UUID placeholders, to be created via the ordinary object API against a
dev instance):

| Field | Object 1 | Object 2 | Object 3 |
|---|---|---|---|
| @self | register `hrmq`, schema `LeaveRequest` | register `hrmq`, schema `LeaveRequest` | register `hrmq`, schema `LeaveRequest` |
| employeeId | 00000000-0000-0000-0000-000000000000 | 00000000-0000-0000-0000-000000000000 | 00000000-0000-0000-0000-000000000000 |
| leaveType | holiday | sick | special |
| startDate | 2026-08-03 | 2026-07-01 | 2026-07-20 |
| endDate | 2026-08-14 | 2026-07-03 | 2026-07-20 |
| hours | 80 | — | 8 |
| reason | Summer holiday | — | Moving house (bijzonder verlof) |
| status | submitted | approved | rejected |

## Risks / Trade-offs

- [Live seeds use slug refs while new properties are `format: uuid`] → only
  NEW properties carry the uuid format; nothing existing is tightened.
- [Fragment deep-merge unions by key] → the `hr-leave.json` fragment adds a
  disjoint `LeaveRequest` key; `hr-timesheet.json` replaces its own schema
  wholesale — no cross-fragment collision is possible (ADR-037).
- [Import gating] → double-gated: explicit version bumps + fragment-content
  signature folded into the version by `SettingsService`.

## Migration Plan

No `migration.md`: no database tables (hrmq owns none), no required-field
changes, no data transformation. Deploy = ordinary app update; the repair
step re-imports the register because both the register version and the
fragment signature changed. Rollback = revert the three JSON files.

## Open Questions

None — the audience/claim vocabulary is fixed by the ADR-046 amendment, and
the follow-up questions (trust-level raise, endpoint actions) are recorded in
the chained `portal-contribution` change's design.

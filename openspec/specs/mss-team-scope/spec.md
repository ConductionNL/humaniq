---
capability: mss-team-scope
status: in-progress
built_by: openspec/changes/mss-team-scope
---

# mss-team-scope Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [mss-team-scope](../../changes/mss-team-scope/) _(active)_ — denormalized `managerUserId` on Timesheet/Expense/LeaveRequest (the round-2 `userId` trade-off applied to the manager axis — two-hop filtering does not exist in the manifest token grammar), three `Team*goedkeuring` pages pre-filtered `managerUserId: @me`, Dashboard approver widgets re-scoped to the manager's team with a global HR fallback row, and the recommended-severity `nl-mss-manager-consistency` corpus rule cross-checking every stamp against the org-chart-basic structure (kind: config)

## Purpose

Give managers a team-scoped self-service surface per ADR-001 Rule 2 (Dashboard
widgets + scoped acties inside existing menus — never a "Manager portaal" and
never a sibling app): team approval queues and Dashboard KPIs filtered to the
records of the manager's own team, mechanically backed by an optional
denormalized `managerUserId` stamp (HR/back-office maintained, because the
manifest filter-token grammar has no two-hop/join form — re-verified at HEAD
against the vendored app-manifest-v2 schema) and kept honest by a
machine-checkable recommended-severity consistency rule that compares each
stamp against the manager of the employee's active `OrgAssignment` unit from
org-chart-basic. Spectr canon: `hrmq-canon-mss-team-scope` (3/9 competitive
coverage).

## Requirements

Detailed requirements (REQ-MSS-001 … REQ-MSS-006) are defined in the active
change's delta spec —
[`openspec/changes/mss-team-scope/specs/mss-team-scope/spec.md`](../../changes/mss-team-scope/specs/mss-team-scope/spec.md)
— and are merged here when the change is archived. The umbrella requirement
below anchors the capability until then.

### Requirement: hrmq scopes the manager self-service surface to the manager's own team via an org-audited denormalized stamp (REQ-MSS-000)

The app MUST let a manager see team-scoped approval queues and Dashboard
counts (records stamped with their own NC user id, filtered with the `@me`
token) while HR retains the global queues, and MUST audit every
`managerUserId` stamp against the org structure (`OrgAssignment` →
`OrgUnit.managerId` → `Employee.nextcloudUserId`) via
`occ hrmq:rules:audit` — reporting provable mismatches at recommended
severity and never punishing absent org data.

#### Scenario: Manager sees their team, HR sees everything, the audit checks the stamp
- **GIVEN** an imported hrmq register with a submitted record stamped `managerUserId` matching the logged-in manager
- **WHEN** the manager opens a Team-goedkeuring page and the audit runs
- **THEN** the record appears in the manager's team queue and in the HR global queue, and the audit reports a `nl-mss-manager-consistency` violation only if the stamp contradicts the employee's active placement's unit manager

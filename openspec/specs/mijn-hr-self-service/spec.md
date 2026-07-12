---
capability: mijn-hr-self-service
status: in-progress
built_by: openspec/changes/mijn-hr-self-service
---

# mijn-hr-self-service Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [mijn-hr-self-service](../../changes/mijn-hr-self-service/) _(active)_ — `Mijn HR` menu group (ADR-001 menu 2) with four `@me`-scoped employee index pages (uren / declaraties / verlof / loonstroken), a `Dashboard` page (ADR-001 menu 1) with self-service + approver KPI widgets, `Employee.nextcloudUserId` account link, and the denormalized `userId` scoping property on Timesheet/Expense/LeaveRequest/Payslip (kind: config)

## Purpose

Give hrmq's logged-in employees an in-app self-service surface per ADR-001
Rule 2 (role-filtered wrapper, never a sibling portal app): `Mijn HR` index
pages that show only the current user's records — scoped by a denormalized
`userId` property filtered with the renderer's `@me` token, the one mechanism
verified to work with today's renderer + OpenRegister — plus a Dashboard with
"mine" and approver KPIs. Employee self-service is a baseline market
expectation per the 2026-07-12 deep research (Spectr insights
`hrmq-insight-afas-baseline`, `hrmq-insight-ranked-buildlist`). External
(no-NC-account) self-service stays with portaliq and is out of scope.

## Requirements

See the active change's delta spec:
[changes/mijn-hr-self-service/specs/mijn-hr-self-service/spec.md](../../changes/mijn-hr-self-service/specs/mijn-hr-self-service/spec.md)
(REQ-MHS-001 … REQ-MHS-006). Canonical requirements land here on archive.

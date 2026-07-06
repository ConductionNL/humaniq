---
capability: portal-schemas
status: in-progress
built_by: openspec/changes/portal-schemas
---

# portal-schemas Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [portal-schemas](../../changes/portal-schemas/) _(active)_ — LeaveRequest schema + declarative lifecycle, Timesheet `clientRef` scoping property, version bumps (kind: config; chain head for `portal-contribution`)

## Purpose

Declarative register foundation for hrmq's Wave-1 contribution to portaliq,
the shared external portal for people without Nextcloud accounts (hydra
ADR-046 + 2026-07-06 amendment). Two pieces the fleet review flagged as
missing: a `LeaveRequest` schema with a declarative ADR-031 approval
lifecycle (reusing the shared `NoSelfApprovalGuard`), and an optional
`clientRef` UUID domain reference on `Timesheet` so a client can be scoped
to the billable hours they review. Pure register configuration — no PHP, no
frontend.

## Requirements

Detailed requirements (REQ-PSCH-001 … REQ-PSCH-004) are defined in the
active change's delta spec —
[`openspec/changes/portal-schemas/specs/portal-schemas/spec.md`](../../changes/portal-schemas/specs/portal-schemas/spec.md)
— and are merged here when the change is archived. The umbrella requirement
below anchors the capability until then.

### Requirement: hrmq's portal-facing register surface is declarative and UUID-scoped (REQ-PSCH-000)

The register configuration MUST model leave requests as a `LeaveRequest`
schema whose approval workflow is a declarative `x-openregister-lifecycle`
(no imperative transition PHP), and every portal scoping property
(`LeaveRequest.employeeId`, `Timesheet.clientRef`) MUST be a UUID reference
to a domain object — never a Nextcloud user id (ADR-046 A4).

#### Scenario: Register surface satisfies the portal contract

- GIVEN the shipped register configuration at HEAD
- WHEN the `LeaveRequest` and `Timesheet` schemas are inspected
- THEN the leave workflow is declared as an `x-openregister-lifecycle` and the scoping properties are UUID domain references
- @e2e exclude declarative register configuration with no hrmq UI surface — verified mechanically by the JSON gates and the gate-28 helper; the rendering surface is portaliq's

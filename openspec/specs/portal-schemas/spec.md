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

### Requirement: LeaveRequest schema exists in the hrmq register (REQ-PSCH-001)

The register configuration MUST define a `LeaveRequest` schema (fragment
`lib/Settings/register.d/hr-leave.json`, ADR-037 style) with properties
`employeeId` (`type: string`, `format: uuid` — UUID reference to the
Employee domain object, never a Nextcloud user id, ADR-046 A4), `leaveType`
(enum exactly `holiday`, `sick`, `unpaid`, `special`, `care`, `parental`),
`startDate` (`format: date`), `endDate` (`format: date`), `hours` (number,
optional), `reason` (string, optional), `status` (enum `draft`, `submitted`,
`approved`, `rejected`, default `draft`), and the repo-standard workflow
stamps `submittedAt`, `approvedBy`, `approvedAt`, `rejectionReason`.
Required: `employeeId`, `leaveType`, `startDate`, `endDate`, `status`. Every
property MUST carry a human-friendly English `title` and a `description`
(gate-28 / ADR-011).

#### Scenario: LeaveRequest schema parses with the contracted shape

- GIVEN the shipped `lib/Settings/register.d/hr-leave.json`
- WHEN the fragment is parsed and deep-merged into the register configuration
- THEN `components.schemas.LeaveRequest` defines the property set above with `leaveType` enum exactly `holiday`/`sick`/`unpaid`/`special`/`care`/`parental`
- AND `employeeId`, `leaveType`, `startDate`, `endDate`, `status` are required
- AND every property carries a non-empty `title` and `description`
- @e2e exclude declarative register configuration with no hrmq UI surface this change — verified mechanically by python3 json.load + the gate-28 helper (check_schema_property_meta.py), and consumed by portaliq, not by any hrmq page

### Requirement: LeaveRequest lifecycle is declarative with the shared guard (REQ-PSCH-002)

The `LeaveRequest` schema MUST govern its `status` field with a declarative
`x-openregister-lifecycle` state machine (ADR-031) — initial `draft`,
terminal `[]`, transitions `submit` (`draft`/`rejected` → `submitted`),
`approve` (`submitted` → `approved`) and `reject` (`submitted` →
`rejected`) — and the `approve` and `reject` transitions MUST declare
`requires: OCA\Hrmq\Lifecycle\NoSelfApprovalGuard` (separation of duties:
the approver may not be the requesting employee). No imperative PHP
transition code may exist for leave.

#### Scenario: Lifecycle annotation matches the contracted state machine

- GIVEN the shipped `LeaveRequest` schema
- WHEN its `configuration.x-openregister-lifecycle` is inspected
- THEN `field` is `status`, `initial` is `draft` and `terminal` is empty
- AND `submit` transitions `draft`/`rejected` to `submitted`, `approve` transitions `submitted` to `approved`, `reject` transitions `submitted` to `rejected`
- AND both `approve` and `reject` require `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`
- @e2e exclude declarative lifecycle configuration enforced by OpenRegister's state machine, not by hrmq UI — the guard itself is already covered by the hrmq-timesheet-approval capability; shape verified mechanically via python3 json inspection

### Requirement: Timesheet carries an optional clientRef UUID domain reference (REQ-PSCH-003)

The `Timesheet` schema MUST define a `clientRef` property — `type: string`,
`format: uuid`, `nullable: true`, NOT in `required`, titled "Client" — whose
value is the UUID of the client contact/organisation **domain object** that
reviews the billable hours (never a Nextcloud user id, ADR-046 A4). Existing
Timesheet objects without a `clientRef` MUST remain valid, and every
property of the touched Timesheet schema MUST carry a `title` and
`description` (gate-28 / ADR-011).

#### Scenario: clientRef is additive and optional

- GIVEN the shipped `lib/Settings/register.d/hr-timesheet.json`
- WHEN the `Timesheet` schema is parsed
- THEN `clientRef` is defined with `type` `string`, `format` `uuid`, `nullable` `true` and title "Client"
- AND `clientRef` is absent from the `required` list
- AND every Timesheet property carries a non-empty `title` and `description`
- @e2e exclude declarative register configuration with no hrmq UI surface this change — verified mechanically by python3 json.load + the gate-28 helper; the client-facing read over this field renders inside portaliq

### Requirement: Version bumps gate the re-import (REQ-PSCH-004)

The change MUST bump `info.version` in `lib/Settings/hrmq_register.json`
from `0.1.0` to `0.2.0` and the `Timesheet` schema version from `0.1.0` to
`0.2.0`, and MUST introduce `LeaveRequest` at version `0.1.0`, because
OpenRegister's `importFromApp` is version-gated. No live seed/demo objects
may be added to any `register.d` fragment (fragment objects go live on
import).

#### Scenario: Versions are bumped and no demo objects ship

- GIVEN the three touched register JSON files
- WHEN they are compared against the previous revision
- THEN the register `info.version` is `0.2.0`, the Timesheet schema version is `0.2.0` and the LeaveRequest schema version is `0.1.0`
- AND no `register.d` fragment gains any object with schema `LeaveRequest`
- @e2e exclude version metadata in declarative register configuration — verified mechanically via python3 json inspection and git diff; import gating is OpenRegister behaviour, not hrmq UI

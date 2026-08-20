# portal-schemas Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- `openspec/changes/portal-schemas/`

## Purpose

Declarative register foundation for hrmq's Wave-1 portaliq contribution
(hydra ADR-046 + 2026-07-06 amendment): a `LeaveRequest` schema with a
declarative approval lifecycle (ADR-031) and a `clientRef` UUID scoping
property on `Timesheet`, so the chained `portal-contribution` change can
declare portal collections and create-actions over them.

## ADDED Requirements

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

## Non-Functional Requirements

- **Performance:** fragment deep-merge and import are unchanged code paths;
  one additional fragment file is O(KB).
- **Accessibility:** N/A — no UI in this change; the property `title`s feed
  accessible form labels wherever the schema is rendered (ADR-011).
- **Internationalization:** all titles/descriptions and enum values ship as
  ENGLISH source per fleet i18n policy; Dutch HR terms appear only inside
  descriptions as clarifications.

## Acceptance Criteria

- All three JSON files parse (`python3 -c "import json; json.load(...)"`).
- Gate-28 helper reports zero findings on the touched register files.
- `openspec validate` passes for this change.

## Notes

- Chain head (ADR-032): `portal-contribution` (kind: code) depends on this
  change and declares the portal manifest over these schemas.
- Related: hydra ADR-046 (+A4), ADR-031 (declarative lifecycles), ADR-037
  (register fragments), ADR-011 (schema standards / gate-28).

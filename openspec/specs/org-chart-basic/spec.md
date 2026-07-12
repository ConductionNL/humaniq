---
capability: org-chart-basic
status: in-progress
built_by: openspec/changes/org-chart-basic
---

# org-chart-basic Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [org-chart-basic](../../changes/org-chart-basic/) _(in progress)_ — new `OrgUnit` (self-referencing hierarchy with type/cost-center/manager) and effective-dated `OrgAssignment` schemas in a new `hr-org` fragment, `$ref`-driven related surfaces, 2 new machine-checkable org-integrity rules (framework `hr-org-core`), org pages under Personeel and an EmployeeDetail assignments list (kind: config)

## Purpose

Give hrmq its first organisational model: an `OrgUnit` hierarchy and
effective-dated `OrgAssignment` placements on the OpenRegister data layer,
resolved everywhere through the renderer's declarative related machinery,
with assignment-consistency and cycle-freedom enforced as versioned corpus
rules. Feeds manager self-service team scoping and shillinq cost-center
owner resolution (Spectr `hrmq-insight-ranked-buildlist`; round-0 draft
`spec/org-chart-basic`). The draft's D3 tree, exports and snapshot
materialisation are explicit follow-ups.

## Requirements

Authoritative requirements (REQ-ORG-001 … REQ-ORG-008) live in the active
change's delta spec until archive:
[openspec/changes/org-chart-basic/specs/org-chart-basic/spec.md](../../changes/org-chart-basic/specs/org-chart-basic/spec.md).
They are synced into this file when the change is archived (`/opsx-archive`).

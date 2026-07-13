---
capability: performance-review-cycles
status: in-progress
built_by: openspec/changes/performance-reviews-mvp
---

# performance-review-cycles Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [performance-reviews-mvp](../../changes/performance-reviews-mvp/) _(active)_ — `ReviewCycle` schema (new fragment hr-performance.json) with declarative concept→open→gesloten lifecycle, ReviewCycles/ReviewCycleDetail sub-pages under the existing Personeel group (ADR-001 Rule 6 — no new menu), seeded open 2026 cycle (kind: config)

## Purpose

The orchestration container of the performance-review MVP: a generic
`ReviewCycle` (jaargesprek / beoordeling / tussentijds) with a declarative
open/close lifecycle, surfaced as SUB_PAGEs under the existing Personeel
group per ADR-001 Rule 6 — performance is dossier-anchored; there is no
performance module and no 10th top-level menu. The cycle is the container
`PerformanceReview.cycleId` references (see `performance-reviews`) and is
deliberately generic so the future `comp-planning-cycle` change can
reference cycles instead of reinventing them. Grounded in Spectr
canonicalFeature `hrmq-canon-performance-reviews` (6/9 competitor
coverage). OKR/9-box/kalibratie (`performance-management-advanced` draft)
and the comp cycle (`comp-planning-cycle` draft) are explicitly out of
scope.

## Requirements

Detailed requirements (REQ-PRC-001 … REQ-PRC-003) are defined in the active
change's delta spec —
[`openspec/changes/performance-reviews-mvp/specs/performance-review-cycles/spec.md`](../../changes/performance-reviews-mvp/specs/performance-review-cycles/spec.md)
— and are merged here when the change is archived. The umbrella requirement
below anchors the capability until then.

### Requirement: Review cycles are declarative register objects under the existing Personeel group (REQ-PRC-000)

The review-cycle capability MUST consist solely of the `ReviewCycle` schema
in `lib/Settings/register.d/hr-performance.json` (declarative
`x-openregister-lifecycle` concept→open→gesloten; no bespoke tables,
services or controllers — ADR-022/ADR-031) plus manifest sub-pages under
the pre-existing `EmployeesGroup` and seed data. Per ADR-001 Rule 6 the
capability MUST NOT introduce a new menu group or top-level menu entry.

#### Scenario: No module, no new menu

- GIVEN the hrmq codebase with this capability applied
- WHEN `src/manifest.json` and `lib/` are inspected
- THEN `ReviewCycles` is a child of the pre-existing `EmployeesGroup`, the top-level menu count is unchanged, and no PHP CRUD service/controller for cycles exists
- @e2e exclude structural manifest/register assertion with no user-observable flow of its own; covered by check:manifest and the delta spec's scenarios

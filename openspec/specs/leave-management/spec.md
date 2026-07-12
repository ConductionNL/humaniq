---
capability: leave-management
status: in-progress
built_by: openspec/changes/leave-verzuim-mvp
---

# leave-management Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [leave-verzuim-mvp](../../changes/leave-verzuim-mvp/) _(active)_ — Verlof & verzuim menu group (ADR-001 menu 5) with LeaveRequests/LeaveApproval/LeaveRequestDetail pages driving the existing LeaveRequest lifecycle, new `LeaveBalance` schema with calculated `remainingHours` + BW 7:640a expiry, 3 new machine-checkable NL leave rules in a new labour corpus (kind: config)

## Purpose

Make the existing `LeaveRequest` workflow (shipped schema-only by
`portal-schemas`) a usable back-office feature: the frozen ADR-001 menu-5
`Verlof & verzuim` surface with request, approval-queue and detail pages
bound to the already-declared submit→approve/reject lifecycle, a
`LeaveBalance` schema with a declaratively calculated remaining-hours figure
(statutory minimum 4× contractual weekly hours per BW art. 7:634; statutory
hours lapse 1 July of the following year per BW art. 7:640a), and three
versioned machine-checkable leave rules enforced by `NlLeaveChecks`.
Grounded in the 2026-07-12 market deep-research (Spectr insights
`hrmq-insight-nc-ecosystem-gap`, `hrmq-insight-ranked-buildlist`): leave is a
core module in every competitor, and hrmq's schema had zero UI and no
balances. Automatic accrual and CAO bovenwettelijk rules are explicitly out
of scope.

## Requirements

See the active change's delta spec:
[changes/leave-verzuim-mvp/specs/leave-management/spec.md](../../changes/leave-verzuim-mvp/specs/leave-management/spec.md)
(REQ-LVM-001 … REQ-LVM-006). Canonical requirements land here on archive.

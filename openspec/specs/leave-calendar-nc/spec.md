---
capability: leave-calendar-nc
status: in-progress
built_by: openspec/changes/leave-calendar-nc
---

# leave-calendar-nc Specification

**Status**: in-progress
**Scope**: hrmq (NC platform leaf; writes VEVENTs into the Nextcloud CalDAV store)
**OpenSpec changes**:
- [leave-calendar-nc](../../changes/leave-calendar-nc/) _(active)_ — `LeaveCalendarService` idempotent on-demand sync of approved `LeaveRequest`s and `SickLeaveCase`s onto one configured shared Nextcloud calendar (deterministic `hrmq-leave-{uuid}`/`hrmq-sick-{uuid}` UIDs, duck-typed `CalDavBackend`, `skipped-no-calendar` degradation, AVG summary boundary: sickness renders as 'Afwezig' without reason), occ trigger `hrmq:calendar:sync [--from]` (kind: code)

## Purpose

Make approved absence visible where the team already looks (Spectr
`hrmq-canon-leave-calendar`, 3/9 competitor coverage): one shared Nextcloud
calendar carries an all-day event per approved leave and per sickness case,
upserted/removed idempotently by an operator-triggered occ sync — no event
listeners, no background jobs, no external connectors. The AVG boundary is a
requirement, not a habit: sickness events say `Afwezig — {naam}` and no event
ever carries a reason, leave type, or diagnosis. Two-way sync, free/busy, iMIP
invitations, and per-employee personal calendars are explicitly out of scope.

## Requirements

See the active change's delta: [openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md](../../changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md) (REQ-LC-001…REQ-LC-007). Requirements are synced here on archive (`/opsx-archive`).

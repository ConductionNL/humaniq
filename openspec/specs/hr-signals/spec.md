---
capability: hr-signals
status: in-progress
built_by: openspec/changes/hr-signals
---

# hr-signals Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [hr-signals](../../changes/hr-signals/) _(active)_ — corpus-first HR-moment signalling: `nl-signaal-contract-verloopt` (temporary contract ending ≤60 days, no successor — advisory) + `nl-aanzegtermijn-bewaking` (BW 7:668 lid 1, mandatory) with the new `aanzegdOn` field, `NlSignalChecks` + the `signals` audit context, the 'Aflopende contracten' dashboard widget, and one intended-violation seed (kind: config)

## Purpose

Stop temporary contracts from lapsing unnoticed (Spectr `hrmq-canon-hr-signals`,
3/9 competitor coverage): the rule corpus signals a temporary contract ending
within 60 days that has no successor, and enforces the statutory aanzegplicht
(written notice one month before the end of a ≥6-month fixed term, BW 7:668 —
missed notice costs up to a month's wage). Investigated-at-HEAD boundaries:
proeftijd and WML signals were deliberately NOT added — `nl-onboarding-
proeftijd-bewaking` and `nl-minimumloon-2026`/`nl-minimumuurloon-wet` already
machine-check them. Push notifications (`x-openregister-notifications`),
ketenregeling chain counting, and aanzegbrief generation are explicitly out of
scope.

## Requirements

See the active change's delta: [openspec/changes/hr-signals/specs/hr-signals/spec.md](../../changes/hr-signals/specs/hr-signals/spec.md) (REQ-SIG-001…REQ-SIG-006). Requirements are synced here on archive (`/opsx-archive`).

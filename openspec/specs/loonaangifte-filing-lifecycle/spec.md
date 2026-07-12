---
capability: loonaangifte-filing-lifecycle
status: in-progress
built_by: openspec/changes/loonaangifte-filing-lifecycle
---

# loonaangifte-filing-lifecycle Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [loonaangifte-filing-lifecycle](../../changes/loonaangifte-filing-lifecycle/) _(active)_ — declarative concept→klaargezet→bevestigd→verzonden lifecycle on `LoonaangifteFiling`, tijdvakcode + response fields, 3 new machine-checkable NL deadline/tijdvakcode rules, lifecycle actions + deadline KPIs on the filing pages (kind: config)

## Purpose

Turn the passive `LoonaangifteFiling` record into a real Dutch wage-tax filing
workflow: a declarative create→review→confirm→send state machine, first-class
tijdvakcode data per Belastingdienst LH 210, statutory deadline derivation
(period end + one calendar month, no weekend extension) and alerting as
versioned machine-checkable corpus rules, and filing pages that drive the
lifecycle. #1-ranked missing feature from the 2026-07-12 market deep-research
(Spectr insights `hrmq-insight-tijdvak-deadlines`, `hrmq-insight-ranked-buildlist`).
Digipoort wire transport is explicitly out of scope.

## Requirements

See the active change's delta spec:
[changes/loonaangifte-filing-lifecycle/specs/loonaangifte-filing-lifecycle/spec.md](../../changes/loonaangifte-filing-lifecycle/specs/loonaangifte-filing-lifecycle/spec.md)
(REQ-LFL-001 … REQ-LFL-006). Canonical requirements land here on archive.

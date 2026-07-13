---
capability: offboarding-wizard
status: in-progress
built_by: openspec/changes/offboarding-wizard-mvp
---

# offboarding-wizard Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [offboarding-wizard-mvp](../../changes/offboarding-wizard-mvp/) _(active)_ — new `Offboarding` schema in the existing `hr-onboarding` fragment with a simplified declarative `aangekondigd → afronding_gepland → eindafrekening_gereed → afgerond` lifecycle (cancellable pre-afgerond, checklist-gated by rule checks — no guard classes), 4 new machine-checkable NL rules (transitievergoeding BW 7:673 with the 2026 formula constants as rule parameters, verlofsaldo-uitbetaling BW 7:641, getuigschrift BW 7:656, einddatum-consistentie BW 7:667), and offboarding pages under the existing ADR-001 `Onboarding & ATS` menu group (kind: config)

## Purpose

Give hrmq its departure surface, closing the hire-to-leave case bracket the
`hr-onboarding` fragment opened: one `Offboarding` case per departure with a
deterministic, declarative lifecycle whose milestones (departure announced,
wind-down planned, eindafrekening ready, completed) are gated by concrete
checklist and eindafrekening fields — `exitGesprekDone`, `assetsIngeleverd`,
`toegangIngetrokken`, `verlofsaldoUitbetaald`, `vakantiegeldAfgerekend`,
`transitievergoedingBedrag`, `getuigschriftVerstrekt` — documented on the
transitions and enforced by audit rules (write-time guard wiring is owned by
the active `hrmq-rule-compliance-enforcement` change). Four machine-checkable
NL rules cover the statutory transitievergoeding for dismissal-initiated
departures (formula constants versioned as rule parameters data), the payout
of open leave balances, the getuigschrift obligation, and the consistency of
`Employee.endDate` with the case's last working day. MVP-scoped from the
2026-05 `spec/offboarding-wizard` draft; market grounding: Spectr
canonicalFeature `hrmq-canon-offboarding` (coverage 4/9) and the round-3
disposition analysis. The eindafrekening computation engine, UWV WW-melding /
pensioenfonds / ZVW submissions, AVG retention timers, per-item asset tracking
(the parallel `asset-management-mvp` change owns asset data — loose coupling
only) and `Employee.endDate` write automation are explicitly out of scope.

## Requirements

Detailed requirements (REQ-OFB-001 … REQ-OFB-006) are defined in the active
change's delta spec —
[`openspec/changes/offboarding-wizard-mvp/specs/offboarding-wizard/spec.md`](../../changes/offboarding-wizard-mvp/specs/offboarding-wizard/spec.md)
— and are merged here when the change is archived. The umbrella requirement
below anchors the capability until then.

### Requirement: Offboarding is one declarative case object with rule-checked gates (REQ-OFB-000)

The app MUST model offboarding as a single `Offboarding` object per departure
in the `hr-onboarding` register fragment, whose state machine is fully
declarative (`x-openregister-lifecycle`, no imperative workflow code and no
bespoke guard classes) and whose statutory gates are versioned,
machine-checkable corpus rules enforced by an auto-discovered `CheckProvider`
— never ad-hoc validation scattered through controllers or Vue components.

#### Scenario: The offboarding surface is declarative

- GIVEN the hrmq codebase at HEAD
- WHEN the offboarding surface is inspected
- THEN the state machine lives in `lib/Settings/register.d/hr-onboarding.json`, the rules in `lib/Standards/rules/labour.json` with predicates in `lib/Standards/Checks/NlOffboardingChecks.php`, and the UI in `src/manifest.json` pages — with no offboarding-specific PHP controller, service, guard class, or custom Vue component
- @e2e exclude declarative config + corpus data with no bespoke runtime surface; covered by PHPUnit predicate tests and the shared CnPageRenderer library tests (app-level e2e suite tracked by active change hrmq-test-coverage-baseline)

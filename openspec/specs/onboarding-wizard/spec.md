---
capability: onboarding-wizard
status: in-progress
built_by: openspec/changes/onboarding-wizard-mvp
---

# onboarding-wizard Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [onboarding-wizard-mvp](../../changes/onboarding-wizard-mvp/) _(active)_ — new `Onboarding` schema in a new `hr-onboarding` fragment with a simplified declarative `aangenomen → … → afgerond` lifecycle (cancellable pre-afgerond, checklist-gated by rule checks — no guard classes), 3 new machine-checkable NL rules (WID check, proeftijd bewaking, loonheffingenverklaring), and onboarding pages under the new ADR-001 `Onboarding & ATS` menu group (kind: config)

## Purpose

Give hrmq its first onboarding surface: one `Onboarding` case per hire with a
deterministic, declarative lifecycle whose milestones (contract signed, data
validated, ready for first workday, proeftijd running, completed) are gated by
concrete checklist fields — `contractSigned`, `widCheckDone`, `bsnValidated`,
`ibanVerified`, `itProvisioned`, `pensioenAangemeld` — documented on the
transitions and enforced by audit rules (write-time guard wiring is owned by
the active `hrmq-rule-compliance-enforcement` change). Three machine-checkable
NL rules cover the WID identity check before the first workday, BW 7:652
proeftijd limits and overdue-proeftijd bewaking, and the loonheffingenverklaring
before the first payroll run. MVP-scoped from the 2026-05 `spec/onboarding-wizard`
draft; market grounding: Spectr insight `hrmq-insight-ranked-buildlist`
(onboarding rank 6/7), the draft's 8.5/10 case-management demand score, and
the round-1 finding that Krip, Personio and BambooHR all ship onboarding
(competitive parity). The wizard stepper UI (custom nc-vue widget), BSN/IBAN
validation services, IT auto-provisioning and the reminder/escalation engine
are explicitly out of scope.

## Requirements

Detailed requirements (REQ-OBW-001 … REQ-OBW-006) are defined in the active
change's delta spec —
[`openspec/changes/onboarding-wizard-mvp/specs/onboarding-wizard/spec.md`](../../changes/onboarding-wizard-mvp/specs/onboarding-wizard/spec.md)
— and are merged here when the change is archived. The umbrella requirement
below anchors the capability until then.

### Requirement: Onboarding is one declarative case object with rule-checked gates (REQ-OBW-000)

The app MUST model onboarding as a single `Onboarding` object per hire in the
`hr-onboarding` register fragment, whose state machine is fully declarative
(`x-openregister-lifecycle`, no imperative workflow code and no bespoke guard
classes) and whose legal gates are versioned, machine-checkable corpus rules
enforced by an auto-discovered `CheckProvider` — never ad-hoc validation
scattered through controllers or Vue components.

#### Scenario: The onboarding surface is declarative

- GIVEN the hrmq codebase at HEAD
- WHEN the onboarding surface is inspected
- THEN the state machine lives in `lib/Settings/register.d/hr-onboarding.json`, the rules in `lib/Standards/rules/labour.json` with predicates in `lib/Standards/Checks/NlOnboardingChecks.php`, and the UI in `src/manifest.json` pages — with no onboarding-specific PHP controller, service, guard class, or custom Vue component
- @e2e exclude declarative config + corpus data with no bespoke runtime surface; covered by PHPUnit predicate tests and the shared CnPageRenderer library tests (app-level e2e suite tracked by active change hrmq-test-coverage-baseline)

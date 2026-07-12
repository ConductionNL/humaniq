---
capability: recruiting-applications
status: in-progress
built_by: openspec/changes/recruiting-ats-basic
---

# recruiting-applications Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [recruiting-ats-basic](../../changes/recruiting-ats-basic/) _(active)_ — new `Application` schema (fragment hr-ats.json; candidate PII inside the application, no Candidate entity) with declarative pipeline lifecycle nieuw→screening→gesprek→aanbod→aangenomen/afgewezen, stored-but-rule-checked `retentionExpiryDate` (AP sollicitatie-richtlijn: 4 weken, 1 jaar met talent-pool-toestemming), 2 machine-checkable AVG rules in a new privacy corpus + `NlAtsChecks`, Applications/ApplicationDetail pages, seeded expired-retention violation (kind: config)

## Purpose

The application half of the recruiting MVP: candidate PII lives inside the
`Application` object (AVG data-minimisation — one object, one retention
clock, one delete; no Candidate entity), the pipeline is a declarative
state machine with `afwijzen` reachable from every active stage, and the
Autoriteit Persoonsgegevens sollicitatie-bewaartermijn (delete at the
latest 4 weeks after rejection; at most 1 year with explicit
`talentPoolOptIn` consent) is a stored, machine-checkable fact enforced by
`nl-ats-retentie-derivatie` / `nl-ats-retentie-verlopen` via
`occ hrmq:rules:audit`. The kanban board (nc-vue widget follow-up), public
career page (portaliq per ADR-046), interviews, offers/e-signature
(docudesk/decidesk follow-up), automatic Employee creation on hire, and the
automatic deletion job are explicitly out of scope.

## Requirements

Detailed requirements (REQ-RCA-001 … REQ-RCA-005) are defined in the active
change's delta spec —
[`openspec/changes/recruiting-ats-basic/specs/recruiting-applications/spec.md`](../../changes/recruiting-ats-basic/specs/recruiting-applications/spec.md)
— and are merged here when the change is archived. The umbrella requirement
below anchors the capability until then.

### Requirement: Candidate data carries an enforced AVG retention clock (REQ-RCA-000)

Candidate PII MUST live only on the `Application` object, and every
rejected application MUST carry a `retentionExpiryDate` derived per the
Autoriteit Persoonsgegevens richtlijn (rejectedDate + 4 weken, or + 1 jaar
with explicit talent-pool consent), with derivation correctness and expiry
overrun surfaced as mandatory violations by the versioned rule corpus.

#### Scenario: Expired candidate data is a mandatory audit violation

- GIVEN an Application with status `afgewezen` whose `retentionExpiryDate` lies in the past
- WHEN `occ hrmq:rules:audit` runs
- THEN a mandatory `nl-ats-retentie-verlopen` violation is reported for that object
- @e2e exclude occ/RuleEngine surface with no UI; covered by PHPUnit over NlAtsChecks, app-level e2e suite tracked by hrmq-test-coverage-baseline

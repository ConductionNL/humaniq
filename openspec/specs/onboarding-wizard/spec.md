---
capability: onboarding-wizard
status: done
built_by: openspec/changes/archive/2026-07-13-onboarding-wizard-mvp
---

# onboarding-wizard Specification

**Status**: done
**Scope**: hrmq
**OpenSpec changes**:
- [onboarding-wizard-mvp](../../changes/archive/2026-07-13-onboarding-wizard-mvp/) _(archived 2026-07-13)_ — new `Onboarding` schema in a new `hr-onboarding` fragment with a simplified declarative `aangenomen → … → afgerond` lifecycle (cancellable pre-afgerond, checklist-gated by rule checks — no guard classes), 3 new machine-checkable NL rules (WID check, proeftijd bewaking, loonheffingenverklaring), and onboarding pages under the new ADR-001 `Onboarding & ATS` menu group (kind: config)

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

### Requirement: A new `hr-onboarding` fragment SHALL define the `Onboarding` schema (REQ-OBW-001)

`lib/Settings/register.d/hr-onboarding.json` (new file, `x-hrmq-fragment: hr-onboarding`, OpenAPI 3.0.0 `components.schemas` shape like `hr-verzuim.json`) declares `Onboarding` (slug `Onboarding`, icon `AccountPlus`, version `0.1.0`, `x-schema-org: schema:Action`) with properties: `employeeId` (string, format uuid, `$ref: Employee`), `startDate` (string, format date), `proeftijdEndDate` (string, format date, nullable), `status` (enum `aangenomen|contract_getekend|gegevens_gevalideerd|gereed_eerste_werkdag|proeftijd_lopend|afgerond|geannuleerd`, default `aangenomen`), checklist booleans `contractSigned`, `widCheckDone`, `bsnValidated`, `ibanVerified`, `itProvisioned`, `pensioenAangemeld` (all default false), `widCheckDate` (string, format date, nullable), `notes` (string, nullable). `required: [employeeId, startDate, status]`. The existing register Repair import picks the fragment up without code changes.

#### Scenario: Schema validates a complete case
- **GIVEN** the imported hrmq register
- **WHEN** an object `{employeeId: "00000000-0000-0000-0000-000000000000", startDate: "2026-08-01"}` is created
- **THEN** creation succeeds with `status` defaulted to `aangenomen` and all six checklist booleans defaulted to `false`

#### Scenario: Unknown status rejected
- **WHEN** an object is written with `status: "not-a-status"`
- **THEN** OpenRegister schema validation rejects it (enum mismatch)

### Requirement: `Onboarding` SHALL carry a declarative lifecycle `aangenomen → … → afgerond` with `annuleren` from every pre-`afgerond` state (REQ-OBW-002)

`configuration.x-openregister-lifecycle` on the schema declares `field: status`, `initial: aangenomen`, `terminal: [afgerond, geannuleerd]`, and transitions `contract_bevestigen` (aangenomen→contract_getekend), `gegevens_valideren` (contract_getekend→gegevens_gevalideerd), `gereed_melden` (gegevens_gevalideerd→gereed_eerste_werkdag), `starten` (gereed_eerste_werkdag→proeftijd_lopend), `afronden` (proeftijd_lopend→afgerond), `annuleren` (aangenomen|contract_getekend|gegevens_gevalideerd|gereed_eerste_werkdag|proeftijd_lopend→geannuleerd). Each forward transition's description documents its checklist gate (`contract_bevestigen`: contractSigned; `gegevens_valideren`: bsnValidated + ibanVerified; `gereed_melden`: widCheckDone + itProvisioned + pensioenAangemeld). **No transition carries a `requires:` guard** — gate enforcement stays in the audit rules because the active `hrmq-rule-compliance-enforcement` change owns write-time guard wiring for compliance-checked schemas.

#### Scenario: Case walks the happy path
- **GIVEN** an `Onboarding` in status `aangenomen`
- **WHEN** the actions `contract_bevestigen`, `gegevens_valideren`, `gereed_melden`, `starten`, `afronden` are executed in order via the OpenRegister lifecycle endpoint
- **THEN** the object's `status` ends as `afgerond`

#### Scenario: Illegal jump is rejected
- **GIVEN** a case in status `aangenomen`
- **WHEN** the `starten` action is attempted
- **THEN** OpenRegister rejects the transition (no `aangenomen→proeftijd_lopend` edge)

#### Scenario: Cancellation from any pre-afgerond state
- **GIVEN** a case in status `gegevens_gevalideerd`
- **WHEN** `annuleren` is executed
- **THEN** status becomes `geannuleerd`; **AND** `annuleren` is not a declared edge from `afgerond` or `geannuleerd`

#### Scenario: No guard classes are declared
- **WHEN** the lifecycle block of the `Onboarding` schema is inspected
- **THEN** no transition declares a `requires:` guard FQCN

### Requirement: The rule corpus SHALL gain three machine-checkable NL onboarding rules (REQ-OBW-003)

`lib/Standards/rules/labour.json` gains `nl-onboarding-wid-check` (framework `nl-wid` — new slug, added to the examples in `lib/Standards/rules/SCHEMA.md`; source Wet op de identificatieplicht art. 15 jo. Wet LB 1964 art. 28 lid 1 onder f; `sourceUrl: https://wetten.overheid.nl/BWBR0006297`; domain `labour`), `nl-onboarding-proeftijd-bewaking` (framework `bw7-10`; source BW art. 7:652; `sourceUrl: https://wetten.overheid.nl/BWBR0005290`; domain `labour`), and `nl-onboarding-loonheffingenverklaring` (framework `nl-loonheffingen`; source Wet LB 1964 art. 29 jo. 28; `sourceUrl: https://wetten.overheid.nl/BWBR0002471`; domain `payroll`). All three: `jurisdiction: NL`, `severity: mandatory`, `machineCheckable: true`. `RuleCatalogue::VERSION` stays `2026-07` (already reflects the current month per SCHEMA.md's year-month granularity).

#### Scenario: Corpus stays loadable and versioned
- **WHEN** `occ hrmq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads without error and reports the three new rules as enforced (each has a CheckProvider predicate)

### Requirement: `NlOnboardingChecks` SHALL enforce WID timing, proeftijd limits, and loonheffingenverklaring presence (REQ-OBW-004)

New auto-discovered provider `lib/Standards/Checks/NlOnboardingChecks.php` (implements `CheckProvider`; does NOT implement `SeedsObjects`). `RuleAuditService::buildRelatedContext()` is extended with `context['related']['Employee']` (per employee: `loonheffingenVerklaringOnFile`, `startDate`, keyed by id/slug) and `context['related']['EmploymentContract']` (per `employeeId`: `type`, `startDate`, `endDate`). Predicates, all keyed on `Onboarding`:

1. **`nl-onboarding-wid-check`** — violates when the case is not `geannuleerd`, `widCheckDone` is not true, and (`startDate` is before the audit-run date OR `status` ∈ {`gereed_eerste_werkdag`, `proeftijd_lopend`, `afgerond`}).
2. **`nl-onboarding-proeftijd-bewaking`** — violates when (a) a contract resolves for `employeeId` and `proeftijdEndDate` exceeds startDate + 1 month for fixed-term contracts shorter than 2 years or startDate + 2 months for permanent/≥ 2-year contracts, or (b) `status` is `proeftijd_lopend` and `proeftijdEndDate` is before the audit-run date (overdue-unclosed). When no contract resolves, branch (a) is skipped (deliberately not fail-closed — contracts are optional MVP data; design.md D3).
3. **`nl-onboarding-loonheffingenverklaring`** — violates when `status` ∈ {`gereed_eerste_werkdag`, `proeftijd_lopend`, `afgerond`} and the resolved Employee lacks `loonheffingenVerklaringOnFile = true`; fail-closed when `employeeId` does not resolve at those statuses.

#### Scenario: Overdue WID check flagged
- **GIVEN** the seed case `onboarding-visser` (startDate `2026-07-01`, `widCheckDone: false`, status `gegevens_gevalideerd`)
- **WHEN** `occ hrmq:rules:audit` runs on any date after 2026-07-01
- **THEN** a `nl-onboarding-wid-check` violation is reported for that case

#### Scenario: Cancelled case never WID-flagged
- **GIVEN** a case in status `geannuleerd` with `widCheckDone: false` and a past `startDate`
- **WHEN** the audit runs
- **THEN** no `nl-onboarding-wid-check` violation is reported for it

#### Scenario: Proeftijd exceeding the fixed-term cap flagged
- **GIVEN** an Onboarding whose employee has a `temporary` EmploymentContract ending within 2 years, with `proeftijdEndDate` more than 1 month after `startDate`
- **WHEN** the audit runs
- **THEN** a `nl-onboarding-proeftijd-bewaking` violation is reported

#### Scenario: Overdue running proeftijd flagged
- **GIVEN** a case in status `proeftijd_lopend` with `proeftijdEndDate` in the past
- **WHEN** the audit runs
- **THEN** a `nl-onboarding-proeftijd-bewaking` violation is reported (a proeftijd must be explicitly closed via `afronden` or `annuleren`)

#### Scenario: Missing loonheffingenverklaring blocks readiness
- **GIVEN** a case in status `gereed_eerste_werkdag` whose resolved Employee has `loonheffingenVerklaringOnFile: false`
- **WHEN** the audit runs
- **THEN** a `nl-onboarding-loonheffingenverklaring` violation is reported

#### Scenario: Seed data yields exactly one new violation
- **GIVEN** the seeded register (REQ-OBW-006)
- **WHEN** the audit runs
- **THEN** `onboarding-visser` flags `nl-onboarding-wid-check` and no other new-rule violation is reported; **AND** no pre-existing check regresses

### Requirement: New onboarding pages SHALL surface the case, its checklist, and the lifecycle under the `Onboarding & ATS` menu group (REQ-OBW-005)

`src/manifest.json` gains (a) a new menu group with the byte-identical tuple `{id: "OnboardingAtsGroup", label: "Onboarding & ATS", icon: "AccountPlus", order: 106}` — pinned for a clean union with the parallel `recruiting-ats-basic` change, which declares the same tuple (design.md D5) — containing an `Onboardings` child ("Onboardings", icon `AccountPlusOutline`); (b) an `Onboardings` index page (route `/onboardings`, register `hrmq`, schema `Onboarding`) with columns `employeeId`, `status`, `startDate`, `proeftijdEndDate`, filter `status`, default sort `startDate` descending; (c) an `OnboardingDetail` detail page (route `/onboardings/:id`) with a "Case" `data` widget (excluding the checklist fields), a "Checklist" `data` widget (including the six gate booleans + `widCheckDate` + `notes` — the SickLeaveCaseDetail grouped-data pattern), a `related` widget (the `$ref` Employee resolves there), a files widget for contract/WID artefacts (`type: integration`, `integrationId: files`), a `lifecycleActions` block exposing Contract bevestigen / Gegevens valideren / Gereed melden / Starten / Afronden / Annuleren (exactly the REQ-OBW-002 edges, no invented ones), and an audit-history sidebar tab. `src/icons.js` registers `AccountPlus` and `AccountPlusOutline`. The manifest validates (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: Detail page drives the lifecycle
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** a case in status `aangenomen` opened on `OnboardingDetail`
- **WHEN** the user executes Contract bevestigen
- **THEN** the page reflects status `contract_getekend` and offers Gegevens valideren

#### Scenario: Menu group tuple matches the coordination pin
- **WHEN** the merged manifest menu is inspected
- **THEN** exactly one group with id `OnboardingAtsGroup` exists, with label `Onboarding & ATS`, icon `AccountPlus`, order `106`

### Requirement: Seed data SHALL provide one mid-flow case exercising the WID violation and one clean completed case (REQ-OBW-006)

`lib/Settings/register.d/hr-seed.json` gains one new-hire Employee seed (`employee-visser`, `startDate: 2026-07-01`, `identityDocumentVerified: false`, `loonheffingenVerklaringOnFile: false`) and two Onboarding seeds referencing employees by slug (the Timesheet `employeeId` mechanism): `onboarding-visser` (status `gegevens_gevalideerd`, `startDate: 2026-07-01`, `proeftijdEndDate: 2026-08-01`, `contractSigned`/`bsnValidated`/`ibanVerified` true, `widCheckDone`/`itProvisioned`/`pensioenAangemeld` false — mid-flow, WID overdue → exactly one violation) and `onboarding-jansen` (status `afgerond`, `startDate: 2024-01-01`, `proeftijdEndDate: 2024-02-01`, all six checklist fields true, `widCheckDate: 2023-12-28` — clean historical case for the existing `employee-jansen`). All identifiers are obvious placeholders. `NlOnboardingChecks` seeds no provider objects: a self-contained sample cannot carry a resolvable `employeeId`.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** the new employee and the two cases exist exactly once

#### Scenario: Completed case is clean
- **GIVEN** the seeded data
- **WHEN** the audit runs
- **THEN** `onboarding-jansen` reports no violation on any of the three new rules

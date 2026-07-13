---
kind: config
---

# Offboarding wizard MVP (deterministic offboarding case with checklist-gated lifecycle)

## Why

The round-3 disposition analysis over the Spectr canonical-feature coverage matrix scored `hrmq-canon-offboarding` at 4/9 coverage and dispositioned it as a build: hrmq now runs the full hire side (onboarding-wizard-mvp, archived 2026-07-13) but has **no departure surface at all** — no schema, no rules, no pages — while Dutch MKB offboarding is exactly the fractured, compliance-heavy tangle the 2026-05 `spec/offboarding-wizard` draft documented (belastingnaheffingen and pensioenfonds-corrections surfacing months after departure, unrevoked access weeks post-departure, incorrectly calculated eindafrekeningen, each absence corrected at 5–10× the cost of proactive handling). The draft specified a 17-state machine over 7 entities (OffboardingStep, Eindafrekening, EquipmentReturn, ExitInterview, Getuigschrift, RetentionTimer), a ~400-line eindafrekening computation engine, UWV WW-melding / pensioenfonds / ZVW submissions via openconnector, and AVG retention timers with cryptographic destruction. This change MVP-scopes that draft down to what the current declarative stack ships well today — mirroring the onboarding-wizard-mvp gold pattern: one `Offboarding` case object with a simplified declarative lifecycle, boolean/date checklist fields for the concrete gates, machine-checkable NL rules for the statutory eindafrekening components (transitievergoeding BW 7:673, verlofsaldo-uitbetaling BW 7:641, getuigschrift BW 7:656, einddatum-consistentie BW 7:667), and index/detail pages under the existing ADR-001 `Onboarding & ATS` menu group.

## What Changes

- **New `Offboarding` schema** in the existing register fragment `lib/Settings/register.d/hr-onboarding.json` (sibling of `Onboarding` — the fragment covers the hire-to-leave case bracket): one offboarding case per departure, referencing the `Employee` (`employeeId` `$ref`), with `lastWorkingDay`, `reason` (enum `opzegging-werknemer|opzegging-werkgever|einde-contract|pensioen|overlijden|vso`), checklist gate fields (`exitGesprekDone` date, `assetsIngeleverd`, `toegangIngetrokken`), eindafrekening fields (`verlofsaldoUitbetaald`, `vakantiegeldAfgerekend`, `transitievergoedingBedrag`, `getuigschriftVerstrekt`), free-text `notes`, and a `status` field.
- **Declarative lifecycle** `aangekondigd → afronding_gepland → eindafrekening_gereed → afgerond` plus `annuleren` from every pre-`afgerond` state (→ `geannuleerd`), via `x-openregister-lifecycle` — a simplification of the draft's 17-state chain. Transition descriptions document which checklist/eindafrekening fields gate each step; **no new lifecycle guard classes** — enforcement stays in the rule checks, because the active `hrmq-rule-compliance-enforcement` change owns the guard-wiring design decision for compliance-checked schemas (the onboarding-wizard-mvp precedent, see design.md D2).
- **Four new machine-checkable NL rules** in the existing labour corpus (`lib/Standards/rules/labour.json`) + a new check provider `lib/Standards/Checks/NlOffboardingChecks.php`:
  - `nl-offboarding-transitievergoeding` — dismissal-initiated departures (reason `opzegging-werkgever`, or `einde-contract`: non-renewal of a fixed-term contract is employer-initiated by default) must have `transitievergoedingBedrag ≥ 0` recorded before the case reaches `afgerond` (checked from `eindafrekening_gereed` onward — BW 7:673); the rule's `parameters` carry the 2026 formula constants as data (1/3 monthly wage per service year; statutory cap in EUR or one annual salary if higher — cap constant ships as a TODO placeholder, see design.md D3);
  - `nl-offboarding-verlofsaldo-uitbetaling` — a case may not be `afgerond` with `verlofsaldoUitbetaald ≠ true` while the employee still has an open leave balance (remaining hours > 0 across their `LeaveBalance` objects — cross-object via the `RuleAuditService` related-context pre-pass; BW 7:641);
  - `nl-offboarding-getuigschrift` — the employer provides a getuigschrift on request (BW 7:656); a completed case without `getuigschriftVerstrekt` is flagged at `recommended` severity (MVP has no "requested" field — the flag prompts HR to verify, see design.md D3);
  - `nl-offboarding-einddatum-consistentie` — on `afgerond` the resolved `Employee.endDate` must equal the case's `lastWorkingDay` (BW 7:667; rule check, **not** an automated write, in the MVP).
  `RuleCatalogue::VERSION` bumps `2026-07.5 → 2026-07.6`.
- **Offboarding pages** under the **existing** `Onboarding & ATS` menu group (`OnboardingAtsGroup`, order 106 — landed with onboarding-wizard-mvp; no new group, no coordination tuple needed): an `Offboardings` index (columns employee/reason/status/lastWorkingDay) and an `OffboardingDetail` page with `lifecycleActions`, grouped Checklist + Eindafrekening data widgets, a files widget for VSO/getuigschrift artefacts, and a related widget. `AccountMinus`/`AccountMinusOutline` are registered in `src/icons.js` (present in `vue-material-design-icons`, unregistered today).
- **Seed data**: 2 Offboarding cases in `lib/Settings/register.d/hr-seed.json` — one mid-flow at `eindafrekening_gereed` for the existing `employee-jansen` with a missing transitievergoeding (exercises the `nl-offboarding-transitievergoeding` violation) and one `afgerond` clean historical case — plus one former-employee seed (`employee-de-boer`, `endDate` set) for the clean case to reference, because neither existing seeded employee can satisfy the einddatum-consistentie rule without corrupting its active-employee story (design.md Seed Data).

### Non-goals

- **No eindafrekening computation engine** — the draft's ~400-line deterministic severance math (verlofuren × uurloon, vakantiegeld 8%, 13e-maand pro rata, transitievergoeding formula, inhoudingen) is follow-up; the MVP records `transitievergoedingBedrag` as auditable input and versions the formula constants as rule `parameters` data so the rule and a later computation service share one source of truth.
- **No UWV WW-melding, pensioenfonds- or ZVW-afmelding submissions** — the openconnector submission flows are follow-up; nothing in the MVP models submission state.
- **No AVG retention timers or cryptographic destruction** — `Employee.identityDocumentRetainedUntil` already exists for retention bookkeeping; the RetentionTimer entity and destruction automation are follow-up.
- **No ExitInterview entity** — `exitGesprekDone` (date) records that the exit interview happened; structured feedback capture and 90-day anonymisation are follow-up.
- **No per-item equipment tracking** — `assetsIngeleverd` records the outcome as a boolean. A **parallel** change this round, `asset-management-mvp`, is authoring the asset data model; this change deliberately takes **no hard dependency** on it — a follow-up rule can cross-check `assetsIngeleverd` against open asset assignments once both have landed (design.md D6).
- **No IT deprovisioning automation or data-export provisioning** — `toegangIngetrokken` records the outcome; OCS automation is follow-up.
- **No automated `Employee.endDate` write on `afgerond`** — the einddatum linkage is a rule check in the MVP; write-side automation follows the `hrmq-rule-compliance-enforcement` guard decision.
- **No new lifecycle guard classes** — checklist gates are documented on the transitions and enforced by audit rules; write-time guard wiring is owned by the active `hrmq-rule-compliance-enforcement` change.
- **No getuigschrift generation machinery here** — the docudesk `getuigschrift` document type already exists (hrmq-docudesk-documents); `getuigschriftVerstrekt` records provision to the leaver.

## Capabilities

### New Capabilities

- `offboarding-wizard`: the Offboarding schema in the hr-onboarding fragment, its simplified declarative lifecycle, the transitievergoeding/verlofsaldo/getuigschrift/einddatum rules and checks, and the offboarding pages under the existing `Onboarding & ATS` menu group.

### Modified Capabilities

<!-- none — the Onboarding schema, existing rules and existing pages are untouched; hr-onboarding.json and labour.json only gain sibling entries -->

## Impact

- `lib/Settings/register.d/hr-onboarding.json` — **modified**: gains the `Offboarding` schema (sibling of `Onboarding`) with `x-openregister-lifecycle`.
- `lib/Standards/rules/labour.json` — 4 new NL rules (`nl-offboarding-transitievergoeding`, `nl-offboarding-verlofsaldo-uitbetaling`, `nl-offboarding-getuigschrift`, `nl-offboarding-einddatum-consistentie`), all framework `bw7-10` / sourceUrl `https://wetten.overheid.nl/BWBR0005290`.
- `lib/Standards/RuleCatalogue.php` — `VERSION` bump `2026-07.5 → 2026-07.6`.
- `lib/Standards/Checks/NlOffboardingChecks.php` — **new** auto-discovered check provider.
- `lib/Service/RuleAuditService.php` — `buildRelatedContext()`: the existing `Employee` index gains `endDate`; a new `LeaveBalance` index (per `employeeId`: entitled/bovenwettelijk/used hours) feeds the verlofsaldo predicate.
- `src/manifest.json` — `Offboardings` child in the existing `OnboardingAtsGroup` + `Offboardings` index + `OffboardingDetail` detail page.
- `src/icons.js` — register `AccountMinus` and `AccountMinusOutline`.
- `lib/Settings/register.d/hr-seed.json` — 1 former-employee seed + 2 Offboarding seeds.
- `lib/Repair/InitializeRegister.php` — no change (fragment glob already picks up hr-onboarding.json).
- Related active changes: `asset-management-mvp` (parallel this round; owns asset data — loose coupling only, design.md D6), `hrmq-rule-compliance-enforcement` (owns write-time guard wiring for compliance-checked schemas).

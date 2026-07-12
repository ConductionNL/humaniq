---
kind: config
---

# Onboarding wizard MVP (deterministic onboarding case with checklist-gated lifecycle)

## Why

The 2026-07-12 market deep-research (logged in Spectr, insight `hrmq-insight-ranked-buildlist` — HR-suite modules, onboarding ranked 6/7 on the build list) and the round-1 competitive sweep found that structured onboarding is competitive parity, not differentiation: Krip (the only generic Nextcloud HR app), Personio and BambooHR all ship an onboarding module, and the 2026-05 `spec/onboarding-wizard` draft scored onboarding case management 8.5/10 on demand — Dutch MKB organisations run "new hire first week" as a tangle of spreadsheets and ad-hoc email, payroll discovers new employees late, nobody can prove a WID identity check happened, and missed proeftijd deadlines lock employers into contracts. hrmq — a Dutch-SMB HRM/payroll app on the OpenRegister data layer — has **no onboarding surface at all**: no schema, no rules, no pages. This change MVP-scopes that 2026-05 draft (which specified a 13-state machine, a 15-step wizard stepper, a reminder/escalation engine, BSN/IBAN validation services and IT auto-provisioning) down to what the current declarative stack ships well today: one `Onboarding` case object with a simplified 6-state lifecycle, boolean/date checklist fields for the concrete legal gates, three machine-checkable NL rules (WID check, proeftijd bewaking, loonheffingenverklaring), and index/detail pages under the ADR-001 `Onboarding & ATS` menu.

## What Changes

- **New `Onboarding` schema** in a new register fragment `lib/Settings/register.d/hr-onboarding.json`: one onboarding case per hire, referencing the `Employee` (`employeeId` `$ref`), with `startDate`, `proeftijdEndDate`, checklist fields for the concrete gates (`contractSigned`, `widCheckDone` + `widCheckDate`, `bsnValidated`, `ibanVerified`, `itProvisioned`, `pensioenAangemeld`), free-text `notes`, and a `status` field.
- **Declarative lifecycle** `aangenomen → contract_getekend → gegevens_gevalideerd → gereed_eerste_werkdag → proeftijd_lopend → afgerond` plus `annuleren` from every pre-`afgerond` state (→ `geannuleerd`), via `x-openregister-lifecycle` — a simplification of the draft's 13-state chain. Transition descriptions document which checklist fields gate each step; **no new lifecycle guard classes** — enforcement stays in the rule checks, because the active `hrmq-rule-compliance-enforcement` change owns the guard-wiring design decision for compliance-checked schemas (see design.md).
- **Three new machine-checkable NL rules** in the existing labour corpus (`lib/Standards/rules/labour.json`) + a new check provider `lib/Standards/Checks/NlOnboardingChecks.php`:
  - `nl-onboarding-wid-check` — the WID identity check must be done on or before the first workday (Wet op de identificatieplicht art. 15 jo. Wet LB 1964 art. 28 lid 1 onder f; new framework slug `nl-wid`);
  - `nl-onboarding-proeftijd-bewaking` — `proeftijdEndDate` must respect the BW 7:652 contract-type limits (max 1 month for fixed-term < 2 years, max 2 months for permanent/≥ 2 years) and a running proeftijd past its end date must be explicitly closed (framework `bw7-10`);
  - `nl-onboarding-loonheffingenverklaring` — the employee's loonheffingenverklaring must be on file before the first payroll run (Wet LB 1964 art. 29 jo. 28; the `Employee.loonheffingenVerklaringOnFile` field already exists in `hr-objects.json`; framework `nl-loonheffingen`).
- **Onboarding pages** under a new **`Onboarding & ATS`** menu group (ADR-001 frozen menu 6, icon `account-plus`): an `Onboardings` index (columns employee/status/startDate/proeftijdEndDate) and an `OnboardingDetail` page with the data widget grouped as a checklist, `lifecycleActions`, a files widget for contract/WID artefacts, and a related widget. `AccountPlus` is registered in `src/icons.js` (not present today). The parallel `recruiting-ats-basic` change declares the **same** menu group — both changes use the identical `{id, label, icon, order}` tuple so the build-time union is clean (coordination pinned in design.md).
- **Seed data**: 2 Onboarding cases in `lib/Settings/register.d/hr-seed.json` — one mid-flow with an overdue WID check (exercises the `nl-onboarding-wid-check` violation) and one `afgerond` clean — plus one new-hire Employee seed for the mid-flow case to reference.

### Non-goals

- **No wizard stepper UI** — the draft's 15-step operator wizard needs a custom nc-vue widget (Vue logic lives in `@conduction/nextcloud-vue`, never in the app); the MVP drives the case through the standard detail page (checklist data widget + lifecycleActions). The stepper is a named follow-up.
- **No BSN modulus-11 (elfproef) or IBAN modulus-97 validation services** — `bsnValidated`/`ibanVerified` capture that the check was performed; validation automation is follow-up.
- **No IT auto-provisioning** (Nextcloud OCS user creation) — `itProvisioned` captures the outcome; the OpenConnector/OCS integration is follow-up.
- **No reminder/escalation engine** and no proeftijd T-7/T-2 notification watcher — the `nl-onboarding-proeftijd-bewaking` audit rule flags overdue proeftijd instead; scheduled notifications are a later n8n/OpenConnector concern.
- **No new lifecycle guard classes** — checklist gates are documented on the transitions and enforced by audit rules; write-time guard wiring is owned by the active `hrmq-rule-compliance-enforcement` change.
- **No WID evidence vault** (document hashing, retention timers, restricted ACLs) — the files widget stores artefacts; `Employee.identityDocumentRetainedUntil` already exists for retention bookkeeping.

## Capabilities

### New Capabilities

- `onboarding-wizard`: the Onboarding schema + fragment, its simplified declarative lifecycle, the WID/proeftijd/loonheffingenverklaring rules and checks, and the onboarding pages under the new `Onboarding & ATS` menu group.

### Modified Capabilities

<!-- none — existing specs are untouched; recruiting-ats-basic is a sibling change sharing only the menu group declaration -->

## Impact

- `lib/Settings/register.d/hr-onboarding.json` — **new** fragment: `Onboarding` schema with `x-openregister-lifecycle`.
- `lib/Standards/rules/labour.json` — 3 new NL rules (`nl-onboarding-wid-check`, `nl-onboarding-proeftijd-bewaking`, `nl-onboarding-loonheffingenverklaring`); `lib/Standards/rules/SCHEMA.md` framework examples gain `nl-wid`.
- `lib/Standards/Checks/NlOnboardingChecks.php` — **new** auto-discovered check provider.
- `lib/Service/RuleAuditService.php` — `buildRelatedContext()` (added by pension-filing-upa-mvp) gains `Employee` and `EmploymentContract` indexes so the loonheffingenverklaring and proeftijd predicates can resolve the referenced employee/contract.
- `src/manifest.json` — new `OnboardingAtsGroup` menu group + `Onboardings` index + `OnboardingDetail` detail page.
- `src/icons.js` — register `AccountPlus` (and `AccountPlusOutline` for the child entry).
- `lib/Settings/register.d/hr-seed.json` — 1 new-hire Employee seed + 2 Onboarding seeds.
- `lib/Repair/InitializeRegister.php` — no change (fragment glob picks up the new file).
- Related active changes: `recruiting-ats-basic` (parallel; declares the identical `Onboarding & ATS` menu group — union must be clean), `hrmq-rule-compliance-enforcement` (owns write-time guard wiring for compliance-checked schemas), `hrmq-ia-navigation-alignment` (owns the full ADR-001 menu realignment; this change adds only the one ADR-001-conformant group).

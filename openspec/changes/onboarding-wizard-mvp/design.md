# Design — onboarding-wizard-mvp

## Context

hrmq has no onboarding surface, but every ingredient exists: `Employee` (in `lib/Settings/register.d/hr-objects.json`) already carries the onboarding-relevant compliance fields (`identityDocumentVerified`, `identityDocumentRetainedUntil`, `loonheffingenVerklaringOnFile`, `startDate`, `nextcloudUserId`); `EmploymentContract` carries `type` (`permanent|temporary|agency|minijob`), `startDate`, `endDate`; the SickLeaveCase/Timesheet/Expense fragments established the declarative `x-openregister-lifecycle` pattern; the labour corpus + `CheckProvider` machinery enforces the existing rules; `RuleAuditService::buildRelatedContext()` (landed with pension-filing-upa-mvp) already builds a cross-type sibling index into `$context['related']`; and ADR-001 freezes **Onboarding & ATS** (`account-plus`) as top-level menu 6.

Source draft: `spec/onboarding-wizard` branch (2026-05-23) — a 13-state Onboarding machine (`aangenomen → contract_verzonden → contract_getekend → id_geverifieerd → bsn_gevalideerd → iban_geverifieerd → … → proeftijd_afgerond`), 7 entity schemas (OnboardingStep, WIDCheck, BSNValidatie, ContractSigningEvent, Reminder, …), a 15-step wizard stepper, a reminder/escalation engine, and eIDAS/docudesk/OCS integrations. Market grounding: Spectr `hrmq-insight-ranked-buildlist` (onboarding rank 6/7 of the HR-suite build list), the draft's own demand scores (8.5/10 case management, 9.0/10 payroll-readiness gate), and the round-1 finding that Krip, Personio and BambooHR all ship onboarding — parity, delivered MVP-thin.

## Goals / Non-Goals

**Goals:** one `Onboarding` case object per hire with a simplified declarative lifecycle; the concrete legal gates (contract signed, WID check, BSN/IBAN validated, IT provisioned, pensioen aangemeld) as checklist fields whose gating is documented on transitions and enforced by audit rules; WID/proeftijd/loonheffingenverklaring as versioned machine-checkable corpus entries; index/detail pages under the ADR-001 `Onboarding & ATS` group.

**Non-Goals:** wizard stepper UI (custom nc-vue widget, follow-up), BSN elfproef / IBAN modulus-97 validation services, IT auto-provisioning via OCS, reminder/escalation engine and proeftijd notification watcher, new lifecycle guard classes, WID evidence vault (hashing/retention/ACL), the draft's OnboardingStep/WIDCheck/BSNValidatie/Reminder side entities.

## Decisions

### D1 — One case object, six states, checklist fields instead of step entities

The draft's per-step `OnboardingStep` rows (15 fixed steps) and evidence side-entities collapse into boolean/date fields on the `Onboarding` case itself: `contractSigned`, `widCheckDone`+`widCheckDate`, `bsnValidated`, `ibanVerified`, `itProvisioned`, `pensioenAangemeld`, plus `notes`. Rationale: the standard `data` widget renders booleans as a perfectly serviceable checklist, every field is auditable through OpenRegister's audit trail, and no new renderer is needed. The draft's 13 statuses — several of which merely mirror one checklist bit (`id_geverifieerd`, `bsn_gevalideerd`, `iban_geverifieerd`, `it_provisioned`) — collapse into six milestone states:

`aangenomen → contract_getekend → gegevens_gevalideerd → gereed_eerste_werkdag → proeftijd_lopend → afgerond`, terminal `afgerond` and `geannuleerd`.

### D2 — Lifecycle transitions document their checklist gates; NO new guard classes

| action | from | to | checklist gate (documented in the transition description) |
|---|---|---|---|
| `contract_bevestigen` | aangenomen | contract_getekend | `contractSigned = true` |
| `gegevens_valideren` | contract_getekend | gegevens_gevalideerd | `bsnValidated = true` AND `ibanVerified = true` |
| `gereed_melden` | gegevens_gevalideerd | gereed_eerste_werkdag | `widCheckDone = true` (WID before first workday), `itProvisioned = true`, `pensioenAangemeld = true` |
| `starten` | gereed_eerste_werkdag | proeftijd_lopend | startDate reached; proeftijd clock runs against `proeftijdEndDate` |
| `afronden` | proeftijd_lopend | afgerond | proeftijd completed without termination |
| `annuleren` | aangenomen, contract_getekend, gegevens_gevalideerd, gereed_eerste_werkdag, proeftijd_lopend | geannuleerd | hire cancelled / no-show / proeftijd termination |

The gates are **descriptions plus audit rules, not write-time guards**. The pension change's `PayrollRunApprovedGuard` precedent would suggest per-transition `requires:` guard classes here (three of them), but the active `hrmq-rule-compliance-enforcement` change owns exactly this design decision — whether compliance-checked schemas get lifecycle hook points for a generic `RuleComplianceGuard` or stay audit-only — and adding three bespoke guards now would pre-empt (and likely be replaced by) that generic mechanism. So: transition descriptions name their gating fields, `NlOnboardingChecks` makes gate violations visible at audit time, and guard wiring arrives with `hrmq-rule-compliance-enforcement`. This is the one deliberate departure from the pension gold pattern.

### D3 — Rules ride the existing corpus + `$context['related']` machinery

All three rules go into the existing `lib/Standards/rules/labour.json` (file exists on HEAD with 6 rules, frameworks `bw7-10`/`nl-poortwachter`). `RuleAuditService::buildRelatedContext()` gains two more indexes: `context['related']['Employee']` (per employee `{id/slug, loonheffingenVerklaringOnFile, startDate}`) and `context['related']['EmploymentContract']` (per `employeeId` the contract `{type, startDate, endDate}`). The predicate contract is already `fn(array $object, array $context): bool` — no RuleEngine change.

- **`nl-onboarding-wid-check`** (per-object): an Onboarding not `geannuleerd`, with `widCheckDone ≠ true`, violates when its `startDate` is in the past **or** its status is at/past `gereed_eerste_werkdag` — the identity check must precede the first workday.
- **`nl-onboarding-proeftijd-bewaking`** (cross-object + per-object): (a) when a contract resolves for the case's `employeeId`, `proeftijdEndDate` must be ≤ startDate + 1 month for fixed-term < 2 years (`endDate` < startDate + 2y) and ≤ startDate + 2 months for permanent/≥ 2 years (BW 7:652); when **no** contract resolves the limit branch is skipped (contracts are optional data for an MVP case — unlike the pension reference rule this is deliberately not fail-closed, see Risks); (b) per-object: a case in `proeftijd_lopend` whose `proeftijdEndDate` is past violates — a proeftijd must be explicitly closed (`afronden`/`annuleren`), never silently outlived.
- **`nl-onboarding-loonheffingenverklaring`** (cross-object): an Onboarding at/past `gereed_eerste_werkdag` (the MVP proxy for "before the first payroll run") whose resolved Employee lacks `loonheffingenVerklaringOnFile = true` violates. Fail-closed on a dangling `employeeId` at those statuses.

Sources (verified against the corpus's existing wetten.overheid.nl citation style): WID art. 15 jo. Wet LB 1964 art. 28 lid 1 onder f → `sourceUrl: https://wetten.overheid.nl/BWBR0006297` (Wet op de identificatieplicht); BW 7:652 → `https://wetten.overheid.nl/BWBR0005290` (same URL as the existing bw7-10 rules); Wet LB 1964 art. 29 jo. 28 → `https://wetten.overheid.nl/BWBR0002471`. New framework slug `nl-wid` (frameworks are opaque strings to `RuleCatalogue`; `SCHEMA.md` examples gain the slug); the other two reuse existing slugs `bw7-10` and `nl-loonheffingen`. `RuleCatalogue::VERSION` stays `2026-07` (already bumped this month; SCHEMA.md's version has year-month granularity).

### D4 — `schema:Action`, Dutch domain statuses, dates as plain inputs

Schema.org annotation is **`schema:Action`** (a workflow act with a lifecycle — the PensionFiling/Timesheet precedent), not the draft's `schema:Event`. Status values keep the draft's Dutch snake_case milestone names (trimmed per D1). `startDate` duplicates the hire date on the case (the case is the workflow record; `Employee.startDate` stays canonical for payroll — the seed keeps them equal) and `proeftijdEndDate` is authoritative input, not derived: BW 7:652 caps it but the actual clause is contractual (and may be absent — proeftijd is void for contracts ≤ 6 months); derivation-correctness is what `nl-onboarding-proeftijd-bewaking` checks, mirroring the stored-not-computed WVP milestone precedent.

### D5 — `Onboarding & ATS` menu group is declared identically to `recruiting-ats-basic` (parallel-change coordination)

A **parallel** change, `recruiting-ats-basic`, declares the same ADR-001 menu-6 group. Both changes MUST declare the byte-identical group tuple so the build-time menu union is clean:

```json
{ "id": "OnboardingAtsGroup", "label": "Onboarding & ATS", "icon": "AccountPlus", "order": 106 }
```

Derivation: id follows the `<Domain>Group` convention (`PayrollGroup`, `ExpensesGroup`); label and icon are ADR-001 menu 6 verbatim (`account-plus` → PascalCase `AccountPlus`); order 106 is the menu-6 provisional slot between `VerlofVerzuimGroup` (105) and `ExpensesGroup` (110), matching ADR-001's 5→6→7 sequence — **verified byte-identical against the tuple `recruiting-ats-basic` pins in its design.md D6** (final ordering of all ADR-001 menus is owned by the active `hrmq-ia-navigation-alignment` change and deliberately not solved here). Each change contributes only its own children (`Onboardings` here; vacatures/kandidaten there); whichever merges second unions its child into the existing group and re-verifies `npm run check:manifest`. `AccountPlus` is not in `src/icons.js` today and must be registered (`vue-material-design-icons/AccountPlus.vue` exists in the package) — a double registration from both changes is an idempotent import collision handled by normal merge conflict resolution.

### D6 — Wizard stepper is a named follow-up, not a degraded inline attempt

The draft's stepper (15 steps, lock icons, greyed future steps) is a genuinely new widget type; per the vue-logic-lives-in-nc-vue rule it must be built in `@conduction/nextcloud-vue` (e.g. `CnStepperWidget`) and consumed via the manifest, never hand-rolled in hrmq. The MVP detail page (checklist data widget + lifecycleActions) is the functional equivalent minus the visual metaphor. Follow-up spec: `onboarding-stepper-widget` (nc-vue first, then a manifest widget swap here).

### Declarative-vs-imperative decision (ADR-031)

| behaviour | path | rationale |
|---|---|---|
| Onboarding state machine | **declarative** `x-openregister-lifecycle` on the schema | ADR-031 default; renderer ships `lifecycleActions` widget |
| Checklist gates on transitions | **declarative descriptions + audit rules** — deliberately NO guard classes | guard wiring for compliance-checked schemas is owned by the active `hrmq-rule-compliance-enforcement` change; bespoke guards here would pre-empt its generic mechanism |
| WID / proeftijd / loonheffingenverklaring rules | imperative **CheckProvider** methods (`NlOnboardingChecks`) | domain-rule evaluation over the versioned corpus — the established ADR-031 exception this app already uses for all rules |
| Employee/EmploymentContract sibling index | imperative extension of the existing `buildRelatedContext()` pre-pass | pension-filing-upa-mvp precedent; the predicate contract already carries `$context` |
| Checklist / case UI | declarative manifest widgets (`data` ×2, `related`, files `integration`, `lifecycleActions`) | all exist in the vendored manifest schema; SickLeaveCaseDetail is the structural template |
| Wizard stepper | **out of scope** (follow-up nc-vue widget) | Vue logic lives in nc-vue, never in the app |

## Schema (new fragment `lib/Settings/register.d/hr-onboarding.json`)

OpenAPI 3.0.0 `components.schemas` fragment shape (like `hr-verzuim.json`), `x-hrmq-fragment: hr-onboarding`, one schema **`Onboarding`** (slug `Onboarding`, icon `AccountPlus`, version `0.1.0`, `x-schema-org: schema:Action`):

- `employeeId` — string, format uuid, `$ref: Employee`, **required**. The hire this case onboards.
- `startDate` — string, format date, **required**. First workday; anchor for the WID rule.
- `proeftijdEndDate` — string, format date, nullable. End of the probationary period (BW 7:652-capped, contractual input — D4); null when no proeftijd was agreed.
- `status` — enum `aangenomen|contract_getekend|gegevens_gevalideerd|gereed_eerste_werkdag|proeftijd_lopend|afgerond|geannuleerd`, default `aangenomen`, **required**, governed by the lifecycle (D2).
- `contractSigned` — boolean, default false. Signed employment contract on file (artefact in the files widget).
- `widCheckDone` — boolean, default false. WID identity check performed (original document seen; copy stored per Wet LB 1964 art. 28 lid 1 onder f).
- `widCheckDate` — string, format date, nullable. When the WID check was performed; must be on or before `startDate`.
- `bsnValidated` — boolean, default false. BSN checked (elfproef automation is a non-goal; this records the outcome).
- `ibanVerified` — boolean, default false. IBAN verified (modulus-97 automation is a non-goal).
- `itProvisioned` — boolean, default false. Nextcloud/IT account provisioned (OCS automation is a non-goal).
- `pensioenAangemeld` — boolean, default false. Hire registered with the pension fund/administrator.
- `notes` — string, nullable. Free-text case notes.

`required: [employeeId, startDate, status]`. Lifecycle in `configuration.x-openregister-lifecycle` (`field: status`, `initial: aangenomen`, `terminal: [afgerond, geannuleerd]`) with the D2 transitions; **no** transition carries a `requires:` guard (D2).

## New corpus rules (labour.json)

| id | framework | source | statement (short) | severity | machineCheckable |
|---|---|---|---|---|---|
| `nl-onboarding-wid-check` | `nl-wid` (new slug) | Wet op de identificatieplicht art. 15 jo. Wet LB 1964 art. 28 lid 1 onder f | The employer verifies the new hire's identity from an original WID document on or before the first workday and retains a copy; an onboarding case whose start date has passed (or that reports ready-for-first-workday) without `widCheckDone` violates | mandatory | true |
| `nl-onboarding-proeftijd-bewaking` | `bw7-10` | BW art. 7:652 | A proeftijd may not exceed 1 month for fixed-term contracts shorter than 2 years and 2 months for permanent/≥ 2-year contracts (deviations only by CAO); a running proeftijd past its end date must be explicitly closed | mandatory | true |
| `nl-onboarding-loonheffingenverklaring` | `nl-loonheffingen` | Wet LB 1964 art. 29 jo. 28 | The employee's loonheffingenverklaring (gegevens voor de loonheffingen) must be on file before the first payroll run; a ready-or-started onboarding whose Employee lacks `loonheffingenVerklaringOnFile` violates | mandatory | true |

WID + proeftijd: `domain: labour`; loonheffingenverklaring: `domain: payroll` (rule `domain` is per-rule; the labour.json file-level domain key stays `labour` — the nl-wvp precedent shows file and rule domains may differ across files). `jurisdiction: NL` for all three; sourceUrls per D3. Checks live in the **new** auto-discovered provider `lib/Standards/Checks/NlOnboardingChecks.php` (implements `CheckProvider`, does **not** implement `SeedsObjects` — an onboarding sample cannot carry a resolvable `employeeId` cross-reference; the pension precedent): all three predicates keyed on `Onboarding`, the proeftijd and loonheffingenverklaring ones reading `context['related']`.

## Manifest delta

- `OnboardingAtsGroup` menu group per D5 (id/label/icon/order pinned for the recruiting-ats-basic union), child `Onboardings` ("Onboardings", icon `AccountPlusOutline`).
- `Onboardings` (new index page, route `/onboardings`): register `hrmq`, schema `Onboarding`, columns `employeeId`, `status`, `startDate`, `proeftijdEndDate`; filters `status`; default sort `startDate` desc.
- `OnboardingDetail` (new detail page, route `/onboardings/:id`): a `data` widget "Case" (columns 2, excluding the checklist booleans/notes); a `data` widget "Checklist" (include: the six gate booleans + `widCheckDate` + `notes`) — the grouped-data-widget pattern SickLeaveCaseDetail uses for its Poortwachter milestones; a `related` widget (the `$ref` Employee resolves there); a files `integration` widget titled for contract + WID artefacts (`type: integration`, `integrationId: files` — the SickLeaveCaseDetail shape); `lifecycleActions` bound to the D2 transitions (labels Contract bevestigen / Gegevens valideren / Gereed melden / Starten / Afronden / Annuleren); audit-history sidebar tab.
- `src/icons.js` registers `AccountPlus` + `AccountPlusOutline` (present in `vue-material-design-icons`, unregistered today — unregistered names fall back to help-circle).
- `npm run check:manifest` must stay green.

## Seed Data (ADR-001)

`hr-seed.json` today seeds one Employee (`employee-jansen`, startDate 2024-01-01, `identityDocumentVerified: true`, `loonheffingenVerklaringOnFile: true`). Onboarding seeds reference employees by slug (the Timesheet `employeeId` mechanism):

- **1 new Employee seed** — `employee-visser` (`employeeNumber: EMP-0004`, Noa Visser, `startDate: 2026-07-01`, `taxTableColor: wit`, `identityDocumentVerified: false`, `loonheffingenVerklaringOnFile: false`): a fresh hire mid-onboarding, consistent with the mid-flow case below.
- **2 Onboarding seeds**:
  1. `onboarding-visser` — `employeeId: employee-visser`, `startDate: 2026-07-01`, `proeftijdEndDate: 2026-08-01`, status `gegevens_gevalideerd`, `contractSigned: true`, `bsnValidated: true`, `ibanVerified: true`, `widCheckDone: false`, `itProvisioned: false`, `pensioenAangemeld: false` — startDate is past and the WID check never happened → exercises the `nl-onboarding-wid-check` violation (and only that rule: status is pre-`gereed_eerste_werkdag`, so the loonheffingenverklaring rule stays silent; proeftijd is within the 1-month BW cap and status is not `proeftijd_lopend`).
  2. `onboarding-jansen` — `employeeId: employee-jansen`, `startDate: 2024-01-01`, `proeftijdEndDate: 2024-02-01`, status `afgerond`, all six checklist fields `true`, `widCheckDate: 2023-12-28`, notes text — the clean, fully-walked historical case; jansen's existing `loonheffingenVerklaringOnFile: true` keeps rule 3 green.

No EmploymentContract seeds exist, so the proeftijd limit branch is skipped for both cases (D3 — not fail-closed) and neither flags `nl-onboarding-proeftijd-bewaking`. All identifiers are obvious placeholders. Net expected audit delta on seed data: exactly one new violation (`nl-onboarding-wid-check` on `onboarding-visser`); no pre-existing check may regress.

## Risks / Trade-offs

- **Gates are advisory until guard wiring lands**: a user can execute `gereed_melden` with `widCheckDone: false`; the audit rule flags it but nothing blocks the write. Deliberate (D2) — the alternative (three bespoke guard classes) collides with `hrmq-rule-compliance-enforcement`'s pending generic-guard decision. When that change lands its mechanism, the transition descriptions already name the exact fields to wire.
- **Proeftijd limit branch is skipped without a contract** (not fail-closed): an Onboarding for an employee without an `EmploymentContract` object gets no BW 7:652 limit check. Trade-off: fail-closed would flag every contract-less MVP case (contracts are optional data today). The overdue-unclosed branch is per-object and always active. Tightening to fail-closed is a check-only change once contract records are mandatory.
- **BW 7:652 nuances not modelled**: proeftijd is void for contracts ≤ 6 months and CAO deviations exist; the MVP checks only the two headline caps. The rule statement records the fuller obligation so tightening is check-only.
- **"Before first payroll run" proxied by status**: rule 3 anchors on `gereed_eerste_werkdag`/`proeftijd_lopend`/`afgerond` rather than actual PayrollRun membership (no per-employee run lines exist in the data model yet). Recorded in the rule statement; tightening later is check-only.
- **Menu-group race with recruiting-ats-basic**: if the other change lands a different tuple, `check:manifest` still passes (two groups) but the IA breaks. Mitigation: D5 pins the tuple with a deterministic derivation; whichever change merges second reconciles to one group in conflict resolution.
- **Status duplicates checklist truth**: a case can claim `contract_getekend` while `contractSigned` is false (audit-visible). Accepted — the same prepare-early/progress-late gap the pension change accepted, kept visible by the rules.

## Open Questions

- None blocking. Stepper widget (`onboarding-stepper-widget` in nc-vue), BSN/IBAN validation services, OCS provisioning and the reminder engine are named follow-ups (Non-Goals); write-time gate enforcement follows `hrmq-rule-compliance-enforcement`.

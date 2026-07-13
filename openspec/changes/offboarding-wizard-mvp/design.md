# Design — offboarding-wizard-mvp

## Context

hrmq at HEAD (11 merged round-2/3 builds) has every ingredient except the departure case itself: `Employee` (in `lib/Settings/register.d/hr-objects.json`) carries `endDate`, `identityDocumentRetainedUntil` and `loonheffingenVerklaringOnFile`; the `hr-onboarding.json` fragment exists with the `Onboarding` schema and its checklist-gated declarative lifecycle (onboarding-wizard-mvp, archived 2026-07-13); `LeaveBalance` (in `hr-leave.json`) carries `entitledHours`/`bovenwettelijkHours`/`usedHours` per employee-year (note: **no stored `remainingHours` field** — remaining is arithmetic); the labour corpus (`lib/Standards/rules/labour.json`, 15 rules) + `CheckProvider` machinery enforces the existing rules at `RuleCatalogue::VERSION = 2026-07.5`; `RuleAuditService::buildRelatedContext()` already builds `Employee` (loonheffingenVerklaringOnFile/startDate), `EmploymentContract` and `OrgUnit` indexes into `$context['related']`; the `OnboardingAtsGroup` menu group (order 106) exists in `src/manifest.json` with Onboardings/Vacatures/Sollicitaties children; and the docudesk document flow (hrmq-docudesk-documents) already generates `getuigschrift` PDFs via `occ hrmq:documents:generate`.

Source draft: `spec/offboarding-wizard` branch (2026-05) — a 17-state Offboarding machine, 7 entity schemas (OffboardingStep, Eindafrekening, EquipmentReturn, ExitInterview, Getuigschrift, RetentionTimer, …), a deterministic eindafrekening computation service, UWV/pensioenfonds/ZVW submission flows, AVG retention timers with cryptographic destruction, and eIDAS/docudesk/OCS integrations. Market grounding: Spectr canonicalFeature `hrmq-canon-offboarding` at 4/9 coverage, dispositioned as a build by the round-3 disposition analysis; the draft's own problem statement (manual departure handling corrected at 5–10× cost). The draft is **modernised against HEAD**, not applied verbatim — several of its claims are stale or wrong (it targets a `lib/Settings/hrmq_register.json` that does not exist, uses snake_case field names against hrmq's camelCase convention, asserts transitievergoeding applies to `opzegging_werknemer` — legally false, BW 7:673 lid 1 — and cites a ≈ €202,000 2026 cap that is implausible against the indexed series).

## Goals / Non-Goals

**Goals:** one `Offboarding` case object per departure with a simplified declarative lifecycle; the concrete gates (exit interview held, assets returned, access revoked) and eindafrekening components (verlofsaldo paid out, vakantiegeld settled, transitievergoeding recorded, getuigschrift provided) as checklist fields whose gating is documented on transitions and enforced by audit rules; the four statutory obligations as versioned machine-checkable corpus entries with the transitievergoeding formula constants as rule `parameters` data; index/detail pages under the existing ADR-001 `Onboarding & ATS` group.

**Non-Goals:** eindafrekening computation engine, UWV WW-melding / pensioenfonds / ZVW submissions, AVG retention timers + cryptographic destruction, ExitInterview/EquipmentReturn/RetentionTimer side entities, per-item asset tracking (parallel `asset-management-mvp` owns asset data — loose coupling only, D6), IT deprovisioning / data-export automation, automated `Employee.endDate` writes, new lifecycle guard classes, getuigschrift generation machinery (docudesk path exists).

## Decisions

### D1 — One case object, five states, checklist fields instead of step entities

The draft's 17 statuses (many mirroring one step's completion: `exit_interview`, `equipment_geretourneerd`, `it_accounts_deactiveren`, `getuigschrift_opstellen`, …) and its per-step `OffboardingStep` rows collapse into boolean/date fields on the `Offboarding` case itself, exactly as onboarding-wizard-mvp D1 collapsed the hire side: `exitGesprekDone` (date), `assetsIngeleverd`, `toegangIngetrokken`, `verlofsaldoUitbetaald`, `vakantiegeldAfgerekend`, `transitievergoedingBedrag` (number), `getuigschriftVerstrekt`, plus `notes`. The standard `data` widget renders these as a serviceable checklist, every field is auditable through OpenRegister's audit trail, and no new renderer is needed. The milestone states are:

`aangekondigd → afronding_gepland → eindafrekening_gereed → afgerond`, terminal `afgerond` and `geannuleerd`.

The draft's ten-value `reden` enum collapses to six MVP reasons: `opzegging-werknemer`, `opzegging-werkgever` (covers vergunning + ontbinding routes), `einde-contract`, `pensioen`, `overlijden`, `vso` (vaststellingsovereenkomst). `ontslag_op_staande_voet` and `proeftijd_beëindigd` fold into `opzegging-werkgever` with `notes` (their distinct fiscal treatment is computation-engine territory, a non-goal).

### D2 — Lifecycle transitions document their checklist gates; NO new guard classes

| action | from | to | gate (documented in the transition description) |
|---|---|---|---|
| `afronding_plannen` | aangekondigd | afronding_gepland | lastWorkingDay confirmed; exit gesprek + asset return + access revocation planned (no field gate) |
| `eindafrekening_gereedmelden` | afronding_gepland | eindafrekening_gereed | `verlofsaldoUitbetaald = true` when an open leave balance remains, `vakantiegeldAfgerekend = true`, `transitievergoedingBedrag` recorded for dismissal-initiated reasons |
| `afronden` | eindafrekening_gereed | afgerond | `exitGesprekDone` recorded, `assetsIngeleverd = true`, `toegangIngetrokken = true`, `getuigschriftVerstrekt` on request; `Employee.endDate` equals `lastWorkingDay` |
| `annuleren` | aangekondigd, afronding_gepland, eindafrekening_gereed | geannuleerd | departure withdrawn (opzegging ingetrokken / VSO niet getekend) |

The gates are **descriptions plus audit rules, not write-time guards** — the same deliberate deviation onboarding-wizard-mvp D2 made and for the same reason: the active `hrmq-rule-compliance-enforcement` change owns whether compliance-checked schemas get a generic `RuleComplianceGuard` hook, and bespoke guards here would pre-empt it. Transition descriptions name their gating fields, `NlOffboardingChecks` makes gate violations visible at audit time, and guard wiring arrives with that change.

### D3 — Rules ride the existing corpus + `$context['related']` machinery; formula constants are rule `parameters` data

All four rules go into `lib/Standards/rules/labour.json` under framework `bw7-10` (`sourceUrl: https://wetten.overheid.nl/BWBR0005290` — the existing bw7-10 citation), `jurisdiction: NL`, `machineCheckable: true`. `RuleAuditService::buildRelatedContext()` changes: the existing `Employee` index gains `endDate`; a **new** `LeaveBalance` index `related.LeaveBalance.byEmployeeId` maps each `employeeId` to its list of `{leaveType, year, entitledHours, bovenwettelijkHours, usedHours}` rows. The predicate contract is already `fn(array $object, array $context): bool` — no RuleEngine change.

- **`nl-offboarding-transitievergoeding`** (per-object, `severity: mandatory`): an Offboarding with a dismissal-initiated `reason` and `status ∈ {eindafrekening_gereed, afgerond}` violates when `transitievergoedingBedrag` is not a number ≥ 0 — the transitievergoeding must be recorded before the case completes (BW 7:673). Dismissal-initiated = `{opzegging-werkgever, einde-contract}` (non-renewal of a fixed-term contract is employer-initiated by default; whether the employee declined a suitable renewal is not decidable from MVP fields — recorded in the statement, tightening is check-only). `opzegging-werknemer`, `pensioen`, `overlijden` are statutorily exempt (BW 7:673 lid 1/lid 7); `vso` severance is negotiated, not statutory. The rule's `parameters` carry the formula constants **as data** so the rule and a later computation engine share one source of truth: `wageFractionPerServiceYear: "1/3"` (BW 7:673 lid 2), `capEur: 98000` — **TODO placeholder**: 98000 is the published 2025 indexed cap; the 2026 figure from the Regeling indexering transitievergoeding could not be verified confidently at authoring time and MUST be replaced with the published 2026 number (source wetten.overheid.nl) during implementation — plus `capAlternative: "one gross annual salary when higher than capEur"` (BW 7:673 lid 3).
- **`nl-offboarding-verlofsaldo-uitbetaling`** (cross-object, `severity: mandatory`): a case in `afgerond` violates when `verlofsaldoUitbetaald ≠ true` while the resolved employee's open leave balance is positive — remaining per LeaveBalance row = `entitledHours + bovenwettelijkHours − usedHours`, open saldo = Σ max(0, remaining) over the rows in `related.LeaveBalance.byEmployeeId` (BW 7:641: untaken leave is paid out at end of employment). When **no** balance resolves for the employee the check is skipped (deliberately not fail-closed — LeaveBalance rows are optional data, mirroring the onboarding proeftijd-cap precedent).
- **`nl-offboarding-getuigschrift`** (per-object, `severity: recommended`): a case in `afgerond` with `getuigschriftVerstrekt ≠ true` is flagged. BW 7:656 obliges the employer **on request** and the MVP has no "requested" field, so the flag is advisory — it prompts HR to verify whether the leaver asked for one (severity `recommended`, the `nl-contract-schriftelijk` precedent). Generation itself rides the existing docudesk `getuigschrift` document type (hrmq-docudesk-documents); a follow-up can tighten the predicate to cross-check a `generated` GeneratedDocument.
- **`nl-offboarding-einddatum-consistentie`** (cross-object, `severity: mandatory`): a case in `afgerond` violates when the resolved `Employee.endDate` does not equal `lastWorkingDay` (missing/empty `endDate` counts as a mismatch); fail-closed when `employeeId` does not resolve at `afgerond`. Rationale for `mandatory` despite the "SHOULD" phrasing of the linkage: `endDate` is the statutory reference date for loonaangifte, pensioenafmelding and the 5-year ID-retention clock (`nl-id-bewaarplicht-5jaar` derives from it) — a completed departure whose HR master record still says "employed" is a data error, not a style preference. The MVP checks the linkage; it does **not** write `Employee.endDate` (automation follows the `hrmq-rule-compliance-enforcement` guard decision).

`RuleCatalogue::VERSION` bumps `2026-07.5 → 2026-07.6` (SCHEMA.md: bump on any corpus change; the sub-version scheme is established). If the parallel `asset-management-mvp` bumps first, this change takes the next free increment at merge time — the constant is a single line, union-trivial.

### D4 — `schema:Action`, Dutch domain statuses, `transitievergoedingBedrag` stored-not-computed

Schema.org annotation is **`schema:Action`** (a workflow act with a lifecycle — the Onboarding/PensionFiling/Timesheet precedent). Status values are Dutch snake_case milestone names, reason values Dutch kebab-case (matching the given MVP vocabulary). `lastWorkingDay` is the case's anchor date; the draft's separate `einddatum`/`laatste_werkdag` pair collapses to one field, with `Employee.endDate` staying the canonical contract end date — the einddatum-consistentie rule keeps the two honest. `transitievergoedingBedrag` is authoritative **input**, not derived: the statutory formula needs service years, wage components per the Besluit loonbegrip and cap indexation that the MVP data model cannot compute honestly; derivation-correctness arrives with the follow-up computation engine, and the rule meanwhile enforces *presence* for dismissal-initiated departures (the stored-not-computed WVP-milestone precedent). `exitGesprekDone` is a date (not boolean) so the audit trail shows *when* the exit interview happened — mirroring `widCheckDate` on the hire side.

### D5 — Pages join the EXISTING `OnboardingAtsGroup`; no new menu group

Unlike onboarding-wizard-mvp (which had to pin a coordination tuple with the then-parallel `recruiting-ats-basic`), the `OnboardingAtsGroup` (`{id: OnboardingAtsGroup, label: "Onboarding & ATS", icon: AccountPlus, order: 106}`) **exists on HEAD** with three children. This change only appends an `Offboardings` child (`{id: Offboardings, label: "Offboardings", icon: AccountMinusOutline, route: Offboardings}`) — the group tuple is untouched, so no cross-change coordination is needed. ADR-001's menu 6 covers the hire-to-leave employee flow; the full menu realignment stays owned by `hrmq-ia-navigation-alignment`. `AccountMinus` and `AccountMinusOutline` exist in `vue-material-design-icons` (verified in node_modules) but are not in `src/icons.js` today and must be registered (unregistered names fall back to help-circle).

### D6 — Loose coupling to the parallel `asset-management-mvp`; assets are a boolean here

A **parallel** change this round is authoring the asset data model (`asset-management-mvp`). This change deliberately references assets **loosely**: `assetsIngeleverd` is a self-contained boolean recording that the leaver returned company property — no `$ref`, no schema name, no rule reads asset objects. Rationale: a hard dependency would couple two same-round changes' merge order and break whichever lands first; the boolean is honest MVP scope either way. Named follow-up once both land: a cross-object rule (or a tightened `nl-offboarding-*` predicate) that checks `assetsIngeleverd` against the employee's open asset assignments via a related-context index — check-only, no schema change.

### D7 — Wizard/dashboard UI stays out; the detail page is the surface

Per the vue-logic-lives-in-nc-vue rule, no custom Vue components: the standard detail page (Case + Checklist + Eindafrekening data widgets, `lifecycleActions`, related, files) is the functional equivalent of the draft's case dashboard. If the `onboarding-stepper-widget` follow-up ever lands in `@conduction/nextcloud-vue`, this page adopts it via a manifest widget swap.

### Declarative-vs-imperative decision (ADR-031)

| behaviour | path | rationale |
|---|---|---|
| Offboarding state machine | **declarative** `x-openregister-lifecycle` on the schema | ADR-031 default; renderer ships `lifecycleActions` widget |
| Checklist/eindafrekening gates on transitions | **declarative descriptions + audit rules** — deliberately NO guard classes | guard wiring for compliance-checked schemas is owned by the active `hrmq-rule-compliance-enforcement` change (onboarding-wizard-mvp D2 precedent) |
| Transitievergoeding / verlofsaldo / getuigschrift / einddatum rules | imperative **CheckProvider** methods (`NlOffboardingChecks`) | domain-rule evaluation over the versioned corpus — the established ADR-031 exception this app already uses for all rules |
| Transitievergoeding formula constants | **data** — rule `parameters` in labour.json | one versioned source of truth for the rule today and the computation engine follow-up (nl-wvp `milestoneWeeks` / tijdvakcode precedent) |
| Employee-endDate / LeaveBalance sibling indexes | imperative extension of the existing `buildRelatedContext()` pre-pass | onboarding/pension precedent; the predicate contract already carries `$context` |
| Checklist / case UI | declarative manifest widgets (`data` ×3, `related`, files `integration`, `lifecycleActions`) | all exist in the vendored manifest schema; OnboardingDetail is the structural template |
| Eindafrekening computation, UWV/pensioenfonds submissions, retention timers | **out of scope** (named follow-ups) | need services/integrations the MVP data model cannot carry honestly |

## Schema (new schema in existing fragment `lib/Settings/register.d/hr-onboarding.json`)

Sibling of `Onboarding` under `components.schemas`, **`Offboarding`** (slug `Offboarding`, icon `AccountMinus`, version `0.1.0`, `x-schema-org: schema:Action`):

- `employeeId` — string, format uuid, `$ref: Employee`, **required**. The departing employee.
- `lastWorkingDay` — string, format date, **required**. The employee's last working day; anchor for the einddatum-consistentie rule.
- `reason` — enum `opzegging-werknemer|opzegging-werkgever|einde-contract|pensioen|overlijden|vso`, **required**. Drives transitievergoeding applicability (D3).
- `status` — enum `aangekondigd|afronding_gepland|eindafrekening_gereed|afgerond|geannuleerd`, default `aangekondigd`, **required**, governed by the lifecycle (D2).
- `exitGesprekDone` — string, format date, nullable. Date the exit interview was held; null while pending.
- `assetsIngeleverd` — boolean, default false. Company property returned (per-item tracking is the parallel `asset-management-mvp`'s domain — D6).
- `toegangIngetrokken` — boolean, default false. Nextcloud/IT access revoked (OCS automation is a non-goal).
- `verlofsaldoUitbetaald` — boolean, default false. Open leave balance paid out (BW 7:641; checked cross-object by `nl-offboarding-verlofsaldo-uitbetaling`).
- `vakantiegeldAfgerekend` — boolean, default false. Accrued vakantiegeld settled in the eindafrekening.
- `transitievergoedingBedrag` — number, nullable. Gross statutory transition payment in EUR; required data for dismissal-initiated reasons (D3), null when not applicable. Recorded input, not derived (D4).
- `getuigschriftVerstrekt` — boolean, default false. Getuigschrift provided to the leaver (BW 7:656; generated via the existing docudesk flow).
- `notes` — string, nullable. Free-text case notes.

`required: [employeeId, lastWorkingDay, reason, status]`. Lifecycle in `configuration.x-openregister-lifecycle` (`field: status`, `initial: aangekondigd`, `terminal: [afgerond, geannuleerd]`) with the D2 transitions; **no** transition carries a `requires:` guard (D2).

## New corpus rules (labour.json)

| id | framework | source | statement (short) | severity | machineCheckable |
|---|---|---|---|---|---|
| `nl-offboarding-transitievergoeding` | `bw7-10` | BW art. 7:673 | Dismissal-initiated departures (opzegging-werkgever; einde-contract non-renewal) carry a statutory transitievergoeding of 1/3 monthly wage per service year, capped at the indexed maximum or one annual salary if higher; a case at/past eindafrekening_gereed without a recorded amount ≥ 0 violates | mandatory | true |
| `nl-offboarding-verlofsaldo-uitbetaling` | `bw7-10` | BW art. 7:641 | Untaken leave is paid out at end of employment; a completed case whose employee still holds a positive open leave balance without verlofsaldoUitbetaald violates | mandatory | true |
| `nl-offboarding-getuigschrift` | `bw7-10` | BW art. 7:656 | The employer provides a getuigschrift on the employee's request; a completed case without getuigschriftVerstrekt is flagged for HR verification | recommended | true |
| `nl-offboarding-einddatum-consistentie` | `bw7-10` | BW art. 7:667 | The HR master record's endDate equals the completed case's lastWorkingDay — the statutory reference date for loonaangifte, pension deregistration and retention clocks | mandatory | true |

All four: `domain: labour`, `jurisdiction: NL`, `sourceUrl: https://wetten.overheid.nl/BWBR0005290`. The transitievergoeding rule carries `parameters` per D3 (`wageFractionPerServiceYear`, `capEur` — TODO placeholder 98000 pending the published 2026 figure — `capAlternative`, `dismissalInitiatedReasons: [opzegging-werkgever, einde-contract]`). Checks live in the **new** auto-discovered provider `lib/Standards/Checks/NlOffboardingChecks.php` (implements `CheckProvider`, does **not** implement `SeedsObjects` — an offboarding sample cannot carry a resolvable `employeeId` cross-reference; the onboarding/pension precedent): all four predicates keyed on `Offboarding`, the verlofsaldo and einddatum ones reading `context['related']`.

## Manifest delta

- `Offboardings` child appended to the **existing** `OnboardingAtsGroup` (D5) — group tuple untouched.
- `Offboardings` (new index page, route `/offboardings`): register `hrmq`, schema `Offboarding`, columns `employeeId`, `reason`, `status`, `lastWorkingDay`; filters `status`, `reason`; default sort `lastWorkingDay` desc.
- `OffboardingDetail` (new detail page, route `/offboardings/:id`): a `data` widget "Case" (columns 2, showing reason/status/lastWorkingDay — excluding `employeeId`, the checklist and eindafrekening fields and `notes`); a `data` widget "Checklist" (include: `exitGesprekDone`, `assetsIngeleverd`, `toegangIngetrokken`); a `data` widget "Eindafrekening" (include: `verlofsaldoUitbetaald`, `vakantiegeldAfgerekend`, `transitievergoedingBedrag`, `getuigschriftVerstrekt`, `notes`) — the OnboardingDetail grouped-data pattern; a `related` widget (the `$ref` Employee resolves there); a files `integration` widget titled for VSO/getuigschrift artefacts (`type: integration`, `integrationId: files`); `lifecycleActions` bound to the D2 transitions (labels Afronding plannen / Eindafrekening gereedmelden / Afronden / Annuleren); audit-history sidebar tab.
- `src/icons.js` registers `AccountMinus` + `AccountMinusOutline` (present in `vue-material-design-icons`, unregistered today).
- `npm run check:manifest` must stay green.

## Seed Data (ADR-001)

`hr-seed.json` today (read fresh) seeds Employees `employee-jansen` (the active anchor employee: 2026 payslips, timesheets, leave balance 160+40−56 = 144 h remaining) and `employee-visser` (mid-onboarding new hire). Offboarding seeds reference employees by slug (the Timesheet/Onboarding `employeeId` mechanism):

- **1 new former-employee seed** — `employee-de-boer` (`employeeNumber: EMP-0005`, Jesse de Boer, `startDate: 2019-02-01`, **`endDate: 2026-05-31`**, `taxTableColor: wit`, `identityDocumentVerified: true`, `identityDocumentRetainedUntil: 2031-12-31` — ≥ 5 years past the end year so `nl-id-bewaarplicht-5jaar` stays green — `loonheffingenVerklaringOnFile: true`): a cleanly departed employee. Neither existing employee can anchor the clean case: `employee-jansen` must stay active (his 2026 payslips/attendance would contradict an `endDate`, and he anchors the mid-flow case below) and `employee-visser` is mid-onboarding — an `afgerond` offboarding for either would corrupt its story or flag `nl-offboarding-einddatum-consistentie`.
- **2 Offboarding seeds**:
  1. `offboarding-jansen` — `employeeId: employee-jansen`, `reason: opzegging-werkgever`, `lastWorkingDay: 2026-08-31`, status `eindafrekening_gereed`, `vakantiegeldAfgerekend: true`, `verlofsaldoUitbetaald: false`, `transitievergoedingBedrag: null`, `exitGesprekDone: null`, `assetsIngeleverd: false`, `toegangIngetrokken: false`, `getuigschriftVerstrekt: false` — a dismissal-initiated case that claims its eindafrekening is ready while the transitievergoeding was never recorded → exercises the `nl-offboarding-transitievergoeding` violation (and **only** that rule: verlofsaldo/getuigschrift/einddatum all key on `afgerond`, which this case has not reached — jansen's open 144 h saldo and missing `Employee.endDate` stay silent by design). Departure announced, jansen still working until 31 August — consistent with his active seeds.
  2. `offboarding-de-boer` — `employeeId: employee-de-boer`, `reason: opzegging-werknemer`, `lastWorkingDay: 2026-05-31` (= the employee's `endDate`), status `afgerond`, `exitGesprekDone: 2026-05-28`, `assetsIngeleverd: true`, `toegangIngetrokken: true`, `verlofsaldoUitbetaald: true`, `vakantiegeldAfgerekend: true`, `transitievergoedingBedrag: null` (resignation — statutorily exempt, D3), `getuigschriftVerstrekt: true`, notes text — the clean, fully-walked historical case; no LeaveBalance rows exist for de-boer so the verlofsaldo cross-object branch is skipped, and the einddatum rule resolves `endDate = lastWorkingDay`.

All identifiers are obvious placeholders. Net expected audit delta on seed data: exactly one new violation (`nl-offboarding-transitievergoeding` on `offboarding-jansen`); no pre-existing check may regress (in particular `employee-de-boer` must not flag any Employee-keyed payroll check — hence the retention/loonheffingen fields above).

## Risks / Trade-offs

- **Gates are advisory until guard wiring lands**: a user can execute `afronden` with `assetsIngeleverd: false`; the audit flags it but nothing blocks the write. Deliberate (D2) — same trade as onboarding-wizard-mvp; when `hrmq-rule-compliance-enforcement` lands its mechanism, the transition descriptions already name the exact fields to wire.
- **2026 transitievergoeding cap is a placeholder**: `capEur: 98000` is the published **2025** indexed cap; the 2026 Regeling indexering figure could not be verified confidently at authoring time (the draft's ≈ €202,000 claim is implausible against the 75k→98k indexed series and was discarded). Shipping the placeholder with a TODO in the rule `parameters` is preferable to inventing a number — replacing it is a one-line data change and the predicate (presence ≥ 0) does not depend on it.
- **"Employer-initiated" is approximated by reason**: `einde-contract` is treated as dismissal-initiated (non-renewal owes transitievergoeding since the WAB), though edge cases (employee declined an equivalent renewal) are exempt in law. Recorded in the rule statement; tightening needs fields the MVP does not have.
- **Getuigschrift "on request" proxied by severity**: no `requested` field exists, so the predicate flags every completed case without one — at `recommended` severity so the audit stays honest about mandatory violations. Tightening (a request field, or a docudesk GeneratedDocument cross-check) is check-only.
- **Verlofsaldo branch skipped without LeaveBalance rows** (not fail-closed): a departing employee without balance rows gets no payout check. Trade-off mirrors the onboarding proeftijd-cap decision — fail-closed would flag every balance-less case; tightening is check-only once balances are mandatory data.
- **`RuleCatalogue::VERSION` race with `asset-management-mvp`**: both same-round changes bump the constant. Single-line union; whichever merges second takes the next increment and re-runs the audit (D3).
- **Status duplicates checklist truth**: a case can claim `eindafrekening_gereed` while components are missing (audit-visible — the seed exercises exactly this). Accepted — the same prepare-early/progress-late gap the onboarding and pension changes accepted, kept visible by the rules.

## Open Questions

- None blocking. Eindafrekening computation engine (consuming the D3 `parameters` constants), UWV WW-melding / pensioenfonds / ZVW submission flows, AVG retention timers, the asset cross-check rule (after `asset-management-mvp` lands, D6) and `Employee.endDate` write automation are named follow-ups; write-time gate enforcement follows `hrmq-rule-compliance-enforcement`. The 2026 cap constant must be verified against wetten.overheid.nl during implementation (D3 TODO).

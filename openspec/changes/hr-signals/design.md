# Design — hr-signals

## Context

Verified at HEAD before designing (all fresh reads, not from memory):

- **Corpus**: `labour.json` (15 rules, version `2026-07`, frameworks incl. `bw7-10`, `nl-arbeidstijdenwet`, `hr-org-core`) carries `nl-onboarding-proeftijd-bewaking` — enforced by `NlOnboardingChecks::proeftijdSatisfied()`, which checks the BW 7:652 caps **and** flags a running proeftijd past its end date. `payroll.json` carries `nl-minimumloon-2026` + `nl-minimumuurloon-wet`, both `machineCheckable: true` and both enforced by `NlPayrollChecks` on `EmploymentContract` (`hourlyWage >= 14.71`, positive `hoursPerWeek`). **Grep for `aanzeg`/`keten` across rules + Checks: zero hits.** Consequence (build brief honoured): proeftijd and WML signals would be duplicates — dropped; contract-expiry + aanzegtermijn are the honest pair.
- **`RuleCatalogue::VERSION` is `2026-07.5`** → this change bumps to `2026-07.6` (SCHEMA.md: bump on any corpus change).
- **`RuleEngine`** merges providers additively per object type (`array_merge` in `checks()`), so a new provider MAY key `EmploymentContract` even though `NlPayrollChecks` and `NlDocumentChecks` already do.
- **`RuleAuditService`**: predicates are pure `fn(array $o, array $context): bool`; cross-object facts arrive via context builders. The existing `related.EmploymentContract.byEmployeeId` index **keeps only the last-loaded contract per employee** (documented MVP simplification in `buildRelatedContext()`), so it cannot answer "does a successor contract exist" — a new index is required. Time comes from `new \DateTimeImmutable('today')` (the `NlOnboardingChecks`/`NlVerzuimChecks` convention).
- **`EmploymentContract`** (`hr-objects.json`, schema 0.1.0; register `hrmq_register.json` `info.version` 0.5.0): `type` enum `permanent|temporary|agency|minijob`, `startDate` (required), `endDate` (nullable date), `hourlyWage`, `hoursPerWeek`, `awfTariff`, plus cross-jurisdiction booleans. **No aanzegging field exists** — added here.
- **Dashboard** (`src/manifest.json`, page `Dashboard`, type `dashboard`): six widgets (5 × `stat`, 1 × `object-table`), layout rows y=0/2/4; the `object-table` shape is `{content: {source: {register, schema, filter, order, limit}, columns, rowRoute, viewAllRoute, viewAllLabel, emptyText}}`. Filter grammar (verified in `@conduction/nextcloud-vue` `resolveFilterTokens.js` + `fetchAggregate.js` + `CnObjectListWidget.vue`): `@today` and `@today±Nd` tokens resolve at fetch time, and operator-shaped values (`{gte: …, lte: …}`) serialize as `field[op]` (list) / `filter[field][op]` (aggregate) params. Pages `EmploymentContracts` (index) and `EmploymentContractDetail` (detail) exist for routing.
- **Seeds**: `hr-seed.json` anchors on 2026-07/08 (attendance 2026-07-08…10, leave 2026-08-03…14, reference audit date 2026-07-12/13). Existing contract seeds: `contract-jansen-permanent` (hr-documents.json, permanent) and the `NlPayrollChecks::seedObjects()` sample (`EMP-NL-0001`, permanent). The `Onboarding` seeds reference `employee-visser`/`employee-jansen` only — a new **devries** contract cannot perturb the proeftijd audit through the last-wins `byEmployeeId` index. `employee-devries` is an established dangling-slug placeholder (Timesheet/LeaveBalance/SickLeaveCase/AttendanceRecord seeds all reference it without an Employee object).

## Goals / Non-Goals

**Goals:** two versioned machine-checkable signalling rules (advisory contract-expiry with successor awareness; statutory aanzegtermijn), the `aanzegdOn` field they need, a `NlSignalChecks` provider + `signals` audit context, a dashboard "Aflopende contracten" table, and a seed that demonstrably violates both at the seed anchor without regressing any existing rule.

**Non-Goals:** `x-openregister-notifications` wiring (gate-18 dialect not adopted app-wide — round-1 deferral repeated deliberately), ketenregeling chain counting, WIA/jubilea fuzzy signals, aanzegbrief generation (docudesk follow-up), new pages, duplicating the proeftijd/WML coverage documented in Context.

## Decisions

### D1 — Two rules, two severities, honest framing

- **`nl-signaal-contract-verloopt`** (`severity: recommended`, `machineCheckable: true`, domain `labour`, new framework slug **`hr-signals`** added to SCHEMA.md's examples): *a temporary contract whose `endDate` falls within the next 60 days and that has no successor contract for the same employee is flagged for HR follow-up.* This is monitoring practice, not statute — hence `recommended` (the corpus's advisory tier; there is no `advisory` value in the severity enum) and a practice framework slug rather than misfiling it under `bw7-10`. Source: "HR contract-lifecycle control (aflopend tijdelijk contract)", `sourceUrl: https://www.rijksoverheid.nl/onderwerpen/arbeidsovereenkomst-en-cao/vraag-en-antwoord/wat-is-de-aanzegtermijn` (the government explainer covering the moment the signal protects). `parameters: {"windowDays": 60}` — the window is rule data, not code (the `milestoneWeeks`/`breakTiers` convention).
- **`nl-aanzegtermijn-bewaking`** (`severity: mandatory`, `machineCheckable: true`, framework `bw7-10`, `effectiveDate: "2015-01-01"`): *for a fixed-term contract of six months or longer the employer shall inform the employee in writing, at least one month before `endDate`, whether the contract will be continued (BW art. 7:668 lid 1); a contract past that deadline without a recorded written aanzegging violates.* Source: "BW art. 7:668 lid 1 (aanzegplicht)", `sourceUrl: https://wetten.overheid.nl/BWBR0005290`. `parameters: {"minContractMonths": 6, "noticeMonths": 1}`. Naming mirrors `nl-onboarding-proeftijd-bewaking` (statutory bewaking), while the practice signal keeps the brief's `nl-signaal-*` prefix — the id split *is* the severity split, deliberately.

### D2 — Predicates: window-scoped, vacuous-pass discipline, statute checked strictly

Both predicates key on `EmploymentContract` and use `new \DateTimeImmutable('today')`:

- **contract-verloopt** applies only when `type === 'temporary'` AND `endDate` is a parseable date AND `today <= endDate <= today + windowDays`; everything else passes vacuously (permanent/agency/minijob contracts, open-ended, far-future, or already-expired contracts — an expired contract is an ended employment, not a pending signal). Inside the window it violates unless a **successor** exists in `$context['signals']['contractsByEmployeeId'][employeeId]`: another contract (different object id) with `startDate > this.startDate` AND (`endDate` empty OR `endDate > this.endDate`). Renewal-in-place (someone edits `endDate` forward) also clears the signal by leaving the window.
- **aanzegtermijn** applies only when `type === 'temporary'` AND `startDate`/`endDate` parse AND the contract runs ≥ `minContractMonths` AND `endDate >= today` (live contracts — the same monitoring-window honesty as the expiry signal: the check protects the *upcoming* moment; historical breaches are the audit trail's business, and permanent flags on long-expired contracts would be pure noise, which the statement says out loud). It violates when the deadline `endDate − noticeMonths` has passed and `aanzegdOn` is empty or later than the deadline. Object-local — no context needed.

### D3 — `aanzegdOn` is a recorded fact, not a workflow

One nullable date field (`aanzegdOn`, title "Aanzegd on", description naming BW 7:668 and the one-month deadline; gate-28 title+description discipline) on `EmploymentContract`, schema 0.1.0 → 0.2.0, register 0.5.0 → 0.6.0. HR records the date the written aanzegging went out; *how* it went out (letter, docudesk template, e-mail) is out of scope (Non-Goals). No lifecycle, no boolean pair (`aanzegd: true` without a date would be unverifiable against the deadline arithmetic).

### D4 — New provider `NlSignalChecks` + a dedicated `signals` context

A new provider rather than extending an existing one: `NlPayrollChecks` is payroll math (WML/Awf/30%), `NlDocumentChecks` is document evidence, `NlOnboardingChecks` keys the `Onboarding` case type — HR-moment signalling is its own concern, and the fleet convention is one provider per change/domain (`NlGlPostChecks`, `NlAttendanceChecks`). `RuleEngine` merges per-type providers additively (verified), so keying `EmploymentContract` alongside the others is safe. `RuleAuditService` gains `buildSignalsContext()` → `$context['signals'] = ['contractsByEmployeeId' => [employeeId => list of {id, type, startDate, endDate}]]` — a **full-list** index, because the existing `related.EmploymentContract.byEmployeeId` is documented last-wins and cannot see siblings; it loads once per audit, degrades to an empty index when the schema is absent, and includes each row's object id so the predicate can exclude self. The existing last-wins index is left untouched (its consumer, the proeftijd check, is out of scope here — tightening it is the follow-up its own docblock already names).

### D5 — Dashboard widget mirrors the existing object-table shape exactly

`Dashboard.config.widgets` gains `dash-expiring-contracts` (`type: object-table`, title/label "Aflopende contracten", icon `FileSignOutline` — the EmploymentContract schema icon): `source: {register: "hrmq", schema: "EmploymentContract", filter: {type: "temporary", endDate: {gte: "@today", lte: "@today+60d"}}, order: {endDate: "asc"}, limit: 5}`, columns `employeeId` / `endDate` / `aanzegdOn`, `rowRoute: EmploymentContractDetail`, `viewAllRoute: EmploymentContracts`, `viewAllLabel: "Alles bekijken"`, `emptyText: "Geen aflopende contracten binnen 60 dagen"`. Layout appends `{widgetId: dash-expiring-contracts, gridX: 0, gridY: 9, gridWidth: 12, gridHeight: 5}` — the first free row under the y=4/h=5 table, mirroring `dash-my-recent-hours`. The filter's 60 stays literally in sync with the rule's `windowDays: 60` — noted in the widget's `_note` so a future window change touches both. The page `_note`'s "growth belongs to a later dashboard change" clause is updated to name this change as that growth. No `x-openregister-notifications` anywhere (Non-Goals; gate-18 hard-fails only the *legacy* dialect, but adopting the canonical one app-wide is its own change).

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Rules (`nl-signaal-contract-verloopt`, `nl-aanzegtermijn-bewaking`) | declarative corpus rows (`labour.json`) + SCHEMA.md framework addition | corpus = versioned static data, ADR-031 default for universal facts |
| `aanzegdOn` field | declarative schema delta (`hr-objects.json`) | ADR-031 default |
| Predicates | imperative `NlSignalChecks` provider | the app's established rule-corpus exception (predicates are code by design) |
| Successor index | imperative `buildSignalsContext()` in `RuleAuditService` | cross-object facts reach pure predicates only via the audit context (glpost/attendance/documents precedent) |
| Expiring-contracts surface | declarative manifest widget | ADR-031 default |
| Notifications | **none** | gate-18 canonical dialect not adopted app-wide — explicit round-1 deferral repeated, not an oversight |

### Mixed-spec rationale (kind: config)

`kind: config` per the time-attendance precedent: the deltas are corpus JSON, schema JSON, manifest JSON, and seeds; the PHP (one provider, one context builder, a VERSION bump) is the corpus's established predicate plumbing riding along, not a new service surface.

## Schema delta (`hr-objects.json`)

`EmploymentContract` 0.1.0 → 0.2.0, one new property:

| Field | Type | Notes |
|---|---|---|
| `aanzegdOn` | string, format date, nullable | Date the written aanzegging (BW 7:668 lid 1) was sent to the employee; must be on or before `endDate` minus one month to satisfy `nl-aanzegtermijn-bewaking`. |

Register `info.version` (`lib/Settings/hrmq_register.json`) 0.5.0 → 0.6.0.

## New corpus rules (labour.json)

| id | framework | source | statement (short) | severity | machineCheckable | parameters |
|---|---|---|---|---|---|---|
| `nl-signaal-contract-verloopt` | `hr-signals` | HR contract-lifecycle control (aflopend tijdelijk contract) | A temporary contract ending within the next 60 days with no successor contract for the same employee shall be signalled for HR follow-up (renew, aanzeggen, or offboard) | recommended | true | `{"windowDays": 60}` |
| `nl-aanzegtermijn-bewaking` | `bw7-10` | BW art. 7:668 lid 1 (aanzegplicht) | For a fixed-term contract of ≥ 6 months the employer shall inform the employee in writing, at least one month before the end date, whether the contract will be continued; a live contract past that deadline without a recorded aanzegging violates (historical breaches after expiry are not re-flagged — monitoring window stated in the rule) | mandatory | true | `{"minContractMonths": 6, "noticeMonths": 1}` |

Both `domain: labour`, `jurisdiction: NL`; aanzegtermijn `effectiveDate: "2015-01-01"` (Wet werk en zekerheid), signal rule `effectiveDate: null`. SCHEMA.md framework examples gain `hr-signals`. `RuleCatalogue::VERSION` → `2026-07.6`.

## Manifest delta (`src/manifest.json`)

Per D5: one `object-table` widget + one layout row on the existing `Dashboard` page; no new pages, menus, or deepLinks. `npm run check:manifest` must stay green.

## Seed Data (ADR-001)

`hr-seed.json` gains one `EmploymentContract` (obvious placeholders, existing employee-slug convention):

- `contract-devries-tijdelijk` — `employeeId: "employee-devries"` (established dangling-slug placeholder), `type: "temporary"`, `writtenContract: true`, `startDate: "2025-09-01"`, `endDate: "2026-08-01"`, `hoursPerWeek: 32`, `hourlyWage: 21.00`, `cao: null`, `awfTariff: "high"` (temporary ⇒ expected high), `aanzegdOn: null`, plus the jurisdiction-neutral booleans the other contract seeds carry (`workingTimeDocumented: true`, `overtimeMultiplier: 1.5`, `ftePartOfYearMinijob: false`, `dpaeFiledBeforeStart: true`).

At the seed anchor (audit ≈ 2026-07-13) this contract violates **exactly** the two new rules: expiry in 19 days with no devries successor (`nl-signaal-contract-verloopt`), and an 11-month fixed term whose aanzeg deadline 2026-07-01 passed with `aanzegdOn: null` (`nl-aanzegtermijn-bewaking`). Verified non-regression: `awfTariff: high` matches `expectedAwfTariff` (temporary), `hourlyWage 21.00 ≥ 14.71`, `hoursPerWeek 32 > 0`, temporary passes `nl-contract-schriftelijk` vacuously, and no `Onboarding` seed references `employee-devries`, so the last-wins `related` contract index cannot shift the proeftijd audit. The widget shows the same contract on the dashboard. Time-anchoring caveat (the attendance-seed convention): both violations naturally expire once real time passes `2026-08-01` — the seeds demonstrate, they do not regression-pin.

## Risks / Trade-offs

- **Time-dependent rules** — both predicates read the real clock, so audit results drift with the calendar by design; the statements say so, and the seed section documents the demo window honestly.
- **Successor heuristic is shape-based** (later start + later/absent end), not a legal chain model — ketenregeling semantics are explicitly a follow-up; a false "successor found" requires a deliberately overlapping later contract, which is itself data worth seeing.
- **Duplicate context loading**: `buildSignalsContext()` re-loads EmploymentContracts that `buildRelatedContext()` also loads — accepted (same O(objects) profile as the five existing builders, `LIMIT` 10000); merging the two indexes is a refactor the proeftijd follow-up can do.
- **Widget/rule window duplication** (60 in two files) — cross-referenced `_note`s; a config-token indirection would be over-engineering for one constant.
- **No push notification** — the signal is pull (dashboard + audit) until the gate-18 dialect adoption change lands; deferral is recorded in three places (Non-Goals, ADR-031 table, tasks).

## Open Questions

- None blocking. Ketenregeling chain rule, aanzegbrief docudesk template, and `x-openregister-notifications` adoption are named follow-ups.

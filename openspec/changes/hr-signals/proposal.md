---
kind: config
---

# HR Signals (contract-expiry & HR-moment signalling, corpus-first)

## Why

Spectr's canonical-feature scan flags `hrmq-canon-hr-signals` at 3/9 competitor coverage: every serious NL SMB HR suite nags the HR admin about upcoming HR moments — the loudest being *aflopende tijdelijke contracten*, where missing the moment has a statutory price (BW 7:668 aanzegvergoeding: up to one month's wage for a missed written aanzegging). hrmq holds the data (`EmploymentContract.type/startDate/endDate`) and the machinery (versioned rule corpus, `RuleEngine` check providers, `RuleAuditService` cross-object context, dashboard) but signals nothing: a temporary contract can lapse unnoticed.

**Investigated at HEAD before choosing the signals** (the build brief's candidate list, checked against the live corpus and providers):

- `nl-signaal-proeftijd-verloopt` — **already covered, not duplicated**: `nl-onboarding-proeftijd-bewaking` (labour.json, enforced by `NlOnboardingChecks::proeftijdSatisfied()`) machine-checks both the BW 7:652 contract-type caps *and* the overdue-unclosed proeftijd (a running proeftijd past `proeftijdEndDate` violates).
- `nl-signaal-wml-ondergrens` — **already covered, not duplicated**: `nl-minimumloon-2026` (payroll.json, `machineCheckable: true`) is enforced by `NlPayrollChecks` on `EmploymentContract.hourlyWage >= 14.71`, and `nl-minimumuurloon-wet` checks the hourly-basis fields; a new WML rule would be a duplicate row over the same fields.
- **Chosen instead**: the contract-expiry signal (`nl-signaal-contract-verloopt`, the brief's anchor) plus the genuinely missing statutory signal adjacent to it — **`nl-aanzegtermijn-bewaking`** (BW 7:668 lid 1: a fixed-term contract of ≥ 6 months requires written aanzegging at least one month before `endDate`). Grep across `labour.json`/`payroll.json` and all `Checks/` providers finds no `aanzeg*` (nor `keten*`) coverage; the rule is machine-checkable once `EmploymentContract` carries the aanzegging date.

## What Changes

- **Two corpus rules in `lib/Standards/rules/labour.json`**: `nl-signaal-contract-verloopt` (a temporary contract whose `endDate` falls within the next 60 days with no successor contract for the same employee — advisory monitoring, `severity: recommended`, new framework slug `hr-signals` added to SCHEMA.md's examples) and `nl-aanzegtermijn-bewaking` (`severity: mandatory`, framework `bw7-10`, BW art. 7:668 lid 1, `effectiveDate: 2015-01-01`). `RuleCatalogue::VERSION` bumps `2026-07.5` → `2026-07.6`.
- **One schema field**: `EmploymentContract` gains nullable `aanzegdOn` (date the written aanzegging was sent to the employee); schema version 0.1.0 → 0.2.0, register `info.version` 0.5.0 → 0.6.0 (`lib/Settings/hrmq_register.json`).
- **New check provider `lib/Standards/Checks/NlSignalChecks.php`** keyed on `EmploymentContract` (the per-change-provider convention — `NlGlPostChecks`/`NlAttendanceChecks`/`NlDocumentChecks`; `RuleEngine` merges multiple providers per object type additively). The successor check is cross-object: `RuleAuditService` gains `buildSignalsContext()` → `$context['signals']['contractsByEmployeeId']` (the **full list** of contracts per employee — the existing `related.EmploymentContract.byEmployeeId` index deliberately keeps only the last-loaded contract and cannot answer "is there a successor").
- **Dashboard widget "Aflopende contracten"** on the existing `Dashboard` page: an `object-table` over `EmploymentContract` filtered `{type: "temporary", endDate: {gte: "@today", lte: "@today+60d"}}` (the `@today`/`@today±Nd` filter tokens and the operator filter shape are both supported by the widget fetchers at HEAD), row-routing to `EmploymentContractDetail`, view-all to `EmploymentContracts`.
- **One seed** in `lib/Settings/register.d/hr-seed.json`: `contract-devries-tijdelijk` — a temporary contract ending `2026-08-01` (inside the seed corpus's 2026-07/08 time anchor) with no successor and no `aanzegdOn` → intentionally violates both new rules at the reference audit date, and nothing else.

### Non-goals

- **`x-openregister-notifications` on the new rules/widget is explicitly OUT** — the gate-18 canonical notification dialect is not yet adopted app-wide in hrmq (the same deferral round 1 made); when hrmq adopts the dialect, expiry signals become push notifications in that change, not this one.
- **Ketenregeling (Wab 3×3) chain counting** — needs contract-chain semantics (interruptions ≤ 6 months) beyond this MVP's successor probe; follow-up rule once chains matter.
- **WIA/jubilea/verjaardagen signals** — too fuzzy for the corpus's machine-checkable discipline (SCHEMA.md: flag honestly).
- **Automated aanzegging generation** — `hrmq-docudesk-documents` owns document generation; a future `aanzegbrief` template is its follow-up.
- **New pages** — the widget deep-links into the existing `EmploymentContracts`/`EmploymentContractDetail` pages.

## Capabilities

### New Capabilities

- `hr-signals`: the corpus-first signalling slice — two rules (advisory contract-expiry + statutory aanzegtermijn), the `aanzegdOn` field, `NlSignalChecks` + the signals audit context, the dashboard expiring-contracts widget, and the intended-violation seed.

### Modified Capabilities

<!-- none — onboarding-wizard (proeftijd) and payroll (WML) rules are deliberately NOT touched; this change only adds alongside them -->

## Impact

- `lib/Standards/rules/labour.json` — two new rules; `lib/Standards/rules/SCHEMA.md` — `hr-signals` added to the framework examples list.
- `lib/Standards/RuleCatalogue.php` — `VERSION` `2026-07.5` → `2026-07.6`.
- `lib/Standards/Checks/NlSignalChecks.php` — NEW provider (auto-discovered).
- `lib/Service/RuleAuditService.php` — new `buildSignalsContext()` wired into `audit()` next to the existing context builders.
- `lib/Settings/register.d/hr-objects.json` — `EmploymentContract` + `aanzegdOn`, schema version 0.2.0; `lib/Settings/hrmq_register.json` — register 0.6.0.
- `lib/Settings/register.d/hr-seed.json` — one new `EmploymentContract` seed.
- `src/manifest.json` — `dash-expiring-contracts` widget + layout row on the `Dashboard` page (`npm run check:manifest` must stay green).
- `tests/Unit/` — predicate unit coverage for both rules (window edges, successor shapes, vacuous passes) following the existing checks-test layout.

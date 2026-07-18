---
kind: config+code
depends_on: [30-procent-regeling]
---

# WNT disclosure — a topfunctionaris marker, an annual verantwoording, and a norm-overschrijding check

## Why

The Wet normering topinkomens (WNT, 2013, BWBR0032249) caps and mandates public disclosure of
compensation for topfunctionarissen (board members and directors who lead the whole organisation) at
(semi-)public institutions. hrmq has no WNT concept anywhere today — no marker for which employees
are topfunctionarissen, no disclosure record, and no check that flags pay above the norm.

**Reuse, do not re-declare, the WNT-norm datum.** The active (not yet merged) sibling change
`30-procent-regeling` already does the verification work this task asked for: its design.md
independently establishes the 2026 general bezoldigingsmaximum at **€262.000**
(`parameters.dertigProcentRegeling.aftoppingsgrens` in `lib/Standards/tables/nl-2026.json`, `{jaar:
262000, maand: 21833.33}`, `verified: true`), citing Wet LB 1964 art. 31a's own reference to "de
WNT-norm" for the 30%-ruling's aftoppingsgrens — the *same* statutory figure this change needs, not a
coincidentally-similar one. Independent re-verification for this task (Rijksoverheid.nl,
lawandpepper.com citing art. 2.3 WNT + Staatscourant 2025-27439, AWVN) confirms the same €262.000
figure for 2026. This change reads that one leaf rather than declaring a second copy that could
silently diverge from it — the exact single-home-for-a-datum discipline `cao-library` established
for CAO figures.

**Scope, per instruction: a marker, the annual disclosure, and one check — nothing more.** The old
draft branch `spec/wnt-disclosure` (May 2026) proposed a ten-feature executive-compensation
suite: real-time YTD-to-norm dashboards with hourly alerting, a bespoke aggregation engine spanning
payroll + provisions + manual entry, interim-executive norm tiering, automated education/healthcare
klasse-indeling (A–G) derivation, a severance-plafond sub-system, a recovery-tracking workflow with
quarterly reminders and hard-blocking escalation, immutable multi-version PDF report generation with
a `wnt-auditor` RBAC role, ZIP audit exports with checksums, and multi-year retroactive
reconciliation. None of that exists in hrmq today, and hrmq has no case-management, workflow-
escalation, or bespoke compensation-aggregation capability to build it on. This change deliberately
does not build any of it — see design.md "Named gaps" for the explicit mapping. What genuinely
belongs in hrmq's shipped, declarative-lifecycle architecture is exactly what the task named: a
topfunctionaris marker, the annual WNT-verantwoording disclosure record, and a machine check flagging
pay above norm without a valid transitional exemption.

## What Changes

- **`Employee` schema** (`lib/Settings/register.d/hr-objects.json`) gains `wntTopfunctionaris`
  (boolean, default `false`) and `wntUitzonderingReden` (nullable enum `overgangsrecht` \|
  `ontheffing-minister`, default null) — the recorded transitional-exemption ground, when one
  applies (WNT art. 2.7 overgangsrecht / ministerial ontheffing).
- **NEW `WntDisclosure` schema** (`lib/Settings/register.d/hr-wnt.json`, new `hr-wnt` fragment) — one
  row per `(year, employeeId)` for a topfunctionaris, carrying `totalCompensation` (the aggregated
  WNT-bezoldiging for that year — hand-entered by finance; see Non-Goals) and a plain two-state
  `concept -> gepubliceerd` lifecycle. The set of a year's `WntDisclosure` rows, filterable on the
  index page, **is** the annual WNT-verantwoording — no separate aggregate "report" schema.
- **NEW corpus rule `nl-wnt-norm-overschrijding`** (`lib/Standards/rules/payroll.json`, new framework
  slug `wnt-2013`, `WntDisclosure`, `mandatory`, `machineCheckable: true`) reading the SAME
  `parameters.dertigProcentRegeling.aftoppingsgrens.jaar` leaf `30-procent-regeling` adds to
  `nl-2026.json` — violates when `totalCompensation` exceeds that figure and the referenced
  `Employee.wntUitzonderingReden` is null. `RuleCatalogue::VERSION` bump.
- **NEW `lib/Standards/Checks/NlWntChecks.php`** (auto-discovered `CheckProvider`) registering the
  predicate, with a small `RuleAuditService` context enrichment (`Employee.byId` gains
  `wntUitzonderingReden`, the `Employee.byId` incremental-field precedent).
- **Manifest**: a read-only-list `WntDisclosures` index page + `WntDisclosureDetail` detail page,
  landing as a sibling of `PensionFilings`/`LoonaangifteFilings` in the existing `PayrollGroup`
  ("Loonadministratie") menu — **not** a new top-level "Aangiftes & compliance" menu: ADR-001 Rule 5
  names that menu, but verified against HEAD, `PensionFilings`/`LoonaangifteFilings` both actually
  ship inside `PayrollGroup` today (ADR-001 predates both shipped placements by seven weeks); this
  change follows the shipped precedent, not the stale ADR text (the same kind of correction
  `multi-administratie`'s #64 made to REQ-MULTI-005).
- **Seed data**: one topfunctionaris `WntDisclosure` at/under norm (clean pass), one over norm with a
  recorded `overgangsrecht` exemption (clean pass — the exemption gate), and one over norm with no
  exemption (violation).

### Non-goals (named fast-follows and exclusions)

- **Automated compensation aggregation** across payroll runs, pension provisions, and nature
  compensation — `totalCompensation` is hand-entered by finance for the MVP, the same "data entry,
  not computed" boundary `payroll-core-engine`'s own disclaimer accepts for pension. A named
  fast-follow once a shared aggregation capability exists.
- **Real-time YTD-to-norm dashboards, hourly alerting, quarterly reminder sweeps** — no
  case-management/notification-workflow capability exists in hrmq for this; `nl-wnt-norm-overschrijding`
  is a point-in-time audit check (`occ hrmq:rules:audit`), not a live monitor.
- **Interim-executive norm tiering, education/healthcare klasse-indeling (A–G) automation** — real
  WNT nuance, genuinely out of scope: hrmq has no employer-sector/klasse taxonomy (the same gap
  `abp-aansluiting` and `aor-ambtenarenrecht` name for their own admin-set flags).
  `wntUitzonderingReden` and `totalCompensation` are the honest MVP surface; per-class norm variation
  is a named fast-follow.
- **Severance-plafond administration, recovery tracking with escalation, immutable multi-version PDF
  generation, `wnt-auditor` RBAC role, ZIP audit export** — all require case-management,
  document-generation, or RBAC machinery beyond this change's scope. PDF generation, if ever needed,
  is a named fast-follow onto the already-shipped `payslip-pdf-docudesk` mechanism, not a new one.
- **Multi-year retroactive reconciliation** — out of scope; a correction is a new `WntDisclosure` row
  for the same year, hand-reconciled, not an automated re-derivation.

## Capabilities

### New Capabilities

- `wnt-disclosure`: the `wntTopfunctionaris`/`wntUitzonderingReden` marker fields, the
  `WntDisclosure` annual-verantwoording schema, and the `nl-wnt-norm-overschrijding` check.

### Modified Capabilities

<!-- none — this change is additive; it reads the 30-procent-regeling WNT-norm leaf but does not
     modify that change's schema, engine, or checks -->

## Impact

- `lib/Settings/register.d/hr-objects.json` — `Employee` +2 fields.
- `lib/Settings/register.d/hr-wnt.json` — NEW `hr-wnt` fragment, `WntDisclosure` schema.
- `lib/Standards/rules/payroll.json` — +1 rule (new `wnt-2013` framework, added to `SCHEMA.md`);
  `lib/Standards/RuleCatalogue.php` — `VERSION` bump.
- `lib/Standards/Checks/NlWntChecks.php` — NEW.
- `lib/Service/RuleAuditService.php` — `Employee.byId` gains `wntUitzonderingReden`.
- `src/manifest.json` — `WntDisclosures` + `WntDisclosureDetail` pages, `PayrollGroup` menu entry.
- `lib/Settings/register.d/hr-seed.json` — 3 new `WntDisclosure` seeds + 2 `Employee` marker edits.
- `tests/Unit/Standards/NlWntChecksTest.php` — NEW.
- Depends on `30-procent-regeling` (active, not yet merged) for the `aftoppingsgrens` leaf this
  change's rule reads — see design.md Risks for the landing-order contingency.

## Sources

- Wet normering topinkomens (WNT), <https://wetten.overheid.nl/BWBR0032249> — the base law.
  **Verified.**
- Rijksoverheid.nl, "Topinkomens bestuurders (semi)publieke instellingen",
  <https://www.rijksoverheid.nl/themas/overheid-en-democratie/beloningen-bestuurders/topinkomens-overheid> —
  2026 algemeen bezoldigingsmaximum €262.000. **Verified.**
- Law & Pepper, "WNT: Het algemeen bezoldigingsmaximum 2026 is bekend" — cites art. 2.3 WNT and
  Staatscourant 2025-27439 for the €262.000 2026 figure (up from €246.000 in 2025).
  <https://lawandpepper.com/juridisch-nieuws/wet-normering-topinkomens-wnt-bezoldigingsnorm-2026/>
  **Verified**, corroborating `30-procent-regeling`'s design.md D3 figure independently.
- AWVN, "Algemene bezoldigingsmaximum 2026",
  <https://www.awvn.nl/belonen/nieuws/algemene-bezoldigingsmaximum-2026/> — independent
  corroboration. **Verified.**

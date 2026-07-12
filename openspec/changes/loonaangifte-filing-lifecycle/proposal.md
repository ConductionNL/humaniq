---
kind: config
---

# Loonaangifte Filing Lifecycle (tijdvakcodes, deadlines, review→confirm→send)

## Why

The 2026-07-12 market deep-research (logged in Spectr, insights `hrmq-insight-tijdvak-deadlines` / `hrmq-insight-upa-table-stakes`) ranked a working loonaangifte filing flow as hrmq's #1 missing feature: every NL competitor (AFAS, Loket.nl, Nmbrs, Employes) ships a filing lifecycle where a wage-tax filing is created, reviewed, confirmed and sent, with hard statutory deadlines surfaced in-app. hrmq's `LoonaangifteFiling` schema today is a passive record — it has `period`, `deadline` and `submittedDate` fields but no state machine, no tijdvakcode, no deadline derivation, and no deadline alerting, so an HR admin gets zero workflow support for the one obligation with a hard one-calendar-month statutory deadline (Wet LB 1964 art. 28 / AWR art. 19).

## What Changes

- **Lifecycle on `LoonaangifteFiling`** — declarative `x-openregister-lifecycle` state machine `concept → klaargezet → bevestigd → verzonden`, with `heropen` (reopen) back-edges and a `markeer-afgekeurd` (mark rejected by Belastingdienst response) edge. Modeled on the market-standard create→review→confirm→send flow (verified at Loket.nl).
- **Tijdvakcode support** — new `tijdvakcode` property (official Belastingdienst numeric period code, e.g. `6010` = January 2026 monthly, `6710` = four-weekly period 1, `6400` = year) plus `aangiftenummer`/`betalingskenmerk` context fields the code feeds into.
- **Deadline derivation documented on the schema** — the NL deadline rule (filing + payment due exactly one calendar month after period end, no weekend extension) recorded on the `deadline` property description and enforced by a new rule check.
- **Three new machine-checkable NL rules** in the versioned rule corpus (`lib/Standards/rules/payroll.json`) + checks in `NlWageTaxFilingChecks`: tijdvakcode↔period consistency, deadline-correctness (period end + 1 calendar month), and deadline-approaching/overdue signalling for unfiled periods.
- **Filing pages upgraded** — `LoonaangifteFilings` index gains status + deadline columns; `LoonaangifteFilingDetail` gains `lifecycleActions` (klaarzetten / bevestigen / verzenden / heropenen) and a deadline KPI stats-block, mirroring the existing Timesheet/Expense detail-page pattern.
- **Response-message capture** — new `responseStatus` / `responseMessage` fields so a Belastingdienst acceptance/rejection can be recorded against the sent filing (in-app response handling is table stakes per the Loket.nl reference flow; actual Digipoort wire transport stays out of scope — see Non-goals).

### Non-goals

- No Digipoort wire submission (Logius "nieuwe Digipoort" migration is in flight through Q3 2026; the gateway-vs-direct decision is an open question logged in Spectr as `hrmq-insight-digipoort`). `verzonden` records that the filing left the building (manually or via a future OpenConnector integration); it does not implement transport.
- No payroll-calculation engine and no XML rendering of the Gegevensspecificaties message — this change makes the filing *workflow* real; message generation is a follow-up spec.
- DE/FR/US filing lifecycles unchanged (fields stay; the new lifecycle + rules are NL-scoped, jurisdiction-guarded).

## Capabilities

### New Capabilities

- `loonaangifte-filing-lifecycle`: the filing state machine (concept→klaargezet→bevestigd→verzonden), tijdvakcode assignment, statutory deadline derivation + alerting rules, and the filing pages' lifecycle actions and deadline KPIs.

### Modified Capabilities

<!-- none — the existing specs (hrmq-expenses, hrmq-timesheet-approval, portal-*) are untouched -->

## Impact

- `lib/Settings/register.d/hr-objects.json` — `LoonaangifteFiling` schema: add `x-openregister-lifecycle`, `status`, `tijdvakcode`, `aangiftenummer`, `betalingskenmerk`, `responseStatus`, `responseMessage` properties; bump schema version.
- `lib/Standards/rules/payroll.json` — 3 new NL rules (`nl-loonaangifte-tijdvakcode`, `nl-loonaangifte-deadline-derivation`, `nl-loonaangifte-deadline-alert`).
- `lib/Standards/Checks/NlWageTaxFilingChecks.php` — new check methods for the 3 rules.
- `src/manifest.json` — `LoonaangifteFilings` index columns; `LoonaangifteFilingDetail` lifecycleActions + deadline stats-block.
- `lib/Repair/InitializeRegister.php` — no change (fragment import picks up the schema bump).
- Seed data (`hr-seed.json`) — seed filings in different lifecycle states.
- Related active change: `hrmq-rule-compliance-enforcement` (RuleComplianceGuard wiring) — this change keeps guards out of scope and relies on the state machine + checks; the guard change can later wire rule predicates into transitions.

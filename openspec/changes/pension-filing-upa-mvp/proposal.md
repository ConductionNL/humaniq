---
kind: config
---

# Pension Filing UPA MVP (sector-pension filing lifecycle, gated on approved payroll runs)

## Why

The 2026-07-12 market deep-research (logged in Spectr, insight `hrmq-insight-upa-table-stakes`, source `hrmq-src-loket-apg`) verified that automated sector-pension filing (UPA — Uniforme Pensioenaangifte) is table stakes in NL payroll software and ranked it hrmq's #3 missing feature: Loket.nl's APG pensioenaangifte (funds ABP/O&O, SPW, bpfBOUW, Schoonmaak, Architecten/PFAB, PWRI) can only be created once a salary run is approved, follows the same create→review→confirm→send lifecycle as the loonaangifte, auto-dispatches confirmed filings on a 15-minute scheduler, and surfaces APG response messages in-app. hrmq — a Dutch-SMB HRM/payroll app on the OpenRegister data layer — has **no pension filing at all**: no schema, no rules, no pages. An employer in an APG-administered sector gets zero support for a monthly delivery obligation.

## What Changes

- **New `PensionFiling` schema** in a new register fragment `lib/Settings/register.d/hr-pension.json`: one UPA delivery to one sector fund for one wage period, referencing the `PayrollRun` it reports on (`payrollRunId` `$ref`), with `fund` (APG-administered funds first: `abp`, `spw`, `bpf-bouw`, `schoonmaak`, `pfab`, `pwri` — extensible enum), `aanleverkenmerk` (UPA delivery reference), `deadline`, and response-capture fields (`responseStatus`/`responseMessage`).
- **Declarative lifecycle** `concept → gecontroleerd → bevestigd → verzonden` via `x-openregister-lifecycle` (actions `controleren`, `bevestigen`, `verzenden`, `heropenen`, `corrigeren`), mirroring the loonaangifte filing flow.
- **New lifecycle guard `OCA\Hrmq\Lifecycle\PayrollRunApprovedGuard`** on the `controleren` transition: loads the referenced PayrollRun from the register and rejects the transition unless the run's `status` is `approved`, `posted` or `paid` — this encodes the verified Loket.nl gating rule "a pensioenaangifte can only be created after salary-run approval". Same `LifecycleGuardInterface` as `NoSelfApprovalGuard`; the established ADR-031 lifecycle-guard exception. Change kind stays `config` per repo precedent — `hrmq-expenses` was `config` with guard involvement.
- **Three new machine-checkable NL rules** in the versioned corpus (`lib/Standards/rules/payroll.json`, new framework value `nl-pensioenaangifte`) + a new check provider `lib/Standards/Checks/NlPensionFilingChecks.php`: payroll-run-approved reference integrity, APG monthly-completeness, and deadline alerting for unsent filings.
- **Filing pages**: new `PensionFilings` index (columns period/fund/status/deadline/responseStatus) and `PensionFilingDetail` with `lifecycleActions` (Controleren/Bevestigen/Verzenden/Heropenen/Corrigeren) and a status/deadline stats-block, added to the Loonadministratie menu group next to `LoonaangifteFilings`.
- **Seed data**: 3 PensionFiling objects in different lifecycle states plus approved PayrollRun seeds for them to reference.

### Non-goals

- **No UPA XML message generation** — rendering the SIVI Uniforme Pensioenaangifte message schema is a follow-up spec; this change makes the filing *workflow* real.
- **No wire delivery to APG** — `verzonden` records that the delivery left the building (manually or via a future OpenConnector integration); no transport is implemented.
- **No scheduled auto-dispatch** — Loket.nl's 15-minute scheduler behaviour is a later n8n/OpenConnector concern.
- **No non-APG fund configuration UI** — the `fund` enum is extensible in data; a fund-administration settings surface is out of scope.

## Capabilities

### New Capabilities

- `pension-filing-upa-mvp`: the PensionFiling schema + fragment, its guarded lifecycle (payroll-run-approval gate), the UPA reference-integrity/completeness/deadline rules and checks, and the filing pages.

### Modified Capabilities

<!-- none — existing specs (loonaangifte-filing-lifecycle, hrmq-expenses, hrmq-timesheet-approval, portal-*) are untouched; the loonaangifte change is a sibling, not a dependency -->

## Impact

- `lib/Settings/register.d/hr-pension.json` — **new** fragment: `PensionFiling` schema with `x-openregister-lifecycle`.
- `lib/Lifecycle/PayrollRunApprovedGuard.php` — **new** guard implementing `LifecycleGuardInterface`.
- `lib/Standards/rules/payroll.json` — 3 new NL rules (`nl-upa-payrollrun-approved`, `nl-upa-monthly-completeness`, `nl-upa-deadline-alert`); `RuleCatalogue::VERSION` bump; `lib/Standards/rules/SCHEMA.md` framework examples gain `nl-pensioenaangifte`.
- `lib/Standards/Checks/NlPensionFilingChecks.php` — **new** auto-discovered check provider.
- `lib/Service/RuleAuditService.php` — audit context gains a lightweight cross-type index so the reference-integrity and completeness predicates can see PayrollRun/PensionFiling siblings (predicates already receive `$context` per the RuleEngine contract).
- `src/manifest.json` — new `PensionFilings` index + `PensionFilingDetail` detail pages; Loonadministratie menu entry.
- `lib/Settings/register.d/hr-seed.json` — 3 PensionFiling seeds + approved PayrollRun seeds.
- `lib/Repair/InitializeRegister.php` — no change (fragment glob picks up the new file).
- Related active changes: `loonaangifte-filing-lifecycle` (same filing-lifecycle pattern, independent), `hrmq-ia-navigation-alignment` (owns the eventual ADR-001 "Aangiftes & compliance" menu realignment — this change follows the current Loonadministratie placement).

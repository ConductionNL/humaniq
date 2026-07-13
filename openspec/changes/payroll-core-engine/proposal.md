---
kind: code
depends_on:
  - payroll-core-schema   # config head: nl-2026.json tables, Employee/PayrollRun/Payslip deltas, the two engine-contract rules
chain:
  - payroll-core-schema   # spec 1 — tax-year table corpus + schema deltas + engine-output rules (config head, merged first)
  - payroll-core-engine   # this change — the calculator, run service, occ commands, checks, golden tests
---

# Payroll Core — the open-source Dutch payroll calculation engine (chain spec 2)

## Why

Spec 2 of 2 in the payroll-core chain (spec 1: `payroll-core-schema`, the config head that shipped
the verified `lib/Standards/tables/nl-2026.json` parameter corpus, the schema deltas and the two
engine-contract rules). This change makes hrmq the **only open-source Dutch payroll engine in
existence** (Spectr canon `hrmq-canon-payroll-engine`, 7/9 coverage, P1-strategic; insight
`hrmq-insight-odoo-nl-enterprise-only` — Odoo's NL payroll localisation is Enterprise-only; the
2026-07-12/13 deep-research found no OSS alternative anywhere).

Everything around the engine already exists in this repo and is waiting for a producer:
`PayrollGLPostService` posts **approved** runs to shillinq's journal, `PayrollNetPayService` turns
them into SEPA batches, the loonaangifte/UPA filing lifecycles report on them, and 30+
machine-checkable NL payroll rules audit their outputs — but every euro in every seeded
Payslip/PayrollRun is hand-typed. This change computes them: a pure, stateless, table-driven
`PayrollCalculator` implementing the Belastingdienst Rekenvoorschriften 2026 formula chain over
the spec-1 tables, and a `PayrollRunService` that turns it into draft runs + generated payslips.

## What Changes

- **NEW `lib/Payroll/TaxTables.php`** — versioned table loader: reads
  `lib/Standards/tables/<id>.json`, validates the shape, converts euro amounts to **integer
  cents**, exposes the parameter groups + the table `id` (the run's `engineVersion` stamp).
- **NEW `lib/Payroll/PayrollCalculator.php`** — pure, stateless, table-driven: input =
  employee + contract + period + tables → component breakdown (integer-cents everywhere). Steps
  (exact equation chain in design.md D2, straight from Rekenvoorschriften §2.1–2.2.4): bruto from
  `grossMonthlySalary` (fixed monthly salary is the ONE MVP path; hourly×hours from approved
  Timesheets is a named fast-follow), vakantiegeldreservering 8%, tabelloon → loonheffing via
  schijven + AHK/ARK formulas (witte maandtabel path; groene = same chain without arbeidskorting,
  structure-only), Zvw werkgeversheffing, Awf/Aof/Wko/Whk employer charges (`awfTariff` drives
  laag/hoog; caps at maximumpremieloon), netto. AOW-age variant: reduced volksverzekeringen-rate
  bracket set + AOW korting columns per the tables, switched on `dateOfBirth` + the tables'
  AOW-leeftijd.
- **NEW `lib/Service/PayrollRunService.php`** — creates a **draft** `PayrollRun` for
  (period, administrationId), generates one Payslip per active NL employee (contract covering the
  period), rolls up totals, stamps `engineVersion`/`calculatedAt`; idempotent per
  (period, administrationId) mirroring the netpay/glpost service patterns; **recalculation allowed
  only in `draft`**; `approve` stays a human action on the existing status enum (no lifecycle
  invented). Payslips are created via OpenRegister's ObjectService — verified against the
  openregister checkout: `allowCreate: false` is a **UI-only** affordance of the manifest
  object-list widget (zero server-side occurrences), so a service-side write is legitimate and the
  "payroll-generated, not hand-created" intent is preserved (design.md D5).
- **NEW occ commands** — `hrmq:payroll:run --period YYYY-MM [--administration ADM]
  [--recalculate]` (create/recalculate draft runs) and `hrmq:payroll:verify --period YYYY-MM
  [--administration ADM]` (runs the corpus audit scoped to the run + its payslips — the corpus
  becomes the engine's own self-check), registered in `appinfo/info.xml`.
- **NEW guarded endpoint** — `POST /api/payroll/calculate` (`PayrollController::calculate`),
  mirroring `DocumentController`'s no-admin-idor RBAC pattern: the posted `runId` must resolve
  through ObjectService under the caller's RBAC before anything computes; non-draft runs are
  refused. ONE endpoint, no CRUD (ADR-022).
- **Manifest** — `PayrollRuns` index gains a "Loonrun aanmaken" `open-form` action (schema-driven
  create of a draft run — the manifest v2 page `actions` + `open-form` action type demonstrably
  support this, read fresh); `PayrollRunDetail` gains a "(Her)berekenen" `api-call` action bound
  to the new endpoint (page-actions like EmploymentContractDetail's generate action — NOT the
  `lifecycleActions` widget, which is reserved for `x-openregister-lifecycle` transitions
  PayrollRun deliberately does not carry) plus a Payslips child list now that spec 1 gave Payslip
  a real `payrollRunId` `$ref`.
- **NEW `lib/Standards/Checks/NlEngineChecks.php`** — implements the two spec-1 rules
  (`nl-engine-table-version`, `nl-engine-output-consistency`) + `RuleAuditService` context
  enrichment so the payslip predicate can see its run (the glpost context precedent).
- **GOLDEN TESTS** — table-driven PHPUnit fixtures in `tests/fixtures/payroll-2026/*.json`
  (bruto in → expected components out), computed from the tables file itself (self-consistent) +
  clearly marked empty slots for official Belastingdienst test cases; edge cases: AOW-age, no
  loonheffingskorting, minimum-wage earner, part-time; plus a balancing-invariant test asserting
  the spec-1 consistency equation across every fixture and service-level tests (idempotency,
  draft-only recalculation) with a mocked ObjectService.
- **DISCLAIMER (a requirement, not a footnote)** — README section: the engine is
  rekenvoorschriften-based and **NOT certified**; outputs carry `engineVersion`; production use
  requires verification against the official Belastingdienst test sets. Honesty is a feature.

### Non-goals (named fast-follows and exclusions)

- **Hourly path** (hourlyWage × approved Timesheet hours when salary is absent) — named
  fast-follow; the MVP computes fixed monthly salaries only and reports skipped employees.
- **Anoniementarief computation** — employees failing the BSN/ID preconditions are **skipped with
  a warning** (never silently computed wrong); the 52% flat path is a fast-follow.
- **CAO rules, bijzonder tarief (vakantiegeld payout), 30%-ruling netto-operation, pension premie
  calculation, Zvw-inhouding mode, loonaangifte message generation** — per the chain head's
  non-goals; the calculator's table-driven, pure-function shape is the extension point.
- **Write-time guards / lifecycle on PayrollRun** — `approve` stays a plain human status edit;
  guard wiring is owned by the active `hrmq-rule-compliance-enforcement` change.

## Capabilities

### New Capabilities

- `payroll-core-engine`: the pure table-driven NL gross-to-net calculator, the draft-run
  generation service with idempotent recalculation, the occ run/verify commands, the guarded
  calculate endpoint + run pages upgrade, the NlEngineChecks enforcement of the spec-1 contract,
  and the golden-test suite + non-certification disclaimer.

### Modified Capabilities

<!-- none — payroll-core-schema is consumed, not modified; glpost/netpay/filing specs untouched -->

## Impact

- `lib/Payroll/TaxTables.php`, `lib/Payroll/PayrollCalculator.php` — NEW (pure PHP, no NC deps →
  unit-testable without stubs).
- `lib/Service/PayrollRunService.php` — NEW (ObjectService access idiom per
  PayrollNetPayService); `lib/Service/SettingsService.php` — two config getters:
  `payroll_aof_tariff` (`laag|hoog`, default `laag`) and `payroll_whk_percentage` (default = the
  tables' flagged national average) — the per-employer values the tables file cannot know.
- `lib/Command/PayrollRunCommand.php`, `lib/Command/PayrollVerifyCommand.php` — NEW;
  `appinfo/info.xml` +2 `<command>` entries.
- `lib/Controller/PayrollController.php` — NEW; `appinfo/routes.php` +1 route (before catch-all).
- `lib/Standards/Checks/NlEngineChecks.php` — NEW; `lib/Service/RuleAuditService.php` — context
  enrichment (`payroll.runsById`).
- `src/manifest.json` — PayrollRuns index action, PayrollRunDetail api-call action + Payslips
  child list + `_note` updates; `npm run check:manifest` passes.
- `tests/fixtures/payroll-2026/*.json`, `tests/Unit/Payroll/PayrollCalculatorTest.php`,
  `tests/Unit/Payroll/BalancingInvariantTest.php`, `tests/Unit/Service/PayrollRunServiceTest.php`,
  `tests/Unit/Standards/NlEngineChecksTest.php` — NEW.
- `README.md` — non-certification disclaimer section.
- Depends on: `payroll-core-schema` (tables file, schema fields, corpus rules) — MUST be merged
  first (ADR-032 chain).

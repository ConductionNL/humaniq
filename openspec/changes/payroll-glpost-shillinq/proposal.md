---
kind: code
---

# Payroll GL Posting to Shillinq (loonjournaalpost per loonrun)

## Why

The 2026-07-12 market deep-research + session decision (Spectr insight `hrmq-insight-buildround-2026-07-12`) identified AFAS's core pitch — payroll flows *automatically* into the books — as the gap hrmq+shillinq can close open-source. Today an approved `PayrollRun` carries GL-reconciliation fields (`glExpensePosted`, `glLiabilityPosted`) that the corpus rule `xc-payroll-gl-reconciliation` audits, but nothing in the fleet ever writes the corresponding journal entry: the bookkeeper re-keys the loonjournaalpost by hand in shillinq, and the reconciliation rule stays red on every real run.

The ecosystem rule is explicit: financial machinery is built in shillinq (it already ships `JournalEntry`, GL/GLTransaction materialisation, and SEPA pain.001 PaymentRun) and hrmq REINTEGRATES it as a leaf — no duplicate bookkeeping code in hrmq. An earlier draft on remote branch `spec/hrmq-shillinq-payroll-cost-post` sketched the RGS mapping (bruto-loon → 4xxx expense, sociale lasten, netto-loonschuld → balance accounts); this change modernises that sketch against the current repo state, with **per-run** posting (not per-employee) for the MVP.

## What Changes

- **New `PayrollGLPost` schema** (new fragment `lib/Settings/register.d/hr-glpost.json`, version 0.1.0): the hrmq-side record of one posting attempt — `payrollRunId` ($ref PayrollRun), `period`, `status` (`pending`/`posted`/`failed`/`skipped-no-shillinq`), `journalEntryId` + `journalNumber` (the shillinq JournalEntry it produced), `errorMessage`, `postedAt`, and a `lines` snapshot of the journal lines sent.
- **New `PayrollGLPostService`** (`lib/Service/PayrollGLPostService.php`): given an approved `PayrollRun`, builds a balanced 4-line journal from the run totals (explicit balancing equation in design.md D2) and creates a shillinq `JournalEntry` object (register `shillinq`, schema `JournalEntry`, `journalType: manual`, `state: draft`) via OpenRegister's ObjectService — same-instance, NOT HTTP. Duck-typed per the ADR-046 philosophy `PortalContributionProvider` already follows: when the shillinq register/schema is absent the outcome is recorded as `skipped-no-shillinq`, never an exception.
- **Configurable account mapping**: RGS-coded account numbers from app config via `SettingsService` getters (`glpost_account_gross` → default `4001`, `glpost_account_employer_charges` → `4002`, `glpost_account_wage_tax_liability` → `1701`, `glpost_account_net_wages_liability` → `1702`; defaults are obvious configurable placeholders).
- **New occ command `hrmq:glpost:run [--period YYYY-MM]`** posting all approved-but-unposted runs, registered in `appinfo/info.xml` `<commands>` next to the existing rules commands. This is the MVP trigger: PayrollRun transitions are plain data edits today (no payroll engine drives them), so an event-driven lifecycle hook is deferred until `hrmq-rule-compliance-enforcement` wires guards/events.
- **Reconciliation goes green**: on success the service writes the run's existing `glExpensePosted` / `glLiabilityPosted` **amounts** (they are numbers, not booleans — see design.md D4) so the existing corpus rules `xc-payroll-gl-reconciliation` (and, once remittance is recorded, `xc-withholding-liability-clearing`) can pass, and advances the run `approved → posted`.
- **New corpus rule `nl-glpost-idempotent-per-run`** (machine-checkable, domain `ledger-integrity` like the existing GL rules) + a new `NlGlPostChecks` check provider auditing that each run has at most one active (pending/posted) `PayrollGLPost` and that a posted record is complete and balanced.
- **Manifest pages**: `PayrollGLPosts` index (period, status, journalEntryId, postedAt) + `PayrollGLPostDetail` under the existing Loonadministratie (`PayrollGroup`) menu group.
- **Unit tests**: PHPUnit for the line-building math (balanced entry, remainder handling, failure paths) with a mocked ObjectService.

### Non-goals

- **SEPA net-pay payment batch** (consuming shillinq's PaymentRun / pain.001) — separate follow-up spec.
- **Per-employee splits** — one journal per run; per-employee/kostenplaats dimensions are a follow-up.
- **Vakantiegeld accrual postings** — `PayrollRun` has no vakantiegeld-reservation total field today (only `Payslip.vakantiegeldReserved` per payslip), so the MVP journal cannot source it; deliberately left out until the run aggregates it.
- **Reversal/correction postings** — the escape hatch is manual: void the JournalEntry in shillinq (its `voided` lifecycle state) and re-run `hrmq:glpost:run` after correcting the run.
- **Driving shillinq's post transition** — hrmq creates the JournalEntry in `draft`; posting draft→posted (GLTransaction materialisation) stays behind shillinq's own lifecycle + approval policy (design.md D3).

## Capabilities

### New Capabilities

- `payroll-glpost-shillinq`: the payroll-to-GL leaf — PayrollGLPost record, balanced journal construction from run totals, duck-typed JournalEntry creation in the shillinq register, idempotency rule + check, occ trigger, and the GL-post pages.

### Modified Capabilities

<!-- none — existing specs (loonaangifte-filing-lifecycle, hrmq-expenses, hrmq-timesheet-approval, portal-*) untouched -->

## Impact

- `lib/Settings/register.d/hr-glpost.json` — NEW fragment: `PayrollGLPost` schema + seed objects (fragment auto-merged by `SettingsService` per ADR-037).
- `lib/Service/PayrollGLPostService.php` — NEW service (ADR-031 exception: cross-app integration, documented in design.md).
- `lib/Service/SettingsService.php` — four `glpost_account_*` config getters with placeholder defaults.
- `lib/Command/GlPostRunCommand.php` — NEW occ command; `appinfo/info.xml` gains one `<command>` entry.
- `lib/Standards/rules/payroll.json` — new rule `nl-glpost-idempotent-per-run`.
- `lib/Standards/Checks/NlGlPostChecks.php` — NEW check provider (auto-discovered by RuleEngine); `lib/Service/RuleAuditService.php` — enrich the audit `$context` with a per-run PayrollGLPost index so the cross-object idempotency predicate stays a pure `fn(array $o, array $context)`.
- `src/manifest.json` — `PayrollGLPosts` + `PayrollGLPostDetail` pages, menu entry in `PayrollGroup`.
- `tests/Unit/Service/PayrollGLPostServiceTest.php` — NEW unit tests.
- Cross-app read-only dependency (duck-typed, optional): shillinq register `shillinq`, schema `JournalEntry` (contract: `shillinq/openspec/specs/bookkeeping-journal-entries/spec.md`, REQ-JE-002/-JE-007).

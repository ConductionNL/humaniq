---
capability: payroll-glpost-shillinq
status: done
built_by: openspec/changes/archive/2026-07-12-payroll-glpost-shillinq
---

# payroll-glpost-shillinq Specification

**Status**: done
**Scope**: hrmq (cross-app leaf; consumes the shillinq `JournalEntry` register via OpenRegister)
**OpenSpec changes**:
- [payroll-glpost-shillinq](../../changes/archive/2026-07-12-payroll-glpost-shillinq/) _(archived 2026-07-12)_ — `PayrollGLPost` record + `PayrollGLPostService` posting one balanced loonjournaalpost per approved `PayrollRun` into shillinq's `JournalEntry` register (duck-typed, same-instance ObjectService, `skipped-no-shillinq` degradation), occ trigger `hrmq:glpost:run`, idempotency rule `nl-glpost-idempotent-per-run`, GL-post pages (kind: code)

## Purpose

Make payroll flow automatically into the books — the AFAS core pitch, matched
open-source (2026-07-12 deep research, Spectr insight
`hrmq-insight-buildround-2026-07-12`): one balanced 4-line journal (bruto
loonkosten, werkgeverslasten, loonheffing-schuld, netto-loonschuld) per
approved payroll run, delivered as a draft `JournalEntry` into the shillinq
bookkeeping app on the same instance. hrmq stays a leaf — no duplicate
bookkeeping machinery; the existing `xc-payroll-gl-reconciliation` corpus rule
turns green on posted runs. SEPA net-pay batches, per-employee splits, and
vakantiegeld accruals are explicitly out of scope.

## Requirements

@e2e exclude backend occ/service change plus declarative manifest pages; hrmq has no app-level e2e suite yet (tracked by active change hrmq-test-coverage-baseline)

### REQ-PGP-001: A `PayrollGLPost` schema SHALL record every posting attempt in a new register fragment

`lib/Settings/register.d/hr-glpost.json` (NEW, merged by `SettingsService` per ADR-037) declares `PayrollGLPost` v0.1.0 (`icon: BookEditOutline`, `x-schema-org: schema:AccountingTransaction`) with: `payrollRunId` (string, format uuid, `$ref` PayrollRun, required), `period` (string YYYY-MM, required), `status` (enum `pending|posted|failed|skipped-no-shillinq`, default `pending`, required), `journalEntryId` (string, nullable — the shillinq JournalEntry object id, plain string per ADR-062 rule 7: cross-register targets get no `$ref`), `journalNumber` (string, nullable), `errorMessage` (string, nullable), `postedAt` (string, date-time, nullable), `lines` (array snapshot of the journal lines sent: `{accountNumber, side, amount, description}`).

#### Scenario: Fragment merges and validates
- **GIVEN** the hrmq register import (`occ` Repair step or forced settings reload)
- **WHEN** the fragments under `lib/Settings/register.d/` are deep-merged and imported
- **THEN** the `PayrollGLPost` schema exists in the hrmq register and an object `{payrollRunId: "<uuid>", period: "2026-05", status: "pending"}` validates

#### Scenario: Unknown status rejected
- **WHEN** a `PayrollGLPost` is written with `status: "done"`
- **THEN** OpenRegister schema validation rejects it (enum violation)

### REQ-PGP-002: `PayrollGLPostService` SHALL build a balanced 4-line journal from the run totals

`lib/Service/PayrollGLPostService.php` (NEW) maps an approved run to exactly four lines on configurable accounts (via `SettingsService` getters `glpost_account_gross`/`glpost_account_employer_charges`/`glpost_account_wage_tax_liability`/`glpost_account_net_wages_liability`, placeholder defaults `4001`/`4002`/`1701`/`1702`): debit gross = `totalGross`, debit employer charges = `totalEmployerCharges`, credit loonheffing-schuld = `totalLoonheffing`, credit netto-loonschuld = `totalNet + R` with `R = (totalGross + totalEmployerCharges) − (totalLoonheffing + totalNet)`, so debits equal credits by construction (design.md D2). Amounts rounded to cents; zero-amount lines dropped. When any total is missing/non-numeric or `R < 0`, the attempt is recorded `failed` with a diagnostic `errorMessage` and nothing is written to shillinq.

#### Scenario: Balanced journal for the seeded totals
- **GIVEN** an approved run with totals gross 3800.00, employer charges 649.80, loonheffing 1102.00, net 2698.00
- **WHEN** the service builds the journal lines
- **THEN** it produces D 3800.00 + D 649.80 and C 1102.00 + C 3347.80, and total debits equal total credits (4449.80)

#### Scenario: Inconsistent totals fail closed
- **GIVEN** an approved run where `totalLoonheffing + totalNet > totalGross + totalEmployerCharges` (negative remainder)
- **WHEN** posting is attempted
- **THEN** no shillinq object is created and the `PayrollGLPost` ends `failed` with an `errorMessage` naming the inconsistency

### REQ-PGP-003: The service SHALL create the journal as a shillinq `JournalEntry` via OpenRegister's ObjectService

The write targets register `shillinq`, schema `JournalEntry` (contract verified in `shillinq/lib/Settings/register.d/add-shillinq-bookkeeping-foundation.json` and `shillinq/openspec/specs/bookkeeping-journal-entries/spec.md` REQ-JE-002): required fields `journalNumber` (deterministic `HRMQ-LOON-{period}-{administrationId}`), `entryDate` (last day of the wage period), `description` ("Loonjournaalpost {period} — hrmq loonrun {payrollRunId}"), `lines` (per REQ-PGP-002; each `{accountNumber, side, amount}` with `side` ∈ `debit|credit`, non-negative amounts), `journalType: manual`, `approvalState: not-required`, `administrationId` (passed through verbatim from the run), `state: draft`. hrmq SHALL NOT drive shillinq's post transition — GLTransaction materialisation stays behind shillinq's own lifecycle and approval policy (design.md D3). On success the `PayrollGLPost` records `journalEntryId`, `journalNumber`, `postedAt`, the `lines` snapshot, and `status: posted`.

#### Scenario: JournalEntry lands in shillinq as a draft
- **GIVEN** shillinq installed with its register imported, and an approved run `2026-05` / `ADM-001`
- **WHEN** the service posts the run
- **THEN** a `JournalEntry` exists in register `shillinq` with `journalNumber: "HRMQ-LOON-2026-05-ADM-001"`, `journalType: manual`, `state: draft`, balanced lines, and `administrationId: "ADM-001"`; **AND** the `PayrollGLPost` is `posted` with that entry's id in `journalEntryId`

#### Scenario: Same-instance only
- **GIVEN** the service implementation
- **WHEN** scanned for HTTP clients (`IClientService`, curl, guzzle) targeting shillinq
- **THEN** none exist — the only channel is `OCA\OpenRegister\Service\ObjectService` resolved from the container

### REQ-PGP-004: Absent shillinq SHALL degrade to `skipped-no-shillinq`, never an exception

Availability is duck-typed (ADR-046 philosophy, as `PortalContributionProvider` does): `IAppManager::isInstalled('shillinq')` plus a try/catch-guarded ObjectService resolve of register `shillinq` / schema `JournalEntry`. Any miss records a `PayrollGLPost` with `status: skipped-no-shillinq` and an explanatory `errorMessage`; the run keeps `status: approved` so a later invocation retries once shillinq is present. hrmq gains no info.xml or composer dependency on shillinq.

#### Scenario: Instance without shillinq
- **GIVEN** a Nextcloud instance where shillinq is not installed
- **WHEN** `occ hrmq:glpost:run` processes an approved run
- **THEN** the command exits 0, the run's `PayrollGLPost` is `skipped-no-shillinq`, no exception is thrown, and the `PayrollRun` still has `status: approved`

#### Scenario: Shillinq installed later
- **GIVEN** a run whose latest `PayrollGLPost` is `skipped-no-shillinq`
- **WHEN** shillinq is installed and `occ hrmq:glpost:run` runs again
- **THEN** a new attempt posts the run and ends `posted` (the skip is superseded, not permanent)

### REQ-PGP-005: A successful post SHALL update the run so the existing GL-reconciliation rule passes

On success the service writes to the `PayrollRun`: `glExpensePosted = totalGross + totalEmployerCharges`, `glLiabilityPosted = totalGross + totalEmployerCharges − totalNet` (these fields are numbers — the amounts `xc-payroll-gl-reconciliation` in `NlPayrollChecks` compares cents-equal), and `status: approved → posted`. `withholdingsClearedToZero` / `withholdingLiabilityBalance` (rule `xc-withholding-liability-clearing`) are not touched — clearing belongs to remittance, out of scope.

#### Scenario: Reconciliation rule goes green
- **GIVEN** an approved run with the REQ-PGP-002 totals and no GL fields set (audit shows an `xc-payroll-gl-reconciliation` violation)
- **WHEN** the run is posted successfully and `occ hrmq:rules:audit` runs
- **THEN** the run carries `glExpensePosted: 4449.80`, `glLiabilityPosted: 1751.80`, `status: posted`, and no `xc-payroll-gl-reconciliation` violation is reported for it

### REQ-PGP-006: An occ command SHALL be the MVP trigger

`lib/Command/GlPostRunCommand.php` (NEW) registers `hrmq:glpost:run` with optional `--period YYYY-MM`, declared in `appinfo/info.xml` `<commands>` (the existing command registration point, next to `hrmq:rules:audit`). It selects `PayrollRun` objects with `status: approved` (period-filtered when given), invokes the service per run, prints one outcome line per run plus a summary, and exits `0` when every selected run ends `posted` or `skipped-no-shillinq`, `1` when any ends `failed`. No automatic lifecycle hook ships in this change: PayrollRun transitions are plain data edits today, and event-driven posting is deferred to the guard/event wiring owned by the active `hrmq-rule-compliance-enforcement` change (design.md D5).

#### Scenario: Period-filtered posting
- **GIVEN** approved runs for `2026-04` and `2026-05`
- **WHEN** `occ hrmq:glpost:run --period 2026-05` executes
- **THEN** only the `2026-05` run is posted; the `2026-04` run is untouched and still selectable

#### Scenario: Failure surfaces in the exit code
- **GIVEN** one approved run with inconsistent totals (REQ-PGP-002 failure path)
- **WHEN** the command runs
- **THEN** it exits `1` and the per-run line shows `failed` with the error message

### REQ-PGP-007: Posting SHALL be idempotent per run, enforced by the service and audited by a corpus rule

Invariant: at most one `PayrollGLPost` in `{pending, posted}` per `payrollRunId` (service-enforced pre-check); `failed`/`skipped-no-shillinq` attempts are superseded by retries. Crash-safety via the deterministic `journalNumber`: before creating, the service searches shillinq for `HRMQ-LOON-{period}-{administrationId}` and adopts an existing entry instead of double-posting; a stale `pending` record is completed (entry found) or marked `failed` superseded (not found) on the next invocation (design.md D6). The corpus (`lib/Standards/rules/payroll.json`) gains `nl-glpost-idempotent-per-run` (domain `ledger-integrity`, jurisdiction `NL`, framework `payroll-core`, severity `recommended`, `machineCheckable: true` — consistent with the sibling GL rules `xc-payroll-gl-reconciliation` / `xc-withholding-liability-clearing`). A NEW check provider `lib/Standards/Checks/NlGlPostChecks.php` audits it on `PayrollGLPost` objects; `RuleAuditService` enriches the audit `$context` with `glpost.activeCountByRun` so the predicate stays a pure `fn(array $o, array $context): bool`: an active record violates when its run's active count exceeds 1, and a `posted` record violates unless `journalEntryId` and `postedAt` are present and the `lines` snapshot balances cents-equal.

#### Scenario: Double invocation posts once
- **GIVEN** an approved run
- **WHEN** `occ hrmq:glpost:run` executes twice in a row
- **THEN** exactly one `posted` `PayrollGLPost` and exactly one shillinq `JournalEntry` exist for that run (the second invocation selects no approved runs)

#### Scenario: Audit flags a duplicated active post
- **GIVEN** two `PayrollGLPost` objects in `posted` status referencing the same `payrollRunId` (data tampered outside the service)
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** `nl-glpost-idempotent-per-run` violations are reported for those objects

### REQ-PGP-008: Manifest pages SHALL expose the GL-post records under Loonadministratie

`src/manifest.json`: the `PayrollGroup` menu ("Loonadministratie") gains child `PayrollGLPosts` (label "Loonjournaalposten", icon `BookEditOutline`); NEW pages `PayrollGLPosts` (index: columns `period`, `status` badge, `journalEntryId`, `postedAt`; default sort `postedAt` desc) and `PayrollGLPostDetail` (all fields including `errorMessage`, the `lines` snapshot as a table, and `journalNumber`/`journalEntryId` displayed as the pointer into shillinq — plain display, no cross-app deep link in MVP), structured like the existing `PayrollRunDetail`. The manifest MUST keep validating (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0 and both new pages plus the menu entry are present

### REQ-PGP-009: Seed data SHALL ship one posted GL-post aligned with the seeded payroll run

`hr-glpost.json` `components.objects` gains one `PayrollGLPost` (slug `glpost-2026-01-adm-001`, `status: posted`, period `2026-01`) referencing the `NlPayrollChecks::seedObjects()` payroll run (2026-01 / ADM-001 — the only PayrollRun seed in the repo; its `glExpensePosted` 4449.80 / `glLiabilityPosted` 1751.80 already reflect the post-GLPost end state) via the slug-style placeholder `payrollRunId: "payroll-run-2026-01-adm-001"` (same placeholder convention as `hr-seed.json`), with `journalNumber: "HRMQ-LOON-2026-01-ADM-001"`, placeholder `journalEntryId`, `postedAt`, and the balanced 4-line snapshot (D 4001 3800.00, D 4002 649.80, C 1701 1102.00, C 1702 3347.80).

#### Scenario: Idempotent seed
- **WHEN** the register import (Repair step) runs twice
- **THEN** the seeded `PayrollGLPost` exists exactly once and its lines snapshot balances

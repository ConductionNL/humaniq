# Design — payroll-sepa-netpay-shillinq

## Context

**hrmq side (verified against HEAD, post `payroll-glpost-shillinq`):** `Employee` v0.2.0 (`lib/Settings/register.d/hr-objects.json`) has **no bank-account fields** — this change adds them. `Payslip` v0.2.0 carries `employeeId` (required; seed conventions vary: `hr-seed.json` uses the object slug `employee-jansen`, the `NlPayrollChecks` test-data seeder uses `employeeNumber` `EMP-NL-0001`), `period` (required, YYYY-MM), `nettoPay` (required, number). `PayrollRun` v0.1.0 carries `period`, `administrationId`, `status` (`draft|approved|posted|paid`) — `payroll-glpost-shillinq` advances `approved → posted` on GL posting, so net pay must select runs in **`approved` OR `posted`** (a run whose journal is booked still needs its salaries paid, and vice versa; the two leaves are order-independent). Payslips have no `payrollRunId` — collection is **by `period` match**, the same convention `NlPayrollChecks` uses.

**shillinq side (verified against `apps-extra/shillinq` HEAD):** register slug `shillinq`, schema slug `PaymentRun`, declared in `lib/Settings/register.d/bookkeeping-accounts-payable-core.json` (v0.1.0, icon `BankTransferOutline`, `x-schema-org: schema:PaymentService`, OR audit trail enabled). Required: `runNumber` (stable batch id, unique per administration), `administrationId`, `executionDate` (date), `status` + `lifecycleState` (both enum `draft|approved|exported|reconciled`, default `draft`; `x-openregister-lifecycle` on `lifecycleState` is canonical), `totalAmount` (≥ 0), `currency` (ISO 4217, default EUR), `paymentLines` (minItems 1; items require `payeeId`, `creditorIban`, `amount`, `apTransactionRef`; optional `payeeName`, `remittanceInfo`). `debtorAccountIban` is **nullable**. Lifecycle transitions: `approve` (draft→approved, **`x-rbac-role: controller`** — the hard gate before money moves), `export` (approved→exported), `reconcile` (exported→reconciled). **pain.001 generation is a service call on the export transition, not object creation**: `PaymentRunExportService::export()` refuses any run whose state ≠ `approved`, renders pain.001.001.03 + CSV via the `PaymentRunGeneratorInterface` generators, stores + tags the files, writes `exportedFileRef`/`exportedAt`, and drives `approved → exported` declaratively (`payment-run-sepa-export` REQ-SEPA-001…-006). Reconciliation (REQ-SEPA-007) matches CAMT.053 entries on `EndToEndId` = `{runNumber}-{lineIndex}` with an `(amount, creditorIban)` fallback. **Verified consumption surface:** `SepaPain001Generator` reads per line only `amount`, `payeeName`, `creditorIban`, `remittanceInfo`, optional `creditorBic`; neither the generator, `PaymentRunExportService`, nor `PaymentRunReconciliationService` ever dereferences `payeeId` or `apTransactionRef` (grep-verified) — they are schema-required plain strings ("FK to … UUID / slug", not `$ref`, no FK validation).

**Old hrmq draft (`origin/spec/bank-payment-batch-sepa`, 2026-05-23):** reused for the problem statement (art. 7:625 BW, fragmented uploads, error cost), the one-payment-per-employee principle, and idempotency-by-payroll-run (F-008). Its pain.001.001.09 generation, XSD validation, PSD2 submission, pain.002 reconciliation, approval thresholds, and pre-notification e-mails (F-002…F-007, F-010) are **superseded by shillinq** and explicitly out of scope here.

**Integration pattern precedent:** `lib/Service/PayrollGLPostService.php` — this change is its deliberate sibling and copies its shape: lazy container-resolved ObjectService, `IAppManager` + guarded-resolve duck probe, two-layer idempotency with deterministic reference, stale-`pending` recovery, `skipped-no-shillinq` recording, occ trigger, mocked-ObjectService unit tests.

## Goals / Non-Goals

**Goals:** one draft shillinq `PaymentRun` per payable payroll run — every employee's netto-loon as one SEPA credit-transfer line — without hrmq ever producing SEPA XML; fail-closed on missing IBANs; idempotent + crash-safe; graceful no-shillinq degradation; the IBAN gap auditable via the rule corpus before it bites; auditable hrmq-side batch record with pages.

**Non-Goals:** pain.001/pain.002/CAMT.053 handling and bank connectivity (shillinq's), driving any shillinq lifecycle transition, expense/bonus aggregation, partial batches, IBAN mod-97 checksum + BIC derivation, pre-notification e-mails, approval thresholds (shillinq's `controller` RBAC gate is the approval).

## Decisions

### D1 — hrmq is a LEAF: it writes a draft shillinq PaymentRun, it does not do payments

hrmq holds **no** SEPA, bank-file, or payment-execution machinery. The only payment artefact hrmq owns is `PayrollPaymentBatch` — a *log of the handoff* (what was sent, when, outcome). The batch itself is a shillinq `PaymentRun` created in `draft` through OpenRegister's ObjectService on the same instance (NOT HTTP). Everything from approval onward — the RBAC `controller` approve gate, pain.001 generation, export, CAMT.053 reconciliation — is shillinq's, and hrmq structurally *cannot* skip it: the export service refuses non-`approved` runs and only shillinq's lifecycle can approve.

### D2 — Line construction (per payslip) and the fail-closed contract

For a payable run (D4), the service collects all `Payslip` objects with `period == run.period` and builds one payment line per payslip:

| PaymentRun line field | Source | Notes |
|---|---|---|
| `payeeId` | Employee object id/slug | required plain string; semantic reuse — see D3 |
| `payeeName` | `Employee.tenaamstelling`, else `firstName + " " + lastName` | what the bank prints as `Cdtr/Nm` |
| `creditorIban` | `Employee.iban` | the field this change adds; missing ⇒ line error |
| `amount` | `Payslip.nettoPay` | rounded to cents; missing/non-numeric/negative ⇒ line error |
| `remittanceInfo` | `"Salaris {period}"` | what the employee sees on the bank statement (`RmtInf/Ustrd`) |
| `apTransactionRef` | Payslip object id/slug | required plain string; semantic reuse — see D3 |

Employee resolution tries, in order: object id, slug, `employeeNumber` — covering both seed conventions (Context). Zero-`nettoPay` payslips are excluded (old draft F-001: no zero-balance payments); if *no* lines remain the batch is `failed` ("nothing to pay"). **Fail-closed:** if ANY payslip yields a line error (unresolvable employee, missing/empty `iban`, bad `nettoPay`), NO shillinq object is created and the `PayrollPaymentBatch` ends `failed` with every line's diagnostic concatenated in `errorMessage` — a partial salary run is an incident, not a feature. `totalAmount` = integer-cents sum of the lines; `lineCount` = count. Collection is pure and side-effect free (unit-testable like `PayrollGLPostService::buildLines()`).

### D3 — Filling shillinq's AP-shaped required fields (documented semantic reuse)

`paymentLines[].payeeId` ("FK to Payee") and `.apTransactionRef` ("FK to the APTransaction this line settles") are required by shillinq's schema because PaymentRun was born in accounts payable — but they are plain strings, never FK-validated, and grep-verified unconsumed by the pain.001 generator, the export service, and the reconciliation service (Context). A payroll batch has no shillinq `Payee` and no `APTransaction`, so hrmq fills them with the honest nearest equivalents: `payeeId` = the hrmq Employee object id, `apTransactionRef` = the hrmq Payslip object id. This keeps the batch traceable line-by-line back to hrmq objects and keeps the payload schema-valid. Risk registered below; if shillinq later hardens these into `$ref`s, this change's spec is the contract to renegotiate (a dedicated `paymentLines[].source` discriminator would be the ask).

### D4 — Run selection, and what a batch does NOT do to the run

Payable = `PayrollRun.status ∈ {approved, posted}` (Context: GL posting and net pay are order-independent leaves; `draft` runs are never paid, and `paid` is reserved for when remittance actually clears — a follow-up beyond this MVP). Unlike `PayrollGLPostService` (which writes `glExpensePosted`/`glLiabilityPosted` and advances `approved → posted`), the net-pay service writes **nothing** back to the run: no PayrollRun field describes payment today, and advancing `posted → paid` on mere *draft-batch creation* would overclaim — money has not moved until shillinq exports and reconciles. The `PayrollPaymentBatch` record alone carries the state.

### D5 — The created PaymentRun (draft, deterministic, EUR)

`runNumber` = deterministic **`HRMQ-NETPAY-{period}-{administrationId}`** (unique per administration by construction — one salary batch per run; also the idempotency probe key, D6), `administrationId` passed through verbatim from the run, `executionDate` = day `netpay_execution_day` (config, default `25` — the customary Dutch salary date) of the period month, clamped to the period's last day, `status`/`lifecycleState` = `draft`, `currency` = `EUR`, `totalAmount` + `paymentLines` per D2, `debtorAccountIban` = the `netpay_debtor_iban` config value when set, else omitted (nullable — the bookkeeper completes it in shillinq before approval; the export emits whatever the run carries). On success the `PayrollPaymentBatch` records `shillinqPaymentRunRef` (the created object id), `runNumber`, `totalAmount`, `lineCount`, `createdAt`, `status: created`.

### D6 — Idempotency and crash-safety (two layers, mirroring GL posting)

- **hrmq layer:** at most one `PayrollPaymentBatch` in `{pending, created}` per `payrollRunId`, service-enforced pre-check. `failed` and `skipped-no-shillinq` are retryable-superseded: the next `hrmq:netpay:run` tries again (a skip must not become permanent once shillinq is installed; a fail must not become permanent once the IBAN is filled in).
- **shillinq layer:** before creating, probe the shillinq register for a `PaymentRun` with the deterministic `runNumber`; if found (crash after create, before record update), adopt it — record its id and complete the batch instead of double-creating. `runNumber` is shillinq's own stable batch identifier and the `EndToEndId` stem, so adoption is exact.
- A stale `pending` batch is resolved on the next run via the same probe: PaymentRun found → complete as `created`; not found → mark the stale record `failed` (superseded) and retry fresh.

### D7 — Duck-typed degradation (ADR-046 philosophy, ADR-031 exception)

Availability probe: `IAppManager::isInstalled('shillinq')` AND a try/catch-guarded ObjectService resolve of register `shillinq` + schema `PaymentRun` — identical shape to `PayrollGLPostService::shillinqAvailable()`, but probing `PaymentRun` (the AP-core fragment can theoretically be absent even where the bookkeeping foundation is present). Any miss → `PayrollPaymentBatch` with `status: skipped-no-shillinq` and a human `errorMessage`; no exception, no log spam above INFO. hrmq keeps zero composer/info.xml dependency on shillinq.

### D8 — Trigger is an occ command, not a lifecycle hook (MVP)

`hrmq:netpay:run [--period YYYY-MM]` selects payable runs (D4) and processes each — same rationale as GL posting's D5: PayrollRun transitions are plain data edits today; the event-driven hook belongs to `hrmq-rule-compliance-enforcement`'s guard/event wiring. Exit code: `0` when every selected run ends `created` or `skipped-no-shillinq`, `1` when any ends `failed`.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `Employee.iban`/`tenaamstelling`, `PayrollPaymentBatch` data model + statuses | declarative schemas (`hr-objects.json`, `hr-paybatch.json` fragment) | ADR-031 default |
| Line collection + cross-register PaymentRun creation | **imperative `PayrollNetPayService`** | **ADR-031 exception: external/cross-app integration** — a multi-step, duck-typed write into ANOTHER app's register with per-line resolution, fail-closed aggregation, and crash-recovery probing cannot be expressed as a declarative lifecycle action on an hrmq schema; same exception class as `PayrollGLPostService` |
| Trigger | imperative occ command (D8) | no lifecycle exists on PayrollRun to hang a declarative action on; runs on operator demand |
| IBAN-present audit | imperative CheckProvider predicate (`NlNetPayChecks`) | the app's established rule-corpus exception |
| Batch pages | declarative manifest | ADR-031 default |

### Mixed-spec rationale (kind: code)

This change is `kind: code`: the PHP surface dominates (service + command + check provider + RuleAuditService context enrichment + unit tests) while the config surface (two schema-fragment edits, one rule row, two manifest pages) rides along. Same yellow-flag precedent as `payroll-glpost-shillinq` and `hrmq-rule-compliance-enforcement`; splitting the JSON edits into a `kind: config` change would create an artificial ordering dependency for ~100 lines of JSON.

## Schema delta

**`Employee` (in `lib/Settings/register.d/hr-objects.json`), v0.2.0 → 0.3.0, additive-nullable:**

| Field | Type | Notes |
|---|---|---|
| `iban` | string, nullable, `pattern: ^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$` | shape check only (mod-97 is a non-goal); ALL seed/example values are SAFE placeholders (`NL00BANK0123456789` style, matching shillinq's convention) |
| `tenaamstelling` | string, nullable | account-holder name as the bank knows it; falls back to `firstName + lastName` when absent (D2) |

**New fragment `lib/Settings/register.d/hr-paybatch.json`** — `PayrollPaymentBatch` v0.1.0, icon `BankTransferOutline` (mirrors shillinq's PaymentRun icon), `x-schema-org: schema:PaymentService`; required `[payrollRunId, period, status]`:

| Field | Type | Notes |
|---|---|---|
| `payrollRunId` | string, format uuid, `$ref` PayrollRun | required — the run this attempt belongs to |
| `period` | string | YYYY-MM, copied from the run for listing/filtering |
| `status` | enum `pending\|created\|skipped-no-shillinq\|failed`, default `pending` | `created` = draft PaymentRun delivered to shillinq (NOT paid — D4) |
| `shillinqPaymentRunRef` | string, nullable | shillinq PaymentRun object id — plain string, NOT `$ref` (cross-register target; ADR-062 rule 7) |
| `runNumber` | string, nullable | the deterministic idempotency key sent (`HRMQ-NETPAY-{period}-{administrationId}`, D6) |
| `totalAmount` | number, nullable | sum of the line amounts (cents-rounded) |
| `lineCount` | integer, nullable | number of payment lines sent |
| `errorMessage` | string, nullable | failure/skip diagnostic; on `failed` carries the per-line errors (D2) |
| `createdAt` | string, date-time, nullable | when the PaymentRun was created (or adopted) in shillinq |

No per-line snapshot array (unlike `PayrollGLPost.lines`): the authoritative lines live on the shillinq PaymentRun, which is OR-audit-trailed; duplicating employee IBANs into a second register would spread bank data without adding audit value.

## New corpus rule (`lib/Standards/rules/payroll.json`)

| id | domain | jurisdiction | framework | severity | machineCheckable | statement (short) |
|---|---|---|---|---|---|---|
| `nl-netpay-iban-present` | ledger-integrity | NL | payroll-core | recommended | true | Every payslip on an approved or posted payroll run shall resolve to an employee with a bank account (IBAN) on file, so that net wages can be paid out by the agreed date (BW art. 7:625). |

Source: "Wage payment control (payroll-to-bank)", `sourceUrl: https://wetten.overheid.nl/BWBR0005290/` (Burgerlijk Wetboek Boek 7) — framework/severity consistent with the sibling `payroll-core` ledger-integrity rows.

**Check plumbing (mirrors `NlGlPostChecks`):** predicates are pure `fn(array $o, array $context): bool`. `RuleAuditService::audit()` pre-loads Employees and PayrollRuns and injects `$context['netpay']['ibanByEmployeeKey']` (object id, slug, AND employeeNumber each mapped → IBAN-present bool) and `$context['netpay']['payablePeriods']` (periods having a run in `approved`/`posted`). The new `NlNetPayChecks` provider keys the check on `Payslip`: violation when the payslip's period is payable and its `employeeId` resolves to no key or to an IBAN-less employee.

## Manifest delta (`src/manifest.json`)

- Menu: `PayrollGroup` ("Loonadministratie") gains child `{id: PayrollPaymentBatches, label: "Betaalbatches", icon: BankTransferOutline, route: PayrollPaymentBatches}`.
- `PayrollPaymentBatches` (index): columns `period`, `status` (badge), `totalAmount`, `lineCount`, `createdAt`; default sort `createdAt` desc.
- `PayrollPaymentBatchDetail`: data card (all schema fields incl. `errorMessage`), with `runNumber`/`shillinqPaymentRunRef` displayed as the pointer into shillinq (plain display — no cross-app deep link in MVP). Structure mirrors `PayrollGLPostDetail`. Detail copy must read "klaargezet in shillinq" — created ≠ betaald (D4).

## Seed Data (ADR-001)

- `hr-seed.json`: `employee-jansen` gains `iban: "NL00BANK0123456789"`, `tenaamstelling: "S. Jansen"` (obvious placeholders, shillinq's SAFE-IBAN convention).
- `NlPayrollChecks::seedObjects()`: the `EMP-NL-0001` Employee row gains `iban: "NL00BANK0000000001"` + `tenaamstelling` so `occ hrmq:rules:audit` stays green on the seeded test data under the new rule (the seeder's run/payslip are payable).
- `hr-paybatch.json` `components.objects`: one `PayrollPaymentBatch` (slug `paybatch-2026-05-adm-001`) in `created`, aligned with the seeded `hr-seed.json` run `payrollrun-2026-05` (approved, `ADM-001`) and its single payslip `payslip-jansen-2026-05` (`nettoPay` 2698.00): `payrollRunId: "payrollrun-2026-05"` (slug-style placeholder ref, same convention as `hr-glpost.json`), `runNumber: "HRMQ-NETPAY-2026-05-ADM-001"`, `shillinqPaymentRunRef: "pr-hrmq-netpay-2026-05-placeholder"`, `totalAmount: 2698.00`, `lineCount: 1`, `createdAt: "2026-06-24T09:00:00Z"`. All identifiers obvious placeholders.

## Risks / Trade-offs

- **AP-shaped required fields carry payroll ids (D3)**: schema-honest today (plain strings, unconsumed), but a future shillinq change could start dereferencing `payeeId`/`apTransactionRef`. Mitigation: this spec documents the reuse explicitly; the follow-up ask is a `paymentLines[].source` discriminator in shillinq.
- **administrationId vocabulary mismatch** (inherited from GL posting): hrmq seeds `ADM-001`, shillinq seeds `adm-consultancy-nl`. Passed through verbatim; the operator keeps one vocabulary. Already surfaced by the sibling change.
- **Period-match collection**: payslips join the run by `period` only — in a multi-administration deployment with two runs in one period, both batches would sweep the same payslips. Acceptable now (a single seeded administration; `Payslip` carries no `administrationId`); the fix (payslip↔run linkage) is a schema follow-up shared with GL posting's per-employee split.
- **`created` ≠ paid**: money moves only after shillinq's controller approves and exports, and `PayrollRun.status` never advances to `paid` here (D4). Page copy must not overclaim.
- **Duplicate salary risk is the top-severity failure** — hence fail-closed lines, at-most-one-active-batch, the deterministic `runNumber` probe, AND shillinq's own approve gate as the human backstop. Four layers, one of them mandatory-human.
- **Employee IBAN is personal data** now stored in the hrmq register and copied into shillinq PaymentRun lines: both registers are OR-audit-trailed, no third copy is kept (no line snapshot on the batch — Schema delta note).

## Open Questions

- None blocking. Expense-reimbursement batching, partial/held-line batches, IBAN checksum + BIC lookup, `posted → paid` on reconciliation feedback, and the shillinq `paymentLines[].source` discriminator are tracked as follow-ups.

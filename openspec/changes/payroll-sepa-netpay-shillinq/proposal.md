---
kind: code
---

# Payroll SEPA Net Pay to Shillinq (salaris-betaalbatch per loonrun)

## Why

The sibling change `payroll-glpost-shillinq` (archived 2026-07-12) closed the *bookkeeping* half of the AFAS pitch: an approved `PayrollRun` now flows into shillinq's journal automatically. The *payment* half is still manual: nothing turns a run's payslips into a bank file, so the salarisadministrateur re-keys every employee's netto-loon into internet banking by hand — the exact error class (wrong amount to wrong IBAN, wage late past the agreed date, art. 7:625 BW exposure) the 2026-05-23 draft `spec/bank-payment-batch-sepa` catalogued.

That draft predates the leaf rule and had hrmq generating pain.001 XML, talking PSD2 to banks, and ingesting pain.002 itself. All of that machinery now exists in shillinq: `PaymentRun` (batch + lifecycle `draft → approved → exported → reconciled`, `bookkeeping-accounts-payable-core`), pain.001.001.03 generation on the export transition (`payment-run-sepa-export`), and CAMT.053 reconciliation. The ecosystem rule is the same as for GL posting: **payment machinery is built in shillinq; hrmq REINTEGRATES it as a leaf** — hrmq NEVER generates SEPA XML itself. What hrmq contributes is the payroll-shaped input: which employees, which IBANs, which net amounts.

One data gap blocks this today: `Employee` has no bank-account fields at all (verified against HEAD, schema v0.2.0). This change adds them.

## What Changes

- **`Employee` schema v0.2.0 → 0.3.0** (`lib/Settings/register.d/hr-objects.json`): two new nullable fields — `iban` (string, IBAN-shaped pattern, SAFE placeholders in all seeds) and `tenaamstelling` (string, the account-holder name as the bank knows it).
- **New `PayrollPaymentBatch` schema** (new fragment `lib/Settings/register.d/hr-paybatch.json`, v0.1.0): the hrmq-side record of one net-pay handoff attempt — `payrollRunId` ($ref PayrollRun), `period`, `status` (`pending`/`created`/`skipped-no-shillinq`/`failed`), `shillinqPaymentRunRef` + `runNumber` (nullable pointers into shillinq), `totalAmount`, `lineCount`, `errorMessage`, `createdAt`.
- **New `PayrollNetPayService`** (`lib/Service/PayrollNetPayService.php`): for an approved/posted `PayrollRun`, collects that period's `Payslip` objects, resolves each employee's `iban`/`tenaamstelling`, and creates ONE shillinq `PaymentRun` (register `shillinq`, schema `PaymentRun`, `lifecycleState: draft`) with one payment line per payslip via OpenRegister's ObjectService — same-instance, NOT HTTP. Approval, pain.001 generation, export, and bank-statement reconciliation all stay behind shillinq's own lifecycle (there is deliberately NO way for hrmq to reach `exported` — shillinq's approve gate is RBAC `controller`).
- **Duck-typed + inert without shillinq** (ADR-046 philosophy, mirroring `PayrollGLPostService`): absence records `skipped-no-shillinq`, retryable, zero composer/info.xml dependency.
- **Fail-closed on missing IBANs**: any payslip whose employee cannot be resolved or lacks an `iban` fails the WHOLE batch (`status: failed`, per-line diagnostics in `errorMessage`) — no partial batch in the MVP, because a partial salary run is an incident, not a convenience.
- **Idempotent per run**: at most one active (`pending`/`created`) `PayrollPaymentBatch` per `payrollRunId`, plus the deterministic shillinq `runNumber` `HRMQ-NETPAY-{period}-{administrationId}` probed before creating (crash recovery adopts, never double-creates) — the same two-layer D6 design as GL posting.
- **New occ command `hrmq:netpay:run [--period YYYY-MM]`** mirroring `hrmq:glpost:run` (registered in `appinfo/info.xml` `<commands>`, exit 0/1).
- **New corpus rule `nl-netpay-iban-present`** (`lib/Standards/rules/payroll.json`, machineCheckable) + new check provider `lib/Standards/Checks/NlNetPayChecks.php`: every payslip on an approved/posted run must resolve to an employee with an IBAN — surfacing the blocker in `occ hrmq:rules:audit` *before* the batch fails.
- **Manifest pages**: `PayrollPaymentBatches` index + `PayrollPaymentBatchDetail` under the existing Loonadministratie (`PayrollGroup`) menu group.
- **Unit tests**: PHPUnit for line collection, fail-closed IBAN handling, idempotency, and duck-typed skip with a mocked ObjectService (mirroring `PayrollGLPostServiceTest`).
- **Seeds**: placeholder IBANs (`NL00BANK…` style) + tenaamstelling on the seeded Employees (both `hr-seed.json` and the `NlPayrollChecks` test-data seeder, so the new rule audits green out of the box) and one `created` `PayrollPaymentBatch` aligned with the seeded 2026-05 run.

### Non-goals

- **pain.001 XML generation** — shillinq's (`payment-run-sepa-export`, REQ-SEPA-001/-002); hrmq never emits SEPA XML.
- **Driving shillinq's `approve`/`export`/`reconcile` transitions, bank connectivity, PSD2 submission, pain.002/CAMT.053 handling** — the old draft's F-006/F-007 are shillinq's job now.
- **Expense-reimbursement / bonus aggregation into the batch** (old draft F-001) — the batch pays `Payslip.nettoPay` only; shillinq already has `expense-reimbursement-or-passthrough` for declaraties.
- **Partial batches / per-line hold-for-review** (old draft F-003's "hold invalid IBANs") — MVP is fail-closed; partial payment is a follow-up once there is an operator UI to review held lines.
- **IBAN mod-97 checksum validation and BIC derivation** — the schema pattern checks shape only; checksum/BIC are a follow-up (shillinq emits BIC-less pain.001 legally within SEPA).
- **Pre-notification e-mails, approval thresholds, hash-chained audit exports** (old draft F-004/F-005/F-010) — out of MVP; shillinq's PaymentRun already carries the OR audit trail.

## Capabilities

### New Capabilities

- `payroll-sepa-netpay-shillinq`: the payroll-to-payment leaf — Employee bank fields, PayrollPaymentBatch record, net-pay line collection with fail-closed IBAN resolution, duck-typed PaymentRun creation in the shillinq register, idempotency, occ trigger, IBAN-present rule + check, and the batch pages.

### Modified Capabilities

<!-- none — payroll-glpost-shillinq and the other existing specs are untouched; the Employee field addition is additive-nullable and owned by this capability -->

## Impact

- `lib/Settings/register.d/hr-objects.json` — `Employee` gains `iban` + `tenaamstelling` (nullable, additive), version bump 0.2.0 → 0.3.0.
- `lib/Settings/register.d/hr-paybatch.json` — NEW fragment: `PayrollPaymentBatch` schema + one seed object (fragment auto-merged by `SettingsService` per ADR-037).
- `lib/Settings/register.d/hr-seed.json` — seeded Employee gains placeholder `iban`/`tenaamstelling`.
- `lib/Service/PayrollNetPayService.php` — NEW service (ADR-031 exception: cross-app integration, documented in design.md).
- `lib/Service/SettingsService.php` — two `netpay_*` config getters (execution day, optional debtor IBAN).
- `lib/Command/NetPayRunCommand.php` — NEW occ command; `appinfo/info.xml` gains one `<command>` entry.
- `lib/Standards/rules/payroll.json` — new rule `nl-netpay-iban-present`.
- `lib/Standards/Checks/NlNetPayChecks.php` — NEW check provider (auto-discovered by RuleEngine); `lib/Standards/Checks/NlPayrollChecks.php` — seeder Employee row gains placeholder IBAN; `lib/Service/RuleAuditService.php` — enrich the audit `$context` with the employee-IBAN index + payable periods so the predicate stays a pure `fn(array $o, array $context)`.
- `src/manifest.json` — `PayrollPaymentBatches` + `PayrollPaymentBatchDetail` pages, menu entry in `PayrollGroup`.
- `tests/Unit/Service/PayrollNetPayServiceTest.php` — NEW unit tests.
- Cross-app dependency (duck-typed, optional, write-only-draft): shillinq register `shillinq`, schema `PaymentRun` (contract: `shillinq/lib/Settings/register.d/bookkeeping-accounts-payable-core.json` + `shillinq/openspec/specs/payment-run-sepa-export/spec.md` REQ-SEPA-001…-007).

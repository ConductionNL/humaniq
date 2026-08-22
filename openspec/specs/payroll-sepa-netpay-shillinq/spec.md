---
capability: payroll-sepa-netpay-shillinq
status: done
built_by: openspec/changes/archive/2026-07-13-payroll-sepa-netpay-shillinq
---

# payroll-sepa-netpay-shillinq Specification

**Status**: done
**Scope**: humaniq (cross-app leaf; consumes the shillinq `PaymentRun` register via OpenRegister)
**OpenSpec changes**:
- [payroll-sepa-netpay-shillinq](../../changes/archive/2026-07-13-payroll-sepa-netpay-shillinq/) _(archived 2026-07-13)_ — Employee `iban`/`tenaamstelling` fields, `PayrollPaymentBatch` record + `PayrollNetPayService` creating one draft shillinq `PaymentRun` per approved/posted `PayrollRun` (one net-pay line per payslip; duck-typed, same-instance ObjectService, fail-closed on missing IBANs, `skipped-no-shillinq` degradation), occ trigger `humaniq:netpay:run`, corpus rule `nl-netpay-iban-present`, batch pages (kind: code)

## Purpose

Pay the salaries an approved payroll run owes — without humaniq ever touching a
bank or emitting SEPA XML. humaniq contributes the payroll-shaped input (which
employees, which IBANs, which net amounts) as a draft `PaymentRun` in shillinq's
register; shillinq owns everything from there: the RBAC-gated approval, the
pain.001.001.03 export, and CAMT.053 reconciliation. Sibling of
`payroll-glpost-shillinq` under the same cross-app-leaf philosophy: payment
machinery lives in shillinq, humaniq reintegrates it. Fail-closed on missing
employee IBANs, idempotent-with-crash-recovery per run, and gracefully inert
when shillinq is absent.

## Requirements

### REQ-PNP-001: The `Employee` schema SHALL carry nullable bank-account fields

`lib/Settings/register.d/hr-objects.json`: `Employee` v0.2.0 → **0.3.0** gains `iban` (string, nullable, `pattern: ^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$` — shape check only, mod-97 checksum is a non-goal) and `tenaamstelling` (string, nullable — the account-holder name as the bank knows it). Both additive; no existing Employee object becomes invalid. Every seeded or exampled IBAN in the repo SHALL be an obvious SAFE placeholder (`NL00BANK0123456789` style, shillinq's convention).

#### Scenario: Existing employees stay valid
- **GIVEN** an Employee object created before this change (no `iban`, no `tenaamstelling`)
- **WHEN** the updated register imports and the object is re-validated
- **THEN** it validates unchanged (both fields nullable and not required)

#### Scenario: Malformed IBAN rejected
- **WHEN** an Employee is written with `iban: "not-an-iban"`
- **THEN** OpenRegister schema validation rejects it (pattern violation)

### REQ-PNP-002: A `PayrollPaymentBatch` schema SHALL record every net-pay handoff attempt in a new register fragment

`lib/Settings/register.d/hr-paybatch.json` (NEW, merged by `SettingsService` per ADR-037) declares `PayrollPaymentBatch` v0.1.0 (`icon: BankTransferOutline`, `x-schema-org: schema:PaymentService`) with: `payrollRunId` (string, format uuid, `$ref` PayrollRun, required), `period` (string YYYY-MM, required), `status` (enum `pending|created|skipped-no-shillinq|failed`, default `pending`, required), `shillinqPaymentRunRef` (string, nullable — the shillinq PaymentRun object id, plain string per ADR-062 rule 7: cross-register targets get no `$ref`), `runNumber` (string, nullable), `totalAmount` (number, nullable), `lineCount` (integer, nullable), `errorMessage` (string, nullable), `createdAt` (string, date-time, nullable). Deliberately NO per-line snapshot: the authoritative lines live on the OR-audit-trailed shillinq PaymentRun, and employee IBANs are not duplicated into a second humaniq store.

#### Scenario: Fragment merges and validates
- **GIVEN** the hrmq register import (`occ` Repair step or forced settings reload)
- **WHEN** the fragments under `lib/Settings/register.d/` are deep-merged and imported
- **THEN** the `PayrollPaymentBatch` schema exists in the hrmq register and an object `{payrollRunId: "<uuid>", period: "2026-05", status: "pending"}` validates

#### Scenario: Unknown status rejected
- **WHEN** a `PayrollPaymentBatch` is written with `status: "paid"`
- **THEN** OpenRegister schema validation rejects it (enum violation — `created` means delivered-to-shillinq, never paid)

### REQ-PNP-003: `PayrollNetPayService` SHALL collect one payment line per payslip, fail-closed on missing IBANs

`lib/Service/PayrollNetPayService.php` (NEW) selects `Payslip` objects with `period == run.period` for a payable run (`status ∈ {approved, posted}` — GL posting and net pay are order-independent leaves) and maps each to a line: `payeeId` = the Employee object id, `payeeName` = `tenaamstelling` else `firstName + " " + lastName`, `creditorIban` = `Employee.iban`, `amount` = `Payslip.nettoPay` rounded to cents, `remittanceInfo` = `"Salaris {period}"`, `apTransactionRef` = the Payslip object id (the two required AP-shaped plain strings, semantic reuse — grep-verified unconsumed by shillinq's generator/export/reconciliation). Employee resolution tries object id, slug, then `employeeNumber` (both seed conventions). Zero-`nettoPay` payslips are excluded. **Fail-closed:** any payslip with an unresolvable employee, a missing/empty `iban`, or a missing/non-numeric/negative `nettoPay` produces a line error; if ANY line errs, NO shillinq object is created and the `PayrollPaymentBatch` ends `failed` with every line's diagnostic in `errorMessage` — no partial batch. An empty line set (no payslips, or all zero) likewise ends `failed`. Line collection is pure/side-effect-free and directly unit-testable, with `totalAmount` computed in integer cents.

#### Scenario: One line per payslip with the employee's IBAN
- **GIVEN** an approved run `2026-05`/`ADM-001` and one payslip (`nettoPay` 2698.00) whose employee has `iban: "NL00BANK0123456789"`, `tenaamstelling: "S. Jansen"`
- **WHEN** the service collects lines
- **THEN** it yields exactly one line `{payeeName: "S. Jansen", creditorIban: "NL00BANK0123456789", amount: 2698.00, remittanceInfo: "Salaris 2026-05"}` with `totalAmount` 2698.00 and `lineCount` 1

#### Scenario: Missing IBAN fails the whole batch
- **GIVEN** an approved run with two payslips, one employee with an IBAN and one without
- **WHEN** the batch is attempted
- **THEN** no shillinq `PaymentRun` is created
- **AND** the `PayrollPaymentBatch` ends `failed` with an `errorMessage` naming the IBAN-less employee's payslip
- **AND** the run's status is unchanged, so a retry after fixing the IBAN succeeds

### REQ-PNP-004: The service SHALL create the batch as a draft shillinq `PaymentRun` via OpenRegister's ObjectService

The write targets register `shillinq`, schema `PaymentRun` (contract verified in `shillinq/lib/Settings/register.d/bookkeeping-accounts-payable-core.json` and `shillinq/openspec/specs/payment-run-sepa-export/spec.md`): `runNumber` = deterministic `HRMQ-NETPAY-{period}-{administrationId}`, `administrationId` passed through verbatim, `executionDate` = the configured `netpay_execution_day` (SettingsService getter, placeholder default `25`) of the period month clamped to the period's last day, `status: draft`, `lifecycleState: draft`, `currency: EUR`, `totalAmount` + `paymentLines` per REQ-PNP-003, and `debtorAccountIban` from the `netpay_debtor_iban` config when set (else omitted — nullable; the bookkeeper completes it in shillinq before approval). humaniq SHALL NOT generate SEPA XML and SHALL NOT drive any shillinq lifecycle transition — approve (RBAC `controller` gate), export (pain.001 generation), and reconcile stay behind shillinq's own lifecycle. On success the `PayrollPaymentBatch` records `shillinqPaymentRunRef`, `runNumber`, `totalAmount`, `lineCount`, `createdAt`, `status: created`. The service SHALL NOT write anything back to the `PayrollRun` (no field describes payment today, and `paid` before reconciliation would overclaim).

#### Scenario: Draft PaymentRun lands in shillinq
- **GIVEN** shillinq installed with its register imported, and an approved run `2026-05`/`ADM-001` with one valid payslip line
- **WHEN** the service processes the run
- **THEN** a `PaymentRun` exists in register `shillinq` with `runNumber: "HRMQ-NETPAY-2026-05-ADM-001"`, `lifecycleState: draft`, `currency: EUR`, one payment line carrying the employee's IBAN and net amount
- **AND** the `PayrollPaymentBatch` is `created` with that object's id in `shillinqPaymentRunRef`
- **AND** the `PayrollRun` object is unmodified

#### Scenario: Same-instance only, no SEPA XML
- **GIVEN** the service implementation
- **WHEN** scanned for HTTP clients targeting shillinq and for XML writers/pain.001 emission
- **THEN** none exist — the only channel is `OCA\OpenRegister\Service\ObjectService` resolved from the container, and no SEPA serialisation code ships in humaniq

### REQ-PNP-005: Absent shillinq SHALL degrade to `skipped-no-shillinq`, never an exception

Availability is duck-typed (ADR-046 philosophy, the `PayrollGLPostService` shape): `IAppManager::isInstalled('shillinq')` plus a try/catch-guarded ObjectService resolve of register `shillinq` / schema `PaymentRun`. Any miss records a `PayrollPaymentBatch` with `status: skipped-no-shillinq` and an explanatory `errorMessage`; the run stays payable so a later invocation retries once shillinq is present. humaniq gains no info.xml or composer dependency on shillinq.

#### Scenario: Instance without shillinq
- **GIVEN** a Nextcloud instance where shillinq is not installed
- **WHEN** `occ humaniq:netpay:run` processes an approved run
- **THEN** the command exits 0, the run's `PayrollPaymentBatch` is `skipped-no-shillinq`, no exception is thrown, and the run stays payable

#### Scenario: Shillinq installed later
- **GIVEN** a run whose latest `PayrollPaymentBatch` is `skipped-no-shillinq`
- **WHEN** shillinq is installed and `occ humaniq:netpay:run` runs again
- **THEN** a new attempt creates the draft PaymentRun and ends `created` (the skip is superseded, not permanent)

### REQ-PNP-006: Batch creation SHALL be idempotent per run with crash recovery

Invariant: at most one `PayrollPaymentBatch` in `{pending, created}` per `payrollRunId` (service-enforced pre-check); `failed`/`skipped-no-shillinq` attempts are superseded by retries. Crash-safety via the deterministic `runNumber`: before creating, the service searches shillinq for `HRMQ-NETPAY-{period}-{administrationId}` and adopts an existing `PaymentRun` instead of double-creating; a stale `pending` record is completed as `created` (PaymentRun found) or marked `failed` superseded (not found) on the next invocation. Duplicate salary payment is the top-severity failure this guards; shillinq's mandatory human approve gate is the final backstop.

#### Scenario: Double invocation creates once
- **GIVEN** an approved run with valid lines
- **WHEN** `occ humaniq:netpay:run` executes twice in a row
- **THEN** exactly one `created` `PayrollPaymentBatch` and exactly one shillinq `PaymentRun` exist for that run (the second invocation is an idempotent no-op for it)

#### Scenario: Crash between create and record is adopted
- **GIVEN** a `pending` `PayrollPaymentBatch` whose deterministic `runNumber` already exists as a shillinq `PaymentRun` (crash after create, before the record update)
- **WHEN** the next invocation processes the run
- **THEN** the existing PaymentRun is adopted — the stale record completes as `created` with its id in `shillinqPaymentRunRef`, and no second PaymentRun is created

### REQ-PNP-007: An occ command SHALL be the MVP trigger

`lib/Command/NetPayRunCommand.php` (NEW) registers `humaniq:netpay:run` with optional `--period YYYY-MM`, declared in `appinfo/info.xml` `<commands>` next to `humaniq:glpost:run`. It selects payable runs (`status ∈ {approved, posted}`, period-filtered when given), invokes the service per run, prints one outcome line per run plus a summary, and exits `0` when every selected run ends `created` or `skipped-no-shillinq`, `1` when any ends `failed`. No automatic lifecycle hook ships (deferred to `humaniq-rule-compliance-enforcement`'s guard/event wiring).

#### Scenario: Period-filtered batching
- **GIVEN** payable runs for `2026-05` and `2026-06`
- **WHEN** `occ humaniq:netpay:run --period 2026-05` executes
- **THEN** only the `2026-05` run is processed; the `2026-06` run is untouched and still selectable

#### Scenario: Failure surfaces in the exit code
- **GIVEN** one payable run with an IBAN-less employee (REQ-PNP-003 failure path)
- **WHEN** the command runs
- **THEN** it exits `1` and the per-run line shows `failed` with the per-line error message

### REQ-PNP-008: A corpus rule SHALL audit IBAN presence before the batch fails

`lib/Standards/rules/payroll.json` gains `nl-netpay-iban-present` (domain `ledger-integrity`, jurisdiction `NL`, framework `payroll-core`, severity `recommended`, `machineCheckable: true` — consistent with the sibling payroll-core rows; source "Wage payment control (payroll-to-bank)", BW art. 7:625). A NEW check provider `lib/Standards/Checks/NlNetPayChecks.php` keys the check on `Payslip`; `RuleAuditService` enriches the audit `$context` with `netpay.ibanByEmployeeKey` (object id, slug, and employeeNumber each mapped → IBAN-present) and `netpay.payablePeriods` (periods having an `approved`/`posted` run) so the predicate stays a pure `fn(array $o, array $context): bool`: a payslip violates when its period is payable and its `employeeId` resolves to no employee or to one without an `iban`.

#### Scenario: IBAN-less employee on a payable run is flagged
- **GIVEN** an approved run for `2026-05` and a `2026-05` payslip whose employee has no `iban`
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** an `nl-netpay-iban-present` violation is reported for that payslip

#### Scenario: Draft-run payslips are not flagged
- **GIVEN** a payslip whose period has only a `draft` run
- **WHEN** the audit runs
- **THEN** no `nl-netpay-iban-present` violation is reported for it (nothing is payable yet)

### REQ-PNP-009: Manifest pages SHALL expose the payment batches under Loonadministratie

`src/manifest.json`: the `PayrollGroup` menu ("Loonadministratie") gains child `PayrollPaymentBatches` (label "Betaalbatches", icon `BankTransferOutline`); NEW pages `PayrollPaymentBatches` (index: columns `period`, `status` badge, `totalAmount`, `lineCount`, `createdAt`; default sort `createdAt` desc) and `PayrollPaymentBatchDetail` (all fields including `errorMessage`, with `runNumber`/`shillinqPaymentRunRef` displayed as the pointer into shillinq — plain display, no cross-app deep link in MVP; copy says "klaargezet in shillinq", never "betaald"), structured like the existing `PayrollGLPostDetail`. The manifest MUST keep validating (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0 and both new pages plus the menu entry are present

### REQ-PNP-010: Seed data SHALL ship placeholder IBANs and one created batch aligned with the seeded run

Seeds (ADR-001, all values obvious SAFE placeholders): `hr-seed.json`'s `employee-jansen` gains `iban: "NL00BANK0123456789"` + `tenaamstelling: "S. Jansen"`; the `NlPayrollChecks::seedObjects()` Employee row (`EMP-NL-0001`) gains `iban: "NL00BANK0000000001"` + a tenaamstelling so `occ humaniq:rules:audit` stays green on seeded test data under REQ-PNP-008; `hr-paybatch.json` `components.objects` gains one `PayrollPaymentBatch` (slug `paybatch-2026-05-adm-001`, `status: created`, period `2026-05`) referencing the seeded run `payrollrun-2026-05` (approved, `ADM-001`, single payslip `payslip-jansen-2026-05` with `nettoPay` 2698.00) via the slug-style placeholder `payrollRunId: "payrollrun-2026-05"`, with `runNumber: "HRMQ-NETPAY-2026-05-ADM-001"`, `shillinqPaymentRunRef: "pr-humaniq-netpay-2026-05-placeholder"`, `totalAmount: 2698.00`, `lineCount: 1`, and `createdAt`.

#### Scenario: Idempotent seed
- **WHEN** the register import (Repair step) runs twice
- **THEN** the seeded `PayrollPaymentBatch` exists exactly once, `totalAmount` equals the seeded payslip's `nettoPay`, and the seeded employees carry placeholder IBANs

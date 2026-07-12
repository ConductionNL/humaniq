# Tasks — payroll-sepa-netpay-shillinq

- [x] 1. Schema: add nullable `iban` (IBAN-shaped pattern) + `tenaamstelling` to `Employee` in `lib/Settings/register.d/hr-objects.json`, version bump 0.2.0 → 0.3.0 per REQ-PNP-001
- [x] 2. Schema: create fragment `lib/Settings/register.d/hr-paybatch.json` with the `PayrollPaymentBatch` schema v0.1.0 (fields, enums, `$ref` payrollRunId, nullable shillinqPaymentRunRef/runNumber/totalAmount/lineCount/errorMessage/createdAt, NO line snapshot) per REQ-PNP-002
- [x] 3. Config: add `netpay_execution_day` (placeholder default 25) + `netpay_debtor_iban` (default empty → omitted) getters to `lib/Service/SettingsService.php` per REQ-PNP-004
- [x] 4. Service: implement `lib/Service/PayrollNetPayService.php` — pure line collection per payslip (employee resolution by id/slug/employeeNumber, tenaamstelling fallback name, cents arithmetic, zero-nettoPay exclusion, `"Salaris {period}"` remittance) per REQ-PNP-003
- [x] 5. Service: fail-closed aggregation — ANY line error (unresolvable employee, missing IBAN, bad nettoPay) or an empty line set records the batch `failed` with all per-line diagnostics in `errorMessage`, creating nothing in shillinq, per REQ-PNP-003
- [x] 6. Service: shillinq PaymentRun creation via `OCA\OpenRegister\Service\ObjectService` (register `shillinq`, schema `PaymentRun`, lifecycleState draft, deterministic runNumber `HRMQ-NETPAY-{period}-{administrationId}`, executionDate from config day clamped to period end, EUR, debtor IBAN passthrough when configured, payeeId/apTransactionRef semantic reuse per design.md D3) per REQ-PNP-004
  - hrmq writes NOTHING back to the PayrollRun (design.md D4) and ships no SEPA/XML code
- [x] 7. Service: duck-typed availability probe (IAppManager + guarded register `shillinq`/schema `PaymentRun` resolve) → `skipped-no-shillinq` recording, zero hard dependency, per REQ-PNP-005
- [x] 8. Service: idempotency + crash recovery — at-most-one active (`pending`/`created`) batch per run, runNumber probe/adopt, stale-pending resolution per REQ-PNP-006 (design.md D6)
- [x] 9. Command: `lib/Command/NetPayRunCommand.php` (`hrmq:netpay:run [--period]`, payable-run selection `approved|posted`, per-run output, exit 0/1) + register it in `appinfo/info.xml` `<commands>` per REQ-PNP-007
- [x] 10. Corpus: add `nl-netpay-iban-present` to `lib/Standards/rules/payroll.json` (ledger-integrity / NL / payroll-core / recommended / machineCheckable) per REQ-PNP-008
- [x] 11. Checks: new `lib/Standards/Checks/NlNetPayChecks.php` provider keyed on `Payslip` + `RuleAuditService` context enrichment (`netpay.ibanByEmployeeKey`, `netpay.payablePeriods`) per REQ-PNP-008
- [x] 12. Manifest: `PayrollPaymentBatches` index + `PayrollPaymentBatchDetail` pages and the `PayrollGroup` menu child ("Betaalbatches", "klaargezet in shillinq" copy) in `src/manifest.json` per REQ-PNP-009; `npm run check:manifest` passes
- [x] 13. Seed: placeholder `iban`/`tenaamstelling` on `hr-seed.json`'s `employee-jansen` AND the `NlPayrollChecks::seedObjects()` Employee row; one `created` `PayrollPaymentBatch` (slug `paybatch-2026-05-adm-001`, obvious placeholders) in `hr-paybatch.json` per REQ-PNP-010
- [x] 14. Unit tests: `tests/Unit/Service/PayrollNetPayServiceTest.php` with a mocked ObjectService — line collection + totals, IBAN-missing fail-closed (nothing created), idempotency pre-check + adopt path, duck-typed skip (mirroring `PayrollGLPostServiceTest`; bootstrap per `tests/bootstrap.php`)
- [x] 15. Quality gates: `composer check:strict` green; in the dev container run the register import, `occ hrmq:netpay:run` against seeded data (with and without shillinq enabled; verify the draft PaymentRun in shillinq carries the seeded IBAN + amount), and `occ hrmq:rules:audit` — confirm `nl-netpay-iban-present` is enforced without regressing existing rules

Acceptance criteria (plain reminders, not tasks):
- hrmq contains NO pain.001/SEPA serialisation and drives NO shillinq lifecycle transition; ObjectService only, draft only
- fail-closed: a single missing IBAN means NO PaymentRun is created (no partial batch)
- `skipped-no-shillinq` and `failed` leave the run payable (retryable)
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH per ADR-007

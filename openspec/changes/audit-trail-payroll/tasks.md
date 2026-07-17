# Tasks — audit-trail-payroll

> Verify against HEAD, not this brief — `payroll-core-engine`, `jurisdiction-packs` and `avg-dsr`
> are all merged; this is a standalone delta on top, not a chain.

## 1. Schema deltas (declarative)

- [ ] 1.1 `lib/Settings/register.d/hr-objects.json`: `Payslip.properties.engineInputSnapshot`
      (string, nullable, full description naming `CalculationInput` + `nl-engine-provenance-complete`)
      per REQ-AUDP-001; `Payslip` version `0.8.0` → `0.9.0`.
- [ ] 1.2 `lib/Settings/register.d/hr-documents.json`: `GeneratedDocument.documentType` enum gains
      `payroll-audit-report`; new nullable `$ref` `payrollRunId` (PayrollRun) per REQ-AUDP-004;
      `GeneratedDocument` version `0.2.0` → `0.3.0`.

## 2. Reproducibility (code)

- [ ] 2.1 `lib/Service/PayrollRunService.php::generate()`: serialize the per-employee
      `CalculationInput` to canonical JSON and stamp it onto `engineInputSnapshot` in the same
      write as `engineVersion`/`calculatedAt` per REQ-AUDP-001.
- [ ] 2.2 `lib/Command/PayrollReproduceCommand.php` (`hrmq:payroll:reproduce --payslip <uuid>`):
      load, resolve engine artefact, decode snapshot, recompute, compare cents-exact, report
      match/first-mismatch, register in `appinfo/info.xml` per REQ-AUDP-002.

## 3. Integrity orchestration (code, zero new hashing)

- [ ] 3.1 `lib/Service/PayrollAuditVerificationService.php::verifyRun(runId)`: resolve run+payslip
      audit-row id range via `AuditQueryService::query()`, call
      `AuditHashService::verifyChain($min, $max)`, return its result unmodified per REQ-AUDP-003.
- [ ] 3.2 Unit test asserting no `hash(`/`sha256` literal exists in
      `PayrollAuditVerificationService.php` — the "reuse, not reimplement" guard.

## 4. Auditor export (code, existing docudesk leaf)

- [ ] 4.1 `lib/Service/HrDocumentService.php`: `payroll-audit-report` generation path —
      `dataRefs = [PayrollRun]`, `adHocData.auditReport` assembling per-payslip provenance +
      REQ-AUDP-003's `verifyRun()` result + `AuditQueryService` entries per REQ-AUDP-004.
- [ ] 4.2 `occ hrmq:documents:generate --type payroll-audit-report --run <uuid>` +
      `DocumentController::generate()` guard extension (resolve `runId` under caller RBAC before
      any assembly, the `authorizeContract`/`authorizePayslip` precedent) per REQ-AUDP-004.

## 5. Machine-checkable rule (data + code)

- [ ] 5.1 `lib/Standards/rules/payroll.json`: add `nl-engine-provenance-complete` row
      (`domain: tax`, `jurisdiction: NL`, `framework: payroll-core`, `severity: mandatory`)
      per REQ-AUDP-005; `RuleCatalogue::VERSION` `2026-07.25` → `2026-07.26`.
- [ ] 5.2 `lib/Standards/Checks/NlEngineChecks.php`: register the `nl-engine-provenance-complete`
      predicate under `Payslip` (vacuous guard, presence+decode+jurisdiction-consistency check,
      no calculator invocation) per REQ-AUDP-005.

## 6. Retention boundary (verification only, no new code)

- [ ] 6.1 Test asserting `AvgDsrRetentionClassifier::PAYROLL_FAMILY_SCHEMAS` still governs
      `Payslip`/`PayrollRun` unchanged and that `engineInputSnapshot` carries no independent
      retention field, per REQ-AUDP-006.

## 7. Tests

- [ ] 7.1 `NlEngineChecksTest`: vacuous/violation/pass cases for `nl-engine-provenance-complete`
      mirroring the existing two predicates' test structure.
- [ ] 7.2 `PayrollAuditVerificationServiceTest`: clean-chain and tampered-row cases against a
      seeded audit-trail fixture range.
- [ ] 7.3 `PayrollReproduceCommandTest`: match, mismatch, and missing-snapshot cases.
- [ ] 7.4 `PayrollRunServiceTest` extension: generated payslips carry a decodable
      `engineInputSnapshot` matching the employee's resolved inputs.

## 8. Docs

- [ ] 8.1 `docs/payroll/payroll-engine.md`: document `engineInputSnapshot`,
      `hrmq:payroll:reproduce`, and the audit-report export alongside the existing
      `nl-engine-table-version`/`nl-engine-output-consistency` section.

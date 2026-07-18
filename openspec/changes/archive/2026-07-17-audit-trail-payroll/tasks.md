# Tasks — audit-trail-payroll

> Verify against HEAD, not this brief — `payroll-core-engine`, `jurisdiction-packs` and `avg-dsr`
> are all merged; this is a standalone delta on top, not a chain.

## 1. Schema deltas (declarative)

- [x] 1.1 `lib/Settings/register.d/hr-objects.json`: `Payslip.properties.engineInputSnapshot`
      (string, nullable, full description naming `CalculationInput` + `nl-engine-provenance-complete`)
      per REQ-AUDP-001; `Payslip` version `0.8.0` → `0.9.0`. Live-verified: `occ maintenance:repair`
      landed it on 8080 (`Payslip` version `0.9.0` confirmed in `oc_openregister_schemas`).
- [ ] 1.2 `lib/Settings/register.d/hr-documents.json`: `GeneratedDocument.documentType` enum gains
      `payroll-audit-report`; new nullable `$ref` `payrollRunId` (PayrollRun) per REQ-AUDP-004.
      **DEFERRED** — `GeneratedDocument.employeeId` is a REQUIRED schema field (the four existing
      document types are all employee-anchored); a run-scoped audit report has no natural employee
      to satisfy it. Loosening that requirement is an invasive change touching 4 already-shipped
      document types, out of proportion to fixing hrmq#98's reproducibility gap and not requested
      by the fix brief. Not built; a follow-up change should resolve the `employeeId` question
      first (nullable field vs. a representative employeeId vs. a separate schema).

## 2. Reproducibility (code)

- [x] 2.1 `lib/Service/PayrollRunService.php::generate()`: serialize the per-employee
      `CalculationInput` to canonical JSON and stamp it onto `engineInputSnapshot` in the same
      write as `engineVersion`/`calculatedAt` per REQ-AUDP-001. Live-verified on 8080 (see report).
- [x] 2.2 `lib/Command/PayrollReproduceCommand.php` (`hrmq:payroll:reproduce --payslip <uuid>`):
      load, resolve engine artefact, decode snapshot, recompute, compare cents-exact, report
      match/first-mismatch, registered in `appinfo/info.xml` per REQ-AUDP-002. Business logic lives
      in `PayrollReproduceService` (thin command wrapper, this codebase's established pattern).
      Live-verified end-to-end on 8080: sealed a payslip, edited its Employee's
      taxTableColor/grossMonthlySalary/loonheffingskortingToegepast via the OR API, re-ran
      `hrmq:payroll:reproduce` — it re-derived the ORIGINAL sealed figures from the snapshot, not
      the edited live data (see report for the exact command output).

## 3. Integrity orchestration (code, zero new hashing)

- [x] 3.1 `lib/Service/PayrollAuditVerificationService.php::verifyRun(runId)`: resolve run+payslip
      audit-row id range, call `AuditHashService::verifyChain($min, $max)`, return its result
      unmodified per REQ-AUDP-003. **Deviates from this requirement's literal text**: uses
      `AuditHandler::getLogs($uuid)` (OpenRegister's own per-object audit-row read path, already
      wired into hrmq's 43 audit-trail widgets), NOT `AuditQueryService::query()` — live/code
      recon found `AuditQueryService::query()` searches OBJECT rows in a register/schema that
      LOOKS LIKE an audit schema by naming convention (procest's `aiAuditEntry` precedent), not the
      `openregister_audit_trails` table `PayrollRun`/`Payslip` rows actually live in; it cannot
      resolve this range at all. `AuditHandler::getLogs()` is the correct existing OR service for
      this (see class docblock). Zero new hashing/chaining code either way — confirmed by 3.2.
- [x] 3.2 Unit test (`PayrollAuditVerificationServiceTest::testNoBespokeHashComputationExistsInThisService`)
      asserting no `hash(`/`sha256` literal exists in `PayrollAuditVerificationService.php` — the
      "reuse, not reimplement" guard. `lib/Service/RuleAuditService.php` was also checked
      (recon flagged it as a possible re-chainer) and carries no hash/chain code at all — nothing to drop.

## 4. Auditor export (code, existing docudesk leaf)

- [ ] 4.1 **DEFERRED** — see 1.2 (blocked on the same `GeneratedDocument.employeeId` question).
- [ ] 4.2 **DEFERRED** — see 1.2/4.1.

## 5. Machine-checkable rule (data + code)

- [x] 5.1 `lib/Standards/rules/payroll.json`: added `nl-engine-provenance-complete` row
      (`domain: tax`, `jurisdiction: NL`, `framework: payroll-core`, `severity: mandatory`)
      per REQ-AUDP-005; `RuleCatalogue::VERSION` `2026-07.25` → `2026-07.26`.
- [x] 5.2 `lib/Standards/Checks/NlEngineChecks.php`: registered the `nl-engine-provenance-complete`
      predicate under `Payslip` (vacuous guard, presence+decode+jurisdiction-consistency check,
      no calculator invocation) per REQ-AUDP-005.

## 6. Retention boundary (verification only, no new code)

- [x] 6.1 `tests/Unit/Service/AvgDsrRetentionClassifierPayrollAuditTest.php`: asserts
      `AvgDsrRetentionClassifier::PAYROLL_FAMILY_SCHEMAS` still governs `Payslip`/`PayrollRun`
      unchanged, that `engineInputSnapshot`'s presence/absence never alters the retention outcome,
      and that it is the schema's only addition (no independent retention field), per REQ-AUDP-006.
      **`RetentionService::placeLegalHold()` was investigated and deliberately NOT used** — the
      canonical, already-merged spec (design.md D5) explicitly rules out any new
      retention/immutability mechanism for this capability, reusing
      `AvgDsrRetentionClassifier`'s existing 7-year AWR fallback instead; see report for the
      full reasoning.

## 7. Tests

- [x] 7.1 `NlEngineChecksTest`: vacuous/violation/pass cases for `nl-engine-provenance-complete`
      mirroring the existing two predicates' test structure, including the live-verified
      OpenRegister JSON-string-column auto-decode quirk (see 2.2 report notes).
- [x] 7.2 `PayrollAuditVerificationServiceTest`: clean-chain, tampered-row, unknown-run, and
      no-audit-rows-yet cases against fake `AuditHandler`/`AuditHashService` collaborators.
- [x] 7.3 `PayrollReproduceCommandTest` (CLI contract: missing `--payslip`, refused, unknown run)
      + `PayrollReproduceServiceTest` (the full match/mismatch/missing-snapshot/employee-edit-
      invariance matrix, against the REAL `PayrollCalculator`/`PackRepository`/`TaxTables`).
- [x] 7.4 `PayrollRunServiceTest` extension: generated payslips carry a decodable
      `engineInputSnapshot` matching the employee's resolved inputs (canonical sorted-key JSON
      asserted too); a hand-entered payslip carries no `engineInputSnapshot` key at all.

## 8. Docs

- [ ] 8.1 `docs/payroll/payroll-engine.md` — **NOT DONE**. Deferred alongside the audit-report
      export (4.1/4.2) to prioritize the live-verified reproducibility fix within this session;
      file an issue for the docs update + the deferred REQ-AUDP-004 export together.

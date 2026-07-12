# Tasks — payroll-glpost-shillinq

- [x] 1. Schema: create fragment `lib/Settings/register.d/hr-glpost.json` with the `PayrollGLPost` schema v0.1.0 (fields, enums, `$ref` payrollRunId, nullable journalEntryId/journalNumber/errorMessage/postedAt, lines snapshot) per REQ-PGP-001
- [x] 2. Config: add the four `glpost_account_*` getters (placeholder defaults 4001/4002/1701/1702, RGS-configurable) to `lib/Service/SettingsService.php` per REQ-PGP-002
- [x] 3. Service: implement `lib/Service/PayrollGLPostService.php` — balanced 4-line builder with the D2 balancing equation, cents rounding, zero-line dropping, negative-remainder/missing-totals `failed` path per REQ-PGP-002
  - vakantiegeld deliberately absent: no run-level reservation field exists (design.md Non-Goals)
- [x] 4. Service: shillinq JournalEntry creation via `OCA\OpenRegister\Service\ObjectService` (register `shillinq`, schema `JournalEntry`, journalType manual, state draft, deterministic journalNumber `HRMQ-LOON-{period}-{administrationId}`, administrationId passthrough, entryDate = period end) per REQ-PGP-003
- [x] 5. Service: duck-typed availability probe (IAppManager + guarded register/schema resolve) → `skipped-no-shillinq` recording, zero hard dependency, per REQ-PGP-004
- [x] 6. Service: idempotency + crash recovery — at-most-one active PayrollGLPost per run, journalNumber probe/adopt, stale-pending resolution per REQ-PGP-007 (design.md D6)
- [x] 7. Service: success effects on the run — numeric `glExpensePosted`/`glLiabilityPosted` amounts and `approved → posted` status per REQ-PGP-005
- [x] 8. Command: `lib/Command/GlPostRunCommand.php` (`hrmq:glpost:run [--period]`, per-run output, exit 0/1) + register it in `appinfo/info.xml` `<commands>` per REQ-PGP-006
- [x] 9. Corpus: add `nl-glpost-idempotent-per-run` to `lib/Standards/rules/payroll.json` (ledger-integrity / NL / payroll-core / recommended / machineCheckable) per REQ-PGP-007
- [x] 10. Checks: new `lib/Standards/Checks/NlGlPostChecks.php` provider + `RuleAuditService` context enrichment (`glpost.activeCountByRun`) per REQ-PGP-007
- [x] 11. Manifest: `PayrollGLPosts` index + `PayrollGLPostDetail` pages and the `PayrollGroup` menu child in `src/manifest.json` per REQ-PGP-008; `npm run check:manifest` passes
- [x] 12. Seed: one posted `PayrollGLPost` (slug `glpost-2026-01-adm-001`, balanced snapshot, obvious placeholders) in `hr-glpost.json` per REQ-PGP-009
- [x] 13. Unit tests: `tests/Unit/Service/PayrollGLPostServiceTest.php` with a mocked ObjectService — balanced-entry math, remainder edge cases, failed/skipped paths, idempotency pre-check (bootstrap per `tests/bootstrap.php`)
- [x] 14. Quality gates: `composer check:strict` green; in the dev container run the register import, `occ hrmq:glpost:run` against seeded data (with and without shillinq enabled), and `occ hrmq:rules:audit` — confirm the new rule is enforced and `xc-payroll-gl-reconciliation` goes green on a posted run without regressing existing rules

Acceptance criteria (plain reminders, not tasks):
- debits equal credits by construction for every emitted journal; the equation is D2's, not an ad-hoc variant
- no HTTP path to shillinq anywhere; ObjectService only
- `skipped-no-shillinq` leaves the run `approved` (retryable); `failed` likewise
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH per ADR-007

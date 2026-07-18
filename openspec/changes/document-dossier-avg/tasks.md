# Tasks — document-dossier-avg

> Verify against HEAD, not this brief — `hrmq-docudesk-documents`, `avg-dsr`, `dga-payroll-mode`, and
> `nl-id-bewaarplicht-5jaar` are already merged at HEAD; this change composes/extends them, it does not depend on
> any pending change.
>
> **Revised 2026-07-18 (hrmq#99).** Tasks 5-8 and 12 below are STRUCK — superseded by hrmq#99, which deleted
> `AvgDsrRetentionClassifier` and closed the "GeneratedDocument evades the retention guard" gap via a real
> OpenRegister legal-hold inheritance (`PayrollRetentionGuardService::inheritLegalHold()`), never a
> `GeneratedDocument.retainedUntil` field. Task 9 is narrowed to extend hrmq#99's already-shipped
> `NlDossierRetentionChecks.php` with an `Employee` entry rather than create a new file. This change's live
> remaining work is tasks 1-4, 9 (narrowed), 10 (narrowed), 11, 13 (narrowed), 14-15.

- [ ] 1. Manifest: `EmployeeDetail` gains a `GeneratedDocument` `object-list` widget (`filter: {employeeId:
  "@objectId"}`, sort `generatedAt` desc, `rowRoute: GeneratedDocumentDetail`) per REQ-DOSS-001; `npm run
  check:manifest` passes
- [ ] 2. Schema: `lib/Settings/register.d/hr-objects.json` — `Employee.loonheffingenVerklaringRetainedUntil`
  (nullable date, mirrors `identityDocumentRetainedUntil`) per REQ-DOSS-002
- [ ] 3. Standards: `NlPayrollChecks::checks()['Employee']` — `nl-loonbelastingverklaring-bewaarplicht-5jaar`,
  reusing the existing `retainedAtLeastYearsAfterEnd()` helper per REQ-DOSS-002
- [ ] 4. Standards: `lib/Standards/rules/payroll.json` — corpus entry for the new rule, `severity: recommended`,
  source citation (Uitvoeringsregeling loonbelasting 2011 art. 12.1 lid 5) per REQ-DOSS-002
- [x] ~~5. Schema: `GeneratedDocument.retainedUntil`~~ — STRUCK (hrmq#99 supersedes, design.md D3)
- [x] ~~6. Service: `HrDocumentService::generateLoonstrook()` populate `retainedUntil`~~ — STRUCK (hrmq#99
  supersedes: `HrDocumentService::generateLoonstrook()` now calls `PayrollRetentionGuardService
  ::inheritLegalHold()` instead, design.md D3)
- [x] ~~7. Service: `HrDocumentService::generateJaaropgaaf()` populate `retainedUntil`~~ — STRUCK (hrmq#99
  supersedes: `generateJaaropgaaf()` now calls `PayrollRetentionGuardService::inheritLegalHold()` for any held
  underlying Payslip, design.md D3)
- [x] ~~8. Verify AvgDsrRetentionClassifier retention-locks GeneratedDocument~~ — STRUCK, moot:
  `AvgDsrRetentionClassifier` is deleted by hrmq#99; the guarded `Gdpr\DataSubjectRequestService::erase()` checks
  the inherited legal hold directly instead (design.md D3, openspec/specs/avg-dsr/spec.md REQ-DSR-005)
- [ ] 9. Standards: EXTEND the already-shipped `lib/Standards/Checks/NlDossierRetentionChecks.php` (hrmq#99) with
  an `Employee` entry for `nl-bewaartermijn-verstreken` (`identityDocumentRetainedUntil`,
  `loonheffingenVerklaringRetainedUntil`) — do NOT create a new file, do NOT re-add a `GeneratedDocument` entry
  (hrmq#99 already registered one) per REQ-DOSS-004
- [ ] 10. Seed: `employee-jansen` gains `loonheffingenVerklaringRetainedUntil` (compliant); a NEW seeded
  `Employee` with the field unpopulated and `endDate` in the past (violated); a NEW seeded `Employee` with
  `identityDocumentRetainedUntil` in the past and still on file (ceiling violation, this change's own
  `nl-bewaartermijn-verstreken` `Employee` entry) per design.md Seed Data. The `GeneratedDocument`
  hold-inheritance/ceiling seed cases are hrmq#99's, not duplicated here.
- [ ] 11. Tests: `NlPayrollChecksTest` — `nl-loonbelastingverklaring-bewaarplicht-5jaar` vacuous/satisfied/
  violated cases per REQ-DOSS-002
- [x] ~~12. Tests: `HrDocumentServiceTest` retainedUntil population~~ — STRUCK (hrmq#99 supersedes; see
  `PayrollRetentionGuardServiceTest`/`HrDocumentServiceTest`'s hole-#1 inheritance tests instead)
- [ ] 13. Tests: `NlDossierRetentionChecksTest` — extend with vacuous/violated/satisfied cases for the NEW
  `Employee` entry only (`identityDocumentRetainedUntil`/`loonheffingenVerklaringRetainedUntil`); the
  `GeneratedDocument`/payroll-family cases are hrmq#99's own tests per REQ-DOSS-004
- [ ] 14. README: the dossier list, the loonbelastingverklaring retention sibling rule, a note that
  `GeneratedDocument` retention protection is hrmq#99's (legal-hold inheritance, not a field on this schema), the
  `Employee`-scoped ceiling check, and the explicit non-goals (no ACL/e-sign/destruction-job schemas; no
  retention signal for the four letter-type documents, sourced or unsourced) per REQ-DOSS-001/-002/-004
- [ ] 15. Quality gates: `composer check:strict` ALL CHECKS PASSED; `npm run check:manifest` PASS (0 errors);
  `npm run build` green

Acceptance criteria (plain reminders, not tasks):
- no new schema (`dossier-document`/`document-category`/`retention-policy`/`acl-grant`/`signature-request`/
  `destruction-certificate`) exists anywhere in the diff — verify by grepping `lib/Settings/register.d/*.json`
  after implementation; their absence is intentional
- `GeneratedDocument.retainedUntil` does NOT exist anywhere in the diff (hrmq#99 supersession, design.md D3) —
  the schema keeps no such field; retention protection for a generated PDF is a legal hold, not a field
- every new/changed retention period cites a specific legal source (Uitvoeringsregeling loonbelasting 2011 art.
  12.1 lid 5) in the corpus rule's `source` field — no new period is added without one
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH (ADR-007)

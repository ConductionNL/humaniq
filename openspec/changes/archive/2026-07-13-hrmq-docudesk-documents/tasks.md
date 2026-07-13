# Tasks — hrmq-docudesk-documents

- [x] 1. Schema: create fragment `lib/Settings/register.d/hr-documents.json` with the `GeneratedDocument` schema v0.1.0 (documentType enum, `$ref` employeeId, nullable `$ref` contractId, plain-string templateRef, status enum with default, nullable filePath/errorMessage/generatedAt) per REQ-HDD-001
- [x] 2. Config: add `documents_template_{documentType}` getters (empty defaults → discovery) and the `documents_employer_*` block getters (placeholder defaults) to `lib/Service/SettingsService.php` per REQ-HDD-003 / REQ-HDD-002
- [x] 3. Service: implement `lib/Service/HrDocumentService.php` — duck-typed docudesk resolve (IAppManager probe + guarded container `get()` of the DocumentService/TemplateService FQCNs) per REQ-HDD-005; verify the docudesk method signatures against the installed HEAD, not from memory
- [x] 4. Service: template selection — config-UUID first, `getTemplatesByNamespace('hrmq')` + `category === documentType` discovery second, fail-closed on zero/multiple matches, record `templateRef` per REQ-HDD-003
- [x] 5. Service: payload assembly + render call — `dataRefs` (hrmq Employee + EmploymentContract refs), `adHocData` employer/document blocks only (no flattened object data), `generateDocument(..., {format: 'pdf', userId})` per REQ-HDD-002
- [x] 6. Service: store the returned binary via OpenRegister `FileService::addFile()` on the GeneratedDocument object (`{documentType}-{employeeNumber}-{YYYY-MM-DD}.pdf`), record filePath/generatedAt/status per REQ-HDD-004; failed-store path records `failed`
- [x] 7. Service: idempotency pre-check — at most one active (pending/generated) record per (contractId|employeeId, documentType), no-op on existing `generated`, supersede stale `pending`, retries allowed after failed/skipped per REQ-HDD-006
- [x] 8. Command: `lib/Command/DocumentsGenerateCommand.php` (`hrmq:documents:generate [--type] [--employee]`, backlog = permanent+written contracts without an active arbeidsovereenkomst, employee-level types require `--employee`, per-attempt output, exit 0/1) + register in `appinfo/info.xml` `<commands>` per REQ-HDD-007
- [x] 9. Controller: `lib/Controller/DocumentController.php::generate()` with `#[NoAdminRequired]` + ObjectService contract-resolve guard (404 before any docudesk call), and the POST `/api/documents/generate` route in `appinfo/routes.php` before the catch-all per REQ-HDD-008
- [x] 10. Corpus: add `nl-contract-schriftelijk` to `lib/Standards/rules/labour.json` (labour / NL / bw7-10 / recommended / machineCheckable, BW 7:655 source) per REQ-HDD-009
- [x] 11. Checks: new `lib/Standards/Checks/NlDocumentChecks.php` provider keyed on EmploymentContract + `RuleAuditService` context enrichment (`documents.generatedArbeidsovereenkomstByContract`) per REQ-HDD-009
- [x] 12. Manifest: `GeneratedDocuments` index + `GeneratedDocumentDetail` pages (data/related/files widgets, audit sidebar), `EmployeesGroup` menu child, and the `EmploymentContractDetail` api-call action per REQ-HDD-008 / REQ-HDD-010; `npm run check:manifest` passes
- [x] 13. Seed: `contract-jansen-permanent` anchor contract + one `generated` and one `skipped-no-docudesk` GeneratedDocument (placeholders only) in `hr-documents.json` per REQ-HDD-010
- [x] 14. Unit tests: `tests/Unit/Service/HrDocumentServiceTest.php` with mocked container/services — payload assembly (REQ-HDD-002 scenario), template selection incl. ambiguity failure, duck-type skip path, render/store error paths, idempotency pre-check (bootstrap per `tests/bootstrap.php`)
- [x] 15. Quality gates: `composer check:strict` green; in the dev container run the register import, `occ hrmq:documents:generate` with and without docudesk enabled (verify the occ no-user-session storage path works — design.md Risks), the detail-page action end-to-end, and `occ hrmq:rules:audit` — confirm `nl-contract-schriftelijk` flags an undocumented written contract and passes the seeded one without regressing existing rules

Acceptance criteria (plain reminders, not tasks):
- no HTTP path to docudesk anywhere; container-resolved PHP services only, string FQCNs, zero compile-time imports
- `skipped-no-docudesk` and `failed` are retryable and never throw out of the service
- template ambiguity fails closed — never guess between templates that produce legal paper
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH per ADR-007; no Co-Authored-By trailers

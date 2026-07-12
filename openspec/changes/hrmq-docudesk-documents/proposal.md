---
kind: code
---

# HR document generation via docudesk (arbeidsovereenkomst en verklaringen als leaf)

## Why

**Routing decision — hrmq does NOT build a template engine.** The remote draft `spec/document-template-engine` (2026-05-23) proposed an in-hrmq engine: template authoring UI with merge-field validation, semver versioning with effective-dates, approval workflows, tenant partials, PDF/A archival, bulk rendering. Every one of those capabilities has since shipped in **docudesk** — template CRUD with namespace scoping, versioning, locking and duplication (`template-management`), sandboxed Twig rendering (`TemplateRenderer`), OpenRegister data resolution + huisstijl + mPDF output (`document-creatie-sjablonen`, `pdf-generation`), and even signing (`document-signing`). The draft itself conceded the overlap ("use docudesk for generic document-storage") but still duplicated the machinery. Per the ecosystem rule this change follows the `payroll-glpost-shillinq` precedent instead: the machinery lives in the sibling app; hrmq REINTEGRATES it as a duck-typed leaf — zero template/PDF code in hrmq.

The verified docudesk contract (design.md Context): templates are OpenRegister objects (docudesk `document` register, `template` schema) whose **`namespace` field is explicitly designed for other apps' template collections** (validated as a lowercase NC app id, so `hrmq` templates live in docudesk under `namespace: "hrmq"`); rendering is a plain **same-instance PHP service call** — `OCA\DocuDesk\Service\DocumentService::generateDocument($templateId, $dataRefs, $options)` — that resolves the referenced hrmq objects itself via OpenRegister, renders sandboxed Twig, and returns the PDF **binary** (docudesk does not store it; the caller does). So the integration is invocable from hrmq PHP today, no HTTP, no ExApp.

What hrmq gains: every employee lifecycle event needs templated paper (arbeidsovereenkomst, aanbiedingsbrief, werkgeversverklaring, getuigschrift). Today `EmploymentContract.writtenContract` is a bare boolean the Awf-tariff rule trusts with no document behind it — the compliance evidence gap this change closes.

## What Changes

- **New `GeneratedDocument` schema** (new fragment `lib/Settings/register.d/hr-documents.json`, v0.1.0): the hrmq-side record of one generation attempt — `documentType` (enum `arbeidsovereenkomst`/`aanbiedingsbrief`/`werkgeversverklaring`/`getuigschrift`), `employeeId` ($ref Employee), `contractId` ($ref EmploymentContract, nullable), `templateRef` (string — the docudesk template UUID used; plain string, NOT $ref, per ADR-062 rule 7 cross-register targets), `status` (`pending`/`generated`/`failed`/`skipped-no-docudesk`), `filePath` (nullable), `errorMessage`, `generatedAt`.
- **New `HrDocumentService`** (`lib/Service/HrDocumentService.php`): duck-typed docudesk consumption per the `PayrollGLPostService`/`PortalContributionProvider` pattern — probe `IAppManager::isInstalled('docudesk')` + guarded container resolve of `OCA\DocuDesk\Service\DocumentService` (string FQCN, no compile-time import); select the template (config key first, then discovery by `namespace: hrmq` + `category == documentType`); pass `dataRefs` pointing at the hrmq Employee/EmploymentContract objects plus an `adHocData` employer block; store the returned PDF binary on the `GeneratedDocument` object's folder via OpenRegister's `FileService::addFile()`; record the outcome. Inert without docudesk: `skipped-no-docudesk`, retryable, never an exception.
- **Config**: `SettingsService` getters `documents_template_{documentType}` (docudesk template UUID overrides, empty default → discovery) and `documents_employer_*` (name/address placeholders merged into every payload).
- **New occ command `hrmq:documents:generate [--type <t>] [--employee <id>]`** (`lib/Command/DocumentsGenerateCommand.php`, registered in `appinfo/info.xml` `<commands>` next to `hrmq:glpost:run`): default backlog = permanent written contracts lacking an active arbeidsovereenkomst document; other types require `--employee`.
- **One REST endpoint** `POST /api/documents/generate` (NEW `lib/Controller/DocumentController.php`, `#[NoAdminRequired]` + contract-existence guard through ObjectService) so the manifest `api-call` action (verified present in app-manifest-v2) can trigger generation from `EmploymentContractDetail`.
- **New corpus rule `nl-contract-schriftelijk`** (`lib/Standards/rules/labour.json`, machineCheckable) + NEW check provider `lib/Standards/Checks/NlDocumentChecks.php`: a permanent `EmploymentContract` with `writtenContract: true` SHALL have a `generated` arbeidsovereenkomst `GeneratedDocument` on file; `RuleAuditService` context enrichment per the `NlGlPostChecks` precedent.
- **Manifest pages**: `GeneratedDocuments` index + `GeneratedDocumentDetail` (data/related/files widgets) under the existing Personeel (`EmployeesGroup`) menu group; `EmploymentContractDetail` gains an `api-call` page action "Genereer arbeidsovereenkomst".
- **Unit tests**: PHPUnit for payload assembly, template selection, duck-type skip and error paths with mocked container/services.

### Non-goals

- **Template AUTHORING UI** — docudesk's template editor owns it; hrmq ships no authoring surface. Seeding a starter hrmq template set INTO docudesk is a follow-up (cross-app write, needs its own design).
- **Signing flow** — chainable in principle (docudesk `SigningService::createRequest` exists and the PDF lands as an OR object file) but it requires a user session and its own lifecycle; follow-up leaf.
- **Payslip PDF rendering** — the separate `spec/payslip-generation` draft owns loonstroken; not absorbed here.
- **Bulk/background generation** — docudesk's `generateBulk` + `BatchDocumentJob` exist; MVP generates per object, synchronously.
- **ODF/HTML output and huisstijl management** — PDF only in MVP; `huisstijlId` stays an optional config passthrough.

## Capabilities

### New Capabilities

- `hrmq-docudesk-documents`: the HR-document leaf — GeneratedDocument record, duck-typed docudesk rendering (same-instance PHP service call), PDF stored on the object via OpenRegister FileService, occ + api-call triggers, `nl-contract-schriftelijk` rule + check, and the document pages.

### Modified Capabilities

<!-- none — existing specs untouched; EmploymentContractDetail gains only an additive manifest action -->

## Impact

- `lib/Settings/register.d/hr-documents.json` — NEW fragment: `GeneratedDocument` schema + seed objects (auto-merged by `SettingsService` per ADR-037).
- `lib/Service/HrDocumentService.php` — NEW service (ADR-031 exception: cross-app integration, documented in design.md).
- `lib/Service/SettingsService.php` — `documents_template_{type}` + `documents_employer_*` config getters with placeholder defaults.
- `lib/Command/DocumentsGenerateCommand.php` — NEW occ command; `appinfo/info.xml` gains one `<command>` entry.
- `lib/Controller/DocumentController.php` — NEW controller (one method); `appinfo/routes.php` gains one POST route before the SPA catch-all.
- `lib/Standards/rules/labour.json` — new rule `nl-contract-schriftelijk`.
- `lib/Standards/Checks/NlDocumentChecks.php` — NEW check provider (auto-discovered by RuleEngine); `lib/Service/RuleAuditService.php` — enrich the audit `$context` with a per-contract GeneratedDocument index so the cross-object predicate stays pure.
- `src/manifest.json` — `GeneratedDocuments` + `GeneratedDocumentDetail` pages, `EmployeesGroup` menu child, `EmploymentContractDetail` api-call action.
- `tests/Unit/Service/HrDocumentServiceTest.php` — NEW unit tests.
- Cross-app dependency (duck-typed, optional): docudesk services `OCA\DocuDesk\Service\DocumentService` / `TemplateService` (contract: `docudesk/openspec/specs/document-creatie-sjablonen/spec.md` REQ-DCS-01/-02/-03/-07, `template-management`); read-only reuse of OpenRegister `FileService` for storage.

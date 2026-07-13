# Spec: hrmq-docudesk-documents

**Status:** in-progress
**Scope:** hrmq (cross-app leaf consuming docudesk's template/rendering engine via same-instance PHP services)
**Kind:** code (PHP service + occ command + controller dominate; schema fragment, corpus rule, and manifest pages ride along — see design.md "Mixed-spec rationale")

**OpenSpec changes**
- `hrmq-docudesk-documents` (2026-07-12)

## Purpose

Generate the standard Dutch HR documents (arbeidsovereenkomst, aanbiedingsbrief, werkgeversverklaring, getuigschrift) from templates hosted in docudesk (`namespace: hrmq`), by calling docudesk's `DocumentService::generateDocument()` on the same instance (never HTTP), storing the returned PDF on a new `GeneratedDocument` record via OpenRegister's FileService, degrading gracefully to `skipped-no-docudesk` when docudesk is absent, and giving `EmploymentContract.writtenContract` machine-checked document evidence (`nl-contract-schriftelijk`). hrmq builds no template engine — the `spec/document-template-engine` draft is superseded by this consumption leaf.

## ADDED Requirements

@e2e exclude backend occ/service change plus declarative manifest pages; hrmq has no app-level e2e suite yet (tracked by active change hrmq-test-coverage-baseline)

### Requirement: A `GeneratedDocument` schema SHALL record every generation attempt in a new register fragment (REQ-HDD-001)

`lib/Settings/register.d/hr-documents.json` (NEW, merged by `SettingsService` per ADR-037) SHALL declare `GeneratedDocument` v0.1.0 (`icon: FileDocumentOutline`, `x-schema-org: schema:DigitalDocument`) with: `documentType` (enum `arbeidsovereenkomst|aanbiedingsbrief|werkgeversverklaring|getuigschrift`, required), `employeeId` (string, format uuid, `$ref` Employee, required), `contractId` (string, format uuid, `$ref` EmploymentContract, nullable), `templateRef` (string, nullable — the docudesk template UUID used; plain string per ADR-062 rule 7: cross-register targets get no `$ref`), `status` (enum `pending|generated|failed|skipped-no-docudesk`, default `pending`, required), `filePath` (string, nullable), `errorMessage` (string, nullable), `generatedAt` (string, date-time, nullable).

#### Scenario: Fragment merges and validates
- **GIVEN** the hrmq register import (`occ` Repair step or forced settings reload)
- **WHEN** the fragments under `lib/Settings/register.d/` are deep-merged and imported
- **THEN** the `GeneratedDocument` schema exists in the hrmq register and an object `{documentType: "arbeidsovereenkomst", employeeId: "<uuid>", status: "pending"}` validates

#### Scenario: Unknown documentType rejected
- **WHEN** a `GeneratedDocument` is written with `documentType: "loonstrook"`
- **THEN** OpenRegister schema validation rejects it (enum violation — payslips belong to the separate payslip-generation draft, not this schema)

### Requirement: `HrDocumentService` SHALL invoke docudesk's renderer same-instance and duck-typed (REQ-HDD-002)

`lib/Service/HrDocumentService.php` (NEW) resolves `OCA\DocuDesk\Service\DocumentService` and `OCA\DocuDesk\Service\TemplateService` exclusively by string FQCN through `ContainerInterface` (no compile-time import, no composer/info.xml dependency) and calls `generateDocument(templateId, dataRefs, options)` with `dataRefs = [{register: "hrmq", schema: "Employee", id}, {register: "hrmq", schema: "EmploymentContract", id}]` (contract ref omitted when `contractId` is null) and `options = {format: "pdf", userId, adHocData: {employer: <config block>, document: {type, requestedAt}}}` — so templates read `Employee.*`, `EmploymentContract.*`, `employer.*` (the docudesk `DataResolverService` keys resolved data by the schema name as passed; verified contract, design.md Context). hrmq SHALL NOT flatten object data into `adHocData` (docudesk re-resolves the objects itself) and SHALL NOT contain any HTTP client targeting docudesk.

#### Scenario: Payload shape
- **GIVEN** an Employee and its permanent EmploymentContract
- **WHEN** the service assembles the generation call for `arbeidsovereenkomst`
- **THEN** `dataRefs` contains exactly the two hrmq object refs, `adHocData.employer` carries the configured employer block, and no Employee/Contract field values are copied into `adHocData`

#### Scenario: Same-instance only
- **GIVEN** the service implementation
- **WHEN** scanned for HTTP clients (`IClientService`, curl, guzzle) targeting docudesk
- **THEN** none exist — the only channel is the DI container resolve of docudesk's PHP services

### Requirement: Template selection SHALL be config-first, discovery-second, and fail closed (REQ-HDD-003)

Per documentType the service SHALL select the docudesk template: (1) the `SettingsService` getter for config key `documents_template_{documentType}` (a docudesk template UUID, empty default) wins when non-empty; (2) otherwise `TemplateService::getTemplatesByNamespace("hrmq")` filtered on `category === documentType` — exactly one match is used; zero or multiple matches record the attempt `failed` with an `errorMessage` naming the ambiguity, and nothing is rendered. The UUID actually used is recorded in `GeneratedDocument.templateRef`.

#### Scenario: Configured template wins
- **GIVEN** `documents_template_arbeidsovereenkomst` set to UUID `T1` while discovery would find `T2`
- **WHEN** an arbeidsovereenkomst is generated
- **THEN** the render uses `T1` and the record carries `templateRef: "T1"`

#### Scenario: Ambiguous discovery fails closed
- **GIVEN** no configured UUID and two docudesk templates with `namespace: hrmq`, `category: getuigschrift`
- **WHEN** a getuigschrift is requested
- **THEN** no render happens and the `GeneratedDocument` ends `failed` with an `errorMessage` naming both candidates

### Requirement: The returned PDF SHALL be stored on the `GeneratedDocument` object via OpenRegister's FileService (REQ-HDD-004)

On a successful render the service SHALL write the returned binary with `FileService::addFile()` into the `GeneratedDocument` object's OR folder as `{documentType}-{employeeNumber}-{YYYY-MM-DD}.pdf`, then record `filePath`, `generatedAt`, and `status: generated`. docudesk returns the binary and does not store it (verified — its own audit entry in the docudesk register is informational only). A storage failure after a successful render SHALL record `failed` with a diagnostic `errorMessage`.

#### Scenario: File lands on the object
- **GIVEN** docudesk installed with one hrmq arbeidsovereenkomst template
- **WHEN** generation succeeds for employee `EMP-0001`
- **THEN** the `GeneratedDocument` is `generated`, its OR object folder contains `arbeidsovereenkomst-EMP-0001-<date>.pdf`, and `filePath` names that file

#### Scenario: Store failure is a failed attempt
- **GIVEN** a render that succeeds but a file write that throws
- **WHEN** the attempt completes
- **THEN** the record ends `failed` with the storage error in `errorMessage` and no `filePath`

### Requirement: Absent docudesk SHALL degrade to `skipped-no-docudesk`, never an exception (REQ-HDD-005)

Availability SHALL be duck-typed (the `PayrollGLPostService` precedent): `IAppManager::isInstalled('docudesk')` plus try/catch-guarded container resolves of the two service FQCNs. Any miss SHALL record `status: skipped-no-docudesk` with an explanatory `errorMessage`; no exception propagates, nothing above INFO is logged, and the attempt is retryable once docudesk is present. hrmq SHALL gain no info.xml or composer dependency on docudesk.

#### Scenario: Instance without docudesk
- **GIVEN** a Nextcloud instance where docudesk is not installed
- **WHEN** `occ hrmq:documents:generate` processes the backlog
- **THEN** the command exits 0 and each attempt is recorded `skipped-no-docudesk` with no exception

#### Scenario: docudesk installed later
- **GIVEN** a contract whose latest `GeneratedDocument` is `skipped-no-docudesk`
- **WHEN** docudesk is installed and the command runs again
- **THEN** a new attempt renders and ends `generated` (the skip is superseded, not permanent)

### Requirement: Generation SHALL be idempotent per (contract|employee, documentType) (REQ-HDD-006)

At most one `GeneratedDocument` in `{pending, generated}` SHALL exist per (`contractId`, `documentType`) — per (`employeeId`, `documentType`) when `contractId` is null — enforced by a service pre-check: an existing `generated` record yields a no-op "already generated" outcome; a stale `pending` record is marked `failed` (superseded) and retried; `failed`/`skipped-no-docudesk` records never block a retry (design.md D6).

#### Scenario: Double invocation generates once
- **GIVEN** one permanent written contract in the backlog
- **WHEN** `occ hrmq:documents:generate` executes twice in a row
- **THEN** exactly one `generated` `GeneratedDocument` exists for that contract and the second run reports a no-op

### Requirement: An occ command SHALL be the backlog trigger (REQ-HDD-007)

`lib/Command/DocumentsGenerateCommand.php` (NEW) SHALL register `hrmq:documents:generate [--type <t>] [--employee <id>]` in `appinfo/info.xml` `<commands>` (next to `hrmq:glpost:run`). Default backlog: every `EmploymentContract` with `type: permanent` and `writtenContract: true` lacking an active arbeidsovereenkomst document; `--type` filters/switches the documentType; documentTypes other than `arbeidsovereenkomst` have no backlog semantics and require `--employee`. Output: one line per attempt plus a summary; exit `0` when every attempt ends `generated`, `skipped-no-docudesk`, or no-op, `1` when any ends `failed`.

#### Scenario: Backlog selection
- **GIVEN** two permanent written contracts (one already documented) and one temporary contract
- **WHEN** `occ hrmq:documents:generate` runs with docudesk present
- **THEN** exactly one new arbeidsovereenkomst is generated (the undocumented permanent one); the temporary contract is not selected

#### Scenario: Employee-level type requires --employee
- **WHEN** `occ hrmq:documents:generate --type werkgeversverklaring` runs without `--employee`
- **THEN** the command refuses with a usage error and generates nothing

### Requirement: One guarded endpoint SHALL back the contract-detail manifest action (REQ-HDD-008)

`appinfo/routes.php` SHALL gain `['name' => 'document#generate', 'url' => '/api/documents/generate', 'verb' => 'POST']` before the SPA catch-all; NEW `lib/Controller/DocumentController.php::generate()` SHALL carry `#[NoAdminRequired]` (CSRF protection stays on — the manifest api-call dispatcher sends the requesttoken) and, before any generation, resolves the posted `contractId` through OpenRegister's ObjectService under the caller's RBAC (unknown/unauthorized → 404, no docudesk call — no-admin-idor guard). `src/manifest.json`'s `EmploymentContractDetail` gains a page action `{type: "api-call", url: "/api/documents/generate", method: "POST", params: {contractId: "@objectId", documentType: "arbeidsovereenkomst"}, confirm: true}` with pre-translated toasts (the `api-call` action type and `@objectId` interpolation are schema-verified in app-manifest-v2).

#### Scenario: Action generates from the detail page
- **GIVEN** a permanent written contract's detail page with docudesk present
- **WHEN** the user confirms the "Genereer arbeidsovereenkomst" action
- **THEN** the endpoint returns the created `GeneratedDocument` outcome, a success toast shows, and the page refreshes

#### Scenario: Unknown contract is rejected before rendering
- **WHEN** `POST /api/documents/generate` is called with a non-existent `contractId`
- **THEN** the response is 404 and no docudesk call or `GeneratedDocument` write happens

### Requirement: A corpus rule SHALL demand document evidence for written permanent contracts (REQ-HDD-009)

`lib/Standards/rules/labour.json` gains `nl-contract-schriftelijk` (domain `labour`, jurisdiction `NL`, framework `bw7-10`, source BW art. 7:655, severity `recommended`, `machineCheckable: true`): a permanent `EmploymentContract` with `writtenContract: true` SHALL have an active `GeneratedDocument` of type `arbeidsovereenkomst` in status `generated` referencing it. NEW check provider `lib/Standards/Checks/NlDocumentChecks.php` (auto-discovered by RuleEngine) keys the predicate on `EmploymentContract` objects; `RuleAuditService` enriches the audit `$context` with `documents.generatedArbeidsovereenkomstByContract` (contractId → present) so the predicate stays a pure `fn(array $o, array $context): bool` (the `NlGlPostChecks` precedent).

#### Scenario: Undocumented written contract flagged
- **GIVEN** a permanent contract with `writtenContract: true` and no `generated` arbeidsovereenkomst
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** an `nl-contract-schriftelijk` violation is reported for that contract

#### Scenario: Documented contract passes
- **GIVEN** the seeded `contract-jansen-permanent` with its seeded `generated` arbeidsovereenkomst
- **WHEN** the audit runs
- **THEN** no `nl-contract-schriftelijk` violation is reported for it (and temporary contracts are never flagged)

### Requirement: Manifest pages SHALL expose the documents under Personeel, and seeds SHALL ship both outcome examples (REQ-HDD-010)

`src/manifest.json`: the `EmployeesGroup` menu ("Personeel") gains child `GeneratedDocuments` (label "Documenten", icon `FileDocumentOutline`); NEW pages `GeneratedDocuments` (index: columns `documentType`, `status` badge, `employeeId`, `generatedAt`; default sort `generatedAt` desc) and `GeneratedDocumentDetail` (data widget, related widget resolving Employee/EmploymentContract, files widget showing the stored PDF, audit sidebar), structured like the existing detail pages. `npm run check:manifest` MUST keep passing. `hr-documents.json` `components.objects` seeds (placeholder convention of `hr-seed.json`): the anchor `EmploymentContract` `contract-jansen-permanent` (`employeeId: "employee-jansen"`, permanent, written), one `generated` `GeneratedDocument` `gendoc-arbeidsovereenkomst-jansen` referencing it, and one `skipped-no-docudesk` `GeneratedDocument` `gendoc-werkgeversverklaring-jansen-skipped` (`contractId: null`) — all identifiers obvious placeholders.

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0 and both new pages, the menu entry, and the contract-detail action are present

#### Scenario: Idempotent seed
- **WHEN** the register import (Repair step) runs twice
- **THEN** the seeded contract and both seeded documents exist exactly once

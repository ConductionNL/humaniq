---
capability: hrmq-docudesk-documents
status: done
built_by: openspec/changes/archive/2026-07-13-hrmq-docudesk-documents
---

# hrmq-docudesk-documents Specification

**Status**: done
**Scope**: hrmq (cross-app leaf consuming docudesk's template/rendering engine via same-instance PHP services)
**OpenSpec changes**:
- [hrmq-docudesk-documents](../../changes/archive/2026-07-13-hrmq-docudesk-documents/) _(archived 2026-07-13)_ — `GeneratedDocument` record + `HrDocumentService` generating the standard HR documents (arbeidsovereenkomst, aanbiedingsbrief, werkgeversverklaring, getuigschrift) from docudesk-hosted `namespace: hrmq` templates via `DocumentService::generateDocument()` (duck-typed, same-instance, `skipped-no-docudesk` degradation), PDF stored on the object via OpenRegister FileService, occ trigger `hrmq:documents:generate` + one guarded api-call endpoint, evidence rule `nl-contract-schriftelijk`, document pages (kind: code)
- [payslip-pdf-docudesk](../../changes/archive/2026-07-14-payslip-pdf-docudesk/) _(archived 2026-07-14)_ — amends this leaf: `GeneratedDocument` v0.2.0 (`loonstrook`/`jaaropgaaf` enum values + `payslipId`/`jaaropgaafId` `$ref`s, REQ-HDD-001), generalised dataRefs assembly (REQ-HDD-002), per-payslip/per-jaaropgaaf idempotency keys (REQ-HDD-006), occ `--type loonstrook|jaaropgaaf` + `--period`/`--year` (REQ-HDD-007), payslip variant of the guarded endpoint (REQ-HDD-008)

## Purpose

Generate the standard Dutch HR documents (arbeidsovereenkomst, aanbiedingsbrief, werkgeversverklaring, getuigschrift) from templates hosted in docudesk (`namespace: hrmq`), by calling docudesk's `DocumentService::generateDocument()` on the same instance (never HTTP), storing the returned PDF on a new `GeneratedDocument` record via OpenRegister's FileService, degrading gracefully to `skipped-no-docudesk` when docudesk is absent, and giving `EmploymentContract.writtenContract` machine-checked document evidence (`nl-contract-schriftelijk`). hrmq builds no template engine — the `spec/document-template-engine` draft is superseded by this consumption leaf.

## ADDED Requirements

@e2e exclude backend occ/service change plus declarative manifest pages; hrmq has no app-level e2e suite yet (tracked by active change hrmq-test-coverage-baseline)

### Requirement: A `GeneratedDocument` schema SHALL record every generation attempt in a new register fragment (REQ-HDD-001)

`lib/Settings/register.d/hr-documents.json` (merged by `SettingsService` per ADR-037) SHALL declare `GeneratedDocument` **v0.2.0** (`icon: FileDocumentOutline`, `x-schema-org: schema:DigitalDocument`) with: `documentType` (enum `arbeidsovereenkomst|aanbiedingsbrief|werkgeversverklaring|getuigschrift|loonstrook|jaaropgaaf`, required — extended append-only by payslip-pdf-docudesk, non-breaking), `employeeId` (string, format uuid, `$ref` Employee, required), `contractId` (string, format uuid, `$ref` EmploymentContract, nullable), `payslipId` (string, format uuid, `$ref` Payslip, nullable — the wage statement a `loonstrook` record renders; null on every other type), `jaaropgaafId` (string, format uuid, `$ref` Jaaropgaaf, nullable — the annual aggregate a `jaaropgaaf` record renders; null on every other type), `templateRef` (string, nullable — the docudesk template UUID used; plain string per ADR-062 rule 7: cross-register targets get no `$ref`), `status` (enum `pending|generated|failed|skipped-no-docudesk`, default `pending`, required), `filePath` (string, nullable), `errorMessage` (string, nullable), `generatedAt` (string, date-time, nullable).

#### Scenario: Fragment merges and validates
- **GIVEN** the hrmq register import (`occ` Repair step or forced settings reload)
- **WHEN** the fragments under `lib/Settings/register.d/` are deep-merged and imported
- **THEN** the `GeneratedDocument` schema exists in the hrmq register and an object `{documentType: "arbeidsovereenkomst", employeeId: "<uuid>", status: "pending"}` validates

#### Scenario: Loonstrook documents now validate
- **WHEN** a `GeneratedDocument` is written with `{documentType: "loonstrook", employeeId: "<uuid>", payslipId: "<uuid>", status: "generated"}`
- **THEN** it validates (the pre-extension enum rejection of `loonstrook` is superseded by payslip-pdf-docudesk)

#### Scenario: Unknown documentType still rejected
- **WHEN** a `GeneratedDocument` is written with `documentType: "kerstkaart"`
- **THEN** OpenRegister schema validation rejects it (the enum stays closed; it was extended, not opened)

### Requirement: `HrDocumentService` SHALL invoke docudesk's renderer same-instance and duck-typed (REQ-HDD-002)

`lib/Service/HrDocumentService.php` SHALL resolve `OCA\DocuDesk\Service\DocumentService` and `OCA\DocuDesk\Service\TemplateService` exclusively by string FQCN through `ContainerInterface` (no compile-time import, no composer/info.xml dependency) and SHALL call `generateDocument(templateId, dataRefs, options)` with per-documentType `dataRefs` — the four letter types pass `[{register: "hrmq", schema: "Employee", id}, {register: "hrmq", schema: "EmploymentContract", id}]` (contract ref omitted when `contractId` is null); `loonstrook` passes `[{Employee}, {register: "hrmq", schema: "Payslip", id}]`; `jaaropgaaf` passes `[{Employee}, {register: "hrmq", schema: "Jaaropgaaf", id}]` — and `options = {format: "pdf", userId, adHocData: {employer: <config block, now including loonheffingennummer>, document: {type, requestedAt}}}`. Templates read the resolved objects keyed by the schema name as passed (`Employee.*`, `EmploymentContract.*`, `Payslip.*`, `Jaaropgaaf.*`, `employer.*` — the verified docudesk `DataResolverService` contract). hrmq SHALL NOT flatten object data into `adHocData` (docudesk re-resolves the objects itself) and SHALL contain no HTTP client targeting docudesk.

#### Scenario: Payload shape
- **GIVEN** an Employee and its permanent EmploymentContract
- **WHEN** the service assembles the generation call for `arbeidsovereenkomst`
- **THEN** `dataRefs` contains exactly the two hrmq object refs, `adHocData.employer` carries the configured employer block, and no Employee/Contract field values are copied into `adHocData`

#### Scenario: Per-type subject refs
- **WHEN** the service assembles a `loonstrook` and a `jaaropgaaf` generation call
- **THEN** the loonstrook `dataRefs` pair Employee with the Payslip ref, the jaaropgaaf `dataRefs` pair Employee with the Jaaropgaaf ref, and neither contains an EmploymentContract ref

#### Scenario: Same-instance only
- **GIVEN** the service implementation
- **WHEN** scanned for HTTP clients (`IClientService`, curl, guzzle) targeting docudesk
- **THEN** none exist — the only channel is the DI container resolve of docudesk's PHP services

### Requirement: Template selection SHALL be config-first, discovery-second, and fail closed (REQ-HDD-003)

Per documentType the service selects the docudesk template: (1) the `SettingsService` getter for config key `documents_template_{documentType}` (a docudesk template UUID, empty default) wins when non-empty; (2) otherwise `TemplateService::getTemplatesByNamespace("hrmq")` filtered on `category === documentType` — exactly one match is used; zero or multiple matches record the attempt `failed` with an `errorMessage` naming the ambiguity, and nothing is rendered. The UUID actually used is recorded in `GeneratedDocument.templateRef`.

#### Scenario: Configured template wins
- **GIVEN** `documents_template_arbeidsovereenkomst` set to UUID `T1` while discovery would find `T2`
- **WHEN** an arbeidsovereenkomst is generated
- **THEN** the render uses `T1` and the record carries `templateRef: "T1"`

#### Scenario: Ambiguous discovery fails closed
- **GIVEN** no configured UUID and two docudesk templates with `namespace: hrmq`, `category: getuigschrift`
- **WHEN** a getuigschrift is requested
- **THEN** no render happens and the `GeneratedDocument` ends `failed` with an `errorMessage` naming both candidates

### Requirement: The returned PDF SHALL be stored on the `GeneratedDocument` object via OpenRegister's FileService (REQ-HDD-004)

On a successful render the service writes the returned binary with `FileService::addFile()` into the `GeneratedDocument` object's OR folder as `{documentType}-{employeeNumber}-{YYYY-MM-DD}.pdf`, then records `filePath`, `generatedAt`, and `status: generated`. docudesk returns the binary and does not store it (its own audit entry in the docudesk register is informational only). A storage failure after a successful render records `failed` with a diagnostic `errorMessage`.

#### Scenario: File lands on the object
- **GIVEN** docudesk installed with one hrmq arbeidsovereenkomst template
- **WHEN** generation succeeds for employee `EMP-0001`
- **THEN** the `GeneratedDocument` is `generated`, its OR object folder contains `arbeidsovereenkomst-EMP-0001-<date>.pdf`, and `filePath` names that file

#### Scenario: Store failure is a failed attempt
- **GIVEN** a render that succeeds but a file write that throws
- **WHEN** the attempt completes
- **THEN** the record ends `failed` with the storage error in `errorMessage` and no `filePath`

### Requirement: Absent docudesk SHALL degrade to `skipped-no-docudesk`, never an exception (REQ-HDD-005)

Availability is duck-typed (the `PayrollGLPostService` precedent): `IAppManager::isInstalled('docudesk')` plus try/catch-guarded container resolves of the two service FQCNs. Any miss records `status: skipped-no-docudesk` with an explanatory `errorMessage`; no exception propagates, nothing above INFO is logged, and the attempt is retryable once docudesk is present. hrmq carries no info.xml or composer dependency on docudesk.

#### Scenario: Instance without docudesk
- **GIVEN** a Nextcloud instance where docudesk is not installed
- **WHEN** `occ hrmq:documents:generate` processes the backlog
- **THEN** the command exits 0 and each attempt is recorded `skipped-no-docudesk` with no exception

#### Scenario: docudesk installed later
- **GIVEN** a contract whose latest `GeneratedDocument` is `skipped-no-docudesk`
- **WHEN** docudesk is installed and the command runs again
- **THEN** a new attempt renders and ends `generated` (the skip is superseded, not permanent)

### Requirement: Generation SHALL be idempotent per (contract|employee, documentType) (REQ-HDD-006)

At most one `GeneratedDocument` in `{pending, generated}` SHALL exist per idempotency key, where the key is per documentType family: the four letter types key on (`contractId`, `documentType`) — on (`employeeId`, `documentType`) when `contractId` is null; **`loonstrook` keys on (`payslipId`, `documentType`)** — two payslips of the same employee each get their own loonstrook; **`jaaropgaaf` keys on (`jaaropgaafId`, `documentType`)** — one active document per aggregate, and since the `Jaaropgaaf` object is itself upserted per (employeeId, year), transitively one per employee-year. Enforced by the service pre-check: an existing `generated` record yields a no-op "already generated" outcome; a stale `pending` record is marked `failed` (superseded) and retried; `failed`/`skipped-no-docudesk` records never block a retry.

#### Scenario: Double invocation generates once
- **GIVEN** one permanent written contract in the backlog
- **WHEN** `occ hrmq:documents:generate` executes twice in a row
- **THEN** exactly one `generated` `GeneratedDocument` exists for that contract and the second run reports a no-op

#### Scenario: Loonstroken key per payslip, not per employee
- **GIVEN** one employee with two Payslips (2026-05 and 2026-06), the first already having a `generated` loonstrook
- **WHEN** the loonstrook backlog runs
- **THEN** the 2026-05 payslip no-ops and the 2026-06 payslip gets its own new loonstrook — the null-`contractId` employee-level fallback does NOT collapse them

### Requirement: An occ command SHALL be the backlog trigger (REQ-HDD-007)

`lib/Command/DocumentsGenerateCommand.php` SHALL register `hrmq:documents:generate [--type <t>] [--employee <id>] [--period <YYYY-MM>] [--year <YYYY>]` in `appinfo/info.xml` `<commands>`. Default backlog (no options): every `EmploymentContract` with `type: permanent` and `writtenContract: true` lacking an active arbeidsovereenkomst document — unchanged. `--type` filters/switches the documentType: the letter types other than `arbeidsovereenkomst` have no backlog semantics and require `--employee`; **`--type loonstrook`** has real backlog semantics — every `Payslip` lacking an active loonstrook document, optionally narrowed by `--period` and/or `--employee`; **`--type jaaropgaaf`** REQUIRES `--year` and processes every employee with at least one payslip in that year (or just `--employee`), aggregating then rendering per employee. Option misuse — `--period` with any type but loonstrook, `--year` with any type but jaaropgaaf, or `--type jaaropgaaf` without `--year` — yields a `usage-error` outcome, exit 1, nothing generated. Output: one line per attempt plus a summary; exit `0` when every attempt ends `generated`, `skipped-no-docudesk`, or no-op, `1` when any ends `failed` or `usage-error`.

#### Scenario: Backlog selection
- **GIVEN** two permanent written contracts (one already documented) and one temporary contract
- **WHEN** `occ hrmq:documents:generate` runs with docudesk present
- **THEN** exactly one new arbeidsovereenkomst is generated (the undocumented permanent one); the temporary contract is not selected

#### Scenario: Employee-level type requires --employee
- **WHEN** `occ hrmq:documents:generate --type werkgeversverklaring` runs without `--employee`
- **THEN** the command refuses with a usage error and generates nothing

#### Scenario: Loonstrook backlog by period
- **GIVEN** three Payslips for 2026-05 (one already documented) and two for 2026-06
- **WHEN** `occ hrmq:documents:generate --type loonstrook --period 2026-05` runs with docudesk present
- **THEN** exactly two new loonstroken are generated (the undocumented 2026-05 ones); the 2026-06 payslips are not selected

#### Scenario: Jaaropgaaf requires --year
- **WHEN** `occ hrmq:documents:generate --type jaaropgaaf` runs without `--year`
- **THEN** the command refuses with a usage error and neither aggregates nor generates anything

### Requirement: One guarded endpoint SHALL back the contract-detail manifest action (REQ-HDD-008)

`appinfo/routes.php` SHALL keep its single `['name' => 'document#generate', 'url' => '/api/documents/generate', 'verb' => 'POST']` entry before the SPA catch-all — the endpoint stays singular with optional params, NO new route (payslip-pdf-docudesk design.md D6). `lib/Controller/DocumentController.php::generate()` carries `#[NoAdminRequired]` (CSRF protection stays on — the manifest api-call dispatcher sends the requesttoken) and dispatches on `documentType`: the letter types require `contractId`, resolved via `authorizeContract()` under the caller's RBAC (unknown/unauthorized → 404, no docudesk call); **`loonstrook` requires `payslipId`, resolved via `authorizePayslip()` — the identical RBAC-scoped ObjectService resolution against the `Payslip` schema** (unknown/unauthorized → 404 before any docudesk call; `employeeId` is taken from the resolved payslip and `contractId` stays null); a missing subject param for the requested type → 400; `jaaropgaaf` is NOT accepted on this endpoint (occ-only in MVP). `src/manifest.json` carries both page actions: `EmploymentContractDetail` "Genereer arbeidsovereenkomst" (`params: {contractId: "@objectId", documentType: "arbeidsovereenkomst"}`) and `PayslipDetail` "Genereer PDF" (`params: {payslipId: "@objectId", documentType: "loonstrook"}`), each `{type: "api-call", method: "POST", confirm: true}` with pre-translated toasts.

#### Scenario: Action generates from the detail page
- **GIVEN** a permanent written contract's detail page with docudesk present
- **WHEN** the user confirms the "Genereer arbeidsovereenkomst" action
- **THEN** the endpoint returns the created `GeneratedDocument` outcome, a success toast shows, and the page refreshes

#### Scenario: Unknown contract is rejected before rendering
- **WHEN** `POST /api/documents/generate` is called with a non-existent `contractId`
- **THEN** the response is 404 and no docudesk call or `GeneratedDocument` write happens

#### Scenario: Payslip action generates from the payslip page
- **GIVEN** a payslip's detail page with docudesk present
- **WHEN** the user confirms the "Genereer PDF" action
- **THEN** `POST /api/documents/generate` receives `{payslipId, documentType: "loonstrook"}`, the payslip resolves under the caller's RBAC, and the outcome names the created loonstrook `GeneratedDocument`

#### Scenario: Unknown payslip is rejected before rendering
- **WHEN** `POST /api/documents/generate` is called with `documentType: "loonstrook"` and a non-existent `payslipId`
- **THEN** the response is 404 and no docudesk call or `GeneratedDocument` write happens

### Requirement: A corpus rule SHALL demand document evidence for written permanent contracts (REQ-HDD-009)

`lib/Standards/rules/labour.json` gains `nl-contract-schriftelijk` (domain `labour`, jurisdiction `NL`, framework `bw7-10`, source BW art. 7:655, severity `recommended`, `machineCheckable: true`): a permanent `EmploymentContract` with `writtenContract: true` shall have an active `GeneratedDocument` of type `arbeidsovereenkomst` in status `generated` referencing it. Check provider `lib/Standards/Checks/NlDocumentChecks.php` (auto-discovered by RuleEngine) keys the predicate on `EmploymentContract` objects; `RuleAuditService` enriches the audit `$context` with `documents.generatedArbeidsovereenkomstByContract` (contractId → present) so the predicate stays a pure `fn(array $o, array $context): bool` (the `NlGlPostChecks` precedent).

#### Scenario: Undocumented written contract flagged
- **GIVEN** a permanent contract with `writtenContract: true` and no `generated` arbeidsovereenkomst
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** an `nl-contract-schriftelijk` violation is reported for that contract

#### Scenario: Documented contract passes
- **GIVEN** the seeded `contract-jansen-permanent` with its seeded `generated` arbeidsovereenkomst
- **WHEN** the audit runs
- **THEN** no `nl-contract-schriftelijk` violation is reported for it (and temporary contracts are never flagged)

### Requirement: Manifest pages SHALL expose the documents under Personeel, and seeds SHALL ship both outcome examples (REQ-HDD-010)

`src/manifest.json`: the `EmployeesGroup` menu ("Personeel") gains child `GeneratedDocuments` (label "Documenten", icon `FileDocumentOutline`); pages `GeneratedDocuments` (index: columns `documentType`, `status` badge, `employeeId`, `generatedAt`; default sort `generatedAt` desc) and `GeneratedDocumentDetail` (data widget, related widget resolving Employee/EmploymentContract, sidebar with the default Files tab + Audit Trail tab via `hiddenTabs: ["notes", "tags", "tasks"]`), structured like the existing detail pages. `hr-documents.json` `components.objects` seeds (placeholder convention of `hr-seed.json`): the anchor `EmploymentContract` `contract-jansen-permanent` (`employeeId: "employee-jansen"`, permanent, written), one `generated` `GeneratedDocument` `gendoc-arbeidsovereenkomst-jansen` referencing it, and one `skipped-no-docudesk` `GeneratedDocument` `gendoc-werkgeversverklaring-jansen-skipped` (`contractId: null`) — all identifiers obvious placeholders.

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0 and both new pages, the menu entry, and the contract-detail action are present

#### Scenario: Idempotent seed
- **WHEN** the register import (Repair step) runs twice
- **THEN** the seeded contract and both seeded documents exist exactly once

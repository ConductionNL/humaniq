# Delta: hrmq-docudesk-documents (extended by payslip-pdf-docudesk)

The docudesk consumption leaf gains two documentTypes (`loonstrook`, `jaaropgaaf`) whose subjects are a Payslip / a Jaaropgaaf aggregate instead of an EmploymentContract. Five requirements widen; nothing the leaf shipped regresses — the four letter types, their backlog, their endpoint path, and their idempotency keying stay byte-for-byte compatible. Note (design.md Risks): requirement TITLES stay stable so MODIFIED matches by header; REQ-HDD-006's title still names the original key shape while its body now defines the per-family keys.

## MODIFIED Requirements

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

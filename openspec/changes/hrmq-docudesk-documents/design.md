# Design — hrmq-docudesk-documents

## Context

**docudesk side (verified against `apps-extra/docudesk` HEAD, 2026-07-12):**

- **Template storage/identity**: templates are OpenRegister objects in docudesk's `document` register, `template` schema (register/schema ids resolved at runtime from docudesk settings `template_register`/`template_schema` via `OpenRegisterResolver`; the schema slugs are declared in `docudesk/lib/Settings/docudesk_register.json`). Required fields `name`, `content` (sandboxed Twig/HTML), **`namespace`** — validated as a lowercase-alphanumeric NC app id and explicitly designed so "multiple apps maintain their own template collections" (`TemplateService` header; `getTemplatesByNamespace('larpingapp')` is the in-repo example). Optional `format`/`orientation` (page setup), `category`, `tags`, plus lock fields. A template is identified by its **object UUID**.
- **Rendering invocation**: `OCA\DocuDesk\Service\DocumentService::generateDocument(string $templateId, array $dataRefs, array $options): array` — a plain PHP service on the same instance, resolvable from the DI container. `$dataRefs` is `[{register, schema, id}, ...]`; docudesk's `DataResolverService` fetches each object via OpenRegister's ObjectService and keys the resolved data **by the schema name as passed** (so a ref `{register: hrmq, schema: Employee, id}` surfaces in the template as `Employee.firstName`); `$options.adHocData` merges on top at top level (our `employer.*` block). `$options`: `format` (`pdf`|`odf`|`html`, default `pdf`), `huisstijlId`, `pdfOptions`, `userId`, `zaakId`. Twig rendering is sandboxed (`TemplateRenderer`: whitelisted tags/filters/functions, no method/property calls); PDF is produced by mPDF (`PdfService`, PDF/A-3b capable). There is also a REST surface (`POST /apps/docudesk/api/documents/generate`) — NOT used here; same-instance PHP is the channel, mirroring the no-HTTP rule from payroll-glpost-shillinq.
- **Output**: `generateDocument()` **returns the PDF binary** in `['content']` (plus `format`, `metadata`, `warnings`) and throws on failure. docudesk does **not** store the file; it only logs an audit entry into its own register (`document`/`generatedDocument`: templateId+version, dataRefs, status, generatedBy). Storage is the caller's job.
- **Signing**: chainable in principle — `SigningService::createRequest()` exists but requires an authenticated user session and its own request lifecycle; out of MVP scope (Non-Goals).

**hrmq side (verified against HEAD):** `Employee` (name/BSN/salary/dates…) and `EmploymentContract` (`employeeId` $ref, `type` enum `permanent|temporary|agency|minijob`, **`writtenContract` boolean** — today only a precondition input to `nl-awf-laag-hoog-tarief`, with no document evidence behind it, `startDate`/`endDate`/`hoursPerWeek`/`hourlyWage`/`cao`…) in `lib/Settings/register.d/hr-objects.json`. Register slug `hrmq` (`SettingsService::getRegisterSlug()`). `appinfo/routes.php` has only the SPA shell + manifest + catch-all (3 routes); `PageController` shows the attribute idiom. Storage primitive: OpenRegister's `FileService::addFile(ObjectEntity|string $objectEntity, string $fileName, string $content, …): File` writes a binary into an object's own folder — the established files-leaf that the manifest `files` widget and sidebar already render.

**Integration pattern precedent:** `PayrollGLPostService` (payroll-glpost-shillinq D7) — duck-typed availability probe, `skipped-no-*` degradation, zero hard dependency; `PortalContributionProvider` (ADR-046) for the philosophy. Manifest `api-call` action verified in `app-manifest-v2.schema.json` (#91 Wave 3): POST/PUT an app endpoint with `@objectId` token interpolation, confirm gating, toasts, auto page-refresh.

**Old draft routed away:** `origin/spec/document-template-engine` proposed the engine inside hrmq (authoring UI, merge-field validation, versioning, approval workflows, PDF/A, bulk). Everything on that list now ships in docudesk; building it twice violates the leaf rule. The draft is superseded by this consumption leaf, not modernised.

## Goals / Non-Goals

**Goals:** generate the four standard HR documents from docudesk-hosted templates with hrmq data; PDF stored on the GeneratedDocument object (files widget shows it); auditable hrmq-side record per attempt; graceful no-docudesk degradation; `writtenContract` gets machine-checked document evidence (`nl-contract-schriftelijk`); occ + detail-page triggers.

**Non-Goals:** template authoring UI (docudesk's), seeding starter templates into docudesk (follow-up — cross-app write), signing flow (follow-up leaf; needs a user session), payslip PDFs (`spec/payslip-generation` draft owns them), bulk/background generation, ODF/HTML output, huisstijl management.

## Decisions

### D1 — hrmq is a LEAF: it calls docudesk's renderer, it does not render

hrmq holds **no** Twig, mPDF, template, or versioning machinery. The only document artefact hrmq owns is `GeneratedDocument` — a *log of the handoff* (which template, for whom, outcome, where the file landed). Templates are authored and versioned in docudesk under `namespace: "hrmq"`; rendering happens inside docudesk's sandbox; hrmq contributes only the data payload and stores the returned binary. Exactly the payroll-glpost-shillinq division of authority, with docudesk instead of shillinq.

### D2 — Invocation contract (same-instance PHP, duck-typed)

`HrDocumentService` resolves docudesk classes exclusively by **string FQCN** through `ContainerInterface` (`'OCA\DocuDesk\Service\DocumentService'`, `'OCA\DocuDesk\Service\TemplateService'`) — no `use` import, no composer/info.xml dependency. Call: `generateDocument(templateId, dataRefs, options)` with `dataRefs = [{register: 'hrmq', schema: 'Employee', id: <employeeId>}, {register: 'hrmq', schema: 'EmploymentContract', id: <contractId>}]` (contract ref omitted when `contractId` is null) and `options = {format: 'pdf', userId: <acting user or 'system'>, adHocData: {employer: {…config}, document: {type, requestedAt}}}`. Template variables therefore read `Employee.firstName`, `EmploymentContract.startDate`, `employer.name` — documented as the template-author contract on the config keys. docudesk re-resolves the objects itself via OpenRegister (its own audit entry then carries the dataRefs), so hrmq does NOT flatten object data into adHocData — one source of truth, no drift.

### D3 — Template selection: config first, discovery second, fail closed

Per documentType: (1) config key `documents_template_{documentType}` (a docudesk template UUID, admin-set) wins when non-empty; (2) otherwise discovery — `TemplateService::getTemplatesByNamespace('hrmq')` filtered on `category === documentType`: exactly one match → use it; zero or multiple → record `failed` with a diagnostic naming the ambiguity (never guess between templates that produce legal paper). The UUID actually used is recorded in `GeneratedDocument.templateRef` (plain string, ADR-062 rule 7 — cross-register target, no `$ref`).

### D4 — Output storage: OpenRegister FileService onto the GeneratedDocument object

The returned PDF binary is written via `FileService::addFile($generatedDocumentObject, $fileName, $binary)` into the GeneratedDocument object's OR folder; `filePath` records the returned file path and `status` flips to `generated` with `generatedAt`. Filename: `{documentType}-{employeeNumber}-{YYYY-MM-DD}.pdf`. Rationale: the object folder is the established files-leaf (detail page `files` widget + sidebar render it, RBAC follows the object), needs no user-session home directory (occ-safe), and keeps the document attached to its audit record. A failure in the store step records `failed` (with the docudesk render already logged on docudesk's side — acceptable duplication of audit trails, noted in Risks).

### D5 — Degradation and retryability (`skipped-no-docudesk`)

Availability probe: `IAppManager::isInstalled('docudesk')` AND a try/catch-guarded container resolve of the two service FQCNs. Any miss → record `GeneratedDocument` with `status: skipped-no-docudesk` and a human `errorMessage`; no exception, nothing above INFO in the log. Skips and failures are retryable: the next trigger supersedes them with a fresh attempt (idempotency below). hrmq keeps zero hard dependency on docudesk.

### D6 — Idempotency: at most one active document per (contract|employee, type)

Invariant: at most one `GeneratedDocument` in `{pending, generated}` per (`contractId`, `documentType`) — or per (`employeeId`, `documentType`) when `contractId` is null — enforced by a service pre-check (already-generated → no-op outcome "already generated"; stale `pending` older than the attempt window → marked `failed` superseded, retried). `failed`/`skipped-no-docudesk` never block a retry. No cross-app probe layer is needed (unlike glpost D6): docudesk's audit entry is informational, not an idempotency anchor — the file on the hrmq object is the source of truth.

### D7 — Triggers: occ backlog command + one guarded endpoint for the manifest action

- **occ** `hrmq:documents:generate [--type <t>] [--employee <id>]` (`appinfo/info.xml` `<commands>`): with no options it processes the `nl-contract-schriftelijk` backlog — every `EmploymentContract` with `type: permanent`, `writtenContract: true` and no active arbeidsovereenkomst document; `--type` restricts/switches the documentType; types other than `arbeidsovereenkomst` have no backlog semantics and require `--employee`. One outcome line per attempt + summary; exit `0` when every attempt ends `generated`/`skipped-no-docudesk` (or no-op), `1` when any ends `failed`.
- **Endpoint** `POST /api/documents/generate` `{contractId, documentType}` → `DocumentController::generate()`, `#[NoAdminRequired]` (CSRF stays on — the api-call dispatcher sends the requesttoken via the shared axios client). Guard (no-admin-idor gate): the contract must resolve through ObjectService under the caller's OR RBAC before any generation; unknown id → 404, no docudesk call. The manifest `EmploymentContractDetail` page action (type `api-call`, confirm: true) posts `{contractId: '@objectId', documentType: 'arbeidsovereenkomst'}`. No lifecycle hook in MVP (same rationale as glpost D5 — no schema lifecycle to hang it on yet).

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `GeneratedDocument` data model + statuses | declarative schema (`hr-documents.json` fragment) | ADR-031 default |
| Template selection + docudesk render call + file store | **imperative `HrDocumentService`** | **ADR-031 exception: external/cross-app integration** — duck-typed multi-step call into ANOTHER app's service with binary handling cannot be a declarative lifecycle action; same exception class as `PayrollGLPostService` |
| Triggers | imperative occ command + one thin controller (D7) | no lifecycle on EmploymentContract; the endpoint exists solely because the manifest `api-call` action needs an app URL |
| Document-evidence audit | imperative CheckProvider predicate (`NlDocumentChecks`) | the app's established rule-corpus exception |
| Document pages + contract action | declarative manifest | ADR-031 default (`api-call` is a declarative action type) |

### Mixed-spec rationale (kind: code)

`kind: code`: the PHP surface dominates (service + command + controller + check provider + RuleAuditService enrichment + unit tests) while the config surface (one schema fragment, one rule row, two pages + one action) rides along — same yellow-flag precedent as payroll-glpost-shillinq and hrmq-rule-compliance-enforcement; splitting the ~100-line fragment into a `kind: config` change would only create an artificial ordering dependency.

## Schema delta (new fragment `lib/Settings/register.d/hr-documents.json`)

`GeneratedDocument` v0.1.0, icon `FileDocumentOutline`, `x-schema-org: schema:DigitalDocument`; required `[documentType, employeeId, status]`:

| Field | Type | Notes |
|---|---|---|
| `documentType` | enum `arbeidsovereenkomst\|aanbiedingsbrief\|werkgeversverklaring\|getuigschrift` | required — which standard HR document |
| `employeeId` | string, format uuid, `$ref` Employee | required — whose document |
| `contractId` | string, format uuid, `$ref` EmploymentContract, nullable | the contract it evidences (required in practice for `arbeidsovereenkomst`; null for employee-level types) |
| `templateRef` | string, nullable | docudesk template UUID actually used — plain string, NOT `$ref` (cross-register target; ADR-062 rule 7) |
| `status` | enum `pending\|generated\|failed\|skipped-no-docudesk`, default `pending` | outcome; failed/skipped are superseded by retries (D6) |
| `filePath` | string, nullable | path of the stored PDF in the object's OR folder (D4) |
| `errorMessage` | string, nullable | failure/skip diagnostic |
| `generatedAt` | string, date-time, nullable | when the PDF was produced and stored |

## New corpus rule (`lib/Standards/rules/labour.json`)

| id | domain | jurisdiction | framework | severity | machineCheckable | statement (short) |
|---|---|---|---|---|---|---|
| `nl-contract-schriftelijk` | labour | NL | bw7-10 | recommended | true | A permanent employment contract recorded as written (`writtenContract: true`) shall have its arbeidsovereenkomst document on file — an active `GeneratedDocument` of type `arbeidsovereenkomst` in status `generated` referencing the contract — so the written-form precondition other rules rely on (nl-awf-laag-hoog-tarief) is evidenced, not merely asserted. |

Source: BW art. 7:655 (schriftelijke opgave verplichtingen werkgever), `sourceUrl: https://wetten.overheid.nl/BWBR0005290` — matching the sibling labour rows.

**Check plumbing (NlGlPostChecks precedent):** predicates are `fn(array $o, array $context): bool`; cross-object evidence needs context. `RuleAuditService::audit()` pre-loads GeneratedDocuments and injects `$context['documents']['generatedArbeidsovereenkomstByContract']` (contractId → true when an active `generated` arbeidsovereenkomst exists); NEW provider `NlDocumentChecks` keys the check on `EmploymentContract` objects: violates when `type === 'permanent' && writtenContract === true` and the context has no entry for the contract's id. (Checked on the contract, not the document — the violation is a *missing* document.)

## Manifest delta (`src/manifest.json`)

- Menu: `EmployeesGroup` ("Personeel") gains child `{id: GeneratedDocuments, label: "Documenten", icon: FileDocumentOutline, route: GeneratedDocuments}`.
- `GeneratedDocuments` (index): columns `documentType`, `status` (badge), `employeeId`, `generatedAt`; default sort `generatedAt` desc.
- `GeneratedDocumentDetail`: data widget (all schema fields incl. `errorMessage`/`templateRef`), related widget (resolves Employee + EmploymentContract), **files widget** (the stored PDF — the whole point), audit sidebar tab. Structure mirrors `PayrollGLPostDetail`/`EmploymentContractDetail`.
- `EmploymentContractDetail`: page-level `actions` entry `{type: "api-call", label: "Genereer arbeidsovereenkomst", icon: FileDocumentPlusOutline, url: "/api/documents/generate", method: POST, params: {contractId: "@objectId", documentType: "arbeidsovereenkomst"}, confirm: true, successMessage/errorMessage pre-translated}` (schema-verified: api-call supports app-relative URLs, `@objectId` interpolation, confirm gating, toasts, auto refresh).
- `npm run check:manifest` MUST keep passing.

## Seed Data (ADR-001)

`hr-documents.json` `components.objects` (placeholder convention of `hr-seed.json`):

1. `EmploymentContract` slug `contract-jansen-permanent` — `employeeId: "employee-jansen"` (the existing hr-seed Employee), `type: permanent`, `writtenContract: true`, plausible placeholder dates/wage — the anchor the seeded document and the corpus rule need.
2. `GeneratedDocument` slug `gendoc-arbeidsovereenkomst-jansen` — `documentType: arbeidsovereenkomst`, `employeeId: "employee-jansen"`, `contractId: "contract-jansen-permanent"`, `status: generated`, `templateRef: "docudesk-template-placeholder-uuid"`, `filePath: "arbeidsovereenkomst-EMP-0001-2026-01-15.pdf"`, `generatedAt: "2026-01-15T10:00:00Z"` — the rule's green example (no real file behind the placeholder path; the seed evidences the record shape, and the audit predicate reads the record, not the folder).
3. `GeneratedDocument` slug `gendoc-werkgeversverklaring-jansen-skipped` — `documentType: werkgeversverklaring`, `employeeId: "employee-jansen"`, `contractId: null`, `status: skipped-no-docudesk`, `errorMessage` explaining docudesk was absent, `filePath: null`, `generatedAt: null` — the degradation example.

All identifiers are obvious placeholders.

## Risks / Trade-offs

- **Two audit trails**: docudesk logs its own `generatedDocument` entry per render; hrmq keeps `GeneratedDocument`. They are complementary (docudesk: template version + dataRefs; hrmq: HR semantics + file), not synchronized — documented, acceptable.
- **Template-author contract is conventional**: variables (`Employee.*`, `EmploymentContract.*`, `employer.*`) and `category == documentType` discovery are conventions between this spec and template authors in docudesk; a renamed hrmq field silently renders empty (docudesk Twig runs `strict_variables: false`). Mitigation: the config-key docs state the variable contract; template seeding follow-up will pin it.
- **occ user context**: `generateDocument` and `FileService::addFile` run without a user session under occ (docudesk falls back to `'system'`; OR object folders are system-owned). Verified-by-running is a task-14 gate, not an assumption.
- **`writtenContract` semantics**: the rule reads it as "contract is in writing → the writing should be on file". A tenant whose written contract exists only on paper can attach it manually to the object folder later; MVP's predicate accepts only `generated` records — the stricter reading — and the rule severity is `recommended`, not `mandatory`, precisely for that case.
- **Endpoint scope**: one POST endpoint re-opens the "no domain routes" note in `appinfo/routes.php`; kept because the declarative `api-call` action needs a URL, guarded, and single.

## Open Questions

- None blocking. Starter-template seeding into docudesk, signing chain, aanbiedingsbrief/getuigschrift detail-page actions (Employee-level), and event-driven generation are tracked as follow-ups.

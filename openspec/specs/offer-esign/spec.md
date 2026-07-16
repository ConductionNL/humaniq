---
capability: offer-esign
status: done
built_by: openspec/changes/archive/2026-07-15-offer-esign
---

# offer-esign Specification

**Status**: done
**Scope**: hrmq (offer-letter generation + e-signature request for recruiting `Application`s at the `aanbod` stage, through the existing docudesk consumption leaf)
**OpenSpec changes**:
- [offer-esign](../../changes/archive/2026-07-15-offer-esign/) _(archived 2026-07-15)_ — `Application` v0.3.0 (additive `offerLetterFileId`/`offerSigningRequestId`/`offerSigningStatus`), new `OfferEsignService` (duck-typed docudesk probe, config-first/discovery-second/fail-closed `aanbiedingsbrief` template selection, render+store via `FileService::addFile()`→real `File::getId()`, `SigningService::createRequest()` with the verified real `signers` field shape + provenance fields, single-slot idempotency, no-auto-hire boundary), two occ commands (`hrmq:offer:request-signature`/`hrmq:offer:sync-signature`), one guarded `OfferController` endpoint, `ApplicationDetail` manifest action, one seed (kind: code)

## Purpose

Close the MVP gap the `Application.aanbieden` transition's own docblock names ("An offer is extended to the candidate. No offer-letter generation/e-signature in the MVP."): generate a real offer-letter PDF via docudesk from `Application`+`Vacancy` data and raise a real docudesk signing request, tracked directly on the `Application` object (`offerLetterFileId`/`offerSigningRequestId`/`offerSigningStatus`), duck-typed optional and idempotent — while stating plainly what this change does NOT claim: candidate self-service signing completion, webhook-driven status updates, and auto-hire on completion all stay explicitly out of scope.

## ADDED Requirements

@e2e exclude backend occ/service/controller change plus declarative manifest action; hrmq has no app-level e2e suite yet (tracked by active change hrmq-test-coverage-baseline)

### Requirement: `Application` SHALL gain additive offer-signing tracking fields (REQ-OFFR-001)

`lib/Settings/register.d/hr-ats.json` SHALL bump `Application` from v0.2.0 to v0.3.0 with three new nullable, additive properties, each carrying title and description: `offerLetterFileId` (integer — the Nextcloud file id of the generated offer-letter PDF, null until a letter is stored), `offerSigningRequestId` (string — the docudesk signing-request id, a plain string and NOT a `$ref`, since docudesk owns a different register/app than the in-register `$ref` targets ADR-062 rule 7 governs), and `offerSigningStatus` (string, nullable, enum `PENDING`/`IN_PROGRESS`/`COMPLETED`/`DECLINED`/`CANCELLED`/`EXPIRED`/`skipped-no-docudesk`/`failed` — the first six verbatim from docudesk's own `SigningService::STATUS_TRANSITIONS` vocabulary, the last two this leaf's own duck-typed/failure outcomes; null means no offer-esign attempt has been made). Neither `Application.required` nor its `x-openregister-lifecycle` block changes — these fields are orthogonal to the pipeline `status` state machine.

#### Scenario: Fragment merges and validates
- **GIVEN** the hrmq register import
- **WHEN** the fragments under `lib/Settings/register.d/` are deep-merged and imported
- **THEN** `Application` validates at v0.3.0 and an object with `offerLetterFileId: null, offerSigningRequestId: null, offerSigningStatus: null` validates

#### Scenario: Existing Applications are unaffected
- **GIVEN** the three seeded Applications from `recruiting-ats-basic` (`application-voorbeeld-nieuw`/`-afgewezen`/`-aangenomen`)
- **WHEN** the register import runs after this change
- **THEN** all three still validate with the three new fields defaulting to null, and no existing field or transition is altered

### Requirement: `OfferEsignService` SHALL generate the offer letter from Application/Vacancy data and store it, duck-typed optional (REQ-OFFR-002)

`OfferEsignService::requestSignature(string $applicationId, ?string $userId=null): array` SHALL first resolve the Application and reject any status other than `aanbod` with a `usage-error` outcome (nothing generated). When docudesk is unavailable — `IAppManager::isInstalled('docudesk')` is false, or `OCA\DocuDesk\Service\DocumentService`, `TemplateService`, or `SigningService` cannot be resolved from the container — the outcome SHALL be `skipped-no-docudesk` and the method SHALL NEVER throw. Otherwise it SHALL select a docudesk template via the config-first (`SettingsService::getDocumentsTemplateId('aanbiedingsbrief')`) / discovery-second (`TemplateService::getTemplatesByNamespace('hrmq')` filtered `category === 'aanbiedingsbrief'`) / fail-closed algorithm (zero or multiple matches → `failed`, never guess), render with `dataRefs = [{register, schema: "Application", id: applicationId}, {register, schema: "Vacancy", id: vacancyId}]` and `adHocData` carrying only the `employer` block plus `document.type: 'aanbiedingsbrief'` (no candidate PII flattened), verify non-empty rendered content, and store the PDF via `FileService::addFile()`, capturing the returned `File::getId()` as `offerLetterFileId` — the real Nextcloud file id, not merely a path. (Implementation note: the template-select/render/store mechanics live in a composed collaborator, `OfferLetterService`, to keep `OfferEsignService`'s own cyclomatic/class complexity under the fleet's PHPMD gates — `OfferEsignService::requestSignature()` remains the single public entry point this requirement governs.)

#### Scenario: Wrong stage rejected
- **GIVEN** an Application in status `gesprek`
- **WHEN** `requestSignature()` is called for it
- **THEN** the outcome is `usage-error`, no template is selected, and no docudesk call is made

#### Scenario: Docudesk absent degrades gracefully
- **GIVEN** an Application in status `aanbod` and docudesk not installed
- **WHEN** `requestSignature()` is called
- **THEN** the outcome is `skipped-no-docudesk`, `offerSigningStatus` is set to `skipped-no-docudesk`, `offerLetterFileId` stays null, and no exception is thrown

#### Scenario: dataRefs assembly carries no Employee ref
- **GIVEN** an Application in status `aanbod` referencing a Vacancy, with docudesk available
- **WHEN** the service assembles the generation call
- **THEN** `dataRefs` contains exactly the Application and Vacancy refs (no Employee ref — none exists pre-hire), and `adHocData` carries no `Application.*`/`Vacancy.*` field values

#### Scenario: Successful generation stores a real Nextcloud file id
- **GIVEN** a resolvable `aanbiedingsbrief` template and a successful render
- **WHEN** the PDF is stored
- **THEN** `Application.offerLetterFileId` is set to the integer returned by `File::getId()` on the stored file, not a path string

### Requirement: `OfferEsignService` SHALL request an e-signature via docudesk's real `createRequest()` contract (REQ-OFFR-003)

Immediately after a successful letter generation (REQ-OFFR-002), `OfferEsignService` SHALL call `OCA\DocuDesk\Service\SigningService::createRequest(array $data): array` with the VERIFIED real field shape — `documentFileId` (the stored file id), `documentName`, `signatureLevel: 'SES'`, `signingMode: 'sequential'`, `deadline` (now plus `SettingsService::getOfferSigningDeadlineDays()`), and `signers` — an array of `{userId, displayName, email, order}` (NOT a `signerIds` field, which `createRequest()` does not accept as input; `signerIds` is a field `createRequest()` derives internally from the created `signers` and returns) — with exactly one entry for the candidate: `{userId: '', displayName: candidateName, email, order: 0}`. The call SHALL also thread the seven optional provenance fields `createRequest()` accepts directly (`sourceApp: 'hrmq'`, `subjectRegister`, `subjectSchema: 'Application'`, `subjectId: applicationId`, `subjectLabel: candidateName`, `externalReference: applicationId`, `correlationId: applicationId`). The entire write sub-pipeline (any idempotent supersede plus this call) SHALL be wrapped so that `RuntimeException('No authenticated user')` — thrown by `createRequest()`/`cancelRequest()` whenever `IUserSession::getUser()` is null, which is ALWAYS true for a genuine `occ` CLI process — is caught and turned into a `failed` outcome carrying the real exception message, never allowed to escape uncaught. On success, `Application.offerSigningRequestId` and `offerSigningStatus` (from the created request's own `status`) SHALL be persisted.

#### Scenario: Real signers payload, not signerIds
- **GIVEN** a successfully generated offer letter for candidate "Sanne Voorbeeld" (sanne.voorbeeld@example.org)
- **WHEN** the service calls `createRequest()`
- **THEN** the passed `$data['signers']` is `[{userId: '', displayName: 'Sanne Voorbeeld', email: 'sanne.voorbeeld@example.org', order: 0}]` and `$data` contains no `signerIds` key

#### Scenario: Provenance fields correlate back to hrmq
- **WHEN** `createRequest()` is called
- **THEN** `$data['sourceApp'] === 'hrmq'`, `$data['subjectSchema'] === 'Application'`, and `$data['subjectId']` equals the Application's id

#### Scenario: CLI session gap surfaces as a failed outcome, never an uncaught exception
- **GIVEN** `hrmq:offer:request-signature` invoked from a genuine `occ` CLI process (no Nextcloud user session)
- **WHEN** the underlying `createRequest()` throws `RuntimeException('No authenticated user')`
- **THEN** the command's outcome is `failed` with that message surfaced, the process exits 1, and no exception propagates out of the service

#### Scenario: Successful request persists id and status
- **GIVEN** `createRequest()` returns a created request with `id: "req-123"` and `status: "PENDING"`
- **WHEN** the outcome is recorded
- **THEN** `Application.offerSigningRequestId === "req-123"` and `Application.offerSigningStatus === "PENDING"`

### Requirement: A missing docudesk installation SHALL degrade gracefully everywhere in the pipeline (REQ-OFFR-004)

Both `requestSignature()` and `syncSignatureStatus()` SHALL treat an uninstalled docudesk, or a container that fails to resolve `DocumentService`, `TemplateService`, or `SigningService`, as an ordinary, non-exceptional outcome (`skipped-no-docudesk` for `requestSignature()`; `syncSignatureStatus()` simply has nothing to sync when no request was ever created). Neither method SHALL throw when docudesk is absent, and the leaf SHALL carry zero composer/info.xml dependency on docudesk.

#### Scenario: Repeated skip is stable
- **GIVEN** docudesk remains uninstalled
- **WHEN** `requestSignature()` is called twice in a row for the same Application
- **THEN** both calls return `skipped-no-docudesk` and `Application.offerSigningRequestId` stays null both times

### Requirement: `requestSignature()` SHALL be idempotent per Application — supersede stale, no-op on completed, retry on terminal-unsuccessful (REQ-OFFR-005)

`OfferEsignService` SHALL treat the Application's current `offerSigningStatus` as the single-slot idempotency state (one Application has at most one active offer-esign attempt): `PENDING` or `IN_PROGRESS` SHALL trigger a best-effort `SigningService::cancelRequest()` on the existing `offerSigningRequestId` (fail-soft — a cancel failure is logged and does NOT block the fresh attempt) before a new letter/request is created; `COMPLETED` SHALL short-circuit to an `already-signed` outcome with no new letter or request created; every other value (`DECLINED`, `CANCELLED`, `EXPIRED`, `failed`, `skipped-no-docudesk`, or null) SHALL proceed straight to a fresh attempt.

#### Scenario: Stale pending request is superseded, not duplicated
- **GIVEN** an Application with `offerSigningStatus: 'PENDING'` and `offerSigningRequestId: 'req-old'`
- **WHEN** `requestSignature()` is called again for it
- **THEN** `SigningService::cancelRequest('req-old')` is attempted, a new letter is generated, and `Application.offerSigningRequestId` ends up pointing at the newly created request — never `req-old` and never two live requests

#### Scenario: Completed request is a no-op
- **GIVEN** an Application with `offerSigningStatus: 'COMPLETED'`
- **WHEN** `requestSignature()` is called again for it
- **THEN** the outcome is `already-signed`, no new letter is generated, and `offerSigningRequestId`/`offerLetterFileId` are unchanged

#### Scenario: Declined request is retryable
- **GIVEN** an Application with `offerSigningStatus: 'DECLINED'`
- **WHEN** `requestSignature()` is called again for it
- **THEN** a fresh letter and signing request are created with no cancel attempt against the declined request (it is already terminal)

### Requirement: Requesting or syncing a signature SHALL NEVER itself advance the Application past `aanbod` (REQ-OFFR-006)

Neither `requestSignature()` nor `syncSignatureStatus(?string $applicationId=null): array` SHALL write `Application.status` or invoke the `aannemen` lifecycle transition, regardless of the observed `offerSigningStatus` — including `COMPLETED`. `syncSignatureStatus()` SHALL be a read-only poll: for each target Application it SHALL call `SigningService::getRequest($requestId)` (no authenticated session required — read-only, unlike the write methods) and write ONLY the returned `status` onto `offerSigningStatus`; a `getRequest()` failure (e.g. not-found) SHALL be logged and leave the Application's fields unchanged rather than throwing. Hiring stays an explicit, separate HR action.

#### Scenario: Completed signature does not auto-hire
- **GIVEN** an Application in status `aanbod` with `offerSigningRequestId` set
- **WHEN** `syncSignatureStatus()` observes the docudesk request has reached `COMPLETED`
- **THEN** `Application.offerSigningStatus` becomes `COMPLETED` but `Application.status` remains `aanbod` — no `aannemen` transition is executed

#### Scenario: Sync is read-only and CLI-safe
- **GIVEN** `hrmq:offer:sync-signature` invoked from a genuine `occ` CLI process with no Nextcloud user session
- **WHEN** it polls a live signing request via `getRequest()`
- **THEN** the call succeeds (no `RuntimeException('No authenticated user')`) and the Application's `offerSigningStatus` is updated

### Requirement: Triggers SHALL be an admin/HR-only occ command pair plus a guarded, resolve-first manifest action (REQ-OFFR-007)

`occ hrmq:offer:request-signature --application <id>` SHALL invoke `requestSignature()` for exactly one Application (`--application` required — no backlog semantics, an HR judgement call, not a scan) and exit 1 when the outcome is `failed`/`usage-error`, 0 otherwise; its docblock/help text SHALL state the CLI-session limitation (REQ-OFFR-003) plainly. `occ hrmq:offer:sync-signature [--application <id>]` SHALL default to every Application whose `offerSigningRequestId` is set and `offerSigningStatus` is `PENDING`/`IN_PROGRESS`. `OfferController::requestSignature(string $applicationId): JSONResponse`, behind `#[NoAdminRequired]`, SHALL reject a caller for whom `isAdminOrHr()` is false with HTTP 403 BEFORE any resolve or docudesk call, SHALL resolve the posted `applicationId` through `ObjectService::find()` under the caller's ambient RBAC and return HTTP 404 when it does not exist or is unauthorized (both collapsing to the same response, the no-admin-idor guard) BEFORE any docudesk call, and SHALL return HTTP 400 when the resolved Application's status is not `aanbod`. `ApplicationDetail` SHALL gain a page-level `api-call` action `request-offer-signature` (`url: /api/offer/request-signature`, `method: POST`, `params: {applicationId: "@objectId"}`, `confirm: true`) mirroring the `PayslipDetail` `generate-loonstrook` shape, and its "Application" data widget's exclude list SHALL gain `offerLetterFileId`/`offerSigningRequestId` (internal correlation ids); `offerSigningStatus` SHALL stay visible as the human-readable state. `npm run check:manifest` MUST keep passing.

#### Scenario: Non-admin/non-HR caller rejected before any resolve
- **GIVEN** an authenticated caller who is neither an admin nor HR
- **WHEN** `POST /api/offer/request-signature` is called with any `applicationId`
- **THEN** the response is HTTP 403 and no `ObjectService::find()` call or docudesk call is made

#### Scenario: Unauthorized or unknown applicationId collapses to 404
- **GIVEN** an admin/HR caller and an `applicationId` that either does not exist or the caller's RBAC denies
- **WHEN** `POST /api/offer/request-signature` is called
- **THEN** the response is HTTP 404 in both cases (no existence leak) and no docudesk call is made

#### Scenario: Wrong-stage Application rejected by the controller
- **GIVEN** an admin/HR caller and an `applicationId` that resolves to an Application in status `screening`
- **WHEN** `POST /api/offer/request-signature` is called
- **THEN** the response is HTTP 400 and no docudesk call is made

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0 and the `request-offer-signature` action is present on `ApplicationDetail`

### Requirement: Seed data SHALL provide an `aanbod`-stage Application to exercise the new surface (REQ-OFFR-008)

`lib/Settings/register.d/hr-ats.json` SHALL gain one Application seed, `application-voorbeeld-aanbod` (candidate "Sanne Voorbeeld", referencing the existing seeded `vacancy-vue-developer`, `status: aanbod`, `offerLetterFileId: null`, `offerSigningRequestId: null`, `offerSigningStatus: null`) — the concrete, not-yet-attempted example the new command and manifest action exercise. The three existing seeded Applications (`application-voorbeeld-nieuw`/`-afgewezen`/`-aangenomen`) SHALL remain untouched.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** `application-voorbeeld-aanbod` exists exactly once, and the three pre-existing seeded Applications are unchanged

#### Scenario: Seed is ready for the new action
- **GIVEN** a fresh import of the seed data
- **WHEN** `occ hrmq:offer:request-signature --application application-voorbeeld-aanbod` is run with docudesk installed and a resolvable `aanbiedingsbrief` template
- **THEN** the outcome is neither `usage-error` (the seed is correctly in status `aanbod`) nor blocked by stale idempotency state (all three new fields start null)

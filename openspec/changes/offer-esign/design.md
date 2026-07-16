# Design — offer-esign

## Context

**What already exists (verified against development HEAD, 2026-07-16):**

- **The docudesk document leaf is live** (`hrmq-docudesk-documents`, archived 2026-07-13; extended by `payslip-pdf-docudesk`, archived 2026-07-14). `HrDocumentService` (`lib/Service/HrDocumentService.php`, 1279 lines) is the reference pattern: `IAppManager::isInstalled('docudesk')` probe + guarded container resolve of `OCA\DocuDesk\Service\DocumentService`/`TemplateService` by string FQCN, config-first/discovery-second/fail-closed template selection in `namespace: hrmq`, `generateDocument(templateId, dataRefs, options)` same-instance, PDF stored via OpenRegister `FileService::addFile()`, `skipped-no-docudesk` degradation, at-most-one-active idempotency pre-check with stale-`pending`-supersede. `GeneratedDocument.documentType` already enumerates `aanbiedingsbrief` (offer letter) as one of the four "letter" types — but every letter type is generated for an `EmploymentContract`/`Employee` (`buildDataRefs()` unconditionally opens with an `Employee` ref; `generateBacklog()`'s non-backlog branch requires `--employee`), and `GeneratedDocument.employeeId` is a REQUIRED schema field. A candidate at `aanbod` has no `Employee` record (`recruiting-applications` design: onboarding hand-off is a MANUAL follow-up). `HrDocumentService`/`GeneratedDocument` therefore cannot represent a candidate's offer letter without fabricating an `employeeId` — this leaf needs its own service and its own tracking fields, not a `GeneratedDocument` row.
- **`Application` is the sole candidate record** (`lib/Settings/register.d/hr-ats.json`, v0.2.0, `recruiting-ats-basic`): candidate PII lives directly on it (no `Candidate` entity, AVG data-minimisation). Lifecycle `nieuw → screening → gesprek → aanbod → aangenomen/afgewezen`; the `aanbieden` transition (`gesprek → aanbod`) docblock states: "An offer is extended to the candidate. No offer-letter generation/e-signature in the MVP." — the exact gap this change closes, the same shape `interview-scheduling` closed on `uitnodigen`.
- **`SigningService`** (`/home/rubenlinde/wave2-worktrees/sweep-clean/docudesk/lib/Service/SigningService.php`) — verified real contract (not assumed):
  - `createRequest(array $data): array` reads `documentFileId`, `documentName` (validated non-empty), `signatureLevel` (validated ∈ `SES`/`AdES`/`QES`), `signingMode` (validated ∈ `sequential`/`parallel`), `deadline` (falls back to `signing_request_expiry_days` config + 30 days), and **`signers`** — NOT `signerIds`. `signers` is an array of `{userId, displayName, email, order}`; `createRequest()` itself creates one `SignerRecord` object per entry and derives `signerIds` from the created records. It ALSO reads seven optional provenance fields directly off `$data` (`sourceApp`, `subjectRegister`, `subjectSchema`, `subjectId`, `subjectLabel`, `externalReference`, `correlationId`) — the same fields docudesk's own cross-app `DocumentSigningRequestedEvent`/`DocumentSigningRequestedListener` contract threads into the identical `createRequest()` call, confirming both the event path and a direct container-resolved call persist through one shared contract.
  - `createRequest()`, `cancelRequest()`, `sign()`, `decline()` ALL open with `$user = $this->userSession->getUser(); if ($user === null) { throw new RuntimeException('No authenticated user'); }`. A genuine `occ` CLI process has no Nextcloud user session — `IUserSession::getUser()` is null — so every one of these calls throws when invoked from CLI. `getRequest(string $requestId, string $callerUserId='', bool $isAdmin=false): ?array` has NO such guard (read-only, no session needed) and, called with its defaults, returns the full record unscoped.
  - `sign()` authorizes by `($signer['userId'] ?? '') === $user->getUID()` — a signer with an empty/absent `userId` can never complete `sign()` (no authenticated user's UID is ever the empty string). There is no external/non-NC-user signer path anywhere in this service.
  - `FileService::addFile()` (OpenRegister, `lib/Service/FileService.php:1438`) returns `\OCP\Files\File`, which exposes `getId(): int` — the real Nextcloud file id `SigningService::produceAndStoreSignedArtifact()` requires (`(int) ($request['documentFileId'] ?? 0)`, resolved via `IRootFolder::getUserFolder($uid)->getById($fileId)`). `HrDocumentService` only ever reads `getPath()` today; this leaf is the first hrmq caller that needs `getId()`.
- **Controller/RBAC precedent**: `DocumentController::generate()` resolves the posted subject id through `ObjectService::find()` under the caller's ambient RBAC BEFORE any docudesk call (no-admin-idor guard, 404 collapses not-found/unauthorized). `PayrollController::isAdminOrHr()` is the established "admin/HR only" check (`$this->groupManager->isAdmin($uid)` — today functionally admin-group-only; the same limitation every `isAdminOrHr()` caller in the fleet carries, not invented here).
- **Manifest**: `ApplicationDetail` (route `/applications/:id`) has an "Application" data widget excluding `vacancyId`/`talentPoolOptIn`/`rejectedDate`/`retentionExpiryDate`, a "Privacy & retention" widget, `lifecycleActions` for the five ATS transitions, no page-level `actions` yet. `PayslipDetail`'s `generate-loonstrook` action is the verbatim `api-call` shape to mirror (icon `FileDocumentPlusOutline`, already registered — reused here to avoid an `icons.js` touch).

## Goals / Non-Goals

**Goals:** an `aanbod`-stage Application can have a real offer-letter PDF generated and a real docudesk signing request raised, both through the existing "hrmq assembles data, docudesk renders/signs" division of authority; the request lifecycle (id + status) is visible on the Application; duck-typed graceful degradation; idempotent re-invocation; RBAC-gated triggers; and an honest, explicit boundary around what this change does NOT claim (candidate self-service completion, webhook ingestion, auto-hire).

**Non-Goals:** candidate-facing signing UI/portal (D5), webhook/`SigningConcludedEvent` listener, auto-hire on completion, offer-letter template layout authoring, a new top-level menu (the action lives on the existing `ApplicationDetail`), any change to the `x-openregister-lifecycle` state machine on `Application` (offer-esign is orthogonal to `status`).

## Decisions

### D1 — `OfferEsignService` is a new, sibling service — not an `HrDocumentService` extension

`HrDocumentService`'s entire method surface is `employeeId`/`contractId`-shaped (`generate()`, `buildDataRefs()` requiring an `Employee` ref, `GeneratedDocument.employeeId` required). Widening it to accept an `Application` subject would mean either fabricating a placeholder `employeeId` (dishonest — the candidate is not an employee) or making `employeeId` nullable fleet-wide on a schema four other document types depend on being required. `OfferEsignService` re-implements the SAME algorithmic shape (duck-typed probe → template selection → render → store → record) against its own subject model, the same relationship `payslip-pdf-docudesk`'s loonstrook/jaaropgaaf paths have to the four letter types — but as a new service, because the tracking record here (`Application` itself, via D2) is a different object type than `GeneratedDocument`.

### D2 — Tracking lives directly on `Application`, not a new `GeneratedDocument`-shaped schema

`GeneratedDocument` requires `employeeId` — unavailable pre-hire. Rather than relaxing that invariant for every other consumer of the schema, or inventing a parallel `OfferDocument` tracking schema for what is a single-slot-per-Application concern, three additive fields land directly on `Application` (append-only, v0.2.0 → v0.3.0, non-breaking): `offerLetterFileId` (integer, nullable — the NC file id from `FileService::addFile()->getId()`), `offerSigningRequestId` (string, nullable — the docudesk signing-request id; a plain string, NOT a `$ref`, because docudesk owns a different register/app — the identical cross-app precedent `GeneratedDocument.templateRef` already sets, ADR-062 rule 7 applies only to in-register targets), `offerSigningStatus` (string, nullable, enum). One Application has at most one active offer-esign attempt at a time — the fields ARE the idempotency state, no separate keyed index needed (contrast `GeneratedDocument`'s at-most-one-active-per-key scan, which exists because ONE `EmploymentContract`/`Payslip`/`Jaaropgaaf` can accumulate several `GeneratedDocument` rows over time; an `Application` has exactly one offer-esign slot for its entire lifetime).

`offerSigningStatus` enum: docudesk's own six (`PENDING`, `IN_PROGRESS`, `COMPLETED`, `DECLINED`, `CANCELLED`, `EXPIRED` — `SigningService::STATUS_TRANSITIONS` keys, kept verbatim rather than inventing a lowercase translation layer that could silently drift from the source of truth) plus two leaf-local outcomes for the paths that never reach docudesk's own state machine: `skipped-no-docudesk` and `failed`. `null` means "no offer-esign attempted yet".

### D3 — dataRefs: `[Application, Vacancy]`, no `Employee` ref

`OfferEsignService` renders with `dataRefs = [{register, schema: "Application", id: applicationId}, {register, schema: "Vacancy", id: vacancyId}]` (vacancyId read off the resolved Application) — templates read `Application.candidateName`, `Application.email`, `Vacancy.title`, `Vacancy.department`, `employer.*`. No `Employee` ref (none exists yet); no candidate PII flattened into `adHocData` (the established `buildOptions()` pattern: `adHocData` carries only the employer block + document metadata). `options.adHocData.document.type = 'aanbiedingsbrief'` — same key HrDocumentService's letter types already write, so a single template category selection (D4) covers both call sites.

### D4 — Template selection re-implements the leaf's algorithm; reuses the `aanbiedingsbrief` category

`HrDocumentService::selectTemplate()` is `private` and keyed to its own subject model — not reusable by composition. `OfferEsignService` implements the identical config-first (`SettingsService::getDocumentsTemplateId('aanbiedingsbrief')`, already generic over any documentType string — no new getter) / discovery-second (`TemplateService::getTemplatesByNamespace('hrmq')` filtered `category === 'aanbiedingsbrief'`) / fail-closed (zero or multiple matches → `failed`, never guess) algorithm. Reusing the EXISTING `aanbiedingsbrief` category — rather than inventing e.g. `offer-esign-letter` — is deliberate: an offer letter is an offer letter regardless of whether the subject is a not-yet-hired candidate or an existing employee's contract renegotiation; template authors maintain one category, not two.

### D5 — The verified auth gap: `createRequest()`/`cancelRequest()` require an authenticated NC user; there is no external-signer path

This is the load-bearing finding of this change (see proposal.md "Why" and Risks below). Two consequences, both specified rather than papered over:

1. **The occ write trigger (`hrmq:offer:request-signature`) will reliably fail with `failed: "No authenticated user"` when run from a genuine `occ` CLI process**, because `SigningService::createRequest()`'s internal `IUserSession::getUser()` check has no CLI-session substitute anywhere in the verified contract. `OfferEsignService::requestSignature()` wraps the whole write sub-pipeline (supersede-cancel + createRequest) in one try/catch → `failed` outcome carrying the real exception message, the same fail-soft boundary `HrDocumentService::generateInternal()` uses around `generateDocument()`. The command is still specified (scriptability parity with the sibling leaf, and for a future service-account/impersonation context), but its docblock and `--help` text state the limitation plainly.
2. **The primary, actually-working write path is the guarded `ApplicationDetail` manifest action**, executed in an authenticated browser session exactly like `PayslipDetail`'s `generate-loonstrook` action — `IUserSession::getUser()` is populated there.
3. **`syncSignatureStatus()` (the read path) has no such constraint** — `getRequest()` carries no session guard — so `hrmq:offer:sync-signature` genuinely works from CLI. This asymmetry (writes need a session, reads don't) is the reason the MVP boundary explicitly allows a poll/sync command instead of a webhook: it is the one piece of the lifecycle CLI can honestly own today.
4. **No external (non-NC-user) candidate can complete `sign()`** because it requires `signer.userId === $user->getUID()`. `OfferEsignService` still creates the `signers` entry with `userId: ''` (empty, honest — hrmq has no NC user for the candidate to supply) alongside `displayName`/`email`; the request and its audit trail are real, but nothing in this change claims the candidate can self-serve the actual signature click. This is Non-Goal #1 in proposal.md, not a silent gap.

### D6 — Idempotency: supersede stale PENDING/IN_PROGRESS, no-op on COMPLETED, retry everything else

Mirrors `HrDocumentService::generateInternal()`'s "stale pending → supersede, then fall through" idiom, adapted to the single-slot-on-Application model (D2):

- `offerSigningStatus` is `PENDING` or `IN_PROGRESS` → this is a re-run over a live request: best-effort `SigningService::cancelRequest($oldRequestId)` (fail-soft — a cancel failure is logged and does NOT block the fresh attempt; per D5 this call itself needs a session and will itself throw from CLI, folded into the same try/catch), then proceed to create a fresh request.
- `offerSigningStatus` is `COMPLETED` → no-op, outcome `already-signed` (the `already-generated` idiom).
- `offerSigningStatus` is `DECLINED`/`CANCELLED`/`EXPIRED`/`failed`/`skipped-no-docudesk`/`null` → retryable, straight to a fresh attempt (the `failed`/`skipped-no-docudesk` never-blocks-a-retry idiom, extended with the three additional terminal-but-unsuccessful docudesk states).

### D7 — Stage guard and the no-auto-hire boundary

`requestSignature()` only proceeds when the resolved Application's `status === 'aanbod'` — any other status is a `usage-error` outcome, nothing generated (mirrors `HrDocumentService::generateBacklog()`'s option-misuse guard shape). This is enforced in the SERVICE (not only the manifest action's visibility), because the occ command has no manifest-driven visibility gating.

`syncSignatureStatus()` writes ONLY `offerSigningStatus` (and, implicitly, never touches `offerLetterFileId`/`offerSigningRequestId` once set). Neither method ever calls the `aannemen` lifecycle transition or writes `Application.status` — observing `offerSigningStatus: COMPLETED` is informational; hiring stays an explicit, separate HR action on `ApplicationDetail`. This boundary is a REQ (REQ-OFFR-006), not merely a comment, because "the signature completed" and "we hired them" are legally and operationally distinct decisions that must not be conflated by a background sync.

### D8 — Triggers and RBAC

`hrmq:offer:request-signature --application <id>` (single Application, required option — no backlog semantics; unlike the letter-type backlog, "which candidates are due an offer" is an HR judgement call, not a scan) and `hrmq:offer:sync-signature [--application <id>]` (default: every Application with a non-null `offerSigningRequestId` whose `offerSigningStatus` is `PENDING`/`IN_PROGRESS` — no point re-polling a terminal state). `OfferController::requestSignature(string $applicationId): JSONResponse` — `#[NoAdminRequired]` + `isAdminOrHr()` (403 otherwise) + `authorizeApplication()` (the `authorizeContract()`/`authorizePayslip()` shape: `ObjectService::find()` under caller RBAC, unresolvable/unauthorized → 404, BEFORE any docudesk call) + the stage guard (400 when not `aanbod`). Route `offer#requestSignature` at `POST /api/offer/request-signature`, added before the SPA catch-all in `appinfo/routes.php`. No controller endpoint for `syncSignatureStatus()` in MVP — occ-only, the `hrmq:documents:generate --type jaaropgaaf` precedent for "batch operation, occ-only until a real UI need appears".

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `Application` additive fields | declarative schema (`hr-ats.json`) | ADR-031 default |
| Duck-typed probe, template selection, render+store, `createRequest()` call | imperative `OfferEsignService` | ADR-031 exception already granted to the docudesk leaves: cross-app integration with binary handling and an external service call |
| Idempotent supersede/no-op/retry decision | imperative `OfferEsignService` | depends on the live `offerSigningStatus` value and a docudesk round-trip (`cancelRequest`) — not declaratively expressible |
| Stage guard (`aanbod`-only) | imperative `OfferEsignService` | cross-field guard (checks `Application.status` before acting on a DIFFERENT field group); `x-openregister-lifecycle` governs `status` transitions, not side-effects triggered BY a status value |
| Status sync (`getRequest()` poll) | imperative `OfferEsignService` | cross-app read, same class as the render/store calls |
| Triggers | imperative occ commands + one guarded controller endpoint | no lifecycle hook on `Application.status`; the endpoint exists solely for the manifest api-call action, the `DocumentController`/D6 precedent |
| `ApplicationDetail` manifest action + widget exclude update | declarative manifest | ADR-031 default (`api-call` is a declarative action type) |

### Mixed-spec rationale (kind: code)

`kind: code`: the PHP surface dominates (new service with a duck-typed probe/template-selection/render/store/idempotency pipeline, two occ commands, one guarded controller endpoint, unit tests) while the config surface (three additive fields, one seed, one manifest action) rides along — the identical yellow-flag precedent `payslip-pdf-docudesk` and `interview-scheduling` already set for this fragment; splitting the three-field schema bump into a separate `kind: config` change would only create an artificial ordering dependency on a single fragment file.

## Schema delta (`lib/Settings/register.d/hr-ats.json`)

**`Application` 0.2.0 → 0.3.0** — three new nullable, additive properties (all carry title + description, gate-28):

| Field | Type | Notes |
|---|---|---|
| `offerLetterFileId` | integer, nullable | The Nextcloud file id (`File::getId()`) of the generated offer-letter PDF. Null until `requestSignature()` successfully stores one. |
| `offerSigningRequestId` | string, nullable | The docudesk signing-request id. Plain string, NOT `$ref` (cross-app/cross-register target, the `GeneratedDocument.templateRef` precedent). |
| `offerSigningStatus` | string, nullable | Enum: `PENDING`, `IN_PROGRESS`, `COMPLETED`, `DECLINED`, `CANCELLED`, `EXPIRED` (verbatim `SigningService::STATUS_TRANSITIONS` vocabulary), `skipped-no-docudesk`, `failed`. Null = never attempted. |

No change to `Application.required` or to the `x-openregister-lifecycle` block — offer-esign is orthogonal to the pipeline status machine (D7).

## Config delta (`lib/Service/SettingsService.php`)

`getOfferSigningDeadlineDays(): int` — config key `offer_signing_deadline_days`, `IAppConfig` default `'14'` (an offer acceptance window, distinct from docudesk's own generic `signing_request_expiry_days` 30-day default), read via the established `getValueString(...)` + cast pattern. Used to compute the ISO-8601 `deadline` passed into `createRequest()`.

## Seed Data (ADR-001)

`hr-ats.json` `components.objects` gains one Application, referencing the same seeded `vacancy-vue-developer` the three existing seeds use:

1. `application-voorbeeld-aanbod` — candidate `Sanne Voorbeeld`, `sanne.voorbeeld@example.org`, `status: aanbod`, `offerLetterFileId: null`, `offerSigningRequestId: null`, `offerSigningStatus: null` — the concrete, not-yet-attempted example `hrmq:offer:request-signature --application application-voorbeeld-aanbod` and the new `ApplicationDetail` action exercise. The three existing seeded Applications (`application-voorbeeld-nieuw`/`-afgewezen`/`-aangenomen`) stay untouched (union, no regression) and, being outside `aanbod`, are unaffected by the new stage guard.

## Risks / Trade-offs

- **The occ write trigger is honestly limited (D5).** `hrmq:offer:request-signature` cannot complete a real `createRequest()` call from a bare CLI session today — it is specified for scriptability parity and documented degradation, not as hrmq's primary trigger. The manifest action is.
- **No candidate self-service signing exists yet (D5, Non-Goal #1).** This change delivers "a real, trackable signing request exists" — not "the candidate can click a link and sign." Overselling this boundary would be the same class of dishonesty the codebase already rejects for `verrekendeArbeidskorting`/`statementProvided`.
- **Re-running after a COMPLETED status is a silent no-op**, not an error — if HR genuinely needs to re-issue a signed offer (e.g. a material term changed), that is an operator decision requiring a NEW Application-level action this change does not add (mirrors the `payslip-pdf-docudesk` "corrected-jaaropgaaf reissue" deferral).
- **Template-author contract widens**: `Application.*`/`Vacancy.*` join `Employee.*`/`EmploymentContract.*`/`Payslip.*`/`Jaaropgaaf.*`/`employer.*` as docudesk template-variable conventions; a renamed field renders empty (Twig `strict_variables: false`), same mitigation as every prior leaf — the config-key docs state the contract.
- **`offerSigningStatus`'s 8-value enum mixes vocabularies** (6 docudesk states + 2 hrmq-leaf states) rather than a clean layered model — accepted because a translation/mapping layer between two 6-state and 2-state vocabularies would itself be a place for silent drift; verbatim reuse of docudesk's own constant values is the more honest choice.

## Open Questions

- None blocking. Follow-ups tracked in Non-Goals: candidate-facing signing portal (portaliq/ADR-046), `SigningConcludedEvent` webhook listener, auto-hire policy on completion, corrected-offer reissue flow.

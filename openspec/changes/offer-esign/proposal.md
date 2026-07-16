---
kind: code
depends_on: []
---

# Offer e-signature (offer-esign): close the `aanbieden` MVP gap via docudesk

## Why

`recruiting-ats-basic`'s `Application` lifecycle carries the transition `aanbieden` (gesprek → aanbod) with its own docblock stating plainly: "An offer is extended to the candidate. No offer-letter generation/e-signature in the MVP." Today reaching `aanbod` is a bare status flip — no letter is produced, nothing is sent to the candidate, and there is no record of whether an offer was ever actually delivered or accepted. `interview-scheduling` already closed the sibling gap on `uitnodigen`; this change closes the one on `aanbieden`, the same way `payslip-pdf-docudesk` closed the loonstrook gap: **hrmq assembles data, docudesk renders and signs — no PDF or e-signature machinery of hrmq's own.**

**What already exists (verified against development HEAD, 2026-07-16).** `HrDocumentService` (`lib/Service/HrDocumentService.php`) is the live, working precedent for the generation half: duck-typed `IAppManager::isInstalled('docudesk')` probe + guarded container resolve of `OCA\DocuDesk\Service\DocumentService`/`TemplateService` by string FQCN (zero compile-time import, zero composer/info.xml dependency on docudesk), config-first/discovery-second/fail-closed template selection in `namespace: hrmq`, `DocumentService::generateDocument(templateId, dataRefs, options)` same-instance, the returned PDF stored via OpenRegister's `FileService::addFile()`, and `skipped-no-docudesk` degradation. `GeneratedDocument`'s `documentType` enum already carries `aanbiedingsbrief` (offer letter) as one of the "four letter types" — but every one of those types is generated FOR an `EmploymentContract`/`Employee`, via `HrDocumentService::generate($employeeId, $contractId, ...)`, and `buildDataRefs()` unconditionally opens with an `Employee` ref. A job candidate at the `aanbod` stage has **no** `Employee` record — `recruiting-applications`' own spec states hiring's onboarding hand-off is a MANUAL follow-up ("HR creates the Employee/Onboarding case by hand ... no cross-object write hook fires"). `HrDocumentService` therefore cannot generate a candidate's offer letter as-is; this change adds a sibling service whose subject is the `Application` itself, not an `Employee`.

For signing, `OCA\DocuDesk\Service\SigningService::createRequest(array $data): array` is confirmed live (`/home/rubenlinde/wave2-worktrees/sweep-clean/docudesk/lib/Service/SigningService.php`) — but its real field contract differs from a plausible-sounding `signerIds` shape: `$data` carries `documentFileId`, `documentName`, `signatureLevel` (`SES`/`AdES`/`QES`, validated), `signingMode` (`sequential`/`parallel`, validated), `deadline`, and **`signers`** — an array of `{userId, displayName, email, order}` — from which `createRequest()` itself creates the `SignerRecord` objects and derives `signerIds`. `createRequest()` also accepts seven optional provenance fields (`sourceApp`, `subjectRegister`, `subjectSchema`, `subjectId`, `subjectLabel`, `externalReference`, `correlationId`) that correlate the request back to hrmq for the terminal `SigningConcludedEvent` — the same fields docudesk's own `DocumentSigningRequestedEvent` cross-app contract threads, confirming a direct container-resolved call and the event-based path share one persistence contract.

**A verified, honestly-documented constraint carries through the whole design (see design.md D5):** `SigningService::createRequest()`, `cancelRequest()`, `sign()` and `decline()` all throw `RuntimeException('No authenticated user')` when `IUserSession::getUser()` is null — true for every genuine `occ` CLI invocation. `getRequest()` (read-only) has no such guard. This change specs both an occ write trigger and a manifest-action write trigger, and states plainly that only the manifest action (an authenticated browser session) reliably completes the write path today; the occ trigger exists for scriptability parity with the sibling leaf and ships with this limitation documented, not hidden.

## What Changes

- **`Application` v0.2.0 → v0.3.0** (`lib/Settings/register.d/hr-ats.json`, append-only, non-breaking): three new nullable fields — `offerLetterFileId` (integer, the Nextcloud file id of the generated offer-letter PDF), `offerSigningRequestId` (string, the docudesk signing-request id — a plain string, NOT a `$ref`, since docudesk owns a different register/app, the same cross-app precedent as `GeneratedDocument.templateRef`), and `offerSigningStatus` (string enum mirroring docudesk's own `SigningService::STATUS_TRANSITIONS` vocabulary plus the leaf's own duck-typed/failure statuses).
- **New `OfferEsignService`** (`lib/Service/OfferEsignService.php`): `requestSignature(string $applicationId, ?string $userId=null): array` — resolves the Application (must be in status `aanbod`), duck-typed probes `DocumentService`+`TemplateService`+`SigningService`, selects the `aanbiedingsbrief` template (config-first/discovery-second/fail-closed, the exact `HrDocumentService::selectTemplate()` algorithm re-implemented against the leaf's own subject model), renders with `dataRefs = [{Application}, {Vacancy}]`, stores the PDF via `FileService::addFile()` (real NC file id via `File::getId()`), then calls `SigningService::createRequest()` with the verified real field shape and provenance fields threaded through, persisting `offerLetterFileId`/`offerSigningRequestId`/`offerSigningStatus` on the Application. `syncSignatureStatus(?string $applicationId=null): array` — read-only poll via `SigningService::getRequest()`, reflecting the current docudesk status onto `offerSigningStatus`; never touches `Application.status`.
- **New occ commands**: `hrmq:offer:request-signature --application <id>` and `hrmq:offer:sync-signature [--application <id>]` (default: every Application with an active `offerSigningRequestId`).
- **New `OfferController::requestSignature()`** (`lib/Controller/OfferController.php`) — `POST /api/offer/request-signature`, `#[NoAdminRequired]` + the established `isAdminOrHr()` precedent (`PayrollController`) + the no-admin-idor resolve-first-then-404 guard (`DocumentController::authorizeContract()` pattern) before any docudesk call. Route added to `appinfo/routes.php` before the SPA catch-all.
- **Manifest**: `ApplicationDetail` gains a guarded `request-offer-signature` `api-call` page action (the `PayslipDetail` "Genereer PDF" shape), visible only when `status: aanbod`; the "Application" data widget's exclude list gains `offerLetterFileId`/`offerSigningRequestId` (internal correlation ids — `offerSigningStatus` stays visible as the human-readable state, the `GeneratedDocumentDetail` exclude precedent).
- **Seed**: one new Application `application-voorbeeld-aanbod` (status `aanbod`, no signing fields set yet) — the concrete example the new action/service has to exercise; the three existing seeded Applications stay untouched (union, no regression).
- **Unit tests**: duck-typed skip, idempotent supersede-vs-no-op-vs-retry, the real `signers`/provenance payload shape passed to `createRequest()`, the stage guard (`aanbod`-only), and the no-auto-hire boundary.

### Non-goals

- **Candidate self-service signing UI/portal.** `SigningService::sign()`/`decline()` authorize strictly by matching the authenticated Nextcloud user's UID to the signer record's `userId` — there is no external (non-NC-user) signer flow in the verified docudesk contract today. A job candidate is not, and is not made, an NC user by this change (same AVG data-minimisation stance as "no Candidate entity"). The signing REQUEST is real and trackable; who actually clicks "sign" and how is deferred to a `portaliq`-based candidate portal (ADR-046, the same deferral `recruiting-applications` already recorded for the public career page) or a future docudesk external-signer capability.
- **Webhook/callback ingestion of the signed result.** `SigningConcludedEvent` exists on docudesk's side but no listener is added here; status only moves via the poll command / re-running `requestSignature`. A follow-up change can add an `OCA\DocuDesk\Event\SigningConcludedEvent` listener once the cross-app delegated-signing contract is adopted fleet-wide by hrmq.
- **Auto-hire.** `syncSignatureStatus()` observing `offerSigningStatus: COMPLETED` never calls the `aannemen` lifecycle transition or writes `Application.status`. Hiring stays an explicit HR decision on `ApplicationDetail`, exactly as today.
- **Offer-letter template authoring.** Same division of authority as every other docudesk leaf: layout is a `namespace: hrmq` docudesk template, hrmq pins only the variable contract (`Application.*`, `Vacancy.*`, `employer.*`).

## Capabilities

### New Capabilities

- `offer-esign`: the offer-letter generation + e-signature-request surface for recruiting `Application`s at the `aanbod` stage — `OfferEsignService`, the two occ commands, the guarded `OfferController` endpoint, and the `ApplicationDetail` manifest action.

### Modified Capabilities

- `recruiting-applications`: `Application` gains three additive fields (`offerLetterFileId`, `offerSigningRequestId`, `offerSigningStatus`) and one new seed; the `aanbieden` transition's docblock claim ("No offer-letter generation/e-signature in the MVP") is superseded by this change for the generation/request half — signing COMPLETION and auto-hire remain explicitly out of scope (see Non-goals).

## Impact

- `lib/Settings/register.d/hr-ats.json` — `Application` v0.3.0 (3 additive nullable fields), 1 new seed.
- `lib/Service/OfferEsignService.php` — NEW: duck-typed probe, template selection, render+store, `createRequest()` call, idempotent supersede, status sync.
- `lib/Service/SettingsService.php` — `getOfferSigningDeadlineDays()` getter (config key `offer_signing_deadline_days`, placeholder default).
- `lib/Command/OfferRequestSignatureCommand.php`, `lib/Command/OfferSyncSignatureCommand.php` — NEW occ triggers; registered in `appinfo/info.xml` `<commands>`.
- `lib/Controller/OfferController.php` — NEW; `appinfo/routes.php` gains `offer#requestSignature` before the SPA catch-all.
- `src/manifest.json` — `ApplicationDetail` gains the guarded action + data-widget exclude update.
- `tests/Unit/Service/OfferEsignServiceTest.php` — NEW.
- Cross-app dependency: unchanged posture — string-FQCN container resolution only, zero composer/info.xml dependency on docudesk; three FQCNs now probed instead of two (`DocumentService`, `TemplateService`, `SigningService`).

# Design: sign an employee's HR documents electronically

## Context

Read at `development` af702f78.

- `lib/Service/OfferEsignService.php` (622 lines) raises a filinq signing request for an
  offer letter: it resolves filinq through `FleetAppId` (`DOCUMENT_APP = 'filinq'`, line
  117; `Service\SigningService`, line 126), calls `createRequest()` with `signers` as
  `{userId, displayName, email, order}` plus provenance fields (`sourceApp`,
  `subjectRegister`, `subjectSchema`, `subjectId`, `correlationId`, lines 390-418), and
  records `skipped-no-docudesk` or `failed` instead of throwing. Its docblock (lines 27-58)
  records two verified filinq facts: `createRequest()` throws without a signed-in session,
  and `sign()` authorises only a signer whose `userId` is the signed-in Nextcloud user.
  `syncSignatureStatus()` (line 474) reads status through `getRequest()`, which needs no
  session.
- `lib/Service/OfferSigningRecoveryService.php` holds the signer resolution and the orphaned
  request recovery keyed on `correlationId` and `documentFileId`.
- `HrGeneratedDocument` (`lib/Settings/register.d/hr-documents.json`, 0.2.0):
  `documentType`, `employeeId`, `contractId`, `status` (`pending`, `generated`, `failed`,
  `skipped-no-docudesk`), `filePath`, `generatedAt`. No signing fields.
- `GeneratedDocumentDetail` (`src/manifest.d/hr-documents.json:33`) shows status, data and
  the related employee and contract; it has no action, and it keeps the Files tab so the
  stored PDF is visible.
- `lib/Controller/DocumentController.php:97` exposes `generate()` as
  `POST /api/documents/generate` (`appinfo/routes.php:25`), `#[NoAdminRequired]` with its own
  guard.
- `EmploymentContract.writtenContract` drives the Awf rate and is read by
  `NlDocumentChecks.php:100` (`nl-contract-schriftelijk`) and `NlRetroChecks.php:211`.
- `hrAdministration` (`hr-administratie.json`, 1.1.0) has no signatory.

## Goals / Non-Goals

**Goals**

- Contracts, werkgeversverklaringen and getuigschriften about an employee can be signed in
  the app, with the right signers in the right order.
- A completed contract signature makes `writtenContract` true without a hand tick.
- One implementation of the filinq signing call for offers and HR documents.

**Non-Goals**

- A signing screen in humaniq. filinq shows and records the signature.
- Signers without a Nextcloud account.

## Decisions

### D1. Extract one signing gateway, then add the second caller

`FilinqSigningGateway` takes over from `OfferEsignService` and `OfferSigningRecoveryService`:
the filinq probe, `createRequest()` with signers and provenance, the session-guard catch,
orphan recovery and `getRequest()`. `OfferEsignService` keeps its offer rules (the
`aanbod` stage, the candidate lookup) and calls the gateway. `HrDocumentSigningService` is
the second caller. The frozen `sourceApp: 'hrmq'` provenance value stays as it is for both,
because it is the recovery key for requests already raised.

Alternative considered: copy the offer code into a new service. Rejected: the orphan
recovery and the session guard took two defect rounds to get right, and two copies would
drift.

### D2. Signers follow the document type

| documentType | signers, in order |
|---|---|
| `arbeidsovereenkomst` | the administration's `signatoryUserId`, then the employee's `nextcloudUserId` |
| `werkgeversverklaring` | the administration's `signatoryUserId` |
| `getuigschrift` | the administration's `signatoryUserId` |

With no `signatoryUserId` the acting HR user signs for the employer. An employment contract
whose employee has no `nextcloudUserId` fails cleanly with `no-nextcloud-user-for-employee`
and raises no request, the offer precedent. `loonstrook` and `jaaropgaaf` are refused.

### D3. Status on the document, idempotent per document

`HrGeneratedDocument` gains `signingRequestId`, `signingStatus` (the six filinq states plus
`skipped-no-docudesk` and `failed`) and `signingCompletedAt`. A request while one is
`PENDING` or `IN_PROGRESS` is a no-op that answers the existing request; after `DECLINED`,
`CANCELLED`, `EXPIRED` or `failed` a new request may be raised. `COMPLETED` is final.

### D4. The trigger is a page action, the sync can run from occ

`POST /api/documents/{id}/request-signature` is `#[NoAdminRequired]`, resolves the document
through `RbacObjectReader` first (404 when unreadable), then requires admin or HR. It runs in
the user's session, which filinq requires. `occ humaniq:documents:sync-signatures` reads
statuses through `getRequest()`, which needs no session, and writes them back.

### D5. Completion closes the written-contract flag

When a sync reads `COMPLETED` for an `arbeidsovereenkomst` with a `contractId`, the service
sets that contract's `writtenContract` to true, carrying every other field unchanged, and
stamps `signingCompletedAt`. It never sets `writtenContract` to false.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| raising and reading signing requests | imperative, duck-typed to filinq | external integration, ADR-031 "document" class |
| written-contract flag on completion | imperative, inside the sync | a cross-app status read turned into one field write |
| signing fields and action on the page | declarative manifest | existing widgets and `api-call` action |

## Seed data

- The seeded administration gains a `signatoryUserId` of `admin`.
- One seeded `arbeidsovereenkomst` document with `signingStatus` `COMPLETED` and its
  contract's `writtenContract` true; one `werkgeversverklaring` with `PENDING`.

## Risks / Trade-offs

- [Refactoring the offer path] → the offer tests stay green unchanged; the extraction is
  verified by running them before and after.
- [An employee without an account] → the failure message names the reason, and HR can
  still sign on paper as today.

## Open Questions

- Should humaniq subscribe to filinq's completion event instead of polling, once filinq's
  delegated signing contract is adopted fleet-wide (the `offer-esign` follow-up)?

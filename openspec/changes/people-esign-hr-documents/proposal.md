---
kind: code
---

# Sign an employee's HR documents electronically

## Why

humaniq can already send one kind of document for an electronic signature: the offer letter
on an application at the `aanbod` stage. Everything that follows the hire cannot. An HR
adviser who generates an employment contract, a werkgeversverklaring for a mortgage or a
getuigschrift on `EmploymentContractDetail` gets a PDF, prints it, has it signed with a pen,
scans it back and attaches it by hand. The contract's own `writtenContract` flag, which
decides the low unemployment-fund rate, is then ticked by hand as well.

This change adds `Request signature` to a generated HR document about an existing employee,
through the same filinq signing service the offer letter uses, and closes the loop when the
signature completes.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ppl-esign-documents` | Have an HR document signed electronically. | `partial`, built: only the offer letter on an application can be sent for signing; no signing action on `EmployeeDetail`, `EmploymentContractDetail` or `GeneratedDocumentDetail` |

### Competitors rated yes

- `ppl-esign-documents`, AFAS Profit: "the AFAS Signing Service signs documents digitally
  through the workflow" (https://help.afas.nl/help/NL/SE/Crm_SignSr.htm).
- `ppl-esign-documents`, Visma Raet Youforce: "the manager signs the employment contract
  digitally via SMS verification"
  (https://www.ssc-ons.nl/content/uploads/2021/05/Handleiding-Digitaal-ondertekenen-AOK-door-leidinggevende-1.pdf).
- `ppl-esign-documents`, HR2day: "eIDAS compliant electronic signing on desktop and mobile
  with signing order and audit trail" (https://www.hr2day.com/features/digitaal-ondertekenen/).
- `ppl-esign-documents`, Loket.nl: "sign documents securely and legally valid, generate,
  send and sign in one flow" (https://loket.nl/functionaliteiten/digitaal-dossier/).
- `ppl-esign-documents`, Personio: "request electronic signatures on documents in an
  employee profile"
  (https://support.personio.de/hc/en-us/articles/360012724438-Request-electronic-signatures-on-employee-documents).

### Recorded follow-ups this change picks up

- `2026-07-13-hrmq-docudesk-documents` proposal, Non-goals: "Signing flow. Chainable in
  principle (docudesk `SigningService::createRequest` exists and the PDF lands as an OR
  object file) but it requires a user session and its own lifecycle; follow-up leaf."
- `2026-07-15-offer-esign` built that leaf for offer letters only.
- `2026-09-07-document-dossier-avg` excludes "an eIDAS e-signature workflow" and a
  `signature-request` schema from the dossier change, because humaniq should not build
  signing or a document catalogue of its own. This change builds neither: filinq signs, and
  humaniq keeps only the request id and status on the document it already logs, exactly as
  `offer-esign` does on the application. The distinction is flagged for the reviewer.

## What Changes

- **A signing action on a generated document.** `GeneratedDocumentDetail` gains `Request
  signature` for an `arbeidsovereenkomst`, `werkgeversverklaring` or `getuigschrift` in
  status `generated`. It raises a filinq signing request on the stored PDF.
- **Who signs follows the document.** An employment contract is signed by the employer's
  signatory first and then by the employee. A werkgeversverklaring and a getuigschrift are
  employer statements and are signed by the signatory only. The signatory is a Nextcloud user
  set per administration.
- **The status lives on the document.** `HrGeneratedDocument` gains the signing request id,
  its status and the completion time, the same values `job-application` carries for offers.
- **A completed contract counts as written.** When the signature on an employment contract
  completes, the contract's `writtenContract` is set to true, so the unemployment-fund rate
  check reads the truth. A declined or expired request changes nothing.
- **One signing gateway for both paths.** The filinq signing call, signer resolution and
  orphan recovery move out of `OfferEsignService` into one shared gateway that offers and HR
  documents both use.

## Capabilities

### New Capabilities

- `hr-document-signing`: electronic signing of generated HR documents about an existing
  employee, with the status on the document and the written-contract flag closed on
  completion.

## Impact

- `lib/Settings/register.d/hr-documents.json`: `HrGeneratedDocument` gains
  `signingRequestId`, `signingStatus`, `signingCompletedAt` (0.3.0).
- `lib/Settings/register.d/hr-administratie.json`: `hrAdministration` gains
  `signatoryUserId`.
- `lib/Service/FilinqSigningGateway.php` (new, extracted from `OfferEsignService` and
  `OfferSigningRecoveryService`); `lib/Service/HrDocumentSigningService.php` (new).
- `lib/Controller/DocumentController.php` and `appinfo/routes.php`:
  `POST /api/documents/{id}/request-signature`.
- `lib/Command/DocumentsSyncSignaturesCommand.php` (new): `occ
  humaniq:documents:sync-signatures`, the read path.
- `src/manifest.d/hr-documents.json`: the action and the signing fields on
  `GeneratedDocumentDetail`.

## Cross-app dependencies

- **filinq**: `SigningService` as used by `offer-esign`. Its signer must be a Nextcloud user
  and its request needs a signed-in session, so an employee without a Nextcloud account
  cannot sign yet; a signer outside Nextcloud is filinq's to add (the same gap `offer-esign`
  recorded for candidates).

## Out of scope

- Signing documents humaniq did not generate (an uploaded PDF).
- Signing payslips and annual statements; they need no signature.
- Qualified (QES) signatures. The level stays what filinq offers, as for offers.

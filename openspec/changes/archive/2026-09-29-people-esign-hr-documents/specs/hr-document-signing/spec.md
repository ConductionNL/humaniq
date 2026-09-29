# hr-document-signing

## ADDED Requirements

### Requirement: A generated HR document about an employee SHALL be sendable for electronic signature (REQ-HDS-001)

`GeneratedDocumentDetail` SHALL offer `Request signature` for a generated
`arbeidsovereenkomst`, `werkgeversverklaring` or `getuigschrift`. It SHALL raise a filinq
signing request on the stored PDF with the employer's signatory first and, for an
employment contract, the employee second. It SHALL refuse a `loonstrook` or `jaaropgaaf`.
It SHALL be available to admins and HR only and SHALL answer 404 for a document the caller
may not read. Without filinq it SHALL record `skipped-no-docudesk` and raise nothing.

Rows: `ppl-esign-documents` (humaniq matrix).

#### Scenario: A new contract goes out for signing
- **GIVEN** a generated employment contract for an employee with Nextcloud account
  `s.deboer` and an administration whose signatory is `directie`
- **WHEN** an HR adviser presses `Request signature` on `GeneratedDocumentDetail`
- **THEN** a filinq signing request exists with `directie` first and `s.deboer` second, and
  the document shows signing status `PENDING`

@e2e exclude the request goes to filinq, which needs a signed-in signer; covered by HrDocumentSigningServiceTest::testANewContractGoesOutForSigning and DocumentSigningControllerTest

#### Scenario: A werkgeversverklaring needs only the employer
- **GIVEN** a generated werkgeversverklaring
- **WHEN** an HR adviser requests a signature
- **THEN** the request has one signer, the administration's signatory

@e2e exclude signer selection is server-side; covered by HrDocumentSigningServiceTest::testAnEmployerStatementNeedsOnlyTheEmployer

#### Scenario: An employee without an account is told why
- **GIVEN** a generated employment contract for a portal-only employee without a Nextcloud
  account
- **WHEN** an HR adviser requests a signature
- **THEN** no request is raised and the document shows `failed` with the reason
  `no-nextcloud-user-for-employee`

@e2e exclude signer resolution is server-side; covered by HrDocumentSigningServiceTest::testAnEmployeeWithoutAnAccountIsToldWhy

### Requirement: The signing status SHALL be kept on the document, one request at a time (REQ-HDS-002)

`HrGeneratedDocument` SHALL carry the signing request id, its status and the completion
time. A request while one is pending or in progress SHALL return the existing request. A new
request SHALL be allowed after a decline, cancellation, expiry or failure. A sync command
SHALL read statuses without a signed-in session.

Rows: `ppl-esign-documents` (humaniq matrix).

#### Scenario: A double click raises one request
- **GIVEN** a document with signing status `IN_PROGRESS`
- **WHEN** `POST /api/documents/{id}/request-signature` is called again
- **THEN** the answer names the existing request and filinq holds one request for the
  document

@e2e exclude idempotency is server-side; covered by HrDocumentSigningServiceTest::testADoubleClickRaisesOneRequest

### Requirement: A completed contract signature SHALL mark the contract as written (REQ-HDS-003)

When the signing request of an `arbeidsovereenkomst` completes, humaniq SHALL set the linked
contract's `writtenContract` to true and SHALL stamp the completion time on the document.
A declined, cancelled or expired request SHALL NOT change `writtenContract`.

Rows: `ppl-esign-documents` (humaniq matrix).

#### Scenario: The unemployment-fund rate check reads the signature
- **GIVEN** a permanent contract with `writtenContract` false and its generated contract
  document in signing
- **WHEN** both signers have signed and `occ humaniq:documents:sync-signatures` runs
- **THEN** the contract's `writtenContract` is true and the document reads `COMPLETED` with
  today's completion time

@e2e exclude the sync runs from occ; covered by HrDocumentSigningServiceTest::testACompletedContractIsMarkedWritten and ::testADeclinedContractChangesNothing

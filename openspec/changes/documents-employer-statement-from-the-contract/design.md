# Design: make an employer statement from the contract page

## Context

- `lib/Controller/DocumentController.php::generate()` already takes any letter type with a
  `contractId`, resolves the contract under the caller's RBAC first (404 otherwise), and calls
  `HrDocumentService::generate($employeeId, $contractId, $documentType, $userId)`.
- `HrDocumentService` keys the letter types on (contract or employee, document type) and lets
  only one `pending` or `generated` document exist per key (main spec
  `humaniq-docudesk-documents` REQ-HDD-006).
- `HrDocumentSigningService` lists `werkgeversverklaring => false`: it can be signed, it is
  not required.
- filinq `templates-employer-statement` ships the template; until then generation ends
  `failed` with "no template", which the page shows.

## Decisions

### D1. The action sits on the contract, not the employee

The statement states the contract: start date, type, hours and salary. An employee with two
contracts needs a statement per contract. The action goes on `EmploymentContractDetail`,
next to `Genereer arbeidsovereenkomst`, with the same `api-call` shape and `confirm: true`.

### D2. A statement is never deduplicated

`werkgeversverklaring` is taken out of the REQ-HDD-006 key. Every confirmed action makes a
new `GeneratedDocument`. The earlier statements stay in the file, because a lender may ask
which statement was issued on which date.

Rejected: reusing the last statement when it is recent. "Recent" is the lender's rule, not
ours, and differs per lender.

## Open choices (for Ruben)

### O1. Self-service

Competitors let an employee request a statement. Two ways:

- The employee makes it from `MijnGegevens`. Fast, but HR does not see it before it leaves.
- The employee asks, HR makes it. Needs a request object and a notification.

This change builds neither. It is a follow-up once Ruben picks one.

## Risks

- [Statements pile up] -> each is small and dated; retention follows
  `personnel-retention-expiry` like every generated document.

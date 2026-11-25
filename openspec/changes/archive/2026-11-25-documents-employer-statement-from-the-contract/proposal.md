---
kind: code
depends_on:
  - filinq/templates-employer-statement
---

# Make an employer statement from the contract page

## Why

An employee who applies for a mortgage or a rental home needs an employer statement
(werkgeversverklaring). Humaniq can render one, but only from the command line:
`occ humaniq:documents:generate --type werkgeversverklaring`. No page offers it. The contract
page has `Genereer arbeidsovereenkomst` and the payslip page has `Genereer PDF`; nothing
offers the statement. So HR asks an administrator, or types the letter by hand.

The template is filinq's. filinq `templates-employer-statement` adds the
`werkgeversverklaring-standaard` template in the `hrmq` namespace. This change is humaniq's
half: the page action.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `fil-employer-statement` | Produce an employer statement for an employee's mortgage or rental application. | `partial`: occ trigger and a viewer, no page can request one |

### Competitors rated yes

AFAS, Visma Raet and HR2day (row evidence in the matrix).

## What changes

- `EmploymentContractDetail` gets a page action `Werkgeversverklaring maken`. It calls the
  existing `POST /api/documents/generate` with the contract and
  `documentType: werkgeversverklaring`. No new route.
- Every confirmed action makes a new statement. A statement is dated, and each application
  needs a fresh one, so the one-per-contract rule of the other letter types does not apply.
- The new statement appears on the contract page and in the employee's documents.

## What does not change

- The template and the rendering stay filinq's.
- Signing stays as `people-esign-hr-documents` built it.

## Open question

- Whether an employee may make their own statement from `MijnGegevens`, or only ask HR. Not
  in this change; see design O1.

## Capabilities

### Modified capabilities

- `humaniq-docudesk-documents`: the employer statement gets a page action.

## Impact

- `src/manifest.d/hr-objects.json` (`EmploymentContractDetail` action), l10n en and nl.
- `lib/Service/HrDocumentService.php`: the idempotency rule skips `werkgeversverklaring`.
- Tests for `DocumentController` and `HrDocumentService`.

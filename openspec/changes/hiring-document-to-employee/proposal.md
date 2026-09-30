---
kind: code
---

# Read a new hire's contract or ID into their record

## Why

`hiring-hire-to-employee` built `Create employee` on a hired application and the rehire
match. Its third part, reading a supplied contract or identity document into the new
employee's empty fields, could not be built: filinq has no contract or identity-document
extraction. Its extractors at `development` are financial only (amount, date, IBAN, KvK, VAT
id, totals, in `lib/Service/Extraction/`), `DocumentTextExtractor` returns plain text, and
there is no machine-readable-zone (MRZ) reader. Calling a service that does not exist would
be a guard with no counterpart, so the part moved here.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `dm-document-to-employee` | Have supplied documents such as a signed contract read automatically into a draft new employee for HR to check. | `building`: `Create employee` fills the employee from the application (`hiring-hire-to-employee`); reading a contract or ID is not built |

### Demand

- `dm-document-to-employee`, roadmap: https://loket.nl/roadmap/

## What Changes

- **A supplied document fills the empty fields.** On `OnboardingDetail`, `Read document`
  sends a file attached to the case to filinq's extraction as an employment contract or an
  identity document. humaniq fills only the employee's empty fields, records what was read,
  with what confidence and which fields it wrote, and offers `Create contract from
  document` for the contract values. HR checks every value before the checklist moves on.
- New schema `EmployeeDocumentExtraction` (`hr-onboarding.json`), with its own
  authorization, read included: HR only.
- `EmployeeDocumentExtractionService`, and `POST /api/onboarding/{id}/read-document` and
  `POST /api/onboarding/{id}/contract-from-document` on a controller guarded like
  `HireController` (resolve first, then HR or an administrator).

## Design

As `hiring-hire-to-employee` design D4 recorded it (archived with that change):
the service mirrors `ReceiptExtractionService`: duck-typed to filinq through `FleetAppId`,
`skipped-no-docudesk` when filinq is absent, `failed` on an error, never throws past its
public method, writes only empty `Employee` fields (`firstName`, `lastName`, `dateOfBirth`,
`bsn`) carrying every other field (OpenRegister saves are a full replace), and creates at
most one `EmploymentContract` per extraction.

## Cross-app dependencies

- **filinq**: extraction of an employment contract (names, start and end date, hours per
  week, hourly or monthly wage, contract type) and of an identity document (names, date of
  birth, document number, expiry date, personal number), with a confidence per field.
  Drafted for Ruben in `for-ruben/filinq-employee-document-extraction.md`; this change waits
  on it.

## Out of scope

- Checking an identity document for authenticity. That is `people-dossier-completeness`.

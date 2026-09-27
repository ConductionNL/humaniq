# Design: from hired applicant to employee without retyping

## Context

Read at `development` af702f78.

- `job-application` (`lib/Settings/register.d/hr-ats.json`, 0.3.0) holds `candidateName`
  (one full-name string), `email`, `phone`, `cvFile`, `motivation`, `administrationId` and
  the offer fields. The `aannemen` transition (`aanbod` to `aangenomen`, terminal) says:
  "HR creates the Employee/Onboarding case by hand from this application's data ... no
  cross-object write hook fires here". There is no link from an application to an employee.
- `ApplicationDetail` (`src/manifest.d/hr-ats.json:4`) has one page-level `api-call`
  action (`request-offer-signature`, `POST /api/offer/request-signature`, line 202) guarded
  by `OfferController` (resolve first, then admin or HR).
- `Employee` (`lib/Settings/register.d/hr-objects.json:5`, 0.8.1): required `lastName`,
  `startDate`; `firstName`, `bsn`, `dateOfBirth`, `endDate`, `nextcloudUserId`,
  `administrationId`. No e-mail or phone field.
- `Onboarding` (`hr-onboarding.json`, 0.2.0): `employeeId`, `startDate`, `status` with
  initial `aangenomen`, the checklist booleans.
- `EmploymentContract` holds `employeeId`, `type`, `startDate`, `endDate`, `hoursPerWeek`,
  `hourlyWage`, `writtenContract`.
- `lib/Service/ReceiptExtractionService.php` is the extraction precedent: it resolves
  filinq's `FinancialExtractionService` through `FleetAppId::getService()`, records every
  attempt on a `ReceiptExtraction` object (`hr-expense.json`), writes only fields the user
  left empty, and never triggers a lifecycle transition. Its availability probe records
  `skipped-no-docudesk` and never throws.
- `src/dialogs/` holds humaniq's isolated dialogs (`HoursBookingDialog.vue`), the place
  `hydra-gate-modal-isolation` requires.

## Goals / Non-Goals

**Goals**

- One action turns a hired application into an employee and an onboarding case.
- A returning person keeps one record.
- A supplied contract or ID fills empty fields, with provenance, and HR checks them.

**Non-Goals**

- Hiring automatically on a status change or a signature.
- Overwriting a field HR already filled.
- Judging whether a document is genuine.

## Decisions

### D1. An explicit HR action, not a transition hook

`Create employee` appears on `ApplicationDetail` when `status` is `aangenomen`. It opens
`HireApplicationDialog`, which reads `GET /api/applications/{id}/hire-matches` and posts
`POST /api/applications/{id}/hire` with `{startDate, firstName, lastName, bsn?,
dateOfBirth?, attachToEmployeeId?, createNew?}`.

Alternative considered: a declared flow on the `aannemen` transition. Rejected: the
duplicate check needs a person's choice, a start date is often not known at `Hire`, and a
shipped flow arrives disabled, so the data would still not move on a fresh install.

### D2. What moves, and what stays

`HireService::hire()` writes: `Employee.firstName`, `lastName` (proposed by splitting
`candidateName` on its last space, shown for HR to correct), `privateEmail` from `email`,
`phone`, `administrationId`, `startDate`, and optional `bsn` and `dateOfBirth`; an
`Onboarding` at `aangenomen` with the same `startDate`; and `job-application.employeeId`.
`status` on the application is carried unchanged in the save payload, so no transition
fires. The CV, motivation and answers stay on the application under its own retention clock.

When `job-application.employeeId` is already set, the call is a no-op that answers that
employee's id, so a double click never creates two records.

### D3. The duplicate check is ordered and shown, never silent

`HireMatchService::matches()` returns existing employees, active or former, in this order:
same `bsn`; same `lastName` and `dateOfBirth`; same `privateEmail`, case-insensitive. Each
match carries which key matched and whether the person has an `endDate`. When matches exist
and the request carries neither `attachToEmployeeId` nor `createNew: true`, the hire
answers 409 with the matches.

Attaching to a former employee clears `endDate`, sets `startDate` to the new start and
creates the onboarding case on that record. Earlier `EmploymentContract` rows are left as
they are, so the old employment stays readable. Attaching to an active employee (an internal
move) changes no dates and only creates the onboarding case.

Alternative considered: matching on name alone. Rejected: two different Jan de Vries are
common; name plus date of birth is the weakest key that is still a person.

### D4. Document reading mirrors receipt OCR

`EmployeeDocumentExtractionService::read(string $onboardingId, string $fileId, string
$documentKind)` with `documentKind` `employment-contract` or `identity-document`:

- resolves filinq duck-typed through `FleetAppId`, records `skipped-no-docudesk` when it is
  absent, `failed` on an error, and never throws past its public method;
- records an `EmployeeDocumentExtraction` (`onboardingId`, `employeeId`, `fileId`,
  `documentKind`, `status`, `overallConfidence`, `extractedFields`, `appliedFields`,
  `requestedBy`, `errorMessage`, `extractedAt`);
- writes only empty `Employee` fields (`firstName`, `lastName`, `dateOfBirth`, `bsn`),
  carrying every other field unchanged in the payload;
- keeps contract values on the record; `Create contract from document` then creates one
  `EmploymentContract` from them for HR to open and check. One extraction creates at most
  one contract.

Alternative considered: prefilling the contract create form. Rejected: the renderer has no
create-form defaults (recorded in `mss-team-scope`), so a created record that HR reviews is
the honest path.

### D5. All four endpoints are guarded the same way

`HireController` methods are `#[NoAdminRequired]`, resolve the application or case through
`RbacObjectReader` first (404 when unreadable), then require admin or HR, the
`OfferController` shape.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| create employee and case from an application | imperative `HireService` behind an HR action | a cross-object write that needs a human choice |
| duplicate match | imperative `HireMatchService` | an ordered match over one schema with a reason per hit |
| document reading | imperative, duck-typed to filinq | external document processing, ADR-031 "document generation" class |
| application to employee link | declarative `$ref` field | plain relation |
| the actions on the pages | declarative manifest actions and one isolated dialog | existing primitives |

## Seed data

- The seeded `aangenomen` application gains `employeeId` pointing at a seed employee, so
  `ApplicationDetail` shows `Open employee`.
- One seed former employee (with `endDate`) shares last name and date of birth with a
  second seeded `aanbod` application, so the match list has content after `Hire`.
- One `EmployeeDocumentExtraction` in status `extracted` on the seeded onboarding case.

## Risks / Trade-offs

- [Name split is wrong for "van der" names] → the split is a proposal HR edits in the
  dialog, never written unseen.
- [A BSN read from an ID copy] → it fills only an empty `bsn`, the extraction record names
  its source file, and `bsnValidated` stays HR's tick.
- [Former employee's start date is overwritten] → the earlier start stays on the earlier
  contract and in the audit trail.

## Open Questions

- Should attaching to a former employee also restart `identityDocumentRetainedUntil`, or does
  that stay with the ID check in onboarding?

# Design: generate annual statements from their pages

## Context

Read at `development` af702f78.

- `lib/Controller/DocumentController.php:98` `generate(contractId, documentType, payslipId)`
  behind `POST /api/documents/generate` (`appinfo/routes.php:25`), `#[NoAdminRequired]`.
  Lines 102 to 107 answer `jaaropgaaf` with 400 "Jaaropgaaf genereren is niet beschikbaar via
  dit endpoint (alleen via occ humaniq:documents:generate)". The `loonstrook` branch resolves
  the payslip under the caller's RBAC (`authorizePayslip()`, line 197) before calling the
  service; that is the pattern to copy.
- `lib/Service/HrDocumentService.php`: `generateJaaropgaaf(employeeId, year, userId)`
  (line 455) aggregates with `upsertJaaropgaaf()` (line 863, one `Jaaropgaaf` per employee and
  year from that year's payslips) and renders through filinq; idempotent on
  `(jaaropgaafId, documentType)`. `generateBacklog('jaaropgaaf', employeeId, null, year)`
  (line 246) runs `jaaropgaafBacklog()` (line 352) over every employee with a payslip in the
  year.
- `lib/Command/DocumentsGenerateCommand.php`: `occ humaniq:documents:generate --type
  jaaropgaaf --year`, the only caller of the backlog for jaaropgaven.
- `src/manifest.d/hr-documents.json`: `Jaaropgaven` index (line 74) and `JaaropgaafDetail`
  (line 99), whose note says it has no actions because the aggregate is upserted server side.
- `PayslipDetail`'s "Generate PDF" api-call (`src/manifest.d/hr-objects.json`, around line 806)
  posts `{payslipId: @objectId, documentType: loonstrook}`.
- `lib/BackgroundJob/CompleteHoursMigrationJob.php` is a `QueuedJob` precedent.
- `lib/Service/AdministrationService.php:165` `getActiveAdministrationRole()` answers the
  caller's role for the active administration.

## Goals / Non-Goals

**Goals**

- One statement, and one year's batch, can be started from the pages.
- The same guards apply as for the loonstrook: RBAC first, then the service.

**Non-Goals**

- Reissuing a statement that already has a PDF.
- Delivery to employees.

## Decisions

### D1. The single statement goes through the existing endpoint

`generate()` accepts `documentType: jaaropgaaf` with a `jaaropgaafId`. It resolves the
`Jaaropgaaf` under the caller's RBAC (404 otherwise), reads its `employeeId` and `year`, and
calls `generateJaaropgaaf()`. The service's idempotency answers "already generated" when a
generated document exists, which the page shows as the result.

Alternative considered: a new endpoint for jaaropgaven only. Rejected: one generate endpoint
dispatching on `documentType` is the shape the loonstrook already uses.

### D2. The year batch is a queued job

`POST /api/documents/jaaropgaven {year}` requires an administrator or the `hr` role. `year`
defaults to the previous calendar year and must not be the current or a future year. It counts
the employees with payslips in the year, queues `JaaropgaafYearJob` with the year and the
caller, and answers 202 with the count. The job calls
`generateBacklog('jaaropgaaf', null, null, year)`.

Alternative considered: run the batch inside the request. Rejected: a render per employee
through filinq for a few hundred employees outlives a web request, and the backlog is already
idempotent, so a queued run that is repeated does no harm.

### D3. The index action carries no year field

The `Jaaropgaven` header action posts without a year, so it means "last year", which is the
January job. Another year stays available through occ and through the endpoint's `year`
parameter.

Alternative considered: a host dialog with a year picker. Rejected for now: a single fixed
action covers the yearly need without a host component; a picker can follow if asked for.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| the two page actions | declarative manifest api-call actions | the library primitive exists |
| resolving and rendering | imperative, the existing `HrDocumentService` | unchanged |
| the year batch | imperative `QueuedJob` | long-running work outside the request |

## Seed data

No schema changes. The seeded payslips for 2026 give the batch something to generate on a dev
instance once the year is over, and the single action works on any seeded `Jaaropgaaf`.

## Risks / Trade-offs

- [Two batch requests for the same year] -> both jobs run the idempotent backlog; the second
  finds the documents generated and writes nothing new.
- [An aggregate refreshed after its PDF] -> the figures on the page can differ from the PDF
  once a correction lands; reissuing is the named follow-up, and the page shows the PDF's
  generation date next to the aggregate's.

## Open Questions

- Should the batch skip employees whose statement already has a PDF, or report them? This
  design reports them as already generated.

## Changes during the build (2026-09-29)

- The batch is allowed for HR **and payroll** (`HumaniqRoles::isHr()` or `isPayroll()`, both true
  for an administrator). The `hr` role this design named became the `humaniq-hr` group in
  compliance-roles-and-field-access, which also added `humaniq-payroll`; the payroll officer is
  the natural owner of the January run.
- The count is in the 202 response (`{year, queued}`). A manifest `api-call` toast has a fixed
  text, so the page confirms the queue without the number.
- `HrDocumentService::jaaropgaafEmployeeIds()` is the one place that lists a year's employees;
  the backlog and the count both use it.

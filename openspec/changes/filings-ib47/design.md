# Design: payments to third parties reported to the tax authority

## Context

Read at `development` af702f78.

- No schema, rule or page mentions third-party payments (`grep -rniF 'ib.?47' lib src openspec`
  finds nothing).
- Filing precedents: `LoonaangifteFiling` and `PensionFiling` carry a lifecycle, a deadline and
  deadline rules (`NlWageTaxFilingChecks`, `NlPensionFilingChecks`); `WkrAssessment` is a
  yearly per-administration roll-up (`lib/Service/WkrService.php`,
  `occ humaniq:wkr:assess`).
- Documents: `lib/Service/HrDocumentService.php` generates HR documents through filinq with a
  type and a variable contract (`DocumentsGenerateCommand` types).
- `hrAdministration` carries `loonheffingennummer`, which the report's header needs.

## Goals / Non-Goals

**Goals**

- Every reportable payment recorded once, reported once a year, on time.

**Non-Goals**

- Deciding whether a person is a third party in the fiscal sense. HR records them as such.

## Decisions

### D1. Three schemas

`ThirdPartyPayee`: `name`, `bsn`, `dateOfBirth`, address fields, `administrationId`.
`ThirdPartyPayment`: `payeeId` ($ref), `paidOn`, `amount`, `expenseAllowance`,
`description`, `administrationId`. `ThirdPartyReport`: `administrationId`, `year`,
`messageFileId`, `lineCount`, `totalAmount`, `deadline` (31 January of `year + 1`), `status`
with lifecycle `klaarzetten`, `verzenden`, `heropenen`.

### D2. The report

`ThirdPartyReportService::assemble(administrationId, year)` sums payments per payee, renders the
delivery format with the administration's `loonheffingennummer`, validates it against the
year's schema (shipped under `lib/Standards/ubd/`), and stores the file on the report at
`klaarzetten` through a guard in the `LoonaangifteMessageGuard` shape.

### D3. Rules

`nl-ubd-deadline` (mandatory): a year with payments and no report in `verzonden` by the deadline.
`nl-ubd-payee-identification` (mandatory): a payee with payments and no BSN or date of birth.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| records and lifecycle | declarative schemas | data and a state machine |
| yearly sums and file | imperative service | aggregation across payees plus document generation |
| deadline and identification | corpus rules | the filing rule family |

## Seed data

- Two payees (a guest lecturer, a committee member) with three payments in 2026 on the seed
  administration; the 2026 report in concept.

## Risks / Trade-offs

- [BSN of non-employees] → the same property authorization as employee BSNs
  (`compliance-roles-and-field-access`) applies to `ThirdPartyPayee.bsn`.

## Open Questions

- Should payments be imported from shillinq's payables instead of entered? This design records
  them in humaniq; an import is a later change.

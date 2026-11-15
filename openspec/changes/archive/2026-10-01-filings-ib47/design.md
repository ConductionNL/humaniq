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

## As built (2026-10-01): where the code differs from the decisions above

- **The official format is per melding, not a free file.** The Belastingdienst's UBD 1.0 XSD
  (ODB product zip "Uitbetaalde Bedragen aan Derden m.i.v. 01-01-2024 v08", CC0) has one
  `Uitbetalingsmelding` per payment, and allows summing a payee's payments of a year into one
  melding dated on the last payment (Handleiding Deel 2, 2.3.4). humaniq sends one melding per
  payee and year, amount including expense allowances (Deel 1, 1.4), rounded down to whole
  euros. The meldingsID is a hash of administration, year and payee, so a report assembled
  again after reopening a sent one corrects the earlier meldingen (Deel 2, 2.3.5).
- **D1 names.** The format needs surname, prefix and initials apart, so `ThirdPartyPayee` has
  `lastName`, `prefix` and `initials` instead of `name`, and the address as street, house
  number, addition, postcode, city and country (the format's `adresvast`).
- **D1 file.** The report stores the message itself (`messageXml`) with its upload file name
  (`UBD_<loonheffingennummer>_<leveringsID>.xml`) and `leveringsId`, instead of a
  `messageFileId`; a yearly message is small, and it keeps the report one object.
- **D2 guard.** Assembly is an endpoint (`POST /api/third-party/reports/assemble`), not a
  side effect of `klaarzetten`; the guard on `klaarzetten` (UbdReportReadyGuard) refuses while
  the report has blocking findings or no message.
- **The bron address.** The format needs the employer's address; `hrAdministration` gains
  `postalAddress` (one line, sent as `adresvrij`).
- **relNr.** The message carries the software developer's relation number with the
  Belastingdienst (SWOxxxxx). It is the app config key `ubd_relnr`; while it is empty the
  report has a warning, not a blocking finding.
- **D3 rules** read a `ubd` index RuleAuditService builds (sent report keys, paid payees).
- **The statement** is generated for every payee of a report in one action
  (`POST /api/third-party/reports/{reportId}/statements`), through filinq's `hrmq` template
  namespace with category `ubd-jaaropgaaf`, and stored as a file on the payee.

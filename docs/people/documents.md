---
sidebar_position: 5
description: Generating the standard HR letters — arbeidsovereenkomst, aanbiedingsbrief, werkgeversverklaring, getuigschrift — through docudesk.
---

# Documents

Beyond [payslips and annual statements](/docs/payroll/payslips), Humaniq
generates the standard Dutch HR letters from templates hosted in
[docudesk](https://docudesk.conduction.nl):

- `arbeidsovereenkomst` — employment contract
- `aanbiedingsbrief` — offer letter
- `werkgeversverklaring` — employer's statement (e.g. for a mortgage
  application)
- `getuigschrift` — reference/testimonial letter

Humaniq ships **no template engine of its own** — `HrDocumentService`
assembles the object references and calls docudesk's
`DocumentService::generateDocument()` same-instance, duck-typed via the
DI container, never over HTTP. Every generation attempt is recorded as a
`GeneratedDocument` — `pending`, `generated`, `failed`, or
`skipped-no-docudesk`.

## Template selection: config first, discovery second, fail closed

For each document type, Humaniq first checks a configured template UUID
(`SettingsService` getter `documents_template_{documentType}`); if unset,
it falls back to discovering exactly one docudesk template tagged
`namespace: hrmq` (the docudesk template namespace is deliberately
unchanged by the app rename) with a matching `category`. Zero or multiple matches
fail the attempt closed with a diagnostic naming the ambiguity — nothing
is ever rendered against a guess.

## Idempotency

At most one `GeneratedDocument` in `pending` or `generated` status exists
per idempotency key. The four letter types key on `(contractId,
documentType)` — or `(employeeId, documentType)` when there's no
contract. Re-running generation for an already-documented contract is a
no-op; a `failed` or `skipped-no-docudesk` record never blocks a retry.

## Generating documents

```bash
occ humaniq:documents:generate
occ humaniq:documents:generate --type=werkgeversverklaring --employee=<employee-id>
```

With no options, the default backlog is every permanent
`EmploymentContract` with `writtenContract: true` that has no active
arbeidsovereenkomst document yet. The letter types other than
`arbeidsovereenkomst` have no backlog semantics of their own and require
`--employee`. See [Payslips](/docs/payroll/payslips) for the
`--type loonstrook` and `--type jaaropgaaf` backlog behaviour.

A single guarded endpoint (`POST /api/documents/generate`) backs the
"Genereer arbeidsovereenkomst" action on `EmploymentContractDetail` and
the "Genereer PDF" action on `PayslipDetail` — resolved under the
caller's RBAC before any docudesk call, so an unknown or unauthorized
contract/payslip id returns 404 with nothing rendered.

## Graceful degradation

When docudesk isn't installed, every attempt records
`skipped-no-docudesk` — no exception, exit code 0. Once docudesk is
installed, the next run supersedes the skip and renders normally. Humaniq
carries no `info.xml` or composer dependency on docudesk.

## Machine-checked evidence

**`nl-contract-schriftelijk`** (severity `recommended`, source BW art.
7:655) flags a permanent contract with `writtenContract: true` that has no
active, `generated` arbeidsovereenkomst document referencing it —
temporary contracts are never flagged.

```bash
occ humaniq:rules:audit
```

## Pages

`GeneratedDocuments` (under Personeel, labeled "Documenten") lists
`documentType`, `status`, `employeeId`, and `generatedAt`.
`GeneratedDocumentDetail` shows the record's fields, resolves the related
Employee and EmploymentContract, and offers the standard Files and Audit
Trail sidebar tabs.

---
sidebar_position: 4
description: Loonstrook (payslip) and jaaropgaaf (annual statement) PDFs, rendered through docudesk.
---

# Payslips & annual statements

Every `Payslip` gets a downloadable loonstrook PDF, and every
employee-year gets a jaaropgaaf PDF. Humaniq does not ship its own PDF
rendering engine — `HrDocumentService` assembles the data and hands it to
[docudesk](https://docudesk.conduction.nl)'s template/rendering pipeline
(`namespace: hrmq` templates — the docudesk template namespace is
deliberately unchanged by the app rename), called same-instance through
`DocumentService::generateDocument()`. When docudesk is not installed, the
attempt degrades gracefully to `status: skipped-no-docudesk` rather than
failing.

## Loonstrook (payslip PDF)

A loonstrook is rendered with `dataRefs = [Employee, Payslip]` — the
template reads `Employee.*`, `Payslip.*`, and an `employer.*` block
(including a configured `loonheffingennummer`). No Payslip field values
are hand-copied into ad-hoc data; the template resolves everything through
the refs. The generated PDF is stored via OpenRegister's FileService as
`loonstrook-{employeeNumber}-{YYYY-MM-DD}.pdf`, and the attempt is
recorded as a `GeneratedDocument` with `documentType: loonstrook` and
`payslipId` set.

## Jaaropgaaf (annual statement)

A `Jaaropgaaf` is a per-employee-per-year aggregate, derived **only** from
fields that actually exist on `Payslip` — nothing is fabricated. Generating
a jaaropgaaf for a year first upserts the aggregate (summing every payslip
whose period falls in that year), then renders it with `dataRefs =
[Employee, Jaaropgaaf]`:

- `totalGrossPay`, `totalLoonheffing`, `totalNettoPay` — summed from the
  year's payslips
- `totalZvwWithheld` — summed only from payslips where `zvwMode` is
  `inhouding`; the employer's `werkgeversheffing` is never a werknemer's
  own contribution and is excluded
- `totalPensionContribution`, `totalVakantiegeldReserved`, `payPeriodCount`
- `verrekendeArbeidskorting` — stays `null`, not `0.00`, because no
  `Payslip` field carries arbeidskorting. The value is genuinely unknown,
  and the aggregate says so rather than fabricating a number.

Re-running the aggregation for the same employee and year **updates the
existing object in place** — it never creates a duplicate. An employee
with zero payslips in the requested year produces a `failed` outcome with
a diagnostic; no partial aggregate is ever written.

## Generating documents

```bash
occ humaniq:documents:generate --type=loonstrook --employee=<employee-id> --period=2026-06
occ humaniq:documents:generate --type=jaaropgaaf --employee=<employee-id> --year=2026
```

- `--type` — `loonstrook` or `jaaropgaaf` (also accepts the other HR
  letter types; see [Documents](/docs/people/documents))
- `--employee` — the Employee id
- `--period` — restricts the loonstrook backlog to one `YYYY-MM` period
- `--year` — the year to aggregate/render (required for `jaaropgaaf`)

A guarded API endpoint offers the same payslip-generation action from
`PayslipDetail` in the UI.

## Machine-checked evidence

`nl-loonstrook-verplicht` (severity `recommended`, source BW 7:626) is the
machine-checkable evidence companion to the (narrative,
non-machine-checkable) obligation to provide employees with a loonstrook:
every `Payslip` should have an active `generated` `GeneratedDocument` of
type `loonstrook` referencing it. Whether the paper actually reached the
employee is outside what the system can check — the rule only verifies
that Humaniq generated the document.

```bash
occ humaniq:rules:audit
```

## Jaaropgaven pages

`Jaaropgaven` (under Loonadministratie) lists `employeeId`, `year`,
`totalGrossPay`, `totalLoonheffing`, and `payPeriodCount`, sorted by year
descending. `JaaropgaafDetail` shows the aggregate's fields alongside the
related Employee — a `Jaaropgaaf` carries no lifecycle of its own, and its
PDF lives on the associated `GeneratedDocument`, not on the aggregate.

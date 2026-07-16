---
sidebar_position: 2
description: Employee expense claims with a submit / approve / reject / reimburse workflow, receipt OCR prefill, and mileage-rate compliance.
---

# Expense claims

`Expense` models an employee expense-reimbursement claim as an
OpenRegister object in the `hrmq` register, with a declarative
`x-openregister-lifecycle` on its `status` field (initial `draft`,
terminal `reimbursed`).

## The lifecycle

```
draft → submitted → approved → reimbursed
          ↕
       rejected
```

| Transition | From → to |
| --- | --- |
| `submit` | `draft` or `rejected` → `submitted` |
| `approve` | `submitted` → `approved` |
| `reject` | `submitted` → `rejected` |
| `reimburse` | `approved` → `reimbursed` (terminal) |

`reimburse` is only reachable from `approved` — attempting it from
`submitted` is refused. Every `Expense` carries at least `employeeId`,
`title`, `amount`, `currency`, `category`, `expenseDate`, `status`,
`submittedAt`, `approvedBy`, `approvedAt`, `rejectionReason`, and
`reimbursedAt` (stamped when `reimburse` executes).

The lifecycle is expressed declaratively in the register fragment, the
same pattern as [Timesheets](/docs/hr/timesheets) — no hand-written Vue
approval views and no bespoke reimbursement service code.

## Receipt OCR prefill

An `Expense` with an attached `receiptFile` can be automatically
prefilled from the receipt via [docudesk](https://docudesk.conduction.nl)'s
financial-extraction service — HRMQ assembles the request and applies
the result; docudesk owns all OCR/extraction logic.

**The rule that matters: prefill only empty fields, never overwrite a
human-entered value.** For each of `amount`, `expenseDate`, `vendor`,
and `vatAmount`, the extracted value is written **only** when that
field's current value is empty. A field the employee already typed in
is left untouched, even when the extraction returns a different value
for it — the raw extracted value is still recorded on a
`ReceiptExtraction` attempt log for audit, but the Expense field itself
does not change.

Extraction **never** changes `Expense.status` and never triggers a
lifecycle transition — a low-confidence or wrong extraction can prefill
a field for human review, but it can never advance the claim's
lifecycle or create an approved reimbursement on its own. A draft
expense stays draft after extraction; the employee must still
explicitly submit it.

When docudesk is not installed, extraction degrades to
`skipped-no-docudesk` rather than throwing, and no Expense field is
touched.

```bash
occ hrmq:expense:extract-receipt
occ hrmq:expense:extract-receipt --expense <id>
```

With no options this processes the backlog of every Expense with a
receipt and no active extraction attempt. A guarded "Extract receipt
data" action on the Expense detail page does the same for one claim —
reachable by the owning employee or an admin/HR caller.

## Mileage (reiskosten) compliance

`Expense` carries `travelType` (`business`/`commute`) and `distanceKm`
for travel-category claims. The 2026 onbelaste kilometervergoeding —
**€0,23/km** — is versioned rule-corpus data, not a hardcoded constant,
so a future Belastingdienst rate change is a one-number data edit.

A machine-checkable rule flags any travel claim reimbursing **more**
per kilometer than the onbelast rate — the excess (bovenmatige
vergoeding) becomes taxable wage in principle, and this surfaces as an
audit-time compliance signal, never a write-time block: an
over-rate claim still submits/approves/reimburses exactly as before.

```bash
occ hrmq:rules:audit
```

No gross-up of the excess onto any Payslip/PayrollRun is computed
automatically, and no vaste (fixed monthly) reiskostenvergoeding /
214-dagenregeling modelling exists yet — both are named follow-ups.

## Asset custody, not expenses

Physical company property (laptops, phones, vehicles, access passes) is
tracked separately in the [asset register](/docs/people/assets), not as
an expense claim.

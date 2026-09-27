---
kind: code
---

# Generate annual statements from their pages

## Why

In January a payroll officer owes every employee last year's annual statement (jaaropgaaf).
humaniq aggregates it from the year's payslips and renders the PDF through filinq, and the
`Jaaropgaven` pages show the result. What no page can do is start it. The one generate
endpoint, `POST /api/documents/generate`, answers a jaaropgaaf request with "only via occ
humaniq:documents:generate", so the officer either has shell access or asks someone who does.

The payslip-pdf-docudesk change that built the aggregate named this as its follow-up: "a
JaaropgaafDetail/year-batch UI trigger".

This change adds both triggers: generate one employee's statement from its page, and generate
last year's statements for everyone from the index, as a background job.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `pay-annual-statement` | Produce the annual statement of earnings for each employee. | `partial`, built.state `built`: `Jaaropgaven` and `JaaropgaafDetail` show the aggregate; generation is `occ humaniq:documents:generate --type jaaropgaaf` only |

### Competitors rated yes

- `pay-annual-statement`, AFAS Profit: "Profit prints, e-mails or files payslips and
  jaaropgaven (annual statements)"
  (https://help.afas.nl/help/NL/SE/Pay_Config_Slip_Ess.htm).
- `pay-annual-statement`, Visma Raet Youforce: "Aanvraag jaaropgaven for a full customer or
  administration"
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/2).
- `pay-annual-statement`, HR2day: "annual statement (jaaropgave) emailed to employees with an
  app notification" (https://www.hr2day.com/nieuws/hr2day-penguin-releasenotes/).
- `pay-annual-statement`, Loket.nl: "payslips and annual statements (jaaropgaven) directly
  available" (https://loket.nl/oplossingen-voor/eenvoudig-inzicht/).

### Recorded non-goals this change picks up

- payslip-pdf-docudesk (`openspec/changes/archive/2026-07-14-payslip-pdf-docudesk/design.md`):
  Non-Goals "a JaaropgaafDetail generate action (occ-only in MVP, see D6)" and "bulk/background
  generation"; Open Questions "Follow-ups tracked in Non-Goals: ... a JaaropgaafDetail/year-batch
  UI trigger, corrected-jaaropgaaf reissue flow." This change is the UI trigger and the year
  batch. The reissue of a corrected statement and delivery to the employee stay follow-ups.

## What Changes

- **Generate one statement from its page.** `JaaropgaafDetail` gains a "Generate PDF" action,
  like the one on `PayslipDetail`. It refreshes the aggregate and renders the PDF when none
  exists yet.
- **Generate a year for everyone.** The `Jaaropgaven` index gains "Generate last year's
  statements". It queues a background job that aggregates and renders a statement for every
  employee with payslips in that year, and answers at once with how many it queued.
- **One endpoint, guarded.** `POST /api/documents/generate` accepts `documentType: jaaropgaaf`
  with a `jaaropgaafId` and resolves the statement under the caller's RBAC first. A new
  `POST /api/documents/jaaropgaven` queues a year and requires an administrator or the `hr`
  role.
- **Progress where it already shows.** Each statement's `GeneratedDocument` appears in the
  documents list with its status (`pending`, `generated`, `failed`, `skipped-no-docudesk`), as
  every other generated document does today.

## Capabilities

### New Capabilities

- `payroll-annual-statement-action`: page triggers for one annual statement and for a year's
  batch, over the existing aggregation and rendering.

## Impact

- `lib/Controller/DocumentController.php` and `appinfo/routes.php`: the jaaropgaaf branch of
  `generate()` and `POST /api/documents/jaaropgaven`.
- `lib/BackgroundJob/JaaropgaafYearJob.php` (new, `QueuedJob`): runs the existing year backlog.
- `src/manifest.d/hr-documents.json`: one action on `JaaropgaafDetail`, one on `Jaaropgaven`.

## Out of scope

- Reissuing a corrected statement once a PDF exists (payslip-pdf-docudesk follow-up).
- Delivering statements to employees by notification or e-mail (payslip-pdf-docudesk
  follow-up), and an employee's own statements page.
- Year-to-date figures carried over from a previous payroll package:
  `payroll-period-edge-cases`.

## Cross-app dependencies

- filinq: unchanged. It renders the statement from the existing template contract; without
  filinq each statement is recorded as `skipped-no-docudesk`, as today.

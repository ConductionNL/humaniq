---
kind: code
depends_on:
  - payroll-core-basic
---

# Proposal: Payslip / Loonstrook Generation

## Why

Dutch employment law (Wet op de loonbelasting 1964, artikel 68) requires employers to provide employees with a loonstrook (pay stub) for every payment period and a jaaropgaaf (annual income statement) once per year. Employees increasingly expect digital self-service access to these documents without involving HR each time.

All 6 Dutch HRM competitors analysed for this change support PDF loonstrook generation. Four of six (exact-online-hrm, loket, loonnext, employes) additionally provide a digital employee portal for self-service access. Without payslip generation, hrmq cannot serve the Dutch SME and public-sector market at all — it is a P0-must feature.

The PDF format must follow the standard Dutch NL-formaat: bruto/netto/inhoudingen/cumulatieven. This is not an arbitrary design choice — Dutch employees and accountants expect a fixed sectioned layout for quick verification against Belastingdienst records.

This change is gated on `payroll-core-basic` completing first, as it provides the computed bruto/netto/loonheffing values that populate the loonstrook.

## What Changes

### New Schemas

- **Loonstrook** — OpenRegister schema storing all payslip fields (gross/net/deductions/year-to-date cumulatives) per employee per period, with lifecycle: `concept → gegenereerd → gepubliceerd → gedownload`.
- **Jaaropgaaf** — OpenRegister schema for the annual income statement with year-to-date aggregates per employee.

### New Services (custom PHP — PDF generation is an ADR-031 exception)

- **PdfLoonstrookService** — renders a PDF loonstrook in standard NL format from a Loonstrook object using a Twig/HTML template compiled to PDF via Dompdf.
- **PdfJaaropgaafService** — renders the jaaropgaaf PDF.

### New Background Job

- **LoonstrookGeneratieJob** — triggered after a SalarisRun (from payroll-core-basic) completes; iterates affected employees and creates Loonstrook objects in `concept` status.

### New Frontend

- **Werknemer portaal** — Vue index/detail pages allowing employees to list, view, and download their own loonstroken and jaaropgaaf. Powered by `CnIndexPage` + `CnDetailPage` with employee-scoped object store.

### Modified Capabilities

- **payroll-core-basic** — SalarisRun completion event dispatched to `LoonstrookGeneratieJob`.

## Capabilities

### New Capabilities

- **loonstrook-generatie** — PDF loonstrook generation in NL standard format (bruto, netto, inhoudingen, cumulatieven per periode)
- **jaaropgaaf-generatie** — Annual income statement (jaaropgaaf) PDF generation per kalenderjaar
- **werknemer-portaal** — Digital employee self-service portal: view, filter, and download personal loonstroken and jaaropgaaf

## Stakeholders

| Role | Responsibility | Goals |
|---|---|---|
| Salarisadministrateur | Runs payroll, triggers loonstrook generation, publishes payslips | Batch-generate all loonstroken in one action; approve before publishing |
| Werknemer (Employee) | Consumes loonstrook via portal | Self-service access; download PDF without HR contact |
| HR Medewerker | Manages employee access, handles ad-hoc requests | Fewer manual PDF requests; audit trail of access |
| Leidinggevende (Manager) | May view team payslip summaries (admin only) | Overview of payroll per team per period |

## Impact

- **Employees**: Zero-intervention access to current and historical payslips (up to 7 years, per AVG retention requirement).
- **Payroll administrators**: One-click batch generation post-run; preview PDF before publishing; jaaropgaaf batch at year-end.
- **HR**: Reduced manual payslip requests; full audit trail of which employee viewed which payslip and when.
- **Data**: Two new OpenRegister schemas introduced; no existing schemas modified.
- **Compliance**: Satisfies article 68 Wet LB 1964 (loonstrook obligation) and AVG 7-year retention.

---
capability: payslip-pdf-docudesk
status: in-progress
built_by: openspec/changes/payslip-pdf-docudesk
---

# payslip-pdf-docudesk Specification

**Status**: in-progress
**Scope**: hrmq (loonstrook + jaaropgaaf documents through the existing docudesk consumption leaf)
**OpenSpec changes**:
- [payslip-pdf-docudesk](../../changes/payslip-pdf-docudesk/) _(active)_ — `Jaaropgaaf` aggregate schema + loonstrook/jaaropgaaf rendering through `HrDocumentService` (dataRefs `[Employee, Payslip]` / aggregate-then-render `[Employee, Jaaropgaaf]`, per-payslip and per-jaaropgaaf idempotency), occ `--type loonstrook|jaaropgaaf` with `--period`/`--year`, payslip variant of the guarded endpoint, evidence rule `nl-loonstrook-verplicht` (BW 7:626), Jaaropgaven pages + PayslipDetail action (kind: code; extends `hrmq-docudesk-documents`)

## Purpose

Give every `Payslip` a downloadable loonstrook PDF and every employee-year a
jaaropgaaf PDF — rendered by docudesk from `namespace: hrmq` templates through
the already-shipped `HrDocumentService` pipe (hrmq assembles data, docudesk
renders; no Dompdf/Twig in hrmq, superseding the `spec/payslip-generation`
draft's in-app engine), with an honest `Jaaropgaaf` aggregate derived only from
real Payslip fields and machine-checked BW 7:626 evidence via
`nl-loonstrook-verplicht`. Requirements live in the active change's delta specs
until archive: `openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md`
(new capability) and `.../specs/hrmq-docudesk-documents/spec.md` (leaf extension).

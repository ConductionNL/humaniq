---
kind: code
---

# Payslip and jaaropgaaf PDFs via docudesk (loonstrook/jaaropgaaf as documents on the existing leaf)

## Why

**Routing decision — payslip PDFs render through docudesk, not inside hrmq.** The remote draft `spec/payslip-generation` (2026-05-23) proposed an in-hrmq PDF stack: `PdfLoonstrookService`/`PdfJaaropgaafService` on Dompdf + Twig templates in `lib/Templates/`, a `Loonstrook` schema with its own `concept → gegenereerd → gepubliceerd → gedownload` lifecycle, a `LoonstrookGeneratieJob`, and a werknemer portal — all gated on a `payroll-core-basic` engine that does not exist. That draft predates the `hrmq-docudesk-documents` leaf (archived 2026-07-13), which settled the division of authority fleet-wide: **rendering machinery lives in docudesk; hrmq only assembles data.** `HrDocumentService` already probes docudesk duck-typed, selects a `namespace: hrmq` template config-first/discovery-second/fail-closed, calls `DocumentService::generateDocument(templateId, dataRefs, options)` same-instance, stores the returned PDF on the `GeneratedDocument` record via OpenRegister's `FileService`, and degrades to `skipped-no-docudesk` — the loonstrook is one more `documentType` through that pipe, not a second engine. The draft's other halves have also since landed or moved: the `Loonstrook` data record exists as the richer multi-jurisdiction `Payslip` schema (hr-objects.json v0.2.0), and the werknemer-portaal capability shipped as `mijn-hr-self-service` (`MijnLoonstroken` `@me` page). What the draft still owns — and what this change delivers — is the *document*: a downloadable PDF per payslip and the annual jaaropgaaf.

The market signal is the strongest in hrmq's Spectr corpus: canon `hrmq-canon-payslip-pdf` scores **7/9** competitors shipping payslip PDF generation (it is the definition of table stakes — an HR/payroll product that cannot hand an employee a loonstrook document is not usable for Dutch payroll at all), and `hrmq-canon-jaaropgaaf` scores 4/9 for the annual statement. The legal anchor is equally direct: BW 7:626 obliges the employer to provide a written or electronic loonstrook, and hrmq's own corpus already carries that obligation as `nl-loonstrook-verstrekken` — `machineCheckable: false`, because until now there was no in-system document to check. Today `Payslip.statementProvided` is a self-asserted boolean with no evidence behind it — exactly the `writtenContract` gap the docudesk leaf closed for contracts.

## What Changes

- **`GeneratedDocument` v0.1.0 → v0.2.0** (`lib/Settings/register.d/hr-documents.json`): `documentType` enum gains `loonstrook` + `jaaropgaaf` (append-only, non-breaking); new nullable `$ref` fields `payslipId` (Payslip — which wage statement a loonstrook renders) and `jaaropgaafId` (Jaaropgaaf — which annual aggregate a jaaropgaaf renders), both in-register targets per ADR-062 rule 7.
- **New `Jaaropgaaf` schema** (v0.1.0, same fragment — design.md D2 justifies the home): the per-employee-per-year annual-statement aggregate — `employeeId` ($ref Employee, required), `year` (required), and only totals honestly derivable from existing `Payslip` fields (`totalGrossPay`, `totalLoonheffing`, `totalNettoPay`, `totalZvwWithheld`, `totalPensionContribution`, `totalVakantiegeldReserved`, `payPeriodCount`), plus the statutory placeholders `verrekendeArbeidskorting` (nullable — no Payslip field carries arbeidskorting today; stays null until a payroll engine computes it) and `loonheffingennummer` (employer config snapshot).
- **`HrDocumentService` extended** (`lib/Service/HrDocumentService.php`): loonstrook rendering assembles `dataRefs = [Employee, Payslip]` (schema-name-keyed per the verified docudesk contract — templates read `Payslip.grossPay`); jaaropgaaf rendering first aggregates the year's Payslips into a `Jaaropgaaf` object (idempotent per employee+year: update the existing object, never duplicate), then renders `dataRefs = [Employee, Jaaropgaaf]`. Idempotency keys extend to per-`payslipId` (loonstrook) and per-`jaaropgaafId` (jaaropgaaf) — design.md D4. The `adHocData.employer` block gains `loonheffingennummer` (new `SettingsService` getter, placeholder default).
- **occ command extended**: `hrmq:documents:generate --type loonstrook [--period YYYY-MM]` (backlog: every Payslip lacking an active loonstrook document) and `--type jaaropgaaf --year YYYY` (aggregate + render per employee with payslips in the year); `--period`/`--year` are type-scoped, misuse is a usage-error.
- **`DocumentController::generate()` extended** — the existing `POST /api/documents/generate` accepts an optional `payslipId` and guards it with `authorizePayslip()` (the same RBAC-scoped ObjectService resolution as the `authorizeContract` precedent); the endpoint stays singular with optional params — no new route (design.md D6).
- **New corpus rule `nl-loonstrook-verplicht`** (`lib/Standards/rules/payroll.json`, next to `nl-loonstrook-verstrekken`, severity `recommended`, machineCheckable): every Payslip SHOULD have a `generated` loonstrook `GeneratedDocument` on file — the evidence companion of the non-checkable `nl-loonstrook-verstrekken`. `NlDocumentChecks` gains the Payslip-keyed predicate; `RuleAuditService` extends the existing `documents` context index with `generatedLoonstrookByPayslip`; `RuleCatalogue::VERSION` bumps `2026-07.5` → `2026-07.6`.
- **Manifest**: `PayrollGroup` ("Loonadministratie") gains a `Jaaropgaven` index page + `JaaropgaafDetail`; `PayslipDetail` gains the api-call page action "Genereer PDF" (mirroring the `EmploymentContractDetail` action shape, `params: {payslipId: "@objectId", documentType: "loonstrook"}`); `GeneratedDocumentDetail` excludes the new `$ref` fields from its data widget (Related resolves them).
- **Seeds**: one `Jaaropgaaf` 2026 for `employee-jansen` arithmetically consistent with the seeded `payslip-jansen-2026-05`, and one `generated` loonstrook `GeneratedDocument` referencing that payslip (the new rule's green example).
- **Unit tests**: jaaropgaaf aggregation math (multi-payslip year, `zvwMode` filtering), payslip dataRefs assembly, both idempotency keys, and the usage/authorization guards — mocked container/services per the existing `HrDocumentServiceTest`.

### Non-goals

- **Payslip PDF layout standardisation beyond the docudesk template** — the NL-formaat sectioned layout (bruto/inhoudingen/netto/cumulatieven) is template authoring, and template authoring is docudesk's surface; hrmq pins only the variable contract (`Payslip.*`, `Employee.*`, `employer.*`).
- **Digital delivery / notification** — no NC notification, e-mail, or publish lifecycle on the document; the PDF lands on the `GeneratedDocument` object's files widget and the existing `MijnLoonstroken` self-service page reaches the payslip record.
- **Pre-engine payslip COMPUTATION** — payslips remain data records until a payroll-core engine lands; this change renders what exists (the draft's `depends_on: payroll-core-basic` gate dissolves because nothing here computes wages). Likewise `verrekendeArbeidskorting` stays a null statutory placeholder — never a fabricated number.
- **Year-to-date cumulatieven on the loonstrook** — the draft's `cumulatieven` block needs per-period running totals no Payslip field carries; a template can only show what the record holds. Follow-up once payroll-core owns cumulatives.
- **Bulk/background generation and the draft's per-document lifecycle** (`concept → … → gedownload`) — the `GeneratedDocument` status vocabulary (`pending/generated/failed/skipped-no-docudesk`) already covers the leaf's semantics.

## Capabilities

### New Capabilities

- `payslip-pdf-docudesk`: the payslip/jaaropgaaf document surface — `Jaaropgaaf` aggregate schema, loonstrook + jaaropgaaf rendering through the existing docudesk leaf, the `nl-loonstrook-verplicht` evidence rule, and the Jaaropgaven pages + PayslipDetail action.

### Modified Capabilities

- `hrmq-docudesk-documents`: `GeneratedDocument` enum + `$ref` extension (REQ-HDD-001), generalised dataRefs assembly (REQ-HDD-002), per-payslip/per-jaaropgaaf idempotency keys (REQ-HDD-006), occ `--type loonstrook|jaaropgaaf` + `--period`/`--year` (REQ-HDD-007), and the payslip variant of the guarded endpoint (REQ-HDD-008).

## Impact

- `lib/Settings/register.d/hr-documents.json` — `GeneratedDocument` v0.2.0 (enum + 2 nullable `$ref`s), NEW `Jaaropgaaf` schema, 2 seed objects.
- `lib/Service/HrDocumentService.php` — loonstrook/jaaropgaaf generation paths, jaaropgaaf aggregation, extended idempotency + backlog.
- `lib/Service/SettingsService.php` — `documents_employer_loonheffingennummer` getter; employer block extended.
- `lib/Command/DocumentsGenerateCommand.php` — `--type` values + `--period`/`--year` options and guards.
- `lib/Controller/DocumentController.php` — optional `payslipId` + `authorizePayslip()`; NO route change (`appinfo/routes.php` untouched).
- `lib/Standards/rules/payroll.json` — new rule `nl-loonstrook-verplicht`; `lib/Standards/RuleCatalogue.php` — VERSION `2026-07.6`.
- `lib/Standards/Checks/NlDocumentChecks.php` + `lib/Service/RuleAuditService.php` — Payslip predicate + `generatedLoonstrookByPayslip` context index.
- `src/manifest.json` — `Jaaropgaven` + `JaaropgaafDetail` pages, `PayrollGroup` menu child, `PayslipDetail` action, `GeneratedDocumentDetail` exclude update.
- `tests/Unit/Service/HrDocumentServiceTest.php` — aggregation/dataRefs/idempotency/guard tests.
- Cross-app dependency: unchanged — the same duck-typed docudesk contract (`DocumentService::generateDocument`, `TemplateService::getTemplatesByNamespace`) the leaf already consumes; no new docudesk surface.

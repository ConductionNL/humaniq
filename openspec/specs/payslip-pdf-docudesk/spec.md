---
capability: payslip-pdf-docudesk
status: done
built_by: openspec/changes/archive/2026-07-14-payslip-pdf-docudesk
---

# payslip-pdf-docudesk Specification

**Status**: done
**Scope**: humaniq (loonstrook + jaaropgaaf documents through the existing docudesk consumption leaf)
**Kind**: code (service aggregation/generation paths + command options + controller guard + check provider dominate; schema fields, one rule row, and manifest pages ride along — see the archived change's design.md "Mixed-spec rationale")

**OpenSpec changes**
- [payslip-pdf-docudesk](../../changes/archive/2026-07-14-payslip-pdf-docudesk/) _(archived 2026-07-14)_ — `Jaaropgaaf` aggregate schema + loonstrook/jaaropgaaf rendering through `HrDocumentService` (dataRefs `[Employee, Payslip]` / aggregate-then-render `[Employee, Jaaropgaaf]`, per-payslip and per-jaaropgaaf idempotency), occ `--type loonstrook|jaaropgaaf` with `--period`/`--year`, payslip variant of the guarded endpoint, evidence rule `nl-loonstrook-verplicht` (BW 7:626), Jaaropgaven pages + PayslipDetail action (kind: code; extends `humaniq-docudesk-documents`)

## Purpose

Give every `Payslip` a downloadable loonstrook PDF and every employee-year a jaaropgaaf PDF — rendered by docudesk from `namespace: hrmq` templates through the already-shipped `HrDocumentService` pipe (humaniq assembles data, docudesk renders — no Dompdf/Twig in humaniq, superseding the `spec/payslip-generation` draft's in-app engine), with an honest `Jaaropgaaf` aggregate derived only from real Payslip fields, and machine-checked BW 7:626 evidence via `nl-loonstrook-verplicht`.

## ADDED Requirements

@e2e exclude backend occ/service/controller change plus declarative manifest pages; humaniq has no app-level e2e suite yet (tracked by active change humaniq-test-coverage-baseline)

### Requirement: A `Jaaropgaaf` schema SHALL aggregate only what Payslip actually carries (REQ-PPD-001)

`lib/Settings/register.d/hr-documents.json` SHALL declare `Jaaropgaaf` v0.1.0 (`icon: FileCertificateOutline`, `x-schema-org: schema:Report`) — the per-employee-per-year annual-statement aggregate, required `[employeeId, year, totalGrossPay, totalLoonheffing]`: `employeeId` (string, uuid, `$ref` Employee), `year` (integer), `totalGrossPay` (Σ `Payslip.grossPay`), `totalLoonheffing` (Σ `Payslip.loonheffing`), `totalNettoPay` (Σ `nettoPay`), `totalZvwWithheld` (Σ `zvw` where `zvwMode == "inhouding"` ONLY — the employer levy `werkgeversheffing` is never a werknemersaandeel), `totalPensionContribution` (Σ `pensionContribution`), `totalVakantiegeldReserved` (Σ `vakantiegeldReserved`), `payPeriodCount` (count of aggregated payslips), `verrekendeArbeidskorting` (number, nullable — statutory line that stays `null` because no Payslip field carries arbeidskorting; never fabricated), `loonheffingennummer` (string, nullable — employer config snapshot), `aggregatedAt` (string, date-time, nullable). The draft's `totaalReiskosten` and per-document status lifecycle are NOT adopted (no source field / render state lives on `GeneratedDocument`).

#### Scenario: Fragment merges and validates
- **GIVEN** the hrmq register import
- **WHEN** the fragments under `lib/Settings/register.d/` are deep-merged and imported
- **THEN** the `Jaaropgaaf` schema exists in the hrmq register and `{employeeId: "<uuid>", year: 2026, totalGrossPay: 3800, totalLoonheffing: 1102}` validates

#### Scenario: No fabricated arbeidskorting
- **GIVEN** a `Jaaropgaaf` produced by the aggregation service
- **WHEN** its fields are inspected
- **THEN** `verrekendeArbeidskorting` is `null` (not `0.00`) — the value is unknown, not zero

### Requirement: Loonstrook rendering SHALL pass `[Employee, Payslip]` dataRefs through the existing leaf (REQ-PPD-002)

`HrDocumentService` SHALL generate a `documentType: loonstrook` document with `dataRefs = [{register: hrmq, schema: Employee, id}, {register: hrmq, schema: Payslip, id}]` (schema-name-keyed per the verified docudesk contract — templates read `Employee.*`, `Payslip.*`, `employer.*`), `contractId` null, and the payslip recorded on the new `GeneratedDocument.payslipId` `$ref`. The `adHocData.employer` block SHALL gain `loonheffingennummer` from a new `SettingsService` getter (`documents_employer_loonheffingennummer`, obvious placeholder default). Everything else — probe, template selection (`category === "loonstrook"` discovery), FileService storage (`loonstrook-{employeeNumber}-{YYYY-MM-DD}.pdf`), `skipped-no-docudesk` degradation — is the leaf's existing behaviour, unchanged.

#### Scenario: Payslip dataRefs assembly
- **GIVEN** an Employee with one Payslip
- **WHEN** the service assembles the loonstrook generation call
- **THEN** `dataRefs` contains exactly the Employee and Payslip refs (no EmploymentContract ref), `adHocData.employer.loonheffingennummer` carries the configured value, and no Payslip field values are copied into `adHocData`

#### Scenario: Record carries the payslip reference
- **WHEN** a loonstrook attempt is recorded
- **THEN** the `GeneratedDocument` has `documentType: "loonstrook"`, `payslipId` set to the payslip, and `contractId: null`

### Requirement: Jaaropgaaf generation SHALL aggregate first (idempotent per employee+year), then render `[Employee, Jaaropgaaf]` (REQ-PPD-003)

For `documentType: jaaropgaaf`, `HrDocumentService` SHALL first upsert the employee's `Jaaropgaaf` for the requested year — summing the Payslips whose `period` starts with `{year}-` per REQ-PPD-001's derivations, stamping `payPeriodCount`, `loonheffingennummer` (config snapshot) and `aggregatedAt` — updating the EXISTING (employeeId, year) object in place when one exists, never duplicating. Zero payslips in the year SHALL yield a `failed` outcome with a diagnostic and no aggregate write. It SHALL then render with `dataRefs = [{Employee}, {Jaaropgaaf}]` and record the aggregate on `GeneratedDocument.jaaropgaafId`.

#### Scenario: Aggregation math over a multi-payslip year
- **GIVEN** an employee with three 2026 Payslips (gross 3800/3800/4000; loonheffing 1102/1102/1180; one with `zvw: 70, zvwMode: "werkgeversheffing"`, one with `zvw: 65, zvwMode: "inhouding"`) and one 2025 Payslip
- **WHEN** the 2026 aggregation runs
- **THEN** the `Jaaropgaaf` has `totalGrossPay: 11600`, `totalLoonheffing: 3384`, `totalZvwWithheld: 65` (werkgeversheffing excluded), `payPeriodCount: 3`, and the 2025 payslip is not counted

#### Scenario: Re-aggregation updates, never duplicates
- **GIVEN** an existing `Jaaropgaaf` for (employee, 2026)
- **WHEN** the jaaropgaaf generation runs again after a new 2026 payslip landed
- **THEN** the SAME `Jaaropgaaf` object carries refreshed totals and `aggregatedAt`, and exactly one `Jaaropgaaf` exists for that (employee, year)

#### Scenario: Empty year fails closed
- **WHEN** a jaaropgaaf is requested for an employee with no payslips in the year
- **THEN** the outcome is `failed` with a diagnostic and no `Jaaropgaaf` object is written

### Requirement: A corpus rule SHALL demand loonstrook document evidence per payslip (REQ-PPD-004)

`lib/Standards/rules/payroll.json` SHALL gain `nl-loonstrook-verplicht` (domain `reporting`, jurisdiction `NL`, framework `nl-loonheffingen`, source BW 7:626, `sourceUrl` wetten.overheid.nl/BWBR0005290, severity `recommended`, `machineCheckable: true`), placed beside `nl-loonstrook-verstrekken` as its machine-checkable evidence companion (the obligation row itself stays `machineCheckable: false` — whether paper reached the employee is outside the system): every `Payslip` SHOULD have an active `GeneratedDocument` of type `loonstrook` in status `generated` referencing it via `payslipId`. `NlDocumentChecks` SHALL gain the `Payslip`-keyed pure predicate reading `context['documents']['generatedLoonstrookByPayslip']`; `RuleAuditService::buildDocumentsContext()` SHALL populate that index (payslipId → true) in the same pre-pass as the existing contract index; `RuleCatalogue::VERSION` SHALL bump on this change (`2026-07.10` → `2026-07.11` — versions bump one step from whatever is current at implementation time, since the catalogue moves with every intervening change).

#### Scenario: Undocumented payslip flagged
- **GIVEN** a Payslip with no `generated` loonstrook `GeneratedDocument`
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** an `nl-loonstrook-verplicht` violation (severity `recommended`) is reported for that payslip

#### Scenario: Documented payslip passes
- **GIVEN** the seeded `payslip-jansen-2026-05` with the seeded `generated` loonstrook `gendoc-loonstrook-jansen-2026-05`
- **WHEN** the audit runs
- **THEN** no `nl-loonstrook-verplicht` violation is reported for it and no existing rule regresses

### Requirement: Manifest SHALL expose Jaaropgaven under Loonadministratie and seeds SHALL ship consistent examples (REQ-PPD-005)

`src/manifest.json`: the `PayrollGroup` menu ("Loonadministratie") SHALL gain child `Jaaropgaven` (icon `FileCertificateOutline`); NEW pages `Jaaropgaven` (index: columns `employeeId`, `year`, `totalGrossPay`, `totalLoonheffing`, `payPeriodCount`; sort `year` desc) and `JaaropgaafDetail` (the EmploymentContractDetail two-panel shape: data widget excluding `employeeId` + related widget resolving the Employee; no lifecycleActions — `Jaaropgaaf` carries no lifecycle; no files widget — the PDF lives on the `GeneratedDocument`; audit sidebar tab). `GeneratedDocumentDetail`'s data widget SHALL additionally exclude `payslipId`/`jaaropgaafId` (Related resolves the new `$ref`s). `npm run check:manifest` MUST keep passing. `hr-documents.json` seeds SHALL gain: `jaaropgaaf-jansen-2026` (totals arithmetically consistent with the one seeded payslip: 3800.00 / 1102.00 / 2698.00, `payPeriodCount: 1`, `verrekendeArbeidskorting: null`, placeholder `loonheffingennummer`) and `gendoc-loonstrook-jansen-2026-05` (`documentType: loonstrook`, `payslipId: "payslip-jansen-2026-05"`, `status: generated`, placeholder templateRef/filePath) — existing seeds untouched.

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0 and the `Jaaropgaven` menu entry, both new pages, and the `PayslipDetail` action are present

#### Scenario: Seed consistency
- **WHEN** the register import runs twice
- **THEN** the seeded `Jaaropgaaf` and loonstrook document exist exactly once, and the jaaropgaaf totals equal the seeded payslip's grossPay/loonheffing/nettoPay with `payPeriodCount: 1`

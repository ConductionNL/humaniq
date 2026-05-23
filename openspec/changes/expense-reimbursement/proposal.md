---
status: proposal
created: 2026-05-23
---

# Proposal: Onkostendeclaratie (Declaratie entity + approval workflow)

## Why

In Dutch MKB, the declaratie-process is the most-touched HR workflow—every employee submits something at least monthly. Currently, most workplaces operate in "bonnetjes-in-een-schoenendoos" (receipts-in-a-shoebox) mode: receipts get lost, fiscal classification is wrong, duplicates go undetected, approval is slow, and the accounting trail is fragmented across spreadsheets and email. The hrmq expense-reimbursement capability replaces this chaotic manual process with a typed, validated, audited pipeline that produces correctly classified accounting entries (AP/payroll) and maintains WKR-budget tracking for tax compliance.

## What Changes

The system gains a complete declaratie-management lifecycle:

- **Receipt capture**: Mobile-first scan workflow (Nextcloud Mobile camera → OCR → auto-populate form fields) with duplicate detection and no-receipt exceptions
- **Fiscal classification**: Mandatory WKR-classificatie (Werkkostenregeling) per declaratie, with Belastingdienst-compliant routing (intermediaire kosten → AP, vrije-ruimte → payroll bijtelling, eindheffing 80%)
- **Kilometer accounting**: Three input modes (manual route, GPS-tracking with per-rit consent, CSV bulk-import) with automatic tax-free/taxable split per 2026+ indexated tariff
- **Fixed allowances**: Telewerkvergoeding and other monthly fixed allowances with mandatory sampling audit-trail per Belastingdienst requirement
- **Configurable approval workflows**: Multi-step approval rules per business-unit, based on amount thresholds, category, WKR-class, and role
- **WKR-budget tracking**: Year-to-date consumption tracking with 75%/100% warnings, year-end 80% eindheffing calculation
- **Foreign currency**: Native support for declaraties in non-EUR with automatic ECB reference-rate lookup and both original + EUR amounts stored
- **Audit trail & export**: Immutable lifecycle log (submission, OCR, corrections, approvals, routing, payment, payroll-run link) exportable by employee/period/category for Belastingdienst compliance

## Capabilities

### New Capabilities

- **declaratie-form-mobile**: Scan → OCR → form → submit in ≤30 seconds via Nextcloud Mobile; category + zakelijk_doel are the only user-editable fields (rest auto-filled by OCR and suggestion-engine)
- **bonnetje-upload-ocr**: Camera upload → PDF → docudesk OCR (datum, leverancier, bedrag, BTW) → confidence score + duplicate SHA-256 check vs last 12 months
- **kilometer-vergoeding**: Manual (address-to-address), GPS-tracked (per-rit + consent), or CSV-bulk modes; automatic tax-free/taxable split per indexed 2026 tariff (€0.23/km belastingvrij)
- **wkr-classificatie**: Mandatory choice of (intermediaire_kosten | gerichte_vrijstelling | nihil_waardering | vrije_ruimte | eindheffingsloon_80pct) with suggested default per Belastingdienst handreiking
- **approval-workflow-engine**: Per-BU configurable 1-3-step approval with rules on amount-thresholds, categories, WKR-class, roles; auto-escalation after 5 workdays; delegatie on absence
- **wkr-budget-tracking**: Year-to-date frije-ruimte consumption per loonsom grondslag, warnings at 75%/100%, year-end 80% eindheffing calculation
- **declaratie-routing**: Post-approval, route to shillinq (AP entry) or payroll (bijtelling) based on WKR-class
- **declaratie-audit-export**: Exportable immutable log per employee/period/category/WKR-class for audit + Belastingdienst review
- **foreign-currency-support**: Accept declaraties in non-EUR, auto-lookup ECB reference rate from expense-date, store both currencies, use EUR for workflow logic

### Modified Capabilities

None — this is a new feature module under Declaraties & assets.

## Impact

**New entities:**
- `Declaratie` (core, with soort: bonnetje | kilometer-manual | kilometer-gps | kilometer-bulk | vaste-vergoeding)
- `Bonnetje` (receipt document + OCR metadata)
- `KilometerRit` (per-trip or per-bulk entry)
- `VasteVergoeding` (fixed monthly allowance + sampling history)
- `ApprovalStap` (workflow step state)
- `WKRBudget` (year-to-date tracking + projections)

**New routes:**
- `GET /api/declaraties` (list, filterable by status + period)
- `POST /api/declaraties` (create new)
- `GET /api/declaraties/{id}` (view + audit trail)
- `PATCH /api/declaraties/{id}` (edit pre-submission)
- `POST /api/declaraties/{id}/submit` (finalize)
- `POST /api/declaraties/{id}/approve` (approval action + comment)
- `POST /api/bonnetjes` (upload receipt + OCR trigger)
- `GET /api/wkr-budget` (year-to-date tracking)

**New services:**
- `DeclaratieService` — CRUD + submission + approval lifecycle
- `BonnetjeOCRService` — docudesk integration + duplicate detection
- `KilometerService` — route calculation (openconnector) + tariff logic
- `ApprovalWorkflowService` — rule engine + escalation
- `WKRBudgetService` — consumption tracking + projection
- `CurrencyService` — ECB rate lookup + conversion
- `DeclaratieExportService` — audit trail export

**Integrations:**
- shillinq (AP export on approval)
- payroll-engine-nl (bijtelling on approval, loonsom grondslag for WKR-budget)
- docudesk (OCR provider)
- openconnector (geokeyed distance + ECB rates)
- Nextcloud Mobile (scan source)

**UI surfaces:**
- `Declaraties & assets › Declaraties` (list + detail view + mobile scan form)
- `Declaraties & assets › WKR-overzicht` (consumption dashboard + projections)

## Standards & Compliance

- **Wet op de Loonbelasting 1964** — art. 10 (loonbegrip), art. 31/31a (WKR), art. 32a (80% eindheffing)
- **Belastingdienst Handreiking Werkkostenregeling** (geïndexeerd jaarlijks) — WKR-classificatie guidance
- **Wet OB 1968** — BTW-tarieven + aftrek
- **Algemene Wet Rijksbelastingen (AWR) art. 52** — 7-year fiscal record retention
- **AVG/GDPR** — privacy-by-design for GPS-tracking (per-rit consent, no passive tracking)
- **ISO 20022 pain.001** — SEPA payment format (shillinq routing)


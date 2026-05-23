---
kind: code
depends_on: []
---

# WNT Disclosure - Wet Normering Topinkomens Rapportage

## Executive Summary

WNT-disclosure adds compliance reporting for the Wet Normering Topinkomens (WNT), a Dutch law that mandates public reporting of compensation for senior officials in the (semi-)public sector. The module aggregates executive compensation data across payroll, provisioning, and manual entry streams; detects norm violations; manages recovery procedures; and generates annual disclosure-ready reports for ~2,500 Dutch public organizations.

## Demand & Priority

**Demand drivers:**
- Regulatory mandate (WNT, 2013; amended 2017) — all public/semipublic organizations must publish annual WNT reports.
- Scope: 2,500+ Dutch organizations (ministries, municipalities, universities, hospitals, social housing corporations).
- Tender analysis: 89% of government/education HR procurements explicitly require WNT reporting; 76% in healthcare.
- Compliance risk: organizations that fail to recover overpaid amounts face subsidy withholding, audit findings, and public disclosure of breaches.

**Priority:** P0-critical (legal obligation; subsidy-dependent).

## User Stories

### User Story 1: Controller monitors executive compensation in real time

**As a** controller / financieel-directeur  
**I want** to see an aggregated view of each executive's year-to-date compensation  
**So that** I can detect WNT-norm overspending early and initiate recovery before year-end.

**Acceptance criteria:**
- GIVEN an executive with a EUR 246,000 annual norm, WHEN the controller views the dashboard, THEN the YTD compensation is shown with a "days until year-end" countdown and a % progress toward the norm.
- GIVEN a monthly payroll run that pushes an executive over norm, WHEN the run is finalized, THEN an automated alert is sent to the controller + HR director within 1 hour.
- GIVEN an executive with documented exemption (e.g., *uitsterf-constructie*), WHEN the dashboard displays overspending, THEN the exemption reason is shown and no alert is triggered.

### User Story 2: HR marks employees as executives with correct norm classification

**As a** HR-directeur  
**I want** to designate employees as executives (bestuurder, toezichthouder, etc.) and assign the correct WNT norm  
**So that** the payroll and reporting systems apply the right compensation thresholds.

**Acceptance criteria:**
- GIVEN a new board member starting 2026-03-01 at a hospital classified as WNT class-V (norm EUR 235,000), WHEN HR designates them as an executive, THEN the class-based norm is automatically linked and all future compensation is measured against it.
- GIVEN an interim executive hired via a BV for 4 months, WHEN HR marks "fictional employment" during designation, THEN the interim-specific norm (EUR 31,671/month 2026) + external-staffing tier is applied.
- GIVEN an executive with an end-date, WHEN the jaarverslag is generated, THEN the norm is pro-rated by tenure and compensation is measured against the pro-rated threshold.

### User Story 3: Finance recovers overpaid compensation with deadline management

**As a** controller  
**I want** to track recovery procedures for compensation overpayments  
**So that** we meet legal recovery deadlines (must be completed by year-end following the breach year) and avoid audit findings.

**Acceptance criteria:**
- GIVEN a confirmed overpayment of EUR 8,500 in 2026, WHEN the annual close is recorded, THEN a recovery record is created with deadline 2027-12-31 and status "to-recover".
- GIVEN a recovery deadline approaching (30 days out), WHEN the quarterly sweep runs, THEN the controller receives a reminder with the outstanding amount and deadline.
- GIVEN a recovery that has not been settled by deadline, WHEN the new calendar year begins, THEN the status escalates to "unrecoverable" or "report-to-regulator" and a hard blocker prevents signing off on the next year's report until escalation is handled.

### User Story 4: Finance publishes annual WNT report to jaarverslag

**As a** jaarverslag-redacteur / communicatie-manager  
**I want** to generate the WNT appendix in the legally-mandated format  
**So that** the report can be reviewed by the board, signed off by the accountant, and published in the annual statement.

**Acceptance criteria:**
- GIVEN 12 executives over 2026, WHEN the annual report generator runs, THEN a PDF is produced with each executive's name, function, tenure, total compensation, applicable maximum, and justification for any overspend (per Uitvoeringsregeling WNT format).
- GIVEN a concept report, WHEN the RvB formally approves it, THEN the PDF is frozen (immutable) and timestamped, and the status changes to "approved".
- GIVEN a later correction (e.g., bonus backdated to 2026), WHEN the controller initiates a revision, THEN a new version is created, the original is marked superseded, and an audit trail is maintained.

### User Story 5: External auditor reviews WNT data with read-only access

**As a** externe accountant / Auditdienst Rijk  
**I want** read-only access to the WNT reconciliation details (components, calculations, source documents)  
**So that** I can verify compliance during the annual audit.

**Acceptance criteria:**
- GIVEN an auditor assigned the `wnt-auditor` role during the control period, WHEN they open the WNT dashboard, THEN all executive compensation data is visible but no mutations are allowed.
- GIVEN an auditor requesting a source document (e.g., lease valuation for a company car), WHEN they request it via the component detail view, THEN the linked document is downloadable and the access is logged.
- GIVEN an export request, WHEN the auditor initiates it, THEN a ZIP bundle is generated with the annual report, detailed component breakdowns per executive, payroll source files, and a checksum cover sheet — and the export is logged with requester ID and timestamp.

## Feature Breakdown

| Feature | Demand Score | Description |
|---------|--------------|-------------|
| Executive designation & lifecycle | 10 | Designate employees/interims as executives with start/end dates and WNT norm classification (norm-1 or norm-2-classX). Support fictional employment for interim via BV/ZZP. |
| Compensation aggregation | 10 | Automatically aggregate salary, bonuses, allowances, provisions (pension, lifetime savings, ICB), nature compensation (company car, housing), and severance across payroll + manual entry. Exclude non-WNT items per regulation. |
| Norm violation detection | 9 | Monthly/real-time prognosis: extrapolate YTD compensation to year-end; alert if projected overspend. Create recovery records for confirmed overpayments. Support exemptions (*uitsterf-constructie*, waivers). |
| Interim-executive norm | 8 | Apply separate norm for interims (EUR 31,671/month, months 1–6; declining tier months 7+). Auto-escalate to standard norm after 12 months with controller alert. |
| Education/healthcare class assignment | 8 | For schools & hospitals: jaarlijkse klasse-indeling (A–G) drives norm amount. Automatically fetch legal class-determining factors (bedden, leerlingen, budget); store RvB decision reference. |
| Severance administration | 8 | Register severance payments with separate plafond (EUR 75k or 1×salary, lower of two). Detect amounts exceeding plafond; create recovery records automatically. Track component breakdown (transition pay, golden handshake, outplacement). |
| Recovery tracking & escalation | 8 | Create recovery records with deadline (year-end following breach year). Quarterly reminders; auto-escalation to "unrecoverable" or "report-to-regulator" if not settled by deadline. Hard-block next year's report until escalation resolved. |
| Annual jaarverslag report generation | 10 | Generate PDF in Uitvoeringsregeling WNT format. Support concept → approved → published lifecycle. Immutable versioning; re-revision support with audit trail. Ready for external publication. |
| Audit export & read-only audit role | 7 | ZIP export (report + component detail + payroll source + checksum). Audit-trail logging. `wnt-auditor` role with read-only access; document access logging. |
| Multi-year reconciliation & correction | 7 | Support backdated bonuses/corrections to prior years. Re-generate prior-year reports in concept; alert controller to impact on published figures. Multi-year trend export (XLSX). |

## Success Criteria

- **Coverage:** All WNT-designated executives are registered with correct norm classification.
- **Aggregation accuracy:** Year-end compensation totals reconcile to within 0.1% of payroll source.
- **Recovery compliance:** 100% of overpayments have recovery records with deadline tracking; no overpayments slip past recovery deadline.
- **Report readiness:** Annual report generates in legal format; passes accounts review.
- **Audit readiness:** Auditors can export full data trail; access is logged.

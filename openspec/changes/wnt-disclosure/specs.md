# WNT Disclosure - Technical Specifications

## Overview

The WNT-disclosure module provides three primary surfaces:
1. **Executive Registry** — designate employees/interims as WNT-regulated executives with norm classification.
2. **Compensation Aggregation & Monitoring** — real-time year-to-date compensation tracking; monthly prognosis alerts.
3. **Annual Reporting** — generate jaarverslag-ready WNT appendix; manage recovery procedures; audit exports.

All features are scoped by organisation_id (multi-tenant).

---

## REQ-001: Executive Designation & Lifecycle

**Module:** Executive Registry  
**Scope:** HR-directeur, Controller

The system MUST support designating employees or external interims as WNT-regulated executives, with tenure tracking and automatic norm application.

### REQ-001-A: Register a new executive with norm classification

- GIVEN a new bestuurslid joining a hospital (organisation_id=X) on 2026-03-01, classified as WNT klasse-V (norm EUR 235,000 in 2026),
- WHEN HR initiates the designation via `POST /wnt/executives` with `{ employee_id, functie_naam="Bestuurslid", aanvangsdatum="2026-03-01", wnt_norm_toepasselijk="norm_2_klasse_V", ... }`,
- THEN a `wnt_topfunctionaris_aanwijzing` record is created with the provided norm, all subsequent payroll components for this employee in 2026 are tagged for WNT aggregation, and the compensation monitoring for 2026 begins.

### REQ-001-B: Support partial-year tenure with pro-rata norm

- GIVEN an executive with `aanvangsdatum="2026-04-01"` and `einddatum="2026-09-30"` (9 months),
- WHEN the annual report for 2026 is generated,
- THEN the `wnt_norm_bedrag_pro_rata` is calculated as `(9 / 12) * wnt_norm_bedrag`, and compensation is measured against the pro-rata norm.

### REQ-001-C: Support fictional employment (interim via BV/ZZP)

- GIVEN an interim-bestuurder hired via BV for 4 months (2026-04-01 to 2026-07-31) with `fictieve_dienstbetrekking=true`,
- WHEN HR designates with `bezoldigings_grondslag="extern_via_bv"`,
- THEN the system applies the interim-specific monthly norm (EUR 31,671 in 2026, months 1–6) and the external-staffing tier for months 7+ (per Bijlage 2 WNT 2026).

### REQ-001-D: Escalate interim norm after 12 months

- GIVEN an interim-bestuurder with `aanvangsdatum="2025-06-01"` who reaches month 13 on 2026-06-30,
- WHEN the compensation aggregation processes the executive for July 2026 onwards,
- THEN the system automatically transitions from interim-specific norm to the standard year-norm (norm-1 or norm-2-classX) and sends an alert to the controller: "Interim [name] has served 12+ months; norm reverted to standard classification."

### REQ-001-E: Support exemptions (*uitsterf-constructie* and waivers)

- GIVEN an executive with `uitsterf_constructie_vlag=true` (exemption under wettelijk-overgangsrecht, i.e., was above WNT-norm on 2013-01-01),
- WHEN compensation aggregation detects overspending,
- THEN no alert is triggered, the overspending is recorded with reden_overschrijding_mogelijk_toegestaan="uitsterf_constructie", and the report notes the exemption.

---

## REQ-002: Compensation Aggregation & WNT-Specific Calculations

**Module:** Compensation Aggregation Engine  
**Scope:** Payroll-administrateur, Controller

The system MUST aggregate compensation from payroll, provisioning, and manual entry streams, apply WNT-specific exclusions, and compute accurate annual totals.

### REQ-002-A: Aggregate salary, bonuses, and provisions

- GIVEN an executive with:
  - Bruto jaarsalaris: EUR 180,000 (from payroll 2026-12)
  - IKB: EUR 28,800 (from payroll 2026-12)
  - Werkgever-pensioenpremie: EUR 22,000 (from voorzieningen-administratie)
  - Natura lease-auto: EUR 9,500 (manual entry, valued by lease-administratie)
- WHEN the annual aggregation runs,
- THEN `totaal_bezoldiging_wnt = 180,000 + 28,800 + 22,000 + 9,500 = EUR 240,300`.

### REQ-002-B: Exclude non-WNT compensation per Uitvoeringsregeling

- GIVEN an executive with:
  - Reiskosten woon-werk: EUR 1,500 (taxable allowance per Uitvoeringsregeling Art. 2.1 exclusion)
  - Bijdrage kinderopvang: EUR 600 (excluded per Art. 2.2)
- WHEN the aggregation processes these components with `wnt_meetelt_vlag=false`,
- THEN these amounts are stored for reference (`totaal_bezoldiging_fiscaal = 242,400`) but NOT included in `totaal_bezoldiging_wnt` (remains 240,300).

### REQ-002-C: Recognize backdated bonuses to the correct calendar year

- GIVEN a bonus of EUR 30,000 paid in December 2026 but marked `betreft_jaar="2025"`,
- WHEN the bonus component is recorded with `kalenderjaar=2025`,
- THEN it is added to the 2025 aggregation (triggering a 2025 report re-generation in concept status), not 2026.

### REQ-002-D: Support multi-component bonuses and gratifications

- GIVEN an executive with:
  - End-of-year bonus: EUR 15,000 (January 2027 payout)
  - Performance gratification: EUR 8,000 (June 2026 payout)
- WHEN components are recorded with correct `kalenderjaar` and `component_type`,
- THEN both are aggregated into `totaal_bezoldiging_wnt` for the respective years.

### REQ-002-E: Validate payroll-engine source integration

- GIVEN a payroll run finalised on 2026-12-20 with run_id="run-2026-12",
- WHEN the aggregation pulls components with `bron_administratie="payroll_engine_nl"` and `bron_id="run-2026-12"`,
- THEN the components are fetched from the payroll-engine-nl service (via integration); if the run is locked, components are considered final; if the run is draft, components are marked provisional and re-fetched on next aggregation.

---

## REQ-003: Norm Violation Detection & Real-Time Alerting

**Module:** Overspend Monitor  
**Scope:** Controller, HR-directeur

The system MUST detect norm violations monthly and via real-time payroll events, triggering alerts with prognosis and recovery workflow.

### REQ-003-A: Monthly prognosis alert for projected overspend

- GIVEN an executive with annual norm EUR 246,000 and YTD compensation (end-of-June 2026) of EUR 130,000,
- WHEN the monthly aggregation runs on 2026-07-01,
- THEN the system calculates projected year-end as `(EUR 130,000 / 6) * 12 = EUR 260,000` and sends an alert to the controller: "WNT Prognosis Alert: [Executive Name] is on track to exceed the EUR 246,000 limit. Projected year-end compensation: EUR 260,000."

### REQ-003-B: Real-time alert on one-off overspend event

- GIVEN an executive with norm EUR 246,000 who receives a one-time EUR 40,000 special payment in October 2026,
- WHEN the payment is booked to payroll and synced to WNT aggregation,
- THEN within 1 hour, an alert is sent to the controller + HR-directeur with subject "WNT Norm Exceeded: Immediate Recovery Required for [Executive Name]"; simultaneously, a recovery record is created in status `te_vorderen`.

### REQ-003-C: Suppress alerts for exempt executives

- GIVEN an executive with `uitsterf_constructie_vlag=true`,
- WHEN overspending is detected,
- THEN no alert is sent; instead, the overspending is logged with reden="uitsterf_constructie" for audit trail visibility.

### REQ-003-D: Track recovery deadline (year-end following breach year)

- GIVEN a confirmed overspend of EUR 8,500 in kalenderjaar 2026,
- WHEN the annual close is recorded on 2026-12-31,
- THEN a `wnt_jaar_rapportage` record is created with `terugvordering_vereist_vlag=true`, `terugvordering_bedrag=EUR 8,500`, `terugvordering_status="te_vorderen"`, and an implicit deadline of 2027-12-31 (year-end of the following year).

### REQ-003-E: Quarterly escalation reminder for outstanding recovery

- GIVEN a recovery record with deadline 2027-12-31 that remains unpaid as of 2027-09-01,
- WHEN the quarterly escalation cron runs,
- THEN the controller receives an email: "Recovery Reminder: EUR [amount] must be recovered from [Executive Name] by 2027-12-31 to avoid regulatory escalation."

### REQ-003-F: Auto-escalate past-deadline recovery to unrecoverable status

- GIVEN a recovery record with deadline 2027-12-31 that has not been settled by 2028-01-01,
- WHEN the year-boundary cron runs,
- THEN the status is automatically changed to either `oninbaar` (if documented as uncollectible) or `te_melden_aan_toezicht` (if must be reported to regulator), AND a hard blocker is placed on the next year's WNT report (2027 report cannot be signed off until the escalated recovery is handled).

---

## REQ-004: Interim-Executive & Fictional Employment Norm

**Module:** Interim Norm Engine  
**Scope:** HR-directeur, Payroll-administrateur

The system MUST correctly apply the special interim-bestuurder norm (EUR 31,671/month months 1–6, declining tier months 7+) for executives hired via BV or as ZZP.

### REQ-004-A: Calculate interim monthly norm and detect monthly overspend

- GIVEN an interim-bestuurder hired for EUR 35,000/month via BV (2026-04-01 to 2026-07-31, 4 months),
- WHEN the monthly overspend is calculated (EUR 35,000 - EUR 31,671 = EUR 3,329/month),
- THEN a recovery record is created PER MONTH (4 × EUR 3,329 = EUR 13,316 total) and the controller is alerted to the monthly overspends.

### REQ-004-B: Apply declining tier for months 7+ of interim service

- GIVEN an interim-bestuurder continuously employed from 2025-08-01 through 2026-07-31 (13 months total),
- WHEN the compensation for months 7–12 (2026-02 to 2026-07) is processed,
- THEN the external-staffing tier norm from Bijlage 2 WNT is applied (typically a stepped reduction: month 7 = EUR 28,500, month 8 = EUR 26,000, etc., per 2026 WNT rates).

### REQ-004-C: Automatic transition to standard norm after 12 months

- GIVEN an interim-bestuurder with `aanvangsdatum="2025-06-01"` and no `einddatum` set,
- WHEN the compensation aggregation processes 2026-07 (month 13 of service),
- THEN the system automatically reverts to the standard WNT-norm for the organisation (norm-1 or norm-2-classX) and notifies the controller: "[Interim Name] has served 12+ months. Norm classification has been updated to [new classification]. Please review budget impact."

---

## REQ-005: Klasse-Indeling for Education & Healthcare

**Module:** Class Assignment Registry  
**Scope:** HR-directeur, Controller, RvB

The system MUST support annual class assignment for education and healthcare organisations, automatically driving the WNT norm.

### REQ-005-A: Register class assignment with legal factors

- GIVEN a hospital (org_id=X) with opbrengsten EUR 142M, 240 bedden, kategorie academisch_ziekenhuis,
- WHEN HR (or the system via automated trigger on 2026-01-01) creates a `wnt_klasse_indeling` record for 2026,
- THEN the system applies the Regeling indeling WNT-zorg logic: based on the input factors, the klasse is determined (e.g., klasse V), the associated norm (EUR 235,000) is linked, and `wnt_topfunctionaris_aanwijzing` records for that organisation automatically inherit the norm for 2026.

### REQ-005-B: Store RvB decision reference

- GIVEN an RvB decision on 2025-12-10 (document reference "RvB-besluit 2025-12-10, nr. 42-2025") formally approving the class assignment,
- WHEN the decision is uploaded or referenced in the class-indeling form,
- THEN the system stores `indeling_vastgesteld_door` and `indeling_datum` and marks the record as `definitief` (immutable from that point).

### REQ-005-C: Support mid-year class revision (with effect next calendar year)

- GIVEN an education institution that undergoes a merger on 2026-08-01, doubling leerling-aantal,
- WHEN HR updates the klasse-bepalende-factoren with the post-merger numbers,
- THEN the system flags the change for next-year review (no change to 2026 norm) and generates a proposal for the 2027 klasse-indeling with the updated factors.

### REQ-005-D: Publish norm amounts on 2025-11-01 (annual BZK update)

- GIVEN the Ministry of BZK annual publication of WNT-norms for the next calendar year (expected 2025-11-01),
- WHEN the admin or system refreshes the norm-bedrag table,
- THEN the system updates all `wnt_klasse_indeling` records for the next year with the new norm amounts and flags any executives whose norms have changed for controller review.

---

## REQ-006: Severance Payfond & Separate Plafond

**Module:** Severance Recovery Engine  
**Scope:** HR-directeur, Controller

The system MUST track severance payments with a separate plafond (EUR 75,000 or 1×salary, whichever is lower) and automatically detect amounts exceeding the plafond.

### REQ-006-A: Calculate severance plafond and detect overage

- GIVEN an executive with jaarsalaris EUR 180,000 who receives a severance payout of EUR 90,000 on 2026-06-30,
- WHEN the severance is recorded,
- THEN the system calculates `wnt_plafond_bedrag = min(EUR 75,000, EUR 180,000) = EUR 75,000`, sets `bedrag_binnen_plafond = EUR 75,000`, `bedrag_boven_plafond = EUR 15,000`, and creates a recovery record for the EUR 15,000 overage.

### REQ-006-B: Apply lower-of-two logic

- GIVEN an executive with jaarsalaris EUR 50,000 who receives EUR 60,000 severance,
- WHEN the severance is recorded,
- THEN `wnt_plafond_bedrag = min(EUR 75,000, EUR 50,000) = EUR 50,000`, bedrag_boven_plafond = EUR 10,000 (recoverable).

### REQ-006-C: Track component breakdown within severance

- GIVEN a severance payout with components:
  - Transitievergoeding (wettelijk): EUR 30,000
  - Gouden handdruk: EUR 45,000
  - Outplacement: EUR 8,000
  - Juridische bijstand: EUR 7,000
  - **Total:** EUR 90,000
- WHEN the severance record is created,
- THEN the `samenstelling_json` field stores the component breakdown; the system allocates the first EUR 75,000 across components (pro-rata or per-component logic TBD by HR) and flags the remaining EUR 15,000 as recoverable with component-level detail visible to auditors.

### REQ-006-D: Validate severance triggers against employee record

- GIVEN a severance record with `reden_uitkering = "overlijden"`,
- WHEN the controller records it,
- THEN the system validates that the employee record reflects the termination reason and flags mismatches (e.g., employee still marked active) for HR review.

---

## REQ-007: Annual Jaarverslag Report Generation

**Module:** Report Generator & Publication Manager  
**Scope:** Controller, Jaarverslag-redacteur, RvB

The system MUST generate the annual WNT appendix in the legal format, support approval workflows, and maintain immutable versions.

### REQ-007-A: Generate PDF in Uitvoeringsregeling WNT format

- GIVEN an organisation with 12 WNT executives in 2026,
- WHEN the report generator is triggered (typically Q1 2027),
- THEN a PDF is produced containing:
  - Executive summary (org name, reporting period, number of executives)
  - Per-executive table with: name, function, tenure (start/end dates), deeltijdfactor, totaal-bezoldiging, individually-applicable-maximum, reden-overschrijding (if any), amount-recovery-required (if any)
  - Notes section for exemptions (*uitsterf*, waivers)
  - Auditor sign-off block (if applicable)
  - Conforming to the latest Modeltekst WNT-verantwoording (BZK) format

### REQ-007-B: Manage concept → approved → published lifecycle

- GIVEN a generated report in `publicatie_status = "concept"`,
- WHEN the RvB formally votes to approve the report (via a board-decision button or meeting record),
- THEN the status changes to `door_rvb_vastgesteld`, the PDF is frozen (document_id is locked, no further mutations allowed), and a timestamp is recorded.

### REQ-007-C: Support post-approval revisions (versioning)

- GIVEN a report in status `gepubliceerd_jaarverslag` that must be revised due to a discovered error,
- WHEN the controller initiates a revision,
- THEN a new `versie_nummer` is created (e.g., 2, 3, ...), a fresh PDF is generated, the old version is marked with `vervangen_door_versie_id`, and both versions remain accessible for audit.

### REQ-007-D: Track document immutability and audit trail

- GIVEN a frozen (approved) report,
- WHEN an attempt is made to edit an underlying compensation component (e.g., correct a payroll entry),
- THEN the system detects that the year's report is locked and either:
  - Prevents the edit (hard block), requiring a formal revision to the report, OR
  - Allows the edit but automatically generates a new report version with updated figures and notifies the controller of the impact.

### REQ-007-E: Publish URL and SBR integration

- GIVEN an approved report in status `gepubliceerd_jaarverslag`,
- WHEN the organisation publishes the jaarverslag on its website,
- THEN the report record can store a `publicatie_url` (e.g., https://example.org/jaarverslag-2026/wnt-appendix), and the document is also exported in SBR-Taxonomie XBRL format for submission to Belastingdienst / KvK.

---

## REQ-008: Audit Export & Auditor Access

**Module:** Audit Gateway  
**Scope:** Controller, Externe accountant, Auditdienst Rijk

The system MUST support read-only audit access, detailed export, and per-action audit logging.

### REQ-008-A: Auditor role and read-only dashboard

- GIVEN an external accountant assigned the `wnt-auditor` role during the control period (e.g., 2027-02 through 2027-05),
- WHEN the auditor logs in and navigates to the WNT module,
- THEN all executive compensation data (designations, components, aggregated totals, recovery status) is visible in read-only mode; no mutations (create, edit, delete) are allowed.

### REQ-008-B: Source document linkage and access

- GIVEN an auditor viewing a natura-component (e.g., company-car valued at EUR 9,500),
- WHEN the auditor clicks "View source document",
- THEN the system retrieves the linked document from the document-store (e.g., lease-overeenkomst PDF) and presents it for download; the access is logged as a read event in `wnt-access-audit`.

### REQ-008-C: Comprehensive export bundle

- GIVEN an auditor requesting an export for kontrolejaar 2026,
- WHEN the export is initiated via a secure endpoint (`POST /wnt/exports`),
- THEN a ZIP bundle is generated containing:
  - Annual WNT report (PDF, all versions if multiple exist)
  - Executive roster (CSV: name, function, norm, total-compensation, overspend-amount, recovery-status)
  - Compensation detail per executive (CSV: executive-name, component-type, amount, source, wnt-meetelt-vlag)
  - Payroll source manifests (CSV: run-id, run-date, employees-included, amount-total)
  - Checksum cover sheet (SHA-256 hashes of each file)
  - All wrapped with a timestamp and requester-id

### REQ-008-D: Export request audit logging

- GIVEN an auditor requesting the above export,
- WHEN the export is generated,
- THEN an entry is logged in `wnt-access-audit` with:
  - `requester_id` (auditor user-id)
  - `timestamp` (ISO 8601)
  - `action` ("export-requested")
  - `scope` ("full" or "executive-ids" if filtered)
  - `export_file_id` (link to the delivered ZIP bundle)

---

## REQ-009: Multi-Year Reconciliation & Correction

**Module:** Historical Corrections Engine  
**Scope:** Controller, Finance-directeur, Jaarverslag-redacteur

The system MUST support corrections to prior-year compensation, automatic re-aggregation of reports, and multi-year trend visibility.

### REQ-009-A: Backdate bonus to prior calendar year

- GIVEN a bonus of EUR 20,000 that was actually earned in 2025 but paid in March 2026,
- WHEN the payroll component is recorded with `kalenderjaar = 2025`,
- THEN the bonus is added to the 2025 aggregation (not 2026), and the system automatically regenerates the 2025 `wnt_jaar_rapportage` in concept status with the updated total.

### REQ-009-B: Regenerate prior-year report and alert controller

- GIVEN a 2025 report that was previously `gepubliceerd_jaarverslag`,
- WHEN a correction component is added to 2025 (as in REQ-009-A),
- THEN a new `wnt_publicatie_versie` (version 2) is created for 2025, the figures are recalculated, and the controller receives an alert: "2025 WNT Report has been revised. Previous: EUR [X]. Revised: EUR [Y]. Reason: [correction-reason]. Please review before re-publication."

### REQ-009-C: Support correction reason tracking

- GIVEN a correction to a prior-year component,
- WHEN the correction is recorded,
- THEN the system stores:
  - `correction_reason` (e.g., "Bonus disputed with payroll; settlement reached 2026-03-15")
  - `correction_date`
  - `corrected_by` (user-id)
  - Link to the original component and the corrected component

### REQ-009-D: Multi-year trend export

- GIVEN a controller requesting a trend analysis for executives over 5 years (2022–2026),
- WHEN the trend-export endpoint is called,
- THEN an XLSX file is produced with columns:
  - Executive name, function
  - Per year: total-compensation, applicable-norm, overspend-amount, recovery-status
  - A summary row per executive: trend (up/down/flat), average compensation, highest overspend year
  - A sheet per year for detailed component breakdown

---

## REQ-010: Toezicht & Compliancy Access

**Module:** Regulator Gateway  
**Scope:** Auditdienst Rijk, Inspectie-onderwijsewijzen, Inspectie-GJ

The system MUST support read-only access for regulatory bodies and structured export for compliance reporting.

### REQ-010-A: Auditdienst Rijk read-only role

- GIVEN an ADR auditor assigned the `wnt-auditor-adr` role,
- WHEN the auditor logs in,
- THEN read-only access to all WNT data is granted (same as external accountant, REQ-008-A), plus sector-specific dashboards (e.g., comparison of overspend rates across Rijksoverheid ZBO's).

### REQ-010-B: Regulator export with legal certification

- GIVEN a regulator body requesting an export for compliance verification,
- WHEN the export is initiated with appropriate credentials,
- THEN a signed ZIP bundle (per PKIX standards) is generated, containing the export from REQ-008-C plus:
  - Metadata: organisation-id, report-year, export-date, certifying-authority
  - Organisational integrity statement: "This export is an exact replica of the approved WNT report submitted for [year]"
  - Cryptographic signature (X.509 certificate, if applicable)

### REQ-010-C: Suppress sensitive data for non-executive-specific regulators

- GIVEN a request from the Inspectie van Onderwijs (education inspection),
- WHEN the inspection requests data for a specific education organisation,
- THEN the export includes aggregated WNT compliance metrics (number of overspends, total recovery amount, recovery status breakdown) but excludes individual executive names and compensation details (unless the inspection explicitly requests them via formal datum-verzoek).

---

## Integration Points

### Upstream dependencies

- **employee-master**: Provides employee records; WNT module marks employees as topfunctionarissen with designation.
- **payroll-engine-nl**: Provides salary, bonus, allowances, severance components per payroll run; includes `wnt-relevant` flag.
- **voorzieningen-administratie**: Supplies pension-premie and levensloop-premie per employee per year.
- **lease-auto-administratie** (optional): Provides natura-valuation for company cars.
- **huisvesting-administratie** (optional): Provides natura-valuation for housing.
- **inhuur-administratie** (optional): Provides interim-contract records for fictional-employment designation.
- **document-template-engine**: Renders PDF report in Uitvoeringsregeling WNT format.
- **document-store**: Stores and retrieves PDF reports and source documents (links).

### Downstream consumers

- **jaarverslag-generator**: Incorporates the WNT appendix as a section in the bestuursverslag.
- **terugvordering-administratie**: Consumes WNT recovery records as a specific recovery-type for collections workflows.
- **boekhouding-export**: Exports overspending amounts to a WNT-cost-centre for financial reporting.

### External integrations

- **SBR-Digipoort**: Exports annual report in XBRL-extension format for jaarverslag publication to Belastingdienst / KvK.
- **topinkomensregister.nl** (optional): Organisations may submit directly to BZK's central register.
- **Accountant software** (Audition, CaseWare): Export audit workpapers in standard interchange format.

---

## Non-Functional Requirements

- **Multi-tenancy:** All queries implicitly scope to `organisation_id`. No data leakage across organisations.
- **Audit trail:** All mutations (create, update, delete) log user-id, timestamp, and change-delta.
- **Performance:** Annual report generation (10-50 executives) completes within 5 seconds.
- **Data retention:** WNT reports retained for 7 years minimum (fiscal record-keeping requirement).
- **Regulatory alignment:** All report formats, field names, and calculations conform to the 2026 Uitvoeringsregeling WNT (updated annually as BZK publishes).

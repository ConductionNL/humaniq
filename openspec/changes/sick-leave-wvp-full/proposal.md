---
title: Sick Leave - Wet Verbetering Poortwachter Full 2-Year Cycle
change-id: sick-leave-wvp-full
status: draft
owner: Specter Intelligence
created: 2026-05-23
---

# Proposal: WVP Compliance Module

## Executive Summary

The Wet Verbetering Poortwachter (WVP) requires employers to follow a strict 104-week (two-year) reintegration trajectory for sick employees. Non-compliance costs EUR 35,000+ per case in loonsanctie penalties. This spec replaces `sick-leave-mvp` with a full WVP-compliant module that enforces every wettelijke termijn, segregates medical data per AVG Article 9, and produces every artifact the UWV requires.

**Impact:** Enables HRMQ to compete in overheids-tenders (73% require WVP functionality). Protects against EUR 100,000+ penalties per case for senior staff.

---

## Demand & Priority

**Priority:** P0-must  
**Demand score:** 9/10 (universal requirement across Dutch employers; non-compliance carries criminal penalties for gross negligence)  
**Dependency:** employee-master, payroll-engine-nl, document-template-engine, notification-engine, cao-engine  
**Placement type:** SUB_PAGE (Verlof & verzuim › WVP-dossiers)

### Market Evidence

- 73% of Dutch overheids-HR-aanbiedingen require WVP functionality
- Visma RAET, Youforce, AFAS all provide full WVP modules
- Missing WVP = excluded from Gemeente, Provincies, Waterschappen, Onderwijs, ZVW procurement

---

## Features

### F1: WVP-Case Lifecycle (Demand: 10/10)
Automatically open a new wvp-case when ziekmelding is registered. Reopen within the 4-weken-regel (BeZaVa) if the employee reports sick again within 28 days. Enforce case-status transitions (open → herstel → wia-aangevraagd → loonsanctie → overleden).

**Story:** As a casemanager, I want the system to automatically create a WVP-case when an employee reports sick, so I don't have to manually initiate the 104-week cycle.

### F2: Probleemanalyse Milestone – Week 6 Deadline (Demand: 9/10)
Track the bedrijfsarts' problem analysis due by end of week 6. Send escalation reminders at day 28 and day 35. Flag loonsanctie-risico if milestone is missed.

**Story:** As a casemanager, I want the system to remind the bedrijfsarts at day 28 and alert me if the analysis is not completed by week 6, so we avoid loonsanctie penalties.

### F3: Plan-van-Aanpak (PvA) – Week 8 Deadline (Demand: 10/10)
Bilateral document for reintegration goals and actions. Must be signed by both employer and employee within 2 weeks of probleemanalyse. If employee disagrees, trigger deskundigenoordeel-aanvraag to UWV.

**Story:** As an HR-partner, I want to create a PvA in the system, have the employee sign it via the self-service portal, and be alerted if signatures are missing by week 8.

### F4: CAO-Specific PvA Templates (Demand: 9/10)
Pre-fill PvA templates with CAO-specific clauses (re-integratiebudget, Vervangingsfonds, gesprekscadans). Validate custom templates against UWV-required fields.

**Story:** As a casemanager for a Gemeente employee, I want the PvA template to auto-populate Gemeenten-specific re-integratiebudget amounts and inzetbaarheidsgesprek frequency.

### F5: Eerstejaarsevaluatie – Week 46-52 (Demand: 10/10)
Formele evaluatie to decide: continue 1e spoor (own employer) or start 2e spoor (external reintegration bureau). Daily reminders until scheduled.

**Story:** As a casemanager, I want to schedule the year-1 evaluation around week 46-52 and record the joint decision (continue or refer to reintegration bureau).

### F6: Tweede-Spoor Trajectory (Demand: 9/10)
Open trajectory with a certified reintegration bureau (Blik op Werk) when 1e spoor is exhausted. Track quarterly voortgangsrapportage. Flag 2e-spoor-niet-bijgehouden-risico if reports are 91+ days overdue.

**Story:** As HR, I want to select a reintegration bureau, sign the contract, and automatically schedule quarterly progress reports so we stay compliant.

### F7: Eindevaluatie & RIV Export – Week 87-91 (Demand: 10/10)
Generate Re-integratieverslag (RIV) bundling all WVP artifacts (analyses, PvAs, evaluations, 2e-spoor reports) into one PDF-A with checksum. Employee must sign RIV by week 91 before WIA-claim.

**Story:** As HR, I want to export the complete RIV document at week 87, have the employee review and sign it, and submit to UWV by week 91.

### F8: Medical Data Segregation – AVG Article 9 (Demand: 10/10)
Physically separate medical records (bedrijfsarts dossier) from HR-visible data. Encrypt at rest with HSM keys. Log every read of medical records. Only bedrijfsarts, verzuimcoach, employee, and (with consent) UWV-verzekeringsarts can access.

**Story:** As a bedrijfsarts, I want to document problem analyses and medical findings in a segregated, encrypted space that HR and finance never see.

### F9: Loondoorbetaling Calculation – 70% of Last Loon (Demand: 10/10)
Compute 70% of refundable loon for both jaar 1 and jaar 2, with wettelijk minimum-loon as floor only in jaar 1. Apply CAO-specific suppleties (e.g., 100% jaar 1 / 90% jaar 2 voor Gemeenten).

**Story:** As a payroll administrator, I want the system to automatically calculate sick-leave pay at 70% with the legal minimum-wage floor in year 1, and apply CAO-agreed supplements.

### F10: UWV Poortwachterstoets & Loonsanctie (Demand: 9/10)
Receive UWV poortwachterstoets outcome after WIA-claim is submitted. If loonsanctie is imposed, automatically extend loondoorbetaling obligation and track the sanction period. Support sanction appeals.

**Story:** As HR, when the UWV notifies us of a poortwachterstoets denial with loonsanctie, I want the system to automatically adjust the payroll end-date and flag the case so we can file a bezwaarschrift if we believe we complied.

---

## User Stories & Acceptance Criteria

### Story US-001: Create WVP-Case on Ziekmelding

**GIVEN** an employee with no open WVP-case and no closed case in the last 28 days  
**WHEN** HR registers a ziekmelding with eerste-ziektedag = today  
**THEN** a new wvp-case MUST be created with status `open`, all 11 milestone-due-dates MUST be computed from eerste-ziektedag, and a system notification MUST alert the assigned casemanager

**GIVEN** a wvp-case that was closed (herstel) 14 days ago  
**WHEN** the same employee meldt zich opnieuw ziek  
**THEN** the existing case MUST be reopened, milestone-due-dates MUST remain anchored to the original eerste-ziektedag, and samenvoeging-4-weken-regel MUST be set to true

### Story US-002: Probleemanalyse Escalation

**GIVEN** a wvp-case at day 28 with no completed probleemanalyse  
**WHEN** the system runs the daily escalation job  
**THEN** a reminder email MUST be sent to the contracted bedrijfsarts-organisation and logged on the case

**GIVEN** a wvp-case at day 42 with no completed probleemanalyse  
**WHEN** the system detects the week-6 deadline has passed  
**THEN** the case MUST enter loonsanctie-risico status and the HR-notification MUST name the responsible bedrijfsarts

### Story US-003: PvA Bilateral Signing

**GIVEN** a PvA in concept-status with werkgever-signature only  
**WHEN** the werknemer accesses the self-service portal  
**THEN** the PvA MUST be shown for review with "akkoord en ondertekenen" and "niet akkoord, toelichting" buttons

**GIVEN** a werknemer clicks "niet akkoord"  
**WHEN** toelichting is submitted  
**THEN** a deskundigenoordeel-aanvraag template MUST be generated per Artikel 32 WIA

### Story US-004: Eerstejaarsevaluatie Scheduling

**GIVEN** a wvp-case at week 46 with no scheduled eerstejaarsevaluatie  
**WHEN** the casemanager logs in to the dashboard  
**THEN** a daily reminder MUST be shown until the evaluatie is scheduled or completed

**GIVEN** an eerstejaarsevaluatie completed with besluit `start-2e-spoor`  
**WHEN** the evaluatie is saved  
**THEN** a nieuwe tweede-spoor-traject entity MUST be created in concept-status

### Story US-005: RIV Export & Signing

**GIVEN** a wvp-case at week 87  
**WHEN** HR clicks "RIV samenstellen"  
**THEN** the system MUST bundle probleemanalyse, FML's, alle PvA-versies, evaluaties, and 2e-spoor-rapportages into one PDF-A with checksum-cover-blad

**GIVEN** the RIV PDF is generated and shared with the employee  
**WHEN** the employee signs via employee-portal by week 91  
**THEN** the signed RIV MUST be stored and flagged ready-for-UWV-submission

---

## Customer Journeys

### Journey: Casemanager 1-Year WVP Supervision Cycle

**Trigger:** Employee ziekmelding registered  
**Pain point:** Currently, no system enforces 104-week milestones; loonsanctie penalties are discovered at UWV poortwachterstoets (week 99+), too late to cure  
**Desired outcome:** System proactively alerts casemanager at each milestone, with clear gevolgen-bij-niet-naleven

**Timeline:**
- Week 0: System creates case, shows all 11 milestone due-dates on dashboard
- Week 1-6: Casemanager ensures bedrijfsarts submits probleemanalyse by day 42
- Week 6-8: Casemanager drafts PvA with employee, collects both signatures
- Week 46-52: Casemanager schedules eerstejaarsevaluatie, decides 1e or 2e spoor
- Week 52+: If 2e spoor, selects reintegration bureau, monitors quarterly reports
- Week 87: System alerts to begin eindevaluatie and RIV assembly
- Week 91: RIV submitted to UWV with WIA-claim

### Journey: Bedrijfsarts Medical Documentation

**Trigger:** Casemanager assigns employee to bedrijfsarts  
**Pain point:** Medical notes today mix with HR-visible case data; GDPR exposure if anyone can read them  
**Desired outcome:** Bedrijfsarts logs into segregated medical portal, records probleemanalyse and spreekuur-verslagen without ever seeing employee's salary or HR context

**Timeline:**
- Week 1: Bedrijfsarts logs in, sees only list of assigned employees and case-open-date
- Week 2-6: Bedrijfsarts uploads probleemanalyse, spreekuur-verslag, FML (Functionele Mogelijkheden Lijst)
- All uploads encrypted at rest; access-log written to audit
- Week 46-52: Bedrijfsarts consulted for year-1 evaluation opinion
- Week 87: Bedrijfsarts final opinion on work capacity for RIV

### Journey: Employee Self-Service Ziekmelding & RIV Signoff

**Trigger:** Employee becomes ill or designated by HR  
**Pain point:** Currently no self-service RIV review; employee may not understand what's being sent to UWV  
**Desired outcome:** Employee receives timely notifications, can review PvA and RIV, sign electronically, see own absence status

**Timeline:**
- Week 0: Employee notified of ziekmelding registered, can review case-summary (no medical data visible)
- Week 6-8: Employee receives notification to review and sign PvA
- Week 46-52: Employee invited to year-1 evaluation discussion
- Week 87-91: Employee notified of RIV draft, can review, sign, or raise opmerkingen
- Post-week 91: Employee can see WIA-claim status and UWV-comunicaties

---

## Stakeholder Profiles

### Primary: WVP-Casemanager
- **Role:** HR-business-partner with casuistiek-opleiding (RNVC-register)
- **Responsibility:** Manage 30-60 active WVP-cases simultaneously
- **Success metric:** All milestones met on time, zero loonsanctie penalties
- **Pain point:** Manual tracking of 11 milestones per case × 60 cases = 660 manual reminder tasks/year
- **Desired:** Dashboard showing next-due milestone per case, escalation alerts, RIV assembly in 1 click

### Secondary: Bedrijfsarts (External or In-house)
- **Role:** Licensed occupational physician conducting medical assessments
- **Responsibility:** Probleemanalyse week 6, spreekuurverslagen, FML, eindevaluatie opinion
- **Success metric:** Timely, evidence-based assessments that withstand UWV scrutiny
- **Pain point:** Fear of GDPR breach if HR-system exposes patient data; need for audit trail
- **Desired:** Segregated, encrypted medical portal with no HR-leakage

### Secondary: HR-Medewerker (Intake & Payroll)
- **Role:** Registers ziekmelding, runs payroll, archives closed cases
- **Responsibility:** Case lifecycle, document routing, loondoorbetaling-berekeningen
- **Success metric:** Accurate payroll, zero compliance audit findings
- **Pain point:** 70% loon calculation manual; CAO-specific suppletie-rules scattered in spreadsheets
- **Desired:** Automated loondoorbetaling per CAO, audit trail of all rate changes

### Secondary: Employee / Werknemer
- **Role:** Sick-leave recipient, PvA signer, RIV reviewer
- **Responsibility:** Engage in reintegration planning, attend evaluaties, sign RIV
- **Success metric:** Clear communication, timely outcomes, successful reintegration or fair WIA transition
- **Pain point:** Opacity of multi-week processes; fear of wage cuts or hidden decisions
- **Desired:** Self-service portal showing case status, PvA progress, RIV review, UWV communication

### Tertiary: Finance / Loonboekhouding
- **Role:** Month-end / year-end close, loondoorbetaling verification
- **Responsibility:** Validate loondoorbetaling-lines per case, reconcile with payroll export
- **Success metric:** Audit trail is complete, no loonsanctie clawbacks post-close
- **Pain point:** Currently must manually cross-check sick-leave duration against payroll entries
- **Desired:** Automated payroll-export with case-id reference, loondoorbetaling audit log

### Tertiary: Accountant (External auditor)
- **Role:** Compliance oversight for WVP-cycle adherence
- **Responsibility:** Read-only access to case timelines and milestone completion during audit
- **Success metric:** Can verify all 11 milestones were met on time, no evidence of shortcuts
- **Pain point:** Currently must request individual case files; no integrated report
- **Desired:** Export showing per-case milestone timeline, missing-deadline audit report

---

## Technical Constraints & Integration Touchpoints

**Data isolation:** Medical dossier-entries in separate schema with row-level-security policies, HSM-encrypted keys  
**Upstream deps:** employee-master (dienstverband, voltijdsfactor, loon), payroll-engine-nl (loondoorbetaling-lines), cao-engine (suppletie rules), document-template-engine (PvA, RIV, evaluatie templates), notification-engine (reminders & escalations)  
**External integrations:** UWV Werkgevers-Portal (42e-weeksmelding, RIV-indiening, poortwachterstoets-uitslag), Arbodiensten (probleemanalyse exchange via OAGI or HL7-FHIR), Blik op Werk-keurmerk validation for reintegration bureaus  
**Compliance:** AVG Artikel 9 (medical-data encryption & segregation), Belastingdienst bewaartermijn (24-month audit-log retention), UWV poortwachterstoets response workflow, wettelijke termijnen per BW Boek 7 & WVP

---

## Success Criteria

1. **Compliance:** 100% of milestone due-dates enforced; zero loonsanctie penalties due to missed deadlines
2. **Adoption:** Casemanagers using dashboard alerts in >80% of active cases within 6 months
3. **Data security:** Zero GDPR audit findings on medical-data segregation; all bedrijfsarts access logged
4. **UWV integration:** RIV PDF-A exports pass UWV validation; WIA-claims processed on first submission in >90% of cases
5. **CAO support:** All 7 major CAOs (Gemeenten, Rijk, Provincies, Waterschappen, PO, VO, VVT) configured with correct templates and suppletie-rules


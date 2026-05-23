---
title: WVP Module Specifications
change-id: sick-leave-wvp-full
status: draft
created: 2026-05-23
---

# Detailed Requirements (REQ-XXX-NNN)

---

## REQ-001: WVP-Case Lifecycle

### REQ-001-001: Create Case on Ziekmelding (No Prior 28-Day Case)

**GIVEN** an employee with no open wvp-case and no closed wvp-case in the last 28 days  
**WHEN** HR registers a ziekmelding via the REST API with `eerste-ziektedag = [date]`  
**THEN**
- A new wvp-case MUST be created with status = `open`
- `case-opening-date` = today (system date when ziekmelding is registered)
- All 11 wvp-milestone rows MUST be created with computed due-dates:
  - week-1-arbo-notification: eerste-ziektedag + 7 days
  - week-6-probleemanalyse: eerste-ziektedag + 42 days
  - week-8-pva: eerste-ziektedag + 56 days
  - week-42-uwv-melding: eerste-ziektedag + 294 days
  - week-46-to-52-eerstejaarsevaluatie: eerste-ziektedag + 322 to 364 days
  - week-52-opschudmoment: eerste-ziektedag + 364 days
  - week-68-tussenmaal-evaluatie-2e-spoor: eerste-ziektedag + 476 days
  - week-87-eindevaluatie: eerste-ziektedag + 609 days
  - week-91-wia-aanvraag-deadline: eerste-ziektedag + 637 days
  - week-104-einde-loondoorbetaling: eerste-ziektedag + 728 days
- bedrijfsarts-id MUST be populated from employee-master.bedrijfsarts-assignment
- casemanager-id MUST be populated from HR-context (user who is handling this ziekmelding)
- `cao-id` MUST be fetched from employee-master.cao-assignment
- A notification MUST be sent to the assigned bedrijfsarts: "Probleemanalyse due [date]"
- A notification MUST be sent to the casemanager: "WVP-case created for [employee-name], first milestone: [milestone-type] due [date]"

### REQ-001-002: Reopen Case Within 4-Weken-Regel

**GIVEN** a wvp-case that was closed with status = `herstel` 14 days ago  
**WHEN** the same employee meldt zich opnieuw ziek within 28 days of the prior case's actual-end-date  
**THEN**
- The existing (closed) case MUST be reopened with status = `open`
- `samenvoeging-4-weken-regel` MUST be set to true
- All milestone due-dates MUST remain anchored to the ORIGINAL eerste-ziektedag (not the new ziekmelding date)
- `percentage-arbeidsongeschikt` MUST be reset to 100% (until bedrijfsarts updates it)
- A notification MUST alert the casemanager: "Case reopened under 4-weken-regel; milestones continue from original schedule"

### REQ-001-003: Reject Case Close Without Medical Confirmation

**GIVEN** a wvp-case at week 53 or later  
**WHEN** HR attempts to close the case with status = `herstel` via UI/API  
**AND** there is no bedrijfsarts-spreekuurverslag in the re-integratie-dossier dated within 7 days before the requested close-date  
**THEN**
- The close operation MUST be rejected with error code `WVP-CLOSE-REQUIRES-MEDICAL-CONFIRMATION`
- HR MUST be presented with: "Final bedrijfsarts confirmation required. Last medical entry: [date]. Please request spreekuurverslag from bedrijfsarts before closing."

---

## REQ-002: Probleemanalyse Week-6 Deadline & Escalation

### REQ-002-001: Send Escalation Reminders at Day 28 and Day 35

**GIVEN** a wvp-case at calendar day 28 (since case-opening-date or eerste-ziektedag)  
**WHEN** the milestone `week-6-probleemanalyse` has status = `pending` (i.e., no completed-date yet)  
**THEN**
- A scheduled job (or manually-triggered job) MUST send an email to the contracted bedrijfsarts-organisation
- Email subject: "Probleemanalyse benodigd: [employee-name], vervaldatum [due-date]"
- Email body MUST include: case-id, employee-name, due-date, link to medical portal
- milestone.escalation-sent-at MUST be updated to the current timestamp

**GIVEN** a wvp-case at calendar day 35  
**WHEN** the milestone `week-6-probleemanalyse` still has no completed-date  
**THEN**
- A second escalation email MUST be sent to the bedrijfsarts-organisation
- Email subject: "URGENT: Probleemanalyse vervaldatum nakend — [employee-name]"
- A notification MUST also be sent to the casemanager: "Probleemanalyse at risk; escalation sent to bedrijfsarts"

### REQ-002-002: Flag Loonsanctie-Risico at Week-6 Deadline

**GIVEN** a wvp-case at calendar day 42 (week 6)  
**WHEN** the milestone `week-6-probleemanalyse` has status = `pending` (no completed-date)  
**THEN**
- case.case-status MUST be updated to `open` (no change to existing status, but flag is added)
- wvp-milestone.status MUST be updated from `pending` to `at-risk`
- An HR-notification MUST be generated and displayed on casemanager dashboard:
  - Title: "⚠️ Loonsanctie Risk: Probleemanalyse Not Received"
  - Body: "Deadline [due-date] passed. Missing probleemanalyse means UWV can impose loonsanctie (up to 52 weeks, ~EUR 35k for 50k salary). Contact bedrijfsarts: [name], organization: [org]."
- A system-note MUST be logged to the case: "Milestone week-6-probleemanalyse entered at-risk status per UWV poortwachtsregel"
- If casemanager does not acknowledge within 48 hours, an escalation to case-reviewer role MUST be triggered

### REQ-002-003: Validate Bedrijfsarts-Only Document Upload

**GIVEN** a wvp-case with milestone `week-6-probleemanalyse` pending  
**WHEN** a user without `bedrijfsarts` role attempts to upload a probleemanalyse document  
**THEN**
- The upload MUST be rejected with HTTP 403 Forbidden
- Error message: "Only users with bedrijfsarts role can upload probleemanalyse documents"
- A security-event MUST be logged: (user-id, timestamp, case-id, attempted-document-type, action: "UNAUTHORIZED_UPLOAD_ATTEMPT")
- If the same user attempts 5+ times in 24 hours, account MUST be flagged for review

---

## REQ-003: Plan-van-Aanpak Week-8 Deadline

### REQ-003-001: Enforce Bilateral Signing by Week 8

**GIVEN** a wvp-case with milestone `week-6-probleemanalyse` marked completed on day 30 (relative to eerste-ziektedag)  
**WHEN** day 44 (from eerste-ziektedag) passes without a PvA in status = `vastgesteld`  
**THEN**
- wvp-milestone (week-8-pva).status MUST be updated to `at-risk`
- casemanager MUST be notified:
  - Title: "⚠️ PvA Signature Deadline Approaching"
  - Body: "PvA must be signed by both employer and employee by [due-date]. Current status: [pva-status]. Link to PvA: [URL]."
- At day 56 (if still not vastgesteld): status escalates to HR-manager; second notification sent
- At day 57 (if still not vastgesteld): case enters `loonsanctie-risico`; final alert sent to casemanager with note: "WIA may deny reintegration funding if PvA is not in place by UWV-review."

### REQ-003-002: Employee Sign/Reject Path with PvA Portal

**GIVEN** a PvA in status = `werkgever-signed` (employer already signed)  
**WHEN** the werknemer logs into the self-service portal  
**THEN**
- The PvA MUST be displayed in a formatted, read-friendly layout (not raw JSON)
- Two action buttons MUST be presented: 
  1. "✅ Akkoord en ondertekenen" (agree and sign)
  2. "❌ Niet akkoord, toelichting" (disagree, provide reason)
- If employee clicks button 1 (Akkoord):
  - Employee MUST e-sign (or upload scanned signature) within the UI
  - werknemer-signed-on = current timestamp
  - pva-status MUST transition to `vastgesteld`
  - Casemanager MUST be notified: "PvA signed by employee; now in effect"
  - wvp-milestone (week-8-pva) MUST be marked completed
- If employee clicks button 2 (Niet akkoord):
  - Employee MUST enter free-text toelichting (reason for disagreement)
  - pva-status MUST transition to `werknemers-bezwaar`
  - A deskundigenoordeel-aanvraag template (per Artikel 32 WIA) MUST be auto-generated and sent to employee
  - Employee MUST be informed: "Your objection has been noted. Here is a template to request UWV mediation. You have [days] to file."
  - Casemanager MUST be alerted: "Employee objects to PvA; deskundigenoordeel process initiated"

### REQ-003-003: Generate Deskundigenoordeel-Aanvraag

**GIVEN** an employee who marked a PvA as `werknemers-bezwaar`  
**WHEN** the bezwaar is saved  
**THEN**
- A deskundigenoordeel-aanvraag document template (per UWV Artikel 32) MUST be generated
- Template MUST include:
  - Employee name, case-id, PvA summary
  - Space for employee to detail reason for objection
  - Instructions: "Submit this form to UWV within [deadline]. UWV will appoint an independent expert to review the reintegration plan."
  - UWV submission address & reference form
- Document MUST be emailed to employee with subject: "Aanvraag deskundigenoordeel — uw bezwaar tegen Plan van Aanpak"
- A copy MUST be logged to the case audit-trail
- casemanager MUST be notified to escalate case to manager/director for resolution discussion

---

## REQ-004: PvA Templates per CAO

### REQ-004-001: Pre-Fill CAO-Specific PvA Template

**GIVEN** a wvp-case with cao-id = `cao-gemeenten`  
**WHEN** the casemanager clicks "PvA opstellen"  
**THEN**
- The system MUST fetch the PvA template for CAO Gemeenten
- Template MUST pre-fill with:
  - `re-integratiebudget-eur`: EUR 4.500 (per CAO Gemeenten standard)
  - `evaluatie-frequentie-weken`: 6 weeks
  - Inzetbaarheidsgesprek-cadans: Every 6 weeks (mandatory per CAO)
  - Standard acties for Gemeenten (inzetbaarheidsgesprek, FML, offered roles, etc.)
  - Vervanging-funds conditions (if applicable)
  - Legal reference: CAO Gemeenten Article [X]

**GIVEN** a wvp-case with cao-id = `cao-onderwijs-po` (Primary Education)  
**WHEN** the casemanager clicks "PvA opstellen"  
**THEN**
- The system MUST fetch the PvA template for CAO PO
- Template MUST pre-fill with:
  - Vervangingsfonds conditions & thresholds
  - Inzetbaarheidsgesprek-cadans per PO-rules
  - Standard acties relevant to teaching roles (classroom, admin, support roles)
  - Legal reference: CAO PO Article [X]

### REQ-004-002: Validate Custom Template Against UWV Schema

**GIVEN** an HR-administrator uploads a customized PvA template via admin-config  
**WHEN** the upload completes  
**THEN**
- System MUST validate the template against the UWV-required schema (Verplichte velden):
  - `employee-name` ✓
  - `case-id` ✓
  - `eerste-ziektedag` ✓
  - `doelstelling-re-integratie` ✓
  - `evaluatie-frequentie` ✓
  - `acties[]` with at least 1 entry ✓
  - `re-integratiebudget-eur` (if applicable per UWV guidance)
  - `handtekening-werkgever` & `handtekening-werknemer` fields ✓
- If any required field is missing or malformed:
  - Upload MUST be rejected with HTTP 422
  - Error response MUST list per-veld issues:
    ```json
    {
      "errors": [
        { "field": "doelstelling-re-integratie", "error": "Required field missing" },
        { "field": "acties", "error": "Array must contain at least 1 action" }
      ]
    }
    ```
- If validation passes:
  - Template MUST be stored in template-engine
  - Deployed to all cases with the corresponding cao-id immediately
  - A notification MUST alert casemanagers: "Updated PvA template deployed for [CAO-name]"

---

## REQ-005: Eerstejaarsevaluatie (Week 46-52)

### REQ-005-001: Daily Reminder Until Evaluatie Scheduled

**GIVEN** a wvp-case at week 46 (eerste-ziektedag + 322 days)  
**WHEN** no eerstejaarsevaluatie-meeting is scheduled (i.e., geen eerstejaars-evaluatie record exists)  
**THEN**
- Each morning at 08:00 (tenant's timezone), the casemanager MUST receive a reminder:
  - Title: "📅 Eerstejaarsevaluatie Benodigd — [employee-name]"
  - Body: "This is reminder #[N] to schedule the year-1 evaluation meeting. Click here to schedule: [URL]"
  - The reminder MUST persist on the dashboard until either:
    - A scheduled-date is set (even if future), OR
    - completed-date is recorded
- If no action is taken by week 50, reminder escalates to casemanager's manager

### REQ-005-002: Auto-Create 2e-Spoor Traject on Start Decision

**GIVEN** an eerstejaarsevaluatie completed with decision = `start-2e-spoor`  
**WHEN** the evaluatie is saved  
**THEN**
- A new tweede-spoor-traject entity MUST be created with:
  - `traject-id` = new UUID
  - `case-id` = reference to the wvp-case
  - `traject-status` = `concept` (awaiting bureau selection)
  - `contract-start-date` = NULL (until bureau is selected)
  - `progress-rapportage-frequentie-days` = 90 (default)
  - `created-at` = current timestamp
- casemanager MUST be notified: "Year-1 evaluation concluded. 2e-spoor trajectory initiated. Please select a re-integratiebureau and set contract details."
- UI MUST navigate to a "Select Re-integratieBureau" form
- Casemanager can search & select from partner-registry (filtered by Blik op Werk-status = "certified")

### REQ-005-003: Flag Voortzetting-1e-Spoor for Heroverweging

**GIVEN** an eerstejaarsevaluatie completed with decision = `voortzetting-1e-spoor`  
**WHEN** week 52 passes (erste-ziektedag + 364 days) without a new eerstejaarsevaluatie  
**THEN**
- The next scheduled 6-weekly evaluatie (or the case-dashboard view) MUST flag the case with:
  - Alert title: "⚠️ 1e-spoor Continuation Decision Requires Heroverweging"
  - Body: "The year-1 evaluation decision to continue with 1e-spoor is now [X] weeks old. Reintegration status may have changed. Schedule a heroverweging-meeting."
- The milestone `week-52-opschudmoment` MUST be marked as `at-risk`
- This flags the possibility that 2e-spoor should now be initiated if 1e-spoor has stalled

---

## REQ-006: 2e-Spoor Traject & Quarterly Rapportage

### REQ-006-001: Activate Traject on Bureau Selection

**GIVEN** a tweede-spoor-traject in status = `concept`  
**WHEN** HR selects a re-integratiebureau from partner-registry and saves contract details:
- `traject-status` → `actief`
- `re-integratiebureau-id` = selected bureau
- `contract-start-date` = entered date (typically today or near-future)
- `contract-end-date` = entered date (typically 6-12 months from start)
- `contracted-amount-eur` = entered amount
- `last-voortgangsrapportage-date` = NULL (first report not yet received)

**THEN**
- System MUST schedule first voortgangsrapportage reminder 90 days from `contract-start-date`
- Casemanager MUST be notified: "2e-spoor bureau contract activated: [bureau-name], contract period [start] to [end]. First quarterly report due [date]."
- Bureau MUST receive notification (via email or partner-portal): "Your contract for case [case-id] is now active. First progress report due [date]. Submit via: [portal-URL]."

### REQ-006-002: Flag Rapportage Overdue at Day 91

**GIVEN** a tweede-spoor-traject with `traject-status` = `actief`  
**WHEN** today's date exceeds `last-voortgangsrapportage-date + progress_rapportage_frequentie_days + 14` (i.e., 90+ 14 = 104 days since last report)  
**THEN**
- `traject-status` MUST transition to `voortgangsrapportage-overdue`
- case-status MUST be flagged as `2e-spoor-niet-bijgehouden-risico`
- Casemanager MUST receive critical alert:
  - Title: "🚨 2e-Spoor Progress Report OVERDUE"
  - Body: "[bureau-name] has not submitted a quarterly progress report for [case-id] / [employee-name]. Last report: [date]. This is grounds for loonsanctie if UWV reviews. Contact bureau immediately."
- Bureau MUST receive escalation email: "URGENT: Progress report overdue for case [case-id]. Submit immediately or contract may be terminated."

### REQ-006-003: Alert on Contract End Without Renewal

**GIVEN** a tweede-spoor-traject with `contract-end-date` approaching (within 14 days)  
**WHEN** no new traject-record exists with overlapping/subsequent contract dates  
**AND** week 87 is approaching (eindevaluatie deadline)  
**THEN**
- Casemanager MUST receive warning:
  - Title: "⚠️ 2e-Spoor Contract Ending; Eindevaluatie Deadline Approaching"
  - Body: "Contract with [bureau] ends [date]. Eindevaluatie must be started by week 87 ([date]). If 2e-spoor is not renewed or concluded, the RIV may be incomplete and WIA-claim processing will be delayed."
- Case MUST be flagged for escalation to manager

---

## REQ-007: Eindevaluatie & RIV Export (Week 87-91)

### REQ-007-001: Critical Alert at Week 87

**GIVEN** a wvp-case at week 87 (eerste-ziektedag + 609 days)  
**WHEN** no eindevaluatie has been started (i.e., no eindevaluatie-riva record exists with requested-date set)  
**THEN**
- case-status MUST be flagged as `RIV-deadline-imminent`
- Casemanager MUST receive CRITICAL alert (red banner, persistent until action taken):
  - Title: "🚨 CRITICAL: RIV Assembly Required by Week 91"
  - Body: "Eindevaluatie and RIV must be completed and signed by [week-91-date] for WIA-claim submission. Time remaining: [days]. Start now: [URL]."
- If no action by week 88, escalation to HR-director MUST be triggered
- If no action by week 89, UWV MUST be pre-notified that RIV will be late (per UWV contact procedure)

### REQ-007-002: Bundle & Export RIV PDF-A

**GIVEN** a wvp-case at week 87+ with eindevaluatie initiated  
**WHEN** HR clicks "RIV samenstellen" or "Export RIV"  
**THEN**
- System MUST query all WVP artifacts:
  1. Case summary (employee, dates, milestones, outcomes)
  2. Probleemanalyse (from re-integratie-dossier, if share-with-uwv-bij-riva = true & employee-consent = true)
  3. FML (Functionele Mogelijkheden Lijst) — all versions
  4. PvA — all versions, with signatures
  5. Eerstejaarsevaluatie — meeting minutes & decision
  6. All 6-weekly evaluatie adjustments (if 1e-spoor was continued)
  7. 2e-spoor-rapportages — all quarterly reports from bureau (if 2e-spoor was activated)
  8. Eindevaluatie — final medical opinion from bedrijfsarts
  9. Case timeline — summary of milestone dates & status
- document-template-engine MUST render master RIV template (UWV format 2024-01 compliant):
  - Generate PDF-A (ISO/IEC 19005-1 archival format)
  - Include cover page with:
    - "Re-integratieverslag" title
    - Case ID, employee name, dates
    - Checksum (SHA256) of the PDF content displayed on cover
    - UWV submission instructions
  - All bundled documents MUST retain their original formatting & signatures (where applicable)
  - Page numbers & table of contents MUST be auto-generated
- PDF MUST be stored in document-store
- eindevaluatie-riva.riva-pdf-document-id = [doc-id]
- eindevaluatie-riva.riva-checksum = SHA256(PDF-content)
- Email MUST be sent to employee: "Your RIV is ready for review. Please sign by [week-91-deadline]. Link: [portal-URL]"

### REQ-007-003: Employee Signature & Late-Signing Process

**GIVEN** the RIV PDF has been generated and shared with the employee  
**WHEN** the employee signs via self-service portal by week 91  
**THEN**
- Employee MUST e-sign or upload scanned signature
- eindevaluatie-riva.werknemer-signed-on = current timestamp
- Casemanager MUST be notified: "RIV signed by employee. Ready for UWV submission."

**GIVEN** the week-91 deadline passes without employee signature  
**WHEN** the deadline-check cron runs  
**THEN**
- HR MUST be notified:
  - Title: "⚠️ Employee Has Not Signed RIV"
  - Body: "Deadline [week-91-date] has passed. Employee signature is not yet received. Per UWV instructions, you may submit the RIV as 'Werkgevers-version only' with a note that the employee did not sign. Here is the UWV instructie link: [URL]. Employee can add opmerkingen afterwards."
- HR MUST have a button: "Submit RIV Without Employee Signature (per UWV guidelines)"
- If HR chooses to proceed:
  - RIV MUST be transmitted to UWV with a cover-note: "RIV submitted by employer; employee did not sign by deadline. Employee may file opmerkingen separately."
  - eindevaluatie-riva.uwv-submitted-on = current timestamp

---

## REQ-008: AVG Artikel 9 Medical Data Segregation

### REQ-008-001: HR Cannot View Medical Content

**GIVEN** an HR-user with role `hr-medewerker` opening a wvp-case detail  
**WHEN** the API returns the case payload  
**THEN**
- re-integratie-dossier entries MUST NOT be included in the payload (or returned as metadata only)
- HR MUST see only:
  - `dossier-entry-count`: integer (e.g., "3 medical records")
  - `dossier-date-range`: { first: "2026-03-20", last: "2026-03-28" }
  - Entry types (without content): ["probleemanalyse", "spreekuur-verslag"]
  - No encrypted-payload, no narrative content
- A note MUST be displayed: "Medical dossier contains [N] entries by bedrijfsarts, not visible due to data segregation (AVG Artikel 9)."

### REQ-008-002: Bedrijfsarts Can View & Download Medical Content

**GIVEN** a bedrijfsarts user opening the same wvp-case  
**WHEN** the API request is made  
**THEN**
- re-integratie-dossier entries MUST be returned in full:
  - dossier-entry-id, entry-type, recorded-date, bedrijfsarts-author-id
  - Decrypted content (encrypted-payload → plaintext, via HSM decryption)
  - share-with-uwv-bij-riva flag
  - employee-viewed-at timestamp
- Each read MUST be logged immediately to `medical-access-audit`:
  - `reader-id` = current user ID (bedrijfsarts)
  - `record-id` = dossier-entry-id
  - `timestamp` = current timestamp
  - `ip-address` = client IP
  - `action-code` = "READ_FULL_CONTENT"

### REQ-008-003: Retention & Deletion Per 24-Month Rule

**GIVEN** an employee whose dienstverband has ended  
**WHEN** 24 months have elapsed since case-actual-end-date  
**THEN**
- The `medical-deletion-cron` MUST identify all re-integratie-dossier entries in this case
- Each entry MUST be hard-deleted (removed completely from database)
- A deletion-audit record MUST be created:
  - `dossier-entry-id` (now deleted, but logged)
  - `deletion-date` = current date
  - `deletion-reason` = "AVG 24-month retention expired"
  - This audit record MUST be retained for 7 years per Belastingdienst retention rules
- A notification MUST be sent to HR: "Medical records for case [case-id] / [employee-name] have been deleted per AVG retention policy."

---

## REQ-009: Loondoorbetaling 70% Calculation

### REQ-009-001: Year 1 with Minimum-Loon Floor

**GIVEN** an employee in `year-of-sickness = 1` with `refundable-loon-amount-eur = 3.000`  
**WHEN** payroll-engine-nl calls the API to fetch loondoorbetaling-lines for this pay-period  
**THEN**
- `loondoorbetaling-gross-eur` MUST be calculated as:
  ```
  loondoorbetaling-gross-eur = MAX(
    refundable-loon-amount-eur * 0.70,  // 3.000 * 0.70 = 2.100
    wettelijk-minimum-loon-januari-2026  // e.g., 1.500 (approximate floor)
  )
  // Result: max(2.100, 1.500) = 2.100
  ```
- `cao-suppletie-eur` MUST be calculated per the employee's CAO (if applicable):
  - For CAO Gemeenten (100% jaar 1, 90% jaar 2): `cao-suppletie-eur = 3.000 * (1.00 - 0.70) = 0.90` (i.e., 30% supplement to reach 100%)
- `total-gross-eur` = `loondoorbetaling-gross-eur + cao-suppletie-eur` = 2.100 + 0.90 = 3.000
- Line MUST be created in loondoorbetaling-line entity with all fields populated

### REQ-009-002: Year 2 without Minimum-Loon Floor

**GIVEN** an employee in `year-of-sickness = 2` with `refundable-loon-amount-eur = 1.500`  
**WHEN** payroll-engine-nl calls the API to fetch loondoorbetaling-lines for this pay-period  
**THEN**
- `loondoorbetaling-gross-eur` MUST be calculated as:
  ```
  loondoorbetaling-gross-eur = refundable-loon-amount-eur * 0.70  // 1.500 * 0.70 = 1.050
  // NO minimum-loon floor applied in jaar 2
  ```
- `cao-suppletie-eur` MUST be calculated per CAO:
  - For CAO Gemeenten (90% jaar 2): `cao-suppletie-eur = 1.500 * (0.90 - 0.70) = 0.30` (i.e., 20% supplement to reach 90%)
- `total-gross-eur` = 1.050 + 0.30 = 1.350
- Line MUST be created

### REQ-009-003: CAO-Specific Suppletie Rules

**GIVEN** an employee covered by CAO Gemeenten with suppletie-regeling = "100% jaar 1 / 90% jaar 2"  
**WHEN** payroll runs in jaar 1  
**THEN**
- cao-engine MUST return suppletie-percentage = 1.00 (100%)
- loondoorbetaling calculation:
  ```
  base-70-percent = refundable-loon * 0.70
  cao-suppletie = refundable-loon * (1.00 - 0.70)  // top-up to 100%
  total = base + suppletie
  ```
- Two separate payroll lines MUST be created:
  1. `loondoorbetaling-gross-eur` = base-70-percent (description: "Ziekte 70%")
  2. `cao-suppletie-eur` line (description: "CAO Gemeenten suppletie ziekte")
- Both MUST appear in payroll export separately so HR/finance can audit them

---

## REQ-010: UWV Poortwachterstoets & Loonsanctie Response

### REQ-010-001: Receive & Register Loonsanctie Outcome

**GIVEN** a wvp-case with WIA-aanvraag submitted (week 91)  
**WHEN** UWV issues poortwachterstoets outcome with sanction verdict  
**AND** HR receives the UWV notification (email, file-upload, or API webhook)  
**THEN**
- System MUST parse the UWV outcome and create loonsanctie-case:
  - case.case-status → `loonsanctie`
  - case.loonsanctie-start-date = [date-from-UWV-verdict]
  - case.loonsanctie-weeks = [duration-from-verdict] (typically 52)
  - A case-event MUST be logged: "UWV poortwachterstoets: DENIED. Loonsanctie imposed: [weeks] weeks starting [date]."
- All future loondoorbetaling-lines (from loonsanctie-start-date + loonsanctie-weeks) MUST be automatically extended:
  - New end-date = original end-date + loonsanctie-weeks
  - Lines MUST be recalculated and payroll-updated
- Casemanager MUST receive notification:
  - Title: "⚠️ Loonsanctie Imposed by UWV"
  - Body: "UWV poortwachterstoets result: DENIED. Loonsanctie [weeks] weeks starting [date]. Loondoorbetaling extended to [new-end-date]. Financial impact: ~EUR [calculated-amount]. You have [deadline] to file bezwaarschrift if you believe you complied."
- Finance MUST be alerted to update their forecast (payroll obligation extended)

### REQ-010-002: Support Sanction Appeal

**GIVEN** a case in `loonsanctie` status  
**WHEN** HR decides to appeal the sanction via bezwaarschrift  
**THEN**
- casemanager MUST click "File Bezwaarschrift" button
- System MUST display:
  - UWV deadline for appeal (typically 6 weeks from verdict date)
  - Template bezwaarschrift form with pre-filled case details
  - List of documents/evidence HR can attach (milestones completed, evidence of re-integratie efforts, etc.)
- HR MUST enter:
  - Reason for appeal (free-text)
  - Supporting documents (upload, or link to case artifacts)
  - Signatory (HR-manager or legal representative)
- On submission:
  - case.case-status → `loonsanctie-bezwaar-lopend`
  - A bezwaar-record MUST be logged with submission-date, expected-outcome-date (6 weeks later)
  - Notification MUST be sent to case-reviewer/director for awareness

### REQ-010-003: Early Herstel With Ongoing Sanction

**GIVEN** a case in `loonsanctie` status with loonsanctie-end-date in the future  
**WHEN** the employee recovers and HR registers herstel before the sanction end-date  
**THEN**
- HR MUST confirm: "Employee is recovered. Sanction period is still active ([days] remaining). Do you want to terminate the sanction early?"
- If HR confirms:
  - loonsanctie-weeks MUST be updated to reflect actual duration (fewer weeks)
  - All loondoorbetaling-lines after herstel-date MUST be deleted (no further payment)
  - case.case-status → `herstel` (sanction lifted upon recovery, per UWV logic)
  - A bekorting-loonsanctie-aanvraag MUST be auto-generated and submitted to UWV
    - This form notifies UWV that the employee has recovered early and the sanction should be closed
  - Finance MUST be notified: "Loonsanctie terminated early due to recovery. Loondoorbetaling ends [date]."
  - Casemanager MUST receive notification: "Sanction period ended early upon recovery. UWV has been notified."

---

## Acceptance Criteria Summary

| Requirement | Automated? | Manual Review? | Audit-Logged? | UWV-Facing? |
|-------------|-----------|----------------|--------------|-----------|
| REQ-001-001 | Yes | No | Yes | No |
| REQ-001-002 | Yes | Yes (reopen) | Yes | No |
| REQ-001-003 | Yes | No | Yes | No |
| REQ-002-001 | Yes (cron) | No | Yes | No |
| REQ-002-002 | Yes (cron) | No | Yes | Yes |
| REQ-002-003 | Yes | Yes | Yes | No |
| REQ-003-001 | Yes (cron) | No | Yes | Yes |
| REQ-003-002 | No (portal) | Yes (employee) | Yes | No |
| REQ-003-003 | Yes | No | Yes | No |
| REQ-004-001 | Yes | No | Yes | No |
| REQ-004-002 | Yes | Yes (admin) | Yes | No |
| REQ-005-001 | Yes (cron) | No | Yes | No |
| REQ-005-002 | Yes | No | Yes | No |
| REQ-005-003 | Yes (cron) | No | Yes | No |
| REQ-006-001 | No (HR) | Yes (HR) | Yes | No |
| REQ-006-002 | Yes (cron) | No | Yes | Yes |
| REQ-006-003 | Yes (cron) | No | Yes | Yes |
| REQ-007-001 | Yes (cron) | No | Yes | Yes |
| REQ-007-002 | No (HR) | Yes (HR) | Yes | Yes |
| REQ-007-003 | No (portal) | Yes (employee) | Yes | Yes |
| REQ-008-001 | Yes (RLS) | No | Yes | No |
| REQ-008-002 | Yes (audit) | No | Yes | Yes (if consent) |
| REQ-008-003 | Yes (cron) | No | Yes | No |
| REQ-009-001 | Yes | No | Yes | No |
| REQ-009-002 | Yes | No | Yes | No |
| REQ-009-003 | Yes | No | Yes | No |
| REQ-010-001 | Yes (webhook) | Yes (HR review) | Yes | Yes |
| REQ-010-002 | No (HR) | Yes (HR+legal) | Yes | Yes |
| REQ-010-003 | Yes | Yes (HR confirm) | Yes | Yes |


---
status: specs
---

# AOR Ambtenarenrecht — Specifications

## Requirements

### REQ-001-001: Ontslag-Procedure Entry (Wnra Context)

**GIVEN** an HR-jurist opens a new EmploymentCase with caseType=ontslag
**WHEN** they select a ontslaggrond (a-i per BW art. 7:669)
**THEN**
- System displays subType options per selected grond (a-f: standard, g-h: kantonrechter-route, i: UWV-route)
- Confirm-button pre-fills CaseStep array with workflow steps per grond + legalBasis references
- System calculates opzegtermijn per Wnra context (standard: 4 weeks, executive: 8 weeks, other per contract)
- Bezwaartermijn auto-set to 42 days
- dossierFolderId auto-created in docudesk with ACL tied to EmploymentCase.confidentialityLevel

**AC**:
- Grond-to-workflow mapping covers all 9 grounds (a-i) with correct statutoarische termijnen
- Case opens with status=concept; can be saved without final signing until caseHandlerId is assigned
- Opzegtermijn matches BW art. 7:657 (4 weeks) or 8 weeks for management-level per contract

---

### REQ-001-002: UWV Route Injection (Bedrijfseconomisch / Langdurige Arbeidsongeschiktheid)

**GIVEN** HR-jurist selects caseType=ontslag subType=ontslag-h-grond (overige omstandigheden) or ontslag-i-grond
**WHEN** the case is opened
**THEN**
- Workflow auto-injects CaseStep: "Verzend UWV-formuliereenset A+B" (stepCode: ONT-H01)
- Template-manager pre-fills employee data (persoonsgegevens, dienstverband-details) in UWV form A
- CaseStep "Werkgevers-onderbouwing" auto-created with dueDate = now + 7 days
- UWVIntegrationService registers case for monitoring (status polling from UWV portal)

**AC**:
- UWV formuliereenset auto-populated with current employee-master data (naam, BSN, dienststart, huisadres)
- Checklist "Werkgevers-onderbouwing" visible to assigned manager with guidance per bedrijfseconomisch scenario (3 standard templates: cost-reduction, market-downturn, structural-change)
- Case shows explicit "UWV route active" badge until UWV decision is received
- UWV decisions (toewijzing / afwijzing) auto-imported and create follow-up CaseSteps

---

### REQ-002-001: Transitievergoeding Calculation (Wettelijke Formule)

**GIVEN** an EmploymentCase reaches status=besluitvorming and caseType=ontslag
**WHEN** handler selects "Calculate Transitievergoeding" 
**THEN**
- System fetches salary freeze snapshot from employee-master (signed contract salary + structurele toeslagen at moment of termination-notice)
- Applies 1/3 * maandsalaris * dienstjaren formula per BW art. 7:673
- Service years calculated from contract.startDate (full years only)
- Transitievergoeding object created with formula citation + component breakdown
- Handler sees gross + net estimate for employee communication

**AC**:
- Frozen salary snapshot immutable once Transitievergoeding created (audit trail on any changes)
- Service-years includes proeftijd-periode (counts as full year after completion)
- Calculation round to nearest euro (Dutch banker's rounding)
- Formula example: 3500 base + 500 vakantiegeld = 4000 * 12 dienst-jaren * 1/3 = 16.000 bruto

---

### REQ-002-002: CAO Bovenwettelijke Uitkering

**GIVEN** an EmploymentCase has selected CAO variant (Rijksoverheid, Gemeenten, SGO, Provincies, Waterschappen)
**WHEN** Transitievergoeding calculation is requested
**THEN**
- System retrieves CAO-bepalingen for bovenwettelijke component (BWNL, BWGS, WW-bovenwettelijk, etc.)
- Calculates bovenwettelijkeComponent separately and displays alongside statutory amount
- Totaal-overzicht shows both components with explicit citations to CAO article
- Handler can optionally select "simplified view" showing only total amount for employee letter

**AC**:
- CAO-selector available at EmploymentCase creation (defaults to Gemeenten if not specified)
- Bovenwettelijke component calc uses same salary-freeze snapshot as statutory
- Breakdown example: statutory €16.000 + CAO-BWGS €8.000 = total €24.000
- CAO amendments automatically retrieved on case creation (reads from contract-management.vigerendCAO)

---

### REQ-002-003: Overgangsregeling & Exclusions (Kleine-Werkgever, AOW-Grens)

**GIVEN** a Transitievergoeding calculation is requested for employee matching exclusion criteria
**WHEN** system detects kleine-werkgever-uitzonderingBezwaar OR employee age >= AOW-eligible-age
**THEN**
- Calculation surfaces exclusion warning with explicit legal citation (BW art. 7:673 lid 2 for AOW-grens)
- Proposes reduced or zero transitievergoeding with justification
- Four-eyes approval required: two distinct signatories (hr-director + finance-manager) must confirm override
- Override decision logged immutably in Transitievergoeding.auditTrail

**AC**:
- AOW-grens auto-calculated per DOB (reference date = effectiveDate van Besluit)
- Kleine-werkgever check per employee-master.employerSize
- Manual override flagged in CaseStep ("Transitievergoeding override review") + dashboard alert
- Exclusion citation pre-filled in Besluit-draft

---

### REQ-003-001: Klokkenluidermelding Registration & Protection

**GIVEN** a melder submits disclosure via designated channel (vertrouwenspersoon, HvK, toezichthouder, anoniem)
**WHEN** system registers the Klokkenluidermelding
**THEN**
- Klokkenluidermelding object created with melderType (intern|extern|anoniem)
- protectedUntil auto-set to now + 7 years per Wet bescherming klokkenluiders
- melder_identity field encrypted with key rotated per quarterly key-management schedule
- ACL grants access ONLY to: vertrouwenspersoon + integriteitscoördinator (max 2 named persons)
- Melder's name/contact replaced with pseudoniem in all default views (e.g., "Melder M1" in case summary)

**AC**:
- 7-year protection applies regardless of melding-resolution timeline
- Melder identity resolvable only by decryption keys held by vertrouwenspersoon + integriteitscoördinator
- Cases referencing same Klokkenluidermelding cross-linked in audit-trail, not to melder identity
- Pseudonym consistent within same organisation (e.g., always "Melder M1" for all disclosures from same employee)

---

### REQ-003-002: Melder Retaliation Check on HR Action

**GIVEN** any EmploymentCase involving HR action (termination, suspension, transfer, pay-cut) is initiated within 24 months of a Klokkenluidermelding registered to same employee
**WHEN** case is opened or action is confirmed
**THEN**
- System auto-detects correlation (Klokkenluidermelding.caseId check + melder-timeframe)
- Creates retaliationCheckLog entry on Klokkenluidermelding
- Auto-notifies integriteitscoördinator with case summary + melding context
- Flags CaseStep ("Retaliation Risk Assessment") requiring integriteitscoördinator verdict before action proceeds
- Action blocked at UI level until verdict ∈ {retaliation-risk-low, safe-to-proceed}

**AC**:
- 24-month window calculated from Klokkenluidermelding.createdAt to current CaseStep.dueDate
- Retaliation check triggered even if Klokkenluidermelding.caseId is null (unlinked disclosure)
- Verdict options: "retaliation-risk-high" (action blocked, escalate to college), "retaliation-risk-low" (proceed), "safe-to-proceed" (documented safe)
- Verdict + reasoning logged immutably; cannot be overridden except by college-level escalation

---

### REQ-003-003: External Escalation to Huis voor Klokkenluiders

**GIVEN** a melder requests escalation to Huis voor Klokkenluiders or sectorale toezichthouder
**WHEN** vertrouwenspersoon selects "Generate External Export"
**THEN**
- System generates sanitised disclosure packet:
  - melder_identity replaced with token (e.g., "CLAIM-12345")
  - BSN, persoon-details, and derived-party identities anonymised
  - Summary, subject, and supporting-evidence included verbatim
  - Date-references (melding date, HR actions) retained for timeline context
- Export packaged as encrypted PDF + cover letter per Huis voor Klokkenluiders template
- huisVoorKlokkenluidersRef field populated with external tracking ID
- Email delivery to Huis (configured organisatie-wide) with unsubscribe-URL logged but not shared to melder

**AC**:
- Export generation requires vertrouwenspersoon approval + secondary review by integriteitscoördinator
- Anonymisation rules: NO BSN, NO full names, NO email/tel in export (melder reached via vertrouwenspersoon)
- Encrypted delivery uses PGP key for Huis voor Klokkenluiders (updated per their key-rotation notice)
- huisVoorKlokkenluidersRef allows two-way tracking (HvK reference number logged locally, local caseId available to HvK)

---

### REQ-004-001: Tuchtbesluit Workflow (Barp/AMAR/Judiciary)

**GIVEN** a non-normalised organisation (politie, defensie, rechterlijke macht) opens EmploymentCase with caseType=tucht
**WHEN** HR-jurist selects organisational type + applicable rechtspositie-besluiten (Barp, AMAR, rechterlijke regelgeving)
**THEN**
- Workflow auto-injects correct hoorzitting-template + termijnengelas per applicable regulation
- Automatische CaseStep "Hoorzitting Inleiding" with min. 14-day notice per Barp §3.2 / AMAR art. 42
- Proposed tuchtmaatregel routed for hoor-en-wederhoor if severity > schriftelijke berisping (suspension / demotion / termination)
- Verplichte verdedigingsfase with full case-file access to accused before final decision

**AC**:
- Regulations auto-selected per employee-master.employerType + organisatie-level config
- Hoorzitting-template includes required elements per Barp/AMAR (accusation summary, scheduled hearing date, representation rights)
- CaseStep auto-calculates termijn per regulation (min. 14 days, max 90 days per Barp §3.2)
- Mandatory zwaarte-assessment: schriftelijke berisping < suspensions < demotion < termination (each tier triggers stricter procedural gates)

---

### REQ-004-002: Auto-Anonimisering Post-Expiry

**GIVEN** a tuchtbesluit is issued with maatregel ∈ {schriftelijke berisping, suspensie, demotie}
**WHEN** retentionClass auto-assigned to EmploymentCase on afronding
**THEN**
- Zwaarte-dependent expiry calculated: schriftelijke berisping = 3j, suspensie = 5j, demotie = 10j post-execution
- Scheduled archival-job (n8n workflow via ScheduledWorkflow entity) runs quarterly
- On expiry date, all tuchtmaatregel references in personeelsdossier + EmploymentCase are pseudonymised (name → "Former employee X", date → "Q3 [year]")
- Anonimisering logged immutably with audit-trail entry + notification to HR-records keeper

**AC**:
- Zwaarte-expiry set on CaseStep "Maatregel Uitvoering" completedAt timestamp
- Anonimisering uses deterministic pseudonym per employee + maatregel-type (reproducible for audit checks)
- Anonimisering does NOT delete underlying Tucht-entity; audit-trail remains (for CRB appeals, etc.)
- Job failure logged + escalated to HR-director for manual intervention

---

### REQ-005-001: Disciplinaire Maatregel Juridische Motivering (Wnra)

**GIVEN** HR-jurist prepares EmploymentCase with caseType=disciplinair subType ∈ {waarschuwing, schorsing, loonstop}
**WHEN** concept-besluit is drafted
**THEN**
- Template-system pre-fills Juridische Motivering section with legal framework:
  - BW art. 7:611 (goed werkgeverschap) as baseline
  - CAO-bepalingen if applicable (e.g., CAO Gemeenten art. 3.2.1 on progressive discipline)
  - Specific misconduct reference (dated incident, documented evidence)
- Draft shows "motivation completeness score" (80%+ required for signing)
- Handler can customize motivering per case facts but cannot remove statutory citations

**AC**:
- Motivering template includes required elements: (1) incident date + summary, (2) employee prior warnings/history, (3) proportionality check, (4) BW+CAO basis, (5) remedy proposed (warning / suspension / pay-cut)
- Completeness score requires minimum 3 substantive paragraphs before besluit can transition to signed
- Draft motivering visible to employee during hoor-en-wederhoor phase

---

### REQ-005-002: Loonopschorting vs Loonstop Distinction

**GIVEN** concept-besluit includes salary-suspension component (schorsing, pay reduction, pay stop)
**WHEN** handler selects disciplinary-measure type
**THEN**
- System enforces distinction via two-step routing:
  1. **Loonopschorting** (BW art. 7:629 lid 6): employee suspends work, employer suspends wages, NO arbo-arts meldplicht
     → PayrollMutationService generates "suspend pay" instruction (no regulatory notification required)
  2. **Loonstop** (BW art. 7:629 lid 3): pay reduction/freeze while employee continues work, meldplicht arbo-arts applies
     → PayrollMutationService generates "reduce pay" instruction + CaseStep "Arbo-Arts Notification" (assignee: occupational-health contact)
- System auto-detects if loonopschorting incorrectly mapped to "continued work" scenario (validation error)

**AC**:
- BW article citations pre-filled in besluit-draft per selected measure-type
- PayrollMutationService receives distinct payload per measure (suspend-all-pay vs reduce-to-X amount)
- Arbo-arts meldplicht (loonstop) auto-triggers with preset notification template + occupational-health contact look-up
- Decision immutable once signed; reversal triggers separate CaseStep ("Maatregel Intrekking")

---

### REQ-005-003: Reversal & Pay-Back-with-Interest

**GIVEN** a disciplinary Besluit is revoked (medewerker withdraw aanvraag, rechter invalidates maatregel) or case reaches status=ingetrokken
**WHEN** reversal is confirmed
**THEN**
- System calculates backpay: (original-monthly-salary - suspended-amount) × suspension-duration
- Interest applied per standard Dutch legal interest (Wettelijke Rente art. 6:119 BW) from maatregel-start to reversal-date
- PayrollMutationService receives "reversal-with-rente" instruction with calculated amounts
- All CaseSteps post-reversal-point are rolled-back in status (pending → archived-reverted)
- EmploymentCase.auditTrail permanently logs reversal decision + rechterlijke uitspraak reference (if applicable)

**AC**:
- Backpay calculation includes all salary components (base + vakantiegeld + structurele toeslagen), frozen at measure-start date
- Interest calculated daily (6% per annum standard, or actual rate per employment contract if higher)
- PayrollMutationService confirmation required before marking CaseStep "Pay Reversal Executed" complete
- Reversal-reason documented (withdraw aanvraag | rechterlijke vernietiging | bezwaar-toewijzing) with date of decision

---

### REQ-006-001: Escalatie naar College B&W (Besluitvorming Level)

**GIVEN** an EmploymentCase reaches escalation threshold (C-level termination, €50k+ severance impact, bestuur-affecting integrity issue)
**WHEN** HR-director initiates college-routing
**THEN**
- System auto-detects escalation-eligibility per threshold rules (role-level + financial-impact check)
- Generates collegevoorstel per local gemeente-specific template library (configured in organisatie-settings)
- Pre-populates: case summary, financial impact, legal basis, employee context, recommended college-decision
- Creates CaseStep "Collegevoorstel Review" (assignee: bestuurssecretaris), dueDate = next B&W-agenda-slot
- Integrates with iBabs/Notubiz (gemeente bestuurssysteem) to reserve meeting slot + pre-load voorstel

**AC**:
- Escalation thresholds configurable per org: (1) C-level title auto-escalates, (2) severance > €50k auto-escalates, (3) integrity-bestuur linkage auto-escalates
- Collegevoorstel template auto-selected per template-library (e.g., "Ontslag College Member" vs "Integrity Investigation B&W")
- Voorstel includes 3-5 decision options (approve termination, reject, conditional-approval, request-additional-info) pre-drafted for college deliberation
- iBabs/Notubiz integration confirms agenda-placement or queues for next meeting if slot unavailable

---

### REQ-006-002: College Besluit Registration

**GIVEN** bestuurssecretaris registers college-besluit (decision minutes) from B&W meeting
**WHEN** besluit-decision is recorded
**THEN**
- EmploymentCase links to collegebesluitnummer (unique identifier from iBabs/Notubiz)
- CaseStep "Collegevoorstel Executed" marked complete with decision-outcome (approved | rejected | conditional)
- If approved: next CaseStep "Notify Employee of College Decision" created (channel: aangetekend-post + Digitale Akte)
- If rejected/conditional: CaseStep "Prepare Revised Voorstel" created with bestuurssecretaris instructions

**AC**:
- Collegebesluitnummer uniquely links EmploymentCase.escalationDecision ← iBabs besluitnummer
- Decision outcome persisted immutably in EmploymentCase.auditTrail
- Case can only proceed to "Besluit Signing" if college decision is "approved"
- Notification to employee templates per decision-type (acceptance-letter vs rejection-letter)

---

### REQ-006-003: Terugverwijzing & Revision Termijn

**GIVEN** college decides to "terugverwijzen" (request additional information / further analysis)
**WHEN** bestuursminuten are registered
**THEN**
- CaseStep "Requested Information / Analysis" auto-created with specific requirements from college-minutes
- dueDate = next B&W-agenda-cycle (typically 4-6 weeks)
- Requested documents/analysis fields pre-filled in case-dashboard
- On completion, revised collegevoorstel auto-generated + re-submitted to iBabs for next agenda

**AC**:
- Terugverwijzing reasons tracked (insufficient-legal-basis, financial-impact-unclear, employee-consultation-incomplete, other)
- Each terugverwijzing iteration logged with outcome (approved on revision | rejected | terugverwijzing-again)
- Max terugverwijzingen per case = 2 (escalation to juridisch-advies required if 3rd requested)

---

### REQ-007-001: CRvB Procesdossier Bundling

**GIVEN** employee announces beroep at Centrale Raad van Beroep post-bezwaar-rejection (or first-appeal on tucht/ontslag under ambtenarenwet)
**WHEN** case reaches status=beroep
**THEN**
- System invokes DossierBundlingService to create procesdossier per CRvB-instructies:
  - Chronological file ordering (notice → hoorzitting → decision → bezwaar-handling → beroep-initiation)
  - Geanonimiseerde derden-stukken (getuigen-verklaringen, externe-adviezen with names redacted)
  - Cover-sheet with case-number, parties, legal questions
  - Index with file-references + page-numbers
- Generated procesdossier exported as single PDF + accompanying metadata file (CRvB-template)
- Bundle uploaded to docudesk with CaseStep "Procesdossier Uploaded to CRvB" marker

**AC**:
- CRvB-instructies version auto-matched per CRvB website (quarterly updates tracked)
- Chronological ordering verified via file timestamps + document-type metadata
- Derden-anonimisering uses deterministic redaction (e.g., "Witness A", "External Advisor B")
- PDF bundle includes bookmarks for each major section (enables CRvB reviewers to jump to key documents)

---

### REQ-007-002: Automatic Deadline Calculation on Zittingsdatum

**GIVEN** CRvB registers zittingsdatum (scheduled hearing date)
**WHEN** date is entered into EmploymentCase
**THEN**
- System calculates all internal deadlines per CRvB-procedurele-regels:
  - Verweerschrift deadline = zittingsdatum - 14 days
  - Nadere-Stukken deadline = zittingsdatum - 7 days
  - Getuigenlijst deadline = zittingsdatum - 7 days
- Creates CaseSteps for each deadline with assignees (HR-jurist for verweerschrift, case-handler for updates)
- Dashboard shows countdown + "at risk" warning if deadline within 3 days

**AC**:
- Deadline calculation auto-adjusts if zittingsdatum is postponed (CRvB portal integration)
- Verweerschrift template pre-populated with employer's arguments (from prior bezwaar-handling)
- Nadere-stukken CaseStep includes "upload-zone" in case-UI for submitting supporting documents
- Getuigenlijst template includes required fields per CRvB (name, address, subject of testimony, availability)

---

### REQ-007-003: Post-Uitspraak Workflow

**GIVEN** CRvB uitspraak (verdict) is published + received
**WHEN** case-handler registers decision-outcome
**THEN**
- EmploymentCase.status = beroep_decided, populated with uitspraak-date + decision-summary (CRvB file-number, judge-panel, outcome)
- Workflow branches per verdict:
  - **Toewijzing** (employee wins, employer decision invalidated):
    → CaseStep "Heroverwegen Origineel Besluit" (HR-jurist must draft revised decision or dismissal)
    → PayrollMutationService: if severance was suspended, calculate back-pay-with-rente + issue reversal instruction
    → Notification to employee with compensation + apology statement template
  - **Afwijzing** (employer decision upheld):
    → CaseStep "Prepare Cassatie Advies" (juridisch-advies on grounds for further appeal to Hoge Raad, rare)
    → Notification to employee with closure summary
- Bestuurlijke-melding auto-generated (college informed of beroep-outcome if escalatie was involved)

**AC**:
- Uitspraak-registration immutable once confirmed (audit-trail preserves CRvB-document reference)
- Toewijzing automatically triggers back-pay calculation + PayrollMutationService instruction
- Heroverwegen-process follows same juridische-motivering + hoor-en-wederhoor steps as original decision
- Bestuurlijke-melding templates per outcome-type (toewijzing vs afwijzing)

---

### REQ-008-001: Termijnbewaking & SLA Dashboard

**GIVEN** a Besluit is signed with bezwaartermijn set
**WHEN** daily termijn-tracking job runs (midnight, configurable)
**THEN**
- System calculates daysRemaining = bezwaarDeadline - today
- Dashboard-widget "Termijn Alerts" updated per handler role-assignment
- Alert-tiers per SLA-category:
  - **T-7**: handler sees "high priority" warning (7 days until bezwaar expires)
  - **T-2**: handler sees "critical" warning (2 days), teamlead notified if unaddressed
  - **T-1**: escalation to teamlead + director with "decision required" notification
  - **T-0**: termijn expires, CaseStep "Termijn Verstreken" auto-created, dossiernotitie logged with legal consequence (bezwaar-право forfeited or beroep-recht forfeited)

**AC**:
- Job runs daily at consistent UTC time (e.g., 00:30 UTC) with org-local timezone conversion for deadline calculation
- Dashboard widget ranks cases by daysRemaining (ASC), filterable by status/case-type
- Notifications use escalating channels: T-7 (in-app), T-2 (in-app + email), T-1 (email + SMS to mobile + director-copy)
- Expired-termijn logged immutably with timestamp + legal consequence statement (e.g., "Bezwaartermijn verstreken 2026-07-23, bezwaarrecht forfeit per Awb §6:2")

---

### REQ-008-002: Termijn-Expiry Immutable Logging

**GIVEN** a bezwaartermijn expires without bezwaar-indiening
**WHEN** T-0 is reached and logged
**THEN**
- CaseStep "Termijn Verstreken" auto-created with completedAt = expiry-date, immutable status-locked
- Dossiernotitie entry added to EmploymentCase.auditTrail with:
  - Expiry date + reason (bezwaar | beroep)
  - Legal consequence: "Bezwaarrecht forfeit per Awb §6:2 (6-week termijn)" or "Beroepstermijn forfeit per Beroepswet" 
  - Signature of system (auto-logging system, not human actor)
- Case transitions to status=afgerond (if no further action expected) or status=beroep_forfeit

**AC**:
- Immutable logging requires digital-signature of log-entry (prevents retroactive edits)
- Dossiernotitie visible in case-detail UI with explicit "LEGALLY BINDING CONSEQUENCE" label
- Forfeit-consequence searchable in org-wide audit (for compliance-reporting to bestuur)
- No manual override of expired-termijn; only college-level escalation can restore (rarely)

---

### REQ-009-001: Case Confidentiality & ACL Enforcement

**GIVEN** an EmploymentCase is created with confidentialityLevel ∈ {vertrouwelijk, geheim}
**WHEN** a user without explicit ACL-grant attempts to view the case
**THEN**
- Case is hidden from all search results + list views (CnIndexPage applies ACL-filter)
- Direct URL access blocked with error "Not authorized. Access denied. Incident #XYZ logged." (unique incident-ID for support-tracing)
- Access-denial logged to AuditTrailService with actor, timestamp, and case-reference
- Case-handler (caseHandlerId) receives notification: "Unauthorized access attempt on case [caseNumber] by user [userId] at [timestamp]"
- Repeated access-denials (>5 per day) trigger security-alert to IT-security team

**AC**:
- ACL check runs BEFORE data serialisation (never expose encrypted/masked data if ACL fails)
- Standaard-tier (default) visible to all HR-role users (hr-jurist, hr-manager, hr-director)
- Vertrouwelijk-tier visible only to: caseHandlerId + users explicitly listed in accessControlList[{role, userId}]
- Geheim-tier visible only to: caseHandlerId + integriteitscoördinator + college-secretary (if escalated)
- Access-denial logging includes: actor.userId, case-reference, timestamp, intended-action (view | edit | delete)

---

### REQ-009-002: Melder-Identity Pseudonymisatie in Default Views

**GIVEN** a Klokkenluidermelding is registered
**WHEN** case-summary or case-list is displayed to HR-role users without explicit ACL-grant to melder-identity
**THEN**
- melder_identity field remains encrypted (inaccessible)
- All references to melder in case-summary display as pseudonym (e.g., "Melder M1", "Melder 2026-003")
- Pseudonym consistent within same organisation (same employee → same pseudonym across all related cases)
- Melder's department/function references also pseudonymised ("Employee in [Department A]" instead of full location)
- Only vertrouwenspersoon + integriteitscoördinator see "Identity Decoded" toggle (requires separate permission + audit-log entry)

**AC**:
- Pseudonym generation deterministic per melder-identity hash (reproducible for consistent reference)
- Pseudonym uniqueness within org scope (no cross-organisation melder-tracking)
- "Identity Decoded" toggle logs every access with actor + timestamp + "business justification" field (mandatory entry)
- Decoded identity never cached client-side; fetched fresh on each access

---

### REQ-009-003: Export with AVG-Compliant Pseudonymisation

**GIVEN** case-handler exports EmploymentCase for external juridisch-advies (lawyer, tax-advisor, consultant)
**WHEN** "Generate Export for External Advisor" is selected
**THEN**
- System generates PDF export with:
  - All BSN references replaced with internal ID (e.g., "EMP-9876")
  - Employee full name → "Employee [ID]"
  - Third-party names (witnesses, external parties) → "Third Party A", "Third Party B" (redacted from verbatim text)
  - Dates of birth, addresses (except municipality + postcode first-2-digits)
- Export encrypted via password-protection (system generates random 12-char password, shown to handler in separate popup, never stored)
- Wachtwoord-beveiligde delivery via email with link (password sent via separate SMS to handler's registered phone)
- Export receipt logged: exporter, advisor-details (name + email), export-purpose, timestamp

**AC**:
- Pseudonymisation rules per DPIA-handreikingen Autoriteit Persoonsgegevens (re-identification risk must be <negligible)
- Export cannot include: BSN, full addresses, date-of-birth (unless justified in export-justification field)
- Password protection uses AES-256 encryption (PDF standard)
- Export auto-deletes from external-storage after 30 days (configurable per org-policy)

---

### REQ-010-001: Automatic Retentionklasse Assignment

**GIVEN** an EmploymentCase reaches status=afgerond (decision executed, termijn expired, no further action expected)
**WHEN** case is marked for archival
**THEN**
- System auto-assigns retentionClass per Selectielijsten Gemeenten/Rijk based on caseType:
  - **Ontslag**: retentionClass = "ontslag-75j-post-geboorte" (75 years after employee's DOB)
  - **Integriteitsmelding**: retentionClass = "integriteit-7j" (7 years from melding-date)
  - **Tuchtbesluit**: retentionClass = "tucht-10j-post-verwijdering" (10 years from maatregel-execution-date)
  - **Disciplinair** (minor): retentionClass = "disciplinair-5j" (5 years from decision-date, per CAO)
- EmploymentCase.retentionClass set immutably + archival-flag visible in UI
- CaseStep "Archival Scheduled" created with archival-date calculated per retention-window start-date

**AC**:
- Retentionklasse citations reference Selectielijsten article (e.g., "Selectielijsten Gemeenten art. 3.2.1")
- Calculation uses DOB from employee-master (synced at case-creation, not updated if employee's DOB changes)
- Archival-date calculated automatically (75-year window starts at case-afronding)
- UI shows "Archival Scheduled: 2099-05-15" (helps compliance-checker predict future destruction)

---

### REQ-010-002: Automated Anonimisering & Vernietiging

**GIVEN** a scheduled archival-job (n8n workflow via ScheduledWorkflow entity, quarterly run) encounters EmploymentCase with retentionClass-expiry reached
**WHEN** archival-deadline is today
**THEN**
- If retentionClass = "ontslag-75j-post-geboorte" and today == (DOB + 75 years):
  - Dossier-folder access revoked in docudesk
  - EmploymentCase fields pseudonymised: name → "Former Employee [CaseNumber]", dates → "Q[Year]", BSN → deleted
  - Decision document PDF re-generated with anonymised fields
  - Audit-trail preserved (immutable logs of all state transitions + decision remain in DB)
- If retentionClass = "integriteit-7j" and today == (melding-date + 7 years):
  - Similar pseudonymisation rules + additional: melder-identity + witness-names fully deleted (irreversible)
  - Case-summary → "Integrity disclosure [Year] - Assessment Completed"
- Archival-process logs immutably: job-execution-date, records-affected, destruction-method (pseudonymisation vs deletion), completion-status

**AC**:
- Archival-job runs on quarterly schedule (Q1 Jan-1, Q2 Apr-1, Q3 Jul-1, Q4 Oct-1, UTC midnight)
- Job failure (e.g., docudesk unreachable) triggers immediate alert to compliance-officer + retry scheduled for next day
- Pseudonymisation values deterministic (same employee + caseType always produces same anonym value, for audit-consistency)
- Destruction-log (archival-log) itself retained indefinitely (per Archiefwet audit-trail requirements)

---

### REQ-010-003: RiC-Format Archief Export

**GIVEN** Nationaal Archief or gemeentelijk archief submits overbrenging-verzoek (records-transfer request per Archiefwet)
**WHEN** archival-service receives export-request
**THEN**
- ArchivalService.exportForArchief() generates Records-in-Context (RiC) MIAOU-compliant XML metadata package:
  - Aggregation unit (case-collection) described per RiC agent + context
  - Each file described with: identifier, title, date-created, date-modified, content-description, retention-basis
  - Sensitive-document flags (marked for restricted-access per Archiefwet art. 37)
  - Chain-of-custody metadata (who submitted, when, signature)
- Package exported as .ZIP with XML-metadata + PDF-dossier-folder
- Export logged in compliance-audit (archief-request register)

**AC**:
- RiC-format validated against MIAOU schema (automated validation before export)
- Sensitive-document flags identify: integral-personnel-cases, integrity-disclosures, executive-decisions (restricted-access per 20-year rule per Archiefwet art. 37)
- Archief-request response includes: package-contents list, completion-date, export-signature (digitally signed by compliance-officer)

---

## Acceptance Criteria Summary

All requirements include:
- Immutable audit-trail logging for all state transitions
- Termijn/deadline calculations aligned with Dutch statutory references (BW, Awb, Beroepswet)
- Role-based access control per ADR-005 (vertrouwenspersoon ≠ HR-jurist ≠ integriteitscoördinator)
- External-integration readiness (UWV portal, Huis voor Klokkenluiders, iBabs/Notubiz, Berichtenbox, payroll-engine-nl)
- Notification-delivery channels per importance (in-app, email, SMS for escalations)
- Dutch-language-first UI (en translations available but NL primary per ADR-007)

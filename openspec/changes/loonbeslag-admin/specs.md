# loonbeslag-admin: Specifications

**Status:** draft  
**Created:** 2026-05-23  

## Feature Requirements

### Feature 1: Garnishment Registration & Deadline Enforcement

**Requirement ID:** REQ-001  
**Severity:** Critical (legal/compliance)  
**Acceptance Criteria:**

- **REQ-001-001 | Register exploot within 5 working days**
  - GIVEN a court order (exploot) is received by mail on 2026-05-15 (Wednesday).
  - WHEN the payroll admin registers it in loonbeslag-admin on 2026-05-16 (Thursday), uploading the scanned PDF and entering extraction fields (beslaglegger_naam, beslaglegger_iban, vorderingsbedrag_oorspronkelijk).
  - THEN the system accepts the registration, assigns a unique `beslag_id`, sets `exploot_datum = 2026-05-15`, `status = concept`, and displays a countdown: **"Derdenverklaring due: 2026-06-12 (28 days from service)."**

- **REQ-001-002 | Automatic deadline reminder**
  - GIVEN a Beslag registered with `exploot_datum = 2026-05-01` and `status = concept`.
  - WHEN the daily compliance-check job runs (at 08:00 UTC, morning of 2026-05-31).
  - THEN the system identifies that today is **T-2 (2 days before 2026-06-12 deadline)** and queues a high-priority notification: **"Derdenverklaring overdue in 2 days for Medewerker X (Beslag-ID: YYY). Escalate to HR-manager."** The alert is sent to `payroll_admin` with CC to `hr_manager`.

- **REQ-001-003 | Overdue escalation**
  - GIVEN the same Beslag and today is 2026-06-13 (deadline passed).
  - WHEN the compliance-check runs.
  - THEN status remains `concept` AND a critical alert is queued: **"Derdenverklaring OVERDUE for Medewerker X since 2026-06-12. Employer liable for full debt (€XXX). Escalate immediately to HR leadership and legal."**

- **REQ-001-004 | Mark derdenverklaring sent**
  - GIVEN the derdenverklaring is drafted, reviewed, and dispatched on 2026-06-10 via registered post.
  - WHEN the user clicks "Mark sent" in the UI and selects dispatch method (post_aangetekend), date, and receipt tracking number.
  - THEN a new `BeslagCorrespondentie` row is created with `type = derdenverklaring`, `verzenddatum = 2026-06-10`, `verzendmethode = post_aangetekend`, `verzendbevestiging_uri = <PostNL tracking>`. The Beslag status transitions from `concept` to `actief` (now subject to monthly remittance).

---

### Feature 2: Statutory Exemption Calculation (Wvbvv 2021)

**Requirement ID:** REQ-002  
**Severity:** Critical (worker protection / hardship prevention)  

**Acceptance Criteria:**

- **REQ-002-001 | Standard Wvbvv formula for single no-dependents case**
  - GIVEN an employee Medewerker_X with:
    - Household: Alleenstaand (single, no children)
    - Monthly netto salary: €2.400,00
    - Housing cost (verified lease): €900,00
    - Health insurance premium (from master data): €188,42
  - WHEN the BVV calculation is triggered for June 2026 (peilmaand = 2026-06).
  - THEN the system computes:
    ```
    Base exemption (alleenstaand, Jun 2026 table): €1.055,00
    Housing supplement (€900 @ 13.9% × (900/1000) ≈ €125,00): +€125,00
    Care insurance (€188,42 @ 15.8%): +€29,70
    ─────────────────────────────────────
    Total BVV: €1.209,70
    ```
    A new `BeslagvrijeVoet` row is created with `bvv_bedrag = 1209.70`, `bvv_methode = wvbvv_standaard`, and the calculation is stored with audit-trail links to Wvbvv table version (2026-01-01 or later).

- **REQ-002-002 | Recalculation on employee-submitted proof**
  - GIVEN the same employee submits a scanned lease dated 2026-05-01 showing housing cost €1.200,00 (previous lease was €900).
  - WHEN the user clicks "Upload housing proof" in the BVV detail page and confirms recalculation.
  - THEN the system:
    1. Stores the scanned proof in `bvv_brondocument_uri`.
    2. Recalculates BVV for the next payroll period (if current period is locked) or prospectively:
       ```
       Base: €1.055,00
       Housing supplement (€1.200 @ 13.9% × (1200/1000) ≈ €167,00): +€167,00
       Care: +€29,70
       ─────────────────────────────────────
       New BVV: €1.251,70
       ```
    3. Creates an audit event in `audit-trail-payroll` with reason "Employee submitted housing proof; BVV recalculated."
    4. Applies the new exemption from the **first payroll period after verification** (not retroactively).

- **REQ-002-003 | HR override with mandatory justification**
  - GIVEN the calculated BVV is €1.209,70 but the HR-manager deems it insufficient (employee facing hardship).
  - WHEN the HR-manager clicks "Override exemption" and enters:
    - New exemption amount: €1.400,00
    - Justification: *"Employee has undeclared custody of elderly parent (no statutory allowance); rent €900 plus care assist €300/month (private arrangement, not on lease). Override to protect subsistence level."*
  - THEN:
    1. A new `BeslagvrijeVoet` row is created with `bvv_methode = handmatig_overruled_met_motivering`, `bvv_bedrag = 1400.00`, `handmatig_motivering = [text above]`.
    2. The `vastgesteld_door_id` and `vastgesteld_op` fields are populated.
    3. An **immutable** audit event (high-volume logging) is created in `audit-trail-payroll` with full override text.
    4. A notification is sent to `compliance_officer`: **"BVV override for Medewerker_X (+€190,30 vs. standard). Review justification and confirm within 48 hours."**

- **REQ-002-004 | Household-status changes trigger recalculation**
  - GIVEN a Medewerker's `leefvorm` is recorded as "alleenstaand" and linked BeslagvrijVoet is calculated.
  - WHEN the HR-module (employee-master) receives notice that the medewerker married on 2026-06-20.
  - THEN loonbeslag-admin's integration listener receives the event, marks any active `BeslagvrijeVoet` for July 2026 onwards as requiring recalculation, and queues a notification: **"Leefvorm change detected for Medewerker_X (married 2026-06-20). BVV will be recalculated for July 2026."**

- **REQ-002-005 | Formula transparancy for employee**
  - GIVEN an employee logs in to the self-service portal and views "Mijn Beslagen".
  - WHEN they click on a garnishment and expand "Beslagvrije voet berekening".
  - THEN they see:
    ```
    Beslagvrije voet (juni 2026): €1.209,70
    ─────────────────────────────
    Basis uitkering (alleenstaand)      €1.055,00
    + Huisvestingstoeslagen (€900)      +€125,00
    + Zorgverzekeringspremie            +€29,70
    ─────────────────────────────────
    Totaal                              €1.209,70
    
    [Edit > Request HR review]
    ```
    Any manual override is marked **(Aangepast door HR op [date])** with a link to request justification.

---

### Feature 3: Multi-Garnishment Precedence & Allocation

**Requirement ID:** REQ-003  
**Severity:** Critical (legal compliance / data correctness)  

**Acceptance Criteria:**

- **REQ-003-001 | Preferential garnishment first**
  - GIVEN Medewerker_Y has two active garnishments:
    - Beslag A: LBIO alimentatie (preferentie = preferent, volgnummer = 1, vordert €500).
    - Beslag B: Deurwaarder civiel (preferentie = concurrent, volgnummer = 2, vordert €300).
  - AND the available amount for garnishment (gross − BVV) is €800 in June 2026.
  - WHEN the monthly samenloop-allocation job runs for June 2026 payroll period.
  - THEN a `BeslagSamenloop` row is created:
    ```
    actieve_beslagen: [Beslag_A, Beslag_B]  (ordered by preferent first)
    totaal_beschikbaar_voor_beslag: €800,00
    verdeling_per_beslag: {
      Beslag_A: €500,00  (LBIO preferent, fully satisfied),
      Beslag_B: €300,00  (concurrent, remainder)
    }
    methodiek: "preferent_eerst_dan_concurrent_naar_rato"
    ```
    And the payroll-engine-nl deducts €800 total (€500 to LBIO, €300 to deurwaarder).

- **REQ-003-002 | Concurrent pro-rata allocation**
  - GIVEN Medewerker_Z has two concurrent garnishments (both deurwaarders, different creditors):
    - Beslag C: vordert €800, volgnummer = 1.
    - Beslag D: vordert €300, volgnummer = 2.
  - AND administratie-config `methodiek = "preferent_eerst_dan_concurrent_naar_rato"`.
  - AND available amount is €500 (both are concurrent, so pro-rata).
  - WHEN the samenloop job runs.
  - THEN:
    ```
    Total claim: €800 + €300 = €1.100
    Available: €500
    
    Beslag C: €500 × (€800 / €1.100) = €500 × 0.727 = €363,64
    Beslag D: €500 × (€300 / €1.100) = €500 × 0.273 = €136,36
    ─────────────────────────────────────────────────
    Total: €500,00
    ```
    `verdeling_per_beslag` reflects the pro-rata split.

- **REQ-003-003 | Chronological concurrent (alternative methodiek)**
  - GIVEN same setup as REQ-003-002, BUT administratie-config `methodiek = "strikt_chronologisch_oudst_eerst"`.
  - WHEN the samenloop job runs.
  - THEN:
    ```
    Beslag C (older volgnummer): €500,00 (fully satisfied from available amount)
    Beslag D (newer): €0,00 (nothing left)
    ─────────────────────────────
    Total: €500,00
    ```
    Next month (July), if Beslag C is satisfied, Beslag D starts receiving.

- **REQ-003-004 | Samenloop auditable in export**
  - GIVEN an accountant exports garnishment data for Q2 2026.
  - WHEN they download the Excel summary sheet.
  - THEN they see a tab "Samenloop allocation" showing, per medewerker per month:
    - Netto salary.
    - BVV applied.
    - Available for garnishment.
    - List of active beslagen + allocated amount per Beslag.
    - Methodiek used (pro-rata vs. chrono).

---

### Feature 4: Beslag on Wsnp-Protected Employee (Conflict Detection)

**Requirement ID:** REQ-004  
**Severity:** Critical (worker protection)  

**Acceptance Criteria:**

- **REQ-004-001 | Automatic Wsnp conflict detection**
  - GIVEN a medewerker is entered into Wsnp (schuldsaneringsregeling, Dutch personal bankruptcy protection) on 2026-06-01.
  - WHEN the HR-module notifies loonbeslag-admin of the `wsnp_toelating_datum = 2026-06-01`.
  - THEN loonbeslag-admin:
    1. Identifies all active concurrent beslagen (Beslag status = actief AND preferentie = concurrent) for this medewerker.
    2. Automatically transitions them to `status = opgeschort` effective 2026-06-01.
    3. Creates a new `BeslagCorrespondentie` row (`type = aansprakelijkheidsbetwisting`) with a pre-filled template letter to each concurrent beslaglegger explaining the Wsnp status and suspension.
    4. Queues a notification to `payroll_admin`: **"Medewerker_X entered Wsnp (2026-06-01). Concurrent beslagen A, B, C automatically suspended. Letters queued for dispatch."**

- **REQ-004-002 | Preferent beslagen unaffected**
  - GIVEN same scenario, AND the medewerker also has a preferent LBIO alimentatie beslag.
  - WHEN the Wsnp event arrives.
  - THEN the LBIO beslag remains in `status = actief` (alimentatie is excluded from Wsnp suspension per Dutch law).
  - Only concurrent beslagen are suspended.

- **REQ-004-003 | Incoming beslag on Wsnp employee**
  - GIVEN a medewerker is under active Wsnp (status = under protection).
  - WHEN a new court order (exploot) arrives to garnish their wages.
  - THEN the system:
    1. Creates the Beslag in `status = concept`.
    2. On transition to `actief` (after derdenverklaring), detects the Wsnp status.
    3. Automatically suspends the beslag and sends the aansprakelijkheidsbetwisting letter.
    4. Alerts `hr_manager`: **"New beslag received for Wsnp-protected employee; automatically suspended per law."**

---

### Feature 5: Monthly Remittance via SEPA Batch

**Requirement ID:** REQ-005  
**Severity:** Critical (financial integrity)  

**Acceptance Criteria:**

- **REQ-005-001 | Generate SEPA batch at payroll completion**
  - GIVEN a payroll run for administratie_A, June 2026 is completed (status = finalized).
  - AND there are three active garnishments with allocations:
    - Beslag_A: €450 → LBIO (NL91ABNA0417164300).
    - Beslag_B: €200 → Deurwaarder (NL65PBNK6789123456).
    - Beslag_C: €100 → Gemeente (NL20RABO1234567890).
  - WHEN the user clicks "Generate SEPA remittance batch" in the Payroll completion UI.
  - THEN the system:
    1. Creates three `BeslagAfdracht` rows (status = gepland).
    2. Generates a **SEPA pain.001.003.03 XML file** with:
       ```xml
       <CstmrCdtTrfInitn>
         <!-- Payment Info -->
         <GrpHdr>
           <MsgId>SEPA-20260630-GARNISH-ADM-A</MsgId>
           <CreDtTm>2026-06-30T23:59:59Z</CreDtTm>
         </GrpHdr>
         <!-- Three credit transfers -->
         <PmtInf>
           <PmtInfId>SEPA-ADM-A-2026-06-001</PmtInfId>
           <PmtMtd>TRF</PmtMtd>
           <!-- Beslag_A -->
           <CdtTrfTxInf>
             <Amt>€450,00</Amt>
             <Cdtr>LBIO Amsterdam</Cdtr>
             <CdtrAcct>NL91ABNA0417164300</CdtrAcct>
             <RmtInf>"LBIO-202601-FL2024000123" oder "Salary garnishment May [Employee] ref. 12345"</RmtInf>
           </CdtTrfTxInf>
           <!-- Beslag_B -->
           <CdtTrfTxInf>
             <Amt>€200,00</Amt>
             <Cdtr>Deurwaarderskantoor De Wit</Cdtr>
             <CdtrAcct>NL65PBNK6789123456</CdtrAcct>
             <RmtInf>"DW-2024-5678" oder "Salary garnishment May [Employee] ref. 5678"</RmtInf>
           </CdtTrfTxInf>
           <!-- Beslag_C -->
           ...
         </PmtInf>
       </CstmrCdtTrfInitn>
       ```
    3. The file is saved in document-vault and linked from the Payroll run record.
    4. User can download or forward directly to openconnector for bank submission.

- **REQ-005-002 | IBAN and reference validation**
  - GIVEN the SEPA file generation in REQ-005-001.
  - WHEN the system processes each Beslag's IBAN.
  - THEN:
    1. Every IBAN is validated (IBAN checksum algorithm).
    2. If invalid, the file generation **fails** with error: *"Beslag_B has invalid IBAN (NL65PBNK...). Correct in system and retry."*
    3. User corrects the IBAN in the Beslag record and re-triggers SEPA generation.
    4. The remittance reference includes the `beslaglegger_kenmerk` (e.g., "LBIO-202601-FL2024000123") as the first part of the RmtInf field (ISO 20022 unstructured).

- **REQ-005-003 | Payment status reconciliation**
  - GIVEN the SEPA file is submitted to the bank on 2026-07-01.
  - WHEN the bank returns a **payment status report (pain.002 or camt.054)** on 2026-07-05 indicating:
    - Beslag_A: ACCC (accepted, cleared).
    - Beslag_B: RJCT (rejected, invalid IBAN).
    - Beslag_C: ACCC.
  - THEN the system:
    1. Parses the status file (openconnector delivers it to loonbeslag-admin).
    2. Updates `BeslagAfdracht` rows:
       - Beslag_A: status = `uitgevoerd`, `afdrachtdatum = 2026-07-05`, `betalingsreferentie = <bank-ref>`.
       - Beslag_B: status = `mislukt`, `status_opmerking = "IBAN rejected by bank (2026-07-05)."`, `bedrag_afgedragen = null`.
       - Beslag_C: status = `uitgevoerd`, ...
    3. Creates an audit event in `audit-trail-payroll` for each update.
    4. Queues a notification: **"Beslag_B remittance failed (invalid IBAN). Correct IBAN for Deurwaarder De Wit and resubmit payment."**
    5. The ingehouden amount (€200) is placed on a temporary "Garnishment transit / escrow" GL account pending resolution.

- **REQ-005-004 | Retry failed remittance**
  - GIVEN Beslag_B's IBAN has been corrected to NL65PBNK9876543210 in the system.
  - WHEN the payroll admin clicks "Retry payment" on the failed Beslag_B afdracht.
  - THEN:
    1. A new SEPA file with only Beslag_B is generated.
    2. The afdracht status returns to `gepland`.
    3. User submits the file; bank processes and returns ACCC.
    4. Afdracht status updates to `uitgevoerd`; the transit GL entry is cleared.

---

### Feature 6: Standardized Correspondence Templates

**Requirement ID:** REQ-006  
**Severity:** High (legal compliance / risk mitigation)  

**Acceptance Criteria:**

- **REQ-006-001 | Derdenverklaring pre-fill**
  - GIVEN a Beslag in `status = concept` has been registered (exploot scanned, metadata extracted).
  - WHEN the user clicks "Create statutory declaration" (derdenverklaring).
  - THEN the system opens a **document-editor** (or PDF preview) with a pre-filled Dutch template including:
    ```
    [EMPLOYER HEADER]
    
    VERKLARING VAN DERDEBESLAGENE
    (Artikel 476a Wetboek van Burgerlijke Rechtsvordering)
    
    Aan: [Beslaglegger naam + adres]
    
    Re: Loonbeslag exploot van [exploot_datum]
    
    Ondergetekende, [Employer name], gevestigd te [address], 
    verklaard onder ede / waarheid dat:
    
    1. De betrokken werknemer, [Medewerker voornaam achternaam], 
       geboren [DOB], met burgerservicenummer [BSN],
       staat bij mij in dienst sinds [dienstverband_start].
    
    2. Het brutoloon bedraagt maandelijks € [bruto_maandloon].
    
    3. Reeds vastgestelde beslagen:
       - [Beslag_1: beslaglegger, bedrag, exploot_datum]
       - [Beslag_2: ...] (if applicable)
    
    4. Beslagvrije voet (Wet vereenvoudiging beslagvrije voet):
       De beschermde inkomsten bedragen: € [bvv_bedrag]
       (berekend op basis van: huishoudenstype [leefvorm], 
        kinderen [aantal_kinderen_tlv], woningkosten € [woonkosten], 
        ziektekostenpremie € [nominale_premie_zvw])
    
    5. Beschikbaar voor beslag: € [netto - bvv]
    
    6. Vanaf [vanaf_maand] zal maandelijks € [allocated_bedrag] 
       worden afgedragen via [beslaglegger_iban].
    
    Deze verklaring wordt gegeven op [datum].
    
    [Signature line]
    ```
  - User can edit any field before finalizing. When finalized, a PDF is generated and stored in `document-vault`.

- **REQ-006-002 | Monthly detail statement**
  - GIVEN June 2026 payroll is finalized and afdrachten are recorded.
  - WHEN the system generates the monthly remittance statement (template_type = maandelijkse_specificatie).
  - THEN each beslaglegger receives a summary:
    ```
    MAANDELIJKSE SPECIFICATIE SALARISBESLAGUPDATING
    
    Medewerker: [Name], BSN [BSN]
    Periode: Juni 2026
    
    Brutoloon: € 2.600,00
    Beslagvrije voet: € 1.209,70
    Beschikbaar voor beslag: € 1.390,30
    
    Afdrachten:
    - LBIO (LBIO-202601-FL2024000123): € 450,00 ✓ (afgedragen 2026-07-05)
    - Deurwaarder De Wit (DW-2024-5678): € 300,00 ✓ (afgedragen 2026-07-05)
    
    Totaal afgedragen: € 750,00
    
    Saldo vorderingsbedrag: € [remaining_claim]
    ```
  - The statement is sent via the beslaglegger's preferred method (post, email, Digipoort) as defined in the Beslag record.

- **REQ-006-003 | Release letter (eindverklaring) on full payment**
  - GIVEN a Beslag has `vorderingsbedrag_resterend = 0` after the latest afdracht.
  - WHEN the system processes the final payment AND the vorderingsbedrag is fully satisfied.
  - THEN a `BeslagCorrespondentie` row is automatically created with `type = eindverklaring`:
    ```
    EINDVERKLARING VORDERINGSBEDRAG
    
    Aan: [Beslaglegger]
    Re: Einde salarisgarnering [Medewerker name], BSN [BSN]
    
    Hierbij wordt u medegedeeld dat het vorderingsbedrag van 
    € [original_bedrag] is volledig voldaan.
    
    Totaal afgedragen: € [total_remitted] over [num_months] maanden.
    
    Het salarisgarnering wordt per [last_payment_date] beëindigd.
    Hierna zijn geen verdere inhoudingen meer plaats.
    
    Medewerker kan zich wenden tot u ter bevestiging van einde schuld.
    
    [Date, Signature]
    ```
  - The template is dispatched immediately (or queued for scheduled dispatch).

- **REQ-006-004 | Responsibility-dispute letter (aansprakelijkheidsbetwisting)**
  - GIVEN a Beslag arrives for a medewerker already under Wsnp (REQ-004).
  - WHEN the system auto-suspends the beslag.
  - THEN a `BeslagCorrespondentie` row is created with `type = aansprakelijkheidsbetwisting`:
    ```
    AANSPRAKELIJKHEIDSBETWISTING
    
    Aan: [Beslaglegger]
    Re: Medewerker [Name] — schuldsaneringsregeling van kracht
    
    Dit bedrijf maakt bezwaar tegen uw salarisbeslagupdating vanwege 
    het volgende:
    
    De betrokken werknemer is onder curatele geplaatst in het kader van 
    de Wet Schuldsanering Natuurlijke Personen (Wsnp) sinds [wsnp_datum].
    
    Op grond van de Wsnp zijn alle vorderingen (inclusief deze) 
    opgeschort. Verdere inhoudingen zijn rechtens niet mogelijk totdat 
    de schuldsaneringsregeling is beëindigd.
    
    Wij verzoeken u deze salarisbeslagupdating aan te passen.
    
    [Date, Signature]
    ```

---

### Feature 7: AVG Confidentiality & Gelaagde Toegang

**Requirement ID:** REQ-007  
**Severity:** Critical (GDPR compliance / data protection)  

**Acceptance Criteria:**

- **REQ-007-001 | Employee sees full detail**
  - GIVEN a medewerker (employee) logs into the self-service portal (Mijn HR).
  - WHEN they navigate to "Mijn Beslagen".
  - THEN they see the full garnishment register for themselves only:
    ```
    Actieve Beslagen
    
    LBIO — Alimentatie
    Bedrag: € 450/maand
    Beslaglegger: LBIO Amsterdam
    Vorderingsbedrag resterend: € 10.800,00
    Beslagvrije voet toegepast: € 1.209,70
    Exploot-datum: 2026-05-01
    
    [Details button] [History button] [Request review button]
    ```
  - On the payslip, the deduction line reads:
    ```
    Inhouding loonbeslag: € 450,00
    (afdracht LBIO inzake alimentatie, referentie LBIO-202601-FL2024000123)
    ```

- **REQ-007-002 | Non-authorized manager sees generic line**
  - GIVEN a team-manager (leidinggevende) who is NOT assigned the `hr_manager` or `payroll_admin` role.
  - WHEN they pull up a team payroll summary or a single employee's payslip via a manager-portal.
  - THEN any garnishment lines are displayed as:
    ```
    Overige loonheffing: € 450,00
    ```
    No mention of "beslag," "creditor," or amount detail.

- **REQ-007-003 | HR manager sees full context**
  - GIVEN an HR manager with `hr_manager` role.
  - WHEN they open an employee detail page and click "Beslagen".
  - THEN they see the full Beslag register (same as payroll_admin) plus buttons to:
    - Approve BVV overrides.
    - Escalate deadline misses.
    - Support employee requests (hardship, proof submission).

- **REQ-007-004 | API returns 404 to unauthorized callers**
  - GIVEN an unauthorized user (no payroll_admin, no hr_manager roles) attempts:
    ```
    GET /api/v1/beslagen/beslag-id-123
    ```
  - WHEN the request is processed.
  - THEN:
    1. The API returns HTTP 404 (Not Found) — hiding the existence of the garnishment.
    2. An audit event is logged in `BeslagVertrouwelijkheidsLog`:
       ```
       beslag_id: beslag-id-123
       gebruiker_id: [requesting user]
       toegangstype: raadpleging (unauthorized attempt)
       tijdstip: [timestamp]
       ip_adres: [user IP]
       ```
    3. If > 3 such attempts in 1 hour from same user, a security alert is queued to `compliance_officer`.

- **REQ-007-005 | Confidentiality log every access**
  - GIVEN a payroll_admin opens a Beslag detail page.
  - WHEN the page loads.
  - THEN a `BeslagVertrouwelijkheidsLog` entry is created:
    ```
    beslag_id: [id]
    gebruiker_id: [admin-user-id]
    toegangstype: raadpleging
    rollen_op_moment: ["payroll_admin"]
    rechtvaardiging: "payroll_admin role required for garnishment management"
    ip_adres: [user IP]
    tijdstip: [now]
    ```
  - Similarly, when exporting garnishment data, a log entry records `toegangstype: export` + list of beslagen exported.

- **REQ-007-006 | Report hiding for non-authorized**
  - GIVEN a team-manager generates a "Team Salary Costs" report for their department.
  - WHEN the report is rendered.
  - THEN any lines related to garnishments are omitted OR shown as "Overige loonheffing" without detail, regardless of whether the manager's role has payroll visibility.

---

### Feature 8: Aansprakelijkheidsrisico-Monitor (Liability Risk Alerts)

**Requirement ID:** REQ-008  
**Severity:** High (risk mitigation)  

**Acceptance Criteria:**

- **REQ-008-001 | Incomplete derdenverklaring warning**
  - GIVEN Beslag_1 (LBIO, registered on 2026-05-01) has a derdenverklaring dispatched on 2026-06-10.
  - AND THEN Beslag_2 (deurwaarder, registered on 2026-06-15) arrives for the same medewerker.
  - WHEN the system detects that Beslag_1's derdenverklaring may not have mentioned Beslag_2 (because Beslag_2 arrived after dispatch).
  - THEN a warning is displayed to payroll_admin:
    ```
    ⚠️ POTENTIËLE AANSPRAKELIJKHEID
    
    De derdenverklaring voor Beslag LBIO (2026-06-10) vermeldde 
    mogelijk niet het daarna ontvangen Beslag deurwaarder (2026-06-15).
    
    Aanbeveling: Supplementaire verklaring versturen met vermelding 
    van nieuw Beslag aan LBIO (vervolgbrief).
    ```

- **REQ-008-002 | Underremittance alert**
  - GIVEN Beslag_X allocates €600/month to creditor Y.
  - WHEN the actual afdracht for June 2026 is recorded as €400 (due to cash-flow issue or erroneous allocation).
  - THEN an alert is queued:
    ```
    ⚠️ ONDERAFDRACHT GEDETECTEERD
    
    Beslag [ID]: Geplande afdracht €600, werkelijke afdracht €400.
    Verschil: €200 (creditor korting/uitstel niet vastgesteld).
    
    Werkgever risico: civielrechtelijke aansprakelijkheid als 
    onderafdracht niet gerechtvaardigd.
    ```

- **REQ-008-003 | Deadline-imminent escalation**
  - GIVEN a Beslag is T-3 from derdenverklaring deadline.
  - WHEN the daily compliance check runs.
  - THEN the system sends a high-priority alert to both `payroll_admin` and `hr_manager`:
    ```
    🔴 DERDENVERKLARING DEADLINE in 3 DAGEN
    
    Beslag: [ID], Medewerker: [Name]
    Deadline: [date] (28 days from exploot service)
    
    Verstuurd: Nee
    
    ONMIDDELLIJKE ACTIE VEREIST
    ```

---

### Feature 9: Export & Accountantscontrole (Audit Export)

**Requirement ID:** REQ-009  
**Severity:** High (auditability / compliance verification)  

**Acceptance Criteria:**

- **REQ-009-001 | Quarterly export for auditors**
  - GIVEN an accountant (role = `auditor`) requests an export of garnishment data for Q2 2026 (April–June).
  - WHEN they click "Export for audit" and select date range + format (ZIP).
  - THEN the system:
    1. Identifies all Beslagen active or finalized in the period.
    2. For each Beslag, collects:
       - Beslag summary (id, medewerker, beslaglegger, dates, status).
       - Scanned exploot (if available).
       - All `BeslagvrijeVoet` calculations for the period (showing formula inputs, outputs, overrides with justification).
       - All `BeslagAfdracht` records (showing withheld, remitted, dates, status).
       - All `BeslagCorrespondentie` (derdenverklaring, monthly statements, letters).
       - `BeslagSamenloop` allocation records (showing precedence, pro-rata splits).
    3. Generates a **summary Excel sheet** with:
       - Total beslagen active in period.
       - Total amount withheld.
       - Total amount remitted (by creditor).
       - Compliance checklist (derdenverklaringen filed, afdrachten on time, no underremittances).
       - Exceptions / alerts.
    4. Packages all into a **ZIP file**, encrypted with a single-use passphrase.
    5. Logs the export in `BeslagVertrouwelijkheidsLog` with `toegangstype: export`.

- **REQ-009-002 | Compliance summary sheet**
  - GIVEN the export ZIP is opened.
  - WHEN the accountant reviews the **"Compliance checklist"** tab in the Excel.
  - THEN they see:
    ```
    Q2 2026 — Garnishment Compliance Summary
    ─────────────────────────────────────────
    Total Beslagen (active June): 5
    Total withheld (Q2):          € 4.750,00
    Total remitted:              € 4.500,00
    Difference (unexplained):    € 250,00 [⚠️ investigate]
    
    Derdenverklaringen filed: 5/5 ✓
    Afdrachten on-time: 15/15 ✓
    Overdue statements: 0 ✓
    Liability alerts: 1 [Beslag#3 underremittance]
    
    Samenloop allocations tested: 3 scenarios ✓
    ```

---

### Feature 10: Retention & Automatic Destruction (Bewaartermijn)

**Requirement ID:** REQ-010  
**Severity:** High (legal compliance / data minimization / GDPR)  

**Acceptance Criteria:**

- **REQ-010-001 | 7-year retention rule**
  - GIVEN a Beslag ends (status = `afgelost`, `ingetrokken`) on 2020-04-01.
  - AND the associated medewerker is terminated on 2025-06-01.
  - WHEN the retention-check job runs on 2032-07-01 (7 years after the later date: 2025-06-01).
  - THEN the system:
    1. Marks the Beslag + all related documents for destruction.
    2. Initiates a **pseudo-anonymization process:**
       - Scan files (exploot, letters) are hashed and de-indexed.
       - Personal details (medewerker name, beslaglegger name, IBAN) are removed from searchable records.
       - Numeric summaries (total withheld, total remitted, dates) are retained for statistical purposes.
    3. Schedules the files for shredding (irreversible deletion) after a 30-day hold.

- **REQ-010-002 | Employee right to access**
  - GIVEN a terminated employee (2025-06-01 exit) requests copies of their old garnishment documents (GDPR Art. 15 / DSR request).
  - WHEN the compliance officer receives the request.
  - THEN, even though the normal retention period would be up in 2032, the system:
    1. Allows compliance officer to generate a **subject data export** (DSR response) containing all Beslag + correspondence records for the employee.
    2. Does NOT initiate destruction for that Beslag until the statutory 30-day DSR response window closes AND the standard 7-year period has elapsed.

- **REQ-010-003 | Retention policy transparency**
  - GIVEN a medewerker views an old/finalized Beslag in the self-service portal.
  - WHEN they click "Policy & retention".
  - THEN they see:
    ```
    Betreffende Beslag: [ID]
    Status: Afgelost (2024-12-31)
    
    Bewaarduur: 7 jaar na einde beslag of dienstverband 
    (welke later valt)
    
    In uw geval:
    - Beslag beëindigd: 2024-12-31
    - Dienstverband beëindigd: 2025-03-15 ← [later date]
    - Bewaarperiode eindigt: 2032-03-15
    - Dagen resterend: [calculated]
    
    U kunt documenten tot [date] aanvragen.
    Na [date] worden persoonlijke gegevens verwijderd en 
    alleen statistieken behouden.
    ```

---

## Cross-Functional Requirements

### Performance
- **REQ-PERF-001:** BVV calculation (Wvbvv formula) completes in < 100 ms for any employee.
- **REQ-PERF-002:** Samenloop allocation (multi-beslag precedence) runs in < 500 ms for up to 10 beslagen per medewerker.
- **REQ-PERF-003:** SEPA batch generation (pain.001) completes in < 2 seconds for up to 1000 afdracht records.

### Auditability
- **REQ-AUDIT-001:** Every read/write to a Beslag or BeslagvrijeVoet is logged in `audit-trail-payroll` with user, timestamp, operation, old/new values.
- **REQ-AUDIT-002:** `BeslagVertrouwelijkheidsLog` is immutable; logs cannot be edited or deleted (soft-delete only).

### Scalability
- **REQ-SCALE-001:** loonbeslag-admin must support administraties with up to 500 concurrent beslagen per month without performance degradation.

### Integration
- **REQ-INT-001:** payroll-engine-nl receives `BeslagSamenloop` data via internal event/API and applies deductions correctly.
- **REQ-INT-002:** BVV master-data changes (leefvorm, kinderen, woonkosten) in employee-master trigger loonbeslag-admin recalculation listeners.

---

## Glossary

| Term | Definition |
|------|-----------|
| **Beslag** | Court-ordered wage garnishment (Dutch legal term: "loonbeslag"). One exploot per record. |
| **Beslaglegger** | Creditor or authority collecting the garnishment (LBIO, Belastingdienst, deurwaarder, etc.). |
| **Beslagvrije voet (BVV)** | Statutory exemption amount (portion of salary protected from garnishment per Wvbvv 2021). |
| **Derdenverklaring** | Statutory declaration filed by employer (third party) within 28 days of service, confirming employment, income, and existing garnishments. |
| **Exploot** | Original court order / bailiff notice served on the employer. |
| **Samenloop** | Multi-garnishment scenario; legal precedence and allocation rules apply. |
| **Wsnp** | Wet Schuldsanering Natuurlijke Personen (Dutch personal debt-relief law); suspends concurrent garnishments. |
| **Wvbvv** | Wet vereenvoudiging beslagvrije voet (2021); statutory exemption formula. |


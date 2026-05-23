---
name: uitzend-flexpool-integration-specs
title: Specifications — Uitzendkrachten & Flexpool-integratie
version: 0.1.0
status: draft
---

# Specifications: Uitzendkrachten & Flexpool-integratie

## Requirements Overview

| Req | Domain | Priority | Scenario |
|-----|--------|----------|----------|
| REQ-UZI-001 | Data Model | MUST | Inhuur is geen Employee |
| REQ-UZI-002 | Compliance | MUST | Bureau-validatie SNA-keurmerk |
| REQ-UZI-003 | Compliance | MUST | Inlenersbeloning-onderbouwing verplicht |
| REQ-UZI-004 | Integration | SHOULD | Inlenersbeloning-revisie bij CAO-aanpassing |
| REQ-UZI-005 | Compliance | MUST | Fase-progressie ABU tracking |
| REQ-UZI-006 | Compliance | SHOULD | WAB-onderscheid contract type |
| REQ-UZI-007 | Workflow | MUST | Urenregistratie met manager-approval |
| REQ-UZI-008 | Finance | MUST | Maandelijkse factuur-matching |
| REQ-UZI-009 | Compliance | SHOULD | G-rekening-betaling |
| REQ-UZI-010 | Analytics | SHOULD | TCO-dashboard inhuur vs vast |

---

## Requirement: REQ-UZI-001 — Inhuur is geen Employee

**Domain:** Data Model & Isolation  
**Priority:** MUST  
**Source:** WAADI, WAB (inhuurkrachten hebben geen directe arbeidsrelatie)

### Scenario: New InhuurOpdracht must not create Employee

**GIVEN** an inhuur-coordinator is creating a new InhuurOpdracht record  
**WHEN** the coordinator fills in kandidaat_naam, bureau, manager, functie, startdatum, and clicks "Opslaan"  
**THEN**
- The system creates an InhuurOpdracht record with status=draft
- NO Employee record is created in employee-master
- The kandidaat does NOT appear in the payroll-run candidate list
- The kandidaat does NOT receive a verlof-saldo or performance-cycle invitation
- The kandidaat DOES appear in capaciteitsplanning and organogrammen with a badge "Ingehuurd via {bureau_naam}"
- The coordinator sees confirmation message: "Opdracht opgeslagen; kandidaat is ingehuurd, niet in eigen dienst"

### Scenario: Existing Employee cannot be marked as hired

**GIVEN** an inhuur-coordinator attempts to create an InhuurOpdracht  
**WHEN** the coordinator selects a kandidaat_naam that already exists as an Employee record  
**THEN**
- The system prevents the link with error message: "Kandidaat kan niet ingehuurd worden; deze persoon staat reeds in dienst"
- Recommendation: Use Overplaatsing workflow if converting an employee to flex, or create a separate fee-for-service arrangement

---

## Requirement: REQ-UZI-002 — Bureau-validatie SNA-keurmerk vóór opdracht

**Domain:** Compliance & Risk Mitigation  
**Priority:** MUST  
**Source:** Invorderingswet art. 34, ketenaansprakelijkheid loonheffing/btw

### Scenario: SNA-keurmerk validation blocks invalid bureau

**GIVEN** an inhuur-coordinator is creating a new InhuurOpdracht  
**WHEN** the coordinator selects a bureau and clicks "Opslaan"  
**THEN**
- The system checks `Bureau.sna_keurmerk_status` and `Bureau.sna_vervaldatum`
- If `sna_keurmerk_status = onbekend` OR `sna_vervaldatum < today` OR missing `g_rekening_iban`:
  - The save is BLOCKED
  - Error message: "Bureau voldoet niet aan ketenaansprakelijkheid-eisen — risico op aansprakelijkstelling voor loonheffing/btw door Belastingdienst. Controleer SNA-status en G-rekening configuratie."
  - User is directed to the Bureau detail panel to fix configuration
- If all checks pass:
  - InhuurOpdracht is saved
  - User sees confirmation message

### Scenario: Bureau SNA-status expires after opdracht creation

**GIVEN** an InhuurOpdracht is active with a bureau that has a valid SNA-keurmerk  
**WHEN** the SNA-keurmerk expires (sna_vervaldatum reaches today)  
**THEN**
- The system flags the bureau in the "Bureaus & relaties" tab with a red warning icon: "SNA-keurmerk verlopen; alle actieve opdrachten van dit bureau zijn vergrendeld totdat het keurmerk is verlengd"
- All new InhuurOpdracht creations for this bureau are blocked
- Coordinator receives a digest task: "Vernieuw SNA-keurmerk voor {bureau_naam} — {n} actieve opdrachten geblokkeerd"
- Once coordinator updates Bureau.sna_vervaldatum to a future date, the block is lifted

---

## Requirement: REQ-UZI-003 — Inlenersbeloning-onderbouwing verplicht

**Domain:** Compliance & Wage Equality  
**Priority:** MUST  
**Source:** WAADI art. 4 (inlenersbeloning) — 6-element rule

### Scenario: Onderbouwing required before status=actief

**GIVEN** an inhuur-coordinator is creating a new InhuurOpdracht  
**WHEN** the coordinator fills in uurtarief, functie_titel, and referent_eigen_functie  
**AND** the coordinator clicks "Opslaan"  
**THEN**
- The system allows status to remain as "draft" without onderbouwing
- But if the coordinator tries to change status to "actief" OR to save after setting the status field to "actief":
  - The system checks for a linked InlenersBeloningOnderbouwing with all 6 elements present
  - If missing: save is blocked with error message: "Inlenersbeloning-onderbouwing ontbreekt. Vul in: loon, ADV, toeslagen, periodieken, vergoedingen, vakantiebijslag."
  - If present and geldig_tot >= today: status can be set to "actief"

### Scenario: Coordinator creates and links onderbouwing

**GIVEN** a coordinator has created an InhuurOpdracht in draft status  
**WHEN** the coordinator clicks "Revisie inlenersbeloning" and fills in a new InlenersBeloningOnderbouwing form  
**AND** the coordinator uploads supporting documentation (PDF scan of raamovereenkomst, CAO-staffel extract, etc.)  
**AND** the coordinator saves the onderbouwing  
**THEN**
- The system validates that all 6 elements are present (loon_per_uur >= CAO-minimum for referent-functie)
- The onderbouwing is persisted with geldig_tot = today + 12 months
- The InhuurOpdracht is linked to this onderbouwing (inlenersbeloning_onderbouwing_ref)
- The coordinator can now change status to "actief" and save
- Manager receives notification: "Nieuwe inhuur-opdracht {opdracht_nr} {kandidaat_naam} in afwachting van goedkeuring" with TCO details

### Scenario: Onderbouwing valid_tot approaches expiration

**GIVEN** an active InhuurOpdracht is linked to an InlenersBeloningOnderbouwing  
**WHEN** the onderbouwing's geldig_tot is < 30 days away  
**THEN**
- The InhuurOpdracht detail panel shows a yellow warning: "Inlenersbeloning-onderbouwing vervalt op {geldig_tot}. Revisie aanbevolen."
- The coordinator receives a task: "Revisie inlenersbeloning-onderbouwing opdracht {opdracht_nr}" 30 days before expiration
- If geldig_tot is reached and no new onderbouwing is linked:
  - The InhuurOpdracht status changes to "verlopen_onderbouwing" (read-only state)
  - The kandidaat cannot be billed further until onderbouwing is renewed

---

## Requirement: REQ-UZI-004 — Inlenersbeloning-revisie bij CAO-aanpassing

**Domain:** Integration & Compliance  
**Priority:** SHOULD  
**Source:** Ongoing wage equality (WAADI dynamism)

### Scenario: CAO mutation triggers onderbouwing-revisie task

**GIVEN** the payroll-engine-nl module processes a CAO-staffel update  
**AND** the mutation affects one of the referent-functieprofiel records used in active InhuurOpdracht entries  
**WHEN** the payroll-engine publishes a `cao-staffel-mutation` event (async)  
**THEN**
- The system finds all active InhuurOpdracht records where referent_eigen_functie_ref matches the mutated FunctieProfiel
- For each match, the system creates a task for the inhuur-coordinator:
  - Title: "Revisie inlenersbeloning opdracht {opdracht_nr} — CAO-wijziging {functie_titel}"
  - Description: "Referent-functie {functie_titel} is in CAO bijgewerkt. Controleer of de inlenersbeloning-onderbouwing nog aansluit en actualiseer bij nodig"
  - DueDate: today + 7 days
- The InhuurOpdracht.inlenersbeloning_onderbouwing_ref is marked as "pending_review" (visual indicator)

### Scenario: Coordinator revises onderbouwing post-CAO-change

**GIVEN** a coordinator receives a revisie-task after a CAO-staffel mutation  
**WHEN** the coordinator opens the InhuurOpdracht detail and clicks "Revisie inlenersbeloning"  
**THEN**
- A new InlenersBeloningOnderbouwing form is opened with pre-filled data from the old onderbouwing
- The coordinator is guided to update loon_per_uur based on the new CAO-staffel
- The new onderbouwing is created with revised_from_id pointing to the previous version (audit trail)
- The InhuurOpdracht is updated to link to the new onderbouwing
- The coordinator clicks [Informeer bureau] → an email is sent to the bureau's contact requesting tariff confirmation based on the new onderbouwing
- The coordinator's task is marked complete

---

## Requirement: REQ-UZI-005 — Fase-progressie ABU (A→B→C) tracking

**Domain:** Compliance & CAO Progression  
**Priority:** MUST  
**Source:** ABU-CAO (52-week threshold since 1-1-2024, fase B max 6 contracts in 4 years)

### Scenario: Automatic fase-A→fase-B detection on week 52

**GIVEN** an active InhuurOpdracht with cao_toepassing=ABU and fase=A  
**WHEN** a UrenRegistratieFlex record is registered and approved that brings the total gewerkte_uren to >= 52 weeks (2080 hours / 40 per week, since 1-1-2024)  
**THEN**
- The system automatically updates InhuurOpdracht.fase from "A" to "B"
- The system updates InhuurOpdracht.updated_at and records the phase-change event in an audit log
- The system creates two tasks:
  1. For inhuur-coordinator: "Fase-overgang: {kandidaat_naam} (opdracht {opdracht_nr}) is overgegaan naar fase B (ABU). Controleer StiPP-Plus pensoen-inschrijving en WW-doorbouw."
  2. For inhurende_manager: "Fase-overgang {kandidaat_naam} is nu fase B — recht op pensioen StiPP-Plus en WW-doorbouw van kracht"
- Both tasks include a link to the InhuurOpdracht detail + explanatory text of fase-B implications (max 6 contracts in 4 years, pension accrual, etc.)
- The coordinator and manager are notified (task + email digest)

### Scenario: Fase-B contract count enforcement

**GIVEN** an inhuur-coordinator is creating a new InhuurOpdracht for a kandidaat who is currently in fase-B with an ABU-bureau  
**WHEN** the coordinator tries to save the opdracht  
**THEN**
- The system counts how many active/completed contracts this kandidaat has had in the last 4 years (with the same ABU bureau)
- If count >= 6 (excluding the current new one):
  - Save is BLOCKED with warning: "Kandidaat bereikt ABU-fase-B contractlimiet (6 contracten in 4 jaar). Overgang naar fase C nodig; controleer bescherming tegen onrechtmatige beëindiging (fase C = bepaalde tijd onbepaald)."
- If count < 6: save proceeds

### Scenario: NBBU fase-progression (1→2→3→4)

**GIVEN** an active InhuurOpdracht with cao_toepassing=NBBU  
**WHEN** uren are registered that cross NBBU fase-thresholds (39 weeks for 1→2, 78 for 2→3, 156 for 3→4)  
**THEN**
- The system automatically detects the threshold crossing and updates InhuurOpdracht.fase
- Tasks are created for coordinator + manager with NBBU-specific implications (e.g., "Fase 3: recht op doorbouw arbeidsmarktpositie")

---

## Requirement: REQ-UZI-006 — WAB-onderscheid contract bepaald/onbepaald

**Domain:** Compliance & Cost Analysis  
**Priority:** SHOULD  
**Source:** WAB 2020 (lage vs. hoge WW-premie + transitievergoeding)

### Scenario: Contract-type field on payroll-opdracht

**GIVEN** an inhuur-coordinator is creating an InhuurOpdracht with a payroll-bureau (type=payroll)  
**WHEN** the coordinator opens the opdracht detail form  
**THEN**
- A new field appears: "Contract type: bepaalde tijd / onbepaalde tijd" (required for payroll)
- A note appears: "Bepaalde tijd → hoge WW-premie (KvK-tarief), mogelijk transitievergoeding-doorbelasting. Onbepaalde tijd → lage WW-premie via payroller, geen transitievergoeding."
- The coordinator selects the contract type

### Scenario: TCO-impact display

**GIVEN** the TCO-dashboard is displaying a cost breakdown for an InhuurOpdracht  
**WHEN** the coordinator/controller hovers over "Totale maandlast" or clicks a detail-icon  
**THEN**
- A popup shows the cost composition:
  - Bruto uurbezetting (uren × tarief)
  - SNA/G-rekening split (if applicable)
  - WAB WW-premie (if payroll, bepaalde vs. onbepaalde)
  - Transitievergoeding buffer (if applicable)
- This helps the controller see the full TCO and compare vs. hiring as a direct employee (which includes werkgeverslasten, pensioen, verlof, etc.)

---

## Requirement: REQ-UZI-007 — Urenregistratie met manager-approval-flow

**Domain:** Workflow & Validation  
**Priority:** MUST  
**Source:** Financial controls + evidence for factuur-matching

### Scenario: Manager receives uren-approval task

**GIVEN** a new UrenRegistratieFlex record is created (by bureau API or flex-worker self-service)  
**WHEN** the record is saved with status=ingevoerd  
**THEN**
- The system creates a task in the task-management module:
  - Owner: inhurende_manager_ref (from InhuurOpdracht)
  - Type: "Goedkeuring uren"
  - Title: "Goedkeuring uren week {week_nr} — {kandidaat_naam} ({opdracht_nr})"
  - Details: {uren_per_dag breakdown}, {totaal uren}, {overuren if any}
  - DueDate: now + 3 days
  - Actions: [Goedkeuren] [Afwijzen met opmerking] [Aanpassingen vragen]
- The manager receives a task notification (email, dashboard, or app notification)
- The UrenRegistratieFlex status changes to "in_afwachting_goedkeuring"

### Scenario: Manager approves uren

**GIVEN** a manager has received an uren-approval task  
**WHEN** the manager clicks [Goedkeuren] in the task  
**THEN**
- The system updates UrenRegistratieFlex.status to "goedgekeurd"
- UrenRegistratieFlex.goedgekeurd_door_manager_ref is set to the manager's ID
- UrenRegistratieFlex.goedgekeurd_datum is set to today
- The task is marked complete
- The system triggers the next process: these goedgekeurde uren can now be included in the next maandelijkse FactuurFlex
- Manager sees confirmation: "Uren goedgekeurd; deze kunnen op de volgende factuur meegenomen worden"

### Scenario: Auto-reminder if not approved within 3 days

**GIVEN** an uren-approval task has been pending for 3 calendar days  
**WHEN** the system's nightly batch runs  
**THEN**
- The system checks all "in_afwachting_goedkeuring" UrenRegistratieFlex records with goedgekeurd_datum > now - 3 days
- For each, the system sends an automated reminder email to the manager:
  - Subject: "Herinnering: Goedkeuring uren week {week_nr} {kandidaat_naam}"
  - Body: "Uren zijn nog niet goedgekeurd. Klik hier om goed te keuren of aan te passen."
- No escalation yet; just a reminder

### Scenario: Escalation if not approved within 7 days

**GIVEN** an uren-approval task has been pending for 7 calendar days  
**WHEN** the system's nightly batch runs  
**THEN**
- The system escalates the task:
  - Adds a second owner: inhuur_coordinator_ref
  - Changes task priority to "high"
  - Sends email to both manager and coordinator:
    - Subject: "ESCALATIE: Goedkeuring uren week {week_nr} {kandidaat_naam} ({opdracht_nr}) is 7 dagen achterstand"
    - Body: "De uren zijn nog niet goedgekeurd. Dit verhindert de factuur-matching. Actie nodig."
  - Optionally: auto-adds to coordinator's "urgent" dashboard list

---

## Requirement: REQ-UZI-008 — Maandelijkse factuur-matching

**Domain:** Finance & Controls  
**Priority:** MUST  
**Source:** Accounts payable controls + factuur-fraud prevention

### Scenario: Factuur import and matching

**GIVEN** finance-admin receives a FactuurFlex from a bureau (CSV, PDF, or API import)  
**WHEN** the finance-admin uploads the factuur via [Upload factuur] button in the "Facturen" tab  
**THEN**
- The system parses the factuur and creates a FactuurFlex record with status=ontvangen
- For each factuurregel:
  1. Look up the opdracht by `opdracht_nr` or `bureau_ref` + period + kandidaat-hint
  2. Find all `UrenRegistratieFlex` records for that opdracht in `periode_van..periode_tot` where status=goedgekeurd
  3. Sum the `uren_goedgekeurd` from those records → expected_uren
  4. Compare expected_uren vs. regel.uren_goedgekeurd:
     - If abs(expected_uren - regel.uren) <= 10% of expected_uren: ✓ match (urencheck passed)
     - If abs(expected_uren - regel.uren) > 10%: ✗ mismatch → create dispute
  5. Compare regel.tarief_per_uur vs. InhuurOpdracht.uurtarief_inkoop:
     - If match: ✓
     - If mismatch: ✗ create dispute
  6. If all checks pass for a regel: mark as "matched"
- If ALL regels matched: FactuurFlex.status = "gematcht", ready for approval
- If ANY regel has dispute: FactuurFlex.status = "dispute_open", payment is blocked

### Scenario: Dispute resolution

**GIVEN** a FactuurFlex has status=dispute_open with one or more match_afwijkingen  
**WHEN** finance-admin opens the FactuurFlex detail  
**THEN**
- The system displays a "Disputes" section listing each afwijking:
  - Type (uren_mismatch / tarief_mismatch / opdracht_onbekend)
  - Expected vs. ontvangen values
  - Explanation of the mismatch
- Finance-admin can:
  1. [Uren aanpassen in systeem] — if uren in the system are wrong, manager re-approves them
  2. [Tariff-correctie met bureau afspreken] — create comment + email draft to bureau requesting confirmation or credit note
  3. [Handmatig goedkeuren] — if dispute is justified (e.g., bureau did extra work), finance can override with a note
  4. [Afwijzen] — mark the regel as rejected; create task for bureau contact to issue credit note
- Once all disputes are resolved, status changes to "gematcht" → finance-admin can then approve payment

### Scenario: Payment approval and G-rekening split

**GIVEN** a FactuurFlex has status=gematcht (all regels matched or manually approved)  
**WHEN** finance-admin clicks [Goedkeuren betaling]  
**THEN**
- The system calculates the G-rekening split:
  - g_rekening_percentage from Bureau.g_rekening_percentage (default 25%)
  - bedrag_naar_g_rekening = totaal × g_rekening_percentage / 100
  - bedrag_naar_reguliere_rekening = totaal - bedrag_naar_g_rekening
- FactuurFlex.g_rekening_split is populated
- FactuurFlex.goedgekeurd_door_ref = current user (finance-admin)
- FactuurFlex.goedgekeurd_datum = today
- FactuurFlex.status = "goedgekeurd_betaling"
- Finance-admin is prompted: "Betaalopdracht aanmaken en splitsen naar G-rekening?" with two options:
  1. [Ja, betaalopdracht aanmaken] — trigger export to finance-export module
  2. [Nee, handmatig verwerken] — status stays at "goedgekeurd_betaling" for manual bank transfer

### Scenario: Matching report (optional monthly audit)

**GIVEN** it is end-of-month  
**WHEN** finance-admin runs the "Factuur-matching rapport" from the Facturen tab  
**THEN**
- The system generates a summary for the month:
  - Total invoiced vs. total approved uren (per bureau, per cost center)
  - Dispute count and resolution rate
  - Average time-to-match
  - Flags any unresolved disputes aging > 14 days

---

## Requirement: REQ-UZI-009 — G-rekening-betaling bij hoog ketenaansprakelijkheid-risico

**Domain:** Compliance & Tax Risk Mitigation  
**Priority:** SHOULD  
**Source:** Invorderingswet art. 34 (G-rekening escrow system)

### Scenario: G-rekening split in FactuurFlex

**GIVEN** finance-admin approves a FactuurFlex for payment  
**WHEN** the system calculates the G-rekening split (see REQ-UZI-008)  
**THEN**
- FactuurFlex.g_rekening_split.bedrag_naar_g_rekening = bedrag
- FactuurFlex.g_rekening_split.bedrag_naar_reguliere_rekening = bedrag
- Finance-admin sees a summary: "Betaalopdracht splitsen: € {regulier} naar bureau, € {g_rekening} naar G-rekening {iban}"

### Scenario: Betaalopdracht export with G-rekening split

**GIVEN** finance-admin clicks [Ja, betaalopdracht aanmaken] after approving a FactuurFlex  
**WHEN** the system exports to finance-export module  
**THEN**
- Two betalopdrachten are created:
  1. Regular payment: bedrag_naar_reguliere_rekening → Bureau.standard_iban
  2. G-rekening payment: bedrag_naar_g_rekening → Bureau.g_rekening_iban
- Both transactions are recorded in finance-export audit trail
- FactuurFlex.status = "betaald" (once both are executed)
- Finance-admin receives confirmation with reference numbers

### Scenario: G-rekening release (after loonbelasting/btw clearance)

**GIVEN** (out of scope for this spec, but documented for context)  
**WHEN** (presumed to be annual or per-audit-clearance)  
**THEN**
- The G-rekening funds are periodically released to the bureau after tax authorities confirm no outstanding claims
- This is managed outside the inhuur-module (handled by finance/tax team directly with authorities)

---

## Requirement: REQ-UZI-010 — TCO-dashboard inhuur vs vast

**Domain:** Analytics & Decision Support  
**Priority:** SHOULD  
**Source:** Controller + manager make-or-hire trade-off analysis

### Scenario: TCO dashboard per cost center

**GIVEN** a controller or manager opens the inhuur-module and clicks on the "TCO Dashboard" tab  
**WHEN** the page loads  
**THEN**
- A pivot table is displayed with rows for each cost center / team, grouped by manager
- Columns show:
  - Aantal actieve inhuur-opdrachten
  - Totaal FTE (sum of aantal_uren_per_week / 40 for active opdrachten)
  - Gemiddeld uurtarief (weighted avg of uurtarief_inkoop)
  - Geschatte maandlast (totaal FTE × gemiddeld uurtarief × 4.33 weeks)
  - Geschatte jaarlijkse inhuurkosten (maandlast × 12)
- Each row has a [Drill-down] link to see the list of underlying opdrachten

### Scenario: Vs-vast calculator

**GIVEN** a manager is looking at the TCO dashboard and sees a line item for a cost center with high inhuurkosten  
**WHEN** the manager clicks [Vs. vast calculator]  
**THEN**
- A modal opens with a calculator form:
  - Huidige inhuur-FTE: {pre-filled from the row}
  - Functie-titel: {dropdown or text lookup}
  - Huidig uurtarief inhuur (EUR/uur): {pre-filled avg}
  - Salaris vast werknemer (EUR/jaar): [user input or lookup from employee-master range]
  - Werkgeverslasten percentage: [default 25%, user can adjust]
  - Verlof/ADV impact (dagen × {daily rate}): [calculated or user input]
  - Pensioen bijdrage %: [default per CAO, user can adjust]
- The calculator shows:
  - Jaarlijkse inhuurkosten: {huidige FTE × uurtarief × 1920 uren}
  - Jaarlijkse vaste kosten = (salaris + verlof/adv + pensioen + werkgeverslasten)
  - Break-even uren/jaar (waar worden de kosten gelijk)
  - Recommendation: "Inhuur goedkoper tot {break-even_uren} uren/jaar; daarachter loont het vast aannemen"
- Manager can save the calculation for future reference

### Scenario: Budget vs. realisatie tracking

**GIVEN** the TCO dashboard is open  
**WHEN** a controller has configured an inhuurbudget for a cost center (in settings)  
**THEN**
- The TCO dashboard adds a "Budget vs. realisatie" column:
  - Budget inhuur: {configured in settings, EUR/year}
  - YTD gerealiseerd: {sum of gewerkte uren × uurtarief so far}
  - % van budget gebruikt
  - Prognose einde jaar (if trend continues)
- If prognose > budget: cell is highlighted in orange/red with warning icon
- Controller receives a monthly digest: "Inhuurbudget {cost_center} is {%} used; prognose {format} of budget"

---

## Acceptance Tests

All REQ-UZI-001 through REQ-UZI-010 scenarios must pass with:
- ✅ Correct entity creation and isolation (no unwanted side effects)
- ✅ Validation rules enforced at save time
- ✅ Tasks created and routed to correct owners
- ✅ Status transitions respect prerequisites
- ✅ Audit trail captures all mutations with user + timestamp
- ✅ Integration events published correctly to downstream systems


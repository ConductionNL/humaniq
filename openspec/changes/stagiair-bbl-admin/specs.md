---
status: draft
app: hrmq
spec: stagiair-bbl-admin
version: 0.1.0
owners: [hrmq-team]
---

# Stagiair & BBL-leerling Administratie — Specs

## Functional Requirements

### REQ-SBA-001: Distinct entity-scheiding stagiair vs werknemer

**GIVEN** een nieuwe stagiair wordt geregistreerd  
**WHEN** de HR-admin het stagiair-formulier opslaat  
**THEN** wordt een record aangemaakt in de `Stagiair`-store, NIET in `Employee`; de stagiair krijgt GEEN payroll-entry, GEEN verlofbalans, GEEN CAO-toepassing, en wordt expliciet uitgesloten van de FTE-telling op het Employee-dashboard.

**Acceptance:**
- [ ] Stagiair form does not create Employee record
- [ ] Stagiair does not appear in Employee list
- [ ] Payroll run excludes Stagiair (even if contract_type filled in)
- [ ] Leave balance tracking excludes Stagiair
- [ ] FTE dashboard filters Stagiair out (shows only Employee records)
- [ ] Audit log records "Stagiair created" separately from Employee changes

---

### REQ-SBA-002: BBL-leerling krijgt wel Employee-record

**GIVEN** een nieuwe BBL-leerling wordt geregistreerd  
**WHEN** de HR-admin het BBL-formulier opslaat  
**THEN** wordt zowel een `BBLLeerling`-record als een gelinkt `Employee`-record aangemaakt; het Employee-record krijgt contractvorm `bbl-arbeidsovereenkomst`, salaris volgens BBL-staffel jaar 1, en wordt opgenomen in payroll en CAO-toepassing.

**Acceptance:**
- [ ] BBL registration creates both BBLLeerling + Employee records
- [ ] BBLLeerling.werknemer_id points to created Employee
- [ ] Employee.contractvorm = "bbl-arbeidsovereenkomst"
- [ ] Employee.salaris = BBL-staffel jaar 1 (from CAO for NL region)
- [ ] Employee appears in payroll list
- [ ] Employee eligible for leave, WVP, CAO benefits
- [ ] BBLLeerling detail page shows "Gekoppelde medewerker: [Name]" with edit link
- [ ] Unlinking BBL from Employee sets Employee status to "ex-BBL" with archive flag

---

### REQ-SBA-003: SBB-erkenning is harde voorwaarde

**GIVEN** een organisatie wil een BBL-leerling of MBO-stagiair registreren  
**WHEN** het CREBO-nummer van de opleiding wordt ingevuld  
**THEN** controleert het systeem of de organisatie een geldige `SBBErkenning` heeft voor dat specifieke CREBO; ontbreekt deze of is vervallen, dan blokkeert het systeem opslag met een actionable foutmelding ("Vraag erkenning aan via s-bb.nl/erkenning").

**Acceptance:**
- [ ] BBL/MBO-BOL form requires CREBO field (radio picker or dropdown)
- [ ] On CREBO selection, system queries SBBErkenning table for org's KVK
- [ ] Validation checks: SBBErkenning.status = "geldig" AND CREBO in erkende_crebos array
- [ ] If validation fails: form shows error "Organisatie is niet erkend voor CREBO [code]. Vraag erkenning aan via s-bb.nl/erkenning"
- [ ] Error prevents form submission (button disabled)
- [ ] SBBErkenning cached with sbb_synced_at timestamp (refresh option: "Sync SBB register now")
- [ ] Audit log records CREBO validation (pass/fail)

---

### REQ-SBA-004: POK-driepartijehandtekening verplicht

**GIVEN** een stagiair of BBL-leerling is geregistreerd  
**WHEN** de startdatum nadert (T-7 dagen)  
**THEN** controleert het systeem dat de bijbehorende `PraktijkLeerOvereenkomst` is ondertekend door alle drie de partijen (leerbedrijf, onderwijsinstelling, deelnemer); ontbreekt een ondertekening, dan wordt een blocker-taak aangemaakt voor de stagebegeleider met de melding "POK niet compleet — instroom kan niet plaatsvinden".

**Acceptance:**
- [ ] POK auto-created on Stagiair/BBL registration
- [ ] POK detail page shows three-party signature grid with status (pending / signed / complete)
- [ ] Leerbedrijf signature: email link sent to contactperson, opens signing portal (via document-storage)
- [ ] Onderwijs signature: email sent to onderwijs contactperson, same flow
- [ ] Deelnemer signature: email sent to leerling/stagiair, same flow
- [ ] Once all three signed: pok_ondertekening_status = "compleet", ondertekend_compleet_datum set
- [ ] Scheduled job runs T-7 days before startdatum
- [ ] If pok_ondertekening_status ≠ "compleet", creates task: "POK niet compleet — [Stagiair/BBL name]. Instroom kan niet plaatsvinden. Handtekening ontbreekt van: [list missing]"
- [ ] Task assigned to stagebegeleider_intern, priority HIGH
- [ ] Blocker prevents Employee payroll startup if BBL not ondertekend

---

### REQ-SBA-005: Stagevergoeding zonder loonheffing

**GIVEN** een stagiair krijgt een maandelijkse stagevergoeding  
**WHEN** de finance-admin de uitkering verwerkt  
**THEN** wordt de vergoeding geboekt als "onkostenvergoeding stagiair" zonder inhouding van loonheffing of premies werknemersverzekeringen, mits het bedrag onder de fiscale onbelaste grens blijft; het systeem waarschuwt bij overschrijding ("Bedrag overstijgt onbelaste stagevergoeding — risico op fiscale herkwalificatie als dienstbetrekking").

**Acceptance:**
- [ ] Stagiair detail form has "stagevergoeding_per_maand" field (EUR)
- [ ] On input: system displays fiscal limit (2024: €200/mnd, TBD per year)
- [ ] If amount > fiscal limit: warning banner "⚠️ Bedrag overstijgt onbelaste stagevergoeding (€200/mnd). Risico op fiscale herkwalificatie. Consult HR beleid voordat opslaan."
- [ ] User can still save (warning is advisory, not blocking)
- [ ] Finance export tags stagevergoeding as "non-taxable stipend" (not wage/salary component)
- [ ] Payroll run excludes from bruto salary, from tax withholding, from premium calculations
- [ ] Audit log records amount, threshold check, warning status

---

### REQ-SBA-006: BBL-staffel-progressie per leerjaar

**GIVEN** een BBL-leerling heeft 12 maanden in dienst gewerkt en is bevorderd naar leerjaar 2  
**WHEN** de stagebegeleider de leerjaar-overgang registreert  
**THEN** wordt automatisch een payroll-mutation aangemaakt die het Employee-salaris ophoogt naar BBL-staffel jaar 2 per de eerste van de volgende maand; finance ontvangt een approval-taak.

**Acceptance:**
- [ ] BBLLeerling detail page shows "BBL-leerjaar: [1/2/3]" with action button "Registreer leerjaar-overgang"
- [ ] Button hidden if startdatum + 12 months not yet passed
- [ ] Clicking button opens modal: "Bevordering naar leerjaar 2 — [name], startdatum [date]"
- [ ] Modal confirms 12 months elapsed, shows current BBL-staffel-jaar 1 salary (from Employee)
- [ ] Modal shows proposed new salary for BBL-staffel-jaar 2 (from CAO/contract-management)
- [ ] User confirms, saves
- [ ] System updates BBLLeerling.bbl_staffel_jaar = 2, sets bbl_staffel_jaar_gewijzigd_datum = now
- [ ] Automatically creates payroll_mutation record:
  - entity_type = "BBLLeerling"
  - entity_id = [BBL id]
  - change_type = "staffel_progression"
  - old_salary = BBL-staffel-jaar-1-amount
  - new_salary = BBL-staffel-jaar-2-amount
  - effective_date = first day of next month (or first day of next month after modal-click date)
- [ ] Finance notified via task: "Staffel progression BBL [name] jaar 1→2 pending approval. New salary € [amount] effective [date]. Approve?"
- [ ] Finance approves → mutation marked "approved" → applies in next loonrun
- [ ] Audit log records who triggered, when, salary deltas

---

### REQ-SBA-007: Subsidie Praktijkleren-aanvraag per BBL-leerling

**GIVEN** een BBL-leerling heeft een volledig studiejaar (40 begeleidingsweken) afgerond  
**WHEN** het studiejaar eindigt (default 31 juli)  
**THEN** genereert het systeem automatisch een `SubsidieAanvraagPraktijkleren`-concept met als bewijsstukken de POK, de urenregistratie en het tussentijdse-evaluatieformulier; finance ontvangt een taak om de aanvraag in te dienen bij RVO voor de deadline (default 16 september).

**Acceptance:**
- [ ] Scheduled task runs on 31 juli (annually) or configurable subsidie-year-end date
- [ ] Task queries all BBLLeerling records with:
  - startdatum <= (31-juli minus 40 weeks) — i.e., started at least 40 weeks before
  - status ≠ "archived" or "exited"
- [ ] For each eligible BBL: creates SubsidieAanvraagPraktijkleren (concept)
- [ ] System auto-populates:
  - bbl_leerling_id
  - studiejaar = academic year (e.g., "2025-2026")
  - bedrag_aangevraagd = €2.700 (default, configurable per regulation)
  - status = "concept"
- [ ] System fetches bewijsstukken:
  - pok_url (from PraktijkLeerOvereenkomst.pok_document_url)
  - urenregistratie_url (from document-storage, latest file tagged "urenregistratie-[BBL-id]")
  - evaluatieverslag_url (auto-generated from tussentijdse_evaluaties or user upload)
- [ ] Finance notified via task: "Subsidie Praktijkleren aanvraag gereed voor [count] leerlingen. Deadline: 16 september. Review & submit."
- [ ] Finance opens task detail → list of SubsidieAanvraagPraktijkleren concepts
- [ ] Finance clicks "Dien in bij RVO" → system submits batch to RVO API
- [ ] On RVO confirm: rvo_referentienr captured, status = "ingediend"
- [ ] Finance can track uitkeringsstatus in subsidie-list view
- [ ] Audit log records submission date, RVO ref, bedrag

---

### REQ-SBA-008: Verzekering-status bij instroom

**GIVEN** een stagiair of BBL-leerling staat op het punt te starten  
**WHEN** de startdatum is bereikt  
**THEN** controleert het systeem dat de verzekering-status van de deelnemer (aansprakelijkheid via onderwijsinstelling voor stagiair; ziektewet + ongevallen via werkgever voor BBL-leerling) is gevalideerd; ontbrekende verzekering blokkeert instroom en triggert een taak voor de HR-admin.

**Acceptance:**
- [ ] Stagiair form includes "verzekering_status" field (dropdown: "niet_geverifieerd" / "aansprakelijkheid_ok" / "afgewezen" / custom)
- [ ] BBL form includes same field
- [ ] For Stagiair: label "Aansprakelijkheidsverzekering via onderwijsinstelling"
- [ ] For BBL: label "Ziektewet + arbeidsongevallenverzekering werkgever"
- [ ] Default value on registration: "niet_geverifieerd"
- [ ] HR-admin must verify before startdatum (either manual check or integration stub)
- [ ] Scheduled job runs T-3 days before startdatum
- [ ] If verzekering_status ≠ "ok" or "aansprakelijkheid_ok":
  - Creates task: "Verzekering niet geverifieerd voor [Stagiair/BBL name]. Instroom [startdatum]. Status: [current status]."
  - Task assigned to HR-admin, priority HIGH
  - Blocker flag prevents POK "ondertekend compleet" confirmation if not resolved
- [ ] Once verified, stagebegeleider can proceed with instroom checklist
- [ ] Audit log records status change, timestamp, who verified

---

### REQ-SBA-009: Voortgangsgesprekken-tracking met audit-trail

**GIVEN** een stagiair of BBL-leerling is actief  
**WHEN** de afgesproken evaluatiemomenten (default 25%, 50%, 75% van looptijd) worden bereikt  
**THEN** ontvangt de stagebegeleider een taak om het evaluatiegesprek te voeren en het resultaat te registreren in de POK-`tussentijdse_evaluaties`-array; ontbrekende evaluaties blokkeren de uitstroom-procedure en de subsidie-aanvraag.

**Acceptance:**
- [ ] On POK creation, system auto-populates evaluatie_punten & tussentijdse_evaluaties based on startdatum + einddatum
- [ ] 25% point = startdatum + 25% of (einddatum - startdatum)
- [ ] 50% point = startdatum + 50% of duration
- [ ] 75% point = startdatum + 75% of duration
- [ ] Each evaluation has: moment_percentage, geplande_datum, (voltooide_datum optional), (resultaat json optional)
- [ ] Scheduled task runs daily, checks for evaluatie_punten where geplande_datum = today
- [ ] Creates task for stagebegeleider: "Voortgangsevaluatie [25%/50%/75%] voor [Stagiair/BBL name]. Geplande datum [date]. Registreer resultaat."
- [ ] Stagebegeleider opens Stagiair/BBL detail, clicks "Evaluatie afgerond" for that point
- [ ] Modal appears: "Voortgangsevaluatie [25%] registreren — [name]"
- [ ] Fields: opmerkingen (text), score (optional number 1-10 or text)
- [ ] On save: voltooide_datum = now, resultaat = {opmerkingen, score}, marked complete
- [ ] Task closed automatically
- [ ] Audit log records: evaluatie evaluatie-point, who, when, resultaat
- [ ] Blocking rules: on ausgestrom attempt (REQ-SBA-010):
  - If any evaluatie_punt with moment_percentage in [25, 50, 75] and voltooide_datum = null → show error "Alle evaluatiemomenten moeten afgerond zijn voor uitstroom." and block
- [ ] Blocking rule: on subsidie-aanvraag submission → same check

---

### REQ-SBA-010: Uitstroom met diploma-registratie

**GIVEN** een stagiair of BBL-leerling bereikt de einddatum  
**WHEN** de stagebegeleider de eindbeoordeling registreert  
**THEN** wordt het diploma-veld bijgewerkt, voor BBL-leerlingen wordt de Employee-status op `uit-dienst` gezet (met optionele triggering van regulier sollicitatie-proces voor vaste aanstelling), en de POK wordt definitief gearchiveerd met retentie volgens Archiefwet (7 jaar na uitstroom).

**Acceptance:**
- [ ] Stagiair/BBL detail page shows "Einddatum: [date]" with action button "Registreer uitstroom" (active on einddatum or after)
- [ ] Clicking "Registreer uitstroom" opens modal: "Uitstroom — [name], periode [start]–[eind]"
- [ ] Modal shows completion checklist:
  - [ ] All evaluatie_punten completed? (if not, show blocking error per REQ-SBA-009)
  - [ ] POK signed & complete?
  - [ ] Final diploma status (behaald: yes/no)
  - [ ] Final score (1-10 or text)
  - [ ] Remarks (textarea)
- [ ] User fills in, clicks "Uitstroom registreren"
- [ ] System updates:
  - Stagiair.diploma_behaald = [yes/no]
  - Stagiair.beoordeling_eindcijfer = [score]
  - Stagiair.archivedAt = now (soft archive)
- [ ] For BBL-leerling, additionally:
  - BBLLeerling.diploma_behaald = [yes/no]
  - BBLLeerling.beoordeling_eindcijfer = [score]
  - BBLLeerling.archivedAt = now
  - Employee (linked via werknemer_id) status = "uit-dienst"
  - Employee.exit_date = einddatum
  - Optionally triggers task for HR: "BBL [name] geslaagd. Aanbieden vaste anstelling?" (if diploma_behaald = true and configurable org-policy)
- [ ] POK archival:
  - System calls document-storage: "archive POK [id], retention_until = now + 7 years"
  - pok.archief_url set to archived document location
  - pok.archivering_startdatum = now
  - pok.archivering_verwijderplandatum = now + 7 years
  - POK locked (read-only) from this point
- [ ] Audit log records: exit date, diploma status, score, POK archival
- [ ] Email sent to stagebegeleider: "Uitstroom voltooid voor [name]. Archief-retentie tot [date]."

---

## Non-Functional Requirements

### NFR-SBA-001: Audit Trail

All changes to Stagiair, BBLLeerling, POK, SBBErkenning, SubsidieAanvraagPraktijkleren must be logged with:
- who (user ID + name)
- when (timestamp UTC)
- what (field name, old value, new value)
- why (action context: "registration", "evaluation", "salary-step", "archive", etc.)

Audit log must be immutable after 5 minutes (prevent deletion, only append).

### NFR-SBA-002: Data Privacy (AVG)

- BSN storage only justified for:
  - Stagiair: if stagevergoeding > €0 (fiscal obligation)
  - BBL: always (employee record requires BSN)
- If Stagiair has stagevergoeding_per_maand = €0, BSN must not be stored
- Deletion of Stagiair record: purge BSN after 7 years per Archiefwet + AVG
- BBL archival: anonymize sensitive PII but retain auditability (BOX-format for retention)

### NFR-SBA-003: Document Management

- All PDFs (POK, diploma, SBB-bewijsstuk) stored in document-storage module
- POK signing: integration with contract-management digital-signing flow
- Archive retention: automatic hold via Archiefwet until verwijderplandatum; no delete without compliance sign-off

### NFR-SBA-004: Notifications

- Email to stagebegeleider for evaluation reminders (T-7 days before geplande_datum)
- Email to Finance for subsidie deadline reminders (T-30 days before 16 september)
- Email to HR for POK signature blockers (T-7 days before startdatum)
- Slack integration (if configured): high-priority tasks (POK incomplete, insurance missing)

### NFR-SBA-005: Integrations

- **SBB Register API:** nightly sync of SBBErkenning (erkende_crebos, status). Timeout: 30s. Retry: 3×. On failure: sbb_sync_status = "error", alert ops.
- **RVO Subsidieregeling API:** submit SubsidieAanvraagPraktijkleren batch, poll for rvo_referentienr, track status. Timeout: 60s. On failure: status = "submission_error", notify Finance.
- **payroll-engine-nl:** BBL-staffel-jahr queries via API or config table. On update to bbl_staffel_jaar, trigger payroll mutation.
- **contract-management:** POK as contract type, reuse digital-signing flow, validate archiefretentie rules.
- **document-storage:** upload/store/retrieve POK, diploma, SBB-bewijsstuk, urenregistratie PDFs.
- **task-management:** create/close/query tasks for evaluations, subsidies, blockers.
- **employee-master:** query Employee refs for stagebegeleider/praktijkbeoordelaar, create Employee on BBL registration.

### NFR-SBA-006: Localization

- All UI strings in Dutch (NL-NL)
- Date formats: DD-MM-YYYY (European standard)
- Currency: EUR (€) with 2 decimals
- Numbers: comma as decimal separator (1.234,56 = one thousand, two hundred thirty four euros and fifty six cents)

### NFR-SBA-007: Performance

- Stagiair/BBL list view (filters: status, onderwijsinstelling, periode): < 2s, paginated 50 rows
- SBB CREBO validation (on form blur): < 500ms (local cache lookup)
- Subsidie bulk-submission (100 BBLs): < 30s
- Audit log queries (last 100 entries per entity): < 1s

### NFR-SBA-008: Backwards Compatibility

N/A — new module, no legacy data.

---

## Edge Cases & Error Handling

| Case | Behavior |
|------|----------|
| Stagiair stagevergoeding changes mid-placement | Allow edit, audit trail records old vs. new, warn if new > fiscal limit |
| BBL Employee link broken (linked Employee deleted) | Mark BBLLeerling with error flag, prevent payroll runs, notify Finance immediately |
| POK unsigned at T-7 days & startdatum is tomorrow | Task created with CRITICAL priority, escalate to HR-manager |
| SBB API down, CREBO lookup fails | Show error "SBB register temporarily unavailable. Try again in 5 minutes or contact ops." Allow override with manager approval |
| Multiple evaluations marked complete on same day | Allowed, audit trail shows exact timestamps, no deduplication |
| Subsidie-aanvraag submitted, then BBL exits early (einddatum changed) | Subsidie aanvraag status becomes "invalid — leerling exit_date changed", notify Finance |
| Finance approves BBL salary-step twice | Idempotent: second approval updates payroll_mutation status but doesn't create duplicate mutation |


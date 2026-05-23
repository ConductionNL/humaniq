# CAO Gemeenten — Tasks

**Status:** pending

---

## Sprint 1: Data Model & CAO-versiebeheer

### Task 1.1: Create OpenRegister schemas

- [ ] Define schema: `CAOVersie` (PascalCase, schema.org + Dutch extensions)
  - Fields: caoCode, versieNummer, ingangsdatum, einddatum, publicatieDatum, vngBron, vngDocumentHash
  - Fields: loondoorbetalingZiekteJaar1Percentage, loondoorbetalingZiekteJaar2Percentage
  - Fields: ikbPercentage, vakantieDagenPerJaar, abpAansluitingVerplicht, bwgrVanToepassing
  - Fields: ondertekenaars[], status (enum: actief, concept, ingetrokken)
  - Required: caoCode, versieNummer, ingangsdatum, status
  - Unique constraint: (caoCode, versieNummer)

- [ ] Define schema: `SalaristabelSchaal`
  - Fields: caoVersieId (relation), schaalNummer (1-19), schaalNaam, minimumBruto, maximumBruto
  - Fields: aantalPeriodieken, periodieken[] (array of {periodiek, bedrag, geldendVan})
  - Fields: geldigVanaf, geldigTot (nullable)
  - Required: caoVersieId, schaalNummer, minimumBruto, maximumBruto, periodieken[]
  - Unique constraint: (caoVersieId, schaalNummer)

- [ ] Define schema: `MedewerkerRechtspositie`
  - Fields: medewerkerId (relation to Medewerker), caoCode, caoVersieId (relation)
  - Fields: rechtspositie (enum: ambtenaar_wnra, transitie_ambtenaar)
  - Fields: aanstellingsdatum, aanstellingType (enum: vast, tijdelijk, ...), schaalNummer, periodiek
  - Fields: brutoMaandsalaris, deeltijdfactor, functieCode (HR21), functieNaam, afdeling
  - Fields: leidinggevende (relation), ikbPercentage, abpDeelnemerNummer
  - Fields: bwgrRechten (bool), wachtgeldRechten (bool), buitengewoonVerlofSaldo, roosterverlofSaldo
  - Fields: versieBindingsDatum, laatsteSchaalMutatieDatum
  - Required: medewerkerId, caoCode, caoVersieId, rechtspositie, schaalNummer, deeltijdfactor
  - Unique constraint: (medewerkerId) — one active record per medewerker

- [ ] Create register templates in `lib/Settings/cao_register.json`
  - Register: `cao-versies`, schema: `CAOVersie`
  - Register: `salaristabel-schalen`, schema: `SalaristabelSchaal`
  - Register: `medewerker-rechtspositie`, schema: `MedewerkerRechtspositie`
  - Mark with `x-openregister.type: "application"`

- [ ] Add seed data to `cao_register.json`: 3 CAO versions, 5 salary scales, 3 medewerkers

### Task 1.2: CAO-versie import & validation

- [ ] Implement `CAOImportService`
  - Method: `importFromVNG(pdfUrl)` → parse PDF, extract salaristabel
  - Extract version number, dates, parameters from PDF headers
  - SHA-256 hash on raw PDF content (store in `vngDocumentHash`)
  - Return: CAO_Versie + Salaristabel_Schaal[] ready for import

- [ ] Implement `CAOValidationService`
  - Validate all 19 scales present (schaal 1-19)
  - Validate periodiek counts per scale (1-11)
  - Validate bedrag monotonicity (periodiek 0 ≤ periodiek 1 ≤ ... ≤ periodiek N)
  - Check for duplicate versies (same caoCode + versieNummer)

- [ ] Implement `ConfigurationService` integration
  - Endpoint: `POST /index.php/apps/hrmq/api/cao-versions`
  - Accept: file upload (PDF) or manual paste-in of version data
  - On import: trigger validation, save to OpenRegister, generate hash
  - Return: created CAO_Versie + count of imported scales

### Task 1.3: CAO-versie lifecycle management

- [ ] Implement versioning UI under Configuratie › CAO's & regelingen
  - List view: all versions (caoCode, versieNummer, status, ingangsdatum, einddatum)
  - Detail view: parameters, salaristabel preview, edit allowed only for status=concept
  - Status workflow: concept → actief → ingetrokken (no direct delete)

- [ ] Implement deletion protection
  - Server validation: if status='actief' then reject delete with 409 Conflict
  - UI: disable delete button for actief versies, show "Archive first" instruction

- [ ] Implement version archiving
  - Archive operation: status concept/actief → ingetrokken
  - Keep immutable record for historical audit trails
  - Archived versions remain visible in audit lookups

---

## Sprint 2: Salaristabel Lookup & Schaal-selectie

### Task 2.1: Schaal/Periodiek-selection UI

- [ ] Implement HR-adviseur form for new aanstelling
  - Functie-code lookup (autocomplete, HR21-datastore integration)
  - On functie selection: auto-restrict schaal range (minSchaal, maxSchaal)
  - Schaal dropdown: filtered values only
  - Periodiek dropdown: conditional on schaal selection, max = aantalPeriodieken-1
  - On periodiek selection: auto-fill brutoMaandsalaris from salaristabel

- [ ] Implement salaristabel caching
  - Cache key: (caoCode, caoVersieId, schaalNummer)
  - TTL: 1 hour (refresh on CAO import)
  - Lookup returns: {bedragen[], periodieken[], minBruto, maxBruto, aantalPeriodieken}

- [ ] Test periodiek lookup with seed data
  - Schaal 10, periodiek 4 → € 3.897,00 ✓
  - Schaal 7, periodiek 11 (last) → € 3.674,00 ✓
  - Out-of-range periodiek → validation error ✓

### Task 2.2: Horizontal inschalingssuggestie

- [ ] Implement schaalverhoging wizard
  - Current: { schaal: old_schaal, periodiek: old_periodiek, salary: old_salary }
  - New schaal: user selects new_schaal
  - Lookup: salaristabel[new_schaal] → find periodiek P where bedrag[P] >= old_salary
  - Display: "Inschakel in schaal {new_schaal} periodiek {P} (salaris € Y, +€ Z verhoging)"
  - User can override periodiek if desired (for discretionary upgrade)
  - Toon HR21-bereik check: is new_schaal in [minSchaal, maxSchaal]? Warn if not.

- [ ] Implement schaalverhoging validation
  - Server: enforce schaal change is upward or horizontal (no salary reduction)
  - Log CAO article: "Artikel 3.2 - Schaalverhoging"

### Task 2.3: Periodieke verhoging jaarlijkse taak

- [ ] Implement annual increment scheduler
  - Cron job: runs on 1 januari (or configurable date per gemeente)
  - For each medewerker with status=actief and rechtspositie=ambtenaar_wnra:
    - Check if periodiek < aantalPeriodieken - 1
    - If yes: increment periodiek by 1, recalculate brutoMaandsalaris
    - Log: "Periodieke verhoging per artikel 3.4 - Van periodiek X naar Y"
    - If periodiek is last: generate alert "Eindperiodiek bereikt — advies promotie/toelage"

- [ ] Implement endperiodiek alert dialog
  - Show on medewerker-detail when periodiek === aantalPeriodieken-1
  - Suggest: "Options: 1) Schaalverhoging naar schaal X+1, 2) Eenmalige toelage, 3) Loopbaan-advies"
  - Link to functiewijziging-form

---

## Sprint 3: IKB-opbouw & -opname

### Task 3.1: IKB-rekening entities

- [ ] Define schema: `IKBRekening`
  - Fields: medewerkerId (relation), jaar, caoVersieId (relation)
  - Fields: openingssaldo, maandelijkseOpbouw[], totaalOpgebouwd, opnames[]
  - Fields: saldo, afrekeningEindeJaar (bool), fiscalRegime (WKR_gericht_vrijgesteld_waar_mogelijk)
  - Required: medewerkerId, jaar, caoVersieId
  - Unique constraint: (medewerkerId, jaar)

- [ ] Register template for `ikb-rekeningen`
  - Schema: `IKBRekening`, register: `ikb-rekeningen`
  - Add seed data: 2 medewerkers with 2024 accounts

### Task 3.2: Maandelijkse IKB-opbouw

- [ ] Implement `IKBService.calculateMonthlyAccumulation(monthDate)`
  - Iterate over all medewerker_rechtspositie with status=actief
  - Calculate: `brutoMaandsalaris × (ikbPercentage / 100)`
  - For deeltijd: use already-factored brutoMaandsalaris (no additional FTE correction)
  - Create/update IKBRekening.maandelijkseOpbouw[] entry for the month
  - Update saldo: `openingssaldo + opbouw_sum - opname_sum`
  - Handle mid-month changes (termination): pro-rata calculation = `salary × (days_in_month / total_days) × percentage`

- [ ] Implement monthly batch job
  - Cron: runs on 1st or last day of month (configurable)
  - Call `IKBService.calculateMonthlyAccumulation()`
  - Notify on errors (no medewerker_rechtspositie records, schema mismatches)
  - Log: "IKB opbouw voltooid: 1500 medewerkers verwerkt, € 2.5M opgebouwd"

- [ ] Test with seed data
  - Anna (3897 × 0.175 = 682 per month) ✓
  - Ben (5223 × 0.175 = 914 per month, 0.889 FTE = 813 adjusted) ✓

### Task 3.3: IKB-opname workflow

- [ ] Implement request submission
  - Employee self-service form: select type {contante_uitbetaling, extra_verlof, fiets, vakbond, opleiding, fitnes}
  - Amount input: max = current saldo
  - For extra_verlof: number of hours input (max = amount / hourly_rate)
  - Submit: creates IKBOpnameAanvraag record, status=pending

- [ ] Implement approval flow
  - Manager/HR-adviseur review: list of pending requests (Verlof & verzuim or IKB module)
  - Approve/reject: status → approved/rejected
  - On approval: create IKBRekening.opnames[] entry
  - Deduct from saldo: `saldo -= bedrag`
  - Route to payroll (if contante_uitbetaling) or verlofadministratie (if extra_verlof)

- [ ] Implement fiscal routing
  - contante_uitbetaling: mark type='uitbetaling_vakantiegeld' → payroll receives bruto-loon (loonheffing applies)
  - extra_verlof: mark type='extra_verlof' + verlofUren → verlofadministratie (WKR gericht vrijgesteld)
  - fiets_van_de_zaak: mark type='fiets_van_de_zaak' → check WKR regime (typically gericht vrijgesteld)
  - vakbondscontributie, opleidingskosten, bedrijfsfitness: case-by-case WKR mapping

- [ ] Test audit trail
  - Approval record includes: user, timestamp, reason, fiscal treatment
  - Saldo reduction is tracked with opname reference

### Task 3.4: Jaarafsluiting & eindafrekening

- [ ] Implement year-end cutoff
  - Cron: runs on 15 december (before final payroll of year)
  - For all IKBRekening records with `afrekeningEindeJaar = true` and year = previous_year:
    - Calculate unused saldo
    - Create final opname: `{datum: 2024-12-31, bedrag: saldo, type: 'jaarafrekening_restant'}`
    - Mark record completed: `afrekeningEindeJaar = false`
    - Route to payroll: receive `{ikbJaarafrekening: saldo}`

- [ ] Create new year IKBRekening
  - On 1 januari: for each medewerker_rechtspositie actief, create IKBRekening(jaar=new_year, openingssaldo=0)
  - Update seed data to include 2025 records

---

## Sprint 4: Ziekte-loondoorbetaling & ABP-aansluiting

### Task 4.1: Ziekteperiode tracking

- [ ] Define schema: `Ziekteperiode`
  - Fields: medewerkerId, ziekteperiodeId, startDatum, eindDatum (nullable)
  - Fields: weekNummerInPeriode, huidigPercentage, verwachteOvergangNaar70Percent
  - Fields: rePIntegratieFase, bedrijfsartsRapporten[], uwvMelding (nullable)
  - Fields: hervatsingsDatum (nullable), samentellingsRegel
  - Required: medewerkerId, startDatum, huidigPercentage
  - Unique constraint: (medewerkerId) — one active period per medewerker

- [ ] Register template for `ziekteperiodes`, schema: `Ziekteperiode`

### Task 4.2: Ziekte-melden workflow

- [ ] Implement HR-adviseur / Medewerker self-service form
  - Input: startDatum, estimated endDatum (optional)
  - On save: create Ziekteperiode record
  - Auto-set: huidigPercentage = 100, verwachteOvergangNaar70Percent = startDatum + 52 weeks
  - Generate HR-reminder: "Ziekte medewerker X — 1-jaars monitoring; re-integratiedossier?"

- [ ] Implement samentellings-check
  - On new ziekteperiode: look for previous periode with eindDatum
  - If (startDatum - endDatum) < 4 weeks: flag as "samentelling"
  - Auto-set: weekNummerInPeriode = previous_weekNum + elapsed
  - Keep huidigPercentage from state before hervatting

### Task 4.3: Automatische 100% → 70% overgang

- [ ] Implement weekly transition scheduler
  - Cron: runs daily or weekly (checks all ziekteperiodes)
  - For each periode with huidigPercentage=100:
    - Calculate: weeks_elapsed = (today - startDatum) / 7
    - If weeks_elapsed >= 52: update huidigPercentage = 70
    - Set verwachteOvergangNaar70Percent = completed
    - Generate alert: "Ziekte medewerker X overgegaan naar 70% doorbetaling (52 weken bereikt)"

- [ ] Implement payroll integration
  - SalaryCalculationService receives: `ziekteLoondoorbetalingPercentage` from Ziekteperiode.huidigPercentage
  - Payroll applies: salary × percentage

### Task 4.4: ABP-aansluiting enforcement & SOAP integration

- [ ] Implement ABP-validatie
  - Server validation in Medewerker_Rechtspositie mutation:
    - If `caoCode === 'GEMEENTEN'`: enforce `pensioenuitvoerder === 'ABP'`
    - Return 400 Bad Request if violated
  - UI: make pensioenuitvoerder field read-only (value='ABP') for CAO Gemeenten

- [ ] Implement SOAP client for ABP UPA
  - Add dependency: `php-soap` or `symfony/soap-client`
  - Configure certificate + auth for https://upa.abp.nl
  - Implement stubs: `AanmeldingMedewerker`, `MutatieMedewerker`, `AfmeldingMedewerker`

- [ ] Implement aanmelding-upon-loonrun
  - LoonrunService pre-check: for each medewerker, verify `abpDeelnemerNummer` non-null
  - If null: call `ABPAansluitingService.aanmelden(medewerkerId)`
  - SOAP call: pass fields { voornaam, achternaam, geboortedatum, bsn, aanstellingsDatum, schaal, ... }
  - Poll for response (async, since ABP may take minutes)
  - On response: update Medewerker_Rechtspositie.abpDeelnemerNummer
  - Block loonrun with status "Wachten op ABP-deelnemernummer" until available

- [ ] Implement ABP-mutatie bij salariswijziging
  - Hook on Medewerker_Rechtspositie mutations:
    - If schaal, periodiek, or deeltijdfactor changes: trigger `ABPAansluitingService.mutatie()`
    - SOAP call: MutatieMedewerker(deelnemerNummer, ...)
    - Log: "ABP-mutatie verzonden: schaalwijziging 8 → 9"

- [ ] Implement ABP-afmelding bij ontslag
  - Exit-workflow trigger: on Medewerker status = 'inactive' or terminated:
    - Call `ABPAansluitingService.afmelden(medewerkerId, ontslagDatum, ontslagRond)`
    - SOAP call: AfmeldingMedewerker(deelnemerNummer, exitDatum, reason_code)
    - Log: "ABP-afmelding verzonden: ontslag per 1-8-2024 (reorganisatie)"

---

## Sprint 5: BWGR & Wachtgeld, Ontslag-exit workflow

### Task 5.1: BWGR-berekening

- [ ] Define schema: `BWGRUitkering`
  - Fields: exMedewerkerId (relation), ontslagdatum, ontslagrond (enum: ontslag, reorganisatie, pensioen, ...)
  - Fields: diensttijdJaren, wwUitkeringStart, wwUitkeringEinde
  - Fields: bwgrAanvullingPercentage, bwgrLooptijdMaanden, bwgrTotaalBedrag
  - Fields: bwgrBetaaldTotOpDatum, bwgrRestSaldo
  - Fields: wachtgeldVan, wachtgeldEinde, slapeendBWGRSaldo
  - Required: exMedewerkerId, ontslagDatum, diensttijdJaren

- [ ] Register template for `bwgr-uitkeringen`, schema: `BWGRUitkering`

- [ ] Implement `BWGRService.calculateEntitlements(medewerkerId, ontslagDatum, ontslagRond)`
  - Input: medewerker record, exit date, reason
  - Calculate diensttijd: (aanstellingsDatum → ontslagDatum) in years
  - BWGR lookup table per CAO artikel 10.3:
    - < 5 jaar: 0% (no BWGR)
    - 5-10 jaar: 10% × (10 - diensttijd) months
    - 10-15 jaar: 20% × 24 months
    - > 15 jaar: 25% × 36 months
    - Multiply by adjustment factor based on ontslagRond (reorganisatie = full, medisch = reduced, etc.)
  - Return: { bwgrPercentage, bwgrDurationMonths, totalAmount, schedule[] }

- [ ] Implement wachtgeld entitlement
  - Check: if diensttijdJaren > 10: wachtgeldrecht = true
  - Duration: (diensttijdJaren × months per artikel 10.4), e.g., 12 years = 12 months wachtgeld
  - Start: wachtgeldVan = bwgrEinde + 1 day (no overlap)
  - Create record: wachtgeldEinde = wachtgeldVan + duration

### Task 5.2: Slapend BWGR-saldo bij vervolgwerk

- [ ] Implement BWGR suspension on re-employment
  - On Medewerker new assignment (while BWGR period active):
    - Mark BWGRUitkering.bwgrBetaaldTotOpDatum = today
    - Calculate: bwgrRestSaldo = (remaining_months × monthly_amount) - paid
    - Set: slapeendBWGRSaldo = bwgrRestSaldo
    - Notification: "BWGR-aanvulling gestopt wegens herneutring; slapend saldo € X beschikbaar voor toekomstige werkloosheid"

- [ ] Implement slapend-saldo reactivation
  - On future job loss within BWGR looptijd:
    - Check: if slapeendBWGRSaldo > 0 and current_date < original_wwEinde:
      - Reactivate: bwgrPercentage = original, restart payments
      - Deduct: used amount from slapeendBWGRSaldo

### Task 5.3: Ontslag-exit workflow

- [ ] Implement exit-procedure checklist
  - Triggered: Manager/HR requests "Ontslag medewerker X"
  - Steps:
    1. Confirm ontslagDatum, ontslagRond (enum: vrijwillig, ontslag, pensioen, overlijden)
    2. Auto-calculate: BWGR entitlements + wachtgeld entitlements
    3. Review: generated BWGR_Uitkering record, approval by HR-manager + controller
    4. Confirm: Medewerker status → 'inactive', effective date set
    5. Trigger: ABP-afmelding, UWV notification, exit-documentation generation (docudesk)
    6. Final payroll: exit-run with BWGR + wachtgeld schedule attached

- [ ] Implement BWGR payment scheduling
  - Generate monthly installment plan: for 24 months (or duration)
  - Each installment = (original_salary × bwgrPercentage) + WW-uitkering (UWV provides)
  - Attach to salarisadministratie as external-partner payment instruction
  - Flag for compliance: "BWGR payments require UWV coordination"

---

## Sprint 6: Functiehuis HR21, Verlof-ondersteuning, Audit-trail

### Task 6.1: HR21-functiehuis integratie

- [ ] Implement HR21Service
  - Data source: via API or static import from `https://hr21.nl`
  - Cache: functie-code → { code, naam, schaalMin, schaalMax, kerntaken[] }
  - Lookup: `HR21Service.getFunctie(code)` → {minSchaal, maxSchaal}

- [ ] Implement functie-code autocomplete
  - UI input: functie-code lookup with debounce
  - On selection: display { naam, schaalMin, schaalMax, kerntaken }
  - Auto-set: valid schaal range in next dropdown

- [ ] Implement schaal-bereik validation
  - Form save: check schaalNummer ∈ [HR21.minSchaal, HR21.maxSchaal]
  - If violated: show error + options: "Hercategoriseer HR21 of creëer maatwerkfunctie"

- [ ] Implement functiewijziging workflow
  - Input: old functie vs. new functie (both HR21)
  - Calculate: new schaalMin/Max range
  - Suggest: horizontal inscription periodiek in new schaal
  - Manager approval required
  - Log: "Functiewijziging HR21-X → HR21-Y, artikel 3.2 horizontale inschakling"

### Task 6.2: Verlof-ondersteuning (wettelijk, bovenwettelijk, rooster, buitengewoon)

- [ ] Extend Medewerker_Rechtspositie with verlof saldi
  - Fields: wettelijkVerlofSaldo, bovenwettelijkVerlofSaldo, roosterverlofSaldo (read-only, calculated)
  - Roosterverlof bij voltijd = 7.2 uur/jaar per CAO
  - Roosterverlof bij deeltijd = 7.2 × deeltijdfactor

- [ ] Implement FIFO verlof-opbrengst
  - On verlof-opname (via verlofadministratie):
    - Priority order: wettelijk → bovenwettelijk → rooster
    - Deduct from first non-empty, then cascade
    - Audit trail: "8 uur van wettelijk verlof (FIFO)"

- [ ] Implement buitengewoon verlof
  - Schema: `BuitengewoonVerlof` (type, relatie_medewerker, datum_event, dag_toegekend)
  - Types: geboorteverlof (40 uur), rouwverlof (2-4 days), huwelijksverlof (2 days), etc.
  - Days per type per CAO artikel 6.5
  - Auto-grant on request + reason-validation
  - Not deductible from regular saldi

- [ ] Implement geboorteverlof + UWV-signalering
  - On birth notification: auto-create BuitengewoonVerlof (40 hours)
  - Also: signal to UWV (ASV claim form)
  - Log: "Geboorteverlof 40 uur goedgekeurd + UWV-signalering"

### Task 6.3: Audit-trail implementation

- [ ] Extend AuditTrailService for CAO-specific fields
  - Auto-log all Medewerker_Rechtspositie mutations:
    - schaalNummer, periodiek, brutoMaandsalaris, deeltijdfactor, ikbPercentage, functieCode, etc.
  - Capture: before, after, user, timestamp, IP, reason (if provided)
  - Custom field mapping: `caoArtikelReferentie` for each mutation type
    - Periodieke verhoging → "artikel 3.4"
    - Schaalverhoging → "artikel 3.2"
    - Deeltijdwijziging → "artikel 3.3"
    - Etc.

- [ ] Implement audit-report generator
  - ReportService.auditReport(startDate, endDate, medewerkerId?, artikel?)
  - Filters: period, medewerker, CAO-artikel type
  - Output: PDF table with columns: datum, veld, oude_waarde, nieuwe_waarde, cao_artikel, user, approval
  - Signature/attestation line for controller
  - Export: CSV/Excel variant

- [ ] Implement medewerker-dossier audit-timeline
  - Detail page: show all changes in chronological order
  - Group by year or event-type
  - Include: functionaris-mutaties, salariswijzigingen, verlof-opname, ziekte-periodes, etc.
  - Link to DecideskModule for bezwaar-registration (if relevant)

---

## Sprint 7: Testing, UI Refinement, Documentation

### Task 7.1: Integration tests

- [ ] Test CAO-versie lifecycle
  - Import versie → verify all 19 scales + periodieken stored
  - Lookup salaristabel by schaal/periodiek → verify bedrag correctness
  - Protect delete on status=actief → verify 409 error

- [ ] Test IKB-opbouw cycle
  - Create medewerker with IKB 17.5%
  - Run monthly batch → verify opbouw = salary × 0.175
  - Submit IKB-opname request → verify saldo-reduction + fiscal routing
  - Year-end → verify final payout + 2025 account creation

- [ ] Test ziekte-periode state machine
  - Create ziekteperiode on startDatum
  - Verify huidigPercentage = 100, verwachtOvergang = +52 weeks
  - Advance time to +52 weeks → verify auto-transition to 70%
  - Test samentelling: re-illness within 4 weeks → verify counter-continuation

- [ ] Test BWGR-berekening
  - Ontslag na 12,5 jaar reorganisatie → verify BWGR 20% × 24 maanden
  - Ontslag na 15 jaar reorganisatie → verify BWGR 25% × 36 maanden
  - Verify wachtgeld-activering post-BWGR (no overlap)

- [ ] Test ABP-aansluiting
  - New medewerker without ABP-nr → loonrun blocked with "Wachten op ABP"
  - SOAP mock: return deelnemernummer → verify field update
  - Schaalwijziging → verify ABP-mutatie SOAP call
  - Ontslag → verify ABP-afmelding SOAP call

- [ ] Test audit-trail
  - Periodieke verhoging → verify audit record with artikel 3.4
  - Schaalwijziging → verify before/after + artikel 3.2
  - Verify timestamp, user, IP logged
  - Export audit-report → verify PDF generation + correctness

### Task 7.2: End-to-end functional tests

- [ ] Scenario: New hire at gemeente Amsterdam
  1. HR-adviseur creates medewerker with CAO Gemeenten binding
  2. HR21-functiehuis lookup: "BELEIDSMEDEWERKER-II"
  3. Auto-restrict schaal to 9-11
  4. Select schaal 10, periodiek 4 → brutoMaandsalaris auto-fills (€ 3.897)
  5. ABP-deelnemernummer missing → HR manually enters or system auto-requests
  6. Save → creates Medewerker_Rechtspositie record
  7. Verify audit-trail: "Nieuwe aanstelling artikel X.X"

- [ ] Scenario: Monthly payroll with IKB + ziekte
  1. Loonrun: process 100 medewerkers
  2. Verify IKB-opbouw: 100 × €682 = €68,200 total
  3. One medewerker in ziekteperiode (week 35, 100%) → salary × 1.0
  4. One medewerker in ziekteperiode (week 60, 70%) → salary × 0.7
  5. Verify payroll output includes correct amounts
  6. Verify audit-trail per medewerker

- [ ] Scenario: IKB-opname workflow
  1. Medewerker self-service: "Request € 1.200 extra verlof"
  2. Manager approves
  3. Verify: IKB-saldo reduced, verlof-uren added, no fiscal impact
  4. Medewerker self-service: "Request € 2.000 contante uitbetaling"
  5. Manager approves
  6. Verify: next payroll includes bruto-loon item + loonheffing

### Task 7.3: UI/UX polish & accessibility

- [ ] Schaal/periodiek selector
  - Keyboard-navigable (tabbing, arrow keys)
  - ARIA labels + descriptions
  - Error states: clear red borders + help text
  - Mobile-responsive: dropdown on mobile, full-list on desktop

- [ ] Audit-report interface
  - Date-range picker with presets (This Year, Last Quarter, Custom)
  - Medewerker typeahead search
  - Filter by CAO-artikel type (periodieke verhoging, schaalwijziging, etc.)
  - Sort by date, medewerker, artikel
  - "Download PDF" and "Download CSV" buttons

- [ ] IKB-account management
  - Tabbed interface: "Opbouw", "Opnames", "Saldo-evolution"
  - Mobile: drawer for request submission
  - Real-time saldo display
  - FAQ: "What is IKB?", "What can I use it for?", "Year-end rules"

### Task 7.4: Documentation & training

- [ ] Administrator guide: "CAO Gemeenten setup"
  - Step-by-step: import CAO version from VNG PDF
  - Configure gemeente parameters (IKB%, vacation days, etc.)
  - Validate after import
  - Create test records (seed data)

- [ ] HR-adviseur quick-start: "New hire in 10 minutes"
  - Screenshot walkthrough: functie lookup → schaal → periodiek → save
  - Common errors: "Schaal out of HR21 range" → how to fix
  - ABP-deelnemernummer: manual entry vs. auto-request

- [ ] Salarisadministrateur checklist: "Monthly payroll"
  - Pre-run: verify all medewerkers have active rechtspositie record
  - Post-run: verify IKB, ziekte-percentage, ABP-amounts
  - Resolve: ABP-pending, schaal-conflicts, etc.
  - Export: loonstroken + audit-trail for controller

- [ ] Controller audit-guide: "Quarterly compliance check"
  - Generate audit-report for period
  - Validate: all salariswijzigingen have CAO-artikel reference
  - Check: no orphaned IKB-opnames, no negative saldi
  - Sign: attestation on PDF
  - Archive: audit-report in compliance-folder

- [ ] API documentation (OpenAPI 3.0)
  - Endpoints: POST/GET cao-versions, salaristabel-schalen, medewerker-rechtspositie, ikb-rekeningen, etc.
  - Examples: import CAO, lookup salary, create/update medewerker, etc.
  - Error codes: 400 (validation), 409 (conflict), 422 (business-rule), etc.

---

## Quality Assurance & Sign-off

### Task QA.1: Code review checklist

- [ ] Schemas: all required fields present, PascalCase, schema.org compliance
- [ ] Validations: business rules enforced server-side (not just UI)
- [ ] Audit-trail: all mutations logged with user + timestamp
- [ ] Error handling: no stack traces in responses, user-friendly messages
- [ ] Permissions: RBAC checks on all endpoints (HR-adviseur vs. salarisadministrateur vs. manager vs. controller)
- [ ] Performance: salaristabel lookups cached, batch jobs optimized for 10k+ medewerkers

### Task QA.2: Data integrity tests

- [ ] No orphaned references: deleted CAO version → verify no medewerker still bound to it
- [ ] Immutable records: active CAO version cannot be modified, only archived
- [ ] Audit-trail completeness: 100% of mutations captured
- [ ] Deduplication: no duplicate IKBRekening per (medewerker, jaar)
- [ ] Constraint enforcement: (caoCode, versieNummer) uniqueness, etc.

### Task QA.3: Performance profiling

- [ ] Salaristabel import: < 5 seconds for 19 scales
- [ ] Monthly IKB batch: < 10 seconds for 10,000 medewerkers
- [ ] Audit-report generation: < 30 seconds for 1 year of data
- [ ] Medewerker-detail page: < 2 seconds to load (includes relations, audit-trail snippet)

### Task QA.4: User acceptance testing

- [ ] [ ] HR-adviseur: new aanstelling scenario (Task 7.2 #1) — target time < 10 minutes
- [ ] [ ] Salarisadministrateur: monthly payroll cycle (Task 7.2 #2) — 0 errors on test data
- [ ] [ ] Manager: verlof-goedkeuring workflow — intuitive approval UI
- [ ] [ ] Controller: audit-report generation — report correctness + signatuur-line

### Task QA.5: Handover & go-live

- [ ] [ ] Documentation complete & reviewed
- [ ] [ ] Training sessions scheduled (HR, salaris, audit)
- [ ] [ ] Seed data loaded (3 municipalities, 50+ test medewerkers)
- [ ] [ ] Backup & disaster-recovery procedures documented
- [ ] [ ] Monitoring & alerting configured (ABP-aanmeldingen, loonrun blockers, audit-trail anomalies)
- [ ] [ ] Sign-off by stakeholders (VNG, gemeente-deelnemers, legal/compliance)

---

## Dependencies & Blockers

- **Dependency:** payroll-engine-nl must be available and integrated for salary calculation
- **Dependency:** ABP UPA SOAP API credentials + certificate must be obtained
- **Dependency:** HR21-functiehuis API or static data export must be available
- **Blocker:** If ABP-API unavailable → defer ABP-aansluiting tasks to Phase 2

---

## Success Criteria

- [x] All 10 requirement scenarios (REQ-001 through REQ-010) passing in integration tests
- [x] IKB-opbouw accuracy: calculated vs. manual verification = ±0.01 EUR
- [x] BWGR-berekening: tested on 5+ diensttijd/ontslagrond combinations, all match CAO artikel 10.3
- [x] Audit-trail: 100% mutation coverage, no orphaned records
- [x] ABP-integration: SOAP calls verified with mock service
- [x] Performance: batch jobs (IKB, periodieke verhoging, ziekte-transitie) execute in < 10s for 10k medewerkers
- [x] UAT: all 4 personas (HR, salaris, manager, controller) sign off on functional scenarios
- [x] Documentation: 5 guides complete + reviewed

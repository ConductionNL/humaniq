---
status: draft
app: hrmq
spec: comp-planning-cycle
version: 0.1.0
owners: [hrmq-team]
target-users: [manager, hr-business-partner, reward-manager, cfo, employee, exco]
deps: [employee-master, payroll-engine-nl]
standards: [salarisbanden-functiehuis, compa-ratio, Hay-of-equivalente-functiewaardering, EU-Pay-Transparency-Directive-2023-970]
---

# Annual Compensation Planning Cycle

## Placement & Information Architecture

**Placement type:** `DETAIL_TAB` — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).

**Lives at:** Medewerkers › Functie & comp

**Rationale:** Jaarlijkse comp-cyclus tab.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

De jaarlijkse compensation-planning-cyclus is het ritueel waarin organisaties — typisch tussen oktober en januari, met effectuering per 1 januari — voor elke werknemer afzonderlijk besluiten over: salarisverhoging (al dan niet binnen-band-stap), bonus-allocatie (variabel deel), en mogelijke promotie/her-grading. Dit proces is in veel organisaties ondermaats ondersteund — managers werken met Excel-spreadsheets, budget-allocaties worden inconsistent geïnterpreteerd, en de uiteindelijke compensation-letters worden handmatig samengesteld met hoog foutrisico.

Deze spec definieert een gestructureerde annual comp-planning-cyclus binnen hrmq. De cyclus brengt drie data-stromen samen: (a) het functiehuis met salarisbanden en target compa-ratio's; (b) de performance-uitkomst per werknemer (uit `performance-management-advanced`); (c) het top-down toegekende loonsverhogings- en bonusbudget vanuit CFO/ExCo per organisatie-eenheid. De cyclus orkestreert een meerstaps workflow (manager-voorstel → HR-BP-review → Reward-Committee-akkoord → CFO-approval → letter-generatie → payroll-effectuering) met budget-bewaking, uitzonderingen-tracking, en volledig audit-trail.

Met het oog op de **EU Pay Transparency Directive (2023/970/EU)** — implementatiedeadline 7 juni 2026 — is transparantie een eerste-orde-eis: medewerkers krijgen recht op informatie over salarisband, gendergap-rapportages worden verplicht, en pay-equity-checks tijdens een ronde voorkomen het ontstaan van ongerechtvaardigde verschillen. Deze spec verankert die transparantie en pay-equity-controle in de cyclus.

## Data Model

- **CompCycle** — `cyclus_id`, `jaar`, `effectief_per` (default 1-1-{jaar+1}), `status` (planning/in-progress/cfo-approval/letters/effectuering/closed), `totaal_loonsverhoging_budget_pct`, `totaal_bonus_budget_pct`, `pay_equity_check_status`, `transparency_disclosure_brief_url`.
- **BudgetAllocatie** — `cyclus_ref`, `kostenplaats_of_unit_ref`, `verantwoordelijke_manager_ref`, `loonsverhoging_budget_eur`, `bonus_budget_eur`, `besteed_loonsverhoging_eur`, `besteed_bonus_eur`, `restant_eur`, `over_budget_flag`.
- **CompVoorstelEmployee** — `cyclus_ref`, `employee_ref`, `huidige_salaris`, `huidige_band_ref`, `huidige_compa_ratio`, `performance_input_ref` (uit performance-cycle), `voorgestelde_loonsverhoging_pct`, `voorgestelde_loonsverhoging_eur`, `nieuw_salaris`, `nieuwe_compa_ratio`, `voorgestelde_bonus_eur`, `promotie_voorstel_bool`, `nieuwe_rol_ref_optional`, `nieuwe_band_ref_optional`, `equity_flag` (auto-gezet bij outlier), `manager_onderbouwing`, `status` (concept/manager-submit/hrbp-review/committee-review/cfo-approved/rejected).
- **SalarisBand** — `band_code`, `functie_familie`, `niveau`, `min_eur`, `mid_eur` (target compa-ratio 1.0), `max_eur`, `valuta`, `geldig_per_datum`, `bron` (Hay/Korn-Ferry/Mercer/intern).
- **PayEquityCheck** — `cyclus_ref`, `dimensie` (gender/leeftijd/nationaliteit), `band_ref`, `groep_a_label`, `groep_a_gemiddelde_eur`, `groep_b_label`, `groep_b_gemiddelde_eur`, `gap_pct`, `gap_signaal` (groen <3%, geel 3-5%, rood >5%), `actie_aanbeveling`.
- **CompensationLetter** — `cyclus_ref`, `employee_ref`, `letter_versie`, `oud_salaris`, `nieuw_salaris`, `loonsverhoging_pct`, `bonus_eur`, `promotie_text_optional`, `effectief_per`, `gegenereerd_datum`, `verstuurd_datum`, `acknowledged_door_employee_datum`, `pdf_url`.

## Requirements

### REQ-001: CFO opent cyclus met top-down budget

**GIVEN** ExCo heeft een loonsverhogingsbudget en bonus-budget per business unit vastgesteld  
**WHEN** de Reward Manager een nieuwe `CompCycle` aanmaakt  
**THEN** worden per unit `BudgetAllocatie`-records aangemaakt en gepubliceerd aan de eindverantwoordelijke managers; de cyclus krijgt status `planning`; managers ontvangen een taak met de te verwachten timeline en hun beschikbare budget.

### REQ-002: Manager-voorstel per directe rapporteur

**GIVEN** een manager heeft een open compensatie-cyclus voor zijn team  
**WHEN** de manager het comp-planning-scherm opent  
**THEN** ziet de manager voor elke directe rapporteur: huidig salaris, huidige compa-ratio, salarisband, performance-input uit de laatste cyclus, en velden om een voorstel in te voeren (loonsverhoging %, bonus €, promotie ja/nee); een live budget-teller toont consumptie versus `BudgetAllocatie`.

### REQ-003: Compa-ratio-validatie binnen band

**GIVEN** een manager voert een loonsverhoging in  
**WHEN** het nieuwe salaris berekend wordt  
**THEN** controleert het systeem of de `nieuwe_compa_ratio` binnen de band (min-max) blijft; bij overschrijding wordt een `equity_flag` gezet en wordt een onderbouwing van de manager vereist; bij gerechtvaardigde overschrijding (bv. "schaarse skill, marktconforme correctie") gaat het voorstel met flag door naar HR-BP-review.

### REQ-004: Budget-overschrijding blokkeert manager-submit

**GIVEN** een manager wil zijn comp-voorstel indienen  
**WHEN** de som van loonsverhogingen of bonussen het `BudgetAllocatie`-bedrag overschrijdt  
**THEN** blokkeert het systeem de submit, toont de overschrijding (€ en %), en biedt twee opties: (a) reduceer voorstellen tot binnen budget, of (b) verzoek bij de HR-BP om budget-uitbreiding (deze creëert een task naar de HR-BP met onderbouwing).

### REQ-005: Pay-equity-check vóór HR-BP-akkoord

**GIVEN** een manager heeft zijn voorstellen ingediend  
**WHEN** de HR-BP de review opent  
**THEN** draait het systeem automatisch een `PayEquityCheck` per band binnen scope op dimensies gender/leeftijd/nationaliteit; gaps boven 5% genereren een rood signaal en vereisen mitigerende voorstellen of expliciete onderbouwing waarom de gap acceptabel is (bv. "gap door anciënniteit, geen functioneringsverschil") voordat de cyclus naar CFO-approval kan.

### REQ-006: Workflow manager → HR-BP → Reward Committee → CFO

**GIVEN** een `CompVoorstelEmployee` is door de manager ingediend  
**WHEN** de status wordt doorgezet  
**THEN** volgt het record een vaste workflow: (1) manager-submit, (2) HR-BP-review (kan retourneren met opmerking), (3) Reward-Committee-review (alleen bij promoties of outliers met `equity_flag`), (4) CFO-approval (op aggregaat-niveau per unit, niet per individu); elke status-overgang wordt gelogd met actor, timestamp en eventuele opmerking.

### REQ-007: Compensation-letter-generatie per werknemer

**GIVEN** alle voorstellen binnen een unit zijn CFO-approved  
**WHEN** de Reward Manager de letter-generatie triggert  
**THEN** wordt voor elke werknemer een `CompensationLetter` aangemaakt op basis van een organisatie-template, met variabelen ingevuld (oude/nieuwe salaris, %, bonus, promotie-tekst, effectief per); letters worden gegenereerd als PDF en gearchiveerd in document-storage; verzending verloopt via het werknemersportaal met acknowledgment-tracking.

### REQ-008: Payroll-effectuering per 1 januari (of configureerbaar)

**GIVEN** alle letters zijn gegenereerd en verstuurd  
**WHEN** de `effectief_per`-datum nadert (T-7 dagen)  
**THEN** wordt een payroll-mutation-batch klaargezet richting `payroll-engine-nl` met alle salarisaanpassingen; finance/HR-admin krijgt een approval-taak om de batch te valideren; na approval worden de Employee-records bijgewerkt en gaat de mutation mee in de eerstvolgende loonrun.

### REQ-009: Werknemer-transparantie conform EU Pay Transparency Directive

**GIVEN** een werknemer heeft een compensation-letter ontvangen  
**WHEN** de werknemer het comp-detail-scherm opent  
**THEN** ziet de werknemer naast de eigen aanpassing: de salarisband-range (min/mid/max) van de eigen rol, de eigen compa-ratio, en op verzoek (op aparte pagina, gelogd) de geanonimiseerde gendergap binnen de eigen band en functie-familie — conform de informatierechten uit Richtlijn 2023/970/EU.

### REQ-010: Cyclus-afsluiting met retrospectieve rapportage

**GIVEN** alle effectueringen zijn verwerkt in payroll  
**WHEN** de Reward Manager de cyclus sluit  
**THEN** genereert het systeem een afsluitend cyclus-rapport: totaal besteed loonsverhogingsbudget vs. budget, gemiddelde verhoging per band/unit, distributie van verhogingen, aantal promoties, finale pay-equity-stand vs. cyclusstart, aantal outliers/excepties; rapport wordt gearchiveerd en gedeeld met ExCo + RvC; cyclus-status wordt `closed` en data wordt locked (alleen audit-leesbaar).

## Standards & Compliance

- **EU Pay Transparency Directive (2023/970/EU)** — implementatiedeadline 7 juni 2026; informatierechten werknemers, gender pay gap reporting (vanaf 100+ medewerkers), pay-equity-audit bij gap >5%.
- **Functiehuis met salarisbanden** — methodes Hay (Korn Ferry), Mercer IPE, Towers Watson Global Grading; band-mid representeert target compa-ratio 1.0.
- **Compa-ratio** — salaris ÷ band-mid; gangbare interpretatie: 0.8-0.9 ontwikkelend, 0.95-1.05 op niveau, 1.05-1.20 senior-in-band.
- **AVG** — salarisdata is gevoelig persoonsgegeven; toegangscontrole strikt op need-to-know-basis; gendergap-rapportages alleen geaggregeerd publiceren (k-anonimiteit drempel >5).
- **WOR (Wet op de Ondernemingsraden)** — instemming-plichtig bij wijziging beoordelings- en beloningssysteem; cyclus-design moet aantoonbaar OR-geconsulteerd zijn.
- **Wet Gelijke Behandeling** — verbod loondiscriminatie naar geslacht, leeftijd, ras, religie, seksuele oriëntatie; pay-equity-check operationaliseert deze toetsing.

## Cross-app Dependencies

- **employee-master** — werknemer-master met huidig salaris, huidige rol/band, manager-hierarchie voor budget-allocatie-scope.
- **payroll-engine-nl** — eindbestemming voor de batch van salarisaanpassingen; bron voor huidig salaris en arbeidsvoorwaarden.
- **performance-management-advanced** — `performance_input_ref` levert OKR-eindscores en 9-box-segment als evidence voor het manager-voorstel.
- **finance-export** — totaal-budget vanuit ExCo, allocatie per kostenplaats, terugkoppeling werkelijk besteed bedrag.
- **document-storage** — compensation-letter-archief, cyclus-eindrapport.
- **task-management** — workflow-taken per status-overgang, budget-uitbreidings-verzoeken, manager-rappels.
- **audit-log** — alle status-overgangen, salaris-reads, pay-equity-disclosure-reads.

## Target Users

- **Manager** — doet voorstellen voor directe rapporteurs binnen toegekend budget.
- **HR Business Partner** — reviewt manager-voorstellen, valideert pay-equity, escaleert outliers.
- **Reward Manager** — orchestreert de cyclus, beheert salarisbanden, genereert letters, sluit af met rapportage.
- **Reward Committee** — beoordeelt outliers, promoties, equity-flag-onderbouwingen.
- **CFO** — keurt budget-aggregaten goed per unit; ontvangt slot-rapportage.
- **Werknemer** — ontvangt compensation-letter, heeft transparency-rechten op band + gendergap.
- **ExCo** — stelt jaar-budget vast, ontvangt slot-rapportage incl. pay-equity-stand.
- **OR (Ondernemingsraad)** — gerelateerde stakeholder voor systeem-wijziging-instemming.

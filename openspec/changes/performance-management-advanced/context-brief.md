---
status: draft
app: hrmq
spec: performance-management-advanced
version: 0.1.0
owners: [hrmq-team]
target-users: [employee, manager, hr-business-partner, talent-board, exco, reward-manager]
deps: [employee-master, performance-review-cycle]
standards: [OKR-methodologie, 9-box-talent-grid, GDPR-DPIA-employee-monitoring]
---

# Performance Management Advanced (OKR + 9-box + Kalibratie)

## Placement & Information Architecture

**Placement type:** `DETAIL_TAB` — Tab on the detail view of an existing object. NOT a standalone page — appears inside the parent record's detail surface (e.g. an extra tab on the existing detail header).

**Lives at:** Medewerkers › Functie & comp

**Rationale:** OKR/9-box/Kalibratie tab.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Veel organisaties zijn voorbij het klassieke jaarlijkse beoordelingsgesprek. Hedendaagse performance-praktijk combineert **OKR (Objectives & Key Results)** voor doelstellingen en kwartaalvoortgang, **9-box talent grid** voor de tweedimensionale weging van prestatie (performance) versus groeipotentieel (potential), **kalibratiesessies** om manager-bias eruit te kalibreren binnen een team/business unit, en **continuous feedback** als doorlopende laag onder de cyclische rituelen.

Deze spec breidt de basis-`performance-review-cycle` uit met deze geavanceerde elementen. De spec is bewust modulair: organisaties kunnen losse elementen aanzetten (alleen OKR, of alleen 9-box). De spec gaat ook in op de governance: wie ziet welke data (9-box is doorgaans manager-en-hoger, niet zichtbaar voor de werknemer zelf), hoe wordt kalibratie auditeerbaar vastgelegd, hoe wordt de link naar reward (bonus-allocatie, promotie-recommendatie) gestructureerd zonder een mechanistische "rating × multiplier"-koppeling die in veel organisaties als unfair wordt ervaren.

Belangrijke ontwerpprincipes: (a) OKR-doelen zijn maximaal 3-5 per persoon per kwartaal, met 2-4 key results per doel; (b) 9-box-data is gevoelig (potential-rating kan AVG-implicaties hebben), dus expliciete toegangscontrole en bewaarbeperking; (c) kalibratie is een proces-event, geen losse rating-aanpassing — wijzigingen moeten een onderbouwing en participantenlijst hebben; (d) continuous-feedback-tool is laagdrempelig (kudos, vraag-om-feedback, peer-input) en feed de eindbeoordeling met evidence in plaats van met scores.

## Data Model

- **OKRCycle** — `cyclus_id`, `periode` (Q1-2026), `start`, `eind`, `bedrijfsdoelen` (array — strategische OKRs cascading source), `status` (open/in-progress/calibratie/gesloten).
- **OKR** — `cyclus_ref`, `eigenaar` (ref Employee), `level` (company/bu/team/individu), `objective_titel`, `objective_beschrijving`, `parent_okr_ref_optional` (cascading), `key_results` (array van `{titel, baseline, target, type: number|percentage|binary, current_value, last_update}`), `confidence_score` (1-10), `eindscore` (0.0-1.0 op moment van afsluiting).
- **NineBoxAssessment** — `assessment_id`, `cyclus_ref`, `subject_employee_ref`, `assessor_employee_ref` (manager), `performance_axis` (low/medium/high), `potential_axis` (low/medium/high), `talent_segment` (afgeleid: cell 1-9, e.g. "high-performer/high-potential = star"), `onderbouwing`, `aangepast_na_kalibratie` (bool), `confidential_level` (manager-only/hrbp-also/talent-board-also).
- **KalibratieSessie** — `sessie_id`, `cyclus_ref`, `scope` (team/business-unit/functiehuis-laag), `deelnemers` (array refs), `facilitator_ref`, `datum`, `agenda_employees` (array — beoordeelden in scope), `kalibratie_log` (array `{employee_ref, voor_rating, na_rating, onderbouwing, beslissing_consensus_of_overrule, overruled_door_optional}`), `besluiten_summary`, `gearchiveerd`.
- **ContinuousFeedback** — `feedback_id`, `gever_ref`, `ontvanger_ref`, `type` (kudos/constructief/feedback-vraag-antwoord), `tekst`, `gerelateerd_okr_ref_optional`, `gerelateerd_competentie_optional`, `zichtbaarheid` (alleen-ontvanger/ook-manager/team-public), `aangemaakt_datum`, `meegenomen_in_review_cycle_optional`.
- **RewardLink** — `cyclus_ref`, `subject_employee_ref`, `bonus_voorstel` (€ of %), `promotie_voorstel` (bool + nieuwe rol-ref), `salarisaanpassing_voorstel`, `onderbouwing_referentie` (refs naar OKR-eindscores + 9-box + kalibratie-besluit), `besloten_door`, `effectuering_datum`.

## Requirements

### REQ-001: OKR-cascade van bedrijfsstrategie tot individu

**GIVEN** ExCo heeft bedrijfsdoelen voor cyclus Q1-2026 geformuleerd  
**WHEN** een manager voor zijn team OKRs opstelt  
**THEN** kan de manager elke team-OKR linken aan een parent company-OKR via `parent_okr_ref`; het systeem visualiseert de cascade als boom en signaleert "weeskinderen" (individuele OKRs zonder gelinkte parent) als governance-signaal voor de HR-BP.

### REQ-002: OKR-progress-update per kwartaal

**GIVEN** een werknemer heeft actieve OKRs voor het lopende kwartaal  
**WHEN** elke 2 weken een tooling-trigger afgaat (configurable)  
**THEN** ontvangt de werknemer een taak om per Key Result de `current_value` bij te werken en een `confidence_score` (1-10) af te geven; verlaagde confidence-scores genereren een check-in-taak voor de manager.

### REQ-003: OKR-eindscore is grade, niet pay-trigger

**GIVEN** het kwartaal eindigt  
**WHEN** de werknemer alle key-results afsluit met `current_value`  
**THEN** berekent het systeem een `eindscore` per Key Result (current/target, gecapped op 1.0) en aggregeert dit per OKR; het systeem labelt scores 0.7-1.0 als "succesvol", 0.4-0.7 als "leerzaam-gefaald", <0.4 als "te ambitieus of geblokkeerd"; deze scores worden NIET automatisch doorgegeven aan reward-allocatie maar dienen als bewijsmateriaal in `RewardLink.onderbouwing_referentie`.

### REQ-004: 9-box-assessment met dual-axis input

**GIVEN** een kalibratie-cyclus is geopend voor een business unit  
**WHEN** een manager voor elke directe rapporteur een `NineBoxAssessment` invoert  
**THEN** vereist het systeem zowel de `performance_axis` (op basis van laatste 12 maanden outputs) als de `potential_axis` (groeipotentieel volgend 2-3 jaar), met een verplichte tekstuele onderbouwing van minimaal 200 tekens per as; lege of zeer korte onderbouwingen worden geweigerd.

### REQ-005: 9-box-zichtbaarheid strikt manager-en-hoger

**GIVEN** een werknemer logt in op zijn/haar profielpagina  
**WHEN** het profiel wordt gerenderd  
**THEN** ziet de werknemer GEEN 9-box-segmentatie of potential-rating van zichzelf; deze data is alleen zichtbaar voor de directe manager, de HR-BP van die unit, en (indien `confidential_level` zo gezet) de talent-board; alle reads van 9-box-data worden gelogd in audit-trail.

### REQ-006: Kalibratiesessie als governance-event

**GIVEN** een HR-BP plant een `KalibratieSessie` voor een unit  
**WHEN** de sessie wordt vastgelegd  
**THEN** worden alle managers binnen scope uitgenodigd als deelnemer, hun pre-kalibratie-9-box-assessments worden in een matrix gepresenteerd, en elke wijziging tijdens de sessie wordt vastgelegd in `kalibratie_log` met de onderbouwing en de besluitvorm (consensus / facilitator-overrule); na afsluiting worden de 9-box-records bijgewerkt met `aangepast_na_kalibratie=true`.

### REQ-007: Kalibratie-verdelingscheck

**GIVEN** een kalibratiesessie loopt voor een team van 20 medewerkers  
**WHEN** de facilitator de live distributie monitort  
**THEN** toont het systeem een heatmap van de 9 cellen met huidige aantallen plus configureerbare richtbandbreedtes (default: stars 10-20%, core-players 40-60%, risks <10%); afwijkingen worden visueel gemarkeerd om kalibratie-discussie te triggeren, zonder dwingend te forceren ("forced distribution" is configurable maar default uit).

### REQ-008: Continuous-feedback laag onder de cyclus

**GIVEN** een werknemer of collega wil tussen review-momenten feedback geven  
**WHEN** deze de feedback-tool opent  
**THEN** kan een `ContinuousFeedback`-record worden aangemaakt met type (kudos/constructief/vraag-antwoord) en zichtbaarheid; de werknemer kan ook expliciet feedback OPVRAGEN bij geselecteerde collega's (peer-input-request); aggregaten zijn beschikbaar als evidence-bundel tijdens de volgende review-cyclus.

### REQ-009: Reward-link als onderbouwde aanbeveling, niet als formule

**GIVEN** een cyclus is afgesloten en kalibratie is voltooid  
**WHEN** de reward-manager de bonus-/promotie-/salarisronde voorbereidt  
**THEN** krijgt elke manager per directe rapporteur een `RewardLink`-conceptrecord met geaggregeerde onderbouwing (OKR-eindscores, 9-box-positie, kalibratiebesluit, continuous-feedback-aggregaten); de manager doet een voorstel dat door de HR-BP en eventueel de Reward Committee wordt gereviewd; er is geen automatische "9-box-cell × multiplier"-berekening.

### REQ-010: AVG-bewaarbeperking en DPIA-vereiste

**GIVEN** 9-box-assessments bevatten potential-ratings (beoordeling van toekomstig functioneren)  
**WHEN** een assessment ouder is dan 24 maanden EN de werknemer niet meer in dienst is  
**THEN** anonimiseert/verwijdert het systeem het record automatisch; bij het inschakelen van de 9-box-functionaliteit toont het systeem een DPIA-checklist en vereist het de bevestiging dat een DPIA is uitgevoerd voor deze vorm van employee monitoring (Art. 35 AVG).

## Standards & Compliance

- **OKR (Objectives & Key Results)** — methodologie van Andy Grove (Intel) en gepopulariseerd door John Doerr; kenmerken: ambitieuze doelen, meetbare key results, transparant, gescheiden van compensatie.
- **9-box talent grid** — verspreid door McKinsey/GE; performance vs potential 3×3 matrix; segmenten zoals "star", "core-player", "underperformer".
- **Calibration sessions** — best practice in toonaangevende organisaties om manager-bias te neutraliseren; vereist gedocumenteerde besluitvorming.
- **Continuous performance management** — verschuiving sinds ~2015 weg van jaargesprek-only (Deloitte, Adobe, GE als bekende cases).
- **AVG/GDPR Art. 35 DPIA** — verplichte impactanalyse bij employee monitoring inclusief potential-rating.
- **Bewaartermijnen** — beoordelingen van ex-werknemers max 2 jaar tenzij bewijsnoodzaak (Autoriteit Persoonsgegevens-richtsnoer).

## Cross-app Dependencies

- **employee-master** — subject en assessor refs; organogram voor scope-bepaling kalibratiesessies.
- **performance-review-cycle** — base spec; deze spec extends en hergebruikt de cyclus-orchestratie.
- **compensation-management** — `RewardLink` voedt de bonus- en salarisverhogingsronde (zie `comp-planning-cycle` spec).
- **task-management** — check-in-taken, kalibratie-uitnodigingen, peer-feedback-verzoeken.
- **document-storage** — kalibratie-log-archief, onderbouwingsdocumenten.
- **audit-log** — alle reads van 9-box-data worden gelogd (AVG-vereiste).

## Target Users

- **Werknemer** — beheert OKRs, vraagt en geeft continuous-feedback, ziet eigen review-uitkomst (NIET 9-box).
- **Manager** — formuleert team-OKRs met cascade, beoordeelt op 9-box, neemt deel aan kalibratie, doet reward-voorstel.
- **HR Business Partner (HR-BP)** — faciliteert kalibratiesessies, monitort distributie, valideert reward-voorstellen.
- **Talent-board (ExCo + CHRO)** — ziet 9-box-overzicht op senior niveau, identificeert succession-pool en risico's.
- **Reward Manager** — coördineert de reward-rondes op basis van `RewardLink`-conceptrecords (zie `comp-planning-cycle`).
- **ExCo** — definieert company-OKRs als cascade-bron.

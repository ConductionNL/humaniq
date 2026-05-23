---
status: draft
---
# Recruiting ATS Basic voor HRMQ

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Onboarding & ATS › Vacatures+Kandidaten

**Rationale:** Basic ATS.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Het `recruiting-ats-basic` spec definieert een lichtgewicht Applicant Tracking System binnen HRMQ, gericht op MKB-werkgevers die per jaar 5-50 vacatures uitzetten en die geen behoefte hebben aan zware enterprise-ATS-pakketten zoals Workday Recruiting of SuccessFactors. Het systeem dekt de complete sollicitant-lifecycle: vacature opstellen, publiceren naar externe kanalen (werk.nl, LinkedIn, eigen carriere-site), sollicitaties ontvangen en pipeline-managen, interviews plannen, offer letters genereren, en na hire automatisch doorzetten naar de onboarding-wizard. Bij afwijzing wordt de sollicitant in een GDPR-conforme talent pool bewaard.

De drie pijnpunten in MKB-recruitment die dit spec adresseert: ten eerste **versnippering van vacature-publicatie** — vandaag knipt de HR-medewerker dezelfde vacature-tekst handmatig over werk.nl, LinkedIn Jobs, Indeed, en de bedrijfssite, met een hoog risico op inconsistentie en vertraging bij updates. Ten tweede **e-mail-gebaseerd pipeline-management** — sollicitaties komen binnen via een gedeeld postvak, statusupdates leven in iemands hoofd of een spreadsheet, en de communicatie met kandidaat verzandt regelmatig (kandidaten ervaren dit als "ghosting" en het schaadt de werkgevers-reputatie). Ten derde **GDPR-risico bij afwijzing** — werkgevers bewaren CV's en motivatiebrieven jaren langer dan toegestaan, zonder expliciete toestemming, omdat een gestructureerd verwijderings-proces ontbreekt.

HRMQ Recruiting ATS Basic biedt één plek waar de vacature wordt opgesteld en gepubliceerd, één pipeline waar alle sollicitaties doorheen lopen, en een geautomatiseerde retentie-flow die afgewezen sollicitaties standaard na vier weken verwijdert, tenzij de kandidaat expliciet toestemming geeft om in de talent pool te blijven (dan één jaar). Het systeem is bewust "basic" — geen AI-screening, geen psychometrische tests, geen video-interview-platform; die functionaliteit kan later worden toegevoegd (zie HRMQ-roadmap) maar valt buiten deze MVP.

Het systeem hangt sterk samen met `onboarding-wizard`: zodra een Application status `hire` bereikt, wordt automatisch een onboarding-traject gestart waarin het CV, persoonlijke gegevens, en de offer-letter-data al voor-ingevuld zijn — geen handmatige overdracht meer.

## Data Model

`Vacancy` is het hoofdobject voor een openstaande positie. Velden: `titel`, `functie_schaal` (CAO-schaal of intern niveau), `locatie` (kantoor/hybride/remote met postcode), `contract_type` (vast, tijdelijk_jaar, tijdelijk_7maanden, oproep, stage, freelance), `uren_per_week_min`, `uren_per_week_max`, `salaris_indicatie_min`, `salaris_indicatie_max`, `salaris_zichtbaar` (boolean), `beschrijving_markdown`, `eisen_markdown`, `aangeboden_markdown`, `sluitingsdatum`, `gewenste_startdatum`, `status` (concept, open, gesloten, vervuld, ingetrokken), `aangemaakt_door`, `hiring_manager_id`, `publicatie_kanalen` (lijst met enum: werknl, linkedin, eigen_site, indeed_zelf).

`Application` is een sollicitatie op een Vacancy. Velden: `vacancy_id`, `kandidaat_naam`, `kandidaat_email`, `kandidaat_telefoon`, `cv_file_id` (verwijzing naar OR file-attachment), `motivatie_file_id` (optioneel), `motivatie_inline_text` (optioneel als sollicitant geen brief upload), `ingediend_op`, `bron` (werknl, linkedin, eigen_site, doorverwijzing, anders), `huidige_pipeline_stage` (verwijzing naar PipelineStage), `talent_pool_consent` (boolean, default false), `talent_pool_consent_at`, `delete_after_date` (berekend: ingediend+28 dagen of consent+365 dagen).

`PipelineStage` is een waarde uit een vaste sequentie: `nieuwe_sollicitatie`, `screening`, `eerste_gesprek`, `tweede_gesprek`, `referentie_check`, `aanbieding_uitgebracht`, `geaccepteerd`, `aangenomen`, `afgewezen`, `teruggetrokken_door_kandidaat`. Per vacature kan een hiring manager stages uitschakelen (bv. geen `referentie_check`) maar de volgorde is vast.

`ApplicationEvent` is append-only en legt elke status-mutatie, communicatie, interview-planning of notitie vast. Velden: `application_id`, `event_type` (stage_change, note_added, email_sent, interview_scheduled, interview_completed, offer_sent, rejected, withdrawn), `event_at`, `actor_id`, `from_stage`, `to_stage`, `payload_json` (bv. interview-details of e-mail-content).

`Interview` is een geplande afspraak met een kandidaat. Velden: `application_id`, `interview_type` (telefonisch, video, in_person), `nextcloud_calendar_event_id`, `start_at`, `end_at`, `interviewers` (lijst employee_id), `kandidaat_zichtbaar` (boolean), `notities_pre`, `notities_post`, `score` (1-5 of NPS-stijl). Bij plannen wordt automatisch een Nextcloud Calendar-event aangemaakt met alle interviewers als deelnemer en optioneel met een Talk-link voor video.

`OfferLetter` is het uitbrengen van een aanbod. Velden: `application_id`, `template_id`, `salaris_bruto_per_maand`, `salaris_bruto_jaar`, `startdatum`, `proeftijd_maanden`, `contract_duur_maanden` (null = onbepaalde tijd), `vakantiedagen_per_jaar`, `extra_voorwaarden_markdown`, `generated_pdf_file_id`, `verzonden_op`, `geaccepteerd_op`, `verlopen_op`, `decidesk_envelope_id` (voor digitale ondertekening).

`TalentPool` is een view-laag (geen aparte tabel) over Applications met `talent_pool_consent=true` en `delete_after_date > now()`. Recruiters kunnen hier zoeken op vaardigheden, opleiding, eerdere positie, en proactief benaderen voor nieuwe vacatures.

## Requirements

### REQ-001: Vacature opstellen en publiceren
Een gebruiker met rol `recruiter` of `hiring_manager` stelt een vacature op, kiest publicatie-kanalen, en publiceert in één klik.

GIVEN ik ben ingelogd als recruiter, WHEN ik op "Nieuwe vacature" klik, THEN toont het formulier alle Vacancy-velden met markdown-editors voor beschrijving/eisen/aangeboden en multi-select voor publicatie-kanalen.

GIVEN ik vul een vacature in en kies kanalen `werknl` en `linkedin`, WHEN ik op "publiceer" klik, THEN wijzigt de status naar `open`, wordt de vacature gepost naar werk.nl via openconnector-koppeling, wordt een LinkedIn Jobs-post aangemaakt via LinkedIn API, en verschijnt de vacature op de publieke carriere-pagina van de werkgever.

GIVEN een vacature is gepubliceerd en ik wijzig de beschrijving, WHEN ik op "update gepubliceerd" klik, THEN wordt de gewijzigde versie naar alle actieve kanalen gesynchroniseerd, en een ApplicationEvent-equivalent (VacancyEvent) wordt vastgelegd met de mutatie.

### REQ-002: Sollicitatie ontvangen
Sollicitaties komen binnen via de publieke carriere-pagina, via een werknl-redirect, of via LinkedIn Easy Apply, en landen automatisch in de pipeline op stage `nieuwe_sollicitatie`.

GIVEN een kandidaat ziet de vacature op de carriere-pagina, WHEN hij klikt op "solliciteer" en het formulier indient met CV-upload, motivatie-tekst, naam, e-mail en telefoon, THEN wordt een Application aangemaakt met `huidige_pipeline_stage=nieuwe_sollicitatie`, een ApplicationEvent van type `created`, en de hiring manager ontvangt een Nextcloud Notification.

GIVEN een kandidaat solliciteert via LinkedIn Easy Apply, WHEN de LinkedIn-webhook binnenkomt, THEN wordt zijn LinkedIn-profiel als JSON opgehaald, een PDF-CV gegenereerd uit dat profiel, en de Application aangemaakt met `bron=linkedin`.

GIVEN een kandidaat dient een sollicitatie in zonder CV, WHEN het formulier wordt verwerkt, THEN blokkeert de validatie de submission met de melding "CV is verplicht" (op de carriere-pagina) en geeft een 422 op de API.

### REQ-003: Pipeline-management
De recruiter sleept of klikt de Application door de pipeline-stages.

GIVEN ik open de pipeline-view van een vacature, WHEN deze laadt, THEN toont een kanban-board kolommen per stage, met Application-kaarten met naam, ingedienddatum, en bron-icoon.

GIVEN ik sleep een Application van `screening` naar `eerste_gesprek`, WHEN ik de kaart loslaat, THEN wordt `huidige_pipeline_stage` bijgewerkt, een ApplicationEvent van type `stage_change` vastgelegd, en een dialog opent met "wil je een gesprek plannen?" met directe link naar interview-planning.

GIVEN ik wil een Application bulk-afwijzen, WHEN ik meerdere kaarten selecteer in de `screening`-kolom en klik op "afwijzen", THEN toont een dialog een afwijzings-template (Markdown, bewerkbaar) en bij bevestigen worden alle geselecteerde Applications verschoven naar `afgewezen` met automatische verzending van de afwijzings-e-mail.

### REQ-004: Interview-planning via Nextcloud Calendar
Bij een interview wordt automatisch een Calendar-event aangemaakt voor alle deelnemers.

GIVEN ik kies "plan gesprek" voor een Application, WHEN ik selecteer 2 interviewers, type `video`, en kies datum/tijd uit een agenda-availability-overzicht, THEN wordt een Nextcloud Calendar-event aangemaakt met titel "Sollicitatiegesprek [kandidaat naam] – [vacaturetitel]", met alle interviewers als attendees, en optioneel een Talk-conversatie-link.

GIVEN de kandidaat heeft toestemming gegeven voor zichtbaarheid in zijn agenda (via een uitnodigingslink), WHEN het event wordt aangemaakt, THEN ontvangt hij een iCal-uitnodiging op zijn e-mail.

GIVEN het interview is voorbij, WHEN ik de Application open en klik op de Interview-detail, THEN toont een formulier om notities_post en score in te vullen, en bij opslaan wordt een ApplicationEvent `interview_completed` vastgelegd.

### REQ-005: Offer letter genereren
De OfferLetter wordt op basis van een template gegenereerd, optioneel ondertekend via Decidesk.

GIVEN ik klik op "uitbrengen aanbod" voor een Application in stage `tweede_gesprek`, WHEN ik vul salaris, startdatum, proeftijd, vakantiedagen, en kies de template `vast_contract_standaard`, THEN wordt een PDF gegenereerd met de OfferLetter-data ingevuld in de template, opgeslagen als file-attachment, en de Application schuift naar stage `aanbieding_uitgebracht`.

GIVEN de OfferLetter-PDF is gegenereerd, WHEN ik op "stuur via Decidesk" klik, THEN wordt een Decidesk envelope aangemaakt met de kandidaat als signer en de werkgever als counter-signer, de envelope_id wordt opgeslagen, en de kandidaat ontvangt een Decidesk-uitnodiging per e-mail.

GIVEN de kandidaat tekent de offer in Decidesk, WHEN de Decidesk webhook binnenkomt, THEN wordt `geaccepteerd_op` gezet, de Application schuift naar stage `geaccepteerd`, en de hiring manager én HR-admin ontvangen een notificatie.

### REQ-006: Hand-off naar onboarding-wizard
Zodra een Application in stage `aangenomen` komt (na geaccepteerd offer en eventuele referentiecheck), wordt automatisch een onboarding-traject gestart.

GIVEN een Application is in stage `geaccepteerd` en alle pre-employment checks zijn voltooid, WHEN ik op "start onboarding" klik (of de automatische regel triggert), THEN schuift de stage naar `aangenomen`, een nieuwe Employee-record wordt aangemaakt in employee-master met voor-ingevulde data uit de Application (naam, e-mail, telefoon) en OfferLetter (functie, schaal, startdatum, contract-type, salaris, vakantiedagen), en de onboarding-wizard wordt gestart met de Application-id als context.

GIVEN de onboarding-wizard genereert een asset-checklist en triggert payroll-engine-nl voor de eerste salarisberekening, WHEN de onboarding voltooid is, THEN wordt de Application gearchiveerd (read-only) met verwijzing naar de Employee.

### REQ-007: GDPR-conforme retentie
Afgewezen kandidaten worden standaard na vier weken verwijderd; met expliciete toestemming één jaar.

GIVEN een Application wordt verschoven naar stage `afgewezen`, WHEN de status-mutatie wordt verwerkt, THEN wordt `delete_after_date` gezet op 28 dagen na vandaag, en de afwijzings-e-mail aan de kandidaat bevat een opt-in-link "blijf in onze talent pool".

GIVEN de kandidaat klikt op de talent-pool-opt-in-link binnen 28 dagen, WHEN hij bevestigt, THEN wordt `talent_pool_consent=true`, `talent_pool_consent_at=now()`, en `delete_after_date=now()+365 dagen`, en hij ontvangt een bevestigings-e-mail met intrek-link.

GIVEN de dagelijkse retentie-batch draait, WHEN deze Applications vindt waar `delete_after_date < now()`, THEN worden CV-file, motivatie-file, naam, e-mail, telefoon, en gerelateerde Notes uit ApplicationEvents geanonimiseerd (vervangen door pseudo-ID), maar de Vacancy-statistieken (totaal-aantal sollicitaties, bron, afwijs-redenen) blijven voor analytische doeleinden.

### REQ-008: Talent pool zoeken
Recruiters zoeken in de talent pool op vaardigheden en eerdere posities.

GIVEN ik open "Talent pool", WHEN ik zoek op "Vue developer met 3+ jaar ervaring", THEN toont een gefacetteerde zoek-resultaten-lijst van Applications met `talent_pool_consent=true`, met preview van CV en motivatie, en optie om de kandidaat te benaderen voor een actuele Vacancy.

GIVEN ik wil een kandidaat uit de talent pool benaderen voor een nieuwe Vacancy, WHEN ik klik op "benader", THEN wordt een nieuwe Application aangemaakt op die Vacancy met de CV uit de talent pool, automatisch in stage `screening` (overgeslagen `nieuwe_sollicitatie` want al bekend), en een gepersonaliseerde e-mail wordt voorgesteld.

### REQ-009: Externe publicatie via openconnector
Vacatures worden gepubliceerd naar werk.nl, LinkedIn en eigen site via openconnector-integraties.

GIVEN de werkgever heeft een werk.nl-koppeling actief, WHEN een Vacancy gepubliceerd wordt, THEN wordt deze via de werk.nl API (UWV) als vacature aangemeld met vakgebied, locatie, salaris, contracttype, en de URL voor sollicitaties verwijst terug naar de HRMQ carriere-pagina.

GIVEN de LinkedIn-koppeling is actief, WHEN een Vacancy wordt gepubliceerd, THEN wordt een LinkedIn Jobs-post aangemaakt onder de werkgevers-company-page, met automatische sync van wijzigingen, en LinkedIn-sollicitaties worden via webhook ingelezen.

GIVEN de Vacancy-status wijzigt naar `gesloten`, `vervuld`, of `ingetrokken`, WHEN dit wordt verwerkt, THEN worden de externe publicaties op werk.nl en LinkedIn automatisch ingetrokken of als "niet meer actief" gemarkeerd.

### REQ-010: WCAG AA en publieke carriere-pagina
De publieke carriere-pagina (waar kandidaten solliciteren) voldoet aan WCAG 2.1 AA en is mobiel-eerst.

GIVEN een kandidaat opent de carriere-pagina op een telefoon, WHEN hij door de vacatures bladert, THEN past alle content binnen viewport, navigatie is keyboard-toegankelijk, contrast-ratio is minimaal 4.5:1, en alle formulier-velden hebben labels en error-meldingen.

GIVEN een kandidaat gebruikt een screen-reader, WHEN hij solliciteert, THEN wordt elke stap van het formulier semantisch correct voorgelezen, inclusief verplicht/optioneel markeringen en bevestigings-feedback bij submissie.

## Standards & Sources

- **AVG (GDPR) art. 5(1)(e)** — opslagbeperking; sollicitatie-data niet langer bewaren dan noodzakelijk.
- **NVP Sollicitatiecode 2023** — Nederlandse Vereniging voor Personeelsmanagement, vakbonds-erkende code: max 4 weken bewaren zonder toestemming, max 1 jaar met toestemming.
- **AP Aanbeveling 2021** — Autoriteit Persoonsgegevens-richtlijn over recruitment-data en geautomatiseerde besluitvorming.
- **UWV werk.nl Vacatures API** — REST-koppelvlak voor het melden van openstaande vacatures (gratis voor werkgevers).
- **LinkedIn Talent Hub API** — voor company-page job postings; LinkedIn Easy Apply via Recruiter API of Apply Connect.
- **AI Act (EU 2024)** — annex III categorie "Werkgelegenheid": als HRMQ later AI-screening toevoegt valt dit onder high-risk; deze MVP vermijdt dat bewust.
- **WCAG 2.1 AA** — minimum voor publieke carriere-pagina.
- **Concurrenten**: Recruitee (NL marktleider MKB ATS, dure tier-plans), Homerun (Amsterdamse startup, prima UX, geen DigiD), Personio Recruiting (Duits, sterk in MKB), Workable (UK, multi-board posting). HRMQ-positionering: ingebakken in HR-suite met directe hand-off naar onboarding/payroll, geen aparte tool, geen aparte facturatie.
- **werk.nl Open Standaarden** — UWV-bestand vacature-aanmeldingen XSD-schema.

## Cross-app integration

- **employee-master** (dependency): bij `aangenomen` wordt een Employee aangemaakt met data uit Application en OfferLetter.
- **onboarding-wizard** (dependency): Application-id wordt context voor onboarding-traject; OfferLetter-data is pre-fill.
- **openconnector** (dependency): integraties met werk.nl API, LinkedIn API, Indeed (toekomstig), en e-mail-relay voor afwijzings-/uitnodigingsmails.
- **decidesk** (peer): OfferLetter digitaal ondertekenen via Decidesk envelope; webhook update Application-status bij signing.
- **Nextcloud Calendar** (peer): interviews als events; deelnemers zien afspraak in eigen agenda.
- **Nextcloud Talk** (optioneel): video-interview-links automatisch in Calendar-event.
- **Nextcloud Notifications**: notificaties aan hiring manager bij nieuwe sollicitatie, aan recruiter bij interview-feedback, aan HR-admin bij accepted offer.
- **payroll-engine-nl** (downstream via employee-master): bij hire wordt salaris-data uit OfferLetter doorgezet voor eerste loonberekening.
- **opencatalogi** (optioneel): publieke carriere-pagina kan deel uitmaken van de opencatalogi-site van de werkgever, met opencatalogi-templating en branding.
- **OpenRegister files**: CV's en motivatie-PDFs als file-attachments op Application, met scoped-access (alleen recruiter en hiring manager voor die vacature).

## Target users

**Primaire gebruikers**:
- *Recruiter / HR-administrateur* in MKB — verantwoordelijk voor het draaiende houden van het sollicitatie-proces. Werkt typisch met 5-15 actieve vacatures tegelijk en 50-200 sollicitaties per maand. Heeft beperkte tijd en zoekt automatisering voor publicatie en afwijzings-communicatie.
- *Hiring manager* (lijnmanager met openstaande positie) — wil snel zien wie er gesolliciteerd heeft, profielen beoordelen, en gesprekken plannen zonder telkens naar de recruiter te moeten.

**Secundaire gebruikers**:
- *Kandidaat / sollicitant* — wil snel en mobiel kunnen solliciteren, transparantie over de status, en bij afwijzing een respectvolle, snelle reactie.
- *DGA / werkgever* — wil weten dat het proces compliant loopt (GDPR, NVP-code), zonder elk detail te hoeven kennen.
- *HR-collega's voor referentiecheck* — krijgen via een Application-link toegang tot CV en motivatie voor een specifieke kandidaat, zonder volle pipeline-rechten.

**Use cases zonder dit spec**: vacature-tekst leeft in een Word-document, wordt drie keer geknipt naar verschillende portalen, sollicitaties komen binnen in een gedeeld postvak `jobs@bakkerij-bv.nl`, status leeft in een Excel-spreadsheet bij de recruiter, interviews worden in WhatsApp afgestemd, offers worden als Word-document gemaild, en CV's blijven jaren in het postvak staan tegen de AVG in. Bij elk personeelsverloop in HR raakt context verloren, en bij een AVG-audit ligt de werkgever uit. HRMQ ATS Basic verandert dit zonder enterprise-prijskaartje en met directe koppeling naar de rest van de HR-keten.

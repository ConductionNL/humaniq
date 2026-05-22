---
status: draft
---
# AOR Ambtenarenrecht — Public-Sector Employment Workflows

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › CAO's & regelingen

**Rationale:** Public-sector ruleset.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `aor-ambtenarenrecht` app codifies the public-sector employment workflows that survived the Wet normalisering rechtspositie ambtenaren (Wnra, 1 January 2020). Although civil servants now operate under private employment law, a thick layer of administrative procedures, integrity safeguards, and special appeal rights remains in force across Rijk, provincies, gemeenten, waterschappen, and zelfstandige bestuursorganen. Without dedicated tooling, these procedures live in ad-hoc Word templates, shared mailboxes, and tribal knowledge held by a handful of HR-juristen — a fragile setup that risks procedural mistakes, missed termijnen, and reputational damage when integriteitskwesties are mishandled. In practice, organisations report dat 30-40% van ontslagdossiers proceduregebreken bevat die in beroep tot vernietiging leiden, simpelweg omdat handmatig bewaakte termijnen worden gemist of verkeerde bevoegd-gezag-vermelding op het besluit staat.

This app delivers structured, auditable workflows for the six most consequential public-sector HR events: ontslagprocedure (with overheids-specifieke transitievergoeding), integriteitsmelding (Wet bescherming klokkenluiders), tuchtbesluit, disciplinaire maatregel, escalatie naar college van B&W of dagelijks bestuur, and voorbereiding van beroep bij de Centrale Raad van Beroep (CRvB). Each workflow is template-driven, generates the right administrative besluit (with bezwaar/beroep-clausule where applicable), stores the dossier with the correct retention class, and produces the legally required notifications and afschriften.

The app's design assumes Wnra-context (private employment law as default) but preserves the procedural rigour of the old Ambtenarenwet 2017 where bestuursrechtelijke elements remain, and is explicitly future-proof for organisations re-classified under Ambtenarenwet 2017 (politie, defensie, rechterlijke ambtenaren). Een tweede ontwerpprincipe is procedurele scheiding: integriteitsmeldingen en klokkenluider-dossiers leven in een aparte access-tier zodat reguliere HR-rollen ze niet zien — een patroon dat veel HRM-suites missen en dat tot dure incidenten leidt wanneer melder-vertrouwen geschonden wordt.

## Data Model

Schemas registered in the `hrmq` register:

- `EmploymentCase`: master record for a procedural case. `caseNumber`, `employeeId`, `caseType` (ontslag|integriteit|tucht|disciplinair|escalatie|beroep), `subType`, `status` (concept|in_behandeling|besluitvorming|afgerond|ingetrokken), `openedAt`, `closedAt`, `caseHandlerId`, `legalBasis[]`, `summary`, `confidentialityLevel` (standaard|vertrouwelijk|geheim).
- `CaseStep`: a single procedural step. `caseId`, `stepCode`, `name_nl`, `name_en`, `dueDate`, `completedAt`, `assigneeId`, `outputDocumentId`, `slaCategory`.
- `Besluit`: a formal administrative decision. `caseId`, `besluitType`, `bevoegdGezag`, `signedById`, `signedAt`, `bezwaartermijn` (days), `bezwaarDeadline`, `effectiveDate`, `documentId`, `notificationLog[]`.
- `Klokkenluidermelding`: protected disclosure. `caseId`, `melderType` (intern|extern|anoniem), `meldingChannel` (afdelingshoofd|vertrouwenspersoon|HvK|toezichthouder), `subject`, `summary`, `protectedUntil`, `retaliationCheckLog[]`, `huisVoorKlokkenluidersRef`.
- `Transitievergoeding`: calculation snapshot. `caseId`, `salaryComponents`, `serviceYears`, `ageAtTermination`, `formula`, `grossAmount`, `overheidsToeslag`, `netEstimate`, `paidAt`.
- `IntegrityRegister`: organisation-wide integrity events index, anonymised after a retention window for trend reporting to the bestuur.

Every record carries `auditTrail` (immutable list of state transitions), `dossierFolderId` (link to Nextcloud Files folder under access control), and `accessControlList` (role + named individuals). Sensitive cases (klokkenluider, integriteit) bypass the normal HR-leesrechten and require explicit ACL grant.

## Requirements

**REQ-001: Ontslagprocedure orchestration**
- GIVEN an HR-jurist opens a new ontslagdossier with ontslaggrond (a-i conform BW art. 7:669), WHEN they pick "h-grond (overige omstandigheden)" or "i-grond (cumulatie)", THEN the workflow injects the required extra steps (kantonrechter-route or UWV-route) and pre-fills the relevant verzoekschrift template.
- GIVEN a procedure follows the UWV-route (bedrijfseconomisch of langdurige arbeidsongeschiktheid), WHEN the case is opened, THEN UWV-formuliereenset (A en B) is generated with employee data pre-filled and a checklist for de werkgevers-onderbouwing is created.
- GIVEN the case reaches "besluit", WHEN the besluit is signed, THEN a `Besluit` record is created with a 6-week bezwaartermijn (where applicable for nog-niet-genormaliseerde ambtenaren) or the standard civil-law opzegtermijn, and the employee is notified via aangetekende digitale verzending (Digitale Akte).

**REQ-002: Transitievergoeding met overheidstoeslag**
- GIVEN an employment ends and a `Transitievergoeding` snapshot is requested, WHEN the calculation runs, THEN it uses the wettelijke formule (1/3 maandsalaris per dienstjaar, evenredig voor restjaren) per BW art. 7:673 with the actual maandsalaris incl. structurele toeslagen frozen at the moment of beëindiging.
- GIVEN a CAO bevat een hogere bovenwettelijke uitkering (WW-bovenwettelijk, BWNL, BWGS), WHEN the snapshot is created, THEN the bovenwettelijke component is calculated separately and the totaal-overzicht maakt het onderscheid expliciet visible.
- GIVEN the calculation falls within the kleine-werkgever-overgangsregeling or specifieke uitsluitingen (bv. AOW-gerechtigde leeftijd), WHEN it runs, THEN the exclusion is shown explicitly with citation and a manual override is gated behind a four-eyes-approval.

**REQ-003: Integriteitsmelding / klokkenluiderwerkflow**
- GIVEN an employee submits a melding via the protected channel, WHEN it is registered, THEN a `Klokkenluidermelding` is created with case-handler restricted to the vertrouwenspersoon, the melder's identity is pseudonymised in default views, and the `protectedUntil` field is set to `now + 7 years` per Wet bescherming klokkenluiders.
- GIVEN a melder requests external escalation, WHEN they select "Huis voor klokkenluiders" or a sectorale toezichthouder, THEN the export pakket follows the wettelijk vereiste structuur and a `huisVoorKlokkenluidersRef` is captured for traceability.
- GIVEN any HR action affects the melder within 24 months of the melding, WHEN that action is initiated, THEN a `retaliationCheckLog` entry is auto-created and the integriteitscoördinator is notified for assessment before the action can proceed.

**REQ-004: Tuchtbesluit (waar nog van toepassing)**
- GIVEN the organisation is een niet-genormaliseerde dienst (politie, defensie, rechterlijke macht), WHEN a tuchtdossier is opened, THEN the workflow follows the Besluit algemene rechtspositie politie (Barp), de Algemeen militair ambtenarenreglement (AMAR), of de relevante rechterlijke regelgeving, with the correct hoorzitting-template en termijnen.
- GIVEN a tuchtmaatregel wordt voorgesteld zwaarder dan een schriftelijke berisping, WHEN het concept-besluit wordt opgesteld, THEN een verplichte hoor-en-wederhoor stap met minimaal 14 dagen reactietermijn wordt geforceerd voordat het besluit ondertekend kan worden.
- GIVEN het besluit wordt definitief, WHEN het wordt afgegeven, THEN de tuchtmaatregel verschijnt in het personeelsdossier met een vooraf gedefinieerde verwijdertermijn (3, 5, of 10 jaar afhankelijk van zwaarte), na afloop waarvan de aantekening automatisch wordt geanonimiseerd.

**REQ-005: Disciplinaire maatregelen (genormaliseerd)**
- GIVEN een leidinggevende een formele waarschuwing of schorsing wil opleggen onder Wnra-context, WHEN het concept-besluit wordt opgesteld, THEN de juridische motivering wordt afgedwongen volgens BW 7:611 (goed werkgeverschap) plus eventuele CAO-bepalingen, en pre-fill de hoor-en-wederhoor uitnodiging.
- GIVEN een loonopschorting of loonstop wordt voorgesteld, WHEN het concept-besluit wordt opgesteld, THEN onderscheid wordt afgedwongen tussen loonopschorting (art. 7:629 lid 6 BW, geen meldplichten) en loonstop (art. 7:629 lid 3 BW, met meldplicht arbo-arts), en de juiste payroll-mutatie wordt voorgesteld.
- GIVEN een maatregel wordt ingetrokken of door rechter vernietigd, WHEN dit wordt geregistreerd, THEN alle volgende processtappen worden teruggedraaid, payroll wordt geïnstrueerd tot terugbetaling met rente, en het personeelsdossier wordt opgeschoond met behoud van audit trail.

**REQ-006: Escalatie naar college B&W of dagelijks bestuur**
- GIVEN een case bereikt een niveau dat bestuurlijke besluitvorming vereist (bv. ontslag van een afdelingshoofd, integriteitskwestie raakt aan bestuur, financiële impact > €50k), WHEN escalatie wordt aangevraagd, THEN een collegevoorstel wordt gegenereerd volgens lokale template-bibliotheek met deadlines voor B&W- of DB-vergaderagenda.
- GIVEN het college een besluit neemt, WHEN het besluit wordt teruggekoppeld, THEN het wordt geregistreerd met expliciete verwijzing naar het collegebesluitnummer en de gemeentelijke besluitenlijst.
- GIVEN het bestuur de zaak terugverwijst voor nadere informatie, WHEN de terugverwijzing wordt geregistreerd, THEN een nieuwe `CaseStep` wordt aangemaakt met de gevraagde aanvullingen en een revisietermijn voor de volgende vergadercyclus.

**REQ-007: Beroep bij Centrale Raad van Beroep**
- GIVEN een ex-ambtenaar (niet-genormaliseerd) bezwaar heeft afgewezen gekregen en beroep aankondigt bij de CRvB, WHEN de zaak wordt geregistreerd, THEN het dossier wordt gebundeld in een procesdossier-pakket conform CRvB-instructies, met chronologische indeling en geanonimiseerde derden-stukken.
- GIVEN de CRvB een zittingsdatum bepaalt, WHEN deze wordt geregistreerd, THEN alle interne deadlines (verweerschrift, nadere stukken, getuigenlijst) worden auto-berekend en als `CaseStep`s aangemaakt met assignees.
- GIVEN de uitspraak van de CRvB binnenkomt, WHEN deze wordt geregistreerd, THEN de werkgever-acties (heroverwegen besluit, financiële afwikkeling, mogelijke cassatie) worden als vervolgworkflow voorgesteld en bestuurlijke melding wordt opgesteld.

**REQ-008: Termijnen-bewaking**
- GIVEN elke `Besluit` heeft een bezwaartermijn, WHEN de termijn loopt, THEN een dagelijkse job rekent resterende dagen, toont een dashboard-widget voor de casehandler, en stuurt herinneringen op T-7 en T-2.
- GIVEN een termijn dreigt te verlopen zonder reactie, WHEN T-1 wordt bereikt, THEN de teamlead krijgt een escalatie-notificatie en de zaak krijgt status `risico`.
- GIVEN een termijn is verstreken, WHEN dit wordt gedetecteerd, THEN het feit wordt onuitwisbaar gelogd en de juridische gevolgen (besluit onherroepelijk, fatale termijn beroep) worden in de dossiernotitie vastgelegd.

**REQ-009: Vertrouwelijkheid en toegangsbeheer**
- GIVEN een case is gemarkeerd als `vertrouwelijk` of `geheim`, WHEN een gebruiker zonder expliciete ACL-grant toegang probeert, THEN toegang wordt geweigerd met logging van het pogen en notificatie aan de case-handler.
- GIVEN een vertrouwenspersoon een melding registreert, WHEN deze wordt opgeslagen, THEN de melder-identiteit is alleen zichtbaar voor maximaal twee genoemde personen, en alle andere views tonen een pseudoniem.
- GIVEN een case wordt geëxporteerd voor extern juridisch advies, WHEN de export wordt gegenereerd, THEN alle BSN, persoons- en derden-gegevens worden conform AVG-DPIA pseudonymised en de export krijgt een wachtwoord-beveiligde levering.

**REQ-010: Bewaartermijnen en archivering**
- GIVEN een case wordt afgesloten, WHEN de status `afgerond` wordt gezet, THEN de retentieklasse wordt vastgesteld volgens Selectielijst gemeenten/Rijk: ontslagdossiers 75 jaar na geboorte werknemer, integriteitsmeldingen 7 jaar, tuchtbesluiten 10 jaar na verwijderdatum.
- GIVEN een bewaartermijn loopt af, WHEN de archiefjob draait, THEN het dossier wordt automatisch geanonimiseerd of vernietigd conform retentieklasse, met een onuitwisbaar log van de vernietiging.
- GIVEN het Nationaal Archief of het gemeentelijk archief opvraagt voor overbrenging, WHEN de export wordt aangemaakt, THEN het Records-in-Context (RiC)-formaat wordt gebruikt zoals voorgeschreven door de Archiefwet.

## Standards & Sources

- **Wnra (Wet normalisering rechtspositie ambtenaren)** — kader voor private arbeidsrelatie.
- **Ambtenarenwet 2017** — kader voor genormaliseerde en niet-genormaliseerde sectoren.
- **BW boek 7, titel 10** (arbeidsovereenkomst) — ontslagrecht, transitievergoeding, loondoorbetaling.
- **Wet bescherming klokkenluiders** (juli 2023, EU-richtlijn 2019/1937) — beschermingsregime, Huis voor klokkenluiders.
- **Algemene wet bestuursrecht (Awb)** — bezwaar/beroep, hoorplicht, motiveringsbeginsel (waar nog van toepassing).
- **Beroepswet** — competentie Centrale Raad van Beroep.
- **Barp, AMAR, Wrra** — sectorale rechtspositiebesluiten.
- **Archiefwet 1995 + Selectielijsten Rijk/gemeenten** — retentie.
- **AVG, UAVG, DPIA-handreikingen Autoriteit Persoonsgegevens** — gegevensbescherming, klokkenluider-privacy.
- **CAO Rijk, CAO Gemeenten, CAO SGO, CAO Provincies, CAO Waterschappen** — sectorale arbeidsvoorwaarden.
- **Wet open overheid (Woo)** — publicatieplicht voor categorieën van besluiten, met passende anonimisering.
- **Algemene verordening gegevensbescherming, art. 9 + 10** — bijzondere persoonsgegevens en strafrechtelijke gegevens (relevant bij integriteit- en tuchtzaken).
- **Handboek Integriteit Overheid (CAOP)** — referentiekader voor integriteitsbeleid.
- **Modelregeling Klokkenluiders Adviespunt** — verplicht model sinds 2023.

## Cross-app Integration

- **employee-master**: leest persoons- en dienstverbandsgegevens, schrijft eind-dienstverband terug bij ontslag/afsluiting.
- **contract-management**: koppelt aan vigerend arbeidscontract, registreert wijzigingen (loonopschorting, schorsing) als contractmutaties.
- **payroll-engine-nl**: ontvangt salarismutaties (loonopschorting, loonstop, transitievergoeding, terugbetaling met rente) als deterministische instructies; bevestigt verwerking terug.
- **docudesk**: alle dossierstukken, besluiten, verweerschriften en CRvB-bundels worden opgeslagen met retentieklasse en ACL gekoppeld aan `EmploymentCase.id`.
- **openconnector**: integraties met UWV-portal, Huis voor klokkenluiders, gemeentelijke besluitvorming-systemen (iBabs, Notubiz), Berichtenbox/MijnOverheid voor formele verzendingen.
- **mydash**: bestuurlijke dashboards met geanonimiseerde KPI's (aantal cases per type, doorlooptijden, geslaagde bezwaren).
- **opencatalogi**: publicatie van besluiten-overzichten waar wettelijk vereist (bv. WOO-relevante categorieën, met juiste anonimisering).
- **irma-digid-auth**: integriteitsmeldingen door medewerkers via Yivi met pseudonieme attributen; bestuurlijke besluiten ondertekend via eHerkenning niveau 4; alle dossier-toegang gestaffd per assurance-level.
- **mydash**: KPI-dashboard met anonieme indicatoren (doorlooptijd ontslagprocedures, percentage bezwaren toegewezen, aantal integriteitsmeldingen per kwartaal, retaliation-checks).
- **payroll-engine-nl**: ontvangt mutaties voor schorsing met behoud van loon, loonopschorting, loonstop, transitievergoeding-uitbetaling, en eventuele terugbetaling-met-rente bij vernietiging van een besluit.

## Target Users

1. **HR-jurist / arbeidsjurist** (1–10 per organisatie) — primaire casehandler, kent BW, Awb, Wnra; werkt dagelijks in dossiers en sjablonen, heeft behoefte aan voorgestelde brieven, termijnberekeningen en jurisprudentie-koppelingen om sneller besluiten van hoge kwaliteit te produceren.
2. **Vertrouwenspersoon integriteit** — exclusieve toegang tot klokkenluiderdossiers; jaarlijkse anonieme rapportage aan bestuur.
3. **Integriteitscoördinator** — overzicht over alle integriteitscases, retaliation-checks, trendrapportage.
4. **Lijnmanager** — ontvanger van escalaties, ondertekenaar van lichte disciplinaire maatregelen, getuige in hoorzittingen.
5. **HR-directeur / Hoofd P&O** — bestuurlijke afstemming, escalatie-route naar college/DB.
6. **Bestuurssecretaris / collegegriffier** — ontvangt collegevoorstellen, bewaakt agendacyclus.
7. **Auditdienst Rijk / gemeentelijke accountantsdienst** — read-only audit-toegang voor rechtmatigheidsonderzoek.
8. **Externe advocaat / juridisch adviseur** — tijdelijke ACL-grant voor specifieke dossiers, met pseudonymisatie waar nodig.

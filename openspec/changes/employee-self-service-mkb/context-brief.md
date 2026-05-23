---
status: draft
---
# Employee Self-Service voor MKB

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Mijn HR (top-level wrapper)

**Rationale:** Self-service portal.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Het `employee-self-service-mkb` spec definieert een afzonderlijke applicatie waarmee werknemers in MKB-bedrijven hun eigen HR-gegevens kunnen inzien, hun loonstrook en jaaropgaaf downloaden, verlof aanvragen, contracten raadplegen, declaraties indienen, en opleidings-aanvragen doen — zonder dat ze daarvoor afhankelijk zijn van de HR-administrateur of een ingelogde Nextcloud-werkomgeving.

In MKB-context (10-250 werknemers) is het zelden zinvol om elke werknemer een volwaardig Nextcloud-account te geven met toegang tot mappen, projecten en interne tools. Veel werknemers — denk aan productiemedewerkers, monteurs, horeca-personeel, zorgwerkers met wisselende contracten — hebben geen eigen werkmail en werken vooral mobiel. Tegelijk hebben juist deze werknemers behoefte aan eenvoudige zelfservice: ik wil mijn loonstrook van vorige maand zien, ik wil mijn vakantiedagen-saldo zien, ik wil vrij aanvragen voor 15 augustus, ik wil mijn IBAN wijzigen na een echtscheiding.

Daarom kiest HRMQ voor een **aparte UI-app met een aparte authenticatie-context**. De werknemer logt in via DigiD (de overheidsstandaard voor burger-authenticatie), via Nextcloud SSO (als de werkgever wel werknemers met NC-accounts heeft), of via een magic-link op het privé-emailadres dat de werkgever bij indiensttreding heeft geregistreerd. De UI is mobile-first en voldoet aan WCAG 2.1 AA, want het primaire gebruik is via een telefoon onderweg of thuis op de bank.

Het self-service portaal is **read-mostly**: de werknemer kan veel inzien, maar mutaties zijn beperkt en deels approval-gated. NAW-mutaties zoals een nieuw huisadres of telefoonnummer mag de werknemer zelfstandig doorvoeren; mutaties van fiscaal of financieel kritieke velden (IBAN, BSN, burgerlijke staat, geboortedatum) gaan via een approval-flow waarin de manager of HR-admin de mutatie bevestigt. Dit voorkomt salaris-fraude (wijziging IBAN naar een aanvaller-rekening) en houdt de loonadministratie auditabel.

Het portaal vervangt geen volledig HR-systeem; het is de werknemer-facing laag bovenop de HRMQ-kern. Alle data komt uit dezelfde OpenRegister-store als de HR-admin-app, maar met andere autorisatie en een drastisch versimpelde UI.

## Data Model

Het portaal introduceert geen nieuwe entiteiten in de HR-domein-modellen; het is een view + scoped-write-app op bestaande Employee-, Payslip-, LeaveRequest-, Contract-, Expense- en TrainingRequest-entiteiten uit andere HRMQ-spec.

Wel introduceert het spec drie aparte ondersteunende entiteiten:

`SelfServiceSession` houdt de inlog-sessie en authenticatie-bron bij: `employee_id`, `auth_method` (digid, nc_sso, magic_link), `auth_subject` (BSN-hash voor DigiD, Nextcloud uid voor SSO, e-mail voor magic-link), `started_at`, `last_activity_at`, `device_fingerprint`. Sessies verlopen na 30 minuten inactiviteit of na 8 uur absoluut.

`MagicLinkToken` is voor de magic-link-flow: `token` (256-bit random, eenmalig bruikbaar), `employee_id`, `email_sent_to`, `created_at`, `expires_at` (15 minuten na aanmaken), `consumed_at`. Voorkomt phishing door enkel via bekende werkgever-e-mail te verzenden, met IP- en User-Agent-logging.

`MutationApproval` legt approval-gated mutaties vast: `employee_id`, `field_name` (iban, bsn, burgerlijke_staat, etc.), `old_value`, `new_value`, `requested_at`, `requested_by` (de werknemer), `approver_id` (manager of hr_admin), `decided_at`, `decision` (approved, rejected), `decision_note`. Tot een mutatie is goedgekeurd blijft de oude waarde in Employee staan; bij approval wordt de Employee bijgewerkt en wordt de MutationApproval naar `approved` gezet (append-only history).

Voor declaraties en opleidings-aanvragen leunt het portaal op `expense-reimbursement` en `training-request` (peer/dependency-spec); de portaal-laag levert alleen de werknemer-facing formulieren en validaties.

## Requirements

### REQ-001: Authenticatie via DigiD
Een werknemer kan inloggen via DigiD wanneer de werkgever DigiD heeft ingeschakeld, mits het BSN in zijn Employee-record matcht met het BSN dat DigiD teruggeeft.

GIVEN werkgever heeft DigiD-koppeling ingeschakeld en werknemer "Anna Bakker" heeft BSN 123456789 in haar Employee-record, WHEN Anna op de portaal-loginpagina klikt op "Inloggen met DigiD" en succesvol bij DigiD authenticeert met BSN 123456789, THEN wordt een SelfServiceSession aangemaakt met auth_method=digid en Anna landt op haar dashboard.

GIVEN een DigiD-authenticatie levert een BSN op dat niet in enige Employee-record voorkomt, WHEN de DigiD-callback wordt verwerkt, THEN toont de pagina "Geen actief dienstverband gevonden voor dit BSN; neem contact op met je werkgever" en wordt geen sessie aangemaakt.

### REQ-002: Authenticatie via Nextcloud SSO
Als de werkgever Nextcloud-accounts heeft uitgedeeld, kan de werknemer met die credentials direct doorklikken vanuit Nextcloud zonder opnieuw in te loggen.

GIVEN werknemer is ingelogd in Nextcloud met uid "anna.bakker" en deze uid staat als `nc_user_id` in haar Employee-record, WHEN ze klikt op de Self-Service tegel op haar Nextcloud-dashboard, THEN wordt ze automatisch ingelogd in het self-service-portaal via OAuth-token-passthrough, zonder extra wachtwoord.

### REQ-003: Authenticatie via magic-link
Voor werknemers zonder DigiD-bereidheid of NC-account is magic-link de fallback.

GIVEN werknemer kent zijn werknemers-emailadres "anna@bakkerij-bv.nl", WHEN hij dit e-mailadres invult op de loginpagina en op "stuur link" klikt, THEN verstuurt het systeem binnen 30 seconden een e-mail met een MagicLinkToken-URL die 15 minuten geldig is.

GIVEN een MagicLinkToken is reeds geconsumeerd, WHEN dezelfde link nogmaals wordt geopend, THEN toont de pagina "Deze link is al gebruikt; vraag een nieuwe link aan" en wordt geen sessie aangemaakt.

GIVEN het opgegeven e-mailadres komt niet voor in enige Employee-record, WHEN de werknemer op "stuur link" klikt, THEN toont de pagina exact dezelfde succesmelding ("als dit e-mailadres bekend is, ontvang je binnen enkele minuten een link") om e-mail-enumeratie te voorkomen, maar verstuurt geen e-mail.

### REQ-004: Loonstrook en jaaropgaaf inzien en downloaden
Werknemer ziet een lijst van zijn loonstroken per maand en zijn jaaropgaaves per jaar, en kan elke PDF downloaden.

GIVEN ik ben ingelogd, WHEN ik op "Loonstroken" tik, THEN toont het scherm een chronologische lijst (nieuwste boven) van mijn loonstroken van de laatste 24 maanden met datum en netto-bedrag.

GIVEN ik tik op een loonstrook van mei 2026, WHEN de detail-pagina laadt, THEN toont deze de PDF inline (mobile-friendly viewer) met download-knop en een "stuur naar mijn e-mail"-knop.

GIVEN ik open de tab "Jaaropgaaves", WHEN deze laadt, THEN zie ik per kalenderjaar één regel met jaar, totaal bruto, en download-knop voor de jaaropgaaf-PDF.

### REQ-005: Verlof aanvragen
Werknemer ziet zijn verlofsaldo per categorie en kan een verlofaanvraag indienen die naar zijn manager gaat ter goedkeuring.

GIVEN ik ben ingelogd, WHEN ik op "Verlof" tik, THEN toont het scherm mijn saldi: vakantiedagen restant (uren), bovenwettelijke dagen, ouderschapsverlof restant, en zorgverlof restant.

GIVEN ik tik op "nieuwe aanvraag", WHEN ik kies type "vakantie", datum-van 2026-08-10, datum-tot 2026-08-21, THEN berekent het formulier 8 werkdagen op basis van mijn arbeidspatroon en toont een waarschuwing als dit mijn saldo overschrijdt.

GIVEN ik dien de aanvraag in, WHEN deze is opgeslagen, THEN ontvangt mijn manager een melding (Nextcloud Notification + e-mail) en zie ik in het portaal de aanvraag met status "wacht op goedkeuring".

GIVEN mijn manager keurt de aanvraag goed in de HRMQ-admin-app, WHEN ik mijn portaal opnieuw open, THEN zie ik de status `goedgekeurd` en mijn verlofsaldo is verminderd met 8 dagen (64 uur).

### REQ-006: NAW-mutaties zelf doorvoeren
Wijzigingen aan postadres, telefoon, prive-e-mail mag de werknemer direct doorvoeren zonder approval.

GIVEN ik ben ingelogd, WHEN ik open "Mijn gegevens" en wijzig mijn postcode en huisnummer, THEN wordt de mutatie direct opgeslagen in Employee, een MutationApproval met decision=auto_approved wordt vastgelegd voor audit, en mijn manager en HR-admin ontvangen een informatieve notificatie.

### REQ-007: Approval-gated mutaties (IBAN, BSN, burgerlijke staat)
Wijzigingen aan financieel of identiteits-kritieke velden vereisen managers- of HR-admin-goedkeuring.

GIVEN ik wil mijn IBAN wijzigen naar NL12RABO0123456789, WHEN ik het formulier indien, THEN wordt een MutationApproval aangemaakt met status `pending`, mijn huidige IBAN blijft actief in Employee, en mijn manager én HR-admin ontvangen een hoog-prioriteits-notificatie met expliciete waarschuwing "controleer telefonisch of deze wijziging echt door medewerker is aangevraagd, in verband met fraude-risico".

GIVEN HR-admin keurt de IBAN-mutatie goed in de admin-app, WHEN de approval is bevestigd, THEN wordt Employee.iban bijgewerkt, MutationApproval verschuift naar `approved`, ik ontvang een notificatie "je IBAN-wijziging is doorgevoerd; vanaf de eerstvolgende loonbetaling staat het salaris op de nieuwe rekening".

GIVEN HR-admin keurt de mutatie af met reden "telefonisch geverifieerd: niet door medewerker aangevraagd", WHEN ik mijn portaal open, THEN zie ik een waarschuwingsbanner "een IBAN-wijziging is geweigerd; neem contact op met HR" met de reden, en mijn Employee.iban is ongewijzigd.

### REQ-008: Declaratie indienen
Werknemer kan een onkostendeclaratie indienen met bonnetje-foto en categorie.

GIVEN ik ben ingelogd op mobiel, WHEN ik op "Declaratie" tik en kies categorie "reiskosten zakelijk", THEN toont het formulier datum, bedrag, omschrijving, en een knop "foto van bon".

GIVEN ik tik op "foto van bon", WHEN ik een foto maak van mijn bon, THEN wordt deze geüpload als bijlage bij de declaratie, een eerste OCR-controle haalt bedrag en datum eruit en pre-fillt het formulier ter bevestiging.

GIVEN ik dien de declaratie in, WHEN deze is opgeslagen, THEN gaat ze naar expense-reimbursement met status `submitted`, mijn manager ontvangt een approval-notificatie, en in mijn portaal zie ik de declaratie met status en verwachte uitbetaling.

### REQ-009: Contracten en addenda inzien
Werknemer ziet zijn arbeidsovereenkomst en alle addenda als PDF.

GIVEN ik ben ingelogd, WHEN ik op "Contracten" tik, THEN toont het scherm mijn actuele contract, alle addenda (bv. salarisverhoging, urenwijziging, functiewijziging) en eventuele eerdere contracten bij dezelfde werkgever, elk met ingangsdatum en download-link.

GIVEN ik open mijn actuele contract, WHEN de detail-pagina laadt, THEN zie ik de samenvatting (functie, schaal, uren, einddatum), de PDF inline, en de status van eventuele te tekenen documenten via Decidesk-integratie.

### REQ-010: Opleidings-aanvraag indienen
Werknemer kan een opleiding voorstellen en indienen voor goedkeuring door manager.

GIVEN ik ben ingelogd, WHEN ik tik op "Opleidingen" en daarna op "nieuwe aanvraag", THEN toont het formulier: titel opleiding, aanbieder, kosten, periode, link naar inschrijving, en motivatie.

GIVEN ik dien de aanvraag in, WHEN deze is opgeslagen, THEN ontvangt mijn manager een approval-notificatie, gaat de aanvraag naar het training-budget-systeem voor budgetcheck, en zie ik in mijn portaal de status met verwachte beslis-datum.

GIVEN mijn aanvraag is goedgekeurd en het budget toegekend, WHEN ik mijn portaal open, THEN zie ik status `goedgekeurd` met instructies voor inschrijving en facturering richting werkgever.

### REQ-011: WCAG AA + mobile-first
Het portaal voldoet aan WCAG 2.1 niveau AA en is geoptimaliseerd voor schermen vanaf 320px.

GIVEN ik open het portaal op een iPhone SE (375px breed), WHEN ik door alle hoofdfuncties navigeer, THEN past alle content binnen viewport zonder horizontaal scrollen, en alle interactie-elementen hebben minimaal 44x44 CSS-pixel touch-target.

GIVEN ik gebruik VoiceOver of TalkBack screen-reader, WHEN ik door het loonstrook-overzicht navigeer, THEN voorleest het scherm "Loonstrook mei 2026, netto 2.347 euro 12 cent, download knop", semantisch correct met juiste roles en labels.

GIVEN ik test met axe-core automated WCAG-scan, WHEN de tests draaien op alle hoofdpagina's, THEN zijn er nul issues op niveau AA, en de contrast-ratio voor alle tekst is minimaal 4.5:1.

### REQ-012: Persoonlijke ontwikkeling
Werknemer ziet zijn POP-doelen, voortgangsgesprekken-historie en kan zelfreflectie indienen.

GIVEN ik ben ingelogd, WHEN ik tik op "Mijn ontwikkeling", THEN zie ik mijn huidige POP-doelen voor dit kalenderjaar, de status van elk doel (op koers / aandacht / behaald), en de planning van mijn volgende voortgangsgesprek.

GIVEN ik tik op een doel, WHEN de detail-pagina laadt, THEN zie ik beschrijving, kpi, deadline, mijn laatste update, en een formulier "voeg update toe" met vrije tekst.

## Standards & Sources

- **DigiD-koppelvlakspecificatie 1.13** — Logius standaard voor SAML-authenticatie; sectoraal raamwerk authenticatie burger-overheid.
- **WCAG 2.1 niveau AA** — verplicht voor publieke organisaties (Tijdelijk besluit digitale toegankelijkheid overheid) en best practice voor MKB werkgever-werknemer-portalen.
- **eHerkenning niveau 3** — alternatief voor DigiD wanneer werkgever zelf een eHerkenning-context biedt voor werknemers met BSN.
- **AVG art. 9 lid 2 sub b** — verwerking BSN en gezondheidsgegevens (zorgverlof) in werkgever-werknemer relatie.
- **NEN-EN-301-549** — Europese toegankelijkheidsnorm voor ICT-producten; aanvulling op WCAG.
- **OWASP Authentication Cheat Sheet** — magic-link best practices (eenmalig gebruik, korte TTL, IP-binding, GET vs POST).
- **NIST SP 800-63B level AAL2** — sessie-management richtlijn (max 30 min idle / 12 uur absoluut); HRMQ kiest 30 min / 8 uur conservatief.
- **Concurrenten**: Loket.nl ESS (basis loonstrook + verlof, geen mobile), AFAS InSite (krachtig maar zwaar voor MKB), Personio (Duitse marktleider, goede UX, geen DigiD), Visma Raet ESS (legacy desktop-eerst). HRMQ-positionering: DigiD-first, mobile-first, MKB-passend.

## Cross-app integration

- **employee-master** (dependency): bron van Employee-records inclusief BSN-hash voor DigiD-matching, e-mail voor magic-link, nc_user_id voor SSO.
- **payslip-generation** (dependency): bron van Payslip-PDFs; portaal toont metadata uit Payslip en streamt PDF.
- **leave-management-mvp** (dependency): verlofsaldi, aanvragen-flow, accordering door manager.
- **expense-reimbursement** (dependency, new): declaratie-flow met OCR-ondersteuning en manager-approval.
- **training-request** (dependency, new): opleidings-budget en aanvraag-flow.
- **decidesk** (peer): contract- en addendum-PDFs ondertekenen via Decidesk-integratie; status van te tekenen documenten zichtbaar in portaal.
- **openconnector**: DigiD-koppelvlak via Logius, e-mail-verzending via SMTP-relay of n8n, OCR via lokaal LLM (qwen3.5) of cloud OCR-API.
- **Nextcloud Notifications**: approval-aanvragen aan manager/HR-admin, status-updates aan werknemer.
- **Nextcloud Talk** (optioneel): "vraag aan HR" knop opent een Talk-conversatie met de HR-administrateur als er chat is ingeschakeld in de werkgevers-Nextcloud.

## Target users

**Primaire gebruiker**: werknemer van een MKB-bedrijf. Demografisch zeer divers: van 17-jarige Albert Heijn-medewerker (mobiel-first, korte sessies, snelle taken zoals "vakantie aanvragen voor zomer"), tot 58-jarige loodgieter (paar keer per jaar inloggen voor loonstrook of jaaropgaaf), tot 32-jarige consultant (frequent gebruiker voor declaraties en verlof).

**Secundaire gebruikers**:
- *Manager* — ontvangt approval-aanvragen via notificaties; werkt in de HRMQ-admin-app maar krijgt context vanuit dit portaal.
- *HR-administrateur* — moet vertrouwen dat werknemers zelf hun NAW-data bijwerken, en weet dat IBAN-mutaties altijd menselijk geverifieerd worden vóór goedkeuring.
- *Werkgever (DGA)* — wil dat zijn personeel zonder gedoe bij de eigen data kan, zonder dat hij betrokken hoeft te raken bij elke vraag.

**Use cases zonder dit spec**: werknemer belt of mailt HR voor elke loonstrook-vraag, stuurt verlofaanvraag via WhatsApp, vraagt jaaropgaaf via een spreadsheet-aanvraag, levert papieren bon-declaraties in via de receptie. De HR-administrateur besteedt 30-40% van zijn tijd aan operationele werknemer-verzoeken. Dit portaal automatiseert die volume-laag zodat HR zich kan richten op echte HR-zaken (werving, ontwikkeling, conflicten).

**Belangrijkste niet-gebruikers**: dit portaal is bewust NIET voor de HR-administrateur of manager als primaire werkomgeving — die werken in de hoofd-HRMQ-app. Verwarring tussen "admin-app" en "self-service-app" moet vermeden worden door duidelijke naamgeving, aparte URL's, en aparte brand-context.

---
kind: code
depends_on:
  - payroll-core-basic
chain: []
---

# Proposal: Cross-app: Payroll Cost → shillinq GL Post

## Summary

Maandelijkse salariskosten worden na elke salarisrun automatisch als journaalpost naar het shillinq grootboek gestuurd. De vier loonkostentypen worden via RGS 2026 naar de juiste rekeningen geboekt: bruto-loon → 4xxx, sociale lasten → 17xx, vakantiegeld-reservering → 18xx, netto-loonschuld → 14xx. Posting verloopt via `shillinq.JournalEntry.post` en is idempotent per `(employee_id, period)`.

## Stakeholders

| Rol | Verantwoordelijkheid |
|---|---|
| Salarisadministrateur | Triggert de salarisrun; verwacht automatische doorboekingen naar het grootboek |
| Boekhouder / Controller | Ontvangt grootboekposten in shillinq; controleert volledigheid en RGS-conformiteit |
| IT-beheerder | Configureert de shillinq-koppeling (API-credentials, RGS rekeningnummers) |

## Customer Journeys

### Journey A: Maandelijkse salarisrun met automatische GL-post

**Trigger:** Salarisadministrateur sluit de salarisrun af voor periode april 2026.

**Stappen:**
1. Salarisrun wordt afgesloten in hrmq (payroll-core-basic).
2. hrmq roept de PayrollGLPostService aan voor elke werknemer in de run.
3. Per werknemer wordt gecontroleerd of al een post bestaat voor (employee_id, "2026-04") → idempotentie.
4. Voor nieuwe posten worden vier journaalregels opgebouwd per werknemer (bruto-loon, sociale lasten, vakantiegeld, netto-loonschuld).
5. hrmq roept `shillinq.JournalEntry.post` aan via de geconfigureerde API-sleutel.
6. shillinq retourneert een `journalEntryId`; hrmq slaat de postingstatus op in PayrollGLPost.
7. Salarisadministrateur ziet in het hrmq-overzicht dat alle werknemers succesvol zijn geboekt.

**Pijnpunten zonder deze feature:**
- Handmatige export naar boekhoudpakket na elke salarisrun (tijdrovend, foutgevoelig).
- Geen idempotentie: herstart van een run leidt tot dubbele boekingen.
- Geen RGS-conformiteit: rekeningen moeten handmatig worden ingevuld.

### Journey B: Posting-fout herstellen

**Trigger:** shillinq API is tijdelijk niet bereikbaar tijdens de salarisrun.

**Stappen:**
1. PayrollGLPostService registreert de fout in de PayrollGLPost-record (status: `failed`, errorMessage).
2. Salarisadministrateur ziet in het postingoverzicht welke werknemers niet zijn geboekt.
3. IT-beheerder lost het verbindingsprobleem op.
4. Salarisadministrateur triggert een herpost voor de gefaalde records.
5. Idempotentiecheck zorgt dat al-succesvolle records niet opnieuw worden verstuurd.

## User Stories

### US-001: Automatische GL-post na salarisrun
Als salarisadministrateur wil ik dat na het afsluiten van een salarisrun de loonkosten per werknemer automatisch als journaalpost naar shillinq worden verzonden, zodat ik niet handmatig hoef te boeken.

**Acceptatiecriteria:**
- GEGEVEN een afgesloten salarisrun voor periode 2026-04
- WANNEER de run wordt afgesloten
- DAN wordt voor elke werknemer een PayrollGLPost aangemaakt en via `shillinq.JournalEntry.post` verzonden
- EN de status wordt opgeslagen als `posted` inclusief het teruggegeven `journalEntryId`

### US-002: Idempotente posting per (employee_id, period)
Als salarisadministrateur wil ik dat een herstart van de posting voor dezelfde periode geen dubbele journaalposten aanmaakt in shillinq, zodat ik een run onbezorgd opnieuw kan starten.

**Acceptatiecriteria:**
- GEGEVEN een bestaande PayrollGLPost met status `posted` voor (werknemer X, periode 2026-04)
- WANNEER de posting opnieuw wordt getriggerd voor dezelfde combinatie
- DAN wordt `shillinq.JournalEntry.post` NIET opnieuw aangeroepen
- EN de bestaande record blijft ongewijzigd

### US-003: RGS 2026 rekeningnummer-mapping
Als boekhouder wil ik dat journaalposten de correcte RGS 2026 grootboekrekeningen gebruiken, zodat mijn jaarrekening automatisch juist is ingedeeld.

**Acceptatiecriteria:**
- GEGEVEN de RGS 2026-mapping in de shillinq-configuratie
- WANNEER een journaalpost wordt aangemaakt
- DAN bevat de post debetregels op rekeningen 4xxx (bruto-loon), 17xx (sociale lasten), 18xx (vakantiegeld-reservering)
- EN een creditregel op rekening 14xx (netto-loonschuld)

### US-004: Posting-status inzien per periode
Als salarisadministrateur wil ik per salarisperiode een overzicht zien van de posting-status per werknemer, zodat ik snel gefaalde posten kan opsporen.

**Acceptatiecriteria:**
- GEGEVEN een overzichtspagina met PayrollGLPost-records voor periode 2026-04
- WANNEER de salarisadministrateur de pagina opent
- DAN ziet hij per werknemer de status (`pending`, `posted`, `failed`, `skipped`)
- EN bij `failed` is de foutmelding zichtbaar
- EN is er een actie beschikbaar om gefaalde records opnieuw te versturen

### US-005: Shillinq-koppeling configureren
Als IT-beheerder wil ik in de admin-instellingen de shillinq API-sleutel en RGS-rekening mapping kunnen instellen, zodat de koppeling per organisatie geconfigureerd kan worden.

**Acceptatiecriteria:**
- GEGEVEN de admin-instellingenpagina van hrmq
- WANNEER de IT-beheerder de shillinq-sectie opent
- DAN kan hij de API-sleutel invoeren (opgeslagen via `IAppConfig` met sensitive flag)
- EN kan hij per loonkostentype een RGS-rekeningnummer invoeren
- EN ziet hij feedback bij opslaan (succes of foutmelding)

## Scope

### In scope
- `PayrollGLPostService` — bouwt journaalregels op en roept shillinq aan
- `PayrollGLPostController` — dunne controller voor het triggeren en herposten
- `PayrollGLPost`-schema in OpenRegister — idempotentieregistratie per (employee_id, period)
- Admin-instellingen voor shillinq API-configuratie (API-sleutel + RGS-mapping)
- Posting-overzichtspagina in hrmq (status per werknemer per periode)
- Retry-actie voor gefaalde records
- PHPUnit-tests voor de service-laag
- Integratietests voor de controller-endpoints

### Out of scope
- Bruto-netto berekening (valt onder `payroll-core-basic`)
- shillinq-gebruikersinterface (eigendom shillinq-team)
- Terugboekingen / creditnota's bij correcties
- CAO-specifieke loonbestanddelen
- Multi-currency
- UPA/loonaangifte

## Competitor evidence

Marktstandaard voor GL-koppeling gevonden bij: AFAS Profit (native GL-integratie), exact-online-hrm (auto-post salariskosten + sociale lasten + vakantiegeld), frappe-hr (native ERPNext GL posting), loket (native exports Exact/Twinfield/Snelstart/AccountView), visma-youserve (native SAP/Oracle/Exact exports). Alle 11 onderzochte concurrenten bieden een directe post per salarisrun; idempotentie en RGS-conformiteit zijn de Nederlandse differentiators.

## Kind-motivatie

`kind: code` — de kern van deze wijziging is een externe API-integratie (shillinq JournalEntry.post). Per ADR-031 vallen externe API-adapters buiten de declaratieve schema-engine; de service-laag schrijven in PHP is de correcte vorm. De PayrollGLPost-schemadefinitie in het registerbestand is secundair en klein genoeg voor één envelop (geen aparte config/code chain nodig per ADR-032: de code-wijzigingen zijn het centrum van massa).

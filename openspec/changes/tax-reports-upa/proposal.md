---
kind: code
depends_on: [payroll-core-basic]
chain: []
---

# UPA Loonaangifte to Belastingdienst

## Why

Nederlandse werkgevers zijn wettelijk verplicht elke aangifte-tijdvak een UPA loonaangifte in te dienen bij de Belastingdienst Loonheffingen (LH) portaal. Ontbreekt deze aangifte, dan riskeren werkgevers boetes en naheffingen. hrmq heeft momenteel geen ondersteuning voor UPA loonaangifte, terwijl alle 14 geïdentificeerde NL HR-concurrenten dit bieden (ADP, AFAS, Loket, Employes, Visma Raet, Centric, Easy Loon, e.a.). Gemeenten, ZBO's en semi-publieke organisaties die hrmq inzetten kunnen hun wettelijke verplichtingen daardoor niet via het platform nakomen.

Risico: directe SBR-kanaalkoppeling vereist een Belastingdienst aanleveraarsnummer en XSD-versie 2026 mTLS-certificaat. Het MVP routeert indiening via Loonnext/Nmbrs relay om vroege adoptie te ontsluiten terwijl de directe aanleveraarsintegratie wordt uitgewerkt.

## What Changes

- UPA XML loonaangifte genereren en indienen via Loonnext/Nmbrs relay (MVP)
- Aangifte-status bewaken en Belastingdienst-response verwerken
- Correctieberichten aanmaken en indienen voor eerder ingediende tijdvakken
- Werkkostenregeling (WKR) administratie: vrije ruimte bewaking, categorisering vergoedingen, eindheffing berekening
- Jaarwerk loonbelasting: jaaropgaven per werknemer genereren, loonbelastingkaarten, controle jaar-totalen versus maandaangiften

## Capabilities

### New Capabilities

- `upa-aangifte`: UPA loonaangifte XML genereren en indienen voor een loonheffingen-tijdvak via relay-service (Loonnext of Nmbrs); indienstatus bewaken via achtergrond-job; Belastingdienst-response verwerken en opslaan
- `correctieberichten`: Correctiebericht aanmaken voor een eerder ingediend tijdvak; verschil berekenen ten opzichte van originele aangifte; indienen via relay; status bewaken
- `wkr-administratie`: Werkkostenregeling vrije ruimte bewaken per kalenderjaar; vergoedingen categoriseren als gerichte vrijstelling, vrije ruimte, of belast loon; eindheffing berekenen bij overschrijding vrije ruimte (80% eindheffing)
- `jaarwerk-loonbelasting`: Jaaropgaven genereren per werknemer op basis van gereconcilieerde maandaangiften; loonbelastingkaarten samenstellen; controle jaar-totalen versus maandaangiften; digitaal versturen aan medewerkers

## Impact

- `lib/Service/UpaAangifteService.php`: UPA XML samenstellen conform XSD 2026; relay-API aanroepen; response verwerken
- `lib/Service/WkrAdministratieService.php`: vrije ruimte berekening per jaar; eindheffing bij overschrijding; vergoeding-categorisering
- `lib/Service/JaarwerkService.php`: jaaropgaven genereren; jaar-totalen reconciliëren met maandaangiften
- `lib/Job/UpaIndienenJob.php`: QueuedJob voor asynchrone indiening met exponentiële retry (3 pogingen)
- `lib/Controller/LoonaangifteController.php`: REST endpoints voor aangifte CRUD, genereren en indienen
- `lib/Controller/WkrController.php`: REST endpoints voor WKR administratie
- `lib/Controller/JaarwerkController.php`: REST endpoints voor jaaropgaven
- `lib/Settings/hrmq_register.json`: OpenRegister schemas voor LoonaangifteRun, WkrAdministratie, Jaaropgave; seed data
- `src/views/LoonaangifteView.vue`: overzicht en status-bewaking van aangiften per tijdvak
- `src/views/WkrView.vue`: WKR vrije ruimte dashboard per jaar
- `src/views/JaarwerkView.vue`: jaaropgaven overzicht per jaar
- `src/modals/IndienAangifteModal.vue`: indiening bevestigingsdialog
- `src/modals/CorrectieBerichtModal.vue`: correctiebericht aanmaken dialog
- `src/modals/JaaropgaveDetailModal.vue`: jaaropgave detail en verzendstatus

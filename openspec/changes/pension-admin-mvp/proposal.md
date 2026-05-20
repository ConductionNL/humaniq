---
kind: code
depends_on:
  - payroll-core-basic
---

# Proposal: Pension Admin MVP (PFZW, ABP, BPL, bpfBOUW)

## Why

Dutch werkgevers in zorg, overheid, landbouw en bouw zijn wettelijk verplicht maandelijks pensioenpremies aan te dragen bij sectorfondsen PFZW, ABP, BPL, bpfBOUW en StPVG via de Uniforme Pensioen Aangifte (UPA). Zonder geïntegreerde HR-software moeten HR-medewerkers pensioenaangiften handmatig samenstellen en aanleveren — foutgevoelig, tijdrovend en onschaalbaar voor organisaties met meerdere fondsen.

## What Changes

- Vijf nieuwe OpenRegister-schema's: PensionFund, PensionScheme, PensionParticipant, PensionDeclaration, PensionDeclarationLine
- UPA pensioenaangifte XML-generatie per fonds per aangifte-periode (Pensioenfederatie UPA-standaard)
- Bijdrageberekening per fondsregeling: pensioengrondslag, franchise-aftrek, premiesplitsing werkgever/werknemer
- Digitale aanlevering aan PFZW, ABP, BPL, bpfBOUW en StPVG via geconfigureerde OpenConnector-bronnen
- Declaratielevenscyclus (`concept → ingediend → bevestigd / afgewezen`) declaratief via `x-openregister-lifecycle`
- Pensioen-dashboard: KPI-widgets voor premie-totalen, aangifte-status en openstaande aanleveringen per fonds

## Capabilities

### New Capabilities

- `pension-fund-config`: Pensioenfonds registreren en beheren met aansluitingsnummer, premiepercentages, franchisebedragen en fondscode (PFZW/ABP/BPL/bpfBOUW/StPVG)
- `pension-participant-admin`: Deelnemerregistratie per fonds (deelnemersnummer, ingangsdatum, uittreedatum, parttimepercentage) gekoppeld aan werknemer uit payroll-core-basic
- `pension-contribution-calc`: Berekening pensioengrondslag (pensioensalaris minus franchise) en premiesplitsing werkgever/werknemer per fondsregeling per aangifteperiode
- `pension-declaration`: UPA-XML genereren, valideren op Pensioenfederatie-schema en digitaal indienen bij het fonds via OpenConnector
- `pension-dashboard`: Premie-KPI's en aangifte-statusoverzicht per fonds per maand

## Impact

- `lib/Settings/pensionadmin_register.json`: schema-registratie voor alle vijf entiteiten inclusief lifecycle, notifications en calculation-declaraties
- `lib/Service/PensionCalculationService.php`: grondslag- en premieberekening per fondsregeltype (domeinregelkiezer, imperatief per ADR-031)
- `lib/Service/UpaGeneratorService.php`: XML-generatie conform Pensioenfederatie UPA-standaard (documentgeneratie, imperatief per ADR-031)
- `lib/Service/PensionFundGatewayService.php`: aanlevering-adapters per fonds via OpenConnector (externe integratie, imperatief per ADR-031)
- `lib/Service/PensionDeclarationService.php`: declaratie-orchestratie (berekening → XML → validatie → indiening)
- `lib/Controller/PensionFundController.php`, `PensionParticipantController.php`, `PensionDeclarationController.php`: thin REST-controllers
- `src/views/`: DashboardView, PensionFundIndex/Detail, ParticipantIndex/Detail, DeclarationIndex/Detail
- `src/manifest.json`: Tier-1 manifest met routedefinities (ADR-024)

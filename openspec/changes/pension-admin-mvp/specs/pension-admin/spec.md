# Spec: Pension Admin MVP

**OpenSpec change:** pension-admin-mvp
**Status:** in-progress

---

## ADDED Requirements

### REQ-PEN-001: Pensioenfonds Configuratie

Het systeem stelt beheerders in staat pensioenfondsen te registreren en te beheren met fondscode, aansluitingsnummer, premiepercentages en franchise-parameters.

#### Scenario: Pensioenfonds aanmaken

- **GIVEN** een beheerder op de pensioenfondsbeheer-pagina
- **WHEN** ze een nieuw fonds aanmaken met fondscode `PFZW`, aansluitingsnummer `PZ-1234567`, premie werkgever `14.3%`, premie werknemer `4.9%` en jaarlijkse franchise `17350`
- **THEN** wordt het PensionFund-object opgeslagen in OpenRegister
- **AND** is het fonds selecteerbaar bij het registreren van deelnemers en fondsregelingen

#### Scenario: Ongeldige premiepercentages geblokkeerd

- **GIVEN** een beheerder
- **WHEN** ze een PensionFund aanmaken of muteren waarbij het totaal van werkgever- en werknemerpercentage `> 100` is
- **THEN** geeft het formulier een validatiefout: "Totaal premiepercentage mag niet meer dan 100% zijn"
- **AND** wordt het object niet opgeslagen

#### Scenario: Fondscode verplicht bij aanmaken

- **GIVEN** een beheerder
- **WHEN** ze een PensionFund opslaan zonder fondscode
- **THEN** geeft het systeem een validatiefout op het veld `fundCode`

---

### REQ-PEN-002: Fondsregelingen Beheren

Het systeem beheert de premie- en franchiseparameters per fondsregeling (PensionScheme), zodat meerdere regelingen per fonds mogelijk zijn (bijv. basisregeling en plusregeling).

#### Scenario: Fondsregeling aanmaken

- **GIVEN** een beheerder en een bestaand PensionFund `PFZW`
- **WHEN** ze een PensionScheme aanmaken met naam `PFZW Basisregeling 2026`, franchise `17350`, premie werkgever `14.3%`, premie werknemer `4.9%` en ingangsdatum `2026-01-01`
- **THEN** wordt de regeling opgeslagen gekoppeld aan het PFZW-fonds
- **AND** is de regeling beschikbaar bij het aanmelden van deelnemers

#### Scenario: Regelingenperiode gerespecteerd

- **GIVEN** een fondsregeling met einddatum `2026-12-31`
- **WHEN** een berekening wordt uitgevoerd voor aangifte-periode `2027-01`
- **THEN** geeft het systeem een fout: "Geen actieve fondsregeling voor deze periode"

---

### REQ-PEN-003: Deelnemer-administratie

Het systeem beheert pensioendeelnemers — de koppeling van een werknemer aan een pensioenfonds en -regeling.

#### Scenario: Deelnemer aanmelden

- **GIVEN** een HR-medewerker en een werknemer met BSN `123456782` aanwezig in payroll-core-basic
- **WHEN** ze de werknemer aanmelden bij PFZW met ingangsdatum `2026-01-01`, regeling `PFZW Basisregeling 2026` en deelnemersnummer `PFZ-1001001`
- **THEN** wordt het PensionParticipant-object aangemaakt in OpenRegister
- **AND** wordt de deelnemer meegenomen in de eerstvolgende maandelijkse UPA-aangifte bij PFZW

#### Scenario: Uittreding registreren

- **GIVEN** een actieve deelnemer bij PFZW met ingangsdatum `2020-03-01`
- **WHEN** de HR-medewerker uittreedatum `2026-06-30` registreert
- **THEN** wordt de deelnemer niet meer opgenomen in aangiften voor perioden na `2026-06`
- **AND** blijft de historische deelnemersdata bewaard en raadpleegbaar via de audit trail

#### Scenario: Parttime-percentage

- **GIVEN** een deelnemer met partTimePercentage `80`
- **WHEN** de premieberekening wordt uitgevoerd
- **THEN** wordt de franchise proportioneel aangepast: `franchise × 0.80`
- **AND** is de grondslag: `(pensioensalaris - aangepaste franchise)` met minimum `0`

---

### REQ-PEN-004: Pensioengrondslag- en Premieberekening

Het systeem berekent automatisch de pensioengrondslag en premiesplitsing per deelnemer per aangifteperiode.

#### Scenario: Grondslag- en premieberekening

- **GIVEN** een deelnemer bij PFZW met maandelijks pensioensalaris `4200`, franchise `17350` per jaar (= `1445.83` per maand), premie werkgever `14.3%`, premie werknemer `4.9%`
- **WHEN** de berekening wordt uitgevoerd voor aangifteperiode `2026-05`
- **THEN** is de grondslag `4200.00 - 1445.83 = 2754.17`
- **AND** is de premie werknemer `2754.17 × 4.9% = 134.95`
- **AND** is de premie werkgever `2754.17 × 14.3% = 393.85`

#### Scenario: Grondslag niet negatief

- **GIVEN** een deelnemer met een pensioensalaris lager dan de maandelijkse franchise
- **WHEN** de berekening wordt uitgevoerd
- **THEN** is de grondslag `0` en zijn beide premies `0`

#### Scenario: Maximum pensioensalaris toegepast

- **GIVEN** een fondsregeling met maximumPensioensalaris `71628` per jaar (= `5969` per maand) en een deelnemer met maandelijks salaris `8000`
- **WHEN** de berekening wordt uitgevoerd
- **THEN** wordt het salaris afgetopt op `5969` vóór de grondslagberekening

---

### REQ-PEN-005: UPA Aangifte Genereren

Het systeem genereert een geldige UPA-XML-declaratie per fonds per aangifteperiode op basis van de berekende deelnemergegevens.

#### Scenario: Maandelijkse UPA aanmaken

- **GIVEN** een HR-medewerker en drie actieve deelnemers bij PFZW voor periode `2026-05`
- **WHEN** ze via de declaratie-actie een nieuwe aangifte aanmaken voor `PFZW` en periode `2026-05`
- **THEN** worden voor alle drie deelnemers premieberekeningen uitgevoerd
- **AND** worden PensionDeclarationLine-objecten aangemaakt per deelnemer
- **AND** wordt een UPA-XML-document gegenereerd conform de Pensioenfederatie UPA-standaard
- **AND** krijgt de PensionDeclaration de status `concept`

#### Scenario: XML-validatie vóór indiening

- **GIVEN** een concept-aangifte
- **WHEN** de gebruiker de aangifte ter indiening aanbiedt
- **THEN** valideert het systeem de XML intern tegen het UPA XSD-schema
- **AND** bij validatiefouten: worden de foutieve regels en velden getoond en blijft de status `concept`
- **AND** bij geldige XML: gaat de workflow door naar indiening

#### Scenario: Correctie-aangifte

- **GIVEN** een eerder ingediende aangifte met status `afgewezen`
- **WHEN** de HR-medewerker de correctie indient
- **THEN** bevat de nieuwe XML een `correctienummer` dat één hoger is dan de vorige indiening
- **AND** wordt de corrigerende indiening vastgelegd in de audit trail

---

### REQ-PEN-006: Digitale Aanlevering aan Pensioenfonds

Het systeem dient de UPA-aangifte digitaal in bij het betreffende pensioenfonds via OpenConnector.

#### Scenario: Aangifte indienen bij PFZW

- **GIVEN** een gevalideerde concept-aangifte voor PFZW
- **WHEN** de HR-medewerker de aangifte indient
- **THEN** verstuurt het systeem de XML naar de geconfigureerde OpenConnector-bron voor PFZW
- **AND** verandert de status naar `ingediend` met een `ingediendOp`-timestamp
- **AND** ontvangt de HR-medewerker een bevestigings-notificatie

#### Scenario: Indiening mislukt — fonds reageert met fout

- **GIVEN** een aangifte met status `ingediend`
- **WHEN** het fonds een afwijzing teruggeeft via OpenConnector
- **THEN** verandert de status naar `afgewezen`
- **AND** wordt de foutbeschrijving van het fonds opgeslagen in het veld `foutmelding`
- **AND** ontvangt de HR-medewerker een notificatie met de foutmelding

#### Scenario: Indiening mislukt — verbindingsfout

- **GIVEN** een concept-aangifte
- **WHEN** het indienen mislukt door een verbindingsfout met OpenConnector
- **THEN** blijft de status `concept`
- **AND** toont het systeem een foutmelding aan de gebruiker; de XML is niet verloren

---

### REQ-PEN-007: Declaratielevenscyclus

Het systeem beheert de status-overgangen van PensionDeclaration via een declaratieve lifecycle.

#### Scenario: Geldige lifecycle-overgangen

- **GIVEN** een PensionDeclaration met status `concept`
- **WHEN** de aangifte geldig is en wordt ingediend
- **THEN** gaat de status naar `ingediend`
- **AND** bij bevestiging door het fonds via webhook/callback: status → `bevestigd`
- **AND** bij afwijzing door het fonds: status → `afgewezen`

#### Scenario: Ongeldig overgang geblokkeerd

- **GIVEN** een PensionDeclaration met status `bevestigd`
- **WHEN** een gebruiker probeert de status terug te zetten naar `concept`
- **THEN** weigert de lifecycle-engine de overgang
- **AND** geeft het systeem een fout: "Overgang van bevestigd naar concept is niet toegestaan"

#### Scenario: Elke transitie in audit trail

- **GIVEN** een aangifte die de lifecycle doorloopt van `concept` naar `bevestigd`
- **WHEN** de audit trail wordt geraadpleegd
- **THEN** zijn alle statusovergangen met tijdstempel en gebruiker vastgelegd

---

### REQ-PEN-008: Pensioen Dashboard

Het systeem toont een overzichtsdashboard met premie-KPI's en aangifte-statuskaarten per fonds.

#### Scenario: Dashboard KPI's laden

- **GIVEN** een ingelogde HR-medewerker
- **WHEN** ze de pensioen-dashboard-pagina openen
- **THEN** zijn vier KPI-blokken zichtbaar: totale premie werkgever (lopende periode), totale premie werknemer, openstaande aangiften, afgewezen aangiften

#### Scenario: Aangifte-statusoverzicht per fonds

- **GIVEN** drie geconfigureerde fondsen (PFZW, ABP, BPL) met aangiften voor de lopende periode
- **WHEN** de dashboardpagina wordt geladen
- **THEN** toont een statuskaart per fonds de huidige aangifte-status, het aantal deelnemers en de premietotalen

# Spec: WKR Administratie

Capability: `wkr-administratie` — Werkkostenregeling vrije ruimte bewaken per kalenderjaar; vergoedingen categoriseren als gerichte vrijstelling, vrije ruimte, of belast loon; eindheffing berekenen bij overschrijding van de vrije ruimte (80%).

---

## ADDED Requirements

### REQ-WKR-001: WKR vrije ruimte berekenen per kalenderjaar

Het systeem berekent en bewaakt de beschikbare WKR vrije ruimte per werkgever per kalenderjaar. De vrije ruimte bedraagt 1,92% van de fiscale loonsom tot €400.000 en 1,18% daarboven (tarieven 2026).

#### Scenario: Vrije ruimte berekend op basis van loonsom

- **GIVEN** de werkgever heeft een fiscale loonsom van €2.500.000 voor 2026
- **WHEN** het systeem de vrije ruimte berekent
- **THEN** is de vrije ruimte: (1,92% × €400.000) + (1,18% × €2.100.000) = €7.680 + €24.780 = €32.460
- **AND** toont het WKR-dashboard de beschikbare en gebruikte vrije ruimte als KPI-kaarten

#### Scenario: Dashboard toont actuele WKR-stand

- **GIVEN** er zijn `WkrAdministratie`-objecten aangemaakt voor 2026 met categorie `vrije-ruimte`
- **WHEN** de beheerder het WKR-dashboard (`WkrView`) opent
- **THEN** toont het dashboard: beschikbare vrije ruimte, gebruikte vrije ruimte, resterende vrije ruimte, en eindheffing-prognose
- **AND** is een waarschuwing zichtbaar wanneer het gebruik 80% of meer van de vrije ruimte bereikt

---

### REQ-WKR-002: Vergoeding categoriseren als gerichte vrijstelling, vrije ruimte of belast loon

De payroll-beheerder kan een vergoeding of verstrekking registreren en categoriseren. De categorisering bepaalt de fiscale behandeling conform WKR-regelgeving.

#### Scenario: Gerichte vrijstelling registreren (thuiswerkvergoeding)

- **GIVEN** de beheerder wil een thuiswerkvergoeding registreren van €2,35 per dag voor 200 medewerkers
- **WHEN** de beheerder een `WkrAdministratie`-post aanmaakt via `CnFormDialog` met categorie `gerichte-vrijstelling`
- **THEN** wordt de post opgeslagen met `categorie: gerichte-vrijstelling` en `bedrag: 29400.00`
- **AND** telt het bedrag NIET mee in het verbruik van de vrije ruimte
- **AND** heeft de gerichte vrijstelling geen eindheffing-implicaties

#### Scenario: Vrije ruimte post registreren (kerstpakket)

- **GIVEN** de beheerder registreert een kerstpakket van €300 per medewerker voor 12 medewerkers
- **WHEN** de post wordt aangemaakt met `categorie: vrije-ruimte`
- **THEN** telt €3.600 mee in het verbruik van de vrije ruimte
- **AND** wordt het resterende vrije ruimte saldo direct bijgewerkt in het dashboard

#### Scenario: Categorie validatie bij aanmaken

- **GIVEN** de beheerder vult een `WkrAdministratie`-post in zonder categorie te kiezen
- **WHEN** het formulier wordt opgeslagen
- **THEN** toont het formulier een validatiefout: `Categorie is verplicht`
- **AND** wordt de post niet opgeslagen

---

### REQ-WKR-003: Eindheffing berekenen bij overschrijding vrije ruimte

Wanneer de totale vrije-ruimte posten de beschikbare vrije ruimte overschrijden, berekent het systeem de verschuldigde eindheffing (80% over het overschrijdende bedrag).

#### Scenario: Eindheffing berekend bij overschrijding

- **GIVEN** de beschikbare vrije ruimte is €32.460 en de totale vrije-ruimte posten zijn €38.000
- **WHEN** het systeem de eindheffing berekent
- **THEN** is het overschot €38.000 - €32.460 = €5.540
- **AND** is de eindheffing 80% × €5.540 = €4.432
- **AND** toont het dashboard de eindheffing als aparte KPI met `eindheffingPercentage: 0.80`

#### Scenario: Geen eindheffing bij vrije ruimte niet overschreden

- **GIVEN** de totale vrije-ruimte posten (€28.000) zijn lager dan de beschikbare vrije ruimte (€32.460)
- **WHEN** het systeem de eindheffing berekent
- **THEN** is de eindheffing €0
- **AND** toont het dashboard een positieve indicatie: `Vrije ruimte niet overschreden`

#### Scenario: WKR-posten exporteren voor accountant

- **GIVEN** er zijn `WkrAdministratie`-objecten voor jaar 2026
- **WHEN** de beheerder exporteert via `CnMassExportDialog`
- **THEN** worden alle WKR-posten geëxporteerd als Excel of CSV
- **AND** bevat de export: omschrijving, bedrag, categorie, werkgever, werknemer (indien van toepassing), toelichting

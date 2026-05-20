# Spec: Jaarwerk Loonbelasting

Capability: `jaarwerk-loonbelasting` — Jaaropgaven genereren per werknemer op basis van gereconcilieerde maandaangiften; loonbelastingkaarten samenstellen; jaar-totalen controleren versus maandaangiften; digitaal versturen aan medewerkers.

---

## ADDED Requirements

### REQ-JAR-001: Jaaropgave genereren per werknemer

De payroll-beheerder kan jaaropgaven genereren voor alle werknemers op basis van de gecumuleerde maandaangiften van het afgelopen kalenderjaar.

#### Scenario: Jaaropgave genereren na afronden jaarwerk

- **GIVEN** alle maandaangiften voor jaar 2025 hebben status `verwerkt`
- **AND** de beheerder start jaaropgave-generatie voor jaar 2025
- **WHEN** `JaarwerkService` de jaaropgaven berekent
- **THEN** wordt voor iedere werknemer met actief dienstverband in 2025 een `Jaaropgave`-object aangemaakt
- **AND** zijn `totaalLoon`, `totaalLoonheffing` en `totaalZvwBijdrage` de som van de maandelijkse waarden
- **AND** heeft ieder `Jaaropgave`-object `gegenereerd: true` en `gegenereedOp` gevuld

#### Scenario: Jaaropgave-generatie geblokkeerd bij ontbrekende maandaangiften

- **GIVEN** tijdvak 2025-11 heeft geen `LoonaangifteRun` met status `verwerkt`
- **WHEN** de beheerder jaaropgaven probeert te genereren voor 2025
- **THEN** toont het systeem: `Niet alle maandaangiften voor 2025 zijn verwerkt. Ontbreekt: 2025-11`
- **AND** kan de beheerder de generatie alsnog forceren via bevestigingsdialog, waarbij de ontbrekende periode als nul wordt behandeld

---

### REQ-JAR-002: Jaar-totalen controleren versus maandaangiften

Het systeem voert een reconciliatie uit: de som van de maandelijkse loonheffing-totalen uit de `LoonaangifteRun`-objecten moet overeenkomen met de som van de individuele `Jaaropgave`-totalen.

#### Scenario: Reconciliatie slaagt — geen verschillen

- **GIVEN** de cumulatieve `totaalLoonheffing` over alle maandaangiften 2025 = €1.542.600
- **AND** de som van `totaalLoonheffing` over alle `Jaaropgave`-objecten 2025 = €1.542.600
- **WHEN** het systeem de reconciliatie uitvoert
- **THEN** rapporteert het systeem: `Reconciliatie geslaagd — geen afwijkingen gevonden`
- **AND** kan de beheerder doorgaan met het versturen van jaaropgaven

#### Scenario: Reconciliatie toont afwijking

- **GIVEN** de maandelijks gecumuleerde loonheffing (€1.542.600) wijkt af van de jaaropgave-som (€1.539.800) met €2.800
- **WHEN** het systeem de reconciliatie uitvoert
- **THEN** toont het reconciliatierapport de afwijking: `€2.800 verschil in totale loonheffing`
- **AND** worden de werknemers met de grootste individuele afwijkingen getoond
- **AND** kan de beheerder de betrokken jaaropgaven handmatig corrigeren vóór verzending

---

### REQ-JAR-003: Jaaropgave digitaal versturen aan medewerker

Na controle kan de payroll-beheerder jaaropgaven digitaal versturen aan de medewerkers. Verstuurde jaaropgaven zijn zichtbaar in het medewerkerportaal.

#### Scenario: Jaaropgave versturen aan individuele medewerker

- **GIVEN** werknemer WL-10042 heeft een gegenereerde `Jaaropgave` met `verstuurd: false`
- **WHEN** de beheerder de jaaropgave verstuurt via de detail-view
- **THEN** wijzigt `verstuurd` naar `true` en `verstuurdOp` naar het huidige tijdstip
- **AND** ontvangt de medewerker een Nextcloud-notificatie: `Uw jaaropgave 2025 is beschikbaar`

#### Scenario: Bulk-verzending jaaropgaven

- **GIVEN** er zijn 47 `Jaaropgave`-objecten voor 2025 met `gegenereerd: true` en `verstuurd: false`
- **WHEN** de beheerder bulk-verzenden activeert via `CnMassActionBar`
- **THEN** worden alle geselecteerde jaaropgaven verstuurd
- **AND** wordt voor iedere medewerker een Nextcloud-notificatie aangemaakt
- **AND** toont het overzicht het aantal succesvol verstuurd

#### Scenario: Jaaropgave exporteren als CSV voor accountant

- **GIVEN** er zijn `Jaaropgave`-objecten voor jaar 2025
- **WHEN** de beheerder exporteert via `CnMassExportDialog`
- **THEN** worden alle jaaropgaven geëxporteerd met: werknemerNummer, werknemerNaam, bsnGemaskeerd, totaalLoon, totaalLoonheffing, totaalZvwBijdrage, heffingskorting, verstuurd
- **AND** bevat de export geen volledige BSN-nummers (alleen `bsnGemaskeerd`)

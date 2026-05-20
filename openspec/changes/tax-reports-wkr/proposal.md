---
kind: code
depends_on: [payroll-core-basic]
chain: []
status: draft
---

# Proposal: Werkkostenregeling (WKR) calc + eindheffing

## Summary

Voeg Werkkostenregeling (WKR) administratie toe aan de hrmq-app. Implementeert:

1. **Vrije ruimte berekening** — 3% over de eerste €400.000 loonsom + 1,18% over het meerdere (2026-tarieven).
2. **WKR vergoeding administratie** — registreer individuele vergoedingen per medewerker, ingedeeld naar vergoedingsoort (gerichte vrijstelling of vrije ruimte).
3. **Eindheffing berekening** — automatisch 80% over de overschrijding van de vrije ruimte, direct zichtbaar per boekjaar.

Afhankelijk van `payroll-core-basic` voor de loonsom per boekjaar.

## Priority

**P0-must** — 28 concurrent implementations tracked; aanwezig bij alle toonaangevende Nederlandse HRM-platforms (ADP, AFAS, Centric, Loket, Employes, Exact, HR2day, Loonnext, Pivot-HR, Visma-Raet, Easy-Loon, Salure, Cipal-Schaubroeck).

## Dependencies

| Change | Reden |
|---|---|
| `payroll-core-basic` | Levert loonsomBedrag per boekjaar nodig voor vrije ruimte berekening |

## Features

### F-WKR-001: Vrije Ruimte Berekening

Berekent de jaarlijkse WKR vrije ruimte op basis van de totale loonsom:

```
vrijeRuimte = (3% × min(loonsom, 400.000)) + (1,18% × max(0, loonsom − 400.000))
```

De vrije ruimte is een berekend veld op het WkrBudget-object en wordt bij elke leesactie herberekend via `x-openregister-calculations`.

**Demand evidence:** Aanwezig in alle 11+ NL WKR-implementaties in het competitor-onderzoek (ADP, AFAS, Centric, Loket, Easy-Loon, Employes, Exact, HR2day, Loonnext, Pivot-HR, Visma-Raet).

### F-WKR-002: Eindheffing Berekening

Berekent automatisch de eindheffing over de overschrijding van de vrije ruimte:

```
overschrijding = max(0, toegewezenBedragTotaal − vrijeRuimteTotaal)
eindheffing    = 80% × overschrijding
```

Aggregated op WkrBudget-niveau via `x-openregister-aggregations`. Rapporteerbaar per boekjaar.

**Demand evidence:** Loket (OR-toets + eindheffing), AFAS (WKR-administratie), ADP, HR2day, Easy-Loon, Employes, Pivot-HR, Loonnext.

### F-WKR-003: WKR Vergoeding Administratie

Registreer, bekijk en beheer individuele WKR vergoedingen per medewerker. Elke vergoeding is gekoppeld aan een vergoedingsoort (categorie) en telt al dan niet mee in de vrije ruimte. Volledig CRUD via OpenRegister ObjectService + CnIndexPage/CnDetailPage.

**Demand evidence:** Aanwezig in alle NL HRM-platforms met WKR-functionaliteit.

### F-WKR-004: Vergoedingsoort Beheer

Beheer van WKR vergoedingsoorten (categorieën): naam, code, vrijstellingstype (gerichte vrijstelling of vrije ruimte) en optioneel maximumbedrag. Vormt de basis voor correcte categorisering van vergoedingen.

**Demand evidence:** AFAS (WKR-administratie), Exact (WKR-administratie + grootboek-koppeling), Loket.

## Stakeholders

### HR Administrateur

Verantwoordelijk voor het bijhouden van WKR vergoedingen per medewerker. Beheert vergoedingsoorten en controleert of de vrije ruimte niet wordt overschreden. Heeft dagelijks inzage nodig in het resterende budget.

### Salarisadministrateur

Verwerkt de eindheffing in de loonaangifte. Heeft kwartaal- en jaarcijfers nodig: totale vrije ruimte, totaal toegewezen bedrag, overschrijding en berekende eindheffing.

### Finance Controller

Bewaakt het WKR-budget op organisatieniveau. Wil inzicht in de voortgang van het boekjaar om onverwachte eindheffing aan het einde van het jaar te voorkomen.

## User Stories

### US-WKR-001: Vrije Ruimte Inzien

Als HR administrateur wil ik de vrije ruimte voor het huidige boekjaar zien, zodat ik weet hoeveel budget beschikbaar is voor onbelaste vergoedingen.

**Acceptance criteria:**

- GIVEN een ingelogde HR administrateur
- WHEN zij de WKR Budget-pagina opent voor boekjaar 2026
- THEN ziet zij de vrije ruimte berekend als `3% × min(loonsom, 400.000) + 1,18% × max(0, loonsom − 400.000)`
- AND ziet zij het totale reeds toegewezen bedrag
- AND ziet zij het resterende beschikbare bedrag (vrije ruimte − toegewezen)

### US-WKR-002: WKR Vergoeding Registreren

Als HR administrateur wil ik een WKR vergoeding registreren voor een medewerker, zodat deze wordt meegenomen in de vrije ruimte berekening.

**Acceptance criteria:**

- GIVEN een ingelogde HR administrateur op de vergoedingen-pagina
- WHEN zij een nieuwe vergoeding aanmaakt met medewerker, vergoedingsoort, bedrag en toewijzingsdatum
- THEN wordt de vergoeding opgeslagen als WkrVergoeding-object in het correcte boekjaar
- AND wordt het totaal toegewezen bedrag in het bijbehorende WkrBudget direct bijgewerkt

### US-WKR-003: Eindheffing Berekenen

Als salarisadministrateur wil ik de eindheffing inzien voor het boekjaar, zodat ik dit kan meenemen in de aangifte loonheffingen.

**Acceptance criteria:**

- GIVEN een salarisadministrateur met een bestaand WkrBudget voor boekjaar 2026
- WHEN de totale vergoedingen de vrije ruimte overschrijden
- THEN toont het systeem de overschrijding in euro's
- AND toont het systeem de eindheffing als 80% van de overschrijding
- AND is de eindheffing zichtbaar zonder handmatige berekening

### US-WKR-004: Vergoedingsoort Aanmaken

Als HR administrateur wil ik een nieuwe vergoedingsoort aanmaken, zodat ik vergoedingen correct kan categoriseren.

**Acceptance criteria:**

- GIVEN een ingelogde HR administrateur
- WHEN zij een vergoedingsoort aanmaakt met naam, code en vrijstellingstype
- THEN is de vergoedingsoort selecteerbaar bij het registreren van een vergoeding
- AND is het vrijstellingstype (gerichte vrijstelling / vrije ruimte) zichtbaar in de lijst

### US-WKR-005: WKR Dashboard Overzicht

Als finance controller wil ik een overzicht van de WKR-status per boekjaar zien, zodat ik tijdig kan bijsturen om eindheffing te vermijden.

**Acceptance criteria:**

- GIVEN een ingelogde finance controller
- WHEN zij het WKR-dashboard opent
- THEN ziet zij per boekjaar: loonsom, vrije ruimte, toegewezen bedrag, overschrijding en berekende eindheffing
- AND zijn alle bedragen automatisch berekend zonder handmatige invoer

## Customer Journeys

### CJ-WKR-001: Jaarafsluiting WKR

**Trigger:** Einde boekjaar (december / januari)

**Actoren:** Salarisadministrateur, Finance Controller

**Journey:**
1. Salarisadministrateur opent WkrBudget voor boekjaar 2025
2. Systeem toont vrije ruimte totaal, toegewezen bedrag en overschrijding
3. Bij overschrijding: systeem toont berekende eindheffing (80%)
4. Salarisadministrateur exporteert overzicht als input voor de loonaangifte
5. Finance Controller beoordeelt de eindpositie en neemt actie indien nodig

**Pain points (zonder WKR-module):** Handmatige berekening in Excel met risico op rekenfouten; geen real-time inzage tijdens het jaar.

### CJ-WKR-002: Vergoeding Toekennen

**Trigger:** HR administrateur wil een medewerker een vergoeding geven (bijv. kerstpakket, fietsplan)

**Actoren:** HR Administrateur

**Journey:**
1. HR administrateur kiest medewerker en vergoedingsoort
2. Systeem toont resterend vrij budget voor het boekjaar
3. HR administrateur voert bedrag en datum in
4. Systeem slaat vergoeding op en herberekent direct het resterende budget
5. Bij (dreigende) overschrijding: systeem toont waarschuwing

**Pain points (zonder WKR-module):** Geen real-time inzage; risk op onbedoelde overschrijding met bijbehorende eindheffing.

## Competitor Evidence

| Platform | Feature | Beschrijving |
|---|---|---|
| ADP NL | WKR-administratie | Werkkostenregeling |
| AFAS HRM | WKR-administratie | Werkkostenregeling + vrije ruimte |
| Centric HRM | WKR + Vergoedingen | Werkkostenregeling + secundaire arbeidsvoorwaarden |
| Easy-Loon | WKR | Werkkostenregeling |
| Employes | WKR | Werkkostenregeling |
| Exact Online HRM | WKR-administratie | Werkkostenregeling met grootboek-koppeling |
| HR2day | WKR + vergoedingen | Werkkostenregeling |
| Loket | Werkkostenregeling WKR | WKR vrije ruimte berekening, eindheffing, OR-toets |
| Loonnext | WKR | Werkkostenregeling administratie |
| Pivot-HR | WKR | Werkkostenregeling |
| Visma-Raet | UPA Loonaangifte | Belastingdienst UPA aanlevering met correcties |

## Out of Scope

- UPA loonaangifte aanlevering aan de Belastingdienst (separate change)
- OR-toets / ondernemingsraad goedkeuringsworkflow
- Correctieverklaringen
- Gerichte vrijstelling subcategorieën per specifieke CAO
- 30%-regeling expat-beheer
- Grootboek-koppeling / financiële administratie-integratie

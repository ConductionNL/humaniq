# Specifications: Werkkostenregeling (WKR) calc + eindheffing

Alle requirements zijn traceerbaar naar user stories in `proposal.md` en implementatietaken in `tasks.md`.

---

## REQ-WKR-001: Vrije Ruimte Tier-1 Berekening

**Prioriteit:** P0-must  
**Bronnen:** US-WKR-001, F-WKR-001  
**Implementatie:** `x-openregister-calculations.vrijeRuimteTier1` op schema `WkrBudget`

Het systeem berekent de vrije ruimte over de eerste schijf als 3% van het minimum van de loonsom en €400.000.

**Scenario 1.1 — Loonsom onder drempelwaarde:**

```
GIVEN een WkrBudget met boekjaar 2026 en loonsomBedrag = 300.000
WHEN het budget-object wordt gelezen
THEN is vrijeRuimteTier1 = 3% × 300.000 = 9.000,00
AND is vrijeRuimteTier2 = 0,00
AND is vrijeRuimteTotaal = 9.000,00
```

**Scenario 1.2 — Loonsom boven drempelwaarde:**

```
GIVEN een WkrBudget met boekjaar 2026 en loonsomBedrag = 920.000
WHEN het budget-object wordt gelezen
THEN is vrijeRuimteTier1 = 3% × 400.000 = 12.000,00
AND is vrijeRuimteTier2 = 1,18% × 520.000 = 6.136,00
AND is vrijeRuimteTotaal = 18.136,00
```

**Scenario 1.3 — Loonsom gelijk aan drempelwaarde:**

```
GIVEN een WkrBudget met loonsomBedrag = 400.000
WHEN het budget-object wordt gelezen
THEN is vrijeRuimteTier1 = 12.000,00
AND is vrijeRuimteTier2 = 0,00
AND is vrijeRuimteTotaal = 12.000,00
```

---

## REQ-WKR-002: Vrije Ruimte Tier-2 Berekening

**Prioriteit:** P0-must  
**Bronnen:** US-WKR-001, F-WKR-001  
**Implementatie:** `x-openregister-calculations.vrijeRuimteTier2` op schema `WkrBudget`

Het systeem berekent de vrije ruimte over de tweede schijf als 1,18% over het deel van de loonsom boven €400.000.

**Scenario 2.1 — Tweede schijf aanwezig:**

```
GIVEN een WkrBudget met loonsomBedrag = 1.000.000
WHEN het budget-object wordt gelezen
THEN is vrijeRuimteTier2 = 1,18% × 600.000 = 7.080,00
AND is vrijeRuimteTotaal = 12.000 + 7.080 = 19.080,00
```

**Scenario 2.2 — Geen tweede schijf (loonsom ≤ €400.000):**

```
GIVEN een WkrBudget met loonsomBedrag = 250.000
WHEN het budget-object wordt gelezen
THEN is vrijeRuimteTier2 = 0,00
```

---

## REQ-WKR-003: Toegewezen Bedrag Aggregatie

**Prioriteit:** P0-must  
**Bronnen:** US-WKR-001, US-WKR-002, F-WKR-001  
**Implementatie:** `x-openregister-aggregations.toegewezenBedrag` op schema `WkrBudget`

Het systeem aggregeert alle WkrVergoedingen voor een boekjaar tot het totale toegewezen bedrag op het bijbehorende WkrBudget.

**Scenario 3.1 — Meerdere vergoedingen in hetzelfde boekjaar:**

```
GIVEN een WkrBudget voor boekjaar 2026
AND drie WkrVergoedingen voor boekjaar 2026 met bedragen 528,00 / 749,00 / 75,00
WHEN het WkrBudget-object wordt gelezen
THEN is toegewezenBedrag = 1.352,00
```

**Scenario 3.2 — Vergoeding in ander boekjaar telt niet mee:**

```
GIVEN een WkrBudget voor boekjaar 2026
AND een WkrVergoeding voor boekjaar 2025 met bedrag 500,00
WHEN het WkrBudget 2026 wordt gelezen
THEN is toegewezenBedrag van WkrBudget 2026 ongewijzigd (niet 500,00 hoger)
```

**Scenario 3.3 — Geen vergoedingen:**

```
GIVEN een WkrBudget voor boekjaar 2026 zonder gekoppelde vergoedingen
WHEN het WkrBudget-object wordt gelezen
THEN is toegewezenBedrag = 0,00
AND is overschrijding = 0,00
AND is eindheffing = 0,00
```

---

## REQ-WKR-004: Overschrijding Berekening

**Prioriteit:** P0-must  
**Bronnen:** US-WKR-003, F-WKR-002  
**Implementatie:** `x-openregister-calculations.overschrijding` op schema `WkrBudget`

Het systeem berekent de overschrijding als het positieve verschil tussen toegewezen bedrag en vrije ruimte totaal. De overschrijding is nooit negatief.

**Scenario 4.1 — Vrije ruimte overschreden:**

```
GIVEN een WkrBudget met vrijeRuimteTotaal = 18.136,00
AND toegewezenBedrag = 22.500,00
WHEN het WkrBudget-object wordt gelezen
THEN is overschrijding = 22.500 − 18.136 = 4.364,00
```

**Scenario 4.2 — Vrije ruimte niet overschreden:**

```
GIVEN een WkrBudget met vrijeRuimteTotaal = 18.136,00
AND toegewezenBedrag = 10.000,00
WHEN het WkrBudget-object wordt gelezen
THEN is overschrijding = 0,00 (niet negatief)
AND is eindheffing = 0,00
```

---

## REQ-WKR-005: Eindheffing Berekening

**Prioriteit:** P0-must  
**Bronnen:** US-WKR-003, F-WKR-002  
**Implementatie:** `x-openregister-calculations.eindheffing` op schema `WkrBudget`

Het systeem berekent de verschuldigde eindheffing als 80% van de overschrijding.

**Scenario 5.1 — Eindheffing bij overschrijding:**

```
GIVEN een WkrBudget met overschrijding = 4.364,00
WHEN het WkrBudget-object wordt gelezen
THEN is eindheffing = 80% × 4.364 = 3.491,20
```

**Scenario 5.2 — Geen eindheffing zonder overschrijding:**

```
GIVEN een WkrBudget met overschrijding = 0,00
WHEN het WkrBudget-object wordt gelezen
THEN is eindheffing = 0,00
```

---

## REQ-WKR-006: Resterend Budget Berekening

**Prioriteit:** P1-should  
**Bronnen:** US-WKR-001, US-WKR-005, F-WKR-001  
**Implementatie:** `x-openregister-calculations.resterendBudget` op schema `WkrBudget`

Het systeem toont het resterend beschikbare budget als het positieve verschil tussen vrije ruimte totaal en toegewezen bedrag. Bij overschrijding is het resterend budget 0.

**Scenario 6.1 — Budget beschikbaar:**

```
GIVEN een WkrBudget met vrijeRuimteTotaal = 18.136,00 en toegewezenBedrag = 1.352,00
WHEN het WkrBudget-object wordt gelezen
THEN is resterendBudget = 18.136 − 1.352 = 16.784,00
```

**Scenario 6.2 — Budget uitgeput:**

```
GIVEN een WkrBudget met overschrijding > 0
WHEN het WkrBudget-object wordt gelezen
THEN is resterendBudget = 0,00 (niet negatief)
```

---

## REQ-WKR-007: WkrVergoeding Aanmaken

**Prioriteit:** P0-must  
**Bronnen:** US-WKR-002, F-WKR-003  

Een geautoriseerde gebruiker kan een WkrVergoeding aanmaken met verplichte velden: boekjaar, bedrag, toewijzingsdatum, medewerker-relatie en vergoedingsoort-relatie.

**Scenario 7.1 — Succesvolle aanmaak:**

```
GIVEN een ingelogde HR administrateur
AND een bestaande WkrVergoedingsoort "Thuiswerkvergoeding"
WHEN zij een vergoeding aanmaakt met boekjaar=2026, bedrag=528, toewijzingsdatum=2026-03-01
  EN koppeling naar medewerker en vergoedingsoort
THEN retourneert de API HTTP 201
AND is het object opgeslagen in OpenRegister
AND bevat het object de ingevulde velden inclusief relaties
```

**Scenario 7.2 — Verplicht veld ontbreekt:**

```
GIVEN een ingelogde HR administrateur
WHEN zij een vergoeding aanmaakt zonder `bedrag`
THEN retourneert de API HTTP 400
AND bevat de response een foutbericht over het ontbrekende veld
AND wordt er geen object aangemaakt
```

**Scenario 7.3 — Ongeautoriseerde aanmaak:**

```
GIVEN een niet-ingelogde gebruiker
WHEN een POST wordt gedaan naar /api/wkr-vergoedingen
THEN retourneert de API HTTP 401
```

---

## REQ-WKR-008: WkrVergoedingsoort Beheer

**Prioriteit:** P1-should  
**Bronnen:** US-WKR-004, F-WKR-004  

Een geautoriseerde gebruiker kan vergoedingsoorten aanmaken, bewerken en deactiveren. Het `vrijstellingstype` is verplicht en beperkt tot `gerichteVrijstelling` of `vrijeRuimte`.

**Scenario 8.1 — Vergoedingsoort aanmaken:**

```
GIVEN een ingelogde HR administrateur
WHEN zij een vergoedingsoort aanmaakt met naam="Kerstpakket", code="KERST",
  vrijstellingstype="vrijeRuimte"
THEN retourneert de API HTTP 201
AND is de vergoedingsoort selecteerbaar bij nieuwe vergoedingen
```

**Scenario 8.2 — Ongeldig vrijstellingstype:**

```
GIVEN een ingelogde HR administrateur
WHEN zij een vergoedingsoort aanmaakt met vrijstellingstype="onbekend"
THEN retourneert de API HTTP 400
```

---

## REQ-WKR-009: Boekjaar Isolatie

**Prioriteit:** P0-must  
**Bronnen:** US-WKR-001, REQ-WKR-003  

WkrVergoedingen uit verschillende boekjaren beïnvloeden elkaars WkrBudget niet. De aggregatie filtert strikt op het `boekjaar`-veld.

**Scenario 9.1 — Cross-boekjaar isolatie:**

```
GIVEN WkrBudget 2025 en WkrBudget 2026
AND WkrVergoedingen voor boekjaar 2025 met totaal 650,00
AND WkrVergoedingen voor boekjaar 2026 met totaal 1.352,00
WHEN beide budgetten worden gelezen
THEN is toegewezenBedrag van WkrBudget 2025 = 650,00
AND is toegewezenBedrag van WkrBudget 2026 = 1.352,00
AND beïnvloeden ze elkaars berekeningen niet
```

---

## REQ-WKR-010: WKR Dashboard Overzicht

**Prioriteit:** P1-should  
**Bronnen:** US-WKR-005, F-WKR-001, F-WKR-002  

De WKR-sectie in de app toont per boekjaar: loonsom, vrije ruimte totaal, toegewezen bedrag, resterend budget, overschrijding en eindheffing. Alle bedragen zijn automatisch berekend zonder handmatige invoer door de gebruiker.

**Scenario 10.1 — Dashboard met data:**

```
GIVEN een WkrBudget 2026 met loonsomBedrag=920.000 en vergoedingen totaal 1.352,00
WHEN een finance controller het WKR-overzicht opent
THEN ziet zij:
  - Loonsom: €920.000,00
  - Vrije ruimte: €18.136,00
  - Toegewezen: €1.352,00
  - Resterend: €16.784,00
  - Overschrijding: €0,00
  - Eindheffing: €0,00
```

**Scenario 10.2 — Dashboard bij overschrijding:**

```
GIVEN een WkrBudget waarbij overschrijding = 4.364,00
WHEN een finance controller het WKR-overzicht opent
THEN toont het systeem eindheffing = €3.491,20
AND is de overschrijding visueel benadrukt (CnStatusBadge "waarschuwing")
```

---

## REQ-WKR-011: Seed Data Aanwezig bij Installatie

**Prioriteit:** P1-should  
**Bronnen:** ADR-011 (seed data requirements)  

Bij installatie van de hrmq-app worden automatisch seed-objecten geladen voor de drie WKR-schema's. De seed data is idempotent: herinstalleren maakt geen duplicaten aan.

**Scenario 11.1 — Seed data bij eerste installatie:**

```
GIVEN een verse hrmq-installatie
WHEN de repair step wordt uitgevoerd
THEN zijn er minimaal 3 WkrVergoedingsoort-objecten aanwezig
AND minimaal 3 WkrBudget-objecten (boekjaar 2024, 2025, 2026)
AND minimaal 3 WkrVergoeding-objecten verdeeld over de boekjaren
```

**Scenario 11.2 — Idempotentie bij herinstallatie:**

```
GIVEN een hrmq-installatie met bestaande WKR-seed data
WHEN de repair step opnieuw wordt uitgevoerd
THEN is het aantal WkrVergoedingsoort-objecten niet toegenomen
AND zijn er geen duplicaten aangemaakt (match op slug)
```

---

## REQ-WKR-012: Autorisatie per Object (IDOR-preventie)

**Prioriteit:** P0-must  
**Bronnen:** ADR-005 (security), US-WKR-002  

Elke mutatie-endpoint verifieert dat de geauthenticeerde gebruiker rechten heeft op het specifieke object. Eindpunten met `#[NoAdminRequired]` hebben altijd een per-object autorisatiecheck.

**Scenario 12.1 — Ongeautoriseerde mutatiepoging:**

```
GIVEN gebruiker A heeft een WkrVergoeding aangemaakt
WHEN gebruiker B (geen admin, geen eigenaar) probeert die vergoeding te verwijderen
THEN retourneert de API HTTP 403
AND wordt de vergoeding niet verwijderd
```

**Scenario 12.2 — Admin kan altijd muteren:**

```
GIVEN een admin-gebruiker
WHEN de admin een WkrVergoeding van een willekeurige medewerker wijzigt
THEN retourneert de API HTTP 200
AND is de wijziging opgeslagen
```

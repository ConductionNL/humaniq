---
status: draft
---

# CAO Rijk — Design

## Architecture Overview

`cao-rijk` is a read-model capability that exposes a stable API consumed by payroll-engine-nl, leave-administration, rostering-planning, and contract-generation. It does not own the employment relationship (that lives in hrmq-core `Employment` aggregate) but extends it with rijks-specific arbeidsvoorwaarden and regulatory rules. All entities are stored via OpenRegister and accessed through `ObjectService`.

### Entity Model

#### Core Entities

**CaoRijkEmployment** (extends `Employment`)
- `employmentId`: UUID (reference to parent Employment)
- `salarisschaal`: enum(1-18 + chief subscales 15a–18a)
- `salarisnummer`: int (0-12, documented extensions 13-15)
- `functiefamilie`: enum (53 FGR-families)
- `functietypering`: string (role within family, maps to FUWASYS-score)
- `ministerie`: string (one of 11 ministeries or agency)
- `dienstonderdeel`: string (organisational sub-unit)
- `aanvangsdatum-overheidsdienst`: LocalDate (used for jubilea, BWR)
- `aanvangsdatum-huidige-functie`: LocalDate (used for in-functie-anciënniteit)
- `werktijdfactor`: decimal(3,2) (0.0–1.0, where 1.0 = 36-hour normweek)
- `functieClassificatie`: enum(generiek | sectorgebonden)
- `sectorgebonden-cao`: string, nullable (e.g., "cao-dji", "cao-belastingdienst")
- `createdAt`: Timestamp
- `modifiedAt`: Timestamp
- `effectiveFrom`: LocalDate (peildatum for salary/IKB/pension calculations)

**FuwasysScore**
- `fuwasysScoreId`: UUID
- `caoRijkEmploymentId`: UUID (reference)
- `kennisPunten`: int (0–20)
- `complexiteitPunten`: int (0–20)
- `contactenPunten`: int (0–20)
- `sturingPunten`: int (0–20)
- `afbreukrisicoPunten`: int (0–20)
- `bezwarendePunten`: int (0–20)
- `lichamelijkeInpanningPunten`: int (0–15)
- `oogvereistenPunten`: int (0–10)
- `totaalPunten`: int (computed from 8 deelscores)
- `schaalIndicatie`: string (schaal or schaal-range if on bandgrens)
- `managerMotivatie`: string, nullable (required if on bandgrens)
- `validatedAt`: Timestamp
- `createdAt`: Timestamp

**IkbBudget**
- `ikbBudgetId`: UUID
- `caoRijkEmploymentId`: UUID
- `ikbJaar`: int (calendar year)
- `salarissomBasis`: Money (12 monthly + structural toelagen)
- `ikbPercentage`: decimal(5,2) (e.g., 16.37)
- `totalBudget`: Money (salarissomBasis × ikbPercentage)
- `spentBudget`: Money (cumulative from transactions)
- `remainingBudget`: Money (computed)
- `lineItems`: [
    { `type`: enum(vakantietoelage|eindejaarsuitkering|levensloopbijdrage|bovenwettelijke-verlof), `amount`: Money, `spentAt`: Timestamp }
  ]
- `createdAt`: Timestamp
- `modifiedAt`: Timestamp

**BwrEntitlement**
- `bwrEntitlementId`: UUID
- `caoRijkEmploymentId`: UUID
- `ontslagdatum`: LocalDate
- `ontslaggrond`: enum(reorganisatie|medische-gronden|discipline|eigen-verzoek)
- `diensttijdjaren`: decimal(3,1)
- `leeftijdBijOntslag`: int
- `aansluitendeUitkeringMaanden`: int (0 if niet-applicable)
- `aanvullendeUitkeringMaanden`: int (typically 6)
- `totalUitkeringMaanden`: int (computed)
- `totalUitkeringPercentage`: decimal(5,2)
- `laatstVerdiendeLoOn`: Money
- `createdAt`: Timestamp

**WachtgeldEntitlement**
- `wachtgeldEntitlementId`: UUID
- `caoRijkEmploymentId`: UUID
- `ontslagdatum`: LocalDate
- `ontslaggrond`: enum (as above)
- `diensttijdjaren`: decimal(3,1)
- `leeftijdBijOntslag`: int
- `elegibiliteitsIndicatie`: enum(leeftijdsgebonden|reguliere|niet-eligible)
- `reden`: string (e.g., "aanstelling-na-wnra-conversie")
- `wachtgeldPercentageJaar1`: decimal(5,2) (70%)
- `wachtgeldDuration`: string (duration until AOW or fixed months)
- `createdAt`: Timestamp

**DetacheringsBesluit**
- `detacheringsbesluItId`: UUID
- `caoRijkEmploymentId`: UUID
- `detacheringsType`: enum(binnen-rijks|buiten-rijks)
- `startdatum`: LocalDate
- `einddatum`: LocalDate (mandatory)
- `uitlenendeDienst`: string (ministry/agency)
- `inlenerDienst`: string (ministry/agency for binnen-rijks, external org for buiten-rijks)
- `werkplaats`: string (physical location)
- `doorbetalingsRegime`: enum(uitlenende-betaalt|inlener-betaalt)
- `opslag`: decimal(5,2), nullable (percentage for binnen-rijks doorbelasting)
- `createdAt`: Timestamp
- `modifiedAt`: Timestamp

#### Reference Data Entities

**BbraSalarisTabel**
- `bbrasalarisTabelId`: UUID
- `caoAkkoordDatum`: LocalDate (e.g., 2025-01-01 for CAO-2024-2025)
- `salarisschaal`: enum (1–18 + subscales)
- `salarisnummer`: int (0–12, extensions 13–15)
- `brutoMaandSalaris`: Money
- `effectiveFrom`: LocalDate
- `createdAt`: Timestamp

**FgrFunctiefamilie**
- `fgrFunctiefamilieId`: UUID
- `familieCode`: int (1–53)
- `familieNaam`: string (e.g., "Beleid", "Ondersteunend")
- `schaalBandbredte`: [int, int] (e.g., [11, 13] for some families)
- `sectorgebonden`: boolean (false for generiek families available to all ministeries)
- `applicableSectorCaos`: [string], nullable (e.g., ["cao-dji", "cao-belastingdienst"])
- `createdAt`: Timestamp

**AbpPremietabel**
- `abpPremietabelId`: UUID
- `effectiveFrom`: LocalDate (e.g., 2026-01-01)
- `opPremiePercentage`: decimal(5,2) (currently ~24.7%)
- `aaopPremiePercentage`: decimal(5,2)
- `anwHiaatPercentage`: decimal(5,2)
- `franchiseAmount`: Money (e.g., EUR 17,545 for 2026)
- `werkgeverAandeel`: decimal(5,2) (70% for OP)
- `werknemer Aandeel`: decimal(5,2) (30% for OP)
- `createdAt`: Timestamp

---

## Seed Data

### 1. CaoRijkEmployment

```json
{
  "@self": {
    "register": "cao-rijk-employments",
    "schema": "CaoRijkEmployment",
    "slug": "emp-marieke-beleidsadviseur"
  },
  "employmentId": "00000000-0000-0000-0000-000000000001",
  "salarisschaal": "11",
  "salarisnummer": 6,
  "functiefamilie": "Beleid",
  "functietypering": "Senior beleidsadviseur",
  "ministerie": "Ministerie van Binnenlandse Zaken en Koninkrijksrelaties",
  "dienstonderdeel": "Directie Personeelsmanagement",
  "aanvangsdatum-overheidsdienst": "2015-03-15",
  "aanvangsdatum-huidige-functie": "2023-09-01",
  "werktijdfactor": 1.0,
  "functieClassificatie": "generiek",
  "sectorgebonden-cao": null,
  "effectiveFrom": "2026-01-01",
  "createdAt": "2026-05-20T10:30:00Z",
  "modifiedAt": "2026-05-20T10:30:00Z"
}
```

```json
{
  "@self": {
    "register": "cao-rijk-employments",
    "schema": "CaoRijkEmployment",
    "slug": "emp-jan-dji-bewaarder"
  },
  "employmentId": "00000000-0000-0000-0000-000000000002",
  "salarisschaal": "8",
  "salarisnummer": 3,
  "functiefamilie": "Operationeel (DJI)",
  "functietypering": "Penitentiair inrichtingswerker A",
  "ministerie": "Ministerie van Justitie en Veiligheid",
  "dienstonderdeel": "Dienst Justitiële Inrichtingen (DJI)",
  "aanvangsdatum-overheidsdienst": "2010-06-01",
  "aanvangsdatum-huidade-functie": "2022-11-15",
  "werktijdfactor": 0.9,
  "functieClassificatie": "sectorgebonden",
  "sectorgebonden-cao": "cao-dji",
  "effectiveFrom": "2026-01-01",
  "createdAt": "2026-05-18T14:20:00Z",
  "modifiedAt": "2026-05-18T14:20:00Z"
}
```

```json
{
  "@self": {
    "register": "cao-rijk-employments",
    "schema": "CaoRijkEmployment",
    "slug": "emp-anna-belastingdienst"
  },
  "employmentId": "00000000-0000-0000-0000-000000000003",
  "salarisschaal": "13",
  "salarisnummer": 4,
  "functiefamilie": "Toezicht",
  "functietypering": "Inspecteur Belastingdienst",
  "ministerie": "Ministerie van Financiën",
  "dienstonderdeel": "Belastingdienst",
  "aanvangsdatum-overheidsdienst": "2008-01-15",
  "aanvangsdatum-huidae-functie": "2021-08-01",
  "werktijdfactor": 1.0,
  "functieClassificatie": "sectorgebonden",
  "sectorgebonden-cao": "cao-belastingdienst",
  "effectiveFrom": "2026-01-01",
  "createdAt": "2026-05-19T09:15:00Z",
  "modifiedAt": "2026-05-19T09:15:00Z"
}
```

### 2. FuwasysScore

```json
{
  "@self": {
    "register": "cao-rijk-fuwasys-scores",
    "schema": "FuwasysScore",
    "slug": "fuwasys-marieke-2026"
  },
  "fuwasysScoreId": "00000000-0000-0000-0000-000000000010",
  "caoRijkEmploymentId": "00000000-0000-0000-0000-000000000001",
  "kennisPunten": 15,
  "complexiteitPunten": 12,
  "contactenPunten": 8,
  "sturingPunten": 5,
  "afbreukrisicoPunten": 2,
  "bezwarendePunten": 0,
  "lichamelijkeInpanningPunten": 0,
  "oogvereistenPunten": 0,
  "totaalPunten": 42,
  "schaalIndicatie": "11",
  "managerMotivatie": null,
  "validatedAt": "2026-03-15T11:00:00Z",
  "createdAt": "2026-03-15T11:00:00Z"
}
```

```json
{
  "@self": {
    "register": "cao-rijk-fuwasys-scores",
    "schema": "FuwasysScore",
    "slug": "fuwasys-jan-2025"
  },
  "fuwasysScoreId": "00000000-0000-0000-0000-000000000011",
  "caoRijkEmploymentId": "00000000-0000-0000-0000-000000000002",
  "kennisPunten": 10,
  "complexiteitPunten": 9,
  "contactenPunten": 6,
  "sturingPunten": 3,
  "afbreukrisicoPunten": 1,
  "bezwarendePunten": 4,
  "lichamelijkeInpanningPunten": 3,
  "oogvereistenPunten": 0,
  "totaalPunten": 36,
  "schaalIndicatie": "8",
  "managerMotivatie": null,
  "validatedAt": "2025-10-20T08:45:00Z",
  "createdAt": "2025-10-20T08:45:00Z"
}
```

### 3. IkbBudget

```json
{
  "@self": {
    "register": "cao-rijk-ikb-budgets",
    "schema": "IkbBudget",
    "slug": "ikb-marieke-2026"
  },
  "ikbBudgetId": "00000000-0000-0000-0000-000000000020",
  "caoRijkEmploymentId": "00000000-0000-0000-0000-000000000001",
  "ikbJaar": 2026,
  "salarissomBasis": {
    "amount": 63600.00,
    "currency": "EUR"
  },
  "ikbPercentage": 16.37,
  "totalBudget": {
    "amount": 10418.82,
    "currency": "EUR"
  },
  "spentBudget": {
    "amount": 2850.00,
    "currency": "EUR"
  },
  "remainingBudget": {
    "amount": 7568.82,
    "currency": "EUR"
  },
  "lineItems": [
    {
      "type": "bovenwettelijke-verlof",
      "amount": { "amount": 2850.00, "currency": "EUR" },
      "spentAt": "2026-04-15T13:30:00Z"
    }
  ],
  "createdAt": "2026-01-01T00:00:00Z",
  "modifiedAt": "2026-05-15T14:45:00Z"
}
```

### 4. BbraSalarisTabel

```json
{
  "@self": {
    "register": "cao-rijk-bbra-salarissen",
    "schema": "BbraSalarisTabel",
    "slug": "bbra-schaal11-trede6-2025"
  },
  "bbrasalarisTabelId": "00000000-0000-0000-0000-000000000030",
  "caoAkkoordDatum": "2025-01-01",
  "salarisschaal": "11",
  "salarisnummer": 6,
  "brutoMaandSalaris": {
    "amount": 5124.43,
    "currency": "EUR"
  },
  "effectiveFrom": "2026-01-01",
  "createdAt": "2025-12-20T10:00:00Z"
}
```

```json
{
  "@self": {
    "register": "cao-rijk-bbra-salarissen",
    "schema": "BbraSalarisTabel",
    "slug": "bbra-schaal9-trede3-2025"
  },
  "bbrasalarisTabelId": "00000000-0000-0000-0000-000000000031",
  "caoAkkoordDatum": "2025-01-01",
  "salarisschaal": "9",
  "salarisnummer": 3,
  "brutoMaandSalaris": {
    "amount": 4187.65,
    "currency": "EUR"
  },
  "effectiveFrom": "2026-01-01",
  "createdAt": "2025-12-20T10:00:00Z"
}
```

### 5. AbpPremietabel

```json
{
  "@self": {
    "register": "cao-rijk-abp-premies",
    "schema": "AbpPremietabel",
    "slug": "abp-premies-2026"
  },
  "abpPremietabelId": "00000000-0000-0000-0000-000000000040",
  "effectiveFrom": "2026-01-01",
  "opPremiePercentage": 24.70,
  "aaopPremiePercentage": 2.15,
  "anwHiaatPercentage": 0.98,
  "franchiseAmount": {
    "amount": 17545.00,
    "currency": "EUR"
  },
  "werkgeverAandeel": 70.0,
  "werknemiierAandeel": 30.0,
  "createdAt": "2025-12-15T09:00:00Z"
}
```

---

## Integration Points

### Outbound Events (cao-rijk publishes)

1. **CaoRijkEmploymentCreated**
   - Published when new CaoRijkEmployment is registered
   - Consumed by: payroll-engine-nl (salary lookup), leave-administration (IKB-opbouw), contract-generation (arbeidsovereenkomst)

2. **CaoRijkEmploymentModified**
   - Published when schaal, functiefamilie, werktijdfactor, or dienstonderdeel changes
   - Consumed by: payroll-engine-nl (recalculate salary), rostering-planning (if sectorgebonden reclassified)

3. **IkbBudgetUpdated**
   - Published when IKB-spend transaction posted
   - Consumed by: leave-administration (update verlofkaart)

4. **FunctieClassificationChanged**
   - Published when functievervulling reclassified as generiek/sectorgebonden
   - Consumed by: rostering-planning (enable/disable piketinroostering for DJI-bewaarders)

5. **WachtgeldEntitlementCalculated** / **BwrEntitlementCalculated**
   - Published when termination triggers wachtgeld or BWR claim
   - Consumed by: UitkeringService (initiate claim processing)

### Inbound Dependencies

- **hrmq-core:** CaoRijkEmployment extends Employment aggregate; foreign-key references employmentId
- **payroll-engine-nl:** calls `resolveSalary(employmentId, peildatum)` → receives decomposed bezoldigingsspecificatie
- **leave-administration:** subscribes to `IkbBudgetUpdated` events
- **rostering-planning:** subscribes to `FunctieClassificationChanged` events
- **contract-generation:** calls `cao-rijk` for salarisindicatie + arbeidsvoorwaardenpakket at aanstelling
- **abp-aansluiting-verplicht:** handles daadwerkelijke ABP-koppeling via A&O Services; cao-rijk declares aansluiting maar delegeert feitelijke gegevensuitwisseling
- **wnra-conversion:** prerequisite peer for employees with aanstellingsdatum before 2020-01-01

### Reuse Analysis

- **OpenRegister** — all data persisted via ObjectService (no custom Entity/Mapper)
- **Money value objects** — all financial fields use Money type with EUR currency, round-half-to-even
- **LocalDate** — all civil-calendar dates (aanstellingsdatum, peildatum, etc.) use LocalDate to avoid timezone drift
- **Employment aggregate** — CaoRijkEmployment extends base Employment from hrmq-core via polymorphic `caoSpecificDetails` property
- No deduplication issues — cao-rijk does not overlap with cao-gemeenten, cao-onderwijs, cao-ziekenhuizen, cao-zorg-vvt (all sibling CAO-modules with distinct rulesets)

---

## Compliance & Standards

All implementations ground in primary sources:
- **CAO Rijk 2024-2025** (BZK) for regelingen
- **BBRA 1984** (Bezoldigingsbesluit Burgerlijke Rijksambtenaren) for salaristabellen + schaal-struktur
- **FGR-handleiding** (Functiegebouw Rijk) + FUWASYS-conversietabel for function valuation
- **ABP-pensioenreglement** + yearly ABP-premietabel
- **Wnra** (Wet normalisering rechtspositie ambtenaren) for post-2020-01-01 employment regime
- **Handboek Loonheffingen 2026** (Belastingdienst) for gerichte vrijstellingen & werkkostenregeling
- **Verzamelwet SZW 2026** for wettelijke loondoorbetalingsbasis bij ziekte

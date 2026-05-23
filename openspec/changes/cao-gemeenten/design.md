# CAO Gemeenten — Design

**Status:** pending

---

## Data Model & Entity Definitions

The CAO Gemeenten specification uses five core entities managed in OpenRegister, complemented by related systems (payroll-engine, ABP, verlofadministratie).

### Entity 1: CAO_Versie

**Register:** `cao-versies`  
**Schema:** `CAOVersie` (PascalCase, schema.org + Dutch extensions)

**Purpose:** Master record storing a single CAO version with validity period and statutory parameters.

**Fields:**
```json
{
  "@self": {
    "register": "cao-versies",
    "schema": "CAOVersie",
    "slug": "cao-gemeenten-2024-2026"
  },
  "caoCode": "GEMEENTEN",
  "versieNummer": "2024-2026",
  "ingangsdatum": "2024-01-01",
  "einddatum": "2026-12-31",
  "publicatieDatum": "2024-04-15",
  "vngBron": "https://vng.nl/cao-gemeenten/2024-2026",
  "vngDocumentHash": "sha256:...",
  "loondoorbetalingZiekteJaar1Percentage": 100.0,
  "loondoorbetalingZiekteJaar2Percentage": 70.0,
  "ikbPercentage": 17.5,
  "vakantieDagenPerJaar": 158.4,
  "abpAansluitingVerplicht": true,
  "bwgrVanToepassing": true,
  "ondertekenaars": ["VNG", "FNV", "CNV", "CMHF"],
  "status": "actief"
}
```

**Constraints:**
- `caoCode` is immutable (identifies CAO family)
- `status` ∈ {actief, concept, ingetrokken} — actieve versies kunnen niet verwijderd worden
- `versieNummer` moet uniek zijn per `caoCode`
- `vngDocumentHash` detecteert tampering van officiële tabel

---

### Entity 2: Salaristabel_Schaal

**Register:** `salaristabel-schalen`  
**Schema:** `SalaristabelSchaal`

**Purpose:** Stores all salary scales 1-19 with periodiek ranges and monthly amounts per CAO version.

**Fields:**
```json
{
  "@self": {
    "register": "salaristabel-schalen",
    "schema": "SalaristabelSchaal",
    "slug": "schaal-10-gemeenten-2024-2026"
  },
  "caoVersieId": "uuid",
  "schaalNummer": 10,
  "schaalNaam": "Schaal 10",
  "minimumBruto": 3358.00,
  "maximumBruto": 4671.00,
  "aantalPeriodieken": 11,
  "periodieken": [
    {"periodiek": 0, "bedrag": 3358.00, "geldendVan": "2024-01-01"},
    {"periodiek": 1, "bedrag": 3487.00, "geldendVan": "2024-01-01"},
    {"periodiek": 2, "bedrag": 3618.00, "geldendVan": "2024-01-01"},
    {"periodiek": 3, "bedrag": 3756.00, "geldendVan": "2024-01-01"},
    {"periodiek": 4, "bedrag": 3897.00, "geldendVan": "2024-01-01"},
    {"periodiek": 5, "bedrag": 4041.00, "geldendVan": "2024-01-01"},
    {"periodiek": 6, "bedrag": 4189.00, "geldendVan": "2024-01-01"},
    {"periodiek": 7, "bedrag": 4341.00, "geldendVan": "2024-01-01"},
    {"periodiek": 8, "bedrag": 4497.00, "geldendVan": "2024-01-01"},
    {"periodiek": 10, "bedrag": 4582.00, "geldendVan": "2024-01-01"},
    {"periodiek": 11, "bedrag": 4671.00, "geldendVan": "2024-01-01"}
  ],
  "geldigVanaf": "2024-10-01",
  "geldigTot": null,
  "opmerking": "Automatische jaarlijkse aanpassing per AVV CAO artikel 3.7"
}
```

**Constraints:**
- Alle bedragen zijn monthly-gross in EUR
- Periodieken zijn 0-based (0 = startperiodiek)
- `geldigVanaf` bepaalt effectieve datum per salarismutatie
- Immutable na activatie (copy-on-CAO-wijziging)

---

### Entity 3: Medewerker_Rechtspositie

**Register:** `medewerker-rechtspositie`  
**Schema:** `MedewerkerRechtspositie`

**Purpose:** Per-employee CAO-binding, schaal/periodiek, deeltijdfactor, IKB-percentage, ABP- en BWGR-rechten.

**Fields:**
```json
{
  "@self": {
    "register": "medewerker-rechtspositie",
    "schema": "MedewerkerRechtspositie",
    "slug": "medewerker-amsterdam-2020-beleidsmedewerker"
  },
  "medewerkerId": "uuid",
  "caoCode": "GEMEENTEN",
  "caoVersieId": "uuid",
  "rechtspositie": "ambtenaar_wnra",
  "aanstellingsdatum": "2020-06-01",
  "aanstellingType": "vast",
  "schaalNummer": 10,
  "periodiek": 4,
  "brutoMaandsalaris": 3897.00,
  "deeltijdfactor": 1.0,
  "functieCode": "HR21-BELEIDSMEDEWERKER-II",
  "functieNaam": "Beleidsmedewerker B",
  "afdeling": "Sociaal Domein",
  "leidinggevende": "uuid",
  "ikbPercentage": 17.5,
  "abpDeelnemerNummer": "ABP-12345678",
  "bwgrRechten": true,
  "wachtgeldRechten": false,
  "buitengewoonVerlofSaldo": 16.0,
  "roosterverlofSaldo": 7.2,
  "versieBindingsDatum": "2020-06-01",
  "laatsteSchaalMutatieDatum": null
}
```

**Constraints:**
- `caoVersieId` bindt medewerker aan een specifieke CAO-versie
- `rechtspositie` ∈ {ambtenaar_wnra, transitie_ambtenaar} — bepaalt CAO-toepassing
- `schaalNummer` moet binnen HR21-bereik van `functieCode` vallen
- `deeltijdfactor` ∈ (0, 1] — voltijd = 1.0
- IKB opbouwing afhankelijk van `ikbPercentage` + maandloon

---

### Entity 4: IKB_Rekening

**Register:** `ikb-rekeningen`  
**Schema:** `IKBRekening`

**Purpose:** Annual accumulation and withdrawal tracking of Individual Labor Contribution (IKB) savings account.

**Fields:**
```json
{
  "@self": {
    "register": "ikb-rekeningen",
    "schema": "IKBRekening",
    "slug": "medewerker-amsterdam-2024-ikb"
  },
  "medewerkerId": "uuid",
  "jaar": 2024,
  "caoVersieId": "uuid",
  "openingssaldo": 0.00,
  "maandelijkseOpbouw": [
    {"maand": "2024-01", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
    {"maand": "2024-02", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
    {"maand": "2024-03", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
    {"maand": "2024-04", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
    {"maand": "2024-05", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
    {"maand": "2024-06", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
    {"maand": "2024-07", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
    {"maand": "2024-08", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
    {"maand": "2024-09", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
    {"maand": "2024-10", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
    {"maand": "2024-11", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
    {"maand": "2024-12", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00}
  ],
  "totaalOpgebouwd": 8184.00,
  "opnames": [
    {"datum": "2024-05-15", "bedrag": 3500.00, "type": "uitbetaling_vakantiegeld", "verzoekId": "uuid", "goedkeurdDoor": "uuid", "opmerking": "Pinksteren / mei-vakantie"},
    {"datum": "2024-09-01", "bedrag": 1200.00, "type": "extra_verlof", "verlofUren": 40, "verzoekId": "uuid", "goedkeurdDoor": "uuid"},
    {"datum": "2024-12-15", "bedrag": 1500.00, "type": "fiets_van_de_zaak", "verzoekId": "uuid", "goedkeurdDoor": "uuid"}
  ],
  "saldo": 1984.00,
  "afrekeningEindeJaar": true,
  "fiscalRegime": "WKR_gericht_vrijgesteld_waar_mogelijk"
}
```

**Constraints:**
- `opbouwPercentage` komt uit CAO_Versie
- Opnames in zes doelen: contante_uitbetaling, extra_verlof, fiets_van_de_zaak, vakbondscontributie, opleidingskosten, bedrijfsfitness
- Eindejaar-afrekeningslogica: onopgenomen saldo wordt bruto uitbetaald + IKB-saldo reset naar 0

---

### Entity 5: Ziekteperiode

**Register:** `ziekteperiodes`  
**Schema:** `Ziekteperiode`

**Purpose:** Tracks sick leave periods with two-year loondoorbetaling transition (100% year 1 → 70% year 2).

**Fields:**
```json
{
  "@self": {
    "register": "ziekteperiodes",
    "schema": "Ziekteperiode",
    "slug": "medewerker-amsterdam-2024-03-10"
  },
  "medewerkerId": "uuid",
  "ziekteperiodeId": "uuid",
  "startDatum": "2024-03-10",
  "eindDatum": null,
  "weekNummerInPeriode": 8,
  "huidigPercentage": 100,
  "verwachteOvergangNaar70Percent": "2025-03-10",
  "rePIntegratieFase": "spoor_1",
  "bedrijfsartsRapporten": ["uuid", "uuid"],
  "uwvMelding": null,
  "hervatsingsDatum": null,
  "samentellingsRegel": "binnen_4_weken"
}
```

**Constraints:**
- `huidigPercentage` automatisch bepaald vanuit `startDatum` + `verwachteOvergangNaar70Percent`
- Samentellingsregel: ziekte < 4 weken hervatting telt door
- `uwvMelding` genereert automatisch na 42 weken doorlopende ziekte

---

### Entity 6: BWGR_Uitkering

**Register:** `bwgr-uitkeringen`  
**Schema:** `BWGRUitkering`

**Purpose:** Supplementary unemployment benefit (BWGR) calculation upon termination, plus wachtgeld carve-out.

**Fields:**
```json
{
  "@self": {
    "register": "bwgr-uitkeringen",
    "schema": "BWGRUitkering",
    "slug": "medewerker-amsterdam-ontslag-2024-08"
  },
  "exMedewerkerId": "uuid",
  "ontslagdatum": "2024-08-01",
  "ontslagrond": "reorganisatie",
  "diensttijdJaren": 12.5,
  "wwUitkeringStart": "2024-09-01",
  "wwUitkeringEinde": "2026-04-30",
  "bwgrAanvullingPercentage": 20.0,
  "bwgrLooptijdMaanden": 24,
  "bwgrTotaalBedrag": 18420.00,
  "bwgrBetaaldTotOpDatum": "2024-12-31",
  "bwgrRestSaldo": 13815.00,
  "wachtgeldVan": "2026-05-01",
  "wachtgeldEinde": "2028-04-30",
  "slapeendBWGRSaldo": 0.00,
  "opmerkingen": "Reorganisatie Digitale Transformatie"
}
```

**Constraints:**
- `bwgrLooptijdMaanden` bepaald door CAO artikel 10.3 op basis van diensttijd
- Geen overlap tussen BWGR en wachtgeld
- Slapend-saldo ondersteuning voor vervolgwerkloosheid

---

## API Integration Points

### Outbound to payroll-engine-nl

**Endpoint:** `POST /index.php/apps/payroll-engine-nl/api/salary-calculation`

**Payload (per medewerker, per maand):**
```json
{
  "medewerkerId": "uuid",
  "berekeningsDatum": "2024-05-01",
  "brutoMaandsalaris": 3897.00,
  "schaalNummer": 10,
  "periodiek": 4,
  "deeltijdfactor": 1.0,
  "ikbPercentage": 17.5,
  "ziekteLoondoorbetalingPercentage": 100,
  "eindejaarsuitkeringBedrag": 687.50,
  "levensloopbijdrageBedrag": 0.00,
  "abpVrije_VerslaggingsGegevens": {
    "abpDeelnemerNummer": "ABP-12345678",
    "abpAansluitingDatum": "2020-06-01"
  },
  "caoCode": "GEMEENTEN",
  "caoVersieId": "uuid",
  "bwgrAanvullingPercentage": 0
}
```

### Outbound to ABP UPA

**Integration:** SOAP over HTTPS to `https://upa.abp.nl/UPA2.asmx`

**Operations:**
- `AanmeldingMedewerker` (new employee registration)
- `MutatieMedewerker` (salary, deeltijdfactor changes)
- `AfmeldingMedewerker` (termination)

---

## Seed Data

### CAO_Versie Seed Objects (3 versions)

```json
[
  {
    "@self": {
      "register": "cao-versies",
      "schema": "CAOVersie",
      "slug": "cao-gemeenten-2024-2026"
    },
    "caoCode": "GEMEENTEN",
    "versieNummer": "2024-2026",
    "ingangsdatum": "2024-01-01",
    "einddatum": "2026-12-31",
    "publicatieDatum": "2024-04-15",
    "vngBron": "https://vng.nl/cao-gemeenten/2024-2026",
    "loondoorbetalingZiekteJaar1Percentage": 100.0,
    "loondoorbetalingZiekteJaar2Percentage": 70.0,
    "ikbPercentage": 17.5,
    "vakantieDagenPerJaar": 158.4,
    "abpAansluitingVerplicht": true,
    "bwgrVanToepassing": true,
    "ondertekenaars": ["VNG", "FNV", "CNV", "CMHF"],
    "status": "actief"
  },
  {
    "@self": {
      "register": "cao-versies",
      "schema": "CAOVersie",
      "slug": "cao-gemeenten-2022-2024"
    },
    "caoCode": "GEMEENTEN",
    "versieNummer": "2022-2024",
    "ingangsdatum": "2022-01-01",
    "einddatum": "2023-12-31",
    "publicatieDatum": "2022-04-15",
    "vngBron": "https://vng.nl/cao-gemeenten/2022-2024",
    "loondoorbetalingZiekteJaar1Percentage": 100.0,
    "loondoorbetalingZiekteJaar2Percentage": 70.0,
    "ikbPercentage": 16.5,
    "vakantieDagenPerJaar": 156.0,
    "abpAansluitingVerplicht": true,
    "bwgrVanToepassing": true,
    "ondertekenaars": ["VNG", "FNV", "CNV", "CMHF"],
    "status": "ingetrokken"
  },
  {
    "@self": {
      "register": "cao-versies",
      "schema": "CAOVersie",
      "slug": "cao-gemeenten-2026-2028"
    },
    "caoCode": "GEMEENTEN",
    "versieNummer": "2026-2028",
    "ingangsdatum": "2027-01-01",
    "einddatum": "2028-12-31",
    "publicatieDatum": "2026-04-01",
    "vngBron": "https://vng.nl/cao-gemeenten/2026-2028",
    "loondoorbetalingZiekteJaar1Percentage": 100.0,
    "loondoorbetalingZiekteJaar2Percentage": 70.0,
    "ikbPercentage": 18.0,
    "vakantieDagenPerJaar": 160.8,
    "abpAansluitingVerplicht": true,
    "bwgrVanToepassing": true,
    "ondertekenaars": ["VNG", "FNV", "CNV", "CMHF"],
    "status": "concept"
  }
]
```

### Salaristabel_Schaal Seed Objects (5 schalen × 1 versie = 5 objects)

```json
[
  {
    "@self": {
      "register": "salaristabel-schalen",
      "schema": "SalaristabelSchaal",
      "slug": "schaal-7-gemeenten-2024-2026"
    },
    "caoVersieId": "{{cao-gemeenten-2024-2026.id}}",
    "schaalNummer": 7,
    "schaalNaam": "Schaal 7",
    "minimumBruto": 2647.00,
    "maximumBruto": 3674.00,
    "aantalPeriodieken": 11,
    "periodieken": [
      {"periodiek": 0, "bedrag": 2647.00, "geldendVan": "2024-01-01"},
      {"periodiek": 1, "bedrag": 2756.00, "geldendVan": "2024-01-01"},
      {"periodiek": 2, "bedrag": 2868.00, "geldendVan": "2024-01-01"},
      {"periodiek": 3, "bedrag": 2984.00, "geldendVan": "2024-01-01"},
      {"periodiek": 4, "bedrag": 3104.00, "geldendVan": "2024-01-01"},
      {"periodiek": 5, "bedrag": 3227.00, "geldendVan": "2024-01-01"},
      {"periodiek": 6, "bedrag": 3354.00, "geldendVan": "2024-01-01"},
      {"periodiek": 7, "bedrag": 3485.00, "geldendVan": "2024-01-01"},
      {"periodiek": 8, "bedrag": 3574.00, "geldendVan": "2024-01-01"},
      {"periodiek": 10, "bedrag": 3625.00, "geldendVan": "2024-01-01"},
      {"periodiek": 11, "bedrag": 3674.00, "geldendVan": "2024-01-01"}
    ],
    "geldigVanaf": "2024-10-01",
    "geldigTot": null
  },
  {
    "@self": {
      "register": "salaristabel-schalen",
      "schema": "SalaristabelSchaal",
      "slug": "schaal-10-gemeenten-2024-2026"
    },
    "caoVersieId": "{{cao-gemeenten-2024-2026.id}}",
    "schaalNummer": 10,
    "schaalNaam": "Schaal 10",
    "minimumBruto": 3358.00,
    "maximumBruto": 4671.00,
    "aantalPeriodieken": 11,
    "periodieken": [
      {"periodiek": 0, "bedrag": 3358.00, "geldendVan": "2024-01-01"},
      {"periodiek": 1, "bedrag": 3487.00, "geldendVan": "2024-01-01"},
      {"periodiek": 2, "bedrag": 3618.00, "geldendVan": "2024-01-01"},
      {"periodiek": 3, "bedrag": 3756.00, "geldendVan": "2024-01-01"},
      {"periodiek": 4, "bedrag": 3897.00, "geldendVan": "2024-01-01"},
      {"periodiek": 5, "bedrag": 4041.00, "geldendVan": "2024-01-01"},
      {"periodiek": 6, "bedrag": 4189.00, "geldendVan": "2024-01-01"},
      {"periodiek": 7, "bedrag": 4341.00, "geldendVan": "2024-01-01"},
      {"periodiek": 8, "bedrag": 4497.00, "geldendVan": "2024-01-01"},
      {"periodiek": 10, "bedrag": 4582.00, "geldendVan": "2024-01-01"},
      {"periodiek": 11, "bedrag": 4671.00, "geldendVan": "2024-01-01"}
    ],
    "geldigVanaf": "2024-10-01",
    "geldigTot": null
  },
  {
    "@self": {
      "register": "salaristabel-schalen",
      "schema": "SalaristabelSchaal",
      "slug": "schaal-13-gemeenten-2024-2026"
    },
    "caoVersieId": "{{cao-gemeenten-2024-2026.id}}",
    "schaalNummer": 13,
    "schaalNaam": "Schaal 13",
    "minimumBruto": 4485.00,
    "maximumBruto": 6233.00,
    "aantalPeriodieken": 11,
    "periodieken": [
      {"periodiek": 0, "bedrag": 4485.00, "geldendVan": "2024-01-01"},
      {"periodiek": 1, "bedrag": 4671.00, "geldendVan": "2024-01-01"},
      {"periodiek": 2, "bedrag": 4861.00, "geldendVan": "2024-01-01"},
      {"periodiek": 3, "bedrag": 5054.00, "geldendVan": "2024-01-01"},
      {"periodiek": 4, "bedrag": 5252.00, "geldendVan": "2024-01-01"},
      {"periodiek": 5, "bedrag": 5455.00, "geldendVan": "2024-01-01"},
      {"periodiek": 6, "bedrag": 5663.00, "geldendVan": "2024-01-01"},
      {"periodiek": 7, "bedrag": 5875.00, "geldendVan": "2024-01-01"},
      {"periodiek": 8, "bedrag": 6000.00, "geldendVan": "2024-01-01"},
      {"periodiek": 10, "bedrag": 6118.00, "geldendVan": "2024-01-01"},
      {"periodiek": 11, "bedrag": 6233.00, "geldendVan": "2024-01-01"}
    ],
    "geldigVanaf": "2024-10-01",
    "geldigTot": null
  },
  {
    "@self": {
      "register": "salaristabel-schalen",
      "schema": "SalaristabelSchaal",
      "slug": "schaal-16-gemeenten-2024-2026"
    },
    "caoVersieId": "{{cao-gemeenten-2024-2026.id}}",
    "schaalNummer": 16,
    "schaalNaam": "Schaal 16",
    "minimumBruto": 5687.00,
    "maximumBruto": 7882.00,
    "aantalPeriodieken": 11,
    "periodieken": [
      {"periodiek": 0, "bedrag": 5687.00, "geldendVan": "2024-01-01"},
      {"periodiek": 1, "bedrag": 5918.00, "geldendVan": "2024-01-01"},
      {"periodiek": 2, "bedrag": 6155.00, "geldendVan": "2024-01-01"},
      {"periodiek": 3, "bedrag": 6397.00, "geldendVan": "2024-01-01"},
      {"periodiek": 4, "bedrag": 6645.00, "geldendVan": "2024-01-01"},
      {"periodiek": 5, "bedrag": 6899.00, "geldendVan": "2024-01-01"},
      {"periodiek": 6, "bedrag": 7159.00, "geldendVan": "2024-01-01"},
      {"periodiek": 7, "bedrag": 7426.00, "geldendVan": "2024-01-01"},
      {"periodiek": 8, "bedrag": 7569.00, "geldendVan": "2024-01-01"},
      {"periodiek": 10, "bedrag": 7725.00, "geldendVan": "2024-01-01"},
      {"periodiek": 11, "bedrag": 7882.00, "geldendVan": "2024-01-01"}
    ],
    "geldigVanaf": "2024-10-01",
    "geldigTot": null
  },
  {
    "@self": {
      "register": "salaristabel-schalen",
      "schema": "SalaristabelSchaal",
      "slug": "schaal-19-gemeenten-2024-2026"
    },
    "caoVersieId": "{{cao-gemeenten-2024-2026.id}}",
    "schaalNummer": 19,
    "schaalNaam": "Schaal 19",
    "minimumBruto": 7200.00,
    "maximumBruto": 9950.00,
    "aantalPeriodieken": 11,
    "periodieken": [
      {"periodiek": 0, "bedrag": 7200.00, "geldendVan": "2024-01-01"},
      {"periodiek": 1, "bedrag": 7485.00, "geldendVan": "2024-01-01"},
      {"periodiek": 2, "bedrag": 7778.00, "geldendVan": "2024-01-01"},
      {"periodiek": 3, "bedrag": 8079.00, "geldendVan": "2024-01-01"},
      {"periodiek": 4, "bedrag": 8389.00, "geldendVan": "2024-01-01"},
      {"periodiek": 5, "bedrag": 8708.00, "geldendVan": "2024-01-01"},
      {"periodiek": 6, "bedrag": 9036.00, "geldendVan": "2024-01-01"},
      {"periodiek": 7, "bedrag": 9372.00, "geldendVan": "2024-01-01"},
      {"periodiek": 8, "bedrag": 9546.00, "geldendVan": "2024-01-01"},
      {"periodiek": 10, "bedrag": 9747.00, "geldendVan": "2024-01-01"},
      {"periodiek": 11, "bedrag": 9950.00, "geldendVan": "2024-01-01"}
    ],
    "geldigVanaf": "2024-10-01",
    "geldigTot": null
  }
]
```

### Medewerker_Rechtspositie Seed Objects (3 employees)

```json
[
  {
    "@self": {
      "register": "medewerker-rechtspositie",
      "schema": "MedewerkerRechtspositie",
      "slug": "amsterdam-anna-van-damme-beleidsmedewerker"
    },
    "medewerkerId": "{{anna-van-damme.id}}",
    "caoCode": "GEMEENTEN",
    "caoVersieId": "{{cao-gemeenten-2024-2026.id}}",
    "rechtspositie": "ambtenaar_wnra",
    "aanstellingsdatum": "2020-06-01",
    "aanstellingType": "vast",
    "schaalNummer": 10,
    "periodiek": 4,
    "brutoMaandsalaris": 3897.00,
    "deeltijdfactor": 1.0,
    "functieCode": "HR21-BELEIDSMEDEWERKER-II",
    "functieNaam": "Beleidsmedewerker B",
    "afdeling": "Sociaal Domein",
    "leidinggevende": "{{john-de-wilde.id}}",
    "ikbPercentage": 17.5,
    "abpDeelnemerNummer": "ABP-12345678",
    "bwgrRechten": true,
    "wachtgeldRechten": false,
    "buitengewoonVerlofSaldo": 16.0,
    "roosterverlofSaldo": 7.2,
    "versieBindingsDatum": "2020-06-01",
    "laatsteSchaalMutatieDatum": null
  },
  {
    "@self": {
      "register": "medewerker-rechtspositie",
      "schema": "MedewerkerRechtspositie",
      "slug": "rotterdam-ben-jansen-projectleider"
    },
    "medewerkerId": "{{ben-jansen.id}}",
    "caoCode": "GEMEENTEN",
    "caoVersieId": "{{cao-gemeenten-2024-2026.id}}",
    "rechtspositie": "ambtenaar_wnra",
    "aanstellingsdatum": "2018-03-15",
    "aanstellingType": "vast",
    "schaalNummer": 13,
    "periodiek": 7,
    "brutoMaandsalaris": 5875.00,
    "deeltijdfactor": 0.889,
    "functieCode": "HR21-PROJECTLEIDER-I",
    "functieNaam": "Projectleider I",
    "afdeling": "Infrastructuur en Duurzaamheid",
    "leidinggevende": "{{marie-dupont.id}}",
    "ikbPercentage": 17.5,
    "abpDeelnemerNummer": "ABP-87654321",
    "bwgrRechten": true,
    "wachtgeldRechten": false,
    "buitengewoonVerlofSaldo": 16.0,
    "roosterverlofSaldo": 6.4,
    "versieBindingsDatum": "2024-01-01",
    "laatsteSchaalMutatieDatum": "2024-01-01"
  },
  {
    "@self": {
      "register": "medewerker-rechtspositie",
      "schema": "MedewerkerRechtspositie",
      "slug": "utrecht-christina-brown-manager-financieel"
    },
    "medewerkerId": "{{christina-brown.id}}",
    "caoCode": "GEMEENTEN",
    "caoVersieId": "{{cao-gemeenten-2024-2026.id}}",
    "rechtspositie": "ambtenaar_wnra",
    "aanstellingsdatum": "2015-09-01",
    "aanstellingType": "vast",
    "schaalNummer": 16,
    "periodiek": 10,
    "brutoMaandsalaris": 7725.00,
    "deeltijdfactor": 1.0,
    "functieCode": "HR21-MANAGER-FINANCIEEL",
    "functieNaam": "Manager Financieel",
    "afdeling": "Organisatie & Bestuur",
    "leidinggevende": "{{robert-smith.id}}",
    "ikbPercentage": 17.5,
    "abpDeelnemerNummer": "ABP-55443322",
    "bwgrRechten": true,
    "wachtgeldRechten": true,
    "buitengewoonVerlofSaldo": 16.0,
    "roosterverlofSaldo": 7.2,
    "versieBindingsDatum": "2024-01-01",
    "laatsteSchaalMutatieDatum": "2024-03-01"
  }
]
```

### IKB_Rekening Seed Objects (2 accounts)

```json
[
  {
    "@self": {
      "register": "ikb-rekeningen",
      "schema": "IKBRekening",
      "slug": "amsterdam-anna-2024-ikb"
    },
    "medewerkerId": "{{anna-van-damme.id}}",
    "jaar": 2024,
    "caoVersieId": "{{cao-gemeenten-2024-2026.id}}",
    "openingssaldo": 850.00,
    "maandelijkseOpbouw": [
      {"maand": "2024-01", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
      {"maand": "2024-02", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
      {"maand": "2024-03", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
      {"maand": "2024-04", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
      {"maand": "2024-05", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
      {"maand": "2024-06", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
      {"maand": "2024-07", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
      {"maand": "2024-08", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
      {"maand": "2024-09", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
      {"maand": "2024-10", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
      {"maand": "2024-11", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00},
      {"maand": "2024-12", "grondslag": 3897.00, "opbouwPercentage": 17.5, "opbouw": 682.00}
    ],
    "totaalOpgebouwd": 8184.00,
    "opnames": [
      {"datum": "2024-05-15", "bedrag": 3500.00, "type": "uitbetaling_vakantiegeld", "verzoekId": "uuid-5015", "goedkeurdDoor": "{{john-de-wilde.id}}", "opmerking": "Mei-vakantie"},
      {"datum": "2024-09-01", "bedrag": 1200.00, "type": "extra_verlof", "verlofUren": 40, "verzoekId": "uuid-9001", "goedkeurdDoor": "{{john-de-wilde.id}}"}
    ],
    "saldo": 3534.00,
    "afrekeningEindeJaar": false,
    "fiscalRegime": "WKR_gericht_vrijgesteld_waar_mogelijk"
  },
  {
    "@self": {
      "register": "ikb-rekeningen",
      "schema": "IKBRekening",
      "slug": "rotterdam-ben-2024-ikb"
    },
    "medewerkerId": "{{ben-jansen.id}}",
    "jaar": 2024,
    "caoVersieId": "{{cao-gemeenten-2024-2026.id}}",
    "openingssaldo": 1200.00,
    "maandelijkseOpbouw": [
      {"maand": "2024-01", "grondslag": 5223.00, "opbouwPercentage": 17.5, "opbouw": 914.00},
      {"maand": "2024-02", "grondslag": 5223.00, "opbouwPercentage": 17.5, "opbouw": 914.00},
      {"maand": "2024-03", "grondslag": 5223.00, "opbouwPercentage": 17.5, "opbouw": 914.00},
      {"maand": "2024-04", "grondslag": 5223.00, "opbouwPercentage": 17.5, "opbouw": 914.00},
      {"maand": "2024-05", "grondslag": 5223.00, "opbouwPercentage": 17.5, "opbouw": 914.00},
      {"maand": "2024-06", "grondslag": 5223.00, "opbouwPercentage": 17.5, "opbouw": 914.00},
      {"maand": "2024-07", "grondslag": 5223.00, "opbouwPercentage": 17.5, "opbouw": 914.00},
      {"maand": "2024-08", "grondslag": 5223.00, "opbouwPercentage": 17.5, "opbouw": 914.00},
      {"maand": "2024-09", "grondslag": 5223.00, "opbouwPercentage": 17.5, "opbouw": 914.00},
      {"maand": "2024-10", "grondslag": 5223.00, "opbouwPercentage": 17.5, "opbouw": 914.00},
      {"maand": "2024-11", "grondslag": 5223.00, "opbouwPercentage": 17.5, "opbouw": 914.00},
      {"maand": "2024-12", "grondslag": 5223.00, "opbouwPercentage": 17.5, "opbouw": 914.00}
    ],
    "totaalOpgebouwd": 10968.00,
    "opnames": [
      {"datum": "2024-12-15", "bedrag": 1500.00, "type": "fiets_van_de_zaak", "verzoekId": "uuid-1215", "goedkeurdDoor": "{{marie-dupont.id}}"}
    ],
    "saldo": 10668.00,
    "afrekeningEindeJaar": false,
    "fiscalRegime": "WKR_gericht_vrijgesteld_waar_mogelijk"
  }
]
```

---

## Frontend Components & Interactions

**Pages:**
- **CAO's & regelingen list page** — Browse/filter/archive CAO versions
- **CAO version detail page** — View salaristabel, edit parametersm download VNG-documentatie
- **Medewerker rechtspositie detail** — View/edit schaal, periodiek, IKB%, ABP-deelnemernummer
- **IKB account page** — View opbouw/opname history, submit requests
- **Ziekteperiode tracker** — Auto-detect, show 100% → 70% countdown
- **BWGR calculator** — Termination flow, auto-calculate entitlements
- **Audit trail report** — Filter by medewerker/periode/CAO-artikel, export

**Forms:**
- Auto-generated from schemas via CnFormDialog + OpenRegister integration
- HR21 functieCode lookup → auto-set schaalNummer range
- CAO-versie versioning + immutable publish workflow

---

## Reuse Analysis

**OpenRegister Services:**
- `ObjectService` — CRUD on entities, searchObjects for version matching
- `RelationService` — Link medewerker → CAO_Versie, medewerker → Ziekteperiode
- `AuditTrailService` — Automatic change tracking on salarisschaal, periodiek mutations
- `ExportService` — CSV/Excel export for audit reports
- `ImportService` — Bulk CAO-table imports + idempotent versioning

**No duplication identified.** All CRUD, audit, search leverage platform provided services.

---

## Performance Considerations

- **Salaristabel lookups** cached per CAO-versie, keyed on (caoCode, caoVersieId, schaalNummer, periodiek)
- **IKB monthly aggregation** via offline task runner (not real-time)
- **Ziekteperiode state machine** via cron-backed date transition (100% → 70%)
- **BWGR recalculation** on ontslag-registration, stored immutable

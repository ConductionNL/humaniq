# CAO Gemeenten — Proposal

**Status:** pending

**Change ID:** cao-gemeenten

**Title:** CAO Gemeenten (incl. Wnra rechtspositie + IKB)

**Placement:** `SETTING` — under `Configuratie › CAO's & regelingen`

---

## Executive Summary

Implementeer de volledige CAO Gemeenten 2024-2026 als configureerbare rechtspositieregeling binnen hrmq, inclusief de overgang naar privaatrecht onder de Wet normalisering rechtspositie ambtenaren (Wnra) per 1 januari 2020. Dit feature set levert de datamodellen, salaristabellen, IKB-berekeningen, ziekteloondoorbetaling, bovenwettelijke werkloosheidsregelingen en de verplichte ABP-aansluiting.

**Outcome:** Een HR-adviseur kan binnen 15 minuten een nieuwe medewerker aannemen met correcte salarisschaal, periodiek, IKB-percentage en ABP-aansluiting. Een salarisadministrateur kan maandelijks een CAO-conforme loonstrook genereren. Een controller kan iedere salariscomponent herleiden naar een specifiek CAO-artikel met versiestempel.

---

## Problem Statement

De CAO Gemeenten verschilt fundamenteel van reguliere markt-CAO's op vier punten:

1. **Ziekteloondoorbetaling:** 100% jaar 1, 70% jaar 2 (vs. wettelijke 70% beide jaren)
2. **Bovenwettelijke werkloosheidsregeling:** Uitgebreide BWGR bovenop WW
3. **ABP-aansluiting:** Verplicht pensioen, geen keuze voor andere uitvoerder
4. **Functiewaardering:** Via sectorale HR21 instrument, niet generieke functiehuis

Zonder data-gestuurde ondersteuning van deze afwijkingen raken gemeenten snel uit pace met CAO-compliantie, wat leidt tot onder-uitbetalingen, overwerk voor HR/salarissen en auditrisico.

---

## User Stories & Demand Scores

### Story 1: HR-adviseur nieuwe aanstelling (Demand: 95/100)

```gherkin
GIVEN een HR-adviseur een nieuwe medewerker inhuurt bij gemeente Amsterdam
WHEN de adviseur het aanstellingsformulier opent
THEN het systeem stelt automatisch voor:
  - Salarisschaal op basis van HR21-functiewaardering
  - Periodiek (0-11) op basis van ervaring/anciënniteit
  - IKB 17.5% van brutomaandsalaris
  - ABP-deelnemernummer (nieuw genereren als nodig)
  - Verlofrechten (wettelijk + bovenwettelijk + rooster)
```

**Acceptance Criteria:**
- Template-invulling duurt < 10 minuten
- CAO-versiebinding is expliciet (2024-2026)
- ABP wordt automatisch aangemeld

### Story 2: Salarisadministrateur maandelijkse loonrun (Demand: 95/100)

```gherkin
GIVEN een salarisadministrateur voert de maandelijkse loonrun uit
WHEN het systeem de bruto-netto berekening doet
THEN zijn alle toeslagen, IKB-opbouw, ziekteloondoorbetaling-percentages
     en pensioeninhoudingen CAO-conform
```

**Acceptance Criteria:**
- IKB-opbouw 17.5% correct berekend
- Ziekteperiode automatisch gedetecteerd (overgang 100% → 70%)
- Eindejaar-afrekeningslogica correct
- Versiestempel per berekening vastgelegd

### Story 3: IKB-opname medewerker (Demand: 85/100)

```gherkin
GIVEN een medewerker heeft IKB-saldo en wil extra verlof opnemen
WHEN de aanvraag wordt ingediend en goedgekeurd
THEN het systeem boekt het bedrag af van IKB, voegt uren toe aan verlof,
     en registreert geen fiscale belastinggebeurtenis
```

**Acceptance Criteria:**
- Zes doelen ondersteund: contante uitbetaling, extra verlof, fiets, vakbond, opleiding, fitnes
- Fiscale behandeling correct (WKR gericht vrijgesteld waar mogelijk)
- Saldo-drift preventiemechanisme aanwezig

### Story 4: Controller/Accountant audit (Demand: 80/100)

```gherkin
GIVEN een controller voert kwartaalaudit uit
WHEN het auditrapport wordt gegenereerd
THEN iedere salariscomponent is herleidbaar naar specifiek CAO-artikel
     met datum-binding en versie-stempel
```

**Acceptance Criteria:**
- Audit trail beschikbaar per medewerker per periode
- CAO-artikel-referentie per wijziging vastgelegd
- Export naar CSV/PDF beschikbaar
- Tamper-detection via SHA-256 salaristabel-hash

### Story 5: Ontslag & bovenwettelijke werkloosheidsregeling (Demand: 70/100)

```gherkin
GIVEN een medewerker wordt ontslagen wegens reorganisatie na 12,5 jaar
WHEN de exit-procedure wordt afgerond
THEN het systeem berekent automatisch BWGR-aanvulling 20% gedurende 24 maanden
     en genereert betalingsschema voor salarissen of UWV
```

**Acceptance Criteria:**
- BWGR-berekening op basis van diensttijd + ontslagrond
- Wachtgeldrecht automatisch geactiveerd na BWGR-einde
- Slapend-saldo-beheer bij vervolgwerk

---

## Information Architecture

**Placement type:** `SETTING`

**Navigation path:** Configuratie › CAO's & regelingen › CAO Gemeenten

**No top-level menu entry.** This is configuration data consumed by payroll-engine, not a workflow.

---

## Dependencies & Integrations

- **payroll-engine-nl:** Ontvangt salarisparameters, retourneert bruto-netto berekening
- **abp-aansluiting-verplicht:** SOAP-koppeling naar ABP UPA voor aanmeldingen/mutaties/afmeldingen
- **functiehuis-hr21:** HR21-functiebereik-validatie
- **verlofadministratie:** Ontvangt verlofrechten-data, verwerkt opnamen
- **uwv-koppeling:** Ziekmeldingen > 42w, WW-aanvragen
- **docudesk:** Automatisch gegenereerde contracten, formulieren
- **decidesk:** Bezwaarprocedures tegen functie-indelingen

---

## Stakeholder Impact

| Persona | Frequency | Impact | Effort |
|---------|-----------|--------|--------|
| HR-adviseur | 40-60% | Hoog — snellere nieuwe aanstellingen | M |
| Salarisadministrateur | 30-40% | Hoog — maandelijkse conformiteit | H |
| Manager/leidinggevende | Incidenteel | Gemiddeld — verlofgoedkeuring, promoties | L |
| Controller/accountant | Kwartaal, jaar | Hoog — auditeerbare rapportage | M |
| Medewerker (self-service) | Incidenteel | Gemiddeld — IKB-keuze, loonstrook | L |

---

## Success Metrics

1. **Invoersnelheid:** Nieuwe aanstelling < 10 minuten (vs. huidige 30-45 min)
2. **Loonrun-foutquote:** < 0,5% afwijkingen van CAO-tabel
3. **Audit-traceerheid:** 100% van salariswijzigingen herleidbaar naar CAO-artikel
4. **Conformiteit:** 0 shared-service-meldingen vanuit UWV/ABP/Belastingdienst
5. **Adoptie:** 100% van gemeenten met >= 100 ambtenaren gebruiken cao-gemeenten module

---

## Timeline & Phasing

**Phase 1 (Sprint 1-2):** Data model, salaristabel import, schaal/periodiek-selectie
**Phase 2 (Sprint 3):** IKB-opbouw en -opname, verlofrechten
**Phase 3 (Sprint 4):** Ziekteloondoorbetaling 100%/70%, ABP-aansluiting
**Phase 4 (Sprint 5):** BWGR-berekening, ontslag-exit, audit-trail
**Phase 5 (Sprint 6):** UI-raffinement, test-coverage, kennisoverdracht

---

## Constraints & Assumptions

**Constraints:**
- CAO Gemeenten moet één centrale bron zijn; geen parallelle configuratie
- Salaristabel-versies zijn immutable na activatie (audit-compliance)
- ABP-aansluiting mag nooit worden omzeild (juridisch vereist)

**Assumptions:**
- ABP UPA-API is beschikbaar en stabiel
- HR21-functiehuis is via API beschikbaar
- Gemeenten hebben bestaande payroll-engine-nl integratie

---

## Alternatives & Trade-offs

| Option | Pros | Cons |
|--------|------|------|
| Hardcoded CAO-regels in payroll-engine | Snelle implementatie | Geen toekomstige CAO-wijzigingen zonder code |
| Configureerbare regelset met generieke formula-engine | Flexibel, toekomstproof | Complexer, hogere QA-last |
| **Gekozen: Data-gestuurde tabellen + specialized micro-rules** | Balans flexibiliteit/complexiteit | Mid |

---

## Documentation & Training

- **For HR-adviseurs:** Quick-start guide nieuwe aanstelling (2 pagina's)
- **For salarisadministrateurs:** Maandelijkse loonrun checklist incl. CAO-wijzigingen
- **For controllers:** Audit-trail rapport-generator handleiding
- **For all:** CAO Gemeenten 2024-2026 reference samenvatting (VNG-bron)

---

## Sign-off

- **Proposed by:** Specter Intelligence
- **Created:** 2026-05-23
- **Assigned to:** [pending assignment]

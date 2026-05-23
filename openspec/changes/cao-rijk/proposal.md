---
status: draft
demand: enterprise
scope: application
---

# CAO Rijk — Proposal

## Summary

CAO Rijk is the collective labour agreement module for Dutch civil servants (Rijksambtenaren). It implements the full regulatory framework governing ~130,000 employees across 11 ministries and central agencies, encoding the structural peculiarities that distinguish Rijk employment: the Individueel Keuzebudget Rijk (IKB-Rijk) at 16.37%, mandatory ABP pension affiliation, FUWASYS function valuation, and bovenwettelijke Werkloosheidsregeling (BWR). The module powers P-Direkt and UBR (central HR shared services), feeds payroll-engine-nl monthly salary runs, and integrates with leave-administration, rostering, and contract-generation capabilities.

**Placement:** SETTING under Configuratie › CAO's & regelingen (per ADR-001, Rule 1).

## Features

### 1. BBRA Salary Table Lookup (Demand: 100)

Resolve gross monthly salary for any BBRA-schaal (1-18, including chief subscales 15a–18a) and salarisnummer (0-12 with documented extensions) combination, honouring effective-from dates of CAO-akkoorden and applying werktijdfactor correction.

**Stories:**
- HR-administrateur resolves schaal-11-trede-6 fulltime salary on peildatum 2026-01-15 → EUR 5,124.43
- Parttime 0.7-werktijdfactor employee schaal-9-trede-3 → proportionally reduced gross
- Invalid schaal 19 lookup → SchaalNotFoundException with valid list

### 2. IKB-Rijk Budget Calculation at 16.37% (Demand: 95)

Calculate annual Individueel Keuzebudget Rijk as 16.37% of salarissom (12 monthly BBRA + structural toelagen, excluding incidentele uitkeringen), configurable per CAO-akkoord with floor 16.37%.

**Stories:**
- Employee EUR 4,000/month salary + EUR 200/month TOD → annual IKB EUR 8,250.48
- Mid-year hire 2026-04-01 monthly EUR 3,800 → pro-rata IKB EUR 5,598.54
- IKB-spend on extra verlof → budget updated at uurloon-conversie-factor 1/156 per hour

### 3. FUWASYS Function Valuation & Schaal-Indicatie (Demand: 90)

Convert FUWASYS-puntenscore (nine deelscores: kennis, complexiteit, contacten, sturing, afbreukrisico, bezwarende werkomstandigheden, lichamelijke inspanning, oogvereisten) into salarisschaal-indicatie using FGR-handleiding conversion table.

**Stories:**
- FUWASYS-totaal 38 punten → schaal 11 (bandbreedte 36-40, no manager-discretion)
- Score exactly 40 (bandgrens) → schaal-range [11, 12] requiring documented motivatie
- Missing deelscore (e.g., bezwarende werkomstandigheden) → IncompleteFuwasysException

### 4. Mandatory ABP Affiliation & Pension Calculation (Demand: 95)

Enforce ABP-affiliation from first day of aanstelling. Calculate OP-pensioenpremie, AAOP, and ANW-hiaat using ABP-premiepercentages with employer/werknemer-aandeel split (currently 70/30 for OP).

**Stories:**
- New aanstelling 2026-06-01 EUR 4,500/month → ABP registered, OP-premie calculated at 24.7% total with 30% werknemer-aandeel
- Aanstelling without ABP-aansluiting → MissingAbpAffiliationException, persistence blocked
- Salary crosses ABP-franchise (EUR 17,545 in 2026) → only amount above franchise enters pensioengrondslag

### 5. Wachtgeld Entitlement (Legacy Ambtenaren) (Demand: 80)

Determine wachtgeld-aanspraak for employees with aanstelling before Wnra-conversie 2020-01-01, based on diensttijdjaren and age-at-termination, distinguishing leeftijdsgebonden (50+) and reguliere variants.

**Stories:**
- Employee born 1968-03-12, aangesteld 1995-06-01, ontslag 2026-09-30 reorganisatie → leeftijdsgebonden wachtgeld to AOW-leeftijd at 70% declining staffel
- Employee hired post-Wnra 2022-04-01, requests wachtgeld → NotEligibleForWachtgeld, refer to BWR
- Ontslag eigen-verzoek → zero wachtgeld regardless of diensttijd

### 6. Loondoorbetaling Bij Ziekte (Rijks-Regime) (Demand: 85)

Implement Rijks-loondoorbetalingsregeling: 100% in jaar 1, 70% in jaar 2, calculated over bezoldiging (salaris + structurele toelagen, IKB excluded).

**Stories:**
- Ziekmelding 2026-02-15, EUR 4,800/month bezoldiging → EUR 4,800 doorbetaling in jaar 1
- Same employee sick 2027-02-16 (jaar 2) → EUR 3,360 doorbetaling (70%), IKB continues at full grondslag
- Re-integratie tweede-spoor 40% loonwaarde 2027-05-01 → combined doorbetaling (70% + actual earnings)

### 7. BWR — Bovenwettelijke Werkloosheidsregeling Rijk (Demand: 92)

Determine BWR-aanspraak op aansluitende en aanvullende uitkering bovenop wettelijke WW, duration based on diensttijd-bij-Rijk and age-at-termination.

**Stories:**
- Employee 18 diensttijdjaren, aged 47, reorganisatie → aansluitende uitkering to equal diensttijdjaren duration + aanvullende for 6 months (78% total)
- Employee aged 60, 25 diensttijdjaren → aansluitende uitkering to AOW-leeftijd
- Employee aged 35, 4 diensttijdjaren → only aanvullende uitkering (6 maanden) because diensttijd below threshold

### 8. RVU-Reiskostenforfait & Reiskostenvergoeding (Demand: 75)

Calculate woon-werkverkeer reiskostenvergoeding using RVU-forfait-staffel for OV and kilometervergoeding for eigen-vervoer, with gerichte vrijstelling capped at EUR 0.23/km for 2026.

**Stories:**
- 28 km eigen-vervoer, 214 werkdagen/jaar → EUR 229.81/month (capped at EUR 0.23/km)
- Werkgever EUR 0.30/km vergoeding → EUR 0.07/km reported as belast loon
- OV-jaartrajectkaart EUR 2,400 → volledig onbelast under OV-vrijstelling

### 9. Detacheringsregels (Binnen & Buiten Rijk) (Demand: 78)

Distinguish detachering-binnen-Rijk (uitlenende dienst doorbetaling, inlener factuurt) from detachering-buiten-Rijk (ongewijzigde werkgever-relatie, extern werkplek) and apply correct premie/pension rules.

**Stories:**
- Interne detachering Ministerie A → B voor 1 jaar → salaris/IKB/ABP remain A, B receives doorbelasting on loonkosten + opslag
- Externe detachering niet-Rijks organisatie → ABP/IKB/BWR-opbouw continue on uitlenende dienst kosten
- Detachering zonder einddatum → OpenEindeDetacheringException, einddatum mandatory

### 10. Generieke vs. Sectorgebonden Functie Classification (Demand: 80)

Classify functievervulling as generiek (FGR-available to all ministeries) or sectorgebonden (DJI, Belastingdienst, KMar) and link sectorgebonden to aanvullende-cao-bepalingen.

**Stories:**
- "Senior beleidsmedewerker" FGR-functie → generiek, FGR-referentie 14.2
- "Penitentiair inrichtingswerker A" bij DJI → sectorgebonden with DJI-cao aanvullende bepalingen
- Conflict FGR-beleidsmedewerker claimed bij DJI piketdienst → FunctieClassificatieConflictException with herclassificatie recommendation

## User Stories

### HR-Administrateur Onboarding Flow
- **Given** new employee aanstelling is being registered
- **When** HR-admin enters schaal, salarisnummer, functie, ministerie, IKB-preference, ABP-aansluiting
- **Then** cao-rijk resolves salary, IKB-budget, pension-requirements and blocks persist if ABP missing

### Payroll-Engine Integration
- **Given** monthly salarisrun for a Rijks-werkgever
- **When** payroll-engine calls `resolveSalary(employmentId, peildatum)`
- **Then** cao-rijk returns fully decomposed bezoldigingsspecificatie with BBRA, toelagen, IKB, pension, reiskosten

### Leave-Administration Synchronization
- **Given** employee spends IKB on extra verlofuren
- **When** leave-administration calls `updateIkbBudget(employmentId, spentHours)`
- **Then** cao-rijk event `IkbBudgetUpdated` triggers verlofkaart refresh

### Wachtgeld/BWR Eligibility Check
- **Given** employee termination with reason reorganisatie
- **When** HR-admin requests eligibility for wachtgeld or BWR
- **Then** cao-rijk returns aanspraak based on diensttijd, leeftijd, ontslaggrond

## Customer Journeys

### Journey 1: New Civil Servant Appointment
1. **Trigger:** employee hired as Rijks-ambtenaar effective date D
2. **Pain point:** complex BBRA-schaal assignment; ABP-aansluiting non-negotiable; IKB-percentage varies per akkoord
3. **Resolution:** cao-rijk validates all fields, resolves salary/IKB/pension, emits `CaoRijkEmploymentCreated` to payroll/leave systems
4. **Outcome:** onboarding HR-admin has single source-of-truth for all arbeidsvoorwaarden

### Journey 2: Function Change Within Ministry
1. **Trigger:** employee transfers to different role (TAF or permanent)
2. **Pain point:** new FUWASYS-score, possibly different schaal; need to validate prior salary continuity
3. **Resolution:** cao-rijk classifies new functievervulling as generiek/sectorgebonden, checks schaal-indicatie, emits `FunctieClassificationChanged` to rostering
4. **Outcome:** rooster-planning knows if DJI-piketinroostering applies

### Journey 3: Wachtgeld/BWR Claim After Termination
1. **Trigger:** Rijks-ambtenaar terminated with reorganisatie reason
2. **Pain point:** complex diensttijd + age + akkoord-date interactions for correct entitlement
3. **Resolution:** cao-rijk calculates aanspraak (wachtgeld if pre-2020, else BWR), emits `WachtgeldEntitlementCalculated` to UitkeringService
4. **Outcome:** HR-admin sees clear aanspraak-duration with reason documented

## Stakeholders

### HR-Administrateur (P-Direkt / Ministerieel HR)
- **Responsibilities:** register aanstellingen, assign schalen, process IKB-bestedingen, manage mutaties
- **Goals:** reduce data-entry errors, have single source-of-truth for arbeidsvoorwaarden, automate BBRA lookups
- **Pain points:** manually checking BBRA-tabellen, error-prone schaal-assignment, duplicate ABP-aansluiting requests

### Payroll-Controller (Salarissen)
- **Responsibilities:** validate monthly salarisrun, issue loonstroken, process corrections
- **Goals:** ensure correct salaris per employee per BBRA-schaal, audit trail for compliance, rapid correction of overpayments
- **Pain points:** mismatches between cao-registratie and loonstrook, historical data for terugvorderingen

### Leidinggevende (Manager)
- **Responsibilities:** approve IKB-bestedingsverzoeken, register TAF-besluiten
- **Goals:** control IKB-budget, respect arbeidsmarktpositie of team-leden
- **Pain points:** opaque IKB-regels, unclear what is permissible binnen Rijks-kaders

### CAO-Beleidsmedewerker bij BZK
- **Responsibilities:** maintain BBRA-tabellen, IKB-percentages, BWR-staffels post-akkoord
- **Goals:** ensure new CAO-rules reflected in system quickly, audit-trail for compliance
- **Pain points:** complex rule-versioning, need coordination with multiple Rijks-werkgevers

### Auditors (ADR, Algemene Rekenkamer)
- **Responsibilities:** compliance-rapportages, rechtmatigheid-audits
- **Goals:** verify correct FUWASYS-classificatie, validate wachtgeld/BWR-berekeningen, trace audit-trail
- **Pain points:** scattered data across HR-systems, incomplete historisering

### Medewerker (Self-Service)
- **Responsibilities:** view loonstrook, request IKB-besteding, submit verlofverzoeken
- **Goals:** understand salaris breakdown, manage work-life balance via verlof/IKB
- **Pain points:** opaque toelagen, unclear how IKB-budget is calculated (but does not interact directly with cao-rijk—via hrmq-frontend wrapper)

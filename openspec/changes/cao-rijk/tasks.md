---
status: draft
---

# CAO Rijk — Implementation Tasks

## Data Layer

- [ ] **Create OpenRegister schemas for cao-rijk entities**
  - [ ] Generate `CaoRijkEmployment` schema (extends Employment with rijks-specific fields)
  - [ ] Generate `FuwasysScore` schema (nine deelscores + totaalscore + schaalIndicatie)
  - [ ] Generate `IkbBudget` schema (budget tracking with lineItems array)
  - [ ] Generate `BwrEntitlement` schema (aansluitende + aanvullende uitkering calculations)
  - [ ] Generate `WachtgeldEntitlement` schema (legacy pre-Wnra entitlements)
  - [ ] Generate `DetacheringsBesluit` schema (binnen-Rijk and buiten-Rijk variants)
  - [ ] Generate `BbraSalarisTabel` schema (immutable reference data per CAO-akkoord)
  - [ ] Generate `FgrFunctiefamilie` schema (53 families with schaal-bandbreedte)
  - [ ] Generate `AbpPremietabel` schema (annual ABP-premiepercentages + franchise)
  - [ ] Register all schemas in `lib/Settings/cao-rijk_register.json` with `x-openregister.type: "application"`

- [ ] **Seed data in cao-rijk_register.json**
  - [ ] Add 3 CaoRijkEmployment objects (generiek beleidsadviseur, sectorgebonden DJI-bewaarder, sectorgebonden Belastingdienst-inspecteur)
  - [ ] Add 2 FuwasysScore objects (one with complete scores, one with missing deelscore for validation test)
  - [ ] Add 2 IkbBudget objects (full-year and pro-rata mid-year hire)
  - [ ] Add 2 BbraSalarisTabel objects (schaal-11-trede-6 and schaal-9-trede-3 for 2025-akkoord)
  - [ ] Add 1 AbpPremietabel object (2026 rates with franchise)
  - [ ] Mark register with `@self` envelope for idempotent import
  - [ ] Verify mock data uses realistic Dutch values (street names, postcodes 1000–9999, valid dates)

- [ ] **Database migration for cao-rijk tables (if using direct DB backing)**
  - [ ] Add cao_rijk_employments table with all fields from CaoRijkEmployment schema
  - [ ] Add indexes: (employmentId), (ministerie, dienstonderdeel), (functieClassificatie), (effectiveFrom)
  - [ ] Add cao_rijk_fuwasys_scores table with foreign key to cao_rijk_employments
  - [ ] Add cao_rijk_ikb_budgets table with composite key (caoRijkEmploymentId, ikbJaar)
  - [ ] Add cao_rijk_bwr_entitlements, cao_rijk_wachtgeld_entitlements tables
  - [ ] Add cao_rijk_detacherings_besluiten table with date-range validation constraint
  - [ ] Create reference tables: bbra_salarissen, fgr_functiefamilies, abp_premies
  - [ ] Add audit_trail column to track schema-version on all mutable tables

- [ ] **OpenRegister configuration service**
  - [ ] Implement `CaoRijkConfigurationService` extending `ConfigurationService`
  - [ ] Method `importBbraSalarissen(data, version, force)` with version_compare for idempotency
  - [ ] Method `importFgrFunctiefamilies(data, version, force)`
  - [ ] Method `importAbpPremietabel(data, version, force)`
  - [ ] Call from SettingsLoadService on app install

---

## Backend Business Logic

### REQ-001: BBRA Salary Lookup

- [ ] **SalaryService**
  - [ ] Implement `resolveSalary(employmentId: UUID, peildatum: LocalDate): Money`
  - [ ] Load CaoRijkEmployment, resolve applicable BbraSalarisTabel by peildatum and schaal+salarisnummer
  - [ ] Apply werktijdfactor correction: `brutoMaandSalaris × werktijdfactor`
  - [ ] Round to nearest cent (half-to-even)
  - [ ] Throw `SchaalNotFoundException` if schaal not in BBRA
  - [ ] Log salary resolution in audit-trail with peildatum snapshot

- [ ] **BbraSalarisTabel management**
  - [ ] Implement `findBySchaalAndSalarisnummerAndPeildatum(schaal: string, salarisnummer: int, peildatum: LocalDate): BbraSalarisTabel`
  - [ ] Implement `findValidSchalen(): [string]` returning [1, 2, ..., 18, 15a, 16a, 17a, 18a]
  - [ ] Handle effective-from date filtering (select BBRA-akkoord active on peildatum)
  - [ ] Seed BBRA-2025-akkoord and BBRA-2024-akkoord tables for test

### REQ-002: IKB-Rijk Budget Calculation

- [ ] **IkbBudgetService**
  - [ ] Implement `calculateAnnualIkbBudget(employmentId: UUID, ikbJaar: int): Money`
  - [ ] Fetch CaoRijkEmployment and collect 12 monthly BBRA-salarissen (via `SalaryService.resolveSalary(peildatum)` for each month)
  - [ ] Load structural toelagen (TOD, garantietoelage, etc.) from employment record
  - [ ] Sum monthly salarissen + toelagen: `salarissom = 12 × salaris + toelagen`
  - [ ] Apply IKB-percentage (configurable, default 16.37%): `budget = salarissom × 0.1637`
  - [ ] Round to nearest cent (half-to-even)
  - [ ] Handle pro-rata for mid-year hire: `factor = months_in_year / 12`, apply to salarissom
  - [ ] Create IkbBudget record with totalBudget, spentBudget=0, remainingBudget=totalBudget
  - [ ] Emit `IkbBudgetCalculated` event

- [ ] **IkbBudgetTransaction handling**
  - [ ] Implement `recordIkbSpend(employmentId: UUID, type: enum, amount: Money, spentAt: Timestamp): IkbBudget`
  - [ ] Load current IkbBudget for the ikbJaar
  - [ ] Validate `amount <= remainingBudget`, throw `InsufficientIkbBudgetException` if violated
  - [ ] Add lineItem to IkbBudget.lineItems
  - [ ] Update spentBudget and remainingBudget
  - [ ] Persist and emit `IkbBudgetUpdated` event (consumed by leave-administration)
  - [ ] Implement uurloon-conversie for verlof-spend: `uurkosten = monthly_salary / 156 hours per 36-hour normweek`

- [ ] **IKB-percentage configuration**
  - [ ] Add IKB-percentage field to CAO-configuration setting
  - [ ] Implement `getIkbPercentageForDate(peildatum: LocalDate): decimal` returning configured % or 16.37 default
  - [ ] Allow percentage updates via CAO-akkoord admin UI (CAO-beleidsmedewerker)
  - [ ] Create audit entry when percentage changes

### REQ-003: FUWASYS Function Valuation

- [ ] **FuwasysScoreService**
  - [ ] Implement `calculateTotaalscore(kennis, complexiteit, contacten, sturing, afbreukrisico, bezwarendePunten, lichamelijkeInspanning, oogvereisten): int`
  - [ ] Sum eight deelscores (each validated range)
  - [ ] Implement `validateFuwasysScore(fuwasysScore: FuwasysScore): ValidationResult`
  - [ ] Check all eight deelscores present and > 0 (mandatory), throw `IncompleteFuwasysException` if any missing
  - [ ] Load FGR-conversietabel from reference data

- [ ] **Schaal-Indicatie resolution**
  - [ ] Implement `resolveSchaalIndicatie(totaalscore: int): SchaalIndicatieResult`
  - [ ] Load FGR-handleiding conversietabel (embedded in code or reference data)
  - [ ] Look up totaalscore → (schaal or schaal-range)
  - [ ] If `result.type == "single"`, return schaal directly
  - [ ] If `result.type == "bandgrens"`, return [schaal1, schaal2] and require managerMotivatie
  - [ ] Persist FuwasysScore record with schaalIndicatie
  - [ ] If bandgrens and managerMotivatie is null, emit `FuwasysValidationWarning` (blocks payroll-finalisatie until resolved)

- [ ] **FGR-conversietabel seed data**
  - [ ] Embed or load from config the FGR-handleiding conversietabel (e.g., FGR-score-ranges.json)
  - [ ] Test data: score 38 → schaal 11, score 40 → [11, 12] (bandgrens), score 42 → schaal 12

### REQ-004: Mandatory ABP Affiliation

- [ ] **ABP-AffiliationService**
  - [ ] Implement `validateAbpAffiliation(caoRijkEmployment: CaoRijkEmployment): ValidationResult`
  - [ ] Check that `caoRijkEmployment.abpAffiliationId` is not null and is active from `aanvangsdatum-overheidsdienst`
  - [ ] Throw `MissingAbpAffiliationException` if missing or inactive, prevent persistence of employment

- [ ] **Pension-Premie calculation**
  - [ ] Implement `calculatePensionPremie(salaris: Money, peildatum: LocalDate): PensionDeduction`
  - [ ] Load AbpPremietabel effective on peildatum
  - [ ] Calculate pensioengrondslag: `grondslag = max(0, salaris − franchise)`
  - [ ] Apply OP-premie: `opPremie = grondslag × 0.247`
  - [ ] Split employer/employee: `opEmployer = opPremie × 0.70, opEmployee = opPremie × 0.30`
  - [ ] Calculate AAOP-premie: `aaopPremie = grondslag × 0.0215` (total, split per CAO-akkoord)
  - [ ] Calculate ANW-hiaat: `anwPremie = grondslag × 0.0098`
  - [ ] Return decomposed PensionDeduction with all three components
  - [ ] Log franchise-crossing in audit-trail

- [ ] **ABP-Aansluiting integration**
  - [ ] Call `AbpAansluitingService.ensureAffiliation(employeeId, inceptionDate)` on CaoRijkEmployment creation
  - [ ] This service (owned by `abp-aansluiting-verplicht` capability) registers employee with ABP via A&O Services
  - [ ] cao-rijk does not perform actual ABP registration, only declares the requirement and validates

### REQ-005: Wachtgeld Entitlement

- [ ] **WachtgeldEligibilityService**
  - [ ] Implement `determineWachtgeldEligibility(employmentId: UUID, terminationDate: LocalDate, terminationReason: enum): WachtgeldEligibilityResult`
  - [ ] Check `aanstellingsdatum < 2020-01-01` (pre-Wnra-conversie requirement)
  - [ ] If post-Wnra, return `NotEligibleForWachtgeld(reason: "aanstelling-na-wnra-conversie")`
  - [ ] If `terminationReason == "eigen-verzoek"`, return `NotEligibleForWachtgeld(reason: "eigen-verzoek")`
  - [ ] Calculate `diensttijdjaren` from aanstellingsdatum to terminationDate
  - [ ] Calculate `leeftijd` on terminationDate

- [ ] **Wachtgeld-calculation (leeftijdsgebonden vs. reguliere)**
  - [ ] If `leeftijd >= 50 && diensttijdjaren >= threshold`, return leeftijdsgebonden variant
  - [ ] Calculate duration: `months_to_aow = (aow_leeftijd − leeftijd) × 12` (approx.)
  - [ ] Return `WachtgeldEntitlement(type: "leeftijdsgebonden", duration: months_to_aow, percentage: 70%, staffel: declining_per_year)`
  - [ ] Else, return reguliere variant with fixed duration (e.g., 12 months at 70%)
  - [ ] Store WachtgeldEntitlement record
  - [ ] Emit `WachtgeldEntitlementCalculated` event

- [ ] **AOW-age lookup**
  - [ ] Implement `getAowAge(birthDate: LocalDate): int` (roughly 67.33 for births after 1970)
  - [ ] Use official AOW-age table from Dutch government

### REQ-006: Loondoorbetaling Bij Ziekte

- [ ] **SickLeaveService**
  - [ ] Implement `initiateSickLeave(employmentId: UUID, ziekmelddingDate: LocalDate): SickLeaveRecord`
  - [ ] Record ziekmelding with inceptionDate
  - [ ] Load current bezoldiging (salaris + structurele toelagen, excluding IKB) as basis
  - [ ] Emit `SickLeaveInitiated` event

- [ ] **Loondoorbetaling-percentage resolution**
  - [ ] Implement `getLoondoorbetalingPercentage(sickLeaveRecord: SickLeaveRecord, currentDate: LocalDate): decimal`
  - [ ] Calculate months since ziekmelding
  - [ ] If months <= 12 (jaar 1), return 100% of bezoldiging
  - [ ] If months > 12 (jaar 2+), return 70% of bezoldiging
  - [ ] Handle twee-spoor re-integration: if loonwaardePercentage is set, apply: `doorbetaling = (bezoldiging − loonwaarde) × 0.70 + loonwaarde`
  - [ ] IKB-opbouw continues at full bezoldiging-grondslag (not reduced in jaar 2)

- [ ] **Twee-spoor-traject tracking**
  - [ ] Add fields to SickLeaveRecord: `tweeSpoorStartDate`, `loonwaardePercentage`, `returnToWorkDate`
  - [ ] Implement status transitions: SickLeave → TweeSpoor (re-integration) → ReturnToWork
  - [ ] On returnToWorkDate, revert doorbetaling to normal salary (if recovered within jaar 2)

### REQ-007: BWR — Bovenwettelijke Werkloosheidsregeling

- [ ] **BwrEligibilityService**
  - [ ] Implement `determineBwrEligibility(employmentId: UUID, terminationDate: LocalDate, terminationReason: enum): BwrEligibilityResult`
  - [ ] Check `aanstellingsdatum >= 2020-01-01` (post-Wnra requirement)
  - [ ] If pre-Wnra, return reference to wachtgeld-regeling (REQ-005)
  - [ ] If `terminationReason == "eigen-verzoek"`, return `NotEligibleForBwr(reason: "eigen-verzoek")`
  - [ ] Calculate `diensttijdjaren` and `leeftijd-at-termination`

- [ ] **BWR-calculation (aansluitende + aanvullende)**
  - [ ] Implement `calculateBwrAnspraak(diensttijdjaren: decimal, leeftijd: int): BwrEntitlement`
  - [ ] Load BWR-staffel from CAO-configuration
  - [ ] Determine aansluitende-duration: if `diensttijdjaren >= threshold && leeftijd < 60`, months = `diensttijdjaren × 12`
  - [ ] If `leeftijd >= 60`, aansluitende extends to AOW-leeftijd
  - [ ] Determine aanvullende-duration: always 6 months (or per staffel)
  - [ ] Calculate totaaluitkering-percentage: typically 78% for first 6 months (aanvullende), then staffel-percentage
  - [ ] Store BwrEntitlement record
  - [ ] Emit `BwrEntitlementCalculated` event

- [ ] **WW-integration placeholder**
  - [ ] Document expected interface with `WerkloosheidsuitkeringService` (statutory WW)
  - [ ] BWR-aansluitende starts after WW-uitputting
  - [ ] cao-rijk does not own WW-calculation; it owns BWR-supplements only

### REQ-008: RVU-Reiskostenforfait

- [ ] **ReiskostenService**
  - [ ] Implement `calculateReiskostenvergoeding(employmentId: UUID, peildatum: LocalDate): ReiskostenResult`
  - [ ] Load employee's commute-profile (woon-werk-afstand, vervoermiddel: eigen-vervoer | OV)

- [ ] **Eigen-vervoer berekening**
  - [ ] Implement `calculateEigenVervoer(dailyDistanceKm: decimal, yearlyWorkdays: int, gerichte_vrijstelling_rate: decimal): Money`
  - [ ] Formula: `(afstand × 2 × werkdagen / 12 maanden) × rate` (capped at EUR 0.23/km for 2026)
  - [ ] Apply gerichte-vrijstelling cap: `amount = min(computed, afstand × 2 × werkdagen / 12 × 0.23)`
  - [ ] Bovenmatige deel: if werkgever-vergoeding > EUR 0.23/km, difference is taxed as loon
  - [ ] Log in loonstrook as separate regel: "reiskostenvergoeding" (onbelast) + "reiskosten bovenmatig deel" (belast)

- [ ] **OV-vergoeding berekening**
  - [ ] Implement `calculateOvVergoeding(ovJaarpas: Money): Money`
  - [ ] Return OV-jaarpas amount as volledig-onbelast (no split into taxed portion)
  - [ ] Apply OV-vrijstelling (Article 18b Wet LB 2001)

- [ ] **RVU-forfait-staffel configuration**
  - [ ] Load RVU-forfait table from CAO-configuration (per peildatum)
  - [ ] Implement `getRvuForfaitRateForDate(peildatum: LocalDate): decimal` (EUR 0.23/km for 2026)
  - [ ] Support version-changes per CAO-akkoord

### REQ-009: Detacheringsregels

- [ ] **DetacheringsbesluItService**
  - [ ] Implement `createDetacheringsbesluit(caoRijkEmploymentId: UUID, type: enum(binnen-rijks | buiten-rijks), startdatum, einddatum, ...): DetacheringsBesluit`
  - [ ] Validate `einddatum > startdatum && einddatum is not null`, throw `OpenEindeDetacheringException` if missing
  - [ ] Check no overlapping detacheringsbesluiten for same employee, throw `OverlappingDetacheringException` if found
  - [ ] Persist DetacheringsBesluit
  - [ ] Emit `DetacheringBegunning` and schedule `DetacheringEindigung` event for einddatum

- [ ] **Bin within-Rijk doorbelasting**
  - [ ] If `type == "binnen-rijks"`, implement `calculateDoorbelasting(loonkosten: Money, opslag: decimal): Money`
  - [ ] Formula: `doorbelasting = loonkosten + (loonkosten × opslag%)`
  - [ ] Invoice inlener dienst for doorbelasting
  - [ ] Salary/IKB/pension continue on uitlenende dienst

- [ ] **Buiten-Rijk pension/benefits continuation**
  - [ ] If `type == "buiten-rijks"`, ensure ABP-opbouw, IKB-opbouw, BWR-rights continue at uitlenende dienst cost
  - [ ] External org pays daadwerkelijke werknemerkosten (salaris only)
  - [ ] Emit `DetacheringBuitenRijks` event to notify ABP/IKB systems to continue accrual

- [ ] **Detachering-end handling**
  - [ ] On einddatum, restore employee to original ministry/dienst
  - [ ] Emit `DetacheringEindigung` event
  - [ ] Verify no gaps in salary/pension/IKB-opbouw

### REQ-010: Generieke vs. Sectorgebonden Functie

- [ ] **FunctieClassificationService**
  - [ ] Implement `classifyFunctie(functieNaam: string, fgrFamilieCode: int, ministerie: string, dienstonderdeel: string): FunctieClassificationResult`
  - [ ] Load FGR-functiefamilie by code
  - [ ] If `functieFamilie.sectorgebonden == false`, return `{ classificatie: "generiek", fgrReference: "14.2" }`
  - [ ] Else, determine sector from dienstonderdeel (DJI, Belastingdienst, KMar, etc.)
  - [ ] If FGR-family is sector-specific and dienstonderdeel matches, return `{ classificatie: "sectorgebonden", cao: "cao-dji" }`
  - [ ] If conflict (e.g., generiek functie claimed bij DJI-piketdienst), throw `FunctieClassificatieConflictException` with recommendation

- [ ] **Sector-CAO linking**
  - [ ] Implement mapping: dienstonderdeel → sectorgebonden-cao (e.g., "DJI" → "cao-dji")
  - [ ] Load applicable aanvullende bepalingen from sector-specific cao module (cao-dji, cao-belastingdienst, cao-kmar)
  - [ ] Store `sectorgebonden-cao` field on CaoRijkEmployment
  - [ ] Emit `FunctieClassificationChanged` event on classification change

- [ ] **Functie-change handling**
  - [ ] On functievervulling change, recalculate schaal-indicatie via FUWASYS (REQ-003)
  - [ ] Notify rostering-planning via event if sectorgebonden-status changes (may affect piket-eligibility)
  - [ ] Validate new functie is compatible with current dienstonderdeel (or allow assignment conflict → manual review)

---

## API & Integration

- [ ] **REST API endpoints**
  - [ ] `GET /api/cao-rijk/employments/{employmentId}/salary?peildatum=2026-01-01` → SalaryResponse (from REQ-001)
  - [ ] `GET /api/cao-rijk/employments/{employmentId}/ikb-budget?ikbJaar=2026` → IkbBudgetResponse
  - [ ] `POST /api/cao-rijk/fuwasys-scores` → FuwasysScoreResponse (store FUWASYS-score)
  - [ ] `GET /api/cao-rijk/schaal-indicatie?totaalPunten=42` → SchaalIndicatieResponse
  - [ ] `GET /api/cao-rijk/employments/{employmentId}/pension-premie?peildatum=2026-01-01` → PensionResponse
  - [ ] `POST /api/cao-rijk/employments/{employmentId}/sick-leave` → SickLeaveResponse
  - [ ] `GET /api/cao-rijk/employments/{employmentId}/bwr-eligibility` → BwrEligibilityResponse
  - [ ] `POST /api/cao-rijk/detacherings-besluiten` → DetacheringBesluitResponse
  - [ ] All endpoints return 422 with `ValidationException` details if input invalid

- [ ] **Event publishers**
  - [ ] Emit `CaoRijkEmploymentCreated` on new CaoRijkEmployment
  - [ ] Emit `CaoRijkEmploymentModified` on schaal/functie/werktijdfactor change
  - [ ] Emit `IkbBudgetUpdated` on spend transaction
  - [ ] Emit `FunctieClassificationChanged` on sectorgebonden-status change
  - [ ] Emit `WachtgeldEntitlementCalculated` on wachtgeld-claim
  - [ ] Emit `BwrEntitlementCalculated` on BWR-claim
  - [ ] Implement in `CaoRijkEventPublisher` (use platform-events or Nextcloud event system)

- [ ] **Event subscribers (outbound)**
  - [ ] Subscribe to `Employment.Created` (hrmq-core) → auto-create CaoRijkEmployment stub
  - [ ] Subscribe to `CaoAkkoordUpdated` (CAO-admin) → refresh BBRA/FGR/ABP reference data

- [ ] **Consumed by other systems**
  - [ ] payroll-engine-nl calls `resolveSalary(employmentId, peildatum)` before monthly salarisrun
  - [ ] leave-administration subscribes to `IkbBudgetUpdated` events
  - [ ] rostering-planning subscribes to `FunctieClassificationChanged` events
  - [ ] contract-generation calls cao-rijk API for arbeidsovereenkomst generation

---

## Frontend

- [ ] **CaoRijkEmployment form** (hrmq-frontend wrapper)
  - [ ] Form fields: schaal, salarisnummer, functiefamilie, functietypering, ministerie, dienstonderdeel, aanvangsdatum-overheidsdienst, aanvangsdatum-huidge-functie, werktijdfactor
  - [ ] Schaal dropdown: populated from valid-schalen (REQ-001)
  - [ ] Validate salarisnummer range: 0-12 with UI note on legacy extensions
  - [ ] Werktijdfactor: decimal input 0.0–1.0
  - [ ] Read-only: functieClassificatie (computed by backend on functie-selection)
  - [ ] Read-only: IKB-budget summary (calculated, not editable)
  - [ ] Trigger backend FUWASYS validation on form save (to set schaal-indicatie)

- [ ] **IKB-Spend transaction UI** (within leave-administration app, triggers cao-rijk API)
  - [ ] Form: transaction-type (vakantietoelage | eindejaarsuitkering | levensloopbijdrage | bovenwettelijke-verlof)
  - [ ] Amount input: currency EUR, validation >= 0
  - [ ] Display remaining-budget after transaction
  - [ ] Error message if insufficient-budget

- [ ] **FUWASYS-score entry form** (hrmq-frontend wrapper)
  - [ ] Nine deelscore input fields (ranges validated per deelscore type)
  - [ ] Computed totaalscore displayed read-only
  - [ ] Schaal-indicatie display (single schaal or bandgrens [11, 12])
  - [ ] If bandgrens, show text-area for managerMotivatie (required before submit)
  - [ ] Warning alert if validation fails (missing deelscore)

- [ ] **Detacherings-besluit form**
  - [ ] Type dropdown: binnen-rijks | buiten-rijks
  - [ ] Start-date, end-date picker (validate end > start)
  - [ ] Uitlenende/Inlener ministry dropdowns
  - [ ] Opslag % input (for binnen-rijks doorbelasting)
  - [ ] Error if no end-date provided

- [ ] **Wachtgeld/BWR eligibility check** (HR-admin triggered)
  - [ ] Search employee by name/ID
  - [ ] Display: aanstellingsdatum, diensttijdjaren, leeftijd-at-termination, ontslaggrond, termination-date
  - [ ] Show result: eligible (wachtgeld | BWR) with duration and percentage, or not-eligible with reason
  - [ ] Link to claim-processing workflow (delegate to UitkeringService)

---

## Testing

- [ ] **Unit tests: SalaryService**
  - [ ] Test REQ-001 scenarios: standard fulltime, parttime 0.7, invalid schaal, chief-subscale, CAO-akkoord boundary, legacy extension
  - [ ] Test rounding (half-to-even) with edge cases
  - [ ] Mock BbraSalarisTabel lookups

- [ ] **Unit tests: IkbBudgetService**
  - [ ] Test REQ-002: standard annual, pro-rata mid-year, spend deduction, insufficient-budget rejection, exclusion of incidentele
  - [ ] Test uurloon-conversie for verlofspan
  - [ ] Mock SalaryService.resolveSalary for 12 monthly calls

- [ ] **Unit tests: FuwasysScoreService**
  - [ ] Test REQ-003: uniek-bandbreedte (single schaal), bandgrens (range), missing deelscore exception
  - [ ] Mock FGR-conversietabel lookups

- [ ] **Unit tests: AbpPensionService**
  - [ ] Test REQ-004: standard OP-premie with 70/30 split, franchise-crossing, AAOP+ANW
  - [ ] Test missing-affiliation exception
  - [ ] Mock AbpPremietabel lookups

- [ ] **Unit tests: WachtgeldEligibilityService**
  - [ ] Test REQ-005: pre-Wnra eligible (leeftijdsgebonden), post-Wnra not-eligible, eigen-verzoek not-eligible
  - [ ] Test duration-to-AOW calculation
  - [ ] Mock AOW-age lookup

- [ ] **Unit tests: SickLeaveService**
  - [ ] Test REQ-006: jaar-1 100%, jaar-2 70%, twee-spoor re-integration with loonwaarde
  - [ ] Test IKB-opbouw continues at full basis during jaar-2 reduced doorbetaling

- [ ] **Unit tests: BwrService**
  - [ ] Test REQ-007: aansluitende + aanvullende calculation for age/diensttijd combinations
  - [ ] Test eigen-verzoek not-eligible

- [ ] **Unit tests: ReiskostenService**
  - [ ] Test REQ-008: eigen-vervoer EUR 0.23/km cap, bovenmatige deel taxed, OV-jaarpas onbelast
  - [ ] Test pro-rata for partial-year hire
  - [ ] Mock RVU-forfait-staffel

- [ ] **Unit tests: DetacheringService**
  - [ ] Test REQ-009: binnen-Rijk doorbelasting, buiten-Rijk pension continuation
  - [ ] Test open-ende exception, overlapping exception
  - [ ] Mock event emissions

- [ ] **Unit tests: FunctieClassificationService**
  - [ ] Test REQ-010: generiek FGR-function, sectorgebonden DJI/Belastingdienst/KMar
  - [ ] Test conflict exception (generiek function claimed bij sector)
  - [ ] Mock FGR-family lookups

- [ ] **Integration tests**
  - [ ] Full CaoRijkEmployment creation flow: new employment → auto-create cao-rijk-specific record → emit event
  - [ ] Payroll-engine-nl calls `resolveSalary()` → receives correct salary decomposition
  - [ ] Leave-admin subscribes to `IkbBudgetUpdated` → verlofkaart updates
  - [ ] Rostering-planning subscribes to `FunctieClassificationChanged` → piketinroostering enabled/disabled
  - [ ] Detachering-begin/end events trigger salary/pension continuity checks

- [ ] **Seed data verification**
  - [ ] Verify all seed objects in cao-rijk_register.json use realistic Dutch values
  - [ ] Test idempotent import: re-import with `force: false` should not create duplicates
  - [ ] Verify cross-register consistency (e.g., BbraSalarisTabel references match CaoRijkEmployment schaal)

---

## Deduplication Check

- [ ] **Verify no overlap with cao-gemeenten, cao-onderwijs-po, cao-onderwijs-vo, cao-ziekenhuizen, cao-zorg-vvt**
  - [ ] Each sibling CAO-module owns its own entity definitions (no shared CaoEmployment base)
  - [ ] Shared: Money, LocalDate, Employment aggregate (from hrmq-core)
  - [ ] Shared: OpenRegister infrastructure, ObjectService, ConfigurationService (platform-provided)
  - [ ] No custom Entity/Mapper for cao-rijk data (use OpenRegister only) — confirms no duplication of platform patterns

- [ ] **Verify reuse of platform services**
  - [ ] ObjectService for CRUD (provided, not rebuilt)
  - [ ] ConfigurationService for import/export (provided, extended by CaoRijkConfigurationService)
  - [ ] Money value-objects (shared, not duplicated)
  - [ ] LocalDate for date-handling (shared, not duplicated)
  - [ ] Document in design.md "Reuse Analysis" section (completed in design.md)

- [ ] **Findings**: NO OVERLAP FOUND. cao-rijk is a new, greenfield capability distinct from all sibling CAO-modules. All service reuse documented. Recommendation: APPROVE.

---

## Documentation

- [ ] **ADR for cao-rijk data model** (if new ADR scope arises)
  - [ ] Placeholder: reference ADR-001-data-layer.md for OpenRegister pattern

- [ ] **CAO-Rijk IA Mapping** (for information-architecture compliance)
  - [ ] Mapping table: cao-rijk → SETTING under Configuratie › CAO's & regelingen
  - [ ] Reference ADR-001 (Information Architecture)

- [ ] **API documentation** (OpenAPI 3.0 spec)
  - [ ] Document all 8 REST endpoints (salary lookup, IKB-budget, FUWASYS, pension, sick-leave, wachtgeld/BWR, detachering)
  - [ ] Include example requests/responses
  - [ ] Note error codes and exceptions

- [ ] **Integration guide** (for payroll-engine-nl, leave-admin, rostering, contract-generation teams)**
  - [ ] How to call cao-rijk API for salary resolution
  - [ ] How to subscribe to cao-rijk events
  - [ ] Example event payloads

---

## Compliance & Quality Gates

- [ ] **Hydra gate: forbidden-patterns**
  - [ ] Scan code for unauthorized patterns (e.g., `catch (\Throwable) { return null; }` in auth resolvers — OWASP A01:2021)
  - [ ] cao-rijk must pass gate

- [ ] **Hydra gate: spdx-headers**
  - [ ] Every PHP file under lib/ must have @license + @copyright PHPDoc tags
  - [ ] cao-rijk must pass gate

- [ ] **Hydra gate: schema-standards**
  - [ ] All entity schemas PascalCase, schema.org vocabulary, explicit types + required flags
  - [ ] No invented Dutch field-names when schema.org equivalent exists (use mapping layer)
  - [ ] cao-rijk must pass gate

- [ ] **Code review checklist**
  - [ ] All REQ-XXX-NNN scenarios from specs.md have passing test cases
  - [ ] All GIVEN/WHEN/THEN scenarios in specs.md match implementation
  - [ ] Seed data is realistic (Dutch street-names, valid postcodes, correct dates)
  - [ ] All events emitted and consumed documented
  - [ ] No hardcoded magic numbers (percentages, franchise-amounts configurable via CAO-admin UI)
  - [ ] All date-fields use LocalDate (no Timestamp for civil-calendar dates)
  - [ ] All money-fields use Money value-objects with EUR currency
  - [ ] Rounding: all monetary operations use round-half-to-even

---

## Handoff & Deployment

- [ ] **Merge to development branch**
  - [ ] All 4 artifacts complete (proposal.md, design.md, specs.md, tasks.md)
  - [ ] All tests passing
  - [ ] Code review approved
  - [ ] Hydra gates passing

- [ ] **Deployment to staging**
  - [ ] Database migrations applied
  - [ ] OpenRegister schemas imported
  - [ ] Seed data loaded
  - [ ] Event subscriptions activated
  - [ ] Smoke tests: salary lookup, IKB-budget calculation, FUWASYS validation

- [ ] **Documentation handoff**
  - [ ] Send integration guide to payroll-engine-nl, leave-admin, rostering, contract-generation teams
  - [ ] Schedule onboarding call with P-Direkt / UBR HR-admin users
  - [ ] CAO-beleidsmedewerker (BZK) trained on CAO-configuration UI (BBRA-tables, IKB-percentage updates, etc.)

- [ ] **Go-live**
  - [ ] CAO-Rijk ruleset active in production
  - [ ] P-Direkt hrmq tenants can register Rijks-ambtenaren with full arbeidsvoorwaarden
  - [ ] Payroll-engine-nl monthly salarissen resolve correctly via cao-rijk API

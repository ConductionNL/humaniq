---
status: proposed
---

# Tasks: CAO Ziekenhuizen

## Data Model & Persistence Layer

- [ ] Define `CaoZiekenhuisEmployment` entity extending `Employment` in the domain model
  - [ ] Add FWG attributes (functiegroep, salarisschaal, salarisnummer, functiebenaming)
  - [ ] Add service assignment (dienstverband enum)
  - [ ] Add part-time factor (parttimePercentage decimal)
  - [ ] Add on-call availability flag (inroosterbaarVoorPiket)
  - [ ] Add availability hourly rate (bereidheidsuurtarief Money, optional)
  - [ ] Map to persistent storage with ancienniteit calculation logic
  
- [ ] Define `FwgScoreReport` entity for FWG 3.0 scoring
  - [ ] Add 9 sub-score fields (kennis, zelfstandigheid, social, risico/verantwoordelijkheid/invloed, expressie, beweging, oplettendheid, overige, inconveniënten)
  - [ ] Add totaalscore and derived functiegroep fields
  - [ ] Add validation-status enum (valid, incomplete, mismatch, pending_review)
  - [ ] Implement immutable-score recording (scores are audit-logged once finalized)

- [ ] Define `OrtClaim` entity for shift allowances
  - [ ] Add work-date and time-range fields (startTime, endTime as ZonedDateTime Europe/Amsterdam)
  - [ ] Add day-of-week attribution for correct tariff lookup
  - [ ] Add ortPercentage field (decimal 0.0–0.60)
  - [ ] Add baseHourlyRate and calculated ortAmount (Money)
  - [ ] Add claimStatus enum (provisional, confirmed, disputed, settled)

- [ ] Define `BereikbaarheidsDienst` entity for availability services
  - [ ] Add availability-shift time-range (dienstStartDate, dienstEndDate as ZonedDateTime)
  - [ ] Add passiveHourlyRate (Money, role-specific)
  - [ ] Add array of CallOutSession objects with independent hour tracking
  - [ ] Add totalAmount aggregation
  - [ ] Add dienstStatus enum

- [ ] Define `AanwezigheidsDienst` entity for on-premises non-active services
  - [ ] Add service time-range
  - [ ] Add conversionFactor (decimal, looked up per service type)
  - [ ] Add convertedWageHours and convertedAmount calculations
  - [ ] Add dienstStatus enum

- [ ] Define `SlaapDienst` entity for sleep-duty services
  - [ ] Add duty time-range
  - [ ] Add array of SleepBlock objects with per-block conversion
  - [ ] Add array of CallOutInterruption objects
  - [ ] Add totalConvertedWageHours aggregation
  - [ ] Add dienstStatus enum

- [ ] Define `OveruurClaim` entity for overtime above daily shift
  - [ ] Add contracted-shift and actual-end-time fields
  - [ ] Add overuurDurationMinutes
  - [ ] Add dienstverband enum to determine percentage (100% for surgical/anaesthetic, 50% for others)
  - [ ] Add baseHourlyRate and overuurAmount (Money)
  - [ ] Add claimStatus enum with approval workflow

- [ ] Define `AdvVerlofTegoed` entity for ADV entitlement
  - [ ] Add tegoedYear, accrual start/end dates
  - [ ] Add contractomvangFactor for part-time pro-rata
  - [ ] Add annualEntitlementHours, accruedHours, balanceHours (Decimal)
  - [ ] Add elections array with electionType enum (cash_payout, extra_vacation, structured_reduction)
  - [ ] Add tegoedStatus enum

- [ ] Define `TijdVoorTijdSaldo` entity for time-off bank
  - [ ] Add totalBalanceHours and maximumBalanceHours (80 max)
  - [ ] Add balanceExpiryDate (12 months from most recent entry)
  - [ ] Add entries array with creditDate and expiryDate per entry
  - [ ] Add usageHistory array with usage dates and hours
  - [ ] Add saldoStatus enum (active, at_maximum, expired_pending_payout, closed)

- [ ] Define reference data entities:
  - [ ] `FwgSchaalTabel` — score ranges to salary scale mapping with versioning (effective-from/to dates)
  - [ ] `OrtTariefMatrix` — day-of-week × time-of-day tariff matrix with DST rule
  - [ ] `BereikbaarheidsTariefTabel` — role-category to hourly rate mapping
  - [ ] `AanwezigheidsdienstConversieTabel` — service-type to conversion-factor mapping
  - [ ] `PfzwPremieTabel` — PFZW premium percentage, franchise, and employer/employee split

- [ ] Implement data-access repository layer
  - [ ] CRUD operations for all entities
  - [ ] Query methods for employment lookup by employee/org
  - [ ] Query methods for reference-table lookups (effective-dated)
  - [ ] Audit-trail logging for all salary-sensitive writes (NEN 7512/7513 compliance)

## Business Logic: FWG Scoring (REQ-001)

- [ ] Implement FWG score validation
  - [ ] Validate all 9 sub-scores are present (raise `IncompleteFwgScoreException` if missing)
  - [ ] Validate score ranges per sub-score rubric (0–16, 0–20, 0–8, 0–4, etc.)
  - [ ] Calculate totaalscore as sum of sub-scores

- [ ] Implement FWG-to-functiegroep mapping
  - [ ] Load FwgSchaalTabel for the effective date (CAO agreement date)
  - [ ] Map totaalscore to unique functiegroep using exact range lookup (no overlaps)
  - [ ] Raise exception if score falls outside all ranges (invalid FWG data)

- [ ] Implement reference-function validation
  - [ ] Load FWG-referentiefuncties database (external, via Stichting FWG license)
  - [ ] Match functiebenaming against reference-function records
  - [ ] If functiebenaming maps to a specific functiegroep in the reference, compare against derived functiegroep
  - [ ] Generate `FwgReferenceFunctionMismatch` warning if derived functiegroep falls outside the reference-function's assigned range

- [ ] Expose FWG scoring via API endpoint
  - [ ] POST /api/cao-ziekenhuizen/fwg-scores with sub-scores and functiebenaming
  - [ ] Return derived functiegroep, salarisschaal, and any warnings
  - [ ] Enforce READ+WRITE auth (FWG scoring requires HR admin privilege)

## Business Logic: ORT Calculation (REQ-002)

- [ ] Implement ORT tariff lookup
  - [ ] Load OrtTariefMatrix for the calculation date
  - [ ] Given dayOfWeek and startTime, look up the percentage for each time-of-day boundary
  - [ ] Handle Saturday/Sunday and holiday overrides (feestdagen)

- [ ] Implement minute-by-minute ORT attribution
  - [ ] Split shifts crossing time-of-day or day-of-week boundaries into segments
  - [ ] For each segment, determine dayOfWeek and timeRange
  - [ ] Apply correct ortPercentage per segment
  - [ ] Round ORT amounts to cent-precision (EUR 0.01 rounding)

- [ ] Implement DST boundary handling
  - [ ] Detect DST transitions (Europe/Amsterdam timezone)
  - [ ] For spring-forward transitions (02:00 → 03:00): skip the missing hour; do not double-count
  - [ ] For fall-back transitions (03:00 → 02:00): process both occurrences with correct minute attribution
  - [ ] Use Java ZonedDateTime or equivalent for timezone-aware arithmetic

- [ ] Implement OrtClaim entity creation
  - [ ] Create OrtClaim records from shift definitions (received from rostering-planning)
  - [ ] Mark status as `provisional` on creation
  - [ ] Calculate ortAmount per OrtClaim

- [ ] Implement ORT dispute workflow
  - [ ] Allow HR/payroll to change claimStatus from `confirmed` to `disputed` with a reason
  - [ ] Suppress ORT payout for disputed claims until resolution
  - [ ] Record dispute resolution and update status to `settled`

- [ ] Expose ORT calculation via API
  - [ ] POST /api/cao-ziekenhuizen/ort-claims to register shifts
  - [ ] GET /api/cao-ziekenhuizen/ort-claims to query by employee/date-range
  - [ ] PATCH to update claimStatus (for disputes)
  - [ ] Return aggregated ORT breakdown by date/percentage for payroll validation

## Business Logic: Availability Services (REQ-003)

- [ ] Implement BereikbaarheidsDienst registration
  - [ ] Accept availability-shift time range from HR/roster
  - [ ] Validate that dienstverband permits availability (no administratie, facilitair, etc.)
  - [ ] Look up passiveHourlyRate from BereikbaarheidsTariefTabel per functieCategorie
  - [ ] Calculate passiveAmount = durationHours × passiveHourlyRate

- [ ] Implement call-out tracking
  - [ ] Accept CallOutSession records (call-out time-range and type)
  - [ ] Calculate base salary + ORT for each call-out hour
  - [ ] Sum call-out amounts separately
  - [ ] Update totalAmount = passiveAmount + sum(callOutAmounts)

- [ ] Implement call-out validation
  - [ ] Ensure call-out times fall within the availability-shift period
  - [ ] Raise `CallOutOutsideAvailabilityPeriodException` if not

- [ ] Expose availability services via API
  - [ ] POST /api/cao-ziekenhuizen/bereikbaarheid-diensten to register shifts
  - [ ] POST /api/cao-ziekenhuizen/bereikbaarheid-diensten/{id}/call-outs to log call-outs
  - [ ] GET to query active availability shifts by employee/date
  - [ ] PATCH to update status (completed, cancelled)

## Business Logic: Sleep-Duty Conversion (REQ-004)

- [ ] Implement sleep-duty registration
  - [ ] Accept sleep-duty time-range from roster
  - [ ] Validate that role permits sleep-duty (not Wtb-GiO aios, etc.)
  - [ ] Load AanwezigheidsdienstConversieTabel for sleep-type conversion factor

- [ ] Implement sleep-block conversion
  - [ ] Split sleep-duty into uninterrupted sleep blocks and call-out interruptions
  - [ ] For each sleep block: convertedWageHours = blockDurationHours × conversionFactor
  - [ ] For each call-out: countAsFullWageHours as-is (no conversion factor)
  - [ ] Calculate ORT on converted wage-hours per time-of-night (0–60%)

- [ ] Implement SlaapDienst entity with aggregation
  - [ ] totalConvertedWageHours = sum(sleepBlocks.convertedWageHours) + sum(interruptionWageHours)
  - [ ] totalAmount = totalConvertedWageHours × baseHourlyRate

- [ ] Expose sleep-duty via API
  - [ ] POST /api/cao-ziekenhuizen/slaap-diensten to register shifts
  - [ ] POST /api/cao-ziekenhuizen/slaap-diensten/{id}/call-outs to log interruptions
  - [ ] GET to query by employee/date-range

## Business Logic: Surgical Overtime (REQ-005)

- [ ] Implement surgical department override for overtime
  - [ ] Check if dienstverband is in {ok, ok-anesthesie}
  - [ ] If true: set overuurPercentage = 1.0 (100%)
  - [ ] If false: apply standard rules (0.5 after 8h, 1.0 on weekends)

- [ ] Implement overtime claim validation
  - [ ] Require that overtime exceeds contracted daily-shift end time (not weekly hours)
  - [ ] Raise `OverurenZonderDienstNoodzaakException` if no roster event justifies the claim
  - [ ] Require manager approval if overuurDurationMinutes > 60

- [ ] Implement OveruurClaim entity and aggregation
  - [ ] overuurAmount = baseHourlyRate × (overuurDurationMinutes / 60) × overuurPercentage
  - [ ] Add claimStatus workflow (pending_approval → approved/rejected → settled)

- [ ] Expose overtime via API
  - [ ] POST /api/cao-ziekenhuizen/overuur-claims to register claims
  - [ ] PATCH to approve/reject (manager endpoint)
  - [ ] GET to query by employee/date-range

## Business Logic: PFZW Pension (REQ-006)

- [ ] Implement PFZW registration at employment start
  - [ ] On CaoZiekenhuisEmployment.contractStartDate, create a PFZW enrollment record
  - [ ] Record enrollment date, employee ID, organization ID, PFZW fund

- [ ] Implement PFZW premium calculation
  - [ ] Load PfzwPremieTabel for the payroll month
  - [ ] Calculate annual benefit base = 12 × monthlyBaseSalary
  - [ ] For part-time: adjustedFranchise = parttimePercentage × annualFranchise
  - [ ] taxableBase = annualBenefitBase − adjustedFranchise (min 0)
  - [ ] monthlyPremium = (taxableBase / 12) × premiePercentage
  - [ ] employerPremium = monthlyPremium × 0.50
  - [ ] employeePremium = monthlyPremium × 0.50

- [ ] Implement PFZW payroll integration
  - [ ] Include employerPremium in payroll cost (gross salary + employer social charges)
  - [ ] Include employeePremium in payroll deductions (from net salary)

- [ ] Implement PFZW deduplication for dual-fund employees
  - [ ] If employee has simultaneous cao-ziekenhuizen + cao-umc employments, register separately
  - [ ] Do NOT merge benefit bases across funds

- [ ] Implement PFZW submission interface
  - [ ] Periodic export of PFZW enrollment and premium data
  - [ ] Coordinate with pfzw-aansluiting capability for actual fund submission

- [ ] Expose PFZW via API
  - [ ] GET /api/cao-ziekenhuizen/pfzw-registrations by employee/date
  - [ ] GET /api/cao-ziekenhuizen/pfzw-premiums for payroll validation (read-only, auditor access)

## Business Logic: ADV Entitlement (REQ-007)

- [ ] Implement ADV accrual per employment
  - [ ] On each payroll month, accrue ADV = (monthlyWorkHours / annualWorkHours) × (annualEntitlementHours × contractomvangFactor)
  - [ ] For full-time: annualEntitlementHours = 96; for part-time scale pro-rata
  - [ ] Update AdvVerlofTegoed.accruedHours and balanceHours

- [ ] Implement ADV election interface
  - [ ] Quarterly election window (Q1: end of March, Q2: end of June, Q3: end of Sept, Q4: end of Dec)
  - [ ] Accept election type (cash_payout, extra_vacation, structured_reduction)
  - [ ] For cash_payout: pay out at current hourly rate, apply income tax and PFZW deductions
  - [ ] For extra_vacation: convert hours to calendar-vacation-days per employment law (part-timers use day-equivalents)
  - [ ] For structured_reduction: reduce daily working hours starting next month

- [ ] Implement ADV balance tracking
  - [ ] Record each election in AdvVerlofTegoed.elections array
  - [ ] Update balanceHours after each election
  - [ ] Prevent elections that exceed balanceHours

- [ ] Expose ADV via API
  - [ ] GET /api/cao-ziekenhuizen/adv-tegu/{employmentId}/{year} for employee self-service view
  - [ ] POST /api/cao-ziekenhuizen/adv-elections to submit quarterly election
  - [ ] PATCH to update election status (pending → processed)

## Business Logic: Time-Off Bank (REQ-008)

- [ ] Implement TijdVoorTijdSaldo per employment
  - [ ] Initialize empty balance on first ORT/overuur entry

- [ ] Implement time-off bank credit from shifts
  - [ ] On each OrtClaim or OveruurClaim, allow employee to elect time-off instead of cash
  - [ ] If elected: creditedHours = workHours + (ORT × workHours)
  - [ ] Add TijdSaldoEntry with creditDate and expiryDate (+ 12 months)
  - [ ] Suppress cash payout for that shift

- [ ] Implement balance overflow protection
  - [ ] Check if (totalBalanceHours + creditedHours) > maximumBalanceHours (80)
  - [ ] If yes, raise `TijdVoorTijdSaldoOverflowException`
  - [ ] Force employee to take cash for that shift

- [ ] Implement automatic expiry and payout
  - [ ] Monthly: scan all TijdSaldoEntry records
  - [ ] If expiryDate has passed and entry is not yet used: convert to cash at current hourly rate
  - [ ] Add payout to next salary run with income tax and PFZW deductions
  - [ ] Remove expired entry from balance
  - [ ] Update saldoStatus to `expired_pending_payout` → `closed`

- [ ] Implement time-off bank usage
  - [ ] Employee can use balance for vacation, sick-leave, or personal time
  - [ ] Track usage in usageHistory array
  - [ ] Decrement totalBalanceHours accordingly

- [ ] Expose time-off bank via API
  - [ ] GET /api/cao-ziekenhuizen/tijd-saldo/{employmentId} for self-service view
  - [ ] GET /api/cao-ziekenhuizen/ort-claims?election=tijd_voor_tijd to elect time-off at claim registration
  - [ ] PATCH to use hours (linked to leave requests)

## Business Logic: Part-Time Pro-Rata (REQ-009)

- [ ] Implement part-time salary scaling
  - [ ] In payroll calculation: monthlyNetSalary = (referenceMonthlyBaseSalary × parttimePercentage)
  - [ ] Apply to all salary grade-and-step tables

- [ ] Implement part-time entitlement pro-rata
  - [ ] Vacation accrual: annualVacationDays × parttimePercentage
  - [ ] ADV accrual: annualAdvHours × parttimePercentage
  - [ ] Jubilee payout: monthlyEquivalent × parttimePercentage
  - [ ] PFZW franchise: annualFranchise × parttimePercentage (calculated in PFZW premium logic)

- [ ] Implement ORT percentage non-scaling rule
  - [ ] When calculating ORT, use full ortPercentage (0–60%) regardless of parttimePercentage
  - [ ] ortAmount = baseHourlyRate × (workMinutes / 60) × ortPercentage (no × parttimePercentage)

- [ ] Add validation
  - [ ] Ensure parttimePercentage is in range [0.01, 1.0]
  - [ ] On hire/contract-change, update entitlement tables pro-rata

## Business Logic: Sick-Pay Continuation (REQ-010)

- [ ] Implement sick-leave registration
  - [ ] Accept sickness notification with start date
  - [ ] Record in employment record as sickLeaveStartDate

- [ ] Implement sick-pay calculation
  - [ ] Year 1 (days 0–365 from sickLeaveStartDate): continuationPercentage = 1.0 (100%)
  - [ ] Year 2 (days 365–730): continuationPercentage = 0.9 (90%)
  - [ ] After day 730 (104 weeks statutory limit): continuationPercentage = 0.9 but requires active re-integration

- [ ] Implement re-integration cooperation check
  - [ ] HR/occupational health logs re-integration trajectory participation
  - [ ] If non-cooperation documented: apply penalty (continuationPercentage = 0.6, a 30-point reduction)
  - [ ] Send formal notice to employee with appeal rights

- [ ] Expose sick-pay via API
  - [ ] POST /api/cao-ziekenhuizen/sickness to register sickness notification
  - [ ] GET /api/cao-ziekenhuizen/sickness/{employmentId} for current status
  - [ ] PATCH to update re-integration status

- [ ] Payroll integration
  - [ ] In monthly payroll: calculate sickPayAmount = baseSalary × continuationPercentage
  - [ ] Output separate line-item for sick-pay breakdown in payslip

## Integration with Cross-App Services

- [ ] Integrate with payroll-engine-nl
  - [ ] Expose read-model API with employment, salary scale, ORT claims, availability, sleep-duty, overuur, ADV, time-saldo, PFZW for monthly payroll run
  - [ ] API returns decomposed bezoldigingsspecificatie (salary structure with all components)

- [ ] Integrate with rostering-planning
  - [ ] Subscribe to shift/roster creation events
  - [ ] Auto-generate OrtClaim, BereikbaarheidsDienst, SlaapDienst, OveruurClaim records
  - [ ] Expose ORT-cost query endpoint for roster budget validation

- [ ] Integrate with verlof-administratie
  - [ ] Publish AdvVerlofTegoedUpdated, AdvElectionProcessed events
  - [ ] Publish TijdVoorTijdSaldoUpdated events
  - [ ] Coordinate vacation accrual and usage

- [ ] Integrate with contract-generatie
  - [ ] Expose salary-indicator lookup on hire, internal moves, part-time changes
  - [ ] Query FWG scale and step for contract generation

- [ ] Integrate with pfzw-aansluiting
  - [ ] Send PFZW enrollment and premium records to shared pension capability
  - [ ] Receive deduplication guidance for dual-fund employees

- [ ] Integrate with pre-employment-screening
  - [ ] Query for BIG-registration validation on roles FWG-50+ in clinical dienstverbande

## API & Exposure Layer

- [ ] Design stable read-model API per ADR-002
  - [ ] Endpoints: /api/cao-ziekenhuizen/employments, /ort-claims, /availability, /sleep-duty, /overtime, /adv, /tijd-saldo, /pfzw
  - [ ] Pagination: _page, _limit, total, pages
  - [ ] Auth: Nextcloud built-in, role-based (HR admin, payroll, auditor)
  - [ ] No custom session/token flows
  - [ ] CORS: OPTIONS pre-flight for cross-app callers

- [ ] Implement REST verbs strictly
  - [ ] GET = read (idempotent)
  - [ ] POST = create
  - [ ] PUT = full update
  - [ ] PATCH = partial update (status, dispute reason, etc.)
  - [ ] DELETE = remove (rare; mostly soft-delete with audit-trail)

- [ ] Error handling
  - [ ] HTTP status codes: 200 OK, 201 Created, 400 Bad Request, 403 Forbidden, 404 Not Found, 409 Conflict, 500 Server Error
  - [ ] Response body: { "message": "…" } (no stack traces)
  - [ ] Validation errors: list specific field violations

## Audit & Compliance

- [ ] Implement NEN 7512/7513 audit logging
  - [ ] Log all writes to salary-sensitive entities (employment, scores, ORT, availability, pension, ADV, sick-pay)
  - [ ] Record: timestamp, user ID, entity, action (create/update/delete), old value, new value
  - [ ] Audit records are immutable (append-only)

- [ ] Implement GDPR data-retention policies
  - [ ] Salary/employment data: retain 7 years minimum (per Dutch tax law)
  - [ ] Audit-trail: retain same period
  - [ ] Anonymization: upon employee exit, retain records but anonymize PII after retention period expires

- [ ] Implement access control
  - [ ] HR admin: full R/W on employment, FWG, ADV, PFZW, sick-pay
  - [ ] Payroll: R on employment/salary, W on ORT status/disputes
  - [ ] Auditor: R on all (no writes)
  - [ ] Employee: R on own payslip, ADV balance, time-saldo, sick-pay
  - [ ] Manager: R on team's employment, W on overtime approval, availability validation

## Testing

- [ ] Unit tests
  - [ ] FWG score mapping (valid scores, incomplete, mismatches)
  - [ ] ORT calculation (day-of-week boundaries, DST, minute-level precision, holiday override)
  - [ ] Part-time pro-rata (all entitlements except ORT percentage)
  - [ ] PFZW premium (franchise scaling, dual funds)
  - [ ] ADV accrual and election logic
  - [ ] Time-off bank overflow, expiry, payout
  - [ ] Sick-pay continuation (years 1-2, non-cooperation penalty)

- [ ] Integration tests
  - [ ] Shift → ORT claim → time-off bank credit → payout
  - [ ] Employment → PFZW enrollment → premium calculation
  - [ ] ADV accrual → quarterly election → payroll deduction
  - [ ] Cross-app event publishing (rostering-planning, verlof-administratie)

- [ ] Data-migration tests
  - [ ] Migration from legacy payroll system (if applicable)
  - [ ] Validate historical ORT data re-calculated matches source system

## Documentation

- [ ] API documentation
  - [ ] OpenAPI/Swagger spec for all REST endpoints
  - [ ] Authentication, pagination, error-codes
  - [ ] Example requests/responses per scenario

- [ ] Implementation guide for HR teams
  - [ ] How to register a new employee with FWG classification
  - [ ] How to process part-time changes
  - [ ] How to handle ORT disputes
  - [ ] How to process ADV elections

- [ ] Compliance documentation
  - [ ] Mapping: CAO Ziekenhuizen (Staatscourant) → spec requirements → code implementation
  - [ ] Audit-trail structure (NEN 7512/7513)
  - [ ] Data-retention and anonymization policies

## Deployment & Rollout

- [ ] Configuration management
  - [ ] FWG scale tables, ORT matrix, PFZW premium rates as data-loaded reference tables (not hard-coded)
  - [ ] Effective-from dates enable smooth CAO-agreement transitions

- [ ] Feature flag: cao-ziekenhuizen
  - [ ] Gradual rollout to pilot ziekenhuizen first
  - [ ] Validation against production payroll to ensure accuracy before full rollout

- [ ] Integration checklist
  - [ ] Confirm payroll-engine-nl can read cao-ziekenhuizen employment data
  - [ ] Confirm rostering-planning can publish ORT events
  - [ ] Confirm verlof-administratie receives ADV updates
  - [ ] Confirm PFZW data flows to pfzw-aansluiting

- [ ] Training
  - [ ] HR admin training on FWG classification and employment registration
  - [ ] Payroll training on ORT validation and dispute resolution
  - [ ] Manager training on overtime approval and ADV election processing

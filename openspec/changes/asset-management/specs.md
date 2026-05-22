---
status: draft
version: 1.0
date: 2026-05-22
---

# Specifications: Asset Management voor HRMQ

## REQ-001: Asset Registration

**Title**: Asset-registratie met type-driven veldvalidatie

**Description**: A user with role `asset_manager` or `hr_admin` registers a new Asset with all required fields, with form layout and defaults driven by asset type. The system enforces serienummer uniqueness within the organization for physical assets.

### REQ-001-001: Basic Asset Registration

**GIVEN** I am logged in as `hr_admin`  
**WHEN** I navigate to Assets → "Nieuw Asset" and select type "laptop"  
**THEN** the form displays fields: serienummer, merk_en_model, aankoopdatum, aankoopprijs_excl_btw, aankoopprijs_incl_btw, leverancier, afschrijvingstermijn_maanden  
**AND** afschrijvingstermijn_maanden is pre-filled with 36  
**AND** serienummer field is marked required (red asterisk)  
**AND** all date fields use a date-picker widget  
**AND** all price fields enforce EUR decimal format (2 places)

### REQ-001-002: Serienummer Validation

**GIVEN** I am registering a physical asset type (laptop, telefoon, monitor, bureaustoel, etc.)  
**WHEN** I attempt to save the form without entering serienummer  
**THEN** the form shows a blocking validation error: "Serienummer is verplicht voor type laptop"  
**AND** the submit button remains disabled  
**AND** no database insert occurs

**GIVEN** I am registering a software_license asset  
**WHEN** I leave serienummer blank and attempt to save  
**THEN** the form accepts the submission (serienummer is optional for software)  
**AND** Asset is created with serienummer = null

### REQ-001-003: Serienummer Uniqueness

**GIVEN** a laptop with serienummer "DE50224X9Z" already exists in the organization  
**WHEN** I attempt to create a new laptop with the same serienummer  
**THEN** the form shows validation error: "Serienummer 'DE50224X9Z' is already registered"  
**AND** no database insert occurs  
**AND** the field is highlighted red

**GIVEN** a laptop with serienummer "DE50224X9Z" exists  
**WHEN** I attempt to create a telefoon with the same serienummer  
**THEN** the submission succeeds (serienummer uniqueness is per asset type)

### REQ-001-004: Leverancier Lookup

**GIVEN** I am registering a new asset  
**WHEN** I click the leverancier field  
**THEN** an autocomplete dropdown appears querying OpenRegister Organisation  
**AND** search is case-insensitive (e.g., "dell" matches "Dell")  
**AND** results show Organisation.name and optionally a shortened detail (contact person, city)  
**AND** selecting a result populates leverancier_id (hidden field) and displays the org name

**GIVEN** I type "niet-bestaande-leverancier" and it returns no results  
**WHEN** I press Enter or Tab  
**THEN** the field remains empty, leverancier_id = null, and a helper text displays "Leverancier niet in register; voeg toe in OpenRegister"

### REQ-001-005: Lease Asset Conditional Fields

**GIVEN** I am registering an asset and select type "lease_auto"  
**WHEN** the form re-renders  
**THEN** additional sections appear:
- Leasemaatschappij (required, autocomplete from OpenRegister Organisation)
- Lease contract nummer (required, text field)
- Lease einddatum (required, date picker)
- Maandbedrag lease excl. BTW (required, EUR decimal)
- Lease kilometers per jaar (required, integer ≥0)

**AND** the afschrijvingstermijn_maanden field is read-only, pre-filled with 60

**GIVEN** I select type "leasefiets"  
**WHEN** the form re-renders  
**THEN** the same lease fields appear (except lease_kilometers_per_jaar, which is hidden for non-auto types)  
**AND** afschrijvingstermijn_maanden is pre-filled with 36

### REQ-001-006: Save and Confirmation

**GIVEN** all required fields are completed  
**WHEN** I click "Opslaan"  
**THEN** the system validates all constraints (serienummer, dates, prices, lease fields if applicable)  
**AND** if validation passes, a modal/confirmation appears: "Asset created: Dell XPS 15 (DE50224X9Z)"  
**AND** the modal offers options: "Done" → back to Assets list, "Assign to employee" → direct to assignment flow

**GIVEN** an asset is successfully created  
**WHEN** the DB is queried  
**THEN** the Asset row exists with:
- status = `ingenomen` (default; awaiting first assignment)
- created_at = now
- created_by = current user ID
- all fields saved as entered

---

## REQ-002: Asset Assignment (Uitgifte)

**Title**: Asset-uitgifte aan werknemer met status-tracking

**Description**: An Asset transitions from `ingenomen` or `in_reparatie` to `uitgeleend` when assigned to an employee via AssetAssignment creation. Only one active (inname_datum = null) assignment per Asset is permitted.

### REQ-002-001: Asset Assignment Creation

**GIVEN** an Asset with status `ingenomen` exists and has no active AssetAssignment  
**WHEN** I open the Asset detail and click "Uitgifte"  
**THEN** a modal appears with:
- Employee search field (autocomplete by name, employee ID)
- staat_bij_uitgifte dropdown (nieuw, goed, gebruikt, beschadigd)
- uitgifte_datum pre-filled with today's date (editable, for backdating)

**AND** the "Bevestigen" button is disabled until both employee and state are selected

**GIVEN** all fields are populated  
**WHEN** I click "Bevestigen"  
**THEN** the system validates:
- Asset.status ∉ [vermist, afgeschreven, verkocht]
- No active AssetAssignment exists (inname_datum = null)

**AND** if valid:
- AssetAssignment is created with uitgifte_datum, employee_id, staat_bij_uitgifte, uitgifte_door = current user
- Asset.status is set to `uitgeleend`
- AssetHistoryEntry is created with event_type = `uitgifte`, actor_id = current user, new_value = {status: "uitgeleend"}
- Modal closes and Asset detail re-loads, showing new assignment info

### REQ-002-002: Assignment Conflict Prevention

**GIVEN** an Asset is already actively assigned to employee "Jan de Vries" (AssetAssignment.inname_datum = null)  
**WHEN** I attempt to assign the same Asset to employee "Koen Jansen"  
**THEN** the system shows a blocking error: "Asset is al uitgegeven aan Jan de Vries sinds 2026-03-12; neem eerst in"  
**AND** the Bevestigen button remains disabled  
**AND** no new AssetAssignment is created

### REQ-002-003: Lease Car Privé-Gebruik Declaration

**GIVEN** I am assigning an Asset of type `lease_auto`  
**WHEN** the initial AssetAssignment is created successfully (status=`uitgeleend`)  
**THEN** a follow-up modal appears: "Privé-gebruik verklaring benodigd voor lease-auto"  
**WITH** radio options:
- "Geen privé-gebruik" (value: none)
- "Beperkt privé-gebruik ≤500 km/jaar" (value: beperkt_500km)
- "Volledige privé-gebruik" (value: vol)

**AND** a "Opslaan" button (disabled until selection made)

**GIVEN** the employee selects "Volledige privé-gebruik" and clicks "Opslaan"  
**THEN** the system:
- Queries or creates LeaseCarTaxRecord for this Asset (if not already exists)
- Updates LeaseCarTaxRecord.privé_gebruik_verklaring = "vol"
- Recalculates bijtelling percentage (see REQ-004)
- Emits an event to payroll-engine-nl: `asset_assigned` with bijtelling details
- Shows confirmation: "Lease-auto toewijzing voltooid; bijtelling €... per maand"
- Closes modal and returns to Assets list

### REQ-002-004: Uitgifte Backdating

**GIVEN** I am registering a historical asset assignment (asset was issued before today)  
**WHEN** I open the uitgifte_datum field  
**THEN** a date-picker allows me to select any date in the past or future  
**AND** I can select "2024-09-01" to backdate the assignment

**GIVEN** I select a past date and click "Bevestigen"  
**WHEN** the AssetAssignment is created  
**THEN** AssetHistoryEntry.event_at is set to the selected uitgifte_datum (not current time)  
**AND** the audit trail shows the historical timestamp

---

## REQ-003: Asset Return (Inname)

**Title**: Asset-inname met staat-registratie en schade-tracking

**Description**: An active AssetAssignment (inname_datum = null) is closed when the asset is returned. The condition at return (goed/beschadigd/vermist) is recorded and triggers status transitions and notifications.

### REQ-003-001: Asset Return UI & Validation

**GIVEN** an Asset has an active AssetAssignment (uitgifte_datum filled, inname_datum = null)  
**WHEN** I open the Asset detail and click "Innemen"  
**THEN** a modal appears with:
- staat_bij_inname dropdown (goed, beschadigd, vermist)
- Opmerking text area (optional, placeholder: "Beschrijf schade of reden vermist")
- Inname date field (pre-filled with today, editable)

**AND** "Bevestigen" button is disabled until staat_bij_inname is selected

### REQ-003-002: Inname State Transitions

**GIVEN** I select staat_bij_inname = "goed" and click "Bevestigen"  
**WHEN** the submission succeeds  
**THEN**:
- AssetAssignment.inname_datum = selected date, staat_bij_inname = "goed"
- Asset.status = `ingenomen` (back to depot)
- AssetHistoryEntry created: event_type = `inname`, previous_value = {status: "uitgeleend"}, new_value = {status: "ingenomen"}

**GIVEN** I select staat_bij_inname = "beschadigd" with opmerking = "schermcrack onderkant"  
**WHEN** the submission succeeds  
**THEN**:
- AssetAssignment.inname_datum filled, staat_bij_inname = "beschadigd", opmerking_bij_inname = user text
- Asset.status = `in_reparatie`
- AssetHistoryEntry created with event_type = `inname`, note = "schermcrack onderkant"
- Notification sent to asset_manager: "iPhone beschadigd ingenomen; reparatie nodig?"

**GIVEN** I select staat_bij_inname = "vermist" and provide note = "employee linksaf, asset not found"  
**WHEN** the submission succeeds  
**THEN**:
- AssetAssignment.inname_datum filled, staat_bij_inname = "vermist", opmerking_bij_inname = user text
- Asset.status = `vermist` (immutable; no further assignments allowed)
- AssetHistoryEntry created with event_type = `inname`, note = user text
- Finance/asset_manager flagged for missing-asset liability (configurable org policy)

### REQ-003-003: Event Propagation on Return

**GIVEN** an active lease_auto AssetAssignment is closed (inname_datum set, staat_bij_inname ≠ vermist)  
**WHEN** the submission completes  
**THEN** an event is emitted to payroll-engine-nl: `asset_returned`
```json
{
  "asset_id": "a0002-uuid",
  "employee_id": "emp-maria-uuid",
  "type": "lease_auto",
  "return_date": "2026-05-20",
  "condition": "goed",
  "bijtelling_monthly": null
}
```

**AND** payroll-engine-nl ceases charging bijtelling for this employee from the month after return  
**AND** the employee's payroll is not affected if the return occurs mid-month (adjustment applied next month)

### REQ-003-004: Offboarding Asset Checklist Integration

**GIVEN** an employee's offboarding is initiated with einde_dienstverband = 2026-06-30  
**WHEN** the Offboarding Wizard → "Asset Check" step is displayed  
**THEN** the system queries all AssetAssignments where:
- employee_id = target employee
- inname_datum = null (active assignments)

**AND** a checklist is displayed:
```
☐ Dell XPS 15 (SerNr: DE50224X9Z) — uitgeleend sinds 2024-09-01
  [Mark as Returned] [Mark as Missing]
☐ Tesla Model 3 (Reg: TR-92-KN) — uitgeleend sinds 2025-03-15
  [Mark as Returned] [Mark as Missing]
```

**GIVEN** the HR Admin marks all assets as returned or missing  
**WHEN** the final asset is resolved  
**THEN** the wizard enables the next step (eind-afrekening)  
**AND** all marked assets are flagged for inname processing (staff will execute return flows)

**GIVEN** at least one asset remains unresolved (neither returned nor missing marked)  
**WHEN** the wizard attempts to advance to eind-afrekening  
**THEN** a blocking validation appears: "Voltooi asset-checklist: ... assets nog open"

---

## REQ-004: Lease Car Bijtelling Calculation

**Title**: Automatische bijtellingsberekening volgens fiscale staffel

**Description**: For each lease-auto Asset, the system calculates the monthly bijtelling (loon-in-natura) based on the fiscal staffel (cataloguswaarde bracket, brandstoftype, year of Datum Eerste Tenaamstelling), applied within a 60-month term. The calculation respects privé-gebruik declaration.

### REQ-004-001: Bijtelling Staffel Lookup

**GIVEN** a lease_auto with:
- DET: 2024-06-01
- brandstoftype: elektrisch
- cataloguswaarde: €28.000
- privé_gebruik_verklaring: vol

**WHEN** bijtelling is calculated for January 2026  
**THEN** the system:
1. Confirms DET + 60mo = 2029-06-01, which is ≥ 2026-01-01 (still within term)
2. Looks up fiscal staffel for 2026, brandstoftype=elektrisch:
   - ≤€30.000: 16%
   - €30.001–€60.000: 22%
3. Applies brackets:
   - Full €28.000 @ 16% = €4.480 jaarlijks
   - Maandelijks: €4.480 / 12 = €373,33
4. **Result**: bijtelling_maand = €373,33

### REQ-004-002: Multi-Bracket Cataloguswaarde

**GIVEN** a lease_auto with:
- DET: 2024-06-01
- brandstoftype: elektrisch
- cataloguswaarde: €60.000
- privé_gebruik_verklaring: vol

**WHEN** bijtelling is calculated for January 2026  
**THEN** the system applies fiscal staffel 2026:
1. First €30.000 @ 16% = €4.800
2. Remaining €30.000 @ 22% = €6.600
3. Total jaarlijks: €11.400
4. **Result**: bijtelling_maand = €11.400 / 12 = €950,00

### REQ-004-003: Staffel Transition at 60-Month Boundary

**GIVEN** a lease_auto with:
- DET: 2019-04-01
- brandstoftype: benzine
- cataloguswaarde: €35.000
- privé_gebruik_verklaring: vol

**WHEN** the evaluation date is May 2026 (DET + 60mo = 2024-04-01, which is ≤ today)  
**THEN** the system recognizes the 60-month term has expired  
**AND** applies the overgangsrecht: bijtellingspercentage = 35% (flat rate for vehicles beyond 60-month term)  
**AND** calculation: €35.000 × 35% / 12 = €1.020,83 per month  
**AND** no further percentage updates occur for this vehicle (locked at 35% post-term)

### REQ-004-004: Privé-Gebruik None Scenario

**GIVEN** a lease_auto with:
- DET: 2024-06-01
- brandstoftype: elektrisch
- cataloguswaarde: €40.000
- privé_gebruik_verklaring: none
- Employer maintains trip log with <500 km privé per calendar year

**WHEN** bijtelling is calculated  
**THEN** the system:
1. Recognizes privé_gebruik_verklaring = "none"
2. Sets bijtelling_maand = €0
3. Logs note: "No personal use declaration; employer responsible for mileage audit (<500km/year exemption)"
4. Payroll is not charged bijtelling
5. Employer must maintain audit trail (kilometers log) for 7 years

### REQ-004-005: Beperkt 500km Scenario

**GIVEN** a lease_auto with privé_gebruik_verklaring = beperkt_500km  
**WHEN** bijtelling is calculated  
**THEN** the system:
1. Applies the same percentage as "vol" (no reduction for beperkt_500km; only "none" gives €0)
2. bijtelling_maand = normal calculated amount
3. Logs note: "Limited personal use (≤500km/year) declared; audit trail required"
4. Employer is obligated to track kilometers and provide evidence if audited

---

## REQ-005: Automatic Staffel Transition & Term Boundary

**Title**: Automatische bijtellingspercentage-updates bij jaarlijkse staffelwijziging en 60-maands-termijn-einde

**Description**: The daily batch job monitors lease-car records for two triggers: (a) fiscal year change (new staffel published), or (b) DET + 60 months boundary reached. On either event, bijtelling is recalculated and events are emitted.

### REQ-005-001: Calendar Year Staffel Update

**GIVEN** a lease_auto with:
- DET: 2020-04-01
- brandstoftype: elektrisch
- cataloguswaarde: €32.000
- bijtellingspercentage_current: 8% (2025 staffel)
- today: 2026-01-01 (fiscal year 2026 begins)

**WHEN** the daily batch detects a new fiscal_year (staffel_version incremented)  
**AND** the vehicle is within 60-month term  
**THEN** the system:
1. Looks up 2026 staffel for elektrisch:
   - ≤€30.000: 16%
   - €30.001–€60.000: 22%
2. Applies: First €30.000 @ 16% + €2.000 @ 22% = €5.240 / 12 = €436,67
3. Updates LeaseCarTaxRecord: bijtellingspercentage_current = 16%+ (first bracket), or 17.375% blended (TBD by tax expert)
4. Creates AssetHistoryEntry: event_type = `bijtelling_staffelovergang`, previous_value = {percentage: 8%}, new_value = {percentage: 16%+}
5. Emits event to payroll-engine-nl: `lease_car_bijtelling_update { asset_id, old_percentage: 8%, new_percentage: 16%+, new_monthly: €436,67 }`
6. Notifies asset_manager: "Lease car bijtelling updated: 1 vehicle, new rate €436,67/month"

### REQ-005-002: 60-Month Term Boundary

**GIVEN** a lease_auto with:
- DET: 2020-04-01
- datum_vandaag: 2025-04-01 (exactly DET + 60 months)
- brandstoftype: benzine
- bijtellingspercentage_current: 8% (within-term rate)

**WHEN** the daily batch executes on 2025-04-01  
**AND** detects bijtelling_einddatum_termijn ≤ today  
**THEN** the system:
1. Applies overgangsrecht: percentage = 35% (post-term flat rate)
2. Updates LeaseCarTaxRecord: bijtellingspercentage_current = 35%, bijtelling_einddatum_termijn remains 2025-04-01 (immutable marker)
3. Creates AssetHistoryEntry: event_type = `bijtelling_staffelovergang`, note = "60-month term boundary; overgangsrecht 35% applied"
4. Recalculates monthly amount
5. Emits payroll event with new percentage/amount

### REQ-005-003: No Double-Update on Year Boundary + Term Boundary

**GIVEN** a lease_auto with DET exactly 60 months before 2026-01-01 (i.e., DET = 2021-01-01)  
**WHEN** the batch runs on 2026-01-01  
**THEN** both triggers fire (year change + term boundary)  
**AND** the system:
1. Calculates new 2026 staffel rate (if within-term rules differ)
2. Applies overgangsrecht 35% (post-term always takes precedence)
3. Emits a single payroll event with final percentage = 35%
4. Creates single AssetHistoryEntry with note: "Year change + 60-month term boundary; overgangsrecht 35%"

---

## REQ-006: Payroll Event Coupling

**Title**: Automatische doorvoer van asset-events naar payroll-engine-nl

**Description**: Every asset lifecycle change (assignment, return, bijtelling update) triggers an event emitted to payroll-engine-nl (via RabbitMQ event bus). Payroll processes these events asynchronously and incorporates bijtelling, loon-in-natura, and eindheffing adjustments into the next payroll run.

### REQ-006-001: Asset Assignment Event

**GIVEN** a lease_auto is assigned to an employee on 2026-04-15  
**WITH** calculated bijtelling_maand = €390  
**WHEN** the AssetAssignment creation is finalized  
**THEN** an event is emitted:
```json
{
  "type": "asset_assigned",
  "asset_id": "a0002-uuid",
  "employee_id": "emp-maria-uuid",
  "asset_type": "lease_auto",
  "assignment_date": "2026-04-15",
  "bijtelling_monthly": 390.00,
  "effect_start_month": "2026-04"
}
```

**AND** payroll-engine-nl processes the event:
1. Queues a loon-in-natura adjustment for employee's payroll
2. For April 2026: pro-rata bijtelling = €390 × (16/30 days) = €208
3. For May 2026 onward: full €390 per month
4. Notifications sent to payroll admin: "Bijtelling added for Maria Hernández; €208 in April, €390 starting May"

### REQ-006-002: Asset Return Event

**GIVEN** a lease_auto is returned on 2026-05-20  
**WHEN** the AssetAssignment.inname_datum is set and confirmed  
**THEN** an event is emitted:
```json
{
  "type": "asset_returned",
  "asset_id": "a0002-uuid",
  "employee_id": "emp-maria-uuid",
  "asset_type": "lease_auto",
  "return_date": "2026-05-20",
  "condition": "goed",
  "bijtelling_monthly": null,
  "effect_end_month": "2026-05"
}
```

**AND** payroll-engine-nl:
1. Ceases bijtelling charges from June 2026 onward
2. For May 2026 (return mid-month): bijtelling charged in full (no pro-rata return reduction for simplicity; org policy configurable)
3. Notifications: "Lease car returned; bijtelling removed starting June"

### REQ-006-003: Bijtelling Update Event (Year Change)

**GIVEN** a lease_auto's bijtelling is recalculated due to 2026 staffel update  
**FROM** €250/month → €320/month  
**WHEN** the daily batch updates LeaseCarTaxRecord and creates AssetHistoryEntry  
**THEN** an event is emitted:
```json
{
  "type": "lease_car_bijtelling_update",
  "asset_id": "a0002-uuid",
  "employee_id": "emp-maria-uuid",
  "old_bijtelling_monthly": 250.00,
  "new_bijtelling_monthly": 320.00,
  "effective_month": "2026-01",
  "reason": "fiscal_staffel_2026"
}
```

**AND** payroll-engine-nl:
1. Updates the loon-in-natura amount in the active payroll period
2. Adjusts January payroll: new bijtelling = €320
3. Notifications: "Bijtelling updated for lease car (Excel model 3): €250 → €320/month effective January"

### REQ-006-004: Leasefiets Within Working Costs Regulation

**GIVEN** a leasefiets (€2.400 purchase price) is assigned to an employee  
**WHEN** the asset_assigned event is emitted  
**THEN** the event includes:
```json
{
  "type": "asset_assigned",
  "asset_id": "a0003-uuid",
  "employee_id": "emp-koen-uuid",
  "asset_type": "leasefiets",
  "purchase_price": 2400.00,
  "employer_subsidy_implied": true,
  "working_costs_regulation_applicable": true
}
```

**AND** payroll-engine-nl:
1. Determines employer's working-costs-regulation threshold: 1.92% of first €400.000 cumulative salary = max €7.680/year
2. Checks if this asset + other benefits fit within threshold
3. If within: no eindheffing charge (fringe benefit exempt)
4. If exceeded: triggers eindheffing on amount exceeding threshold
5. Notifications sent to HR/Finance: "Leasefiets assigned; working-costs regulation check: within threshold / WARNING: exceeds threshold"

---

## REQ-007: Asset History & Audit Trail

**Title**: Asset-geschiedenis per werknemer en globale AssetHistoryEntry audit log

**Description**: The employee detail page includes an "Assets" tab showing all active and historical asset assignments. Each asset has a history timeline. The AssetHistoryEntry table (append-only) provides the audit trail for compliance and GDPR.

### REQ-007-001: Employee Assets Tab

**GIVEN** I open the werknemer-detail for "Jan de Vries"  
**WHEN** I click the "Assets" tab  
**THEN** the page displays:

**Active Assets** (section):
- Laptop Dell XPS 15 | SerNr: DE50224X9Z | Assigned 2024-09-01 | Value: €1.452 (incl. VAT)
- Lease Car Tesla 3 | Reg: TR-92-KN | Assigned 2025-03-15 | Bijtelling: €375/month

**Historical Assets** (section):
- iPhone 15 Pro Max | SerNr: 35 921 308 476 325 | Issued 2024-11-05, Returned 2025-01-15 (beschadigd)
- Lease Fiets Gazelle | SerNr: GAZELLE-2024-04891 | Issued 2024-03-20, Returned 2025-01-10 (goed)
- [older assets...]

**AND** each asset row is clickable to open Asset detail

### REQ-007-002: Asset Detail History Timeline

**GIVEN** I open Asset detail for the laptop  
**WHEN** I scroll to the "Geschiedenis" section  
**THEN** a chronological timeline displays:

```
2026-05-20 10:30 | Notitie toegevoegd
  "Scherm krast; nog werkfunctie OK"
  — Added by: Jan de Vries (employee)

2026-05-15 14:15 | Reparatie gestart
  "Scherm vervangen onder garantie"
  — Added by: asset_manager@hrmq.nl

2024-09-01 10:15 | Uitgifte
  Assigned to: Jan de Vries
  Condition: Nieuw
  — Executed by: hr_admin@hrmq.nl
```

**AND** each entry includes:
- Timestamp (event_at)
- Event type icon (uitgifte = 📤, inname = 📥, reparatie = 🔧, etc.)
- Description (generated from event_type + previous_value/new_value)
- Actor name (user who created the entry)
- Optional notes

### REQ-007-003: Asset History Export (GDPR Subject Access Request)

**GIVEN** an employee requests a GDPR subject-access export  
**WHEN** HR Admin runs the export for that employee  
**THEN** the system generates a CSV/JSON export:

```json
{
  "employee_id": "emp-jan-uuid",
  "export_date": "2026-05-22",
  "assets": [
    {
      "asset_id": "a0001-uuid",
      "type": "laptop",
      "merk_en_model": "Dell XPS 15 9530",
      "history": [
        {
          "event_at": "2024-09-01T10:15:00Z",
          "event_type": "uitgifte",
          "actor": "hr_admin@hrmq.nl",
          "note": "Eerste uitgifte Dell laptop"
        },
        ...
      ]
    }
  ]
}
```

**AND** sensitive fields (leverancier contact, price) may be redacted based on org policy  
**AND** the export includes only events where this employee is mentioned (employee_id in AssetHistoryEntry)

---

## REQ-008: Depreciation & End-of-Life Tracking

**Title**: Lineaire afschrijving en signalering einde-afschrijvingstermijn

**Description**: The system tracks asset booked value using linear depreciation from purchase date over the specified term. When an asset reaches full depreciation, a notification is sent and an end-of-life recommendation is displayed.

### REQ-008-001: Booked Value Calculation

**GIVEN** an Asset:
- aankoopdatum: 2023-04-01
- aankoopprijs_incl_btw: €1.800
- afschrijvingstermijn_maanden: 36
- today: 2026-04-01 (36 months later, 100% depreciated)

**WHEN** the Asset detail page is opened  
**THEN** the system calculates:
1. Original net cost: €1.800 / 1.21 = €1.487,60 (remove VAT)
2. Monthly depreciation: €1.487,60 / 36 = €41,32
3. Months elapsed: 36
4. Total depreciation: €41,32 × 36 = €1.487,60
5. Booked value: €1.487,60 - €1.487,60 = **€0**

**AND** the page displays:
- Booked Value: **€0**
- Status banner (yellow/red): "Volledig afgeschreven op 2026-04-01; overweeg vervanging of verkoop"
- Recommendation options: [Vervangen] [Verkopen aan werknemer] [Archiveren]

### REQ-008-002: End-of-Life Notification

**GIVEN** daily batch runs at 02:00 UTC  
**WHEN** an Asset reaches afschrijvingstermijn expiration (aankoopdatum + afschrijvingstermijn_maanden ≤ today)  
**THEN** the system:
1. Identifies the owner of the asset (asset_manager role, or asset's created_by user)
2. Creates a Nextcloud Notification: "Asset end-of-life: Dell XPS 15 (DE50224X9Z) reached end of depreciation term on 2026-04-01"
3. Notification links to Asset detail page
4. Email is sent (if configured): "Please review asset for replacement or disposal"

### REQ-008-003: Depreciation Schedule Report

**GIVEN** an HR Admin or auditor opens Reports → "Afschrijving"  
**WHEN** filters are applied (date range, asset type, status)  
**THEN** a table is displayed:

| Asset | Type | Purchase Date | Cost (incl. VAT) | Term (mo) | Monthly | Booked Value (2026-05-22) | End Date | Status |
|-------|------|---|---|---|---|---|---|---|
| Dell XPS 15 | Laptop | 2024-09-01 | €1.452 | 36 | €40,33 | €1.011,92 | 2027-09-01 | Активно |
| Gazelle Fiets | Leasefiets | 2024-03-15 | €2.178 | 36 | €60,50 | €1.331,25 | 2027-03-15 | Историческое |

**AND** export options are provided (CSV, PDF, Excel)

---

## REQ-009: Bulk Import & Barcode Scan

**Title**: Bulk-import via CSV en barcode/QR-scan voor asset-uitgifte

**Description**: For MKB businesses with legacy Excel asset lists, bulk import is available with validation preview. Barcode/QR scanning is supported on mobile for quick asset lookup during issuance.

### REQ-009-001: CSV Bulk Import Workflow

**GIVEN** an HR Admin has a CSV file with 80 existing assets:
```
type,serienummer,merk_en_model,leverancier_name,aankoopdatum,aankoopprijs_excl_btw,aankoopprijs_incl_btw,afschrijvingstermijn_maanden
laptop,DE50224X9Z,Dell XPS 15,Dell,2024-09-01,1200.00,1452.00,36
laptop,APPLE-MBP-2024,MacBook Pro 16,Apple,2024-08-15,2500.00,3025.00,36
...
```

**WHEN** HR Admin opens Assets → "Import" and uploads the CSV  
**THEN** the system:
1. Parses CSV rows
2. Validates each row:
   - type ∈ enum list
   - serienummer non-empty for physical assets
   - serienummer not already in DB
   - dates are valid ISO format
   - prices are numeric EUR
   - leverancier_name is resolved to OpenRegister Organisation
3. Generates a preview table with any errors highlighted:

```
Row | Type | Serienummer | Status | Error
1   | laptop | DE50224X9Z | ✓ OK | -
2   | laptop | APPLE-MBP-2024 | ✓ OK | -
3   | telefoon | [empty] | ✗ ERROR | Serienummer required for type telefoon
4   | lease_auto | VIN123 | ⚠ WARNING | Leasemaatschappij not found; must be added manually
```

**AND** displays: "Ready to import 78 assets; 1 error, 1 warning"  
**AND** "Import" button is disabled until all errors are resolved

### REQ-009-002: Atomic Import Execution

**GIVEN** preview shows all valid rows  
**WHEN** HR Admin clicks "Bevestig Import"  
**THEN** the system:
1. Wraps all inserts in a DB transaction
2. Inserts all Asset rows
3. If any constraint violation occurs during insert: entire transaction rolls back
4. On success: all 78 assets are created, success message shows count + summary
5. On failure: rollback + error message with problematic row(s) highlighted

### REQ-009-003: Barcode/QR Scan for Asset Issuance

**GIVEN** HR Admin is on mobile device (iOS/Android) and opens Asset issuance page  
**WHEN** the page loads  
**THEN** a barcode-icon button appears in the toolbar

**GIVEN** HR Admin taps the barcode icon  
**WHEN** camera permission is granted  
**THEN** the mobile camera opens with QR-scan overlay

**GIVEN** a physical asset has a printed QR code encoding its Asset UUID  
**WHEN** HR Admin scans the code  
**THEN** the system:
1. Decodes UUID from QR data
2. Queries Asset by id
3. Auto-populates the asset field in the issuance form
4. Closes camera and displays Asset details (type, serial, model)
5. Proceeds to employee selection step

**GIVEN** the scanned code does not decode to a valid Asset UUID  
**WHEN** the scan is attempted  
**THEN** an error appears: "Asset niet herkend; check QR-code kwaliteit"  
**AND** the camera remains open for retry

---

## REQ-010: Access Control & GDPR

**Title**: Role-based access control en GDPR data retention

**Description**: Asset data is sensitive (kenteken, asset location, financial value) and subject to GDPR. Access is restricted by role, and historical records are anonymized after 2 years of employee departure.

### REQ-010-001: Role-Based View Restrictions

**GIVEN** I am logged in as `employee` (no hr or asset roles)  
**WHEN** I open my own werknemer-detail and click the "Assets" tab  
**THEN** I see:
- Laptop Dell XPS 15 | Assigned since 2024-09-01
- Lease Car Tesla | Assigned since 2025-03-15
- (historical items)

**AND** I do NOT see:
- Purchase price or cost
- Leverancier company details
- Bijtelling amounts
- Serial numbers (except for identification)
- Depreciation booked value
- Lease contract numbers or dates

**GIVEN** I am logged in as `asset_manager` or `hr_admin`  
**WHEN** I open any employee's Assets tab or Asset detail  
**THEN** I see all fields (full inventory view)

**GIVEN** I am logged in as `employee`  
**WHEN** I attempt to navigate directly to `/assets` (global assets list)  
**THEN** access is denied: 403 Forbidden  
**AND** redirect to home page with message: "Geen toegang tot asset-inventaris"

### REQ-010-002: Damage Report Submission (Employee Self-Service)

**GIVEN** I am an employee and my assigned laptop has a cracked screen  
**WHEN** I open my Assets tab and click on the laptop  
**THEN** an "Report Damage" button appears

**GIVEN** I click "Report Damage"  
**WHEN** a modal opens with:
- Damage description (text area)
- Photo upload (optional, max 5 MB, PNG/JPG)
- Submit button

**AND** I describe the damage and optionally upload a photo  
**THEN** upon submit:
- A note is added to AssetHistoryEntry: event_type = `damage_report`, note = description
- Photo is stored with asset_id foreign key
- asset_manager is notified: "Damage reported for Dell XPS 15; awaiting review"
- Employee sees confirmation: "Report submitted; asset_manager will review"

### REQ-010-003: GDPR Retention & Anonymization

**GIVEN** an employee exits employment on 2024-01-15  
**WHEN** 2 years have elapsed (today = 2026-01-16)  
**AND** the daily GDPR batch job runs  
**THEN** the system:
1. Queries AssetHistoryEntry rows where:
   - employee_id corresponds to departed employee
   - event_at < 2026-01-16 (older than 2 years from today)
2. For each row:
   - Replaces employee_id with a pseudo-ID (hash: GDPR-[first 8 chars of SHA-256(employee_id)])
   - Retains all other fields (audit_trail still queryable by asset_id, not by employee)
   - No deletion (fiscal/audit trail preserved for 7 years minimum)
3. Creates a GDPR anonymization log entry (audit)

**GIVEN** the departed employee requests a subject-access export after 2 years  
**WHEN** HR Admin runs the export  
**THEN** the system:
1. Queries AssetHistoryEntry by original employee_id (before anonymization)
2. Returns events from before-anonymization period only (if available)
3. For post-anonymization period, informs: "Records older than 2 years have been anonymized per GDPR"

### REQ-010-004: Auditor Read-Only Access

**GIVEN** I am logged in as `auditor` (year-end compliance review)  
**WHEN** I open the Reports section  
**THEN** I see:
- Bijtelling Report: all lease-cars, current percentages, staffel applied per date
- Depreciation Report: all assets, booked values, cost basis
- Asset History: filterable by date range, employee name, asset type

**AND** all reports are read-only; no edit buttons appear  
**AND** exports (CSV, PDF) are available for audit documentation  
**AND** I cannot access employee personal data beyond asset assignment dates

---

## Cross-Functional Requirements

### REQ-011: Database Constraints & Indexes

**Title**: Schema integrity and performance optimization

**Description**: Database tables enforce referential integrity, uniqueness, and sorting constraints.

**Constraints**:
- Asset (serienummer, type) unique constraint
- AssetAssignment: only one active (inname_datum IS NULL) per asset_id
- LeaseCarTaxRecord: 1:1 with Asset (type=lease_auto)
- AssetHistoryEntry: append-only trigger (no updates)
- Foreign keys: asset_id → Asset, employee_id → Employee, leverancier_id → Organisation

**Indexes**:
- (asset_id, inname_datum) — for quick lookup of active assignments
- (employee_id, inname_datum) — for employee asset list
- (status, created_at) — for depot inventory reporting
- (event_at) — for history timeline pagination

---

## Test Scenarios

### Test Suite: Asset Registration
- [ ] Create asset with all required fields; verify DB insert
- [ ] Omit serienummer for physical asset; expect validation error
- [ ] Create software_license without serienummer; expect success
- [ ] Attempt duplicate serienummer within type; expect error
- [ ] Create lease_auto with all lease fields; verify LeaseCarTaxRecord created
- [ ] Update asset leverancier; verify openregister lookup

### Test Suite: Asset Assignment & Return
- [ ] Assign asset to employee; verify status transition, event emission
- [ ] Attempt double-assign; expect error
- [ ] Return asset with goed condition; verify status=ingenomen
- [ ] Return asset with beschadigd; verify status=in_reparatie, notification sent
- [ ] Return asset with vermist; verify status=vermist, no future assignments allowed
- [ ] Backdate assignment; verify event_at reflects backdated timestamp

### Test Suite: Bijtelling Calculation
- [ ] Calculate bijtelling for electric car ≤€30k; verify percentage, amount
- [ ] Calculate bijtelling for car €60k (multi-bracket); verify split calculation
- [ ] Apply 60-month boundary; verify overgangsrecht 35% applied
- [ ] Apply year-boundary staffel change; verify new percentage, no double-update
- [ ] Privé_gebruik=none; verify €0 bijtelling, audit note logged
- [ ] Staffel update event emission; verify payroll-engine-nl receives event

### Test Suite: GDPR & Access Control
- [ ] Employee views own assets; verify sensitive fields hidden
- [ ] Employee attempts global assets list; verify 403 Forbidden
- [ ] Auditor reads reports; verify no edit buttons, exports work
- [ ] GDPR anonymization batch; verify employee_id replaced after 2 years
- [ ] Departed employee SAR export; verify pre-anonymization records included

---

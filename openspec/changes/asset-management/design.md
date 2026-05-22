---
status: draft
version: 1.0
date: 2026-05-22
---

# Design: Asset Management voor HRMQ

## Data Model Overview

The asset-management system centers on five core entities with an append-only history log.

### Entity: Asset

```
Asset {
  id: UUID (primary key)
  type: enum[laptop, telefoon, lease_auto, leasefiets, monitor, bureau, bureaustoel, headset, software_license, overig]
  serienummer: string (required for physical assets; optional for software)
  merk_en_model: string
  leverancier_id: UUID (reference to OpenRegister Organisation, not local duplicate)
  aankoopdatum: date (required)
  aankoopprijs_excl_btw: decimal (EUR, 2 places)
  aankoopprijs_incl_btw: decimal (EUR, 2 places)
  afschrijvingstermijn_maanden: integer (default by type: laptop 36, telefoon 24, lease_auto 60, leasefiets 36, monitor 36, bureau 120, bureaustoel 84, headset 24, software_license 36, overig 60)
  status: enum[uitgeleend, ingenomen, in_reparatie, afgeschreven, vermist, verkocht]
  eigendoms_type: enum[eigendom, operationele_lease, financiele_lease, huur]
  leasemaatschappij_id: UUID (reference to OpenRegister Organisation, required if eigendoms_type is *lease)
  lease_contract_nummer: string
  lease_einddatum: date
  maandbedrag_lease_excl_btw: decimal (EUR, only for operationele_lease and financiele_lease)
  lease_kilometers_per_jaar: integer (only for lease_auto)
  created_at: timestamp
  created_by: UUID (reference to User)
  updated_at: timestamp
  updated_by: UUID (reference to User)
}
```

**Defaults & Constraints**:
- `status` defaults to `ingenomen` upon creation (awaiting first assignment)
- If `eigendoms_type` is *lease, then `leasemaatschappij_id`, `lease_contract_nummer`, `lease_einddatum` are required
- `lease_kilometers_per_jaar` is numeric-only and ≥0
- `serienummer` is unique per asset type (within the organization), enforced at DB level
- If `type` is software_license, `serienummer` may be null

### Entity: AssetAssignment

```
AssetAssignment {
  id: UUID (primary key)
  asset_id: UUID (foreign key → Asset)
  employee_id: UUID (foreign key → Employee from employee-master)
  uitgifte_datum: date (required; defaults to today)
  uitgifte_door: UUID (reference to User, required)
  inname_datum: date (nullable; remains null while asset is actively out)
  inname_door: UUID (reference to User, nullable)
  staat_bij_uitgifte: enum[nieuw, goed, gebruikt, beschadigd] (required)
  staat_bij_inname: enum[nieuw, goed, gebruikt, beschadigd, vermist] (nullable until inname)
  opmerking_bij_inname: text (nullable; damage notes, etc.)
  created_at: timestamp
  updated_at: timestamp
}
```

**Constraints**:
- Only one AssetAssignment per Asset can have `inname_datum = null` (active assignment)
- Transition: `uitgifte_datum` < `inname_datum` (if inname_datum is not null)
- If Asset `status` is `vermist` or `afgeschreven`, no new AssetAssignment can be created

### Entity: LeaseCarTaxRecord

```
LeaseCarTaxRecord {
  id: UUID (primary key)
  asset_id: UUID (foreign key → Asset; must have type=lease_auto)
  kenteken: string (required; Dutch license plate format, enforced via regex)
  datum_eerste_tenaamstelling: date (required; DET — determinant for 60-month cycle)
  cataloguswaarde: decimal (EUR; the value from NHTV catalog for bijtelling calc)
  brandstoftype: enum[benzine, diesel, elektrisch, hybride_plug_in, waterstof] (required)
  co2_uitstoot_g_per_km: integer (grams/km; required for benzine/diesel, ignored for elektrisch)
  bijtellingspercentage_huidig: decimal (0-100, 2 places; e.g., 16.00, 22.00, 35.00)
  bijtelling_einddatum_termijn: date (calculated: DET + 60 months; auto-set via trigger)
  privé_gebruik_verklaring: enum[none, beperkt_500km, vol] (required; no default; user must choose at assignment)
  created_at: timestamp
  created_by: UUID
  updated_at: timestamp
  updated_by: UUID
}
```

**Constraints**:
- Exactly one LeaseCarTaxRecord per Asset with type=lease_auto (1:1 relationship)
- `datum_eerste_tenaamstelling` is immutable after creation
- `bijtelling_einddatum_termijn` is auto-computed and immutable
- `co2_uitstoot_g_per_km` is required for brandstoftype in [benzine, diesel]; ignored and null for elektrisch/waterstof
- `privé_gebruik_verklaring` triggers different calculation logic (none → 0 maand-bijtelling if rittenstaat <500km; otherwise bijtelling applies)

### Entity: AssetHistoryEntry

```
AssetHistoryEntry {
  id: UUID (primary key)
  asset_id: UUID (foreign key → Asset)
  employee_id: UUID (foreign key → Employee, nullable; null for depot-only mutations)
  event_type: enum[uitgifte, inname, status_wijziging, reparatie_melding, bijtelling_correctie, bijtelling_staffelovergang, einde_afschrijving, overig]
  event_at: timestamp (required)
  actor_id: UUID (reference to User, required)
  note: text (optional; e.g., "scherm gebarsten", "auto stalt niet aan")
  previous_value: json (nullable; before-state of affected field)
  new_value: json (nullable; after-state of affected field)
  created_at: timestamp (auto-set)
}
```

**Constraints**:
- AssetHistoryEntry is append-only (no updates, only inserts)
- Rows are created as side-effects of mutations (create assignment, close assignment, staffel change, etc.)
- `event_at` may differ from `created_at` (allows backdating historical corrections)
- `previous_value` and `new_value` store the affected field(s) as JSON for audit trail

### Entity: AssetCategory

```
AssetCategory {
  id: UUID (primary key)
  name: string (required; e.g., "iPhones 14 Pro", "MacBooks M3 14-inch")
  description: text (optional)
  organization_id: UUID (tenant scoping; single org for MVP)
  created_at: timestamp
  created_by: UUID
}
```

**Constraints**:
- Optional; for grouping and procurement analytics; not fiscally relevant
- No direct FK from Asset to AssetCategory (many-to-many via junction table `asset_category_members` if needed; for MVP, scope to simple 1:many)

---

## Seed Data

### Sample Assets

#### Asset 1: Laptop (Eigendom)
```
{
  id: "a0001-uuid",
  type: "laptop",
  serienummer: "DE50224X9Z",
  merk_en_model: "Dell XPS 15 9530",
  leverancier_id: "org-dell-uuid",
  aankoopdatum: "2024-09-01",
  aankoopprijs_excl_btw: 1200.00,
  aankoopprijs_incl_btw: 1452.00,
  afschrijvingstermijn_maanden: 36,
  status: "uitgeleend",
  eigendoms_type: "eigendom",
  created_at: "2024-09-01T10:00:00Z",
  created_by: "user-hr-admin-uuid"
}
```

#### Asset 2: Lease Car (Operationele Lease)
```
{
  id: "a0002-uuid",
  type: "lease_auto",
  serienummer: "VIN123ABC456DEF789",
  merk_en_model: "Tesla Model 3 Standard Range",
  leverancier_id: "org-athlon-uuid",
  aankoopdatum: "2024-06-01",
  aankoopprijs_excl_btw: 0.00,
  aankoopprijs_incl_btw: 0.00,
  afschrijvingstermijn_maanden: 60,
  status: "uitgeleend",
  eigendoms_type: "operationele_lease",
  leasemaatschappij_id: "org-athlon-uuid",
  lease_contract_nummer: "ATH-2024-0012345",
  lease_einddatum: "2027-06-01",
  maandbedrag_lease_excl_btw: 320.00,
  lease_kilometers_per_jaar: 25000,
  created_at: "2024-06-01T10:00:00Z",
  created_by: "user-hr-admin-uuid"
}
```

#### Asset 3: Lease Fiets
```
{
  id: "a0003-uuid",
  type: "leasefiets",
  serienummer: "GAZELLE-2024-04891",
  merk_en_model: "Gazelle Ultimate C380",
  leverancier_id: "org-lease-fietsen-uuid",
  aankoopdatum: "2024-03-15",
  aankoopprijs_excl_btw: 1800.00,
  aankoopprijs_incl_btw: 2178.00,
  afschrijvingstermijn_maanden: 36,
  status: "ingenomen",
  eigendoms_type: "operationele_lease",
  leasemaatschappij_id: "org-lease-fietsen-uuid",
  lease_contract_nummer: "LF-2024-00567",
  lease_einddatum: "2027-03-15",
  maandbedrag_lease_excl_btw: 45.00,
  created_at: "2024-03-15T10:00:00Z",
  created_by: "user-hr-admin-uuid"
}
```

#### Asset 4: Telefoon
```
{
  id: "a0004-uuid",
  type: "telefoon",
  serienummer: "35 921 308 476 325",
  merk_en_model: "iPhone 15 Pro Max",
  leverancier_id: "org-knp-uuid",
  aankoopdatum: "2024-11-01",
  aankoopprijs_excl_btw: 1199.00,
  aankoopprijs_incl_btw: 1450.79,
  afschrijvingstermijn_maanden: 24,
  status: "ingenomen",
  eigendoms_type: "eigendom",
  created_at: "2024-11-01T10:00:00Z",
  created_by: "user-hr-admin-uuid"
}
```

#### Asset 5: Bureaustoel
```
{
  id: "a0005-uuid",
  type: "bureaustoel",
  serienummer: null,
  merk_en_model: "Herman Miller Aeron",
  leverancier_id: "org-ergon-uuid",
  aankoopdatum: "2023-06-10",
  aankoopprijs_excl_btw: 1400.00,
  aankoopprijs_incl_btw: 1694.00,
  afschrijvingstermijn_maanden: 84,
  status: "uitgeleend",
  eigendoms_type: "eigendom",
  created_at: "2023-06-10T10:00:00Z",
  created_by: "user-hr-admin-uuid"
}
```

### Sample AssetAssignments

#### Assignment 1: Laptop to Jan de Vries (Active)
```
{
  id: "aa0001-uuid",
  asset_id: "a0001-uuid",
  employee_id: "emp-jan-vries-uuid",
  uitgifte_datum: "2024-09-01",
  uitgifte_door: "user-hr-admin-uuid",
  inname_datum: null,
  inname_door: null,
  staat_bij_uitgifte: "nieuw",
  staat_bij_inname: null,
  created_at: "2024-09-01T10:15:00Z",
  updated_at: "2024-09-01T10:15:00Z"
}
```

#### Assignment 2: Lease Car to Maria Hernández (Active)
```
{
  id: "aa0002-uuid",
  asset_id: "a0002-uuid",
  employee_id: "emp-maria-hernandez-uuid",
  uitgifte_datum: "2025-03-15",
  uitgifte_door: "user-hr-admin-uuid",
  inname_datum: null,
  inname_door: null,
  staat_bij_uitgifte: "nieuw",
  staat_bij_inname: null,
  created_at: "2025-03-15T10:00:00Z",
  updated_at: "2025-03-15T10:00:00Z"
}
```

#### Assignment 3: Lease Fiets to Koen Jansen (Closed)
```
{
  id: "aa0003-uuid",
  asset_id: "a0003-uuid",
  employee_id: "emp-koen-jansen-uuid",
  uitgifte_datum: "2024-03-20",
  uitgifte_door: "user-hr-admin-uuid",
  inname_datum: "2025-01-10",
  inname_door: "user-office-manager-uuid",
  staat_bij_uitgifte: "nieuw",
  staat_bij_inname: "goed",
  created_at: "2024-03-20T10:00:00Z",
  updated_at: "2025-01-10T14:30:00Z"
}
```

#### Assignment 4: iPhone to Peter Müller (Closed)
```
{
  id: "aa0004-uuid",
  asset_id: "a0004-uuid",
  employee_id: "emp-peter-muller-uuid",
  uitgifte_datum: "2024-11-05",
  uitgifte_door: "user-hr-admin-uuid",
  inname_datum: "2025-01-15",
  inname_door: "user-hr-admin-uuid",
  staat_bij_uitgifte: "nieuw",
  staat_bij_inname: "beschadigd",
  opmerking_bij_inname: "schermcrack onderkant",
  created_at: "2024-11-05T10:00:00Z",
  updated_at: "2025-01-15T09:45:00Z"
}
```

### Sample LeaseCarTaxRecord

#### Tax Record: Tesla Model 3 (Maria Hernández)
```
{
  id: "lctr-0001-uuid",
  asset_id: "a0002-uuid",
  kenteken: "TR-92-KN",
  datum_eerste_tenaamstelling: "2024-06-01",
  cataloguswaarde: 45000.00,
  brandstoftype: "elektrisch",
  co2_uitstoot_g_per_km: null,
  bijtellingspercentage_huidig: 16.00,
  bijtelling_einddatum_termijn: "2029-06-01",
  privé_gebruik_verklaring: "vol",
  created_at: "2024-06-01T11:00:00Z",
  created_by: "user-hr-admin-uuid"
}
```

**Bijtelling Calculation for 2026 (January)**:
- Datum evaluatie: 2026-01-15
- DET + 60mo = 2029-06-01, thus still within 60-month term
- Staffel 2026 voor elektrisch, ≤€30.000: 16%
- Cataloguswaarde: €45.000
  - Eerste €30.000 @ 16% = €4.800 jaarlijks
  - Resterende €15.000 @ 22% = €3.300 jaarlijks
  - Totaal jaarlijks: €8.100
  - Maandelijks (volledige privé-gebruik): €675
- Privé_gebruik_verklaring: "vol" → breng volledige maandelijks bedrag in rekening

### Sample AssetHistoryEntries

#### History 1: Laptop Uitgifte
```
{
  id: "ah0001-uuid",
  asset_id: "a0001-uuid",
  employee_id: "emp-jan-vries-uuid",
  event_type: "uitgifte",
  event_at: "2024-09-01T10:15:00Z",
  actor_id: "user-hr-admin-uuid",
  note: "Eerste uitgifte Dell laptop",
  previous_value: { "status": "ingenomen" },
  new_value: { "status": "uitgeleend" },
  created_at: "2024-09-01T10:15:00Z"
}
```

#### History 2: Lease Car Staffel Override
```
{
  id: "ah0002-uuid",
  asset_id: "a0002-uuid",
  employee_id: "emp-maria-hernandez-uuid",
  event_type: "bijtelling_staffelovergang",
  event_at: "2026-01-01T00:00:00Z",
  actor_id: "system-batch",
  note: "Staffelovergang fiscale jaar 2026; elektrisch auto",
  previous_value: { "bijtellingspercentage": 16.00 },
  new_value: { "bijtellingspercentage": 16.00 },
  created_at: "2026-01-02T02:00:00Z"
}
```

#### History 3: iPhone Inname met Schade
```
{
  id: "ah0003-uuid",
  asset_id: "a0004-uuid",
  employee_id: "emp-peter-muller-uuid",
  event_type: "inname",
  event_at: "2025-01-15T09:45:00Z",
  actor_id: "user-hr-admin-uuid",
  note: "Inname beschadigde iPhone; schermcrack onderste hoek, nog werkend; naar reparatie",
  previous_value: { "status": "uitgeleend", "staat_bij_inname": null },
  new_value: { "status": "in_reparatie", "staat_bij_inname": "beschadigd" },
  created_at: "2025-01-15T09:45:00Z"
}
```

---

## User Flows

### Flow 1: Asset Registration (HR Admin)

1. HR Admin opens HRMQ → Assets → "Nieuw Asset"
2. Selects type (dropdown)
   - If type = lease_auto: additional fields for leasemaatschappij, contract, etc.
   - If type = software_license: serienummer becomes optional
3. Fills required fields: serienummer, merk_en_model, aankoopdatum, aankoopprijs (excl. & incl. BTW)
4. Leverancier lookup (autocomplete, pulls from OpenRegister Organisation)
5. Afschrijvingstermijn auto-populates based on type; HR Admin may override if needed
6. Clicks "Opslaan"
   - Validation: serienummer required for physical types
   - If lease: all lease fields required
   - DB: Asset created with status=`ingenomen` (awaiting first assignment)
   - Notification: Asset created successfully

### Flow 2: Asset Assignment to Employee (HR Admin)

1. HR Admin opens Asset detail → "Uitgifte"
2. Searches employee (by name, employee ID)
3. Selects state_bij_uitgifte (nieuw/goed/gebruikt/beschadigd)
4. Clicks "Uitgifte bevestigen"
   - Validation: Asset status ≠ vermist, afgeschreven, verkocht
   - Validation: No active AssetAssignment exists for this Asset
   - DB: AssetAssignment created (uitgifte_datum=today, inname_datum=null)
   - DB: Asset.status = `uitgeleend`
   - DB: AssetHistoryEntry created (event_type=uitgifte)
   - If type=lease_auto: prompt user for privé_gebruik_verklaring (radio: none/beperkt_500km/vol)
     - On confirm: LeaseCarTaxRecord.privé_gebruik_verklaring = selected value
   - Event emitted to payroll-engine-nl: asset_assigned { asset_id, employee_id, type, bijtelling_monthly (if auto) }

### Flow 3: Asset Return & Damage Tracking (HR Admin)

1. HR Admin opens Asset detail → "Innemen"
2. Selects staat_bij_inname (goed/beschadigd/vermist)
3. If beschadigd or vermist: optional text note (damage description, location, etc.)
4. Clicks "Innemen bevestigen"
   - Validation: Active AssetAssignment exists
   - DB: AssetAssignment.inname_datum = today, staat_bij_inname = selected
   - DB: Asset.status = `in_reparatie` (if beschadigd) or `ingenomen` (if goed/vermist)
   - DB: AssetHistoryEntry created (event_type=inname, note=user text)
   - If beschadigd: notification to asset_manager (e.g., "iPhone beschadigd, reparatie initialiseren?")
   - Event emitted to payroll-engine-nl: asset_returned { asset_id, employee_id, type, bijtelling_monthly=null (if was lease auto) }

### Flow 4: Lease Car Privé-Gebruik Verklaring (Employee Self-Service or HR Admin)

1. Employee receives notification/portal link: "Privé-gebruik verklaring benodigd voor lease-auto"
2. Opens declaration form
3. Selects option (radio):
   - "Geen privé-gebruik" (none)
   - "Beperkt privé-gebruik ≤500 km/jaar" (beperkt_500km)
   - "Volledige privé-gebruik" (vol)
4. Clicks "Opslaan"
   - DB: LeaseCarTaxRecord.privé_gebruik_verklaring = selected
   - Bijtelling recalculation triggered
   - If none: bijtelling_monthly = €0 (assuming rittenstaat <500km audited by employer); note logged
   - If beperkt_500km: bijtelling_monthly = reduced % (TBD by tax expert; likely same as vol but with audit obligation)
   - If vol: bijtelling_monthly = full % × cataloguswaarde
   - Event emitted to payroll-engine-nl: lease_auto_tax_declaration { asset_id, privé_gebruik_verklaring, new_bijtelling_monthly }

### Flow 5: Offboarding Asset Checklist (HR Admin / Offboarding Wizard)

1. HR Admin initiates offboarding for employee (end-of-employment date set)
2. Offboarding Wizard → "Asset Check" step
3. System queries AssetAssignments where employee_id = target employee AND inname_datum = null
4. UI displays checklist:
   - ☐ Laptop (SerNr: DE50224X9Z) — open since 2024-09-01 — [Mark Returned] [Mark Missing]
   - ☐ Lease Car (Reg: TR-92-KN) — open since 2025-03-15 — [Mark Returned] [Mark Missing]
   - (etc.)
5. HR Admin marks each as returned (staat_bij_inname=goed) or missing (staat_bij_inname=vermist)
6. Upon final asset (all marked returned/missing), wizard unlocks next step (eind-afrekening)
   - Note: If asset marked vermist, financial liability may be calculated & deducted from final payout (configurable per org)
7. On wizard completion:
   - DB: All AssetAssignments closed (inname_datum set, Asset.status updated)
   - DB: AssetHistoryEntry created for each (event_type=inname, actor_id=wizard)
   - Events emitted to payroll-engine-nl: asset_returned for each

### Flow 6: Bijtelling Staffel Recalculation (Daily Batch)

1. Daily batch runs at 02:00 UTC
2. Queries all LeaseCarTaxRecords where:
   - branche.staffel_version_year = current year (NEW compared to previous run)
   - OR datum_eerste_tenaamstelling + 60mo ≤ today (term boundary reached)
3. For each record:
   - Look up fiscal staffel for current year, auto's brandstoftype, cataloguswaarde
   - Calc new percentage
   - If changed: DB: LeaseCarTaxRecord.bijtellingspercentage_huidig = new %, updated_at = now
   - Calc new maandbedrag based on privé_gebruik_verklaring
   - Event emitted to payroll-engine-nl: lease_car_bijtelling_update { asset_id, old_percentage, new_percentage, new_monthly_amount }
   - DB: AssetHistoryEntry created (event_type=bijtelling_staffelovergang, previous_value, new_value)
4. Notifications sent to HR admin: "Lease car bijtelling updated: 3 cars; 1 staffel change, 2 term-boundary resets"

---

## API Endpoints (High-level)

### Asset Management
- `POST /api/assets` — Create new asset
- `GET /api/assets/{id}` — Fetch asset detail + history
- `PATCH /api/assets/{id}` — Update asset (limited fields: merk_en_model, leverancier, status)
- `GET /api/assets` — List assets (with filters: status, type, employee_id, category)
- `DELETE /api/assets/{id}` — Soft-delete (cascade: remove AssetAssignments, mark as archiv)

### Asset Assignments
- `POST /api/assets/{id}/assignments` — Assign asset to employee (create AssetAssignment)
- `PATCH /api/assets/{id}/assignments/{aid}` — Return asset (close AssetAssignment, set inname_datum)
- `GET /api/assets/{id}/assignments` — Fetch assignment history for an asset

### Lease Car Tax
- `POST /api/assets/{id}/lease-car-tax` — Create LeaseCarTaxRecord on asset registration
- `GET /api/assets/{id}/lease-car-tax` — Fetch current lease-car tax record
- `PATCH /api/assets/{id}/lease-car-tax` — Update privé_gebruik_verklaring, kenteken, etc.

### Employee Self-Service
- `GET /api/employees/{id}/assets` — Fetch my assets (filtered for current employee)
- `POST /api/employees/{id}/assets/{aid}/damage-report` — Report damage (creates note in AssetHistoryEntry)
- `PATCH /api/employees/{id}/lease-cars/{lcid}/declare-usage` — Submit privé-gebruik declaration

### Reporting & Audit
- `GET /api/assets/report/depreciation` — Export asset depreciation schedule
- `GET /api/assets/report/bijtelling` — Export lease-car bijtelling schedule (for payroll audit)
- `GET /api/assets/history?employee_id={id}&from={date}&to={date}` — Fetch asset history (GDPR subject-access request export)

---

## Sequence Diagrams

### Scenario: Lease Car Assignment & Payroll Coupling

```
HR Admin                Asset-Management DB    Payroll-Engine-NL Event Bus
    |                           |                       |
    |--POST /assets/{id}/assign->|                       |
    |                    [create AssetAssignment]        |
    |                    [create AssetHistoryEntry]     |
    |<--201 {"aa_id": "aa001"}--|                       |
    |                           |                       |
    |  [prompt for privé_gebruik_verklaring]            |
    |  [user selects "vol"]     |                       |
    |                           |                       |
    |--PATCH lease-car-tax----->|                       |
    |  {"privé_gebruik":"vol"}  |                       |
    |                    [update LeaseCarTaxRecord]     |
    |                    [recalc bijtelling_monthly]    |
    |<--204 OK               --|                       |
    |                           |--emit asset_assigned-->|
    |                           |  {asset_id, emp_id,   |
    |                           |   type: lease_auto,   |
    |                           |   bijtelling: €675}   |
    |                           |                |       |
    |                           |         [queue event] |
    |                           |         [next payroll]|
    |                           |    [add loon-in-natura]|
```

---

## Access Control Model

### Roles & Permissions

| Role | Assets | AssetAssignments | LeaseCarTaxRecord | AssetHistory | Employee View |
|------|--------|------------------|-------------------|--------------|---------------|
| asset_manager | CRUD | CRU | RU | R | - |
| hr_admin | CRUD | CRUD | CRUD | R | CRUD (all) |
| employee | - | - | - | R (own) | R (own) |
| auditor | R | R | R | R | - |
| manager | - | - | - | - | R (team) |

**Details**:
- `asset_manager`: Can create/edit/delete assets, assign to employees, close assignments, view history
- `hr_admin`: Full access; performs bulk imports, offboarding checklists, staffel overrides
- `employee`: Can view own active assets (limited: no cost, no supplier, no tax details), report damage
- `auditor`: Read-only access to all assets and history; typically for year-end audit & compliance
- `manager`: Can view team members' active assets (for budget awareness); no edit rights

---

## Tech Stack (Recommended)

- **Backend**: Node.js + Express (or Go if performance needed)
- **Database**: PostgreSQL with JSON fields for AssetHistoryEntry; indexes on (asset_id, status), (employee_id, inname_datum)
- **Event Bus**: RabbitMQ (HRMQ existing infrastructure) for payroll coupling
- **Frontend**: Vue 3 + Vuetify (consistent with HRMQ existing stack)
- **Authentication**: OAuth2 / OIDC (via HRMQ existing auth)
- **Fiscal Staffel Data**: Versioned config table; updates managed via admin UI or batch script (Belastingdienst official publication → JSON → DB)

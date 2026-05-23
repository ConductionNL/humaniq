# loonbeslag-admin: Design

**Status:** draft  
**Created:** 2026-05-23  

## Information Architecture

**Placement:** SUB_PAGE under `Salarissen › Loonbeslagen`  
**Parent menu:** Salarissen (top-level)  
**Route:** `/pay/administratie/{id}/garnishments` or similar  
**Placement rationale (ADR-001):** Wage garnishment is a payroll workflow, not a standalone module. It lives alongside other monthly payroll concerns (loonruns, payslips, SEPA, 30%-regeling, IKB, audit-trail). No new top-level menu introduced.

## Data Model

All entities below inherit from hrmq's base patterns: soft deletes, audit-trail integration, multi-administratie scoping, encrypted storage for PII.

### `Beslag` (Garnishment Order)

Represents one court order or authority directive to garnish an employee's wage. Each employee-garnisher pair is one row; multiple garnishments on the same employee are separate rows.

| Field | Type | Constraints | Notes |
|-------|------|-----------|-------|
| `id` | UUID | PK | |
| `administratie_id` | UUID | FK to Administratie; immutable | Multi-tenant scoping |
| `medewerker_id` | UUID | FK to Medewerker; immutable | Employee being garnished |
| `beslaglegger_type` | Enum | lbio_alimentatie, cjib_boete, belastingdienst, deurwaarder_civiel, gemeente_terugvordering_bijstand, uwv_terugvordering, zorgverzekeraar, student_finance_duo | Determines legal precedence |
| `beslaglegger_naam` | String[255] | NOT NULL | Name of creditor |
| `beslaglegger_adres` | JSON | {straat, huisnummer, postcode, plaats} | Structured address for correspondence |
| `beslaglegger_iban` | String[34] | NOT NULL; validated IBAN | Where remittance is sent |
| `beslaglegger_kenmerk` | String[100] | Indexed | Reference number for remittance description (e.g. "LBIO-202604-12345") |
| `beslaglegger_bsn_kenmerk` | String[50] | Nullable | SSN reference (Belastingdienst, CJIB) |
| `deurwaarder_id` | String[20] | Nullable | KBvG registry number if bailiff-issued |
| `exploot_datum` | Date | NOT NULL; immutable | Court-order service date |
| `exploot_document_uri` | String[500] | Nullable | Link to scanned PDF in document-vault |
| `vorderingsbedrag_oorspronkelijk` | Decimal[12,2] | NOT NULL; immutable | Original debt amount |
| `vorderingsbedrag_resterend` | Decimal[12,2] | NOT NULL | Remaining debt after remittances |
| `rente_aanwas_per_maand` | Decimal[12,2] | Nullable | Monthly interest accrual (where applicable) |
| `preferentie` | Enum | preferent, concurrent | Legal priority (Belastingdienst=preferent; court orders=concurrent unless LBIO alimentatie) |
| `volgnummer_intern` | Integer | NOT NULL; unique per (administratie, medewerker) | Receipt order within administratie |
| `vanaf` | YearMonth | NOT NULL | First payroll period subject to garnishment |
| `tot` | YearMonth | Nullable | Last period, or null if ongoing |
| `status` | Enum | concept, actief, opgeschort, afgelost, ingetrokken, overgedragen | Workflow state |
| `created_at` | Timestamp | NOT NULL | |
| `created_by_id` | UUID | FK to Gebruiker | Who registered the order |
| `updated_at` | Timestamp | NOT NULL | |
| `updated_by_id` | UUID | FK to Gebruiker | Last modifier |
| `deleted_at` | Timestamp | Nullable | Soft delete for audit trail |

**Indexes:** `(administratie_id, medewerker_id, status)`, `(administratie_id, exploot_datum)`, `(beslaglegger_iban, exploot_datum)`.

---

### `BeslagvrijeVoet` (Legal Exemption Calculation)

Stores the computed exemption (portion of salary exempt from garnishment) for an employee in a given month. Recalculated when household/financial situation changes.

| Field | Type | Constraints | Notes |
|-------|------|-----------|-------|
| `id` | UUID | PK | |
| `administratie_id` | UUID | FK to Administratie | Multi-tenant scoping |
| `medewerker_id` | UUID | FK to Medewerker | |
| `peilmaand` | YearMonth | NOT NULL; unique per (medewerker, peilmaand) | Reference month for calculation |
| `bvv_bedrag` | Decimal[12,2] | NOT NULL | Computed exemption amount (€) |
| `bvv_methode` | Enum | wvbvv_standaard, handmatig_overruled_met_motivering | Calculation method |
| `handmatig_motivering` | Text | Nullable; required if bvv_methode = handmatig_... | Free-text override justification |
| `inkomen_grondslag` | Decimal[12,2] | NOT NULL | Net monthly income used in formula |
| `leefvorm` | Enum | alleenstaand, alleenstaande_ouder, gehuwd, samenwonend | Household status per Wvbvv |
| `aantal_kinderen_tlv` | Integer | NOT NULL; ≥0 | Dependent children count (for child allowance) |
| `woonkosten` | Decimal[12,2] | NOT NULL | Monthly rent or mortgage |
| `nominale_premie_zvw` | Decimal[12,2] | NOT NULL | Health-insurance premium (from master data or employee submission) |
| `bvv_brondocument_uri` | String[500] | Nullable | Link to employee-submitted proof (lease, care contract, etc.) |
| `vastgesteld_door_id` | UUID | FK to Gebruiker; nullable | HR-manager who approved override (if manual) |
| `vastgesteld_op` | Timestamp | Nullable | When override was approved |
| `created_at` | Timestamp | NOT NULL | |
| `updated_at` | Timestamp | NOT NULL | |

**Formula (Wvbvv 2021 simplified):**
```
BVV = base_amount(leefvorm) + child_supplement(aantal_kinderen_tlv) + rent_component(woonkosten) + care_component(nominale_premie_zvw)
```
Each component indexed annually; Wvbvv table lookup based on peilmaand.

**Indexes:** `(administratie_id, medewerker_id, peilmaand)`.

---

### `BeslagSamenloop` (Multi-Garnishment Allocation)

When an employee has multiple active garnishments, this entity tracks the monthly split of available remittance funds according to legal precedence.

| Field | Type | Constraints | Notes |
|-------|------|-----------|-------|
| `id` | UUID | PK | |
| `administratie_id` | UUID | FK to Administratie | |
| `medewerker_id` | UUID | FK to Medewerker | |
| `peilmaand` | YearMonth | NOT NULL; unique per (medewerker, peilmaand) | Reference month |
| `actieve_beslagen` | UUID[] | NOT NULL; ordered | Array of Beslag.id in precedence + registration order |
| `totaal_beschikbaar_voor_beslag` | Decimal[12,2] | NOT NULL | = netto_loon - bvv_bedrag |
| `verdeling_per_beslag` | JSONB | {beslag_id: bedrag, ...} | Allocation per garnishment |
| `methodiek` | Enum | preferent_eerst_dan_concurrent_naar_rato, strikt_chronologisch_oudst_eerst | Allocation logic per tenant config |
| `created_at` | Timestamp | NOT NULL | |
| `updated_at` | Timestamp | NOT NULL | |

**Example:**  
Employee netto €2000, BVV €1200, available €800.  
- Beslag A (LBIO, preferent): €600 requested → allocated €600.
- Beslag B (deurwaarder, concurrent): €400 requested → allocated €200.

**Indexes:** `(administratie_id, medewerker_id, peilmaand)`.

---

### `BeslagAfdracht` (Monthly Remittance)

One row per Beslag per payroll period, recording what was actually withheld and remitted.

| Field | Type | Constraints | Notes |
|-------|------|-----------|-------|
| `id` | UUID | PK | |
| `administratie_id` | UUID | FK to Administratie | |
| `loonrun_id` | UUID | FK to Loonrun | Which payroll run triggered this |
| `beslag_id` | UUID | FK to Beslag | Which garnishment |
| `medewerker_id` | UUID | FK to Medewerker | Denormalized for quick lookup |
| `periode_jaar` | Year | NOT NULL | Payroll period year |
| `periode_maand` | Month | NOT NULL; unique per (beslag_id, jaar, maand) | Payroll period month |
| `bedrag_ingehouden` | Decimal[12,2] | NOT NULL | Amount withheld from salary |
| `bedrag_afgedragen` | Decimal[12,2] | Nullable | Amount remitted to creditor (may differ due to rejected payment, future adjustment) |
| `afdrachtdatum` | Date | Nullable | Date payment was executed |
| `betalingsreferentie` | String[100] | Nullable | SEPA transaction ID or bank reference |
| `journaalpost_id` | UUID | Nullable | GL entry for "Garnishers payable" account |
| `status` | Enum | gepland, uitgevoerd, mislukt, teruggeboekt | Payment state |
| `status_opmerking` | Text | Nullable | Error message if mislukt (e.g. "IBAN invalid") |
| `created_at` | Timestamp | NOT NULL | |
| `updated_at` | Timestamp | NOT NULL | |

**Indexes:** `(administratie_id, loonrun_id)`, `(beslag_id, periode_jaar, periode_maand)`.

---

### `BeslagCorrespondentie` (Generated Correspondence)

Tracks the statutory letters sent to creditors and other parties.

| Field | Type | Constraints | Notes |
|-------|------|-----------|-------|
| `id` | UUID | PK | |
| `administratie_id` | UUID | FK to Administratie | |
| `beslag_id` | UUID | FK to Beslag | Which garnishment |
| `type` | Enum | acceptatieverklaring, derdenverklaring, maandelijkse_specificatie, eindverklaring, aansprakelijkheidsbetwisting | Letter category |
| `template_versie` | String[20] | NOT NULL | Template ID (e.g. "derdenverklaring_20260101") |
| `verzenddatum` | Date | NOT NULL | When sent |
| `ontvanger` | String[255] | NOT NULL | Recipient name/address (from Beslag or Medewerker) |
| `document_uri` | String[500] | NOT NULL | Link to rendered PDF in document-vault |
| `verzendmethode` | Enum | post_aangetekend, email, digipoort | Dispatch channel |
| `verzendbevestiging_uri` | String[500] | Nullable | Link to delivery confirmation (PostNL tracking, email read receipt, Digipoort status) |
| `door_gebruiker_id` | UUID | FK to Gebruiker | Who initiated the send |
| `created_at` | Timestamp | NOT NULL | |
| `deleted_at` | Timestamp | Nullable | Soft delete (letters recalled for correction) |

**Indexes:** `(beslag_id, verzenddatum)`, `(administratie_id, type, verzenddatum)`.

---

### `BeslagVertrouwelijkheidsLog` (Confidentiality Audit)

Separate from generic audit-trail-payroll; captures access to sensitive garnishment data for GDPR compliance.

| Field | Type | Constraints | Notes |
|-------|------|-----------|-------|
| `id` | UUID | PK | |
| `administratie_id` | UUID | FK to Administratie | |
| `beslag_id` | UUID | FK to Beslag | Which garnishment accessed |
| `gebruiker_id` | UUID | FK to Gebruiker | Who accessed |
| `toegangstype` | Enum | raadpleging, wijziging, export, correspondentie_verzonden, berekening_bvv | Action type |
| `rollen_op_moment` | String[] | NOT NULL | User's roles at time of access (snapshot) |
| `rechtvaardiging` | String[500] | NOT NULL | Role requirement justifying access (e.g. "payroll_admin") |
| `ip_adres` | String[45] | NOT NULL | IPv4 or IPv6 |
| `user_agent` | Text | Nullable | Browser/client info |
| `tijdstip` | Timestamp | NOT NULL; immutable | UTC timestamp |

**Indexes:** `(beslag_id, tijdstip)`, `(gebruiker_id, tijdstip)`, `(administratie_id, tijdstip)`.

---

## API Routes (High-level)

### Garnishment Management
- `GET /api/v1/administraties/{id}/beslagen` — List garnishments for administratie (filtered by role).
- `POST /api/v1/administraties/{id}/beslagen` — Register new garnishment (upload exploot scan).
- `GET /api/v1/beslagen/{id}` — Fetch garnishment detail (checks confidentiality role).
- `PATCH /api/v1/beslagen/{id}` — Update status, override BVV, etc.

### Exemption Calculation
- `GET /api/v1/beslagen/{id}/bvv-berekening/{peilmaand}` — Fetch computed exemption.
- `POST /api/v1/beslagen/{id}/bvv-override` — HR manually overrides exemption (requires hr_manager role + motivering).
- `POST /api/v1/beslagen/{id}/bvv-recalculate` — Trigger recalculation after employee submits updated rent/care proof.

### Correspondence
- `POST /api/v1/beslagen/{id}/derdenverklaring/draft` — Pre-fill statutory declaration template.
- `POST /api/v1/beslagen/{id}/derdenverklaring/send` — Render PDF and dispatch (post/email/Digipoort).
- `GET /api/v1/beslagen/{id}/correspondentie` — Fetch sent letters.

### Remittance
- `GET /api/v1/loonrun/{id}/beslag-afdrachten` — Garnishment deductions for a payroll run.
- `POST /api/v1/loonrun/{id}/beslag-sepa-batch` — Generate SEPA pain.001 file.
- `PATCH /api/v1/afdracht/{id}/status` — Update remittance status (mark as executed, failed, reversed).

### Export & Audit
- `POST /api/v1/administraties/{id}/beslagen/export` — Accountant export (ZIP of all beslagen, afdrachten, letters for date range).
- `GET /api/v1/beslagen/{id}/audit-log` — Confidentiality access log.

---

## Seed Data (Examples for Testing)

### Beslag

```yaml
- id: "beslag-lbio-001"
  administratie_id: "adm-001"
  medewerker_id: "emp-001"
  beslaglegger_type: "lbio_alimentatie"
  beslaglegger_naam: "LBIO Amsterdam"
  beslaglegger_iban: "NL91ABNA0417164300"
  beslaglegger_kenmerk: "LBIO-202601-FL2024000123"
  exploot_datum: "2026-05-01"
  vorderingsbedrag_oorspronkelijk: 12000.00
  vorderingsbedrag_resterend: 10800.00
  preferentie: "preferent"
  volgnummer_intern: 1
  vanaf: "2026-06"
  tot: null
  status: "actief"

- id: "beslag-deurwaarder-001"
  administratie_id: "adm-001"
  medewerker_id: "emp-001"
  beslaglegger_type: "deurwaarder_civiel"
  beslaglegger_naam: "Deurwaarderskantoor De Wit"
  beslaglegger_iban: "NL65PBNK6789123456"
  beslaglegger_kenmerk: "DW-2024-5678"
  deurwaarder_id: "23045"
  exploot_datum: "2026-04-15"
  vorderingsbedrag_oorspronkelijk: 5500.00
  vorderingsbedrag_resterend: 5400.00
  preferentie: "concurrent"
  volgnummer_intern: 2
  vanaf: "2026-05"
  tot: null
  status: "actief"

- id: "beslag-belastingdienst-001"
  administratie_id: "adm-002"
  medewerker_id: "emp-002"
  beslaglegger_type: "belastingdienst"
  beslaglegger_naam: "Belastingdienst Salarisgarnering"
  beslaglegger_iban: "NL63RABO0123456789"
  beslaglegger_kenmerk: "BDIEN-2024-EMP002"
  beslaglegger_bsn_kenmerk: "123456789"
  exploot_datum: "2026-03-20"
  vorderingsbedrag_oorspronkelijk: 8200.00
  vorderingsbedrag_resterend: 7400.00
  preferentie: "preferent"
  volgnummer_intern: 1
  vanaf: "2026-04"
  tot: null
  status: "actief"
```

### BeslagvrijeVoet

```yaml
- id: "bvv-emp-001-202606"
  administratie_id: "adm-001"
  medewerker_id: "emp-001"
  peilmaand: "2026-06"
  bvv_bedrag: 1209.70
  bvv_methode: "wvbvv_standaard"
  inkomen_grondslag: 2400.00
  leefvorm: "alleenstaand"
  aantal_kinderen_tlv: 0
  woonkosten: 900.00
  nominale_premie_zvw: 188.42
  # Formula per Wvbvv 2021 tables (2026 values):
  # Base (alleenstaand): €1055,00
  # Rent component (€900): +€125,00
  # Care premium (€188,42): ~€29,70
  # Total: €1209,70

- id: "bvv-emp-002-202606"
  administratie_id: "adm-002"
  medewerker_id: "emp-002"
  peilmaand: "2026-06"
  bvv_bedrag: 1456.20
  bvv_methode: "handmatig_overruled_met_motivering"
  handmatig_motivering: "Employee provided proof of higher rent (€1200 verified lease); standard formula underestimated."
  inkomen_grondslag: 3100.00
  leefvorm: "alleenstaande_ouder"
  aantal_kinderen_tlv: 2
  woonkosten: 1200.00
  nominale_premie_zvw: 214.56
  vastgesteld_door_id: "user-hr-manager-001"
  vastgesteld_op: "2026-06-10T14:32:00Z"
```

### BeslagAfdracht

```yaml
- id: "afdracht-lbio-202606"
  administratie_id: "adm-001"
  loonrun_id: "loonrun-adm001-202606"
  beslag_id: "beslag-lbio-001"
  medewerker_id: "emp-001"
  periode_jaar: 2026
  periode_maand: 6
  bedrag_ingehouden: 450.00
  bedrag_afgedragen: 450.00
  afdrachtdatum: "2026-07-05"
  betalingsreferentie: "STP-20260705-001234"
  journaalpost_id: "jop-2026-06-beslag"
  status: "uitgevoerd"

- id: "afdracht-deurwaarder-202606"
  administratie_id: "adm-001"
  loonrun_id: "loonrun-adm001-202606"
  beslag_id: "beslag-deurwaarder-001"
  medewerker_id: "emp-001"
  periode_jaar: 2026
  periode_maand: 6
  bedrag_ingehouden: 150.00
  bedrag_afgedragen: null
  status: "mislukt"
  status_opmerking: "IBAN NL65PBNK6789123456 rejected by bank — returned 2026-07-07."
```

---

## Frontend Components (High-level)

### Payroll Admin Views
- **Garnishment List** — filterable table (status, employee, creditor, deadline).
- **Garnishment Registration** — form to upload exploot, extract metadata, set start date.
- **Exemption Calculator** — displays computed BVV; allows override with justification.
- **Correspondence Builder** — pre-filled letter template; send/track.
- **Remittance Monitor** — monthly afdracht status per garnishment; retry failed payments.
- **Compliance Deadline Widget** — countdown to derdenverklaring due; escalation alerts.

### HR Manager Views
- **Override Approval** — review and approve BVV overrides with employee-submitted proof.
- **Escalation Queue** — missed deadlines, failed remittances.
- **Employee Support** — view garnishments affecting an employee; field hardship-waiver requests.

### Employee Views (Self-Service)
- **Garnishment Summary** — list of active garnishments, amounts, creditors.
- **Exemption Detail** — computed BVV with calculation transparency.
- **Payslip Detail** — garnishment deduction line; remittance schedule.
- **Document Upload** — submit rent/care proof to trigger BVV recalculation.

### Accountant / Auditor Views
- **Export & Attestation** — ZIP download of garnishment audit trail for period.
- **Summary Statistics** — total afdrachten, compliance events, data-protection incidents.

---

## Security & Confidentiality

### Role-Based Access Control
- `payroll_admin` — full CRUD on Beslag, BeslagAfdracht, correspondence; sees all details.
- `hr_manager` — reads Beslag/BeslagvrijeVoet; approves BVV overrides; supports employees.
- `medewerker` (employee) — reads own Beslag, BeslagvrijeVoet, afdracht history on self-service.
- `leidinggevende` (manager, non-HR) — on payslips, sees "Overige loonheffing" without beslag detail.
- `accountant` / `auditor` — read-only export for compliance period.
- `compliance_officer` — reads BeslagVertrouwelijkheidsLog; enforces retention policies.

### API Authorization
- All endpoints check role + `administratie_id` match.
- GET `/api/beslagen/{id}` returns 404 if user lacks payroll_admin or is unauthorized medewerker.
- Every access logged in BeslagVertrouwelijkheidsLog.

### Data Minimization
- Payslip template: conditionally renders "Inhouding loonbeslag" detail only if viewer is employee or payroll_admin.
- Reports (e.g., team salary report) hide beslag rows from non-HR.
- Export ZIP is encrypted; audit log tracks download + decryption.

### Retention & Deletion
- Beslagen and associated docs retained 7 years after garnishment end OR 7 years after employee termination.
- Automated job monthly checks deletion eligibility; pseudo­anonymizes scans; schedules shredding.

---

## Integration Points

### Upstream Dependencies
- **employee-master:** Leefvorm, kinderen, woonkosten (for BVV).
- **payroll-engine-nl:** Consumes BeslagSamenloop; applies deduction in netto-salary calculation.

### Downstream Integrations
- **journaalpost-export:** Beslagafdracht → GL entry "Garnishers payable."
- **openconnector:** SEPA pain.001 batch; Digipoort letter dispatch.
- **document-vault:** Scan storage + retention policy.
- **notification-engine:** Deadline alerts, escalation mails.
- **audit-trail-payroll:** Immutable event log (generic).

---

## Testing Strategy

1. **Unit tests:** Wvbvv formula (various leefvorm, rent, care-premium combos).
2. **Integration tests:** Samenloop allocation (preferent vs. concurrent; pro-rata vs. chrono).
3. **Scenario tests:** End-to-end (register → declare → remit → export).
4. **Confidentiality tests:** Verify payslip detail hidden from non-authorized roles; access logged.
5. **Deadline tests:** Alert triggers at T-2 and T-0.
6. **Payment reconciliation tests:** Failed SEPA → retry queue.

---

## Deployment & Rollout

1. **MVP (June 2026):** Registration, BVV calculation, derdenverklaring template, monthly remittance batch.
2. **Iteration 1 (July 2026):** Confidentiality logging, payslip conditioning, employee view.
3. **Iteration 2 (August 2026):** Accountant export, retention automation, Digipoort integration.

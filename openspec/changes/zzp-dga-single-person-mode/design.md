# Design: zzp-dga-single-person-mode

## Architecture

### Data Model Strategy

The single-person mode is a non-destructive extension to the existing HRMQ data model. No tables are removed or significantly restructured; instead:

1. **hrmq_organisation** gains a `mode` enum column and optional `dga_employee_id` FK — existing organisations default to `mode = 'standard'`
2. **New tables** are created for DGA-specific fiscal metadata (`hrmq_dga_profile`, `hrmq_dga_ib_export`, `hrmq_accountant_delegation`)
3. **Existing payroll-engine** (`payroll-engine-nl`) is reused without modification — the same loonheffing logic runs for standard and DGA modes
4. **UI visibility** is controlled by the `mode` flag: DGA mode hides multi-employee menus and surfaces a DGA-specific dashboard

This approach ensures:
- **Reversibility**: A DGA who hires their first employee switches back to `standard` mode; no data loss
- **Code reuse**: Payroll calculations, loonstrook PDF generation, and jaaropgaaf flows use the shared engine
- **Migration path**: A growing BV that starts in DGA mode can expand to multi-employee without losing history

### Deployment & Lifecycle

- **Feature flag**: `feature.dga_single_person_mode = true` (default: false, opt-in per tenant)
- **Rollout phase**: Pilot with 5–10 accountants managing DGA clients (Q3 2026), then general availability (Q4 2026)
- **Rate table updates**: Annual indexed tables for gebruikelijk-loon norm (1 december, Belastingdienst publication) and ZVW ceiling are loaded via migrations
- **Backwards compatibility**: Existing multi-employee organisations unaffected; no breaking changes

### Integration Points

- **payroll-engine-nl**: Reused for loonheffing, ZVW, and jaaropgaaf generation
- **docudesk**: PDF templating for DGA-flavoured loonstrook and jaaropgaaf
- **bank-payment-batch-sepa**: Monthly salaris-overboeking (single-payment batch for DGA)
- **document-template-engine**: IB-pakket manifest and accountant cover letter generation
- **openconnector**: Post-MVP integration with Belastingdienst loonaangifte-API (optional)

### Role & Permission Model

- **accountant_of_record**: New delegated role; grants specific permissions (`read_payroll`, `write_payroll`, `read_jaaropgaaf`, `export_ib_pakket`) without access to non-payroll data
- **dga_admin**: Existing admin role; can create/manage DGA profile, switch modes, and revoke accountant delegations
- **dga_user**: Self-service access to own loonstroken, jaaropgaaf, and IB-pakket export

---

## Seed Data

### hrmq_organisation (DGA Mode Example)

```json
{
  "@self": {
    "register": "hrmq_organisation",
    "schema": "HrmqOrganisation",
    "slug": "dga-example-consultancy"
  },
  "name": "De Vries Consultancy BV",
  "kvk": "12345678",
  "gemeente_code": "0363",
  "mode": "dga_single_person",
  "dga_employee_id": "emp-001-dga-de-vries",
  "established_at": "2022-01-15"
}
```

### hrmq_employee (DGA)

```json
{
  "@self": {
    "register": "hrmq_employee",
    "schema": "HrmqEmployee",
    "slug": "emp-001-dga-de-vries"
  },
  "first_name": "Petra",
  "last_name": "de Vries",
  "email": "petra@devries-consultancy.nl",
  "bsn": "123456789",
  "is_dga": true,
  "contract_type": "dga_zzp",
  "monthly_bruto": 4500.00,
  "aoow_gerechtigd": true,
  "started_at": "2022-01-15"
}
```

### hrmq_dga_profile (Seed Object)

```json
{
  "@self": {
    "register": "hrmq_dga_profile",
    "schema": "HrmqDgaProfile",
    "slug": "dga-profile-de-vries-2026"
  },
  "employee_id": "emp-001-dga-de-vries",
  "aanmerkelijk_belang_percentage": 100.0,
  "gebruikelijk_loon_norm_year": 2026,
  "gebruikelijk_loon_norm_basis": "wettelijk_56000",
  "gebruikelijk_loon_norm_motivering": null,
  "for_saldo_opening": 12500.00,
  "for_onttrekkingen": [
    {
      "date": "2024-11-15",
      "amount": 2500.00,
      "type": "lijfrente"
    }
  ],
  "lijfrente_polissen": [
    {
      "verzekeraar": "NN Pensioen",
      "polisnummer": "NNP-2024-1234567",
      "stortingen_per_jaar": [
        {"year": 2024, "amount": 5000.00},
        {"year": 2025, "amount": 5000.00}
      ],
      "factor_a": 0.0254
    }
  ],
  "box2_dividenduitkeringen": [
    {
      "date": "2024-12-20",
      "amount": 15000.00
    }
  ],
  "box2_verkrijgingsprijs": 50000.00
}
```

### hrmq_dga_profile (Alternative Example: Different Norm Basis)

```json
{
  "@self": {
    "register": "hrmq_dga_profile",
    "schema": "HrmqDgaProfile",
    "slug": "dga-profile-te-welde-2026"
  },
  "employee_id": "emp-002-dga-te-welde",
  "aanmerkelijk_belang_percentage": 100.0,
  "gebruikelijk_loon_norm_year": 2026,
  "gebruikelijk_loon_norm_basis": "meestverdienende_werknemer",
  "gebruikelijk_loon_norm_motivering": "Werkzaam als interim-manager; gemiddelde inkomsten vorige 3 jaren EUR 72.000 bruto",
  "for_saldo_opening": 0.00,
  "for_onttrekkingen": [],
  "lijfrente_polissen": [],
  "box2_dividenduitkeringen": [],
  "box2_verkrijgingsprijs": null
}
```

### hrmq_accountant_delegation (Seed Object)

```json
{
  "@self": {
    "register": "hrmq_accountant_delegation",
    "schema": "HrmqAccountantDelegation",
    "slug": "delegation-de-vries-to-boekhouders-amsterdam"
  },
  "dga_employee_id": "emp-001-dga-de-vries",
  "accountant_user_id": "user-accountant-001-amsterdam",
  "granted_at": "2025-01-10T09:30:00Z",
  "revoked_at": null,
  "permissions": [
    "read_payroll",
    "write_payroll",
    "read_jaaropgaaf",
    "export_ib_pakket"
  ]
}
```

### hrmq_dga_ib_export (Seed Object)

```json
{
  "@self": {
    "register": "hrmq_dga_ib_export",
    "schema": "HrmqDgaIbExport",
    "slug": "ib-export-de-vries-2024"
  },
  "dga_employee_id": "emp-001-dga-de-vries",
  "calendar_year": 2024,
  "status": "definitief_aangeleverd",
  "accountant_endpoint_id": "endpoint-accountant-001-sftp",
  "pakket_blob_url": "s3://hrmq-exports/ib-pakket-de-vries-2024-20250115.zip",
  "generated_at": "2025-01-15T14:32:00Z",
  "accountant_acknowledgement_at": "2025-01-16T10:15:00Z"
}
```

### hrmq_loonstrook (DGA-Flavoured Example)

```json
{
  "@self": {
    "register": "hrmq_loonstrook",
    "schema": "HrmqLoonstrook",
    "slug": "loonstrook-de-vries-202501"
  },
  "employee_id": "emp-001-dga-de-vries",
  "calendar_month": 202501,
  "bruto_loon": 4500.00,
  "loonheffing": 845.32,
  "zvw_werkgeversbijdrage": 272.82,
  "netto_loon": 3654.68,
  "dga_section": {
    "ytd_bruto": 4500.00,
    "gebruikelijk_loon_norm": 56000.00,
    "remaining_to_norm": 51500.00,
    "status": "green"
  }
}
```

---

## UI Screens

### 1. DGA Organisation Settings (Mode Toggle)

**Route:** `Configuratie › Administraties › [select organisation] › Mode`

**Component:** CnSettingPanel with mode selector

**Fields:**
- `Organisation mode` (radio buttons):
  - "Standard (multi-employee)" — default
  - "DGA Single-Person" — new toggle
- `DGA Employee` (dropdown) — appears only if mode = `dga_single_person`; filtered to employees with `is_dga = true`
- Action buttons:
  - "Save" — triggers validation (exactly 1 DGA employee exists)
  - "Cancel"

**Validation:**
- If switching TO `dga_single_person`: validate exactly 1 active employee with `is_dga = true`
- If switching FROM `dga_single_person`: prompt "Are you sure? All DGA-specific data will be preserved on the employee's record as read-only tabs."
- On save: emit `OrganisationModeChanged` event

---

### 2. DGA Dashboard (Default Landing Page)

**Route:** `Dashboard` (when `organisation.mode = dga_single_person`)

**Layout:** 4-widget grid

**Widget 1: Gebruikelijk-loon Status**
- Title: "Gebruikelijk-loon 2026"
- Displays: 
  - Norm: EUR 56.000 (or actual if custom basis)
  - YTD paid: EUR [current]
  - Remaining: EUR [current]
  - Status bar (% of norm achieved): green/amber/red
  - "View details" link → detail screen

**Widget 2: ZVW Running Total**
- Title: "ZVW Werkgeversheffing"
- Displays:
  - YTD paid: EUR [current]
  - Ceiling (2026): EUR 75.864
  - % of ceiling: [current]
  - Warning flag if overpayment detected

**Widget 3: FOR-saldo & Lijfrente**
- Title: "FOR-saldo en Lijfrente"
- Displays:
  - FOR-saldo opening: EUR [opening]
  - FOR-saldo current: EUR [current]
  - Lijfrente jaarruimte: EUR [available] / EUR [reserved 10-year]
  - "View FOR timeline" link

**Widget 4: Recent Loonstroken & IB-Export**
- Title: "Mijn Loonstroken"
- List of last 3 loonstroken (PDF download links)
- Action buttons:
  - "Genereer jaaropgaaf" (if year ended)
  - "Lever IB-pakket" (if jaaropgaaf finalised)

---

### 3. DGA Profile Detail Tab (Employee Record)

**Route:** `Medewerkers › [DGA employee] › DGA Profiel`

**Visibility:** Appears only for employees with `is_dga = true` (including in standard mode for DGA who hired)

**Sections:**

**A. Fiscale Gegevens**
- Aanmerkelijk-belang percentage (editable)
- Gebruikelijk-loon norm basis (dropdown + motivering textarea if non-default)
- Current norm value (display-only, recalculated annually)

**B. FOR-saldo**
- Opening balance (EUR [value])
- Timeline table: date | type | amount | balance
- "Add onttrekking" button (adds row to timeline)
- Read-only badge if jaar ≥ 2023

**C. Lijfrente-polissen**
- Table: verzekeraar | polisnummer | stortingen | factor A | actions
- "Add polis" button (opens form dialog)

**D. Box-2 Aanmerkelijk Belang**
- Verkrijgingsprijs (EUR [value], editable)
- Dividenduitkeringen timeline: date | amount | actions
- "Add dividend record" button

**E. Delegatie (Accountant-of-record)**
- Table: accountant email | permissions | granted-at | actions (revoke)
- "Invite accountant" button → invitation form

---

## Reuse Analysis

This change leverages existing HRMQ components and services:

| Component/Service | Used For | Reuse Notes |
|---|---|---|
| `ObjectService` (OpenRegister) | CRUD for DGA profile, delegation, IB-export records | No custom implementation; uses generic save/find |
| `PayrollService` (payroll-engine-nl) | Monthly loonstrook generation, loonheffing calculation | No modification; reused as-is |
| `DocutemplateService` (docudesk) | PDF generation for loonstrook + jaaropgaaf | Extended with DGA-specific sections (template parameters) |
| `JaaropgaafService` | Annual report generation | Extended to include FOR/lijfrente/box-2 JSON sections |
| `CnDetailPage` | DGA profile UI | Generic component; no custom build |
| `CnDataTable` | Loonstroken list, FOR timeline, lijfrente polis table | Reused for all tabular UIs |
| `CnSettingPanel` | Organisation mode toggle | Generic; no custom code |

**No component duplication.** All UI screens use standard CnGrid/CnDetailPage/CnDataTable patterns. No new UI framework introduced.

---

## Accessibility & Internationalization

- **i18n**: All labels/messages use i18n keys; Dutch translations provided (nl_NL)
- **Accessibility**: All form fields have `aria-label`; tables have proper `<thead>`/`<tbody>` structure
- **ADR-007 (i18n)**: Numeric formats (currency, dates) respect locale settings; EUR amounts formatted with dot separator and comma decimal (Dutch convention)

---

## Security & Audit Trail

- **Role-based access**: accountant_of_record role scoped to specific permissions; no access to non-payroll data
- **Audit logging**: All delegations, revocations, and IB-pakket exports logged with timestamp and user
- **Data isolation**: DGA profile data NOT accessible to other employees or non-delegated users
- **RBAC by delegation**: accountant_of_record permissions enforced at service layer (ObjectService filters)

---

## Performance Considerations

- **Dashboard widgets**: Cached daily (stale-acceptable scenario for fiscal data); on-demand refresh available
- **Jaaropgaaf generation**: Async job (background worker) to avoid blocking UI during large PDF/ZIP generation
- **Accountant delegation queries**: Indexed on `(dga_employee_id, revoked_at)` for fast lookup of active delegations

---

## Testing Strategy

- **Unit tests**: Entity validators (DGA profile, FOR timeline, lijfrente formula)
- **Integration tests**: Loonstrook PDF generation with DGA section; jaaropgaaf + FOR/lijfrente JSON export
- **E2E tests**: Mode toggle workflow (standard → DGA → back to standard); accountant delegation invite/revoke; IB-pakket export
- **Persona tests**: DGA user (Sem), accountant (Annemarie), admin (Jan-Willem)

---

## Dependencies & Blockers

- `payroll-engine-nl` must be merged and deployed before this spec (dependency: `payroll-core-basic`)
- `docudesk` PDF template framework must support named template parameters (for DGA loonstrook section)
- Belastingsdienst rate tables for 2026 (gebruikelijk-loon norm, ZVW ceiling) finalized by 2025-12-01

No blocking architectural issues.

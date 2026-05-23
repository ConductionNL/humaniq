# WNT Disclosure - Design & Data Model

## Data Model

### Entity 1: `wnt_topfunctionaris_aanwijzing`

**Purpose:** Designate an employee or interim as an executive, with WNT norm classification and tenure tracking.

**Fields:**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | UUID | ✓ | Primary key. |
| `employee_id` | UUID | Optional | FK to employee-master.employees. NULL for external interims without employment contract. |
| `organisation_id` | UUID | ✓ | FK to organisation. Implicit scope. |
| `functie_naam` | string(200) | ✓ | Function title: "bestuurder", "voorzitter raad van toezicht", "lid raad van toezicht", "interim-bestuurder", "directeur", etc. |
| `aanvangsdatum` | date | ✓ | Start date. If before calendar year, affects pro-rata norm for that year. |
| `einddatum` | date | Optional | End date. If set, pro-rata norm applies for partial-year tenure. |
| `aanwijzings_grond` | enum | ✓ | WNT Art. 1.1 category: `bestuurder`, `toezichthouder`, `leidinggevende_topstructuur`, `interim_bestuurder`. |
| `wnt_norm_toepasselijk` | enum | ✓ | `norm_1` (EUR 246k 2026) or `norm_2_klasse_A` through `norm_2_klasse_G`. |
| `fictieve_dienstbetrekking` | boolean | ✓ | True if hired via BV/ZZP with underlying employment link. Triggers interim-norm. |
| `bezoldigings_grondslag` | enum | ✓ | `werknemer` (employment contract), `extern_via_bv` (BV invoice), `extern_zelfstandig` (freelance). |
| `uitsterf_constructie_vlag` | boolean | ✓ | True if exempt under wettelijk-overgangsrecht (was above norm on 2013-01-01). Suppresses alerts. |
| `created_at` | timestamp | ✓ | Audit trail. |
| `created_by` | UUID | ✓ | User who created. |
| `updated_at` | timestamp | ✓ | Audit trail. |
| `updated_by` | UUID | ✓ | User who updated. |

**Unique constraint:** `(organisation_id, employee_id, aanvangsdatum)` — one designation per employee per start date (allows re-designation on role change).

---

### Entity 2: `wnt_bezoldiging_component`

**Purpose:** Atomic compensation element (salary, bonus, provisions, natura) per executive per calendar year.

**Fields:**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | UUID | ✓ | Primary key. |
| `wnt_topfunctionaris_aanwijzing_id` | UUID | ✓ | FK to wnt_topfunctionaris_aanwijzing. |
| `kalenderjaar` | int | ✓ | Calendar year (e.g., 2026). |
| `component_type` | enum | ✓ | `beloning`, `bonus`, `gratificatie`, `belastbare_onkostenvergoeding`, `voorziening_pensioen`, `voorziening_levensloop`, `voorziening_ikb`, `natura_leaseauto`, `natura_woning`, `natura_overig`, `ontslagvergoeding`. |
| `bedrag_eur` | decimal(12,2) | ✓ | Annual amount in EUR. |
| `bron_administratie` | enum | ✓ | `payroll_engine_nl` (with run_id), `manuele_invoer` (with admin_id), `voorzieningen_administratie`. |
| `bron_id` | string(100) | Optional | run_id (payroll), invoice-id (inhuur), or UUID of manual entry record. |
| `wnt_meetelt_vlag` | boolean | ✓ | True if counted toward WNT total (false for reiskosten, kinderopvang per Uitvoeringsregeling). |
| `created_at` | timestamp | ✓ | Audit trail. |
| `created_by` | UUID | ✓ | User who entered. |

**Unique constraint:** `(wnt_topfunctionaris_aanwijzing_id, kalenderjaar, component_type, bron_id)` — avoid duplicate components.

---

### Entity 3: `wnt_jaar_rapportage`

**Purpose:** Aggregated annual compensation record per executive, with norm comparison and recovery status.

**Fields:**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | UUID | ✓ | Primary key. |
| `wnt_topfunctionaris_aanwijzing_id` | UUID | ✓ | FK to wnt_topfunctionaris_aanwijzing. |
| `kalenderjaar` | int | ✓ | Calendar year. |
| `totaal_bezoldiging_wnt` | decimal(12,2) | ✓ | Sum of components where wnt_meetelt_vlag = true. |
| `totaal_bezoldiging_fiscaal` | decimal(12,2) | Optional | For reference/audit; sum of all salary components per tax law. |
| `wnt_norm_bedrag` | decimal(12,2) | ✓ | Applicable norm (norm-1, norm-2-classX, or interim-norm adjusted for tenure). |
| `wnt_norm_bedrag_pro_rata` | decimal(12,2) | Optional | Norm adjusted for partial-year tenure. |
| `overschrijdings_bedrag` | decimal(12,2) | ✓ | max(0, totaal_bezoldiging_wnt - wnt_norm_bedrag_pro_rata). |
| `reden_overschrijding_mogelijk_toegestaan` | enum | Optional | `uitsterf_constructie`, `wettelijk_overgangsrecht`, `individuele_uitzondering_minister`, NULL if no exemption. |
| `terugvordering_vereist_vlag` | boolean | ✓ | True if overschrijdings_bedrag > 0 and no exemption. |
| `terugvordering_bedrag` | decimal(12,2) | ✓ | Amount to be recovered (usually = overschrijdings_bedrag). |
| `terugvordering_status` | enum | ✓ | `niet_vereist`, `te_vorderen`, `in_vordering`, `voldaan`, `oninbaar`. |
| `publicatie_status` | enum | ✓ | `concept`, `door_rvb_vastgesteld`, `gepubliceerd_jaarverslag`. |
| `created_at` | timestamp | ✓ | Audit trail. |
| `created_by` | UUID | ✓ | User who created. |
| `updated_at` | timestamp | ✓ | Audit trail. |
| `updated_by` | UUID | ✓ | User who updated. |

**Unique constraint:** `(wnt_topfunctionaris_aanwijzing_id, kalenderjaar)` — one report per executive per year.

---

### Entity 4: `wnt_ontslagvergoeding`

**Purpose:** Severance-specific tracking with separate plafond (EUR 75k or 1×salary, lower of two).

**Fields:**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | UUID | ✓ | Primary key. |
| `wnt_topfunctionaris_aanwijzing_id` | UUID | ✓ | FK to wnt_topfunctionaris_aanwijzing. |
| `uitkerings_datum` | date | ✓ | Payment date. |
| `totaal_bedrag` | decimal(12,2) | ✓ | Full severance payout. |
| `jaarsalaris_op_uitkeringsdatum` | decimal(12,2) | ✓ | 1× salary baseline for plafond calculation. |
| `wnt_plafond_bedrag` | decimal(12,2) | ✓ | min(EUR 75,000, jaarsalaris_op_uitkeringsdatum). |
| `bedrag_binnen_plafond` | decimal(12,2) | ✓ | min(totaal_bedrag, wnt_plafond_bedrag). |
| `bedrag_boven_plafond` | decimal(12,2) | ✓ | max(0, totaal_bedrag - wnt_plafond_bedrag) — recoverable. |
| `reden_uitkering` | enum | ✓ | `vrijwillig_vertrek`, `ontslag_werkgever`, `contract_niet_verlengd`, `overlijden`. |
| `samenstelling_json` | jsonb | Optional | Object detailing component breakdown: `{ "transitievergoeding": 30000, "gouden_handdruk": 45000, "outplacement": 8000, "juridische_bijstand": 2000 }`. |
| `created_at` | timestamp | ✓ | Audit trail. |
| `created_by` | UUID | ✓ | User who entered. |

---

### Entity 5: `wnt_klasse_indeling`

**Purpose:** Annual class assignment (education & healthcare only) that drives WNT norm amount.

**Fields:**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | UUID | ✓ | Primary key. |
| `organisation_id` | UUID | ✓ | FK to organisation. |
| `kalenderjaar` | int | ✓ | Calendar year. |
| `ingedeelde_klasse` | enum | ✓ | A, B, C, D, E, F, or G. |
| `klasse_bepalende_factoren_json` | jsonb | ✓ | Sector-specific: for healthcare: `{ "opbrengsten_eur": 142000000, "aantal_bedden": 240, "bekostigingscategorie": "academisch_ziekenhuis" }`; for education: `{ "aantal_leerlingen": 850, "budget_eur": 5200000, "sector_multiplier": 1.2 }`. |
| `wnt_norm_bedrag` | decimal(12,2) | ✓ | Norm amount for this class/year (e.g., EUR 235,000 for class-V healthcare 2026). |
| `indeling_vastgesteld_door` | string(200) | Optional | RvB decision reference (e.g., "RvB-besluit 2026-01-15, nr. 47-2026"). |
| `indeling_datum` | date | Optional | Date of RvB decision. |
| `created_at` | timestamp | ✓ | Audit trail. |
| `created_by` | UUID | ✓ | User who entered. |

**Unique constraint:** `(organisation_id, kalenderjaar)` — one class per organisation per year.

---

### Entity 6: `wnt_publicatie_versie`

**Purpose:** Version control on annual jaarverslag appendix (concept → approved → published, with revisions).

**Fields:**

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `id` | UUID | ✓ | Primary key. |
| `organisation_id` | UUID | ✓ | FK to organisation. |
| `kalenderjaar` | int | ✓ | Calendar year. |
| `versie_nummer` | int | ✓ | 1, 2, 3, ... incremented on each revision. |
| `gegenereerd_op` | timestamp | ✓ | Generation timestamp. |
| `gegenereerd_door` | UUID | ✓ | User ID who triggered generation. |
| `document_id` | UUID | Optional | FK to document-store. PDF binary location. |
| `accountantsverklaring_id` | UUID | Optional | Reference to audit certification (if signed). |
| `status` | enum | ✓ | `concept`, `door_rvb_vastgesteld`, `gepubliceerd_jaarverslag`, `ingetrokken_vervangen`. |
| `publicatie_url` | string(500) | Optional | URL where published (organisation website). |
| `vervangen_door_versie_id` | UUID | Optional | FK to newer version if this one was superseded. |
| `created_at` | timestamp | ✓ | Audit trail. |

**Unique constraint:** `(organisation_id, kalenderjaar, versie_nummer)` — one version per year per number.

---

## Seed Data

### Seed: wnt_topfunctionaris_aanwijzing

```json
[
  {
    "id": "aad1-uuid-001",
    "employee_id": "emp-uuid-1001",
    "organisation_id": "org-hospital-001",
    "functie_naam": "Voorzitter Raad van Bestuur",
    "aanvangsdatum": "2024-01-01",
    "einddatum": null,
    "aanwijzings_grond": "bestuurder",
    "wnt_norm_toepasselijk": "norm_2_klasse_V",
    "fictieve_dienstbetrekking": false,
    "bezoldigings_grondslag": "werknemer",
    "uitsterf_constructie_vlag": false,
    "created_at": "2025-01-15T09:00:00Z",
    "created_by": "user-uuid-hr-001"
  },
  {
    "id": "aad1-uuid-002",
    "employee_id": "emp-uuid-1002",
    "organisation_id": "org-hospital-001",
    "functie_naam": "Bestuursleden",
    "aanvangsdatum": "2023-06-01",
    "einddatum": "2026-05-31",
    "aanwijzings_grond": "bestuurder",
    "wnt_norm_toepasselijk": "norm_2_klasse_V",
    "fictieve_dienstbetrekking": false,
    "bezoldigings_grondslag": "werknemer",
    "uitsterf_constructie_vlag": false,
    "created_at": "2023-06-01T10:30:00Z",
    "created_by": "user-uuid-hr-001"
  },
  {
    "id": "aad1-uuid-003",
    "employee_id": null,
    "organisation_id": "org-hospital-001",
    "functie_naam": "Interim-directeur Ziekenhuisapotheek",
    "aanvangsdatum": "2026-04-01",
    "einddatum": "2026-11-30",
    "aanwijzings_grond": "interim_bestuurder",
    "wnt_norm_toepasselijk": "norm_1",
    "fictieve_dienstbetrekking": true,
    "bezoldigings_grondslag": "extern_via_bv",
    "uitsterf_constructie_vlag": false,
    "created_at": "2026-03-20T14:15:00Z",
    "created_by": "user-uuid-hr-001"
  },
  {
    "id": "aad1-uuid-004",
    "employee_id": "emp-uuid-1004",
    "organisation_id": "org-university-002",
    "functie_naam": "Voorzitter Raad van Toezicht",
    "aanvangsdatum": "2024-09-01",
    "einddatum": null,
    "aanwijzings_grond": "toezichthouder",
    "wnt_norm_toepasselijk": "norm_2_klasse_B",
    "fictieve_dienstbetrekking": false,
    "bezoldigings_grondslag": "werknemer",
    "uitsterf_constructie_vlag": true,
    "created_at": "2024-09-01T11:00:00Z",
    "created_by": "user-uuid-hr-002"
  },
  {
    "id": "aad1-uuid-005",
    "employee_id": "emp-uuid-1005",
    "organisation_id": "org-municipality-003",
    "functie_naam": "Burgemeester",
    "aanvangsdatum": "2022-01-01",
    "einddatum": null,
    "aanwijzings_grond": "bestuurder",
    "wnt_norm_toepasselijk": "norm_1",
    "fictieve_dienstbetrekking": false,
    "bezoldigings_grondslag": "werknemer",
    "uitsterf_constructie_vlag": false,
    "created_at": "2022-01-01T08:30:00Z",
    "created_by": "user-uuid-hr-003"
  }
]
```

### Seed: wnt_bezoldiging_component

```json
[
  {
    "id": "cmp-uuid-001",
    "wnt_topfunctionaris_aanwijzing_id": "aad1-uuid-001",
    "kalenderjaar": 2026,
    "component_type": "beloning",
    "bedrag_eur": 180000.00,
    "bron_administratie": "payroll_engine_nl",
    "bron_id": "run-2026-12",
    "wnt_meetelt_vlag": true,
    "created_at": "2026-01-15T09:00:00Z",
    "created_by": "user-uuid-payroll-001"
  },
  {
    "id": "cmp-uuid-002",
    "wnt_topfunctionaris_aanwijzing_id": "aad1-uuid-001",
    "kalenderjaar": 2026,
    "component_type": "voorziening_ikb",
    "bedrag_eur": 28800.00,
    "bron_administratie": "payroll_engine_nl",
    "bron_id": "run-2026-12",
    "wnt_meetelt_vlag": true,
    "created_at": "2026-01-15T09:00:00Z",
    "created_by": "user-uuid-payroll-001"
  },
  {
    "id": "cmp-uuid-003",
    "wnt_topfunctionaris_aanwijzing_id": "aad1-uuid-001",
    "kalenderjaar": 2026,
    "component_type": "voorziening_pensioen",
    "bedrag_eur": 22000.00,
    "bron_administratie": "voorzieningen_administratie",
    "bron_id": "prov-uuid-001",
    "wnt_meetelt_vlag": true,
    "created_at": "2026-01-15T09:00:00Z",
    "created_by": "user-uuid-payroll-001"
  },
  {
    "id": "cmp-uuid-004",
    "wnt_topfunctionaris_aanwijzing_id": "aad1-uuid-001",
    "kalenderjaar": 2026,
    "component_type": "natura_leaseauto",
    "bedrag_eur": 9500.00,
    "bron_administratie": "manuele_invoer",
    "bron_id": "entry-uuid-001",
    "wnt_meetelt_vlag": true,
    "created_at": "2026-02-01T14:30:00Z",
    "created_by": "user-uuid-hr-001"
  },
  {
    "id": "cmp-uuid-005",
    "wnt_topfunctionaris_aanwijzing_id": "aad1-uuid-001",
    "kalenderjaar": 2026,
    "component_type": "belastbare_onkostenvergoeding",
    "bedrag_eur": 400.00,
    "bron_administratie": "payroll_engine_nl",
    "bron_id": "run-2026-12",
    "wnt_meetelt_vlag": false,
    "created_at": "2026-01-15T09:00:00Z",
    "created_by": "user-uuid-payroll-001"
  }
]
```

### Seed: wnt_jaar_rapportage

```json
[
  {
    "id": "rpt-uuid-001",
    "wnt_topfunctionaris_aanwijzing_id": "aad1-uuid-001",
    "kalenderjaar": 2026,
    "totaal_bezoldiging_wnt": 240300.00,
    "totaal_bezoldiging_fiscaal": 240700.00,
    "wnt_norm_bedrag": 235000.00,
    "wnt_norm_bedrag_pro_rata": 235000.00,
    "overschrijdings_bedrag": 5300.00,
    "reden_overschrijding_mogelijk_toegestaan": null,
    "terugvordering_vereist_vlag": true,
    "terugvordering_bedrag": 5300.00,
    "terugvordering_status": "te_vorderen",
    "publicatie_status": "concept",
    "created_at": "2026-12-15T16:45:00Z",
    "created_by": "user-uuid-finance-001",
    "updated_at": "2026-12-15T16:45:00Z",
    "updated_by": "user-uuid-finance-001"
  },
  {
    "id": "rpt-uuid-002",
    "wnt_topfunctionaris_aanwijzing_id": "aad1-uuid-005",
    "kalenderjaar": 2026,
    "totaal_bezoldiging_wnt": 205000.00,
    "totaal_bezoldiging_fiscaal": 205000.00,
    "wnt_norm_bedrag": 246000.00,
    "wnt_norm_bedrag_pro_rata": 246000.00,
    "overschrijdings_bedrag": 0.00,
    "reden_overschrijding_mogelijk_toegestaan": null,
    "terugvordering_vereist_vlag": false,
    "terugvordering_bedrag": 0.00,
    "terugvordering_status": "niet_vereist",
    "publicatie_status": "concept",
    "created_at": "2026-12-15T16:45:00Z",
    "created_by": "user-uuid-finance-001",
    "updated_at": "2026-12-15T16:45:00Z",
    "updated_by": "user-uuid-finance-001"
  }
]
```

### Seed: wnt_ontslagvergoeding

```json
[
  {
    "id": "sev-uuid-001",
    "wnt_topfunctionaris_aanwijzing_id": "aad1-uuid-002",
    "uitkerings_datum": "2026-06-30",
    "totaal_bedrag": 90000.00,
    "jaarsalaris_op_uitkeringsdatum": 180000.00,
    "wnt_plafond_bedrag": 75000.00,
    "bedrag_binnen_plafond": 75000.00,
    "bedrag_boven_plafond": 15000.00,
    "reden_uitkering": "contract_niet_verlengd",
    "samenstelling_json": {
      "transitievergoeding_wettelijk": 30000.00,
      "gouden_handdruk": 45000.00,
      "outplacement": 8000.00,
      "juridische_bijstand": 7000.00
    },
    "created_at": "2026-06-30T10:00:00Z",
    "created_by": "user-uuid-hr-001"
  }
]
```

### Seed: wnt_klasse_indeling

```json
[
  {
    "id": "cls-uuid-001",
    "organisation_id": "org-hospital-001",
    "kalenderjaar": 2026,
    "ingedeelde_klasse": "V",
    "klasse_bepalende_factoren_json": {
      "opbrengsten_eur": 142000000,
      "aantal_bedden": 240,
      "bekostigingscategorie": "academisch_ziekenhuis"
    },
    "wnt_norm_bedrag": 235000.00,
    "indeling_vastgesteld_door": "RvB-besluit 2025-12-10, nr. 42-2025",
    "indeling_datum": "2025-12-10",
    "created_at": "2025-12-10T11:30:00Z",
    "created_by": "user-uuid-hr-001"
  },
  {
    "id": "cls-uuid-002",
    "organisation_id": "org-university-002",
    "kalenderjaar": 2026,
    "ingedeelde_klasse": "B",
    "klasse_bepalende_factoren_json": {
      "aantal_leerlingen": 12500,
      "budget_eur": 45000000,
      "sector_multiplier": 1.15
    },
    "wnt_norm_bedrag": 198000.00,
    "indeling_vastgesteld_door": "RvB-besluit 2025-11-20, nr. 35-2025",
    "indeling_datum": "2025-11-20",
    "created_at": "2025-11-20T09:00:00Z",
    "created_by": "user-uuid-hr-002"
  }
]
```

---

## Database Schema Notes

- All tables include `organisation_id` for implicit multi-tenant scoping.
- All WNT tables are scoped to `organisation_id` at the application layer — queries filter by active tenant.
- Timestamps are UTC; application converts to local timezone on display.
- Financial amounts are decimal(12,2) for EUR precision.
- Enums are stored as string in database; application layer validates against PHP enum class.
- UUIDs use uuid datatype (PostgreSQL native) or CHAR(36) on MySQL.

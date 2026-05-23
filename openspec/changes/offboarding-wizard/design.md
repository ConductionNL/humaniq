---
status: approved
---

# Design: Offboarding workflow (Offboarding case entity)

## Architecture Overview

The offboarding workflow is implemented as a **procest case subtype** with an hrmq-specific overlay. The core data model consists of seven OpenRegister entities (Offboarding, OffboardingStep, Eindafrekening, EquipmentReturn, ExitInterview, Getuigschrift, RetentionTimer) plus supporting enums and audit trails.

### Entity Relationship Diagram

```
Offboarding (case) ─┬─ OffboardingStep (progress tracking)
                    ├─ Eindafrekening (severance computation)
                    ├─ EquipmentReturn[] (inventory)
                    ├─ ExitInterview (feedback)
                    ├─ Getuigschrift (work certificate)
                    └─ RetentionTimer[] (data destruction schedule)
```

All entities live in OpenRegister. Foreign key references use OpenRegister **register+schema+objectId** relations (NOT embedded foreign keys).

## Entity Schemas & Seed Data

### 1. Offboarding (Case)

**Purpose:** Root case entity tracking a single employee's departure from commencement (departure announced) through completion (data-destruction timers started).

**Schema Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| employee_id | UUID | yes | Reference to employee-master record (register:employees, schema:Employee) |
| status | enum | yes | Current workflow state: `opzegging_geregistreerd`, `exit_interview`, `equipment_inventarisatie`, `equipment_geretourneerd`, `eindafrekening_berekenen`, `eindafrekening_goedkeuren`, `eindafrekening_uitbetalen`, `getuigschrift_opstellen`, `uwv_ww_melding`, `pensioenfonds_afmelding`, `zvw_afmelding`, `it_accounts_deactiveren`, `data_export_werknemer`, `manager_handover`, `goodbye_message`, `retentie_timers_starten`, `afgerond` |
| reden | enum | yes | Reason for departure: `opzegging_werknemer`, `opzegging_werkgever_met_vergunning`, `opzegging_werkgever_ontbinding_kantonrechter`, `vaststellingsovereenkomst`, `einde_tijdelijk_contract`, `ontslag_op_staande_voet`, `wederzijds_goedvinden`, `pensionering`, `overlijden`, `proeftijd_beëindigd` |
| reden_toelichting | string | no | Free-text reason detail (e.g., "Andere baan per 1 augustus") |
| aanzeggingsdatum | date | yes | Date departure was announced |
| einddatum | date | yes | Contract end date (statutory reference date) |
| laatste_werkdag | date | yes | Last working day (often before einddatum due to notice period) |
| vrijstelling_werk | boolean | yes | Whether employee is excused from work during notice period |
| vrijstelling_werk_vanaf | date | no | Date vrijstelling begins |
| transitievergoeding_van_toepassing | boolean | yes | Automatically computed from `reden` + contract law |
| transitievergoeding_bedrag_bruto | decimal | no | Final settled amount (EUR, 2 decimals) |
| transitievergoeding_grondslag | string | no | Legal basis: `wet_wwz_2026`, `cao_{name}`, `vaststellingsovereenkomst` |
| outplacement_aangeboden | boolean | yes | Whether outplacement service was offered (transitievergoeding-impact) |
| outplacement_aftrek_bedrag | decimal | no | Amount deducted from severance for outplacement (if any) |
| ww_melding_uwv_vereist | boolean | yes | Computed from `reden`; UWV unemployment notification required |
| ww_melding_uwv_status | enum | no | Submission state: `ontwerp`, `verzonden`, `bevestigd`, `geweigerd`, `niet_vereist` |
| data_export_aan_werknemer_status | enum | no | Data-export state: `aanvraag_ontvangen`, `export_aangemaakt`, `link_verstuurd`, `gedownload`, `voltooid`, `geweigerd` |
| afgerond_op | datetime | no | Timestamp when ALL steps were complete and case closed. Triggers retention-timer creation. |
| retentie_timers_gestart | boolean | no | True after `RetentionTimer` objects auto-created. Guards against re-triggering. |
| hr_owner_user_id | UUID | yes | HR officer/admin managing case |
| manager_user_id | UUID | yes | Employee's direct manager (handover checklist, equipment sign-off) |
| it_owner_user_id | UUID | yes | IT admin responsible for account deactivation |
| created_at | datetime | yes | Auto-set on creation |
| updated_at | datetime | yes | Auto-updated on any field change |

**Seed Data (3 examples):**

```json
[
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "Offboarding",
      "slug": "off_2026_06_johan_pieterzoon"
    },
    "id": "off_01JBCD234EFG",
    "employee_id": "emp_01HXYW987DEF",
    "status": "eindafrekening_uitbetalen",
    "reden": "opzegging_werknemer",
    "reden_toelichting": "Andere baan per 1 augustus",
    "aanzeggingsdatum": "2026-06-15",
    "einddatum": "2026-07-31",
    "laatste_werkdag": "2026-07-29",
    "vrijstelling_werk": true,
    "vrijstelling_werk_vanaf": "2026-07-15",
    "transitievergoeding_van_toepassing": true,
    "transitievergoeding_bedrag_bruto": 4823.17,
    "transitievergoeding_grondslag": "wet_wwz_2026",
    "outplacement_aangeboden": false,
    "outplacement_aftrek_bedrag": 0.00,
    "ww_melding_uwv_vereist": false,
    "ww_melding_uwv_status": "niet_vereist",
    "data_export_aan_werknemer_status": "voltooid",
    "afgerond_op": null,
    "retentie_timers_gestart": false,
    "hr_owner_user_id": "u_12",
    "manager_user_id": "u_88",
    "it_owner_user_id": "u_99",
    "created_at": "2026-06-16T08:00:00+02:00",
    "updated_at": "2026-07-20T14:11:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "Offboarding",
      "slug": "off_2026_05_maria_rodriguez"
    },
    "id": "off_01JBCE456GHI",
    "employee_id": "emp_01HXYW112XYZ",
    "status": "it_accounts_deactiveren",
    "reden": "opzegging_werkgever_met_vergunning",
    "reden_toelichting": "Reorganisatie afdeling finance",
    "aanzeggingsdatum": "2026-05-01",
    "einddatum": "2026-06-30",
    "laatste_werkdag": "2026-06-28",
    "vrijstelling_werk": true,
    "vrijstelling_werk_vanaf": "2026-05-15",
    "transitievergoeding_van_toepassing": true,
    "transitievergoeding_bedrag_bruto": 7150.50,
    "transitievergoeding_grondslag": "wet_wwz_2026",
    "outplacement_aangeboden": true,
    "outplacement_aftrek_bedrag": 800.00,
    "ww_melding_uwv_vereist": true,
    "ww_melding_uwv_status": "verzonden",
    "data_export_aan_werknemer_status": "aanvraag_ontvangen",
    "afgerond_op": null,
    "retentie_timers_gestart": false,
    "hr_owner_user_id": "u_12",
    "manager_user_id": "u_45",
    "it_owner_user_id": "u_99",
    "created_at": "2026-05-02T09:15:00+02:00",
    "updated_at": "2026-05-28T16:30:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "Offboarding",
      "slug": "off_2026_04_peter_jansen_retired"
    },
    "id": "off_01JBCF789JKL",
    "employee_id": "emp_01HXYW245ABC",
    "status": "retentie_timers_starten",
    "reden": "pensionering",
    "reden_toelichting": "Bereikt pensioensleeftijd 67 jaar",
    "aanzeggingsdatum": "2026-02-15",
    "einddatum": "2026-04-30",
    "laatste_werkdag": "2026-04-30",
    "vrijstelling_werk": false,
    "vrijstelling_werk_vanaf": null,
    "transitievergoeding_van_toepassing": false,
    "transitievergoeding_bedrag_bruto": 0.00,
    "transitievergoeding_grondslag": null,
    "outplacement_aangeboden": false,
    "outplacement_aftrek_bedrag": 0.00,
    "ww_melding_uwv_vereist": false,
    "ww_melding_uwv_status": "niet_vereist",
    "data_export_aan_werknemer_status": "voltooid",
    "afgerond_op": "2026-05-15T10:30:00+02:00",
    "retentie_timers_gestart": true,
    "hr_owner_user_id": "u_12",
    "manager_user_id": "u_55",
    "it_owner_user_id": "u_99",
    "created_at": "2026-02-16T08:30:00+02:00",
    "updated_at": "2026-05-15T10:35:00+02:00"
  }
]
```

---

### 2. OffboardingStep (Progress tracking)

**Purpose:** Immutable step-completion records. One row per step per offboarding, recording when/who/status.

**Schema Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| offboarding_id | UUID | yes | Parent Offboarding case |
| step_key | enum | yes | Fixed enum: `opzegging_geregistreerd`, `exit_interview`, `equipment_inventarisatie`, `equipment_geretourneerd`, `eindafrekening_berekenen`, `eindafrekening_goedkeuren`, `eindafrekening_uitbetalen`, `getuigschrift_opstellen`, `uwv_ww_melding`, `pensioenfonds_afmelding`, `zvw_afmelding`, `it_accounts_deactiveren`, `data_export_werknemer`, `manager_handover`, `goodbye_message`, `retentie_timers_starten` |
| status | enum | yes | `completed`, `blocked`, `skipped`, `in_progress` |
| completed_by_user_id | UUID | no | User who completed the step |
| completed_at | datetime | no | Timestamp of completion |
| blockers | array | no | Array of strings explaining why step is blocked (if status=blocked) |
| created_at | datetime | yes | Auto-set |
| updated_at | datetime | yes | Auto-updated |

**Seed Data (5 examples for Peter Jansen's completed case):**

```json
[
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "OffboardingStep",
      "slug": "off_2026_04_peter_step_01_opzegging"
    },
    "id": "step_01JBCG001",
    "offboarding_id": "off_01JBCF789JKL",
    "step_key": "opzegging_geregistreerd",
    "status": "completed",
    "completed_by_user_id": "u_12",
    "completed_at": "2026-02-16T08:30:00+02:00",
    "blockers": null,
    "created_at": "2026-02-16T08:30:00+02:00",
    "updated_at": "2026-02-16T08:30:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "OffboardingStep",
      "slug": "off_2026_04_peter_step_02_exit_interview"
    },
    "id": "step_01JBCG002",
    "offboarding_id": "off_01JBCF789JKL",
    "step_key": "exit_interview",
    "status": "completed",
    "completed_by_user_id": "u_12",
    "completed_at": "2026-04-28T14:00:00+02:00",
    "blockers": null,
    "created_at": "2026-02-16T08:30:00+02:00",
    "updated_at": "2026-04-28T14:00:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "OffboardingStep",
      "slug": "off_2026_04_peter_step_03_equipment_inventorisatie"
    },
    "id": "step_01JBCG003",
    "offboarding_id": "off_01JBCF789JKL",
    "step_key": "equipment_inventarisatie",
    "status": "completed",
    "completed_by_user_id": "u_55",
    "completed_at": "2026-04-29T10:00:00+02:00",
    "blockers": null,
    "created_at": "2026-02-16T08:30:00+02:00",
    "updated_at": "2026-04-29T10:00:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "OffboardingStep",
      "slug": "off_2026_04_peter_step_04_equipment_returned"
    },
    "id": "step_01JBCG004",
    "offboarding_id": "off_01JBCF789JKL",
    "step_key": "equipment_geretourneerd",
    "status": "completed",
    "completed_by_user_id": "u_99",
    "completed_at": "2026-04-30T16:00:00+02:00",
    "blockers": null,
    "created_at": "2026-02-16T08:30:00+02:00",
    "updated_at": "2026-04-30T16:00:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "OffboardingStep",
      "slug": "off_2026_04_peter_step_05_retentie_timers"
    },
    "id": "step_01JBCG005",
    "offboarding_id": "off_01JBCF789JKL",
    "step_key": "retentie_timers_starten",
    "status": "completed",
    "completed_by_user_id": "u_12",
    "completed_at": "2026-05-15T10:30:00+02:00",
    "blockers": null,
    "created_at": "2026-02-16T08:30:00+02:00",
    "updated_at": "2026-05-15T10:30:00+02:00"
  }
]
```

---

### 3. Eindafrekening (Severance settlement)

**Purpose:** Immutable record of the final settlement computation combining: statutory leave (EUR), vacation money (EUR), 13th-month pro rata (EUR), transitievergoeding (EUR), and withholdings (EUR). Frozen after HR-admin approval; only retractable with audit entry.

**Schema Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| offboarding_id | UUID | yes | Parent Offboarding case |
| referentiedatum | date | yes | Settlement reference date (usually einddatum) |
| uurloon_op_einddatum | decimal | yes | Gross hourly rate on reference date (EUR, 2 decimals) |
| componenten | object | yes | Nested structure: see below |
| totaal_bruto | decimal | yes | Sum of all components (EUR) |
| totaal_inhoudingen | decimal | yes | Sum of all deductions (EUR) |
| netto_beverage | decimal | yes | totaal_bruto − totaal_inhoudingen |
| goedgekeurd_door_user_id | UUID | no | HR-admin user who approved |
| goedgekeurd_op | datetime | no | Approval timestamp |
| bevroren | boolean | yes | Immutable once true |
| doorgegeven_aan_payroll_op | datetime | no | Timestamp sent to payroll-engine-nl |
| payroll_run_id | string | no | Reference to payroll's run_YYYY_MM identifier |
| ingetrokken_op | datetime | no | If this eindafrekening was revoked, timestamp |
| ingetrokken_reden | string | no | Reason for revocation (if any) |
| correctie_naheffing | boolean | no | Flag: true if retraction happened post-payment |
| created_at | datetime | yes | Auto-set |
| updated_at | datetime | yes | Auto-updated |

**Componenten nested object structure:**

```json
{
  "verlofuren_wettelijk": {
    "saldo_uren": <float>,        // Hours accrued
    "tarief": <decimal>,          // EUR per hour
    "bedrag": <decimal>           // Total (EUR)
  },
  "verlofuren_bovenwettelijk": {
    "saldo_uren": <float>,
    "tarief": <decimal>,
    "bedrag": <decimal>
  },
  "vakantiegeld_resterend": {
    "grondslag": <decimal>,       // Accumulated salary base
    "percentage": <float>,        // 8% minimum
    "reeds_uitbetaald": <decimal>,
    "bedrag": <decimal>           // Final payout (EUR)
  },
  "dertiende_maand_pro_rata": {
    "breukteller": <int>,         // Days worked this year
    "breuknoemer": <int>,         // Total days in year (365)
    "vol_bedrag": <decimal>,      // Full 13th-month amount
    "bedrag": <decimal>           // Pro-rata amount (EUR)
  },
  "transitievergoeding": {
    "dienstjaren": <float>,       // Years + months as decimal
    "maandsalaris": <decimal>,    // Monthly base (EUR)
    "bedrag_bruto": <decimal>,    // Computed 1/3×maand×jaren (EUR)
    "tabel": <string>             // "bijzonder_tarief" (tax treatment)
  },
  "inhouding_lening": {
    "openstaand": <decimal>,      // Outstanding loan balance
    "bedrag": <decimal>           // Deduction (EUR)
  },
  "inhouding_te_veel_verlof": {
    "uren": <float>,              // Excess hours deducted
    "bedrag": <decimal>           // Deduction (EUR)
  },
  "inhouding_apparatuur": {
    "omschrijving": <string>,     // E.g., "MacBook Pro, nicht geretourneerd"
    "bedrag": <decimal>           // Deduction (EUR)
  }
}
```

**Seed Data (3 examples):**

```json
[
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "Eindafrekening",
      "slug": "eind_2026_06_johan"
    },
    "id": "eind_01JBCE45",
    "offboarding_id": "off_01JBCD234EFG",
    "referentiedatum": "2026-07-31",
    "uurloon_op_einddatum": 28.45,
    "componenten": {
      "verlofuren_wettelijk": {
        "saldo_uren": 38.0,
        "tarief": 28.45,
        "bedrag": 1081.10
      },
      "verlofuren_bovenwettelijk": {
        "saldo_uren": 12.5,
        "tarief": 28.45,
        "bedrag": 355.63
      },
      "vakantiegeld_resterend": {
        "grondslag": 18420.00,
        "percentage": 8.0,
        "reeds_uitbetaald": 0.00,
        "bedrag": 1473.60
      },
      "dertiende_maand_pro_rata": {
        "breukteller": 213,
        "breuknoemer": 365,
        "vol_bedrag": 3950.00,
        "bedrag": 2304.66
      },
      "transitievergoeding": {
        "dienstjaren": 4.583,
        "maandsalaris": 3950.00,
        "bedrag_bruto": 4823.17,
        "tabel": "bijzonder_tarief"
      },
      "inhouding_lening": {
        "openstaand": 0.00,
        "bedrag": 0.00
      },
      "inhouding_te_veel_verlof": {
        "uren": 0.0,
        "bedrag": 0.00
      },
      "inhouding_apparatuur": {
        "omschrijving": "n.v.t.",
        "bedrag": 0.00
      }
    },
    "totaal_bruto": 10037.16,
    "totaal_inhoudingen": 0.00,
    "netto_beverage": 10037.16,
    "goedgekeurd_door_user_id": "u_12",
    "goedgekeurd_op": "2026-07-22T11:00:00+02:00",
    "bevroren": true,
    "doorgegeven_aan_payroll_op": "2026-07-22T11:05:00+02:00",
    "payroll_run_id": "run_2026_07_def",
    "ingetrokken_op": null,
    "ingetrokken_reden": null,
    "correctie_naheffing": false,
    "created_at": "2026-07-20T14:11:00+02:00",
    "updated_at": "2026-07-22T11:05:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "Eindafrekening",
      "slug": "eind_2026_05_maria"
    },
    "id": "eind_01JBCE56",
    "offboarding_id": "off_01JBCE456GHI",
    "referentiedatum": "2026-06-30",
    "uurloon_op_einddatum": 35.20,
    "componenten": {
      "verlofuren_wettelijk": {
        "saldo_uren": 48.0,
        "tarief": 35.20,
        "bedrag": 1689.60
      },
      "verlofuren_bovenwettelijk": {
        "saldo_uren": 20.0,
        "tarief": 35.20,
        "bedrag": 704.00
      },
      "vakantiegeld_resterend": {
        "grondslag": 24800.00,
        "percentage": 8.0,
        "reeds_uitbetaald": 400.00,
        "bedrag": 1552.00
      },
      "dertiende_maand_pro_rata": {
        "breukteller": 182,
        "breuknoemer": 365,
        "vol_bedrag": 5200.00,
        "bedrag": 2598.90
      },
      "transitievergoeding": {
        "dienstjaren": 6.25,
        "maandsalaris": 5200.00,
        "bedrag_bruto": 7150.50,
        "tabel": "bijzonder_tarief"
      },
      "inhouding_lening": {
        "openstaand": 150.00,
        "bedrag": 150.00
      },
      "inhouding_te_veel_verlof": {
        "uren": 0.0,
        "bedrag": 0.00
      },
      "inhouding_apparatuur": {
        "omschrijving": "n.v.t.",
        "bedrag": 0.00
      }
    },
    "totaal_bruto": 15247.50,
    "totaal_inhoudingen": 150.00,
    "netto_beverage": 15097.50,
    "goedgekeurd_door_user_id": "u_12",
    "goedgekeurd_op": "2026-06-25T10:30:00+02:00",
    "bevroren": true,
    "doorgegeven_aan_payroll_op": "2026-06-25T10:35:00+02:00",
    "payroll_run_id": "run_2026_06_def",
    "ingetrokken_op": null,
    "ingetrokken_reden": null,
    "correctie_naheffing": false,
    "created_at": "2026-06-20T09:00:00+02:00",
    "updated_at": "2026-06-25T10:35:00+02:00"
  }
]
```

---

### 4. EquipmentReturn (Inventory tracking)

**Purpose:** Per-item equipment return record, including condition assessment and inhouding-if-unreturned amount.

**Schema Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| offboarding_id | UUID | yes | Parent Offboarding case |
| categorie | enum | yes | `laptop`, `desktop`, `monitor`, `phone`, `tablet`, `keys`, `access_card`, `vpn_dongle`, `other` |
| asset_tag | string | yes | Inventory asset identifier (e.g., "L-2023-0142") |
| beschrijving | string | no | Model/description (e.g., "MacBook Pro 16 inch, 2021") |
| uitgegeven_op | date | yes | Date item was issued to employee |
| verwacht_geretourneerd_op | date | yes | Expected return date (usually last_werkdag) |
| geretourneerd_op | date | no | Actual return date (if completed) |
| ontvangen_door_user_id | UUID | no | User who received returned item |
| staat | enum | no | Condition if returned: `goed`, `gebruik_sporen`, `beschadigd`, `niet_bruikbaar` |
| opmerking | string | no | Free-text notes (e.g., "Inclusief lader; toetsenbord licht versleten") |
| inhouding_indien_niet_geretourneerd | decimal | yes | Deduction amount if never returned (EUR) |
| inhouding_reden | enum | no | `verloren`, `gestolen`, `beschadigd_onherstelbaar`, `niet_ingeleverd` |
| created_at | datetime | yes | Auto-set |
| updated_at | datetime | yes | Auto-updated |

**Seed Data (4 examples for Johan Pieterzoon):**

```json
[
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "EquipmentReturn",
      "slug": "eq_2026_06_johan_laptop"
    },
    "id": "eq_01JBCF67",
    "offboarding_id": "off_01JBCD234EFG",
    "categorie": "laptop",
    "asset_tag": "L-2023-0142",
    "beschrijving": "MacBook Pro 14 inch, Space Gray, 2021",
    "uitgegeven_op": "2023-03-15",
    "verwacht_geretourneerd_op": "2026-07-29",
    "geretourneerd_op": "2026-07-29",
    "ontvangen_door_user_id": "u_99",
    "staat": "goed",
    "opmerking": "Inclusief lader; toetsenbord licht versleten",
    "inhouding_indien_niet_geretourneerd": 850.00,
    "inhouding_reden": null,
    "created_at": "2026-06-20T09:00:00+02:00",
    "updated_at": "2026-07-29T16:30:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "EquipmentReturn",
      "slug": "eq_2026_06_johan_phone"
    },
    "id": "eq_01JBCF68",
    "offboarding_id": "off_01JBCD234EFG",
    "categorie": "phone",
    "asset_tag": "P-2024-0089",
    "beschrijving": "iPhone 14 Pro, Space Black, 256GB",
    "uitgegeven_op": "2024-01-10",
    "verwacht_geretourneerd_op": "2026-07-29",
    "geretourneerd_op": "2026-07-29",
    "ontvangen_door_user_id": "u_99",
    "staat": "goed",
    "opmerking": "Inclusief SIM-uitwerper",
    "inhouding_indien_niet_geretourneerd": 350.00,
    "inhouding_reden": null,
    "created_at": "2026-06-20T09:00:00+02:00",
    "updated_at": "2026-07-29T16:35:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "EquipmentReturn",
      "slug": "eq_2026_06_johan_keys"
    },
    "id": "eq_01JBCF69",
    "offboarding_id": "off_01JBCD234EFG",
    "categorie": "keys",
    "asset_tag": "K-OFF-001",
    "beschrijving": "Sleutel kantoor + serverruimte + kast archief",
    "uitgegeven_op": "2020-06-01",
    "verwacht_geretourneerd_op": "2026-07-29",
    "geretourneerd_op": "2026-07-28",
    "ontvangen_door_user_id": "u_55",
    "staat": "goed",
    "opmerking": "Alle drie sleutels ontvangen",
    "inhouding_indien_niet_geretourneerd": 0.00,
    "inhouding_reden": null,
    "created_at": "2026-06-20T09:00:00+02:00",
    "updated_at": "2026-07-28T14:00:00+02:00"
  }
]
```

---

### 5. ExitInterview (Feedback capture)

**Purpose:** Structured feedback from departing employee (satisfaction, reason categories, recommend status), captured anonymously 90 days post-departure per company retention policy.

**Schema Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| offboarding_id | UUID | yes | Parent Offboarding case |
| afgenomen_door_user_id | UUID | yes | HR user who conducted interview |
| afgenomen_op | datetime | yes | Interview timestamp |
| modaliteit | enum | yes | `in_persoon`, `telefoongesprek`, `video`, `schriftelijk` |
| antwoorden | object | yes | Nested structure: see below |
| anonimiteit | enum | yes | `geanonimiseerd_na_90_dagen`, `geanonimiseerd_onmiddellijk`, `niet_geanonimiseerd` |
| created_at | datetime | yes | Auto-set |
| updated_at | datetime | yes | Auto-updated |

**Antwoorden nested object:**

```json
{
  "tevredenheid_werk_1_10": <int>,        // 1-10 scale
  "tevredenheid_leidinggevende_1_10": <int>,
  "redenen_vertrek": [<string>, ...],     // Array: "carriere_groei", "salaris", "werksfeer", "werk_leven_balans", etc.
  "zou_aanbevelen": <boolean>,
  "open_feedback": <string>               // Free-text comments
}
```

**Seed Data (2 examples):**

```json
[
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "ExitInterview",
      "slug": "exit_2026_06_johan"
    },
    "id": "exit_01JBCG89",
    "offboarding_id": "off_01JBCD234EFG",
    "afgenomen_door_user_id": "u_12",
    "afgenomen_op": "2026-07-25T14:00:00+02:00",
    "modaliteit": "in_persoon",
    "antwoorden": {
      "tevredenheid_werk_1_10": 7,
      "tevredenheid_leidinggevende_1_10": 8,
      "redenen_vertrek": ["carriere_groei", "salaris"],
      "zou_aanbevelen": true,
      "open_feedback": "Goede sfeer, maar weinig doorgroei in mijn functie. Nieuwe baan biedt meer mogelijkheden."
    },
    "anonimiteit": "geanonimiseerd_na_90_dagen",
    "created_at": "2026-07-25T14:00:00+02:00",
    "updated_at": "2026-07-25T14:00:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "ExitInterview",
      "slug": "exit_2026_05_maria"
    },
    "id": "exit_01JBCH01",
    "offboarding_id": "off_01JBCE456GHI",
    "afgenomen_door_user_id": "u_12",
    "afgenomen_op": "2026-06-20T10:30:00+02:00",
    "modaliteit": "telefoongesprek",
    "antwoorden": {
      "tevredenheid_werk_1_10": 5,
      "tevredenheid_leidinggevende_1_10": 6,
      "redenen_vertrek": ["reorganisatie", "onzekerheid_toekomst"],
      "zou_aanbevelen": false,
      "open_feedback": "Reorganisatie was onverwacht. Voelde me niet betrokken in het proces. Snap de zakelijke redenen, maar had meer communicatie verwacht."
    },
    "anonimiteit": "geanonimiseerd_na_90_dagen",
    "created_at": "2026-06-20T10:30:00+02:00",
    "updated_at": "2026-06-20T10:30:00+02:00"
  }
]
```

---

### 6. Getuigschrift (Work certificate)

**Purpose:** Work certificate per art. 7:656 BW (mandatory upon request). Template-rendered, manager-signed via eIDAS, stored with long-term archival.

**Schema Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| offboarding_id | UUID | yes | Parent Offboarding case |
| template_id | string | yes | Reference to docudesk template (e.g., "template_getuigschrift_v2") |
| opgesteld_door_user_id | UUID | yes | HR user who drafted |
| ondertekend_door_user_id | UUID | yes | Manager user who signed (eIDAS) |
| document_id | string | yes | Reference to stored docudesk document |
| verstrekt_op | date | no | Date certificate was delivered to employee |
| type | enum | yes | `feitelijk` (factual only), `kwalitatief` (with evaluation, if requested) |
| bevat_kwalitatief_oordeel | boolean | yes | Whether qualitative assessment is included |
| created_at | datetime | yes | Auto-set |
| updated_at | datetime | yes | Auto-updated |

**Seed Data (2 examples):**

```json
[
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "Getuigschrift",
      "slug": "get_2026_07_johan"
    },
    "id": "get_01JBCH12",
    "offboarding_id": "off_01JBCD234EFG",
    "template_id": "template_getuigschrift_v2",
    "opgesteld_door_user_id": "u_12",
    "ondertekend_door_user_id": "u_88",
    "document_id": "doc_get_01JBCH",
    "verstrekt_op": "2026-07-29",
    "type": "feitelijk",
    "bevat_kwalitatief_oordeel": false,
    "created_at": "2026-07-25T14:00:00+02:00",
    "updated_at": "2026-07-29T09:00:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "Getuigschrift",
      "slug": "get_2026_05_maria"
    },
    "id": "get_01JBCH23",
    "offboarding_id": "off_01JBCE456GHI",
    "template_id": "template_getuigschrift_v2",
    "opgesteld_door_user_id": "u_12",
    "ondertekend_door_user_id": "u_45",
    "document_id": "doc_get_01JBCH_maria",
    "verstrekt_op": "2026-06-28",
    "type": "feitelijk",
    "bevat_kwalitatief_oordeel": false,
    "created_at": "2026-06-18T10:00:00+02:00",
    "updated_at": "2026-06-28T14:00:00+02:00"
  }
]
```

---

### 7. RetentionTimer (Data destruction schedule)

**Purpose:** Immutable timer for cryptographic destruction of archived artefacts, triggered on case completion (`afgerond_op`). Timers are per-artefact-category with wettelijke gronadslag (7y fiscal, 5y labour, 2y recruitment, etc.).

**Schema Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| offboarding_id | UUID | yes | Parent Offboarding case |
| artefact_type | enum | yes | `wid_kopie`, `salarisstrook`, `jaaropgaaf`, `contract`, `beoordelingsverslag`, `ziekteverzuim_dossier`, `functionerings_dossier`, `declaratie`, `correspondentie`, `exit_interview` |
| artefact_referentie | string | yes | Document or storage reference ID |
| gestart_op | date | yes | Date timer begins (usually case afgerond_op) |
| vervalt_op | date | yes | Date when destruction MUST occur |
| grondslag | string | yes | Legal basis enum: `art_28_uitvoeringsregeling_lb` (7y fiscal), `art_78_arbeidswet` (5y labour), `eigen_behoud` (2y recruitment), etc. |
| vernietigd_op | date | no | Timestamp when cryptographic destruction completed |
| vernietigingsmethode | enum | no | `key_destruction` (encrypted), `overwrite_7pass` (plaintext) |
| created_at | datetime | yes | Auto-set |
| updated_at | datetime | yes | Auto-updated |

**Seed Data (3 examples for Peter Jansen's completed case):**

```json
[
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "RetentionTimer",
      "slug": "ret_2026_04_peter_wid"
    },
    "id": "ret_01JBCJ34",
    "offboarding_id": "off_01JBCF789JKL",
    "artefact_type": "wid_kopie",
    "artefact_referentie": "doc_id_01HXZ",
    "gestart_op": "2026-05-15",
    "vervalt_op": "2031-05-15",
    "grondslag": "art_28_uitvoeringsregeling_lb",
    "vernietigd_op": null,
    "vernietigingsmethode": null,
    "created_at": "2026-05-15T10:30:00+02:00",
    "updated_at": "2026-05-15T10:30:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "RetentionTimer",
      "slug": "ret_2026_04_peter_contract"
    },
    "id": "ret_01JBCJ35",
    "offboarding_id": "off_01JBCF789JKL",
    "artefact_type": "contract",
    "artefact_referentie": "doc_contract_01HXZ",
    "gestart_op": "2026-05-15",
    "vervalt_op": "2031-05-15",
    "grondslag": "art_78_arbeidswet",
    "vernietigd_op": null,
    "vernietigingsmethode": null,
    "created_at": "2026-05-15T10:30:00+02:00",
    "updated_at": "2026-05-15T10:30:00+02:00"
  },
  {
    "@self": {
      "register": "hrmq_offboarding",
      "schema": "RetentionTimer",
      "slug": "ret_2026_04_peter_exit_interview"
    },
    "id": "ret_01JBCJ36",
    "offboarding_id": "off_01JBCF789JKL",
    "artefact_type": "exit_interview",
    "artefact_referentie": "exit_01JBCG89",
    "gestart_op": "2026-05-15",
    "vervalt_op": "2026-08-15",
    "grondslag": "eigen_behoud",
    "vernietigd_op": null,
    "vernietigingsmethode": null,
    "created_at": "2026-05-15T10:30:00+02:00",
    "updated_at": "2026-05-15T10:30:00+02:00"
  }
]
```

---

## API Integration Points

### Inbound (reads from peer apps)

- **employee-master** — Fetch employee record (BSN, IBAN, dienstverband-start, current salary). Read-only during offboarding.
- **contract-management** — Fetch active contract (opzegtermijn, non-concurrence, relatie-beding) for compliance check.
- **payroll-engine-nl** — Fetch maandsalaris components and historical gross for 12-month salary averaging.

### Outbound (writes to peer apps)

- **employee-master** — Write: `uit_dienst_per`, `reden_uit_dienst`, `laatste_werkdag`, `status=inactive` on case completion.
- **payroll-engine-nl** — Push frozen Eindafrekening; receive final strook + jaaropgaaf reference back.
- **docudesk** — Render getuigschrift template, manage eIDAS signature, store final PDF.
- **openconnector** — Transmit UWV WW-melding, pensioenfonds-afmelding, ZVW-afmelding.
- **shillinq** — Transmit Eindafrekening AP-entry (4xxx personnel costs, 1xxx payable creditor, 1xxx overpayment relief) + any inhouding-correcties.
- **Nextcloud user management** — Disable user via OCS Users API; set mail-forward; trigger data-export; schedule deletion day 30+.

### Reporting / Audit

- **Audit Trail Service** — Full change tracking (before/after snapshots, actor, timestamp) per OpenRegister pattern.
- **GDPR-verwerkingsregister** — Automatically log all data-subject access requests, exports, destruction confirmations.

---

## Reuse Analysis

The offboarding-wizard change leverages **existing OpenRegister infrastructure**:

- **ObjectService** — CRUD operations on all 7 entity types (no custom DAO layer required)
- **CnIndexPage + CnDataTable** — Case list view with filtering/sorting/pagination
- **CnDetailPage** — Case detail view with step-progress visualization
- **CnFormDialog** — Auto-generated forms for all entities (driven by OpenRegister schemas)
- **AuditTrailService** — Automatic change tracking; audit-tab in case sidebar
- **FileService** — Attachment storage for getuigschrift PDFs, data-exports, certificates
- **NotificationService** — Notify HR/manager when step completion is required
- **TasksController** — Create workflow tasks per OffboardingStep
- **WebhookService** — Emit CloudEvents for payroll-integration, IT-deactivation, etc.

**Custom code required only for:**
- Eindafrekening computation logic (deterministic formula + component audit-trail)
- Reden → toepasselijkheid mapping (statute-based rules)
- Manager-handover-checklist validation (block rules)
- Goodbye-communicatie template + distribution
- Retention-timer auto-creation and destruction-job scheduling
- Integration glue with payroll-engine-nl, openconnector, docudesk

---

## Data Governance

- **Encryption at rest** (per organization's MFA-backed key storage)
- **Field-level audit** (every numeric field in Eindafrekening logged before/after per component)
- **Deletion schedule** (RetentionTimer-driven crypto-erase with immutable audit log)
- **Anonymization policy** (ExitInterview geanonimiseerd_na_90_dagen per GDPR recital 26)
- **Access controls** (PropertyRbacHandler: HR-admin only on Eindafrekening.goedkeuren, IT-owner only on data-export steps)

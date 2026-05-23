---
status: design
date: 2026-05-23
---

# Onboarding workflow — Design

## Entity Schemas

### Onboarding (case)

Top-level case object. One per `employee_id` (uniqueness enforced; rehires get a new case with `is_rehire: true`).

**Type:** `schema:Event` (using schema.org Event as the base for time-bound lifecycle)

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string (ULID) | yes | Unique identifier, prefixed `ob_` |
| `employee_id` | string (FK) | yes | Reference to `Employee` record |
| `status` | enum | yes | Current state in machine: `aangenomen`, `contract_aangemaakt`, `contract_verzonden`, `contract_getekend`, `id_geverifieerd`, `bsn_gevalideerd`, `iban_geverifieerd`, `pensioen_aangemeld`, `zvw_gemeld`, `it_provisioned`, `eerste_werkdag`, `proeftijd_lopend`, `proeftijd_afgerond` |
| `start_date` | date (ISO-8601) | yes | Employee start date |
| `is_rehire` | boolean | no | True if rehiring previous employee |
| `previous_offboarding_id` | string (FK) | no | Link to prior offboarding case if rehire |
| `recruiter_user_id` | string (FK) | yes | Recruiter who opened the case |
| `hiring_manager_user_id` | string (FK) | yes | Line manager responsible for hire |
| `hr_owner_user_id` | string (FK) | yes | HR officer owning the case |
| `it_owner_user_id` | string (FK) | yes | IT admin responsible for provisioning |
| `mentor_user_id` | string (FK) | no | Assigned mentor for first-week guidance |
| `payroll_ready` | boolean | no | Computed: contract voltooid AND BSN valid AND IBAN valid AND ZVW bevestigd AND pensioen bevestigd (or niet_van_toepassing) |
| `payroll_ready_at` | datetime (ISO-8601) | no | Timestamp when payroll_ready became true |
| `checklist_completion_pct` | integer (0-100) | no | Percentage of non-blocking checklist items completed |
| `vog_required` | boolean | no | True if employer background check (VOG) is required for role |
| `vog_status` | enum | no | VOG state: `niet_van_toepassing`, `aangevraagd`, `in_behandeling`, `goedgekeurd`, `geweigerd` |
| `proeftijd_einddatum` | date (ISO-8601) | no | End date of probationary period (Wet Arbeidsmarkt in Balans) |
| `created_at` | datetime (ISO-8601) | yes | Case creation timestamp |
| `updated_at` | datetime (ISO-8601) | yes | Last modification timestamp |

### OnboardingStep

Each row is one of the fixed 15 wizard steps; created lazily on first touch.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string (ULID) | yes | Unique identifier, prefixed `obs_` |
| `onboarding_id` | string (FK) | yes | Reference to parent `Onboarding` |
| `step_key` | enum | yes | Fixed keys: `contract_aanmaken`, `contract_versturen`, `contract_ondertekenen`, `id_upload`, `wid_check`, `bsn_validatie`, `iban_verificatie`, `pensioen_aanmelding`, `zvw_melding`, `it_provisioning`, `bedrijfskleding`, `laptop_uitgifte`, `mentor_toewijzing`, `vog_aanvraag`, `eerste_werkdag_checklist` |
| `status` | enum | yes | `openstaand`, `in_bewerking`, `voltooid`, `geblokkeerd` |
| `voltooid_door_user_id` | string (FK) | no | User who marked step complete |
| `voltooid_op` | datetime (ISO-8601) | no | Completion timestamp |
| `blocking` | boolean | yes | If true, step must be voltooid before case can advance |
| `payload` | JSON | no | Step-specific data (e.g. IBAN, BSN hash, document references) |
| `audit_evidence_id` | string | no | Reference to attached artefact (docudesk ID or internal doc ID) |

### WIDCheck

Wet op de Identificatieplicht evidence.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string (ULID) | yes | Unique identifier, prefixed `wid_` |
| `onboarding_id` | string (FK) | yes | Reference to parent `Onboarding` |
| `document_type` | enum | yes | `paspoort`, `nationaal_id_bewijs`, `rijbewijs` (EU-accepted ID types) |
| `document_nummer_hash` | string | yes | SHA-256 hash of document number (salted) |
| `uitgegeven_door` | string | no | Issuing authority (e.g. "Burgemeester van Amsterdam") |
| `geldig_tot` | date (ISO-8601) | yes | Document expiry date |
| `gecontroleerd_door_user_id` | string (FK) | yes | HR officer who verified document |
| `gecontroleerd_op` | datetime (ISO-8601) | yes | Verification timestamp |
| `fysiek_gezien` | boolean | yes | True if original document physically inspected |
| `fysiek_gezien_reden` | string | no | Exception reason if `fysiek_gezien = false` (e.g. "hybrid procedure, remote verification per COVID protocol 2026") |
| `kopie_versie` | string | yes | Document-archive version stamp (e.g. `wid_kopie_2024`) |
| `kopie_opslag_document_id` | string | yes | docudesk document ID (restricted ACL: HR + auditor) |
| `kopie_bewaartermijn_einde` | date (ISO-8601) | yes | Retention expiry: start_date + 5 years per Uitvoeringsregeling LB art. 28 |

### BSNValidatie

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string (ULID) | yes | Unique identifier, prefixed `bsn_` |
| `onboarding_id` | string (FK) | yes | Reference to parent `Onboarding` |
| `bsn_hash` | string | yes | SHA-256 hash of BSN (salted) |
| `elfproef_resultaat` | enum | yes | `geldig`, `ongeldig` |
| `format_resultaat` | enum | yes | `geldig`, `ongeldig` (9 digits, no leading zeros, no all-same) |
| `gevalideerd_op` | datetime (ISO-8601) | yes | Validation timestamp |
| `bron` | enum | yes | Source: `wid_check`, `employee_master`, `manual_entry` |

### ContractSigningEvent

eIDAS signature receipt from docudesk webhook.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string (ULID) | yes | Unique identifier, prefixed `sign_` |
| `onboarding_id` | string (FK) | yes | Reference to parent `Onboarding` |
| `contract_document_id` | string | yes | docudesk document ID (contract PDF) |
| `envelope_id` | string | yes | docudesk envelope ID |
| `ondertekenmethode` | enum | yes | `eidas_qes`, `eidas_aes` (SES rejected) |
| `ondertekend_door` | string | yes | Signer email address |
| `ondertekend_op` | datetime (ISO-8601) | yes | Signature timestamp |
| `ip_adres` | string | yes | Signer IP address (last octet masked: e.g. `82.157.x.x`) |
| `certificaat_serienummer` | string | yes | X.509 serial number |
| `lta_archive_id` | string | yes | Long-term archive identifier (docudesk LTA) for post-expiry verification |

### Reminder

Task reminder + escalation tracking.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string (ULID) | yes | Unique identifier, prefixed `rem_` |
| `onboarding_id` | string (FK) | yes | Reference to parent `Onboarding` |
| `step_key` | enum | yes | Associated step key |
| `geadresseerde_user_id` | string (FK) | yes | Primary recipient |
| `kanaal` | enum | yes | Delivery: `email`, `nextcloud_notification`, `email_plus_nextcloud_notification` |
| `trigger_at` | datetime (ISO-8601) | yes | Scheduled send time |
| `verzonden_op` | datetime (ISO-8601) | no | Actual send timestamp |
| `escalatie_niveau` | integer (1-3) | yes | Escalation level: 1=initial, 2=second unacknowledged, 3=director/HR-manager |
| `escalatie_naar_user_id` | string (FK) | no | Escalation recipient if reminder unacknowledged |

### OnboardingChecklistItem

Non-blocking side-tasks (eerste-werkdag, equipment, onboarding activities).

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string (ULID) | yes | Unique identifier, prefixed `cli_` |
| `onboarding_id` | string (FK) | yes | Reference to parent `Onboarding` |
| `categorie` | enum | yes | `eerste_werkdag`, `ausrusting`, `bedrijfskleding`, `administrative` |
| `titel` | string | yes | Human-readable task title |
| `verantwoordelijke_user_id` | string (FK) | yes | User responsible for task |
| `due_op` | date (ISO-8601) | yes | Due date |
| `status` | enum | yes | `open`, `in_bewerking`, `voltooid`, `overdue` |
| `opmerking` | string | no | Notes (max 500 chars) |

### Proeftijd (optional, embedded in Onboarding or separate table)

Probationary period tracking (if split into separate entity).

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `id` | string (ULID) | yes | Unique identifier, prefixed `ptd_` |
| `onboarding_id` | string (FK) | yes | Reference to parent `Onboarding` |
| `einddatum` | date (ISO-8601) | yes | Probationary end date |
| `status` | enum | yes | `lopend`, `afgerond_geslaagd`, `afgerond_beëindigd`, `verlenging_afgewezen` |
| `t7_notificatie_verzonden_op` | datetime (ISO-8601) | no | Timestamp of T-7 notification |
| `t2_notificatie_verzonden_op` | datetime (ISO-8601) | no | Timestamp of T-2 notification |
| `afgerond_op` | datetime (ISO-8601) | no | Completion timestamp |
| `afgerond_door_user_id` | string (FK) | no | User who closed proeftijd |

## Seed Data

### Seed: Onboarding Case (Successful Flow)

```json
{
  "id": "ob_01HXYZ123ABC",
  "employee_id": "emp_01HXYW987DEF",
  "status": "contract_getekend",
  "start_date": "2026-06-01",
  "is_rehire": false,
  "previous_offboarding_id": null,
  "recruiter_user_id": "u_42",
  "hiring_manager_user_id": "u_88",
  "hr_owner_user_id": "u_12",
  "it_owner_user_id": "u_99",
  "mentor_user_id": "u_55",
  "payroll_ready": false,
  "payroll_ready_at": null,
  "checklist_completion_pct": 62,
  "vog_required": true,
  "vog_status": "aangevraagd",
  "proeftijd_einddatum": "2026-07-31",
  "created_at": "2026-05-08T09:14:00+02:00",
  "updated_at": "2026-05-20T11:02:00+02:00"
}
```

### Seed: OnboardingStep Instances

```json
[
  {
    "id": "obs_01HXZ001",
    "onboarding_id": "ob_01HXYZ123ABC",
    "step_key": "contract_aanmaken",
    "status": "voltooid",
    "voltooid_door_user_id": "u_12",
    "voltooid_op": "2026-05-08T10:22:00+02:00",
    "blocking": true,
    "payload": {
      "template_id": "contract_tmpl_001",
      "template_naam": "Arbeidsovereenkomst vast onbepaalde duur",
      "render_status": "success"
    },
    "audit_evidence_id": "doc_contract_draft_01HXZ"
  },
  {
    "id": "obs_01HXZ002",
    "onboarding_id": "ob_01HXYZ123ABC",
    "step_key": "contract_versturen",
    "status": "voltooid",
    "voltooid_door_user_id": "u_12",
    "voltooid_op": "2026-05-08T11:35:00+02:00",
    "blocking": true,
    "payload": {
      "envelope_id": "docudesk_env_447",
      "verzonden_naar": "jan.dejong@voorbeeld.nl",
      "verzonden_op": "2026-05-08T11:35:00+02:00"
    },
    "audit_evidence_id": "docudesk_env_447"
  },
  {
    "id": "obs_01HXZ003",
    "onboarding_id": "ob_01HXYZ123ABC",
    "step_key": "contract_ondertekenen",
    "status": "voltooid",
    "voltooid_door_user_id": null,
    "voltooid_op": "2026-05-16T14:08:00+02:00",
    "blocking": true,
    "payload": {
      "ondertekenmethode": "eidas_qes",
      "ondertekend_door": "jan.dejong@voorbeeld.nl",
      "ondertekend_op": "2026-05-16T14:08:00+02:00",
      "certificaat_serienummer": "00:A3:1F:..."
    },
    "audit_evidence_id": "sign_01HXZB34"
  },
  {
    "id": "obs_01HXZ004",
    "onboarding_id": "ob_01HXYZ123ABC",
    "step_key": "id_upload",
    "status": "voltooid",
    "voltooid_door_user_id": "emp_01HXYW987DEF",
    "voltooid_op": "2026-05-17T16:45:00+02:00",
    "blocking": true,
    "payload": {
      "document_type": "paspoort",
      "upload_timestamp": "2026-05-17T16:45:00+02:00",
      "document_storage_id": "doc_id_01HXZ"
    },
    "audit_evidence_id": "doc_id_01HXZ"
  },
  {
    "id": "obs_01HXZ005",
    "onboarding_id": "ob_01HXYZ123ABC",
    "step_key": "wid_check",
    "status": "voltooid",
    "voltooid_door_user_id": "u_12",
    "voltooid_op": "2026-05-18T09:30:00+02:00",
    "blocking": true,
    "payload": {
      "document_type": "paspoort",
      "document_nummer_hash": "sha256:7f3a...",
      "fiziek_gezien": true,
      "geldig_tot": "2031-04-12"
    },
    "audit_evidence_id": "wid_01HXZ789"
  },
  {
    "id": "obs_01HXZ006",
    "onboarding_id": "ob_01HXYZ123ABC",
    "step_key": "bsn_validatie",
    "status": "openstaand",
    "voltooid_door_user_id": null,
    "voltooid_op": null,
    "blocking": true,
    "payload": null,
    "audit_evidence_id": null
  },
  {
    "id": "obs_01HXZ007",
    "onboarding_id": "ob_01HXYZ123ABC",
    "step_key": "iban_verificatie",
    "status": "voltooid",
    "voltooid_door_user_id": "u_12",
    "voltooid_op": "2026-05-18T15:22:00+02:00",
    "blocking": true,
    "payload": {
      "iban": "NL91ABNA0417164300",
      "verificatie_methode": "iban_modulo97_plus_sepa_name_check",
      "validatie_resultaat": "geldig",
      "naam_op_rekening_match": "exact"
    },
    "audit_evidence_id": "doc_iban_01HXZ"
  }
]
```

### Seed: WIDCheck Record

```json
{
  "id": "wid_01HXZ789",
  "onboarding_id": "ob_01HXYZ123ABC",
  "document_type": "paspoort",
  "document_nummer_hash": "sha256:7f3a8e2c1b9d4f5a6c2e8b1d9f4a7c3e",
  "uitgegeven_door": "Burgemeester van Amsterdam",
  "geldig_tot": "2031-04-12",
  "gecontroleerd_door_user_id": "u_12",
  "gecontroleerd_op": "2026-05-18T10:30:00+02:00",
  "fysiek_gezien": true,
  "fysiek_gezien_reden": null,
  "kopie_versie": "wid_kopie_2024",
  "kopie_opslag_document_id": "doc_id_01HXZ",
  "kopie_bewaartermijn_einde": "2031-06-01"
}
```

### Seed: BSNValidatie Record

```json
{
  "id": "bsn_01HXZA12",
  "onboarding_id": "ob_01HXYZ123ABC",
  "bsn_hash": "sha256:b91e3f2a8c1d4e9f6a2b7c1e4a9d2f5b",
  "elfproef_resultaat": "geldig",
  "format_resultaat": "geldig",
  "gevalideerd_op": "2026-05-15T10:32:00+02:00",
  "bron": "wid_check"
}
```

### Seed: ContractSigningEvent

```json
{
  "id": "sign_01HXZB34",
  "onboarding_id": "ob_01HXYZ123ABC",
  "contract_document_id": "doc_contract_01HXZ",
  "envelope_id": "docudesk_env_447",
  "ondertekenmethode": "eidas_qes",
  "ondertekend_door": "jan.dejong@voorbeeld.nl",
  "ondertekend_op": "2026-05-16T14:08:00+02:00",
  "ip_adres": "82.157.x.x",
  "certificaat_serienummer": "00:A3:1F:C2:4D:B8:E1:9A:7F",
  "lta_archive_id": "lta_447"
}
```

### Seed: Reminder Records (Escalation Flow)

```json
[
  {
    "id": "rem_01HXZC56",
    "onboarding_id": "ob_01HXYZ123ABC",
    "step_key": "contract_ondertekenen",
    "geadresseerde_user_id": "emp_01HXYW987DEF",
    "kanaal": "email_plus_nextcloud_notification",
    "trigger_at": "2026-05-11T09:00:00+02:00",
    "verzonden_op": "2026-05-11T09:15:00+02:00",
    "escalatie_niveau": 1,
    "escalatie_naar_user_id": "u_42"
  },
  {
    "id": "rem_01HXZC57",
    "onboarding_id": "ob_01HXYZ123ABC",
    "step_key": "contract_ondertekenen",
    "geadresseerde_user_id": "u_42",
    "kanaal": "nextcloud_notification",
    "trigger_at": "2026-05-13T09:00:00+02:00",
    "verzonden_op": "2026-05-13T09:10:00+02:00",
    "escalatie_niveau": 2,
    "escalatie_naar_user_id": "u_12"
  }
]
```

### Seed: OnboardingChecklistItem (First-Day Tasks)

```json
[
  {
    "id": "cli_01HXZD78",
    "onboarding_id": "ob_01HXYZ123ABC",
    "categorie": "eerste_werkdag",
    "titel": "Sleutelpas activeren",
    "verantwoordelijke_user_id": "u_99",
    "due_op": "2026-06-01",
    "status": "open",
    "opmerking": ""
  },
  {
    "id": "cli_01HXZD79",
    "onboarding_id": "ob_01HXYZ123ABC",
    "categorie": "ausrusting",
    "titel": "Laptop ophalen van magazijn",
    "verantwoordelijke_user_id": "u_99",
    "due_op": "2026-06-01",
    "status": "open",
    "opmerking": "Dell XPS 15 reserved, serial #ABC12345"
  },
  {
    "id": "cli_01HXZD80",
    "onboarding_id": "ob_01HXYZ123ABC",
    "categorie": "bedrijfskleding",
    "titel": "Bedrijfsjas en naamplaatje bestellen",
    "verantwoordelijke_user_id": "u_88",
    "due_op": "2026-05-25",
    "status": "in_bewerking",
    "opmerking": "Maat L besteld bij Kleiderfabriek NL, verwacht 23 mei"
  }
]
```

### Seed: Proeftijd Record (if separate table)

```json
{
  "id": "ptd_01HXZD81",
  "onboarding_id": "ob_01HXYZ123ABC",
  "einddatum": "2026-07-31",
  "status": "lopend",
  "t7_notificatie_verzonden_op": null,
  "t2_notificatie_verzonden_op": null,
  "afgerond_op": null,
  "afgerond_door_user_id": null
}
```

## UI/UX Design Notes

### Wizard Stepper Layout

- **Fixed 15 steps** displayed in left sidebar or horizontal stepper
- **Visual distinction:** blocking (red/warning icon) vs. non-blocking (grey) steps
- **Step state indicators:** openstaand (white), in_bewerking (blue), voltooid (green), geblokkeerd (red)
- **Lock icon** on completed steps with timestamp + completed-by user name
- **Progress indicator:** X of 15 steps completed + overall % for checklist items

### Step Form Components

Each step form includes:
- **Section header** (step title + description)
- **Required fields** (marked with asterisk)
- **File upload** (for ID, WID, documents) with drag-drop + format validation
- **Inline validation** (IBAN checksum, BSN elfproef, name-match warnings)
- **Evidence attachment** (docudesk links, embedded PDF preview where applicable)
- **Audit trail snippet** (last 3 events for this step, expandable)
- **Action buttons:** Save Draft, Mark Complete (if all required fields filled)

### Reminders & Escalation Notifications

- **Email:** plain text with magic link to Nextcloud (no login required if token-valid)
- **Nextcloud notification:** action button redirects to relevant step in wizard
- **Escalation email:** subject line indicates it's a level-2 or level-3 escalation ("Urgent: Contract signature overdue")

### AVG-DSR Export

- **Triggered from:** case header (three-dot menu → "AVG Gegevensuitvraag")
- **PDF generation:** async job, queued, notification sent when ready
- **PDF structure:** personal-data snapshot + audit-trail table + retention schedule + eIDAS signature block
- **Security:** encrypted to HR+auditor ACL only, not downloadable by other roles

## State Machine Diagram

```
aangenomen
  ↓
contract_aangemaakt (step: contract_aanmaken voltooid)
  ↓
contract_verzonden (step: contract_versturen voltooid)
  ↓
contract_getekend (docudesk webhook: ondertekend → ContractSigningEvent created)
  ↓
id_geverifieerd (step: id_upload + wid_check voltooid)
  ↓
bsn_gevalideerd (step: bsn_validatie voltooid + elfproef pass)
  ↓
iban_geverifieerd (step: iban_verificatie voltooid + modulus-97 pass)
  ↓
pensioen_aangemeld (step: pensioen_aanmelding voltooid OR niet_van_toepassing marked)
  ↓
zvw_gemeld (step: zvw_melding voltooid)
  ↓
it_provisioned (step: it_provisioning voltooid + OCS user created)
  ↓
eerste_werkdag (start_date reached, first-day checklist visible)
  ↓
proeftijd_lopend (proeftijd clause exists, watchers active)
  ↓
proeftijd_afgerond (T-2 escalation resolved + manual action OR auto-close on T-0)

Anytime: geannuleerd (cancellation allowed from any state)
```

## Integration Points

### docudesk Webhook Flow
1. HR creates contract via `contract_aanmaken` step
2. Contract rendered by contract-management spec, sent to docudesk via `contract_versturen` step
3. Candidate receives e-signing link (via email + self-service portal)
4. Docudesk signs contract, calls webhook `/api/onboarding/{onboarding_id}/contract_signed`
5. hrmq creates `ContractSigningEvent`, verifies signature (QES/AES), updates case to `contract_getekend`

### Nextcloud User Provisioning
1. `it_provisioning` step triggered
2. hrmq calls OCS Users API: `POST /ocs/v1.php/apps/admin_provisioning_api/api/v1/users`
3. Adds user to groups: `<afdeling>`, `medewerker` (global)
4. Stores resulting `userid` on `Employee` record
5. Sets quota (default 1GB) and disables login until after start_date
6. Marks step voltooid

### payroll-engine-nl Integration
1. Nightly or pre-salarisrun, payroll-engine queries all `Employee` records with `payroll_ready = true` on run-date
2. For any employees with `payroll_ready = false` on run-date, excludes them and logs reason
3. Sends HR + payroll notification with link to incomplete onboarding

### Reminder Scheduler
- Runs every 30 minutes (configurable)
- Queries all `Onboarding` cases with `status != proeftijd_afgerond` + `status != geannuleerd`
- For each case with unsolved `Reminder` rows where `trigger_at <= now`:
  - Sends via configured `kanaal` (email, Nextcloud, or both)
  - Updates `Reminder.verzonden_op`
  - If this is second unacknowledged reminder, escalates to `escalatie_naar_user_id`

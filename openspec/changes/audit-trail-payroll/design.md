# Design: Audit Trail Payroll

## System Architecture

```
┌─────────────────────────────────────────────────────┐
│ hrmq Application Layer (all capabilities)            │
│ (employee-master, payroll-engine, loonbeslag, etc.)  │
└────────────────┬────────────────────────────────────┘
                 │
        ┌────────▼─────────────┐
        │ Audit SDK            │
        │ (auditLogger.log())  │
        └────────┬─────────────┘
                 │
        ┌────────▼──────────────────────────────────┐
        │ PayrollAuditService                        │
        │ ├─ append(event)                           │
        │ ├─ query(filters)                          │
        │ ├─ export(period, format)                  │
        │ ├─ verify_chain()                          │
        │ └─ create_anchor()                         │
        └────────┬──────────────────────────────────┘
                 │
    ┌────────────┼────────────────┐
    │            │                │
┌───▼──────┐ ┌──▼──────┐ ┌───────▼─────┐
│ Database │ │ File    │ │ Crypto      │
│ ─────────│ │ Storage │ │ (Hash, TSA) │
│ Append   │ │ (for    │ │             │
│ Events   │ │ exports)│ │             │
└──────────┘ └─────────┘ └─────────────┘
```

---

## Data Model

### Core Entities

#### 1. PayrollAuditEvent

**Purpose**: Immutable audit log entry. Every payroll-relevant mutation creates exactly one INSERT; UPDATE and DELETE are forbidden by constraint.

**Table**: `payroll_audit_events`

```sql
CREATE TABLE payroll_audit_events (
  -- Identity & ordering
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),  -- UUID v7 for natural time ordering
  administratie_id uuid NOT NULL,                 -- Tenant/organization ID
  
  -- Hash chain
  vorige_event_id uuid,                           -- FK to previous event in administratie (NULL for first event)
  vorige_event_hash bytea,                        -- SHA-256 hash of previous event (NULL for first)
  eigen_hash bytea NOT NULL,                      -- SHA-256(all own fields including vorige_event_hash)
  
  -- Timeline
  tijdstip_utc timestamp(6) NOT NULL,             -- When event occurred (microsecond precision)
  tijdstip_lokaal_amsterdam timestamp(6) NOT NULL, -- Localized to Europe/Amsterdam (with DST data)
  
  -- Actor context
  actor_type varchar(32) NOT NULL,                -- 'gebruiker' | 'systeem' | 'api_token' | 'job_scheduler' | 'migratie' | 'import'
  actor_id varchar(255) NOT NULL,                 -- User ID, system name, token ID, job name
  actor_label varchar(255) NOT NULL,              -- Human-readable label at time of log (e.g., "John Doe (j.doe@org.nl)")
  actor_ip inet,                                   -- Source IP if known
  actor_user_agent text,                          -- Browser/client UA string if applicable
  sessie_id uuid,                                  -- Session ID linking related actions
  
  -- Correlation & causality
  oorzaak_keten_id uuid NOT NULL,                 -- Correlation ID grouping events within one logical action (e.g., one loonrun has one keten_id)
  
  -- Entity context
  entiteit_type varchar(64) NOT NULL,             -- 'Medewerker' | 'Contract' | 'Loonrun' | 'Loonpost' | 'Beschikking30' | 'Beslag' | 'Pensioenaanspraak' | 'JournaalpostExport' | 'LoonaangifteIngediend'
  entiteit_id uuid NOT NULL,                      -- ID of the affected entity
  
  -- Event semantics
  event_type varchar(64) NOT NULL,                -- 'aangemaakt' | 'gewijzigd' | 'verwijderd_logisch' | 'berekend' | 'ingediend' | 'herzien' | 'teruggedraaid' | 'goedgekeurd' | 'afgewezen' | 'gepubliceerd'
  event_naam varchar(255) NOT NULL,               -- Semantic label: 'medewerker.salaris_gewijzigd', 'loonrun.uitgevoerd', 'beschikking30.ingetrokken', etc.
  
  -- Payloads
  payload_voor jsonb,                             -- JSON snapshot of relevant fields BEFORE mutation (NULL for aangemaakt events)
  payload_na jsonb,                               -- JSON snapshot AFTER mutation
  payload_diff jsonb,                             -- RFC 6902 JSON Patch representing the change (NULL for aangemaakt)
  
  -- Justification & context
  motivering text,                                -- Free-text justification (required for high-impact events)
  ui_pad varchar(512),                            -- URL path or menu location where action initiated
  api_endpoint varchar(512),                      -- API method + path if via API (e.g., "PUT /api/payroll/loonrun/{id}")
  
  -- Reproducibility
  engine_versie varchar(32),                      -- Semver of payroll-engine or calculate module (e.g., '1.4.2')
  wet_versie varchar(255),                        -- Reference to law/CAO version in effect (e.g., '2026-loonheffing-tabel-rev3')
  
  -- Immutability markers
  created_at timestamp(6) NOT NULL DEFAULT NOW(),
  
  CONSTRAINT no_update CHECK (TRUE),              -- Enforced by trigger & ORM; prevents UPDATE
  CONSTRAINT no_delete CHECK (TRUE)               -- Enforced by trigger & ORM; prevents DELETE
);

-- Indexes for common queries
CREATE INDEX idx_payroll_audit_events_administratie_tijdstip 
  ON payroll_audit_events(administratie_id, tijdstip_utc DESC);
CREATE INDEX idx_payroll_audit_events_entiteit 
  ON payroll_audit_events(administratie_id, entiteit_type, entiteit_id);
CREATE INDEX idx_payroll_audit_events_actor 
  ON payroll_audit_events(administratie_id, actor_id);
CREATE INDEX idx_payroll_audit_events_keten 
  ON payroll_audit_events(oorzaak_keten_id);

-- Database trigger: prevent any UPDATE or DELETE
CREATE TRIGGER payroll_audit_events_prevent_modification
  BEFORE UPDATE OR DELETE ON payroll_audit_events
  FOR EACH ROW
  EXECUTE FUNCTION raise_immutability_violation();

-- Function: raise_immutability_violation
CREATE OR REPLACE FUNCTION raise_immutability_violation()
  RETURNS TRIGGER AS $$
BEGIN
  RAISE EXCEPTION 'Immutability violation: PayrollAuditEvent records can never be updated or deleted';
END;
$$ LANGUAGE plpgsql;
```

#### 2. PayrollAuditChainAnchor

**Purpose**: Periodic cryptographic anchor (Merkle root) over all events since last anchor. Optionally signed by Time Stamping Authority (RFC 3161) for external proof of non-restoration.

**Table**: `payroll_audit_chain_anchors`

```sql
CREATE TABLE payroll_audit_chain_anchors (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  administratie_id uuid NOT NULL,
  
  -- Coverage
  tot_event_id uuid NOT NULL,                     -- Last event_id included in this anchor
  cumulatieve_root_hash bytea NOT NULL,           -- Merkle-root hash over all events up to tot_event_id
  
  -- Timeline
  anker_tijdstip timestamp(6) NOT NULL,           -- When this anchor was created
  
  -- Cryptographic proof
  anker_methode varchar(64) NOT NULL,             -- 'interne_ondertekening' | 'rfc3161_tsa' | 'blockchain_ots'
  anker_bewijs bytea,                             -- Signature (internal) or TSA response token (RFC 3161)
  
  -- Chain continuity
  vorige_anker_id uuid,                           -- FK to previous anchor (NULL for first)
  
  created_at timestamp(6) NOT NULL DEFAULT NOW(),
  
  CONSTRAINT fk_chain_anchor_events
    FOREIGN KEY (administratie_id, tot_event_id) 
    REFERENCES payroll_audit_events(administratie_id, id)
);

CREATE INDEX idx_payroll_audit_chain_anchors_administratie 
  ON payroll_audit_chain_anchors(administratie_id, anker_tijdstip DESC);
```

#### 3. PayrollAuditRetentionPolicy

**Purpose**: Governance of event retention lifecycle. Supports per-administratie and per-entity-type overrides, with legal-hold capability.

**Table**: `payroll_audit_retention_policies`

```sql
CREATE TABLE payroll_audit_retention_policies (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  administratie_id uuid,                          -- NULL = fleet-wide default; specific UUID = override for one administratie
  entiteit_type varchar(64),                      -- NULL = all entities; specific type = override per entity class
  
  retentie_jaren int NOT NULL DEFAULT 10,         -- Default 10, minimum 7
  vernietigingsmethode varchar(64) NOT NULL,      -- 'volledige_verwijdering' | 'pseudonimisering_met_behoud_metadata'
  
  wetgrondslag text,                              -- E.g., "Art. 52 AWR + Art. 7:611 BW + 30%-regeling looptijd"
  actief_vanaf date NOT NULL,
  
  created_at timestamp(6) NOT NULL DEFAULT NOW(),
  updated_at timestamp(6) NOT NULL DEFAULT NOW()
);

-- Fleet-wide defaults (inserted at app startup)
INSERT INTO payroll_audit_retention_policies 
  (administratie_id, entiteit_type, retentie_jaren, vernietigingsmethode, wetgrondslag, actief_vanaf) 
VALUES
  (NULL, NULL, 10, 'pseudonimisering_met_behoud_metadata', 
   'Art. 52 AWR (fiscal 7yr) + Art. 7:611 BW (labor) + Art. 30 AVG (accountability)', 
   CURRENT_DATE);
```

#### 4. PayrollAuditExport

**Purpose**: Track audit export requests, format, and download lifecycle. Auto-cleanup after 30 days.

**Table**: `payroll_audit_exports`

```sql
CREATE TABLE payroll_audit_exports (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  administratie_id uuid NOT NULL,
  
  -- Request metadata
  aangevraagd_door uuid NOT NULL,                 -- User ID of requester
  aangevraagd_op timestamp(6) NOT NULL,           -- When export was requested
  
  -- Time period & filtering
  periode_van date NOT NULL,
  periode_tot date NOT NULL,
  filter jsonb,                                    -- E.g., {"entiteit_type": ["Loonrun", "Loonpost"], "actor_id": "user-123"}
  
  -- Export configuration
  export_formaat varchar(64) NOT NULL,            -- 'pdf_bewijspakket' | 'csv' | 'json_signed' | 'xbrl_payroll'
  inclusief_chain_proof boolean NOT NULL DEFAULT TRUE,
  
  -- Output
  export_bestand_uri text,                        -- S3/file URI to the generated ZIP/PDF
  export_hash bytea,                              -- SHA-256 of export file for integrity check
  
  -- Lifecycle
  status varchar(32) NOT NULL,                    -- 'in_uitvoering' | 'gereed' | 'mislukt' | 'verlopen'
  download_verloopt_op date,                      -- Auto-cleanup after 30 days
  
  created_at timestamp(6) NOT NULL DEFAULT NOW(),
  updated_at timestamp(6) NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_payroll_audit_exports_administratie_status 
  ON payroll_audit_exports(administratie_id, status);
```

#### 5. PayrollAuditAccessLog

**Purpose**: Audit-of-auditors. Log every query to the audit trail, with mandatory justification, to demonstrate AVG accountability.

**Table**: `payroll_audit_access_logs`

```sql
CREATE TABLE payroll_audit_access_logs (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  
  -- Who accessed
  gebruiker_id uuid NOT NULL,
  
  -- What was accessed
  query_filter jsonb NOT NULL,                    -- E.g., {"entiteit_type": "Medewerker", "entiteit_id": "emp-456"}
  aantal_events_geraadpleegd int NOT NULL,        -- Count of events returned
  
  -- Why & when
  rechtvaardiging text NOT NULL,                  -- Mandatory; e.g., "Voorbereiding jaarrekeningcontrole 2026"
  tijdstip timestamp(6) NOT NULL DEFAULT NOW(),
  
  created_at timestamp(6) NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_payroll_audit_access_logs_gebruiker 
  ON payroll_audit_access_logs(gebruiker_id, tijdstip DESC);
```

---

## Seed Data Examples

### PayrollAuditEvent Examples

```json
{
  "id": "018f4f2c-3c1f-7000-8000-000000000001",
  "administratie_id": "adm-nl-2026-01",
  "vorige_event_id": null,
  "vorige_event_hash": null,
  "eigen_hash": "a1b2c3d4e5f6g7h8...",
  "tijdstip_utc": "2026-01-15T09:30:00.123456Z",
  "tijdstip_lokaal_amsterdam": "2026-01-15T10:30:00.123456+01:00",
  "actor_type": "gebruiker",
  "actor_id": "user-jdoe",
  "actor_label": "Jan Doe (j.doe@bedrijf.nl)",
  "actor_ip": "192.168.1.100",
  "actor_user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)...",
  "sessie_id": "sess-abc123",
  "oorzaak_keten_id": "chain-loonrun-2026-01",
  "entiteit_type": "Medewerker",
  "entiteit_id": "emp-456",
  "event_type": "gewijzigd",
  "event_naam": "medewerker.salaris_gewijzigd",
  "payload_voor": {
    "salaris_maand": "2850.00",
    "salaris_periodiek": "13"
  },
  "payload_na": {
    "salaris_maand": "2900.00",
    "salaris_periodiek": "13"
  },
  "payload_diff": [
    {"op": "replace", "path": "/salaris_maand", "value": "2900.00"}
  ],
  "motivering": "CAO-verhoging met ingang januari 2026 conform collectieve arbeidsovereenkomst.",
  "ui_pad": "/medewerkers/emp-456/salaris",
  "api_endpoint": "PATCH /api/medewerkers/emp-456/salaris",
  "engine_versie": null,
  "wet_versie": "2026-cao-industrie-rev1",
  "created_at": "2026-01-15T09:30:00.123456Z"
}
```

```json
{
  "id": "018f4f2c-3c1f-7000-8000-000000000042",
  "administratie_id": "adm-nl-2026-01",
  "vorige_event_id": "018f4f2c-3c1f-7000-8000-000000000041",
  "vorige_event_hash": "b2c3d4e5f6g7h8i9...",
  "eigen_hash": "c3d4e5f6g7h8i9j0...",
  "tijdstip_utc": "2026-02-28T16:45:00.567890Z",
  "tijdstip_lokaal_amsterdam": "2026-02-28T17:45:00.567890+01:00",
  "actor_type": "systeem",
  "actor_id": "payroll-engine",
  "actor_label": "Payroll Engine v1.4.2",
  "actor_ip": null,
  "actor_user_agent": null,
  "sessie_id": null,
  "oorzaak_keten_id": "chain-loonrun-2026-02",
  "entiteit_type": "Loonrun",
  "entiteit_id": "run-2026-02-nl",
  "event_type": "berekend",
  "event_naam": "loonrun.uitgevoerd",
  "payload_voor": null,
  "payload_na": {
    "status": "berekend",
    "totaal_bruto": "487500.00",
    "totaal_werkgever_bijdrage": "125432.50",
    "aantal_medewerkers": 150,
    "periode": "2026-02",
    "verwerkt_op": "2026-02-28T16:45:00Z"
  },
  "payload_diff": null,
  "motivering": null,
  "ui_pad": "/salarissen/loonruns/run-2026-02-nl",
  "api_endpoint": "POST /api/payroll/loonruns/calculate",
  "engine_versie": "1.4.2",
  "wet_versie": "2026-loonheffing-tabel-rev3, cao-industrie-rev1",
  "created_at": "2026-02-28T16:45:00.567890Z"
}
```

```json
{
  "id": "018f4f2c-3c1f-7000-8000-000000000088",
  "administratie_id": "adm-nl-2026-01",
  "vorige_event_id": "018f4f2c-3c1f-7000-8000-000000000087",
  "vorige_event_hash": "d4e5f6g7h8i9j0k1...",
  "eigen_hash": "e5f6g7h8i9j0k1l2...",
  "tijdstip_utc": "2026-03-10T11:20:00.345678Z",
  "tijdstip_lokaal_amsterdam": "2026-03-10T12:20:00.345678+01:00",
  "actor_type": "gebruiker",
  "actor_id": "user-pmgr",
  "actor_label": "Petra Manager (p.manager@bedrijf.nl)",
  "actor_ip": "203.0.113.45",
  "actor_user_agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)...",
  "sessie_id": "sess-xyz789",
  "oorzaak_keten_id": "chain-beschikking-intrekking-2026-03",
  "entiteit_type": "Beschikking30",
  "entiteit_id": "desc30-emp-789",
  "event_type": "verwijderd_logisch",
  "event_naam": "beschikking30.ingetrokken",
  "payload_voor": {
    "status": "actief",
    "percentage": 30,
    "geldig_vanaf": "2025-01-01",
    "geldig_tot": "2026-12-31",
    "maandelijks_voordeel": "450.00"
  },
  "payload_na": {
    "status": "ingetrokken",
    "percentage": 30,
    "geldig_vanaf": "2025-01-01",
    "geldig_tot": "2026-03-10",
    "maandelijks_voordeel": "0.00",
    "ingetrokken_op": "2026-03-10T12:20:00Z"
  },
  "payload_diff": [
    {"op": "replace", "path": "/status", "value": "ingetrokken"},
    {"op": "replace", "path": "/geldig_tot", "value": "2026-03-10"},
    {"op": "replace", "path": "/maandelijks_voordeel", "value": "0.00"},
    {"op": "add", "path": "/ingetrokken_op", "value": "2026-03-10T12:20:00Z"}
  ],
  "motivering": "Medewerker heeft aangegeven regelmatig meer dan 56 uur per week te werken, dus 30%-regeling niet langer van toepassing conform artikel 1.5 wet LB2013.",
  "ui_pad": "/salarissen/beschikkingen-30/desc30-emp-789/intrekken",
  "api_endpoint": "PUT /api/payroll/beschikking-30/desc30-emp-789/withdraw",
  "engine_versie": null,
  "wet_versie": "2026-wet-LB2013-rev1, 30-regeling-2026",
  "created_at": "2026-03-10T12:20:00.345678Z"
}
```

### PayrollAuditChainAnchor Examples

```json
{
  "id": "018f4f2c-4a2b-7000-8000-000000000201",
  "administratie_id": "adm-nl-2026-01",
  "tot_event_id": "018f4f2c-3c1f-7000-8000-000000000150",
  "cumulatieve_root_hash": "f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6",
  "anker_tijdstip": "2026-03-08T02:00:00Z",
  "anker_methode": "interne_ondertekening",
  "anker_bewijs": "-----BEGIN RSA SIGNATURE-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA...",
  "vorige_anker_id": "018f4f2c-3a1b-7000-8000-000000000199",
  "created_at": "2026-03-08T02:00:01Z"
}
```

```json
{
  "id": "018f4f2c-4a2b-7000-8000-000000000202",
  "administratie_id": "adm-nl-2026-01",
  "tot_event_id": "018f4f2c-3c1f-7000-8000-000000000156",
  "cumulatieve_root_hash": "a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3r4s5t6u7v8w9x0y1z2a3b4c5d6",
  "anker_tijdstip": "2026-03-15T02:00:00Z",
  "anker_methode": "rfc3161_tsa",
  "anker_bewijs": "30821234060b2b0601040183b34f0201307d...",
  "vorige_anker_id": "018f4f2c-4a2b-7000-8000-000000000201",
  "created_at": "2026-03-15T02:00:02Z"
}
```

### PayrollAuditRetentionPolicy Examples

```json
{
  "id": "pol-default-fleet",
  "administratie_id": null,
  "entiteit_type": null,
  "retentie_jaren": 10,
  "vernietigingsmethode": "pseudonimisering_met_behoud_metadata",
  "wetgrondslag": "Art. 52 AWR (fiscal 7yr) + Art. 7:611 BW (labor) + Art. 30 AVG (accountability)",
  "actief_vanaf": "2026-01-01"
}
```

```json
{
  "id": "pol-pensioenaanspraak-extended",
  "administratie_id": "adm-nl-2026-01",
  "entiteit_type": "Pensioenaanspraak",
  "retentie_jaren": 15,
  "vernietigingsmethode": "pseudonimisering_met_behoud_metadata",
  "wetgrondslag": "Pensioenrichtlijn PenV + toeslagwet; aanspraken kunnen levenslang relevant blijven",
  "actief_vanaf": "2026-01-01"
}
```

### PayrollAuditAccessLog Examples

```json
{
  "id": "access-log-001",
  "gebruiker_id": "user-auditor-1",
  "query_filter": {
    "administratie_id": "adm-nl-2026-01",
    "entiteit_type": "Loonrun",
    "periode_van": "2025-01-01",
    "periode_tot": "2025-12-31"
  },
  "aantal_events_geraadpleegd": 1247,
  "rechtvaardiging": "Voorbereiding Belastingdienst boekenonderzoek 2025 — jaarrekeningcontrole",
  "tijdstip": "2026-03-20T10:15:30Z"
}
```

---

## API Contract

### Insert Event

```
POST /api/payroll-audit/events

Request:
{
  "administratie_id": "adm-nl-2026-01",
  "entiteit_type": "Medewerker",
  "entiteit_id": "emp-456",
  "event_type": "gewijzigd",
  "event_naam": "medewerker.salaris_gewijzigd",
  "payload_voor": {...},
  "payload_na": {...},
  "motivering": "...",
  "ui_pad": "...",
  "engine_versie": "1.4.2",
  "wet_versie": "2026-cao-industrie-rev1"
}

Response (200 OK):
{
  "id": "018f4f2c-3c1f-7000-8000-000000000001",
  "eigen_hash": "a1b2c3d4e5f6g7h8...",
  "vorige_event_id": "018f4f2c-3c1f-7000-8000-000000000000",
  "vorige_event_hash": "b2c3d4e5f6g7h8i9...",
  "created_at": "2026-01-15T09:30:00.123456Z"
}

Response (400 Bad Request):
{
  "error": "validation_error",
  "message": "motivering verplicht voor event_type beschikking30.handmatig_ingetrokken"
}
```

### Query Events

```
GET /api/payroll-audit/events?administratie_id=...&entiteit_type=...&periode_van=...&periode_tot=...

Response (200 OK):
{
  "events": [
    { ... event 1 ... },
    { ... event 2 ... }
  ],
  "total": 2500,
  "page": 1,
  "page_size": 100
}
```

### Export Events

```
POST /api/payroll-audit/export

Request:
{
  "administratie_id": "adm-nl-2026-01",
  "periode_van": "2025-01-01",
  "periode_tot": "2025-12-31",
  "filter": { "entiteit_type": "Loonrun" },
  "export_formaat": "pdf_bewijspakket",
  "rechtvaardiging": "Belastingdienst boekenonderzoek 2025-Q2"
}

Response (202 Accepted):
{
  "export_id": "exp-12345",
  "status": "in_uitvoering",
  "estimated_completion": "2026-03-20T14:00:00Z"
}

Polling:
GET /api/payroll-audit/export/exp-12345

Response (200 OK):
{
  "export_id": "exp-12345",
  "status": "gereed",
  "download_url": "https://cdn.bedrijf.nl/audit-exports/exp-12345.zip",
  "export_hash": "c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6...",
  "download_verloopt_op": "2026-04-20"
}
```

### Verify Chain Integrity

```
POST /api/payroll-audit/verify-chain

Request:
{
  "administratie_id": "adm-nl-2026-01",
  "from_event_id": null  // null = from beginning
}

Response (200 OK):
{
  "status": "valid",
  "total_events_checked": 15847,
  "last_event_id": "018f4f2c-3c1f-7000-8000-000000000150"
}

Response (400 Bad Request):
{
  "status": "broken",
  "first_corrupted_event_id": "018f4f2c-3c1f-7000-8000-000000000088",
  "error": "hash mismatch at event {id}: expected {hash1}, got {hash2}"
}
```

---

## Audit SDK Interface

```php
<?php

namespace Hrmq\Audit;

interface AuditLoggerInterface {
    /**
     * Log a single event to the payroll audit trail.
     * 
     * @param string $entiteitType    e.g., 'Medewerker', 'Loonrun', 'Beschikking30'
     * @param string $entiteitId      UUID of the affected entity
     * @param string $eventType       e.g., 'gewijzigd', 'berekend', 'ingetrokken'
     * @param string $eventNaam       Semantic label, e.g., 'medewerker.salaris_gewijzigd'
     * @param array  $payloadVoor     Optional JSON snapshot before (for wijzigingen)
     * @param array  $payloadNa       JSON snapshot after
     * @param array  $options         Additional context: motivering, engine_versie, wet_versie, ui_pad, etc.
     * @return EventId
     * @throws ImmutabilityViolationException
     * @throws AuditLogValidationError
     */
    public function log(
        string $entiteitType,
        string $entiteitId,
        string $eventType,
        string $eventNaam,
        ?array $payloadVoor,
        array $payloadNa,
        array $options = []
    ): EventId;
}

// Usage in loonbeslag-admin capability
$auditLogger->log(
    'Beslag',
    'beslag-456',
    'aangemaakt',
    'beslag.nieuw',
    null,  // no previous state for new event
    [
        'type' => 'inkomstenbelasting',
        'bedrag' => '500.00',
        'redenen' => ['alimentatie', 'belastingschuld']
    ],
    [
        'motivering' => 'Nieuw beslag ingetrokken via UWV SUWI-koppeling',
        'ui_pad' => '/salarissen/beslagen/beslag-456',
        'api_endpoint' => 'POST /api/payroll/beslagen'
    ]
);

// Usage in payroll-engine
$auditLogger->log(
    'Loonrun',
    'run-2026-02-nl',
    'berekend',
    'loonrun.uitgevoerd',
    null,
    [
        'status' => 'berekend',
        'totaal_bruto' => '487500.00',
        'periode' => '2026-02'
    ],
    [
        'engine_versie' => '1.4.2',
        'wet_versie' => '2026-loonheffing-tabel-rev3, cao-industrie-rev1'
    ]
);
```

---

## Implementation Notes

1. **Hash Calculation**: SHA-256 over canonical JSON representation of all fields except `id`, `created_at`, `eigen_hash`. Use stable field ordering to ensure consistent hashing.

2. **UUID v7**: Use UUID v7 (RFC 9562) for natural time-ordering without separate index on timestamp.

3. **Per-Administratie Serialization**: Lock mechanism or single-writer queue ensures that within one `administratie_id`, events are inserted in strict chronological order with correct hash-chain assignment.

4. **Timezone Handling**: `tijdstip_utc` is authoritative; `tijdstip_lokaal_amsterdam` is computed at insert for audit trail clarity. DST transitions are handled by the database (e.g., PostgreSQL's `AT TIME ZONE 'Europe/Amsterdam'`).

5. **Retention Cleanup**: Monthly job queries `PayrollAuditRetentionPolicy` + `created_at` to identify eligible events. Before deletion, write a "tombstone" event with `event_type = 'verwijderd_fysiek'` and `payload_na = null` to preserve chain continuity.

6. **Legal Hold**: Admin interface to create/list/expire `legal_hold` records linked to `administratie_id`. Cleanup job checks active holds before processing deletions.

7. **Export Packaging**: Async job that queries `PayrollAuditEvent`, re-calculates hashes, generates PDF summaries per period, includes applicable `PayrollAuditChainAnchor` records, and signs the package. Outputs ZIP with manifest.json.

8. **Chain Verification Job**: Runs weekly (e.g., Saturday 02:00 UTC, 1 hour before anchor job). Recalculates every hash from scratch; if mismatch, stops and raises high-priority alert.

9. **Performance Indexing**: Composite indexes on (administratie_id, tijdstip_utc DESC), (administratie_id, entiteit_type, entiteit_id) enable < 2s queries on typical filters. Partitioning by administratie_id recommended for > 50M events.

10. **Backward Compatibility**: Existing hrmq apps transition to SDK-based logging; no auto-backfill of old events into PayrollAuditEvent. Historical reconstruction available via manual export/import if needed.

---
title: AVG / GDPR Data-Subject Rights Engine — Design
status: draft
---

# Design: AVG / GDPR Data-Subject Rights Engine

## User Stories

### Story 1: Privacy Officer Sets Up ROPA (Article 30 Register)

**As a** Privacy Officer  
**I want to** configure the organisation's processing activities (verwerkingenregister) with Article 30 compliance  
**So that** I can document legal basis and data categories for each HRM process

**Acceptance Criteria:**

- **GIVEN** a hrmq tenant completing privacy setup
- **WHEN** the privacy-setup wizard launches
- **THEN** the system pre-fills ~15 standard ProcessingActivity records (salarisadministratie, verzuim, performance, recruitment, reiskosten, erfgoedverlof, etc.) with legal-basis defaults per Dutch employment law
- **AND** the privacy officer can customise name, purpose, legal_basis, data_categories, retention_period_months, recipients
- **AND** the privacy officer can add/edit DPIA status and security measures
- **AND** the completed register is exportable as a PDF with AP-inspection-ready formatting
- **AND** each ProcessingActivity record includes a timestamp of last-reviewed + reviewer identity for audit trail

---

### Story 2: Employee Submits Access Request (Article 15)

**As an** Employee  
**I want to** request a copy of all personal data hrmq holds about me  
**So that** I can verify the accuracy and ensure GDPR compliance

**Acceptance Criteria:**

- **GIVEN** a current or former employee
- **WHEN** they visit `/privacy/request` (public, no login required) OR access `Mijn HR › AVG-verzoeken › Inzage aanvragen`
- **THEN** a DsrRequest intake form opens with request_type=access
- **AND** they provide: name, email (or BSN for ex-employees), and reason (optional)
- **AND** upon submission, the system creates a DsrRequest record with status=pending_verification, submitted_at=now
- **AND** identity verification is triggered: DigiD for NL citizens OR manual passport-copy upload + human review for others
- **AND** once verified_at is set, the 30-day deadline_at = verified_at + 30 days
- **AND** the requester receives a confirmation email with case number + deadline + next steps

---

### Story 3: Privacy Officer Collects Evidence Across Apps

**As a** Privacy Officer  
**I want to** gather all personal data about the subject from all hrmq schemas and integrated apps  
**So that** I can compile a complete, lawful response

**Acceptance Criteria:**

- **GIVEN** a verified DsrRequest
- **WHEN** the privacy officer clicks "Collect evidence"
- **THEN** the system executes:
  1. Walks every schema in the hrmq register that declares a `data_subject_field` annotation (e.g. Employee.user_id, LeaveRequest.requester_id, PerformanceReview.subject_employee_id)
  2. Queries records matching the requester's user_id / email
  3. Stores results as DsrEvidence objects with collected_at, collector_user_id, data_snapshot_json
  4. Fires `dsr.collect` webhook via hris-api-public; integrated apps (shillinq, openconnector, opencatalogi audit-log) respond with their data within 5 days
  5. Evidence records are marked with source_app, source_schema, record_ids for traceability
- **AND** if any app fails to respond within 5 days, the privacy officer is alerted and can mark evidence as "incomplete pending response"

---

### Story 4: Privacy Officer Approves Rectification Request (Article 16)

**As a** Privacy Officer  
**I want to** review and approve a requester's correction request  
**So that** I can keep HRM data accurate per the subject's request

**Acceptance Criteria:**

- **GIVEN** a DsrRequest with request_type=rectification, requester specifying: field name, old value, corrected value
- **WHEN** the privacy officer reviews the request
- **THEN** the officer can: ACCEPT (update field, flag in audit-log with AVG-correction tag), REJECT (document reasoning, notify requester)
- **AND** on ACCEPT:
  1. The field is updated with the corrected value
  2. The old value is preserved in the audit-log with: `correction_reason=avg-rectification`, `dsr_request_id`, `timestamp`, `corrected_by`
  3. A `employee.updated` webhook is fired with `correction_reason=avg-rectification` to notify downstream systems
  4. The requester receives confirmation: "Field X corrected on [date]. Downstream systems updated."
- **AND** on REJECT:
  1. The requester receives: reason + legal-basis citation + appeal instructions
  2. The rejection reason is recorded in the DsrRequest with rejection_reason + legal_basis_for_rejection enum

---

### Story 5: Privacy Officer Evaluates Erasure Request (Article 17)

**As a** Privacy Officer  
**I want to** evaluate which fields can be erased vs retained under conflicting legal obligations  
**So that** I can grant erasure where lawful and preserve where required

**Acceptance Criteria:**

- **GIVEN** a DsrRequest with request_type=erasure
- **WHEN** the privacy officer evaluates the request
- **THEN** the system computes:
  1. For each collected data field, determine: erasable? (no conflicting legal obligation) OR retained? (legal obligation exists)
  2. Erasable fields: delete or anonymise (configurable per field)
  3. Retained fields: flag with retention_reason + earliest_erasure_date based on ProcessingActivity.retention_period_months
  4. Example: salarisadministratie (7-year loonheffing retention) → salary fields cannot be erased until 7 years post-termination; verzuim (2-year recovery retention) → can be erased 2 years post-recovery
- **AND** the response to the requester explicitly lists:
  - Fields erased (with confirmation of deletion/anonymisation)
  - Fields retained (with legal-basis citation + earliest erasure date)
  - Justification for each retained field
- **AND** the DsrRequest.status → completed with erasure_summary_json

---

### Story 6: Employee Requests Data Portability Export (Article 20)

**As an** Employee  
**I want to** export my personal data in a machine-readable format  
**So that** I can transfer my data to another controller

**Acceptance Criteria:**

- **GIVEN** a DsrRequest with request_type=portability, verified_at set
- **WHEN** the privacy officer triggers "Generate export"
- **THEN** the system generates:
  1. A machine-readable export (JSON conforming to a published schema + CSV for human readability)
  2. Only includes data the subject provided themselves on a consent or contract basis
  3. Excludes: derived data, internal notes, performance-review comments from others, inferred fields
  4. File format: JSON array of objects OR CSV with headers
  5. Delivered as a signed download link valid for 30 days (single-use or multi-use configurable)
- **AND** the requester receives: email with link + instruction to download within 30 days
- **AND** access logs record: who downloaded, when, from which IP

---

### Story 7: System Tracks Deadline + Alerts Privacy Officer

**As a** Privacy Officer  
**I want to** receive timely alerts about approaching DSR deadlines  
**So that** I never miss the 30-day statutory requirement

**Acceptance Criteria:**

- **GIVEN** an open DsrRequest with deadline_at in the future
- **WHEN** deadline_at - 7 days is reached
- **THEN** the privacy officer receives an alert: "Request [case#] due in 7 days. Status: [status]. Action: [next step required]"
- **AND** if the request is complex (multi-app evidence, >1000 records collected), the privacy officer can extend once by up to 60 days per AVG Art. 12.3:
  - Sets extension=true, extension_reason (documented), extension_deadline_at = deadline_at + 60 days
  - Sends notification to requester: "Your request is complex. We are extending the deadline to [date]."
- **AND** if the extended deadline passes without resolution:
  1. Escalation triggered to DPO + tenant admin
  2. DsrRequest.status → "deadline_breached"
  3. Audit-log records breach with regulatory-notification flag
  4. AP-inspection-ready report is generated
- **AND** all deadline events are recorded in the audit-log

---

### Story 8: Processor Registry + Verwerkersovereenkomst Management

**As a** Privacy Officer  
**I want to** manage external data processors and track signed agreements  
**So that** processors cannot receive data until a verwerkersovereenkomst is on file

**Acceptance Criteria:**

- **GIVEN** a hrmq tenant integrating with an external system (payroll-provider, ATS, learning-platform)
- **WHEN** the integration is enabled
- **THEN** a Processor record is created with: name, contact, data_categories_processed, country
- **AND** the privacy officer is prompted to upload a signed verwerkersovereenkomst (or use a Conduction-provided template)
- **AND** the integration cannot send data to the processor until verwerkersovereenkomst_signed_at is set + document is stored
- **AND** the privacy officer receives a renewal reminder 60 days before expiry (configurable)
- **AND** historical verwerkersovereenkomsten are archived with version + effective_date + expiry_date

---

## Entity Schema

### ProcessingActivity

**Article 30 register entry. Describes a lawful processing of HRM data.**

```json
{
  "schema": "ProcessingActivity",
  "register": "hrmq",
  "properties": {
    "id": {
      "type": "string",
      "description": "Unique identifier (UUID or slug)"
    },
    "name": {
      "type": "string",
      "description": "Processing activity name (e.g. 'Salarisadministratie', 'Verzuimregistratie')"
    },
    "purpose": {
      "type": "string",
      "description": "Concrete purpose (not generic 'HR'). e.g. 'Loonverwerking en belastingaangiften'",
      "required": true
    },
    "legal_basis": {
      "type": "string",
      "enum": ["consent", "contract", "legal_obligation", "vital_interest", "public_task", "legitimate_interest"],
      "description": "GDPR Article 6 legal basis for processing",
      "required": true
    },
    "data_categories": {
      "type": "array",
      "items": {
        "type": "string",
        "enum": ["identifying", "financial", "health", "biometric", "contact", "employment", "performance"]
      },
      "description": "Data categories processed (Article 30)",
      "required": true
    },
    "data_subjects": {
      "type": "array",
      "items": {
        "type": "string",
        "enum": ["employees", "applicants", "ex-employees", "contractors", "managers"]
      },
      "description": "Categories of data subjects affected",
      "required": true
    },
    "retention_period_months": {
      "type": "integer",
      "description": "Retention period in months (e.g. 84 for 7-year loonheffing retention)",
      "minimum": 0,
      "required": true
    },
    "recipients": {
      "type": "array",
      "items": {
        "type": "string"
      },
      "description": "Internal teams + external processors (e.g. ['Salarisadministratie', 'Belastingdienst', 'Pensioenfonds'])",
      "required": true
    },
    "cross_border_transfers": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "destination_country": {"type": "string"},
          "safeguard": {"type": "string", "enum": ["SCC", "adequacy_decision", "binding_rules", "derogation"]},
          "description": {"type": "string"}
        }
      },
      "description": "International data transfers with safeguards"
    },
    "security_measures": {
      "type": "string",
      "description": "Technical and organisational security measures (e.g. 'AES-256 encryption at rest, role-based access control, audit logging')",
      "required": true
    },
    "dpia_required": {
      "type": "boolean",
      "description": "Whether a Data Protection Impact Assessment is required (Article 35)",
      "default": false
    },
    "dpia_completed_at": {
      "type": "string",
      "format": "date-time",
      "description": "Timestamp of completed DPIA (null if not completed)"
    },
    "last_reviewed_at": {
      "type": "string",
      "format": "date-time",
      "description": "Last review timestamp"
    },
    "last_reviewed_by": {
      "type": "string",
      "description": "User ID of reviewer"
    }
  }
}
```

### Processor

**Data processor registry (verwerker). Tracks external organisations processing HRM data.**

```json
{
  "schema": "Processor",
  "register": "hrmq",
  "properties": {
    "id": {
      "type": "string",
      "description": "Unique identifier (UUID or slug)"
    },
    "name": {
      "type": "string",
      "description": "Processor organisation name (e.g. 'Exact Online B.V.')",
      "required": true
    },
    "contact": {
      "type": "string",
      "description": "Contact email or address",
      "required": true
    },
    "data_categories_processed": {
      "type": "array",
      "items": {
        "type": "string",
        "enum": ["identifying", "financial", "health", "biometric", "contact", "employment", "performance"]
      },
      "description": "Data categories this processor handles",
      "required": true
    },
    "country": {
      "type": "string",
      "description": "ISO 3166-1 country code (e.g. 'NL', 'DE')",
      "required": true
    },
    "verwerkersovereenkomst_signed_at": {
      "type": "string",
      "format": "date-time",
      "description": "Date verwerkersovereenkomst was signed (null if not signed)"
    },
    "verwerkersovereenkomst_document_id": {
      "type": "string",
      "description": "Reference to stored verwerkersovereenkomst file"
    },
    "verwerkersovereenkomst_expiry_at": {
      "type": "string",
      "format": "date-time",
      "description": "Expiry date of current verwerkersovereenkomst"
    },
    "created_at": {
      "type": "string",
      "format": "date-time",
      "description": "Record creation timestamp"
    }
  }
}
```

### DsrRequest

**Data-subject request. Represents a single GDPR Article 15-22 request from an employee/ex-employee.**

```json
{
  "schema": "DsrRequest",
  "register": "hrmq",
  "properties": {
    "id": {
      "type": "string",
      "description": "Unique identifier (UUID or case number)"
    },
    "requester_email": {
      "type": "string",
      "format": "email",
      "description": "Email address of data subject",
      "required": true
    },
    "requester_user_id": {
      "type": "string",
      "description": "User ID in hrmq (null for ex-employees without account)",
      "nullable": true
    },
    "request_type": {
      "type": "string",
      "enum": ["access", "rectification", "erasure", "restriction", "portability", "objection"],
      "description": "Type of GDPR request",
      "required": true
    },
    "submitted_at": {
      "type": "string",
      "format": "date-time",
      "description": "Submission timestamp",
      "required": true
    },
    "verified_at": {
      "type": "string",
      "format": "date-time",
      "description": "Identity verification completion timestamp (null until verified)",
      "nullable": true
    },
    "verification_method": {
      "type": "string",
      "enum": ["digid", "passport_copy", "email_confirmation"],
      "description": "Method used for identity verification"
    },
    "deadline_at": {
      "type": "string",
      "format": "date-time",
      "description": "30-day deadline (verified_at + 30 days)",
      "nullable": true
    },
    "extended": {
      "type": "boolean",
      "description": "Whether deadline has been extended",
      "default": false
    },
    "extension_reason": {
      "type": "string",
      "description": "Documented reason for extension (required if extended=true)",
      "nullable": true
    },
    "extension_deadline_at": {
      "type": "string",
      "format": "date-time",
      "description": "Extended deadline (max +60 days per AVG Art. 12.3)",
      "nullable": true
    },
    "status": {
      "type": "string",
      "enum": ["pending_verification", "in_progress", "awaiting_input", "completed", "rejected", "partially_fulfilled", "deadline_breached"],
      "description": "Current request status",
      "required": true
    },
    "rejection_reason": {
      "type": "string",
      "description": "If status=rejected, documented reason",
      "nullable": true
    },
    "legal_basis_for_rejection": {
      "type": "string",
      "enum": ["frivolous_request", "excessive_requests", "no_legal_basis", "not_data_subject"],
      "description": "GDPR legal basis for rejection",
      "nullable": true
    },
    "assigned_to_user_id": {
      "type": "string",
      "description": "Privacy officer or HR admin responsible for this request",
      "nullable": true
    },
    "erasure_summary_json": {
      "type": "object",
      "description": "Summary of erasure decision (fields erased vs retained with reasoning)"
    },
    "created_at": {
      "type": "string",
      "format": "date-time",
      "description": "Record creation timestamp"
    },
    "updated_at": {
      "type": "string",
      "format": "date-time",
      "description": "Last update timestamp"
    }
  }
}
```

### DsrEvidence

**Collected data per request. Stores snapshots of data found for a data subject.**

```json
{
  "schema": "DsrEvidence",
  "register": "hrmq",
  "properties": {
    "id": {
      "type": "string",
      "description": "Unique identifier (UUID)"
    },
    "request_id": {
      "type": "string",
      "description": "Reference to DsrRequest",
      "required": true
    },
    "source_app": {
      "type": "string",
      "enum": ["hrmq", "shillinq", "openconnector", "opencatalogi", "n8n"],
      "description": "Source application",
      "required": true
    },
    "source_schema": {
      "type": "string",
      "description": "Schema name in source app (e.g. 'Employee', 'LeaveRequest', 'PerformanceReview')",
      "required": true
    },
    "record_ids": {
      "type": "array",
      "items": {"type": "string"},
      "description": "IDs of records containing subject's data",
      "required": true
    },
    "data_snapshot_json": {
      "type": "object",
      "description": "Collected data fields (PII-sanitised per export policy)",
      "required": true
    },
    "collected_at": {
      "type": "string",
      "format": "date-time",
      "description": "Collection timestamp",
      "required": true
    },
    "collector_user_id": {
      "type": "string",
      "description": "Privacy officer who triggered collection",
      "required": true
    },
    "record_count": {
      "type": "integer",
      "description": "Number of records collected",
      "minimum": 0
    }
  }
}
```

## Seed Data

### ProcessingActivity Examples

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "ProcessingActivity",
    "slug": "salarisadministratie"
  },
  "name": "Salarisadministratie",
  "purpose": "Loonverwerking, belastingaangiften (IB en VPB), sociale lasten",
  "legal_basis": "legal_obligation",
  "data_categories": ["identifying", "financial", "employment"],
  "data_subjects": ["employees", "ex-employees"],
  "retention_period_months": 84,
  "recipients": ["Belastingdienst", "Pensioenfonds", "Accountant"],
  "security_measures": "AES-256 encryption at rest, role-based access control, audit logging",
  "dpia_required": true,
  "dpia_completed_at": "2026-01-15T10:00:00Z",
  "last_reviewed_at": "2026-05-20T14:30:00Z",
  "last_reviewed_by": "user-dpo-001"
}
```

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "ProcessingActivity",
    "slug": "verzuimregistratie"
  },
  "name": "Verzuimregistratie",
  "purpose": "Registratie en beheer van ziekteperioden, re-integratieproces, WVP-procedures",
  "legal_basis": "legal_obligation",
  "data_categories": ["identifying", "health", "employment"],
  "data_subjects": ["employees"],
  "retention_period_months": 24,
  "recipients": ["Betrokken medewerker", "HR-afdeling", "Arbodienst"],
  "security_measures": "NEN 7510 compliance, encryption in transit, access controls per arbodienst",
  "dpia_required": true,
  "last_reviewed_at": "2026-05-18T09:15:00Z",
  "last_reviewed_by": "user-dpo-001"
}
```

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "ProcessingActivity",
    "slug": "performance-management"
  },
  "name": "Prestatiemanagement",
  "purpose": "Jaarlijkse prestatiebeoordelingen, competentie-ontwikkeling, carrièreplanning",
  "legal_basis": "legitimate_interest",
  "data_categories": ["identifying", "employment", "performance"],
  "data_subjects": ["employees"],
  "retention_period_months": 36,
  "recipients": ["Lijnmanager", "HR-afdeling"],
  "security_measures": "Role-based access, medewerker kan review aanvechten, archivering na vertrek",
  "dpia_required": false,
  "last_reviewed_at": "2026-04-22T16:45:00Z",
  "last_reviewed_by": "user-dpo-002"
}
```

### Processor Examples

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "Processor",
    "slug": "exact-online-payroll"
  },
  "name": "Exact Online B.V. — Payroll Processing",
  "contact": "dpa@exactonline.com",
  "data_categories_processed": ["identifying", "financial", "employment"],
  "country": "NL",
  "verwerkersovereenkomst_signed_at": "2025-11-30T00:00:00Z",
  "verwerkersovereenkomst_document_id": "doc-exact-dpa-2025",
  "verwerkersovereenkomst_expiry_at": "2026-11-30T00:00:00Z",
  "created_at": "2025-11-30T10:00:00Z"
}
```

### DsrRequest Examples

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "DsrRequest",
    "slug": "req-20260515-emp-001"
  },
  "requester_email": "jan.jansen@example.nl",
  "requester_user_id": "emp-001-janjansen",
  "request_type": "access",
  "submitted_at": "2026-05-15T08:30:00Z",
  "verified_at": "2026-05-16T15:45:00Z",
  "verification_method": "digid",
  "deadline_at": "2026-06-15T15:45:00Z",
  "extended": false,
  "status": "in_progress",
  "assigned_to_user_id": "user-dpo-001",
  "created_at": "2026-05-15T08:30:00Z",
  "updated_at": "2026-05-20T10:15:00Z"
}
```

## Reuse Analysis

- **OpenRegister ObjectService** — Used for CRUD on ProcessingActivity, Processor, DsrRequest, DsrEvidence (ADR-001-data-layer)
- **AuditTrailService** — All DSR actions logged immutably for AP inspection
- **FileService** — Verwerkersovereenkomst storage + portability export delivery
- **WebhookService** — `dsr.collect` event dispatch to integrated apps
- **NotificationService** — Deadline alerts, evidence request escalations, requester notifications
- **ImportService / ExportService** — ROPA export as PDF, portability export as JSON/CSV
- **IndexService / SearchTrailService** — Full-text search on requests, ROPA, evidence (popularity tracking)
- **RbacHandler** — Role-based access: privacy-officers only access request details; HR-admins can act on rectifications; employees see only their own requests

**No duplication found.** All services are provided by OpenRegister / platform.

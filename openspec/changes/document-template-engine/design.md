# Document Template Engine — Design

**Change:** document-template-engine  
**Status:** draft  
**Date:** 2026-05-23

## Architecture Overview

The Document Template Engine is a tenant-scoped rendering pipeline that sits between HR-administrative workflows (contracts, addenda, vaststellingsovereenkomsten, loonstroken, jaaropgaven) and a deterministic PDF renderer. The engine owns:

1. **Template Library** — versioned Markdown + Mustache templates with merge-field validation
2. **Partial Library** — tenant-scoped reusable blocks (briefpapier, ondertekening, clausules)
3. **Data Snapshots** — immutable merge-data captured at render-time
4. **Approval Workflows** — state-machine routing for high-risk documents
5. **Audit Trail** — every render linked to template-version, approver, timestamp, data-snapshot
6. **PDF/A Archival** — integration with docudesk for long-term retention

### High-Level Flow

```
HR-Admin writes template in UI-editor
  ↓
System validates merge-fields against hrmq-schema
  ↓
Template versioned (semver), marked active/draft/archived
  ↓
Render request arrives (employee X, template, locale)
  ↓
System snapshots employee+contract+tenant data at render-time
  ↓
If requires_approval=true: route to approval-workflow
  ├─ Draft PDF generated (watermerk "CONCEPT")
  ├─ Approvers review → approve/reject
  └─ On final approval: render definitive PDF
  ↓
If requires_approval=false: render PDF directly
  ↓
PDF converted to PDF/A-2b, hashed (SHA256)
  ↓
Stored in docudesk with bewaartermijn-link
  ↓
Indexed for audit-search (employee-name, contractnummer, render-datum)
```

## Data Model

### Core Entities

#### `hrmq_document_template`

Versioned template definition for a single document-type (e.g. arbeidsovereenkomst-onbepaalde-tijd).

```jsonschema
{
  "type": "object",
  "properties": {
    "id": { "type": "string", "description": "UUID" },
    "tenant_id": { "type": "string", "description": "UUID of owning tenant" },
    "slug": { "type": "string", "description": "e.g. 'arbeidsovereenkomst-onbepaalde-tijd'" },
    "name": { "type": "string", "description": "Display name: 'Arbeidsovereenkomst (onbepaalde tijd)'" },
    "version": { "type": "string", "pattern": "^\\d+\\.\\d+\\.\\d+$", "description": "Semver (e.g. 3.2.0)" },
    "status": { "enum": ["draft", "active", "archived"], "description": "Publication status" },
    "effective_from": { "type": "string", "format": "date-time", "description": "When this version becomes active" },
    "effective_until": { "type": "string", "format": "date-time", "nullable": true, "description": "When this version retires (null = still active)" },
    "markdown_source": { "type": "string", "description": "Template text in Markdown + Mustache syntax" },
    "merge_field_schema": { "type": "object", "description": "JSONB: validated fields drawn from markdown_source at save-time. Keys are field-paths like 'employee.bsn', values are type+description." },
    "partials_used": { "type": "array", "items": { "type": "string" }, "description": "Names of partials included (e.g. ['briefhoofd', 'ondertekening'])" },
    "locales": { "type": "array", "items": { "enum": ["nl", "en", "de", "be"] }, "description": "Supported locales for this template" },
    "requires_approval": { "type": "boolean", "description": "If true, render-requests route through approval-workflow" },
    "approval_workflow_id": { "type": "string", "nullable": true, "description": "UUID of workflow to use if requires_approval=true" },
    "rechtsgebied": { "enum": ["nl", "be", "de"], "default": "nl", "description": "Legal jurisdiction for this template" },
    "created_at": { "type": "string", "format": "date-time" },
    "updated_at": { "type": "string", "format": "date-time" },
    "created_by": { "type": "string", "description": "User ID or service-account" },
    "updated_by": { "type": "string" }
  },
  "required": ["id", "tenant_id", "slug", "name", "version", "status", "markdown_source", "merge_field_schema", "locales"],
  "indexes": ["(tenant_id, slug, status)", "(tenant_id, effective_from)", "(tenant_id, created_at)"]
}
```

#### `hrmq_document_partial`

Tenant-scoped reusable template blocks (briefpapier-header, ondertekening, juridische clausules).

```jsonschema
{
  "type": "object",
  "properties": {
    "id": { "type": "string", "description": "UUID" },
    "tenant_id": { "type": "string", "description": "UUID of owning tenant" },
    "slug": { "type": "string", "description": "e.g. 'briefhoofd', 'ondertekening', 'avg-clausule'" },
    "name": { "type": "string", "description": "Display name" },
    "version": { "type": "string", "pattern": "^\\d+\\.\\d+\\.\\d+$" },
    "status": { "enum": ["draft", "active", "archived"] },
    "markdown_source": { "type": "string", "description": "Partial text (can include merge-fields like {{tenant.kvk_nummer}})" },
    "merge_field_schema": { "type": "object", "description": "Fields used in this partial" },
    "locales": { "type": "array", "items": { "enum": ["nl", "en", "de", "be"] } },
    "created_at": { "type": "string", "format": "date-time" },
    "updated_at": { "type": "string", "format": "date-time" },
    "created_by": { "type": "string" },
    "updated_by": { "type": "string" }
  },
  "required": ["id", "tenant_id", "slug", "name", "version", "status", "markdown_source"],
  "indexes": ["(tenant_id, slug, status)"]
}
```

#### `hrmq_document_approval_workflow`

Ordered approval chain for high-risk documents.

```jsonschema
{
  "type": "object",
  "properties": {
    "id": { "type": "string", "description": "UUID" },
    "tenant_id": { "type": "string" },
    "name": { "type": "string", "description": "e.g. 'Vaststellingsovereenkomst Review'" },
    "approvers": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "order": { "type": "integer", "description": "Sequence (1, 2, 3, ...)" },
          "role": { "type": "string", "description": "Role name (e.g. 'HR-Manager', 'Legal-Counsel', 'CFO')" },
          "min_approval_threshold_amount": { "type": "number", "nullable": true, "description": "Only require approval if bedrag >= threshold. null = always required." }
        },
        "required": ["order", "role"]
      }
    },
    "created_at": { "type": "string", "format": "date-time" },
    "updated_at": { "type": "string", "format": "date-time" }
  },
  "required": ["id", "tenant_id", "name", "approvers"],
  "indexes": ["(tenant_id, id)"]
}
```

#### `hrmq_document_render`

A single render event: template + merge-data → PDF.

```jsonschema
{
  "type": "object",
  "properties": {
    "id": { "type": "string", "description": "UUID" },
    "tenant_id": { "type": "string" },
    "template_id": { "type": "string", "description": "Reference to hrmq_document_template.id" },
    "template_version": { "type": "string", "description": "Template semver at render-time (e.g. '3.2.0')" },
    "target_entity_type": { "enum": ["employee", "contract", "payroll_run", "tenant"], "description": "What entity this document is for" },
    "target_entity_id": { "type": "string", "description": "UUID of the entity (employee_id, contract_id, etc.)" },
    "merge_data_snapshot": { "type": "object", "description": "JSONB: immutable data-snapshot at render-time (employee, contract, tenant, payroll-run fields)" },
    "locale": { "enum": ["nl", "en", "de", "be"], "default": "nl" },
    "pdf_blob_url": { "type": "string", "description": "docudesk storage URL" },
    "pdf_hash_sha256": { "type": "string", "description": "SHA256 of PDF binary" },
    "pdf_a_compliant": { "type": "boolean", "description": "true if stored as PDF/A-2b" },
    "rendered_at": { "type": "string", "format": "date-time" },
    "rendered_by": { "type": "string", "description": "User ID or service-account (e.g. 'payroll-engine')" },
    "superseded_by_render_id": { "type": "string", "nullable": true, "description": "If this render was re-rendered, points to newer render_id" },
    "approval_state": { "enum": ["draft", "in_review", "approved", "rejected", "published"], "nullable": true, "description": "Only set if approval-workflow active" }
  },
  "required": ["id", "tenant_id", "template_id", "target_entity_type", "target_entity_id", "merge_data_snapshot", "rendered_at", "rendered_by"],
  "indexes": ["(tenant_id, target_entity_type, target_entity_id)", "(tenant_id, rendered_at)", "(tenant_id, template_id)"]
}
```

#### `hrmq_document_approval`

Single approval decision within a workflow.

```jsonschema
{
  "type": "object",
  "properties": {
    "id": { "type": "string", "description": "UUID" },
    "tenant_id": { "type": "string" },
    "render_id": { "type": "string", "description": "Reference to hrmq_document_render.id" },
    "workflow_id": { "type": "string", "description": "Reference to approval_workflow_id" },
    "approver_order": { "type": "integer", "description": "Which step in the workflow (1, 2, 3)" },
    "approver_role": { "type": "string", "description": "e.g. 'HR-Manager'" },
    "approver_user_id": { "type": "string", "description": "UUID of the user who approved/rejected" },
    "decision": { "enum": ["approved", "rejected"], "description": "Approval decision" },
    "comment": { "type": "string", "nullable": true, "description": "Optional reason for rejection or approver notes" },
    "decided_at": { "type": "string", "format": "date-time" }
  },
  "required": ["id", "tenant_id", "render_id", "approver_role", "approver_user_id", "decision", "decided_at"],
  "indexes": ["(tenant_id, render_id, approver_order)", "(approver_user_id, decided_at)"]
}
```

#### `hrmq_document_bulk_run`

Mass-render event (e.g. loonronde, jaaropgaven).

```jsonschema
{
  "type": "object",
  "properties": {
    "id": { "type": "string", "description": "UUID" },
    "tenant_id": { "type": "string" },
    "template_id": { "type": "string", "description": "Reference to hrmq_document_template.id" },
    "target_set": { "type": "array", "items": { "type": "string" }, "description": "List of entity_ids (employee_ids, contract_ids, etc.)" },
    "status": { "enum": ["queued", "processing", "completed", "failed"], "description": "Bulk-run status" },
    "total_count": { "type": "integer", "description": "Total entities in batch" },
    "completed_count": { "type": "integer", "description": "Successfully rendered" },
    "failed_count": { "type": "integer", "description": "Failed" },
    "manifest_url": { "type": "string", "nullable": true, "description": "docudesk URL to CSV manifest + ZIP with PDFs" },
    "started_at": { "type": "string", "format": "date-time" },
    "completed_at": { "type": "string", "format": "date-time", "nullable": true },
    "created_by": { "type": "string" }
  },
  "required": ["id", "tenant_id", "template_id", "target_set", "status", "total_count", "started_at"],
  "indexes": ["(tenant_id, template_id, started_at)", "(tenant_id, status)"]
}
```

## API Endpoints (Summary)

| Method | Path | Description |
|---|---|---|
| POST | `/api/v1/tenants/{tenant_id}/templates` | Create template |
| GET | `/api/v1/tenants/{tenant_id}/templates` | List templates |
| GET | `/api/v1/tenants/{tenant_id}/templates/{template_id}` | Get template (latest active version) |
| PATCH | `/api/v1/tenants/{tenant_id}/templates/{template_id}` | Update template markdown, bump version |
| POST | `/api/v1/tenants/{tenant_id}/templates/{template_id}/publish` | Mark version active |
| POST | `/api/v1/tenants/{tenant_id}/partials` | Create partial |
| GET | `/api/v1/tenants/{tenant_id}/partials` | List partials |
| POST | `/api/v1/tenants/{tenant_id}/renders` | Create render request (async) |
| GET | `/api/v1/tenants/{tenant_id}/renders/{render_id}` | Get render (including pdf_blob_url, approval_state) |
| GET | `/api/v1/tenants/{tenant_id}/renders?target_entity_type=employee&target_entity_id={id}` | Get all renders for an entity |
| POST | `/api/v1/tenants/{tenant_id}/renders/{render_id}/approve` | Approve render (if in workflow) |
| POST | `/api/v1/tenants/{tenant_id}/renders/{render_id}/reject` | Reject render (if in workflow) |
| POST | `/api/v1/tenants/{tenant_id}/bulk-renders` | Initiate bulk-render (async) |
| GET | `/api/v1/tenants/{tenant_id}/bulk-renders/{bulk_run_id}` | Get bulk-run status |
| POST | `/api/v1/tenants/{tenant_id}/workflows` | Create approval workflow |
| GET | `/api/v1/tenants/{tenant_id}/workflows` | List workflows |

## Seed Data (Per-Tenant Standard Library)

### Standard Templates (12 base types, installed on tenant-provisioning)

Each template installed as version 1.0.0, status=active, requires_approval per table below.

#### Templates Detail

```yaml
# 1. Arbeidsovereenkomst onbepaalde tijd
- slug: arbeidsovereenkomst-onbepaalde-tijd
  name: Arbeidsovereenkomst (onbepaalde tijd)
  version: "1.0.0"
  status: active
  requires_approval: false
  merge_fields: [employee.name, employee.bsn, contract.start_date, contract.salary_annual, contract.fte, contract.function_title, tenant.name, tenant.kvk_nummer]
  locales: [nl, en]

# 2. Arbeidsovereenkomst bepaalde tijd
- slug: arbeidsovereenkomst-bepaalde-tijd
  name: Arbeidsovereenkomst (bepaalde tijd)"
  version: "1.0.0"
  status: active
  requires_approval: false
  merge_fields: [employee.name, employee.bsn, contract.start_date, contract.end_date, contract.salary_annual, contract.fte, contract.function_title, tenant.name]
  locales: [nl, en]

# 3. Oproepovereenkomst
- slug: oproepovereenkomst
  name: Oproepovereenkomst (casual-labour)
  version: "1.0.0"
  status: active
  requires_approval: false
  merge_fields: [employee.name, contract.hourly_rate, contract.hours_per_week_max, tenant.name]
  locales: [nl, en]

# 4. Stageovereenkomst
- slug: stageovereenkomst
  name: Stageovereenkomst
  version: "1.0.0"
  status: active
  requires_approval: false
  merge_fields: [employee.name, employee.email, contract.start_date, contract.end_date, contract.hours_per_week, contract.supervisor_name, tenant.name]
  locales: [nl, en]

# 5. Addendum Promotie
- slug: addendum-promotie
  name: Addendum — Promotie
  version: "1.0.0"
  status: active
  requires_approval: true  # Legal review before employee sees
  merge_fields: [employee.name, contract.new_function_title, contract.new_salary_annual, contract.new_fte, contract.effective_from, tenant.name]
  locales: [nl, en]

# 6. Addendum Salarisverhoging
- slug: addendum-salarisverhoging
  name: Addendum — Salarisverhoging
  version: "1.0.0"
  status: active
  requires_approval: false
  merge_fields: [employee.name, contract.salary_increase_amount, contract.salary_increase_effective_date, contract.new_salary_annual, tenant.name]
  locales: [nl, en]

# 7. Vaststellingsovereenkomst
- slug: vaststellingsovereenkomst
  name: Vaststellingsovereenkomst (settlement upon termination)
  version: "1.0.0"
  status: active
  requires_approval: true  # Legal + CFO review (financial exposure)
  merge_fields: [employee.name, employee.bsn, contract.termination_date, contract.severance_amount, contract.reason_for_termination, contract.ww_notice, tenant.name, tenant.kvk_nummer]
  locales: [nl]

# 8. Getuigschrift
- slug: getuigschrift
  name: Getuigschrift (reference letter)
  version: "1.0.0"
  status: active
  requires_approval: false
  merge_fields: [employee.name, contract.start_date, contract.end_date, contract.function_title, contract.performance_summary, tenant.name]
  locales: [nl, en]

# 9. Ontslag-aanzegging
- slug: ontslag-aanzegging
  name: Ontslag-aanzegging
  version: "1.0.0"
  status: active
  requires_approval: true  # HR-Manager review (legal risk)
  merge_fields: [employee.name, contract.termination_notice_date, contract.termination_effective_date, contract.reason_for_termination, tenant.name, tenant.kvk_nummer]
  locales: [nl]

# 10. BAPO-bevestiging
- slug: bapo-bevestiging
  name: BAPO-bevestiging (unemployment-benefits confirmation)
  version: "1.0.0"
  status: active
  requires_approval: false
  merge_fields: [employee.name, employee.bsn, contract.termination_date, contract.reason_for_termination, employee.address, tenant.name]
  locales: [nl]

# 11. Concurrentiebeding-opheffing
- slug: concurrentiebeding-opheffing
  name: Concurrentiebeding-opheffing (waiver of non-compete)
  version: "1.0.0"
  status: active
  requires_approval: true  # Legal review (contract modification)
  merge_fields: [employee.name, contract.end_date, contract.non_compete_duration_months, tenant.name, tenant.kvk_nummer]
  locales: [nl]

# 12. Geheimhoudingsverklaring
- slug: geheimhoudingsverklaring
  name: Geheimhoudingsverklaring (NDA)
  version: "1.0.0"
  status: active
  requires_approval: false
  merge_fields: [employee.name, contract.start_date, contract.sensitive_areas, tenant.name, tenant.kvk_nummer]
  locales: [nl, en]
```

### Standard Partials (3 base types, tenant-overridable)

```yaml
# 1. Briefhoofd (letterhead)
- slug: briefhoofd
  name: Briefhoofd (letterhead)
  version: "1.0.0"
  status: active
  markdown_source: |
    ![Logo]({{tenant.logo_url}})
    {{tenant.name}}
    {{tenant.address}}
    {{tenant.kvk_nummer}}
    T: {{tenant.phone}}
  locales: [nl, en]

# 2. Ondertekening (signature block)
- slug: ondertekening
  name: Ondertekening (signature block)
  version: "1.0.0"
  status: active
  markdown_source: |
    Namens {{tenant.name}},
    
    _________________________
    {{approver.name}}
    {{approver.function_title}}
    {{approver.date}}
  locales: [nl, en]

# 3. AVG-clausule (GDPR disclaimer)
- slug: avg-clausule
  name: AVG-clausule
  version: "1.0.0"
  status: active
  markdown_source: |
    Personeelsgegevens worden verwerkt overeenkomstig de Algemene Verordening Gegevensbescherming (AVG, Verordening (EU) 2016/679).
    Voor vragen: {{tenant.dpo_email}}
  locales: [nl, en]
```

### Standard Approval Workflow (for high-risk documents)

```yaml
# Vaststellingsovereenkomst review chain
- id: workflow-vaststellingsovereenkomst
  tenant_id: "default"  # Applied on provision
  name: Vaststellingsovereenkomst Review
  approvers:
    - order: 1
      role: HR-Manager
      min_approval_threshold_amount: null  # Always required
    - order: 2
      role: Legal-Counsel
      min_approval_threshold_amount: null
    - order: 3
      role: CFO
      min_approval_threshold_amount: 5000  # Only if severance >= 5000 EUR
```

## Integration Points

- **Employee/Contract Data:** Read from hrmq-base (employee, contract, payroll-run entities)
- **Docudesk:** Store PDF/A, retrieve dossier-links, enforce bewaartermijn
- **Self-Service Portal:** Deliver rendered PDFs to employees
- **Payroll-Engine:** Consume template-engine for loonstrook, jaaropgaven rendering
- **CAO-Modules:** Provide CAO-specific clause-libraries and versioning hooks
- **Approval-Workflow Engine:** State-machine for multi-step approvals

## Notes

- All templates are initially tenant-scoped forks of a master HRMQ library. Tenant can override partials but cannot edit master library.
- Merge-field validation is performed by comparing field-paths in markdown_source against a pre-built schema (employee, contract, payroll-run, tenant entities).
- Rendering is deterministic via Markdown-to-PDF + Mustache variable expansion, ensuring bit-identical PDFs from the same template+data.
- Approval workflows are optional per template; templates without approval_workflow_id render immediately.
- Bulk renders (loonronde, jaaropgaven) are background jobs that produce a CSV manifest + ZIP of PDFs.

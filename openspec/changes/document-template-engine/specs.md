# Document Template Engine — Specifications

**Change:** document-template-engine  
**Status:** draft  
**Date:** 2026-05-23

## Functional Requirements

### REQ-DTE-001: Template Authoring UI with Merge-Field Validation

**GIVEN** an HR-admin user opens the template-editor for a new arbeidsovereenkomst  
**WHEN** they type `{{employee.` into the markdown-source input  
**THEN** the system displays autocomplete suggestions (employee.name, employee.bsn, employee.email, employee.address, etc.) drawn from the hrmq-base schema.

**GIVEN** an HR-admin completes the template-edit and clicks "Save"  
**WHEN** the template contains a merge-field like `{{employee.bsnummer}}` (typo: should be `bsn`)  
**THEN** the system rejects the save with error: "Regel 42: employee.bsnummer bestaat niet in het employee-schema. Bedoelde je employee.bsn?" and highlights the offending line.

**GIVEN** a valid template is saved  
**WHEN** the user hasn't specified a version  
**THEN** the system auto-assigns version 1.0.0 (or next minor-bump if updating an existing template).

**GIVEN** a template is saved with locales=["nl", "en"]  
**WHEN** the user hasn't filled in `locales` explicitly  
**THEN** the system defaults to ["nl"] and prompts the user to add translations.

**GIVEN** a template has conditional blocks: `{{#if contract.proeftijd_maanden > 0}}proeftijd of {{contract.proeftijd_maanden}} maanden{{/if}}`  
**WHEN** the system validates merge-fields  
**THEN** it recursively validates all fields inside the conditional, and rejects the template if contract.proeftijd_maanden is not a number-type in the schema.

**GIVEN** a user clicks "Preview"  
**WHEN** the template contains merge-fields  
**THEN** the system loads a random employee from the tenant and renders a live preview using that employee's data. Conditional blocks are evaluated. If a field references a relationship (e.g. {{contract....}} but no contract found), preview shows placeholder text "Contract data unavailable for this employee".

### REQ-DTE-002: Conditional Blocks and Nested Logic

**GIVEN** a template contains: `{{#if contract.proeftijd_maanden > 0}}Proeftijd: {{contract.proeftijd_maanden}} maanden{{/if}}`  
**WHEN** a render is executed for a contract with proeftijd_maanden = 0  
**THEN** the entire conditional block is omitted from the PDF (no empty lines left behind).

**GIVEN** the same template is rendered for a contract with proeftijd_maanden = 2  
**WHEN** the render completes  
**THEN** the PDF contains the text "Proeftijd: 2 maanden".

**GIVEN** a template has nested conditionals: `{{#if contract.status = "active"}}{{#if contract.fte > 0.5}}Full-time{{/if}}{{/if}}`  
**WHEN** the system validates merge-fields  
**THEN** it supports up to 5 nesting levels and rejects templates with >5 levels with error "Maximaal 5 geneste conditional-blokken. Herstructureer je template."

**GIVEN** a conditional references a field that doesn't exist: `{{#if employee.foo_bar}}...{{/if}}`  
**WHEN** the system validates  
**THEN** it rejects with error: "Regel X: employee.foo_bar bestaat niet. Conditie-fout."

### REQ-DTE-003: Tenant-Scoped Partials with Branding

**GIVEN** a template includes a partial: `{{> briefhoofd}}`  
**WHEN** the render is executed  
**THEN** the system looks up the partial "briefhoofd" in the tenant's partial-library first. If found (status=active), it is inserted. If not found, the system falls back to the master HRMQ default.

**GIVEN** a tenant has created a custom partial "briefhoofd" with their logo and address  
**WHEN** a render includes `{{> briefhoofd}}`  
**THEN** the tenant's custom version is used (master default is ignored).

**GIVEN** a partial's markdown_source contains merge-fields: `{{tenant.kvk_nummer}}`  
**WHEN** the partial is rendered as part of a larger template  
**THEN** the tenant-scoped fields are resolved correctly, and employee-scoped fields (e.g. {{employee.name}} inside a partial) are also resolved.

**GIVEN** a partial-reference fails (partial doesn't exist and no fallback available): `{{> nonexistent_partial}}`  
**WHEN** the render is executed  
**THEN** the system returns error: "Partial 'nonexistent_partial' not found" and the render fails.

**GIVEN** a partial is marked status=archived  
**WHEN** a template tries to include it: `{{> archived_partial}}`  
**THEN** the render fails with error: "Partial 'archived_partial' is archived and cannot be used."

### REQ-DTE-004: Template Versioning and Effective Dates

**GIVEN** a template arbeidsovereenkomst-onbepaalde-tijd exists at version 2.3.0 (status=active)  
**WHEN** legal-counsel updates the WAB-clausule and saves the template  
**THEN** the system creates version 2.4.0, marks it status=draft (awaiting publication), and keeps version 2.3.0 active.

**GIVEN** version 2.4.0 is ready and the user clicks "Publish"  
**WHEN** no effective_from date is specified  
**THEN** the system marks 2.4.0 status=active immediately, and 2.3.0 is automatically archived. All subsequent renders use 2.4.0.

**GIVEN** a new law takes effect on 2026-07-01, and legal-counsel has drafted a template version that must activate on that date  
**WHEN** they save the template with version=2.5.0, status=active, effective_from="2026-07-01"  
**THEN** the system marks 2.5.0 as scheduled-active but does NOT use it yet. Renders on 2026-06-30 still use 2.4.0. On 2026-07-01, renders automatically switch to 2.5.0.

**GIVEN** a historical render was executed on 2026-06-15 using template version 2.4.0  
**WHEN** a user re-renders the same document (e.g., to correct a typo in employee-name)  
**THEN** the re-render uses the current active version (2.5.0 if now past 2026-07-01, or 2.4.0 if earlier). The original render retains its version in the audit-trail (superseded_by_render_id is set).

**GIVEN** an old version 2.3.0 is archived  
**WHEN** a user wants to re-render a document originally created with 2.3.0  
**THEN** they can explicitly request re-render with version=2.3.0 (even though archived) for audit purposes. The system allows it with a confirmation dialog: "Warning: this version is archived."

### REQ-DTE-005: Deterministic PDF Rendering with Immutable Data Snapshots

**GIVEN** a render-request arrives for employee X, template arbeidsovereenkomst-bepaalde-tijd, on 2026-05-23 at 14:30  
**WHEN** the system executes the render  
**THEN** it snapshots ALL relevant merge-data at that moment:
- employee (name, bsn, address, birth-date, email, etc.)
- contract (salary, start-date, end-date, fte, function-title, probation-months, etc.)
- tenant (name, kvk, address, dpo-email, branding, etc.)
- payroll-run (if applicable)

The snapshot is stored immutable in hrmq_document_render.merge_data_snapshot as JSONB.

**GIVEN** a snapshot has been captured and the PDF rendered (PDF hash = abc123def456)  
**WHEN** the employee's salary is updated the next day  
**THEN** the existing render is not affected. If the user requests a re-render with the same template and same employee, the new render uses current data (new salary) and produces a different PDF (different hash). The old render retains its snapshot and hash.

**GIVEN** two separate render-requests for the same employee, same template, executed at the exact same moment with identical merge-data  
**WHEN** both renders complete  
**THEN** both PDFs are byte-identical (same hash). This property ensures audit-trail consistency: if an employee claims their contract changed, the hash can prove it didn't.

**GIVEN** a user re-renders an existing render with "use original data"  
**WHEN** the system uses the immutable merge_data_snapshot from the original render  
**THEN** the output PDF is byte-identical to the original (same hash).

### REQ-DTE-006: Approval Workflow for High-Risk Templates

**GIVEN** a template vaststellingsovereenkomst has `requires_approval=true` with workflow_id pointing to a 3-step chain: [HR-Manager → Legal-Counsel → CFO]  
**WHEN** an HR-medewerker initiates a render-request  
**THEN** the system:
1. Generates a DRAFT PDF with visible watermark "CONCEPT — niet juridisch bindend" (red, 50% opacity)
2. Creates a hrmq_document_render record with approval_state=draft
3. Routes an approval-notification to the HR-Manager
4. Does NOT publish the PDF or make it available to the employee

**GIVEN** the HR-Manager reviews the draft and clicks "Approve"  
**WHEN** the approval is recorded  
**THEN** the system:
1. Creates a hrmq_document_approval record (HR-Manager, decision=approved, timestamp)
2. Routes the PDF to the next approver (Legal-Counsel)
3. Updates render.approval_state=in_review
4. Notifies Legal-Counsel that approval is pending

**GIVEN** Legal-Counsel reviews and clicks "Reject" with comment "WAB-clausule is outdated"  
**WHEN** the rejection is recorded  
**THEN** the system:
1. Creates a hrmq_document_approval record (Legal-Counsel, decision=rejected, comment)
2. Routes the draft and rejection-reason back to the HR-Manager
3. Resets approval_state to draft (re-entry allowed)
4. Notifies HR-Manager of rejection

**GIVEN** the HR-Manager has re-drafted (bumped template version), and all three approvers have approved in sequence  
**WHEN** the CFO (final approver) approves  
**THEN** the system:
1. Records the CFO approval
2. Generates the FINAL PDF (no watermark)
3. Computes pdf_hash_sha256
4. Stores in docudesk with bewaartermijn-link
5. Sets approval_state=published
6. Routes the final PDF to the employee (via self-service-portaal or email)
7. Creates an audit-log entry: "Vaststellingsovereenkomst approved and published 2026-05-23 by [HR, Legal, CFO]"

**GIVEN** a template's approval-workflow has a min_approval_threshold_amount: vaststellingsovereenkomst with threshold=5000 EUR for CFO  
**WHEN** a render is requested for severance_amount = 4500 EUR  
**THEN** the approval-chain skips the CFO (only HR-Manager → Legal-Counsel required). Threshold not met.

**GIVEN** the same template, severance_amount = 6500 EUR  
**WHEN** a render is requested  
**THEN** the full 3-step chain is required (HR-Manager → Legal-Counsel → CFO).

### REQ-DTE-007: Standard Template Library on Tenant Provisioning

**GIVEN** a new tenant is created (e.g., a manufacturing company in Noord-Holland)  
**WHEN** the provisioning-script runs  
**THEN** the system automatically forks the master HRMQ template-library to the new tenant:
1. Copies 12 base templates (arbeidsovereenkomst-onbepaalde-tijd, bepaalde-tijd, oproepovereenkomst, stageovereenkomst, addendum-promotie, addendum-salarisverhoging, vaststellingsovereenkomst, getuigschrift, ontslag-aanzegging, BAPO-bevestiging, concurrentiebeding-opheffing, geheimhoudingsverklaring)
2. Copies 3 base partials (briefhoofd, ondertekening, avg-clausule)
3. Creates the standard approval-workflow for vaststellingsovereenkomst (HR-Manager → Legal-Counsel → CFO)
4. Installs all as version 1.0.0, status=active

**GIVEN** the tenant selects rechtsgebied=be during provisioning  
**WHEN** templates are installed  
**THEN** Belgian-specific templates (e.g., vaststellingsovereenkomst-be with Belgian severance rules) are used instead of Dutch defaults. Mix-and-match is not allowed; rechtsgebied is a global tenant setting.

**GIVEN** templates are forked to a new tenant  
**WHEN** the master HRMQ library is updated (e.g., new WAB legal guidance)  
**THEN** the tenant's forked versions do NOT auto-update. The tenant must explicitly pull updates (future sync feature). This prevents legal-drift surprises.

**GIVEN** a tenant has had the standard library for 6 months and has customized 5 templates  
**WHEN** the tenant's admin clicks "Sync with master library"  
**THEN** the system:
1. Identifies which templates have been customized (hashes differ from master)
2. Shows a diff view of master-version vs. tenant-version
3. Allows cherry-pick approval of updates
4. Does NOT force updates; manual approval required

### REQ-DTE-008: PDF/A Archival and Bewaartermijn Compliance

**GIVEN** a render completes and a PDF is generated  
**WHEN** the system prepares to store it  
**THEN** it:
1. Converts the PDF to PDF/A-2b format (ISO 19005-2)
2. Embeds all fonts (no external references)
3. Computes SHA256 hash of the PDF/A binary
4. Stores the PDF/A in docudesk via the document-storage API
5. Sets hrmq_document_render.pdf_a_compliant=true

**GIVEN** a contract-template is rendered (target_entity_type=contract)  
**WHEN** the PDF is stored  
**THEN** the system links it to the employee's dossier in docudesk with:
- retention_rule = "7 jaren na uitdiensttreding" (per Belastingdienst)
- indexed_fields = [employee.name, contract.contract_number, render_date]
- accessible_to_roles = [HR-Admin, Employee, Auditor]

**GIVEN** a vaststellingsovereenkomst is rendered  
**WHEN** it is stored  
**THEN** the retention_rule is set to "7 jaren na vaststellingsdatum" (highest legal requirement between employment and settlement law).

**GIVEN** a loonstrook is rendered (payroll context)  
**WHEN** it is stored  
**THEN** the retention_rule is "7 jaren" (Belastingdienst loonadministratie).

**GIVEN** a PDF is stored as PDF/A-2b with embedded fonts and no external resources  
**WHEN** 5 years pass and the system performs a bewaartermijn-audit  
**THEN** the PDF is still readable (no dependency on original fonts, external URLs, or software versions). PDF/A ensures long-term archival.

**GIVEN** an audit-inspector requests "all PDFs for employee X in calendar year 2025"  
**WHEN** they query the render-trail  
**THEN** the system returns a list of all renders linked to that employee in that year, with:
- template-slug and version
- render-date, render-by (user/service)
- merge-data-snapshot summary (salary, function, contract-status)
- docudesk-url to the actual PDF/A
- approval-trail (who approved, when, any rejection-reasons)

### REQ-DTE-009: Multi-Language Rendering

**GIVEN** a template arbeidsovereenkomst-onbepaalde-tijd has locales=[nl, en]  
**WHEN** the template is published  
**THEN** the system requires that merge-fields appear in BOTH nl and en source-code (or uses locale-agnostic fields like employee.name that don't need translation).

**GIVEN** a render-request specifies locale="en"  
**WHEN** the system executes the render  
**THEN** it uses the en-variant of the template-source (if present), or falls back to nl with a warning.

**GIVEN** an employee's profile has preferred_locale="en"  
**WHEN** a render-request doesn't explicitly specify locale  
**THEN** the system defaults to the employee's preferred_locale.

**GIVEN** a partial "ondertekening" has a locale-specific variant: `{{> ondertekening:en}}`  
**WHEN** the template is rendered with locale=en  
**THEN** the system inserts the en-variant of the partial. If not found, falls back to the nl-variant.

**GIVEN** a template is saved with locales=[nl, en] but only the nl-source is provided  
**WHEN** the system validates  
**THEN** it rejects with warning: "Template claims locales=[nl, en] but only nl-source is present. Add en-source or change locales to [nl]."

### REQ-DTE-010: Bulk Rendering for Mass-Mailings

**GIVEN** a payroll-admin initiates a bulk-render: loonstrook, target_set=[emp_1, emp_2, ..., emp_240], on 2026-05-23 (monthly loonronde)  
**WHEN** the request is submitted  
**THEN** the system:
1. Creates a hrmq_document_bulk_run record with status=queued
2. Enqueues the job to a background-queue (e.g., Redis, RabbitMQ)
3. Returns the bulk_run_id immediately (no blocking)
4. Notifies the admin: "Bulk render queued. ID: {bulk_run_id}. You'll be notified when complete."

**GIVEN** the bulk-render job starts processing  
**WHEN** it executes  
**THEN** the system:
1. Spins up max 8 parallel worker-threads
2. Each worker renders an employee's loonstrook, captures PDF, updates the render record
3. On success: increments completed_count, stores PDF-URL
4. On failure (e.g., missing contract-data): increments failed_count, logs error-reason (does NOT block batch)
5. Produces a CSV-manifest with columns: employee_id, employee_name, render_status (success/failed), pdf_url, error_reason

**GIVEN** 240 renders are executing in parallel with 8 workers  
**WHEN** the batch completes (including failures)  
**THEN** the system:
1. Creates a ZIP file containing all 240 PDFs (or 238 successes + blank entries for failures)
2. Uploads the ZIP + CSV-manifest to docudesk
3. Updates hrmq_document_bulk_run: status=completed, completed_count=238, failed_count=2, manifest_url="{docudesk_url}"
4. Notifies the admin: "Bulk render complete. 238/240 succeeded. Download: {manifest_url}"
5. Routes successful PDFs to self-service-portaal per employee

**GIVEN** a failure occurs mid-render (e.g., database timeout on worker-5)  
**WHEN** the worker detects the failure  
**THEN** it:
1. Logs the error with timestamp, employee-id, error-trace
2. Marks that employee's status as failed in the manifest
3. Continues processing remaining employees (batch is NOT aborted)
4. Updates completed_count/failed_count accordingly

**GIVEN** bulk-render completes and manifest is created  
**WHEN** the admin downloads the manifest (CSV)  
**THEN** they see:
```
employee_id,employee_name,render_status,pdf_url,error_reason
emp_1,Emma Jansen,success,https://docudesk.../pdf_1.pdf,
emp_2,Piet de Vries,failed,,Missing contract data: fte not set
emp_3,...
```

### REQ-DTE-011: Merge-Field Type Validation

**GIVEN** a template contains a conditional with numeric comparison: `{{#if contract.salary_annual > 50000}}...{{/if}}`  
**WHEN** the system validates merge-fields  
**THEN** it checks that contract.salary_annual is typed as number in the hrmq-base schema. If the schema says salary_annual is a string, validation fails with error: "contract.salary_annual is a string, not a number. Cannot use > operator."

**GIVEN** a template contains date comparison: `{{#if contract.start_date < "2026-01-01"}}...{{/if}}`  
**WHEN** validation runs  
**THEN** it accepts date-type fields and validates the comparison syntax.

**GIVEN** a template uses string-methods: `{{employee.name:upper}}`  
**WHEN** validation runs  
**THEN** it checks if the merge-field library supports string filters. Supported: :upper, :lower, :capitalize, :truncate. Unsupported: :translate, :custom_function (rejects with "Filter :custom_function not supported").

### REQ-DTE-012: Audit Trail and Render History

**GIVEN** an employee's render-history is queried for a 6-month period  
**WHEN** the query `/api/v1/tenants/{id}/renders?target_entity_type=employee&target_entity_id={emp_id}&date_range=2026-01-01:2026-06-30`  
**THEN** the system returns:
```json
[
  {
    "render_id": "r_123",
    "template_slug": "arbeidsovereenkomst-bepaalde-tijd",
    "template_version": "1.2.0",
    "rendered_at": "2026-05-23T14:30:00Z",
    "rendered_by": "emma.jansen@tenant.nl",
    "approval_state": "published",
    "pdf_url": "https://docudesk/.../r_123.pdf",
    "pdf_hash": "abc123def456...",
    "merge_data_snapshot": {
      "employee": { "name": "John Doe", "bsn": "123456789", ... },
      "contract": { "salary_annual": 50000, "fte": 1.0, ... },
      "tenant": { ... }
    },
    "approvals": [
      { "approver_role": "HR-Manager", "decision": "approved", "decided_at": "2026-05-23T09:00:00Z" },
      { "approver_role": "Legal-Counsel", "decision": "approved", "decided_at": "2026-05-23T11:00:00Z" }
    ]
  },
  ...
]
```

**GIVEN** a user claims "my contract was changed without my knowledge"  
**WHEN** the audit-trail is queried  
**THEN** the system can produce:
1. The original PDF/A (from docudesk, with timestamp)
2. The merge-data-snapshot used at render-time
3. The template-version used
4. Proof that re-rendering with the same snapshot produces byte-identical PDF (same hash)

This proves the contract was not altered after rendering.

## Non-Functional Requirements

### REQ-DTE-NF-001: Performance

- Template save with merge-field validation: < 2 seconds
- Render single document: < 5 seconds (includes PDF generation + docudesk upload)
- Bulk-render 240 documents: < 5 minutes (with 8 parallel workers)
- Approval-state transition (approve/reject): < 1 second
- Query render-history for 1000 renders: < 2 seconds

### REQ-DTE-NF-002: Reliability

- Bulk-render failures on individual documents must NOT block the batch
- PDF storage in docudesk must be transactionally linked to the render-record (if PDF fails to store, the render is marked failed and retried)
- Approval notifications must be delivered reliably (via email + in-app notification)

### REQ-DTE-NF-003: Security

- Merge-field validation MUST prevent template-injection attacks (Mustache sandboxing enforced)
- Template-source updates require tenant-admin role or higher
- Partial-updates require tenant-admin role
- Approval decisions are immutable once recorded (no edit, only new approval if rejected)
- Render audit-trail is read-only to compliance/audit roles

### REQ-DTE-NF-004: Scalability

- Support tenants with up to 10,000 employees per loonronde bulk-render
- Support tenant-templates library with up to 200 templates + 50 partials per tenant
- Support archive growth: each render produces 1 PDF/A (~100 KB avg); 10,000 employees × 12 months × 2 (loonstrook + jaaropgaven) = 240,000 PDFs/year = ~24 GB/year/tenant

### REQ-DTE-NF-005: Compliance

- All document-renders MUST be stored as PDF/A-2b (ISO 19005-2)
- All renders MUST have immutable merge-data-snapshots
- Audit-trail MUST be complete and tamper-proof
- Bewaartermijn enforcement MUST be automated (no manual tracking of retention dates)
- Multi-language templates MUST have translation-completeness validation

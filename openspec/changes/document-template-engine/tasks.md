# Document Template Engine — Tasks

**Change:** document-template-engine  
**Status:** draft  
**Date:** 2026-05-23

## Phase 1: Foundation (Core Data Model & API)

### Data Layer

- [ ] Create database migrations for 6 main entities:
  - [ ] hrmq_document_template (id, tenant_id, slug, name, version, status, markdown_source, merge_field_schema, partials_used, locales, requires_approval, approval_workflow_id, rechtsgebied, timestamps)
  - [ ] hrmq_document_partial (id, tenant_id, slug, name, version, status, markdown_source, merge_field_schema, locales, timestamps)
  - [ ] hrmq_document_approval_workflow (id, tenant_id, name, approvers array, timestamps)
  - [ ] hrmq_document_render (id, tenant_id, template_id, template_version, target_entity_type, target_entity_id, merge_data_snapshot, locale, pdf_blob_url, pdf_hash_sha256, pdf_a_compliant, rendered_at, rendered_by, approval_state, superseded_by_render_id)
  - [ ] hrmq_document_approval (id, tenant_id, render_id, workflow_id, approver_order, approver_role, approver_user_id, decision, comment, decided_at)
  - [ ] hrmq_document_bulk_run (id, tenant_id, template_id, target_set array, status, total_count, completed_count, failed_count, manifest_url, timestamps, created_by)
- [ ] Add indexes on (tenant_id, slug), (tenant_id, status), (tenant_id, rendered_at), (tenant_id, target_entity_type, target_entity_id), (approver_user_id, decided_at)
- [ ] Write migrations for prod rollout (idempotent, reversible)

### Merge-Field Validation Service

- [ ] Create merge-field-schema-builder service:
  - [ ] Parse Markdown + Mustache template-source and extract field-paths
  - [ ] Validate against hrmq-base schema (employee, contract, payroll_run, tenant entities)
  - [ ] Detect unsupported field-references and suggest alternatives (fuzzy-match)
  - [ ] Build a JSON schema document of validated fields and types
  - [ ] Return error-messages with line-numbers and field-suggestions
- [ ] Create field-reference validator:
  - [ ] Check type-compatibility for operators (e.g., > only on numeric fields)
  - [ ] Support string-filters (:upper, :lower, :capitalize, :truncate)
  - [ ] Reject custom/unsupported filters with clear errors
- [ ] Unit-test validation against 12 standard templates and edge cases (missing fields, typos, nested conditionals max-depth, circular partial references)

### Core Services (Backend)

- [ ] Implement TemplateService:
  - [ ] create(tenant_id, slug, name, markdown_source, locales) → validates and stores as v1.0.0
  - [ ] update(template_id, markdown_source) → bumps version, re-validates merge-fields
  - [ ] publish(template_id, version, effective_from?) → marks status=active, archives previous
  - [ ] get(tenant_id, slug) → returns current active version
  - [ ] getVersion(template_id, version) → returns specific version (even if archived)
  - [ ] list(tenant_id, filters?) → returns templates with pagination
  - [ ] delete(template_id) → soft-delete (mark archived)
- [ ] Implement PartialService:
  - [ ] create(tenant_id, slug, name, markdown_source, locales)
  - [ ] update(partial_id, markdown_source) → bumps version
  - [ ] publish(partial_id, version)
  - [ ] get(tenant_id, slug) → current active, with fallback to master-library
  - [ ] list(tenant_id)
- [ ] Implement RenderService:
  - [ ] initiate(tenant_id, template_id, target_entity_type, target_entity_id, locale?) → creates render record, captures merge-data-snapshot, enqueues render-job
  - [ ] getStatus(render_id) → returns render-state, approval-state, pdf_url
  - [ ] listForEntity(tenant_id, entity_type, entity_id) → returns all renders for that entity
  - [ ] approve(render_id, approver_user_id, comment?) → records approval, advances workflow
  - [ ] reject(render_id, approver_user_id, comment) → records rejection, resets to draft
- [ ] Implement ApprovalWorkflowService:
  - [ ] create(tenant_id, name, approvers) → validates approver-sequence
  - [ ] getWorkflow(workflow_id) → returns approval-chain config
  - [ ] evaluateThreshold(workflow_id, amount) → returns list of required-approvers (filtering by min_amount_threshold)
- [ ] Implement BulkRenderService:
  - [ ] initiate(tenant_id, template_id, target_entity_ids, locale?) → creates bulk_run record, enqueues parallel-job
  - [ ] getStatus(bulk_run_id) → returns completed_count, failed_count, status
  - [ ] getManifest(bulk_run_id) → returns CSV + ZIP-download URL

### PDF Rendering & Docudesk Integration

- [ ] Create PDF rendering engine:
  - [ ] Implement Markdown → HTML converter (use markdown-it library)
  - [ ] Implement Mustache-template expansion (use mustache.js, sandboxed)
  - [ ] Integrate with PDF-rendering library (e.g., puppeteer, wkhtmltopdf, or external PDF-service)
  - [ ] Support Markdown features: headings, bold, italic, lists, blockquotes, code-blocks, tables, images
  - [ ] Output to PDF binary
- [ ] Create PDF/A-2b converter:
  - [ ] Take PDF output and convert to PDF/A-2b (ISO 19005-2)
  - [ ] Embed all fonts (no external references)
  - [ ] Ensure no external URLs embedded
  - [ ] Compute SHA256 hash of final PDF/A binary
- [ ] Docudesk integration service:
  - [ ] upload(pdf_binary, filename, merge_data, entity_type, entity_id, retention_rule) → stores in docudesk, returns docudesk_url
  - [ ] link(docudesk_url, employee_id, render_id) → links PDF to employee-dossier, sets retention-rule
  - [ ] query(employee_id, date_range) → retrieves all PDFs for employee in date-range
  - [ ] Handle docudesk API rate-limits and retries
- [ ] Unit-test PDF rendering with sample templates, test edge-cases (long text, special characters, Unicode, conditional blocks)

### REST API Endpoints

- [ ] POST /api/v1/tenants/{tenant_id}/templates → create template
- [ ] GET /api/v1/tenants/{tenant_id}/templates → list templates (paginated, filter by status/locale/slug)
- [ ] GET /api/v1/tenants/{tenant_id}/templates/{template_id} → get current active version
- [ ] PATCH /api/v1/tenants/{tenant_id}/templates/{template_id} → update markdown-source
- [ ] POST /api/v1/tenants/{tenant_id}/templates/{template_id}/publish → publish version with optional effective_from
- [ ] POST /api/v1/tenants/{tenant_id}/partials → create partial
- [ ] GET /api/v1/tenants/{tenant_id}/partials → list partials
- [ ] PATCH /api/v1/tenants/{tenant_id}/partials/{partial_id} → update partial
- [ ] POST /api/v1/tenants/{tenant_id}/renders → initiate render (async, returns render_id)
- [ ] GET /api/v1/tenants/{tenant_id}/renders/{render_id} → get render-status + pdf_url + approval-state
- [ ] GET /api/v1/tenants/{tenant_id}/renders?target_entity_type={type}&target_entity_id={id} → list renders for entity
- [ ] POST /api/v1/tenants/{tenant_id}/renders/{render_id}/approve → approve (requires role check)
- [ ] POST /api/v1/tenants/{tenant_id}/renders/{render_id}/reject → reject with comment
- [ ] POST /api/v1/tenants/{tenant_id}/bulk-renders → initiate bulk-render (async, returns bulk_run_id)
- [ ] GET /api/v1/tenants/{tenant_id}/bulk-renders/{bulk_run_id} → get bulk-run status
- [ ] GET /api/v1/tenants/{tenant_id}/bulk-renders/{bulk_run_id}/manifest → download CSV + ZIP
- [ ] POST /api/v1/tenants/{tenant_id}/workflows → create approval-workflow
- [ ] GET /api/v1/tenants/{tenant_id}/workflows → list workflows
- [ ] Implement request-validation, auth-checks, error-handling per ADR-005 (security)
- [ ] Add OpenAPI/Swagger documentation for all endpoints

## Phase 2: UI & Editor

### Template Editor UI

- [ ] Build template-editor component (Vue/React):
  - [ ] Markdown source-editor with syntax-highlighting (use CodeMirror or Ace editor)
  - [ ] Merge-field autocomplete (on {{, suggest employee.*, contract.*, tenant.*, payroll_run.*)
  - [ ] Live preview pane (render with sample-employee data, highlight errors)
  - [ ] Conditional-block validator (visual warnings for nested-depth, syntax-errors)
  - [ ] Partial-reference browser (search & insert `{{> partial_name}}`)
  - [ ] Locale selector (add/remove locales, warn if ml-source missing)
  - [ ] Template metadata form (name, slug, version, description)
  - [ ] Save button (triggers validation, shows errors inline)
  - [ ] Publish button (with effective_from date-picker, confirmation dialog)
  - [ ] Version history (list previous versions, option to revert or view diff)
- [ ] Implement error-display:
  - [ ] Show validation errors with line-numbers and suggestions
  - [ ] Highlight offending merge-field in editor
  - [ ] Offer auto-fix for common typos (e.g., "bsnummer" → "bsn")
- [ ] Test editor with 12 standard templates, verify merge-field autocomplete works, live-preview renders correctly

### Partial Management UI

- [ ] Build partial-editor component:
  - [ ] Similar to template-editor (Markdown + merge-field validation)
  - [ ] Tenant-branding settings: upload logo, set default letterhead, signature-block
  - [ ] List existing partials, show which templates use each partial
  - [ ] Versioning + publish workflow (same as templates)

### Render Initiation UI

- [ ] Build render-request form (for manual renders):
  - [ ] Template selector (dropdown of active templates)
  - [ ] Target-entity selector (employee, contract, payroll-run)
  - [ ] Entity lookup (search by name, ID)
  - [ ] Locale selector (if template supports multiple locales)
  - [ ] Preview button (show draft without saving)
  - [ ] Submit button (initiate async render)
  - [ ] Status-polling (show render progress, errors if any)
  - [ ] Download button (when render complete, link to PDF in docudesk)

### Approval Workflow UI

- [ ] Build approval-dashboard for approvers:
  - [ ] List pending-approval renders (filtered by approver-role)
  - [ ] Show document summary (employee, contract, reason, amount if applicable)
  - [ ] Display draft PDF (with CONCEPT watermark if approval required)
  - [ ] Approve button (with optional comment)
  - [ ] Reject button (with required comment, reason for rejection)
  - [ ] Approval history (show previous approvals/rejections in chain)
  - [ ] Notification (email + in-app) when render reaches this approver
- [ ] Build approval-workflow-config UI (for admins):
  - [ ] Define approval-chains (role-sequence, min-amount-thresholds)
  - [ ] Assign workflows to templates
  - [ ] Test workflow routing (simulate a render, trace through approvers)

### Bulk-Render Progress UI

- [ ] Build bulk-render-monitor:
  - [ ] Show queue status (queued vs. processing)
  - [ ] Progress bar (completed_count / total_count)
  - [ ] Failed-renders list (with error-reason per failure)
  - [ ] Download manifest (CSV + ZIP) when complete
  - [ ] Auto-refresh status via polling (5-second intervals)

## Phase 3: Background Jobs & Integrations

### Render Job Processing

- [ ] Implement async render-job handler:
  - [ ] Dequeue render-request from job-queue
  - [ ] Fetch template + snapshot merge-data from cache or DB
  - [ ] Resolve all partials (tenant-scoped, with fallback to master)
  - [ ] Expand Mustache variables (sandboxed)
  - [ ] Evaluate conditionals
  - [ ] Call Markdown-to-HTML converter
  - [ ] Call PDF-renderer (puppeteer/wkhtmltopdf)
  - [ ] Convert PDF to PDF/A-2b
  - [ ] Compute hash
  - [ ] Upload to docudesk
  - [ ] Update render-record with pdf_url, hash, approval_state
  - [ ] If approval required: generate CONCEPT watermark and route to first approver
  - [ ] If no approval: mark approved and publish
  - [ ] Error handling: fail-safe logging, notify requestor of errors
- [ ] Implement bulk-render job handler:
  - [ ] Dequeue bulk_run_id
  - [ ] Fetch target_set (array of entity_ids)
  - [ ] Spawn 8 parallel worker-threads
  - [ ] Each worker: execute single render (above), update bulk_run.completed_count/failed_count
  - [ ] On batch completion: generate CSV-manifest + ZIP, upload to docudesk
  - [ ] Update bulk_run record: status=completed, manifest_url
  - [ ] Notify admin (email + in-app) with manifest download link
- [ ] Job timeout & retry logic:
  - [ ] Single render timeout: 30 seconds; auto-retry up to 2 times
  - [ ] Bulk render: individual failures don't block batch; overall timeout 1 hour
  - [ ] Dead-letter queue for permanently-failed renders (admin intervention required)
- [ ] Unit & integration test render jobs with mocked PDF-service

### Tenant Provisioning

- [ ] Implement tenant-provisioning hook (triggered on new-tenant creation):
  - [ ] Fork 12 standard templates to new tenant (version 1.0.0, status=active)
  - [ ] Fork 3 standard partials (briefhoofd, ondertekening, avg-clausule)
  - [ ] Create standard approval-workflow (vaststellingsovereenkomst: HR-Manager → Legal-Counsel → CFO)
  - [ ] Check tenant.rechtsgebied (nl vs. be) and use jurisdiction-specific templates
  - [ ] Log provisioning event (audit-trail)
- [ ] Integration test: create new tenant, verify all 12 templates + 3 partials are installed

### Approval Workflow State Machine

- [ ] Implement approval-state-machine:
  - [ ] States: draft → in_review → published (on final approval), or draft → in_review → draft (on rejection)
  - [ ] Transitions triggered by approve() / reject() calls
  - [ ] Each transition logs approver + timestamp
  - [ ] On state change, send notifications (email + in-app to next approver, or back to submitter if rejected)
  - [ ] Track approval-chain progress (step 1/3, step 2/3, step 3/3)
- [ ] Implement approval-routing:
  - [ ] Get workflow from template.approval_workflow_id
  - [ ] Evaluate threshold logic (is amount > min_approval_threshold? if not, skip approver)
  - [ ] Find next pending-approver role
  - [ ] Send approval-request notification to user(s) with that role in the tenant
- [ ] Unit test state-machine with various approval-chains, thresholds, rejections

### Notification Service

- [ ] Implement notification triggers:
  - [ ] On render initiated (awaiting approval): notify first approver
  - [ ] On approval step complete: notify next approver (or submitter if final)
  - [ ] On rejection: notify submitter with reason
  - [ ] On bulk-render complete: notify initiator with manifest download link
  - [ ] On PDF published: notify employee (if self-service-portaal integration enabled)
- [ ] Notification channels: email (via mail-service), in-app (via notification-API)
- [ ] Template notifications per locale (nl / en)
- [ ] Unit test notification-service with mocked mail & notification backends

## Phase 4: Compliance & Audit

### Audit Trail

- [ ] Implement audit-logging:
  - [ ] Template create/update/publish events → audit-log
  - [ ] Render initiation + completion → audit-log
  - [ ] Approval decisions → audit-log
  - [ ] Bulk-render events → audit-log
  - [ ] Each log-entry: timestamp, actor (user/service), action, entity-id, changes (before/after if applicable)
- [ ] Create audit-query API:
  - [ ] GET /api/v1/tenants/{id}/audit?entity_type={type}&entity_id={id}&date_range={from}:{to} → return audit-entries
  - [ ] GET /api/v1/tenants/{id}/renders/{render_id}/audit → return all events related to this render (creation, approvals, publication)
- [ ] Ensure audit-logs are immutable (append-only, no updates/deletes)

### Bewaartermijn & Archival

- [ ] Implement docudesk retention-rule assignment:
  - [ ] On render completion, determine retention-rule based on document-type:
    - [ ] Contract documents: 7 jaren na uitdiensttreding
    - [ ] Vaststellingsovereenkomst: 7 jaren na vaststellingsdatum
    - [ ] Loonstroken: 7 jaren
    - [ ] Jaaropgaven: 7 jaren
  - [ ] Call docudesk API to link PDF to retention-rule
  - [ ] Link render to employee-dossier (via docudesk entity-linking)
- [ ] Implement retention-enforcement (background job, monthly):
  - [ ] Query docudesk for PDFs past their retention-date
  - [ ] Archive or delete per data-governance policy
  - [ ] Log deletion events (audit-trail)
- [ ] Integration test with docudesk: verify PDF/A upload, retention-rule assignment, retrieval

### Data Export for Audit Inspectors

- [ ] Create audit-export endpoint:
  - [ ] GET /api/v1/tenants/{id}/export/renders?employee_id={emp_id}&year={2026} → return JSON/CSV with:
    - [ ] All renders for employee in calendar year 2026
    - [ ] Template-slug, template-version
    - [ ] Render-date, render-by, approvers
    - [ ] Merge-data-snapshot (summary: salary, function, contract-status)
    - [ ] PDF/A URL (docudesk download link)
    - [ ] Hash + proof of immutability
- [ ] Format: JSON (for programmatic audit) or CSV (for spreadsheet-import)
- [ ] Access control: audit/compliance roles only

## Phase 5: Testing & QA

### Unit Tests

- [ ] Merge-field validation service:
  - [ ] Valid field-references (employee.name, contract.salary_annual, etc.)
  - [ ] Typo detection (employee.bsnummer → error with suggestion)
  - [ ] Type-mismatches (string > 100, numeric field with :upper filter)
  - [ ] Nested conditionals (max 5 levels, reject >5)
  - [ ] Partial references (resolved correctly, circular-ref detection)
- [ ] Template versioning:
  - [ ] Version bumping (1.0.0 → 1.0.1 for patch, 1.1.0 for minor, 2.0.0 for major)
  - [ ] Effective-date scheduling (future versions don't activate until effective_from passes)
  - [ ] Version rollback (re-render with old version, even if archived)
- [ ] Approval workflow state-machine:
  - [ ] State transitions (draft → in_review → published, or back to draft on reject)
  - [ ] Threshold filtering (min_amount_threshold skips approver if not met)
  - [ ] Approval-chain routing (next-approver correctly identified)
- [ ] Merge-data snapshot immutability:
  - [ ] Same template + same data → same PDF hash
  - [ ] Data change → different hash (re-render uses new data)
  - [ ] Snapshot restore → byte-identical PDF

### Integration Tests

- [ ] End-to-end template authoring:
  - [ ] Create template with merge-fields
  - [ ] Validate on save (catch errors)
  - [ ] Publish to active
  - [ ] Render document with real employee-data
  - [ ] Verify PDF output contains correct merge-values
- [ ] End-to-end approval workflow:
  - [ ] Initiate render of requires_approval=true template
  - [ ] Route to first approver, approve
  - [ ] Route to second approver, approve
  - [ ] Route to third approver, reject (with comment)
  - [ ] Back to submitter (re-draft)
  - [ ] Re-route through chain again, final approval → publish
  - [ ] Verify PDF published without CONCEPT watermark
- [ ] Bulk-render with failures:
  - [ ] Initiate bulk-render for 10 employees
  - [ ] Mock 2 failures (missing contract-data)
  - [ ] Verify batch completes with 8 successes, 2 failures
  - [ ] Verify manifest contains both and correct error-reasons
  - [ ] Verify failed PDFs don't block successes
- [ ] Docudesk integration:
  - [ ] Upload PDF/A, verify stored in docudesk
  - [ ] Link to employee-dossier, verify linking succeeds
  - [ ] Set retention-rule, verify docudesk accepts it
  - [ ] Query render-history, verify all renders returned
- [ ] Tenant provisioning:
  - [ ] Create new tenant
  - [ ] Verify 12 templates + 3 partials installed
  - [ ] Verify approval-workflow created
  - [ ] Verify all templates are active (v1.0.0)

### Performance Tests

- [ ] Template validation: 1000 templates validated in < 5 seconds
- [ ] Render single document: < 5 seconds (including PDF generation)
- [ ] Bulk-render 240 documents: < 5 minutes (with 8 workers)
- [ ] Approval-state transition: < 1 second
- [ ] Query render-history (1000 renders): < 2 seconds
- [ ] PDF/A conversion: < 2 seconds per document

### Compliance & Security Tests

- [ ] Merge-field injection attack prevention:
  - [ ] Attempt to inject Mustache logic into merge-field (e.g., {{employee.name#...}})
  - [ ] Verify sandboxed execution prevents code-execution
- [ ] Authorization checks:
  - [ ] Non-admin tries to publish template → denied
  - [ ] Non-approver tries to approve render → denied
  - [ ] Employee tries to view another employee's render → denied
- [ ] Data-privacy:
  - [ ] Merge-data-snapshot contains PII (names, addresses, SSNs) → verify only accessible to authorized roles
  - [ ] PDF/A stored in docudesk → verify encryption at rest
- [ ] Audit-trail immutability:
  - [ ] Attempt to modify audit-log entry → rejected
  - [ ] Verify audit-logs are append-only

## Phase 6: Documentation & Rollout

### Developer Documentation

- [ ] API reference (OpenAPI/Swagger spec)
- [ ] Template authoring guide (Markdown syntax, merge-field syntax, conditionals, partials)
- [ ] Integration guide (how to embed template-engine in other apps like payroll-engine, decidesk)
- [ ] Deployment guide (configuration, environment-variables, docudesk setup)
- [ ] Troubleshooting guide (common errors, debugging)

### User Documentation

- [ ] HR-Administrator guide (template creation, preview, publishing)
- [ ] Approver guide (approval workflow, notification handling)
- [ ] Payroll-Admin guide (bulk-render, loonronde integration)
- [ ] Tenant-Admin guide (branding customization, partial-management)
- [ ] Legal-Counsel guide (template versioning, CAO-clause updates)

### Rollout Plan

- [ ] Alpha testing with 1 pilot tenant (internal HRMQ team)
  - [ ] Create 5 templates, test rendering
  - [ ] Test approval-workflow
  - [ ] Collect feedback
- [ ] Beta testing with 3 customer tenants
  - [ ] Full workflow testing (authoring, rendering, bulk-render, approval)
  - [ ] Performance testing at scale (100+ employees)
  - [ ] Compliance audit (bewaartermijn, PDF/A, audit-trail)
- [ ] GA release:
  - [ ] Tenant provisioning enabled
  - [ ] All 12 standard templates + 3 partials installed
  - [ ] Documentation published
  - [ ] Support training (1st-line support scripts for common issues)

## Phase 7: Post-MVP Features

These are out-of-scope for initial release but planned for future iterations.

- [ ] Real-time collaborative template editing (Google Docs style)
- [ ] WYSIWYG template editor (visual design instead of raw Markdown)
- [ ] Electronic signing integration (Evidos, SignHero via openconnector)
- [ ] Machine-translation support (auto-generate translations from nl-master)
- [ ] Template marketplace (browse/share community-templates)
- [ ] Advanced conditional logic (formula-builder, date-arithmetic)
- [ ] Multi-language partial-inheritance (shared en-partial across multiple templates)
- [ ] Dynamic merge-field discovery (pull available fields from entity-API instead of static schema)

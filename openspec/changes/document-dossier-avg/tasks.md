---
status: proposed
---

# Document Dossier & AVG Retention — Implementation Tasks

## 1. Data Model & Migrations

- [ ] 1.1 Create openregister migration: add `dossier-document` schema with fields (employee_id, category_id, nextcloud_file_id, nextcloud_file_path, mime_type, byte_size, sha256_hash, original_filename, display_title, description, effective_from, expires_at, retention_policy_id, retention_end_at, retention_hold, status, source, source_system_ref, signature_request_id, created_by, created_at, updated_at, last_accessed_at, last_accessed_by, version_number, superseded_at)
- [ ] 1.2 Create openregister migration: add `document-category` schema with fields (code, display_name_nl, display_name_en, description_nl, default_retention_policy_id, requires_signature, signature_type, default_acl_template_id, is_special_category, special_category_reason, legal_basis_default)
- [ ] 1.3 Create openregister migration: add `retention-policy` schema with fields (code, display_name_nl, years, months, anchor, legal_source, legal_source_url, destruction_action, destruction_action_detail, override_allowed)
- [ ] 1.4 Create openregister migration: add `acl-grant` schema with fields (dossier_document_id, principal_type, principal_id, permission, granted_by, granted_at, expires_at, grant_reason, legal_basis, override_approval_by, revoked_at)
- [ ] 1.5 Create openregister migration: add `signature-request` schema with fields (dossier_document_id, signature_type, signatories [jsonb], status, docudesk_envelope_id, docudesk_envelope_url, evidence_url, eidas_audit_trail_id, expires_at, declined_reason)
- [ ] 1.6 Create openregister migration: add `destruction-certificate` schema with fields (dossier_document_ids [array], executed_at, executed_by, policy_codes [array], destruction_method, destruction_method_detail, audit_chain_hash, signed_pdf_path, signed_pdf_hash, failure_log [jsonb])
- [ ] 1.7 Create indexes: (dossier_document: tenant_id, employee_id, category_id); (dossier_document: retention_end_at) partial where status='active'; (acl_grant: dossier_document_id, principal_id); (acl_grant: principal_id, principal_type); (signature_request: status, expires_at); (acl_grant: expires_at) partial where expires_at IS NOT NULL
- [ ] 1.8 Seed `document-category` rows: CONTRACT_ARBEID, BEOORDELING, ZIEKTEMELDING, OPLEIDING_CERT, SALARISBRIEF, ID_KOPIE, VOG, VERKLARING_GEDRAG, plus any others per spec context-brief
- [ ] 1.9 Seed `retention-policy` rows: FISCAAL_7Y_POST_FY, ARBEID_5Y_POST_DIENST, OVERHEID_50Y, ID_KOPIE_5Y_POST_DIENST, ZIEKTEVERZUIM_2Y_POST_DIENST, SOLLICITATIE_4W_POST_DECISION
- [ ] 1.10 Seed default ACL templates per category (e.g. SALARISBRIEF: employee_self view+download, HR view+download+edit, HR-admin view+download+edit+share+sign; ZIEKTEVERZUIM: employee_self view+download, HR view+download+edit with legal_basis=legal_obligation)
- [ ] 1.11 Create audit-log schema with fields (actor_id, target_type [dossier_document|acl_grant|signature_request], target_id, action [view|download|edit|delete|destroy|sign|grant], timestamp, ip_address, user_agent, details [jsonb]); indexes: (target_type, target_id), (actor_id, action), (timestamp)

## 2. Nextcloud Files Integration

- [ ] 2.1 Create system user for dossier binaries (e.g. "hrmq-dossier-system" with no login, full ACL injection capability)
- [ ] 2.2 Create per-employee folder structure: `/dossiers/{tenant_id}/{employee_id}/` owned by system user
- [ ] 2.3 Implement ACL injection via Files API: when an `acl-grant` is created, call Files API to SHARE the file/folder with the principal (user/group) and set permission (read, edit, share, etc.)
- [ ] 2.4 Implement ACL revocation: when an `acl-grant` expires or is deleted, call Files API to UNSHARE
- [ ] 2.5 For special-category documents: enable encryption-at-rest on the per-employee folder (e.g. via Nextcloud server-side encryption for that folder). Verify NEN 7510 / BIO baseline compliance.
- [ ] 2.6 Implement file integrity check (SHA256 hash) at upload time; store hash in dossier-document.sha256_hash for future tamper-evidence

## 3. Document Upload & Categorization

- [ ] 3.1 Create "Medewerkers › Documenten" detail tab component showing list of documents for the current employee
- [ ] 3.2 Create upload form with category selector and category-driven metadata fields (e.g. CONTRACT_ARBEID requires effective_from; VOG requires issuing_authority + issue_date)
- [ ] 3.3 Implement MIME/filename heuristic to pre-select category (e.g. "*.pdf" + "vog-*" → pre-select VOG category)
- [ ] 3.4 Implement category validation: reject upload if category=null
- [ ] 3.5 Implement per-category form schema: CONTRACT_ARBEID (effective_from, contract_type), VOG (issuing_authority, issue_date, expires_at), ZIEKTEVERZUIM (medical_notes [optional]), etc.
- [ ] 3.6 At upload commit, create `dossier-document` row with source=upload, status=active (or draft if requires_signature=true)
- [ ] 3.7 Compute retention_end_at using the category's default retention policy and anchor logic (decision 2 in design)
- [ ] 3.8 Apply default ACL template: create `acl-grant` rows for all role defaults (employee_self, manager, HR, HR-admin, OR-inzage as applicable per category)
- [ ] 3.9 For special-category documents, omit manager ACL and create audit-log entry "special-category handling applied"
- [ ] 3.10 Store binary in `/dossiers/{tenant_id}/{employee_id}/{file_id}.{ext}` via Nextcloud Files API; capture nextcloud_file_id, mime_type, byte_size, sha256_hash
- [ ] 3.11 Create initial audit-log entry for document.created with actor, timestamp

## 4. Document Versioning & History

- [ ] 4.1 Implement version increment: when a new file is uploaded for an existing `dossier_document`, increment version_number and create a new version entry (or use Nextcloud Files' native versioning if available)
- [ ] 4.2 Store old version as a separate record (or Nextcloud Files version node) with superseded_at timestamp
- [ ] 4.3 Implement "View History" UI showing all versions with timestamps, creators, and rollback actions
- [ ] 4.4 Implement rollback: copy content from version N back to a new version (create new version, do NOT move pointer); log audit entry "rollback to version N" with reason
- [ ] 4.5 Implement side-by-side diff viewer for comparing two versions (content diff, metadata diff)
- [ ] 4.6 At destruction time, destroy all versions together in one atomic batch; verify no partial-version destruction

## 5. E-Signature Integration

- [ ] 5.1 Create signature-request form when user clicks "Versturen ter ondertekening" on a document marked for signing
- [ ] 5.2 Pre-select signature_type from category's default (e.g. CONTRACT_ARBEID = advanced)
- [ ] 5.3 Populate signatories list with employee (sign_order=1) + werkgever-vertegenwoordiger (sign_order=2) or custom signatories per category
- [ ] 5.4 Submit to docudesk service-account API; capture docudesk_envelope_id and envelope_url
- [ ] 5.5 Create `signature-request` row with status=sent, docudesk_envelope_id, signatories list
- [ ] 5.6 Implement docudesk webhook listener: POST /dossiers/signature-webhook receiving envelope_id, status (completed|declined|expired), signed_pdf_url, eidas_audit_trail
- [ ] 5.7 On webhook completed: fetch signed PDF from docudesk; store as new version of dossier-document; create audit-log entry for each signatory (action=sign); transition document status to=signed; capture eidas_audit_trail_id
- [ ] 5.8 On webhook declined/expired: revert document status to draft; send notification to initiator with reason
- [ ] 5.9 Implement envelope-expiry auto-cancel: nightly job checks signature_request rows with status=sent and expires_at < NOW, cancels them, logs audit entry

## 6. AVG Retention Engine

- [ ] 6.1 Implement retention-end-at computation logic: given document.category + retention_policy + anchor, calculate retention_end_at (see decision 2 in design.md)
- [ ] 6.2 Call retention-computation function at document save (upload, version bump, metadata edit)
- [ ] 6.3 Implement employment-end event listener: when employee.employment_end event fires from employee-master, query all documents for that employee and recompute retention_end_at if anchor=employment_end
- [ ] 6.4 Implement fiscal-year-end event listener (if needed): recompute retention_end_at for FISCAAL_* policies on fiscal year boundary (2027-01-01, etc.)
- [ ] 6.5 Create nightly destruction job (cron: 23:30 UTC) that queries documents with status=active and retention_end_at < NOW and retention_hold=false
- [ ] 6.6 For each document to destroy: execute destruction_action (hard_delete from Files + register, anonymise, or archive_offsite per policy)
- [ ] 6.7 Transition destroyed documents to status=destroyed; keep metadata for audit trail
- [ ] 6.8 Create one `destruction-certificate` per batch with all destroyed document IDs, policy_codes, audit_chain_hash, signed_pdf
- [ ] 6.9 Log destruction as audit-log entry for each destroyed document with reference to destruction-certificate
- [ ] 6.10 Generate signed PDF archive of destruction-certificate (PDF/A-2b per NTA 9120); store in `/certificates/{date}-batch-{id}.pdf`
- [ ] 6.11 Implement legal-hold skip logic: skip documents with retention_hold=true, log hold-skip entry

## 7. ACL & Access Control

- [ ] 7.1 Implement default ACL matrix application at upload: create acl-grant rows for all applicable roles based on category
- [ ] 7.2 Implement per-document ACL override: HR-admin can grant named_user or named_group with expires_at, grant_reason, legal_basis
- [ ] 7.3 For special-category documents, require override_approval_by (second HR-admin) when granting to non-HR principals
- [ ] 7.4 Implement ACL revocation at expires_at: nightly job queries acl-grant with expires_at < NOW, revokes via Files API, logs audit entry
- [ ] 7.5 Implement ACL query: "what can principal X see" for audit/compliance reports
- [ ] 7.6 At download/view time, check acl-grant: if no matching grant for (document, user, action), return 403 DOSSIER_NO_ACCESS
- [ ] 7.7 Create audit-log entry for each denied access attempt
- [ ] 7.8 Implement ACL grant UI: form to grant temporary access with reason, expiry, legal basis; second-party approval for special-category overrides

## 8. Search & Filtering

- [ ] 8.1 Create "Medewerkers › Documenten" search UI with category, date-range, expiring-soon, missing-required facets
- [ ] 8.2 Implement full-text search on display_title and description
- [ ] 8.3 Apply access-control filter: query only documents where user has acl-grant (view or download)
- [ ] 8.4 For employees: filter to only employee_self documents
- [ ] 8.5 For managers: filter to documents for direct reports + non-salary categories
- [ ] 8.6 For HR: filter to all documents (no role filter)
- [ ] 8.7 For OR-inzage: filter to anonymised aggregate (count of documents per category, no individual employee names)
- [ ] 8.8 Implement "expiring in 30 days" report facet; group by category; include one-click "renew/replace" action
- [ ] 8.9 Implement "missing required" facet (e.g. show documents where is_special_category=true but legal_basis is not set)
- [ ] 8.10 Sort results by expiry date (soonest first) or recency (most recent first) per user preference

## 9. System-Generated Document Deposit

- [ ] 9.1 Create `/dossiers/documents/deposit` service API endpoint, authenticated with OAuth 2.0 service-account key
- [ ] 9.2 Validate payload: category (required), employee_id (required), effective_from (required), expires_at (optional), required-metadata per category
- [ ] 9.3 Implement deduplication: search existing dossier-documents with same category + employee + source_system_ref; if found, return existing document ID (idempotent 200)
- [ ] 9.4 Create new dossier-document row with source=system_generated, status=active, default ACL applied
- [ ] 9.5 Compute retention_end_at
- [ ] 9.6 Store binary in `/dossiers/{tenant_id}/{employee_id}/` via Files API
- [ ] 9.7 Return 201 with document ID and version URL
- [ ] 9.8 On invalid metadata, return 400 with METADATA_INCOMPLETE error
- [ ] 9.9 Create audit-log entry: action=system_deposit, actor=service-account, source_system_ref
- [ ] 9.10 Document API contract in OpenAPI spec / Swagger docs; include example payloads for payroll-engine-nl, time-attendance, leave-absence, rostering-planning

## 10. Special-Category Handling

- [ ] 10.1 At upload time, check if category.is_special_category=true; if so, create audit-log entry "special-category document detected"
- [ ] 10.2 Omit manager role from default ACL for special-category documents
- [ ] 10.3 For encryption-at-rest: mark per-employee folder as encrypted if any special-category document exists in it (or encrypt by default per NEN 7510)
- [ ] 10.4 Create special-category-override-approval form when HR-admin attempts to grant non-HR principal access
- [ ] 10.5 Require override_approval_by (second HR-admin) before granting; create acl-grant with override_approval_by set
- [ ] 10.6 Log override to special-category-override log (queryable by Data Protection Officer)
- [ ] 10.7 At destruction, annotate destruction-certificate with special-category nature
- [ ] 10.8 Create special-category-override report UI: list all overrides, reason, approver, timestamp (for AP audit)

## 11. Audit Logging

- [ ] 11.1 Create audit-log table with schema: actor_id, target_type, target_id, action, timestamp, ip_address, user_agent, details [jsonb]
- [ ] 11.2 Log document.view: create audit-log entry when document detail page loads; update last_accessed_at, last_accessed_by
- [ ] 11.3 Log document.download: create audit-log entry when Files API serves binary; capture byte_size_served, download_token
- [ ] 11.4 Log document.edit: create audit-log entry for metadata edits (old_value, new_value fields in details)
- [ ] 11.5 Log document.destroy: create audit-log entry for each destroyed document with executor=system, policy_code, destruction_certificate_id
- [ ] 11.6 Log document.sign: create audit-log entry for each signatory, action=sign, signatory_role
- [ ] 11.7 Log acl.granted: create audit-log entry when acl-grant is created, including grant_reason
- [ ] 11.8 Log acl.revoked: create audit-log entry when acl-grant expires or is deleted
- [ ] 11.9 Log access.denied: create audit-log entry when user attempts access without permission (403)
- [ ] 11.10 Ensure audit-log entries outlive the document (separate retention policy, e.g. document_retention + 10 years)
- [ ] 11.11 Create audit-log query UI: filter by actor, action, target, date range; export to CSV

## 12. Notifications & Events

- [ ] 12.1 Create n8n event: `dossier.document.created` (document_id, employee_id, category, created_by, created_at)
- [ ] 12.2 Create n8n event: `dossier.document.expiring_soon` (document_id, employee_id, category, expires_at, days_remaining)
- [ ] 12.3 Create n8n event: `dossier.document.destroyed` (document_ids [array], destruction_certificate_id, executed_at)
- [ ] 12.4 Create n8n event: `signature.completed` (document_id, signature_request_id, signatories_status, completed_at)
- [ ] 12.5 Implement in-app notification: when signature-request is declined/expired, notify initiator with reason
- [ ] 12.6 Implement in-app notification: when acl-grant is about to expire (1 week before), notify HR-admin

## 13. Data Protection Officer (AP) Compliance

- [ ] 13.1 Create AP dashboard UI: special-category-override log, destruction-certificate archive, audit-log export
- [ ] 13.2 Implement data-breach notification template: flag special-category document destruction/compromise for AP escalation
- [ ] 13.3 Create AP report: "All special-category documents and their handling (access grants, overrides, destructions)"
- [ ] 13.4 Create AP report: "Data retention compliance: documents by category, retention policy, destruction certificates"
- [ ] 13.5 Implement AP audit-trail export: CSV of audit-log entries for a date range, filterable by action/actor/target

## 14. Integration with Dependent Systems

- [ ] 14.1 Integrate with employee-master: listen for employment-end event, trigger retention_end_at recomputation
- [ ] 14.2 Integrate with payroll-engine-nl: define service-account key, document deposit API contract for SALARISBRIEF, jaaropgave, loonbeslag
- [ ] 14.3 Integrate with time-attendance: define service-account key, document deposit API contract for approved timesheet PDFs
- [ ] 14.4 Integrate with leave-absence: define service-account key, document deposit API contract for leave-decision PDFs
- [ ] 14.5 Integrate with rostering-planning: define service-account key, document deposit API contract for published roster PDFs
- [ ] 14.6 Integrate with docudesk: configure webhook URL, service-account credentials, test signature workflow end-to-end
- [ ] 14.7 Define ZGW-style document endpoints so ConductionNL apps can reference hrmq dossier documents without duplicating binaries

## 15. Testing & Verification

- [ ] 15.1 Unit test: retention-end-at computation for all anchor types (document_effective_from, employment_end, fiscal_year_end, document_expires_at)
- [ ] 15.2 Unit test: ACL grant/revoke logic with expiry, principal types, permission levels
- [ ] 15.3 Unit test: special-category document detection and ACL omission
- [ ] 15.4 Integration test: upload document → create acl-grants → verify Files API shares
- [ ] 15.5 Integration test: signature workflow: upload → send to docudesk → receive signed PDF → store as version
- [ ] 15.6 Integration test: system-generated deposit → deduplication → verify idempotence
- [ ] 15.7 Integration test: destruction job → select expired documents → hard-delete from Files + register → create certificate
- [ ] 15.8 Integration test: employment-end event → recompute retention_end_at for post-dienst documents
- [ ] 15.9 Functional test: employee searches own dossier, sees only own documents
- [ ] 15.10 Functional test: manager searches dossier, sees non-salary documents for direct reports
- [ ] 15.11 Functional test: HR searches dossier, sees all documents
- [ ] 15.12 Functional test: special-category override requires second-party approval
- [ ] 15.13 Functional test: audit-log entries are immutable and outlive destroyed documents
- [ ] 15.14 Performance test: search + facet query on 10k documents across 100 employees
- [ ] 15.15 Security test: attempt unauthorized download, verify 403 + audit-log entry
- [ ] 15.16 Security test: attempt special-category override without approval, verify rejection
- [ ] 15.17 Compliance test: verify destruction-certificate is signed PDF/A-2b format
- [ ] 15.18 Compliance test: verify encryption-at-rest on special-category folder (NEN 7510)

## 16. Documentation & Rollout

- [ ] 16.1 Write user guide: "Personnel Dossier in hrmq" (Dutch + English)
- [ ] 16.2 Write HR-admin guide: "Managing Document Categories, Retention, and Access"
- [ ] 16.3 Write Data Protection Officer guide: "Special-Category Handling, Audit Logs, Destruction Certificates"
- [ ] 16.4 Write API documentation: system-generated document deposit API with examples for each system (payroll, time, leave, rostering)
- [ ] 16.5 Create training video: upload document, categorize, grant temporary access, search, view history
- [ ] 16.6 Create training video: e-signature workflow, manage overrides, special-category compliance
- [ ] 16.7 Create changelog entry: "Personnel Dossier with AVG-compliant retention engine"
- [ ] 16.8 Plan rollout: soft-launch to single pilot tenant (e.g. early-adopter customer), gather feedback, iterate
- [ ] 16.9 Plan rollout: enable for all SMB tenants (5–250 medewerkers), with opt-in
- [ ] 16.10 Plan rollout: enable for public-sector (gemeente/Rijk) with OVERHEID_50Y policy preset

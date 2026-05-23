---
status: proposed
---

# Document Dossier & AVG Retention — Design

## Context

The document-dossier capability lives as a `DETAIL_TAB` on `Medewerkers › Documenten` (ADR-001). Every employee in the tenant can have multiple documents across categories (contracts, appraisals, sick leave notifications, training certificates, payslips, ID copies, VNG/VOG, declarations of good conduct). The dossier must be:

- **AVG-compliant:** Retention anchored to legal minimum per category; automatic destruction with certificate; access-control audit trail; special-category (art. 9) elevation.
- **Register-native:** Metadata lives in openregister as first-class queryable fields; binaries live in Nextcloud Files; the register is the source of truth for retention, ACL, audit.
- **Audit-first:** Every action (view, download, sign, destroy) is logged; audit entries outlive the destroyed object.
- **Integrable:** payroll, time, leave, rostering systems deposit documents via a service API without managing binaries themselves.

## Goals

- **Goal 1:** Eliminate the scattered Dropbox/email/filing-cabinet dossier by providing a structured, searchable, classified register-backed catalogue in hrmq.
- **Goal 2:** Enforce statutory retention via an automated destruction engine, reducing compliance risk and manual oversight.
- **Goal 3:** Provide per-document role-based + granular access control, ensuring employees see only their own dossier and managers have no access to salary documents (AVG least-privilege).
- **Goal 4:** Integrate e-signature (eIDAS) into the document lifecycle so contracts and declarations can be signed and versioned within hrmq.
- **Goal 5:** Log every access for audit, data-breach notification, and WOR (Works Council) oversight.
- **Goal 6:** Enable payroll, time, leave, and rostering systems to deposit documents without the operator uploading each one manually.

## Non-Goals

- **Not a general file-sharing vault:** This is not OneDrive or a shared team folder. It is role-scoped and legally classified.
- **Not an image OCR or document classifier:** AI-assisted categorisation (e.g. scanning a receipt to auto-detect VOG) is out of scope; users classify at upload time.
- **Not a full compliance-management system:** The dossier stores documents and enforces retention; it does not manage CAO interpretations, dispute workflows, or payroll corrections.
- **Not a replacement for external archives:** Documents that must be archived off-site (e.g. by sector-specific law) can be marked for archive-offsite destruction; hrmq triggers the integration but does not host the off-site vault.

## Key Architectural Decisions

### Decision 1: Dossier is a register-backed catalogue, not a folder tree

**Approach:**
The dossier is NOT a Nextcloud folder structure visible in the app's sidebar. Instead, every document is a `dossier-document` row in openregister with pointers to an underlying Nextcloud Files binary. The binaries live in a system-owned per-employee folder (`/dossiers/{tenant_id}/{employee_id}/` structure) with explicit ACL injection per `acl-grant` row. The register holds all metadata (category, retention class, signatories, ACL grants, audit log references).

**Rationale:**
- **Query expressiveness:** A single register query answers "all expiring VOGs across the team," "what can manager X see and why," "all ZIEKTEVERZUIM documents with legal_obligation basis." Folder navigation cannot answer these.
- **Metadata is first-class:** Retention, ACL, audit history are searchable/filterable, not trapped in folder names or file attributes.
- **Version control:** All versions of a document share the same retention class; partial-version destruction cannot occur.
- **Deduplication:** System-generated documents (e.g. payroll PDFs) reference a single binary even if deposited twice; folder navigation cannot deduplicate.

**Trade-off:** Users do not see a literal "My Dossier" folder in Files; the UI surfaces the register query as a searchable list. This is intentional — the dossier is a curated, legally classified surface, not a file dump.

### Decision 2: Retention is computed at save, executed nightly, anchored on statutory rules

**Approach:**
At save time (upload, version bump, or metadata edit), the system computes `retention_end_at` using the document's category's default policy plus any explicit override:
- **FISCAAL_7Y_POST_FY:** Anchored on fiscal_year_end(document_effective_from); e.g. a salary slip from 2026-03-15 expires 2033-12-31.
- **ARBEID_5Y_POST_DIENST:** Anchored on employment_end; e.g. a contract expires 5 years after the employee leaves.
- **OVERHEID_50Y:** Anchored on document_effective_from (or employment_start for some categories); for public-sector employers per Archiefwet.
- **ZIEKTEVERZUIM_2Y_POST_DIENST:** Anchored on employment_end per Wet poortwachter.
- **ID_KOPIE_5Y_POST_DIENST:** Anchored on employment_end per UAVG rules.

A nightly batch job:
1. Queries all `dossier-document` rows with status=active and retention_end_at < NOW.
2. Filters out documents under legal-hold (retention_hold=true).
3. Executes the configured destruction_action (hard_delete from Files + register, anonymise, or archive_offsite).
4. Writes one `destruction-certificate` per batch, signed with the system user's key, listing all destroyed IDs.
5. Transitions documents to status=destroyed; metadata rows are NOT deleted (audit trail must outlive the object).

**Rationale:**
- **Statutory compliance:** Retention is tied to legal citations (Archiefwet, AWR, Burgerlijk Wetboek), not arbitrary company policy.
- **Tamper-proof:** Nightly execution + certificate signature prevent accidental or malicious retention bypass.
- **Audit trail:** Destruction is logged; the log entry is NOT itself destroyed (unlike GDPR right-to-be-forgotten, destruction of classified documents requires proof).

**Trade-off:** Override (e.g. keep a contract longer for litigation) requires explicit legal-hold flag per document; there is no UI "extend retention" button without leaving an audit trail.

### Decision 3: ACL is role-default matrix + per-document grants with expiry

**Approach:**
Every document gets an implicit ACL based on its category and the accessing user's role:

| Principal | CONTRACT_ARBEID | SALARISBRIEF | ZIEKTEVERZUIM | VOG | Auditor (temp) |
|-----------|-----------------|-------------|-------------|-----|---------|
| Employee (self) | view+download | view+download | view+download | view+download | — |
| Direct manager | view | — | — | — | — |
| HR-role | view+download+edit | view+download+edit | view+download+edit | view+download+edit | view+download (if granted) |
| HR-admin | view+download+edit+share+sign | view+download+edit+share+sign | view+download+edit+share+sign | view+download+edit+share | view+download (if granted) |
| OR-inzage | aggregate (anonymised) | aggregate (anonymised) | aggregate (anonymised) | aggregate (anonymised) | — |

Beyond the matrix, an HR-admin can grant temporary permissions via `acl-grant` rows:
- Grant type: named_user or named_group with optional expiry.
- Required fields: principal_id, permission level, grant_reason (e.g. "external audit 2026 Q2"), legal_basis (contract, legitimate_interest, legal_obligation, consent), expires_at (optional).
- Override rule: if a named grant conflicts with the role-default matrix, the named grant applies; if it expires, the role-default applies again.

Special-category documents (ZIEKTEVERZUIM, biometric, union) do NOT default to manager access; an explicit override (with secondary HR-admin approval) is required.

**Rationale:**
- **Privacy by default:** Employees see only their own dossier; managers do NOT default to seeing salary documents.
- **Granular overrides:** Auditors can get temporary access without changing role permissions.
- **Legal audit trail:** Every grant reason is captured (e.g. "external audit," "disciplinary investigation") and is searchable in the AP's audit log.

**Trade-off:** The matrix is not user-editable; new roles or category-specific rules require code changes. Matrix rules are stable because they mirror Dutch law (AVG, arbeidsrecht, WOR), not business process.

### Decision 4: E-signature is eIDAS-compliant, category-driven, versioning-aware

**Approach:**
When an HR user opens a document marked for signature (category's `requires_signature=true`), they click "Versturen ter ondertekening" → a form lists signatories (employee + werkgever-vertegenwoordiger or external signatories per category's `signature_type`):

- **simple:** Employee only; used for akkoorden.
- **advanced:** Employee + HR-admin vertegenwoordiger; used for contracts.
- **qualified:** Same + external notary or third-party verifier; for aansprakelijkheidsverklaringen or high-stakes declarations.

A `signature-request` row is created with type=advanced (or the category default), signatories listed with sign_order. Docudesk sends each signatory an eIDAS-compliant signing link. When a signatory signs:
- Docudesk POSTs back with a signed PDF + eIDAS audit trail.
- System checks signature_request.status=partially_signed or completed.
- If completed, the signed PDF becomes a new version of the original document; a `dossier-document` version history entry records the old version with superseded_at; the document's status moves to=signed and is frozen from further edits.
- The eIDAS audit trail (evidence_url, audit_chain_hash) is recorded in the document metadata and is retained for the document's full lifetime + retention period.

If a signatory declines or the envelope expires:
- signature_request.status → declined or expired.
- Original document status → reverts to draft.
- Initiator receives a notification with the docudesk reason.

**Rationale:**
- **Legal certainty:** eIDAS signatures are legally equivalent to handwritten signatures (910/2014).
- **Category-aware:** Different documents require different assurance levels per law (e.g. arbeidscontracten ≥ advanced per Burgerlijk Wetboek).
- **Version chain:** Signed PDF is kept as a version; if the contract is later superseded, the old signed version remains in the audit trail.

**Trade-off:** Only docudesk-integrated documents can be signed; there is no in-hrmq signing (e.g. a manager clicking a "sign here" button inside hrmq). The e-signature evidence lives in docudesk and is referenced in hrmq.

### Decision 5: Special-category (AVG art. 9) documents default to HR-only, override requires two-person rule

**Approach:**
Documents in categories marked `is_special_category=true` (ZIEKTEVERZUIM, biometric, religious, union membership, criminal-record-related) are treated specially:

1. **Default ACL:** Only HR-role + HR-admin get default access; managers, employees, OR-inzage do NOT (except employee_self always has view on their own health data per GDPR).
2. **Grant override:** If an HR-admin attempts to grant a manager (or wider principal) access to a special-category document, the system requires:
   - A detailed override_reason (e.g. "disciplinary investigation" or "accommodation assessment").
   - Secondary approval by a DIFFERENT HR-admin (two-person rule, audit trail).
3. **Encryption-at-rest:** The Nextcloud Files folder containing special-category binaries MUST use encryption-at-rest (NEN 7510, BIO baseline for overheids-tenants).
4. **AP notification:** The override is logged in a special-category-override log visible to the Data Protection Officer (can export for AP audit).
5. **Destruction annotation:** When a special-category document is destroyed, the destruction-certificate notes its special-category nature so the AP can verify proportional handling.

**Rationale:**
- **GDPR art. 9 compliance:** Special categories require explicit legal basis + security measures. The two-person rule prevents a rogue HR-admin from unilaterally accessing sensitive health data.
- **AP oversight:** The AP (sometimes external, e.g. in a smaller firm) can audit overrides and verify necessity.
- **Encryption:** NEN 7510 / BIO baseline ensures healthcare and public-sector tenants meet industry-standard encryption for sensitive personal data.

**Trade-off:** The two-person rule adds process overhead for emergency-access scenarios; there is no expedited single-admin approval path.

### Decision 6: Audit log outlives the document; destruction itself is audited

**Approach:**
Every action on a document is logged to a tamper-evident audit log:

| Action | Logged Fields |
|--------|---|
| View | actor_id, target_dossier_document_id, action=view, timestamp, IP, user-agent, last_accessed_at updated |
| Download | actor_id, target_dossier_document_id, action=download, timestamp, byte_size_served, download_token, IP, user-agent |
| Edit | actor_id, target_dossier_document_id, action=edit, old_value/new_value (for metadata), timestamp |
| Delete (user-initiated) | actor_id, target_dossier_document_id, action=delete, timestamp, reason |
| Destroy (retention end) | executor=system, target_dossier_document_id, action=destroy, policy_code, destruction_certificate_id, timestamp |
| Sign | actor_id, target_signature_request_id, action=sign, signatory_role, timestamp |

Crucially: **Audit entries are NOT destroyed when the document is destroyed.** The audit-log table has its own retention policy (e.g. retain for the document's retention period + 10 years for legal disputes). This ensures:
- Chain-of-custody tracing for sensitive documents (e.g. a disciplinary investigation).
- Proof of proper destruction (audit entry links to destruction-certificate).
- Tamper-evidence (if an audit entry is modified or deleted, it is flagged).

**Rationale:**
- **Legal requirement:** GDPR art. 30 (processing register) requires documenting all processing; the audit log IS the register entry for the dossier.
- **Dispute resolution:** In a later tribunal case about wrongful dismissal, the audit log proves when documents were accessed by whom.

**Trade-off:** The audit log grows indefinitely (bounded by retention policy); there is no "audit log purge on deletion" shortcut. Queries on large audit logs require indexed searches (principal_id, target_document_id, action, timestamp).

### Decision 7: System-generated documents via service API, with deduplication

**Approach:**
payroll-engine-nl, time-attendance, leave-absence, and rostering-planning systems can POST documents to a `/dossiers/documents/deposit` endpoint authenticated with a service-account key. The payload includes:
- employee_id, category, effective_from, [optional: effective_to, expires_at]
- [Required metadata per category: e.g. SALARISBRIEF requires period]
- source_system_ref (jsonb: {"system":"payroll-engine-nl","batch_id":"2026-03",...})
- Binary: multipart/form-data file upload.

The endpoint:
1. Validates category + required metadata; rejects if incomplete.
2. Dedupes: searches for existing `dossier-document` with same category + employee + source_system_ref. If found, returns the existing document ID (idempotent 200).
3. Creates a new `dossier-document` row with source=system_generated, applies the category's default ACL template, computes retention_end_at.
4. Stores the binary in the system-owned per-employee Files folder.
5. Returns 201 with document ID and version URL.

**Rationale:**
- **System integration:** payroll and time systems do not re-upload the same PDF every batch; they reference the existing document.
- **Operator load:** HR does not manually upload 100 salary slips per month; they appear automatically.
- **Data quality:** Required metadata fields are enforced; the source system cannot deposit a SALARISBRIEF without a period.

**Trade-off:** External systems must be trusted (OAuth 2.0 service-account keys); there is no signature verification on the incoming file. Integrity is assumed at the protocol level (TLS).

## Data Model — Entity Definitions & Seed Data

### `dossier-document` (Catalogue Entry)

**Schema:**
```
{
  id: uuid,
  tenant_id: uuid,
  employee_id: uuid (ref employee-master),
  category_id: uuid (ref document-category),
  nextcloud_file_id: int (Nextcloud node ID),
  nextcloud_file_path: string (denormalised, e.g. /dossiers/{tenant}/{employee}/{file_id}),
  mime_type: string,
  byte_size: int,
  sha256_hash: string (integrity baseline for version comparison),
  original_filename: string,
  display_title: string (user-editable),
  description: text (user-editable),
  effective_from: date (when document took effect, e.g. contract start),
  expires_at: date (optional, e.g. VOG expiry),
  retention_policy_id: uuid (ref retention-policy, default from category),
  retention_end_at: date (computed: anchor + policy years/months),
  retention_hold: boolean (legal hold; overrides destruction),
  status: enum (draft, active, signed, archived, expired, destroyed),
  source: enum (upload, system_generated, e_sign_completed, scan, imported),
  source_system_ref: jsonb ({"system":"payroll-engine-nl","batch_id":"..."}),
  signature_request_id: uuid (nullable, ref signature-request),
  created_by: uuid,
  created_at: timestamp,
  updated_at: timestamp,
  last_accessed_at: timestamp,
  last_accessed_by: uuid,
  version_number: int (incremented on save),
  superseded_at: timestamp (if this is a historical version)
}
```

**Indexes:**
- (tenant_id, employee_id, category_id)
- (retention_end_at) partial where status='active' [for nightly destruction job]
- (created_at, tenant_id) [for recent-uploads queries]
- (signature_request_id) where signature_request_id IS NOT NULL

**Seed Data Example:**

```json
{
  "id": "doc-001",
  "tenant_id": "acme-nv",
  "employee_id": "emp-jansen",
  "category_id": "cat-contract",
  "nextcloud_file_id": 12345,
  "mime_type": "application/pdf",
  "byte_size": 450000,
  "sha256_hash": "abc123def456...",
  "original_filename": "arbeidscontract_jansen_2024.pdf",
  "display_title": "Arbeidscontract",
  "effective_from": "2024-01-01",
  "retention_policy_id": "pol-arbeid-5y-post",
  "retention_end_at": "2029-01-01",
  "status": "signed",
  "source": "e_sign_completed",
  "signature_request_id": "sig-001",
  "created_by": "hr-admin-001",
  "created_at": "2024-01-01T10:00:00Z",
  "last_accessed_at": "2026-05-15T14:30:00Z",
  "last_accessed_by": "emp-jansen"
}
```

### `document-category` (Controlled Vocabulary)

**Schema:**
```
{
  id: uuid,
  tenant_id: uuid,
  code: string (e.g. CONTRACT_ARBEID, BEOORDELING, ZIEKTEMELDING),
  display_name_nl: string,
  display_name_en: string,
  description_nl: text,
  default_retention_policy_id: uuid,
  requires_signature: boolean,
  signature_type: enum (none, simple, advanced, qualified),
  default_acl_template_id: uuid,
  is_special_category: boolean (AVG art. 9),
  special_category_reason: string (nullable, e.g. "health data per GDPR art. 9"),
  legal_basis_default: enum (contract, legitimate_interest, legal_obligation, consent),
  created_at: timestamp,
  is_active: boolean
}
```

**Seed Data Example:**

```json
{
  "code": "CONTRACT_ARBEID",
  "display_name_nl": "Arbeidscontract",
  "display_name_en": "Employment Contract",
  "description_nl": "Bindend arbeidscontract tussen werkgever en werknemer",
  "default_retention_policy_id": "pol-arbeid-5y-post",
  "requires_signature": true,
  "signature_type": "advanced",
  "is_special_category": false,
  "legal_basis_default": "contract"
},
{
  "code": "ZIEKTEMELDING",
  "display_name_nl": "Ziektemelding",
  "display_name_en": "Sick Leave Notification",
  "description_nl": "Medisch attest of eerste ziektedag-melding",
  "default_retention_policy_id": "pol-ziekteverzuim-2y-post",
  "requires_signature": false,
  "signature_type": "none",
  "is_special_category": true,
  "special_category_reason": "health data per GDPR art. 9",
  "legal_basis_default": "legal_obligation"
},
{
  "code": "SALARISBRIEF",
  "display_name_nl": "Salarisbrief",
  "display_name_en": "Payslip",
  "description_nl": "Maandelijkse of periodieke loonberekening",
  "default_retention_policy_id": "pol-fiscaal-7y-post-fy",
  "requires_signature": false,
  "signature_type": "none",
  "is_special_category": false,
  "legal_basis_default": "contract"
},
{
  "code": "VOG",
  "display_name_nl": "Verklaring Omtrent Gedrag",
  "display_name_en": "Certificate of Good Conduct",
  "description_nl": "Verklaring van de justitiële antecedenten",
  "default_retention_policy_id": "pol-vog-5y-post",
  "requires_signature": false,
  "signature_type": "none",
  "is_special_category": false,
  "legal_basis_default": "legal_obligation"
}
```

### `retention-policy` (Bewaartermijn Rule)

**Schema:**
```
{
  id: uuid,
  tenant_id: uuid,
  code: string (e.g. FISCAAL_7Y, ARBEID_POST_DIENST_5Y),
  display_name_nl: string,
  years: int,
  months: int (additional months beyond years),
  anchor: enum (document_effective_from, document_expires_at, employment_end, fiscal_year_end),
  legal_source: string (e.g. "AWR art. 115", "Burgerlijk Wetboek 7:658"),
  legal_source_url: string (nullable, e.g. link to justitie.nl),
  destruction_action: enum (hard_delete, anonymise, archive_offsite),
  destruction_action_detail: string (e.g. "archive_offsite" → integration name),
  override_allowed: boolean (usually false; legal_hold=true bypasses),
  created_at: timestamp,
  is_active: boolean
}
```

**Seed Data Example:**

```json
{
  "code": "FISCAAL_7Y_POST_FY",
  "display_name_nl": "Fiscaal: 7 jaar post-boekingsjaar",
  "years": 7,
  "months": 0,
  "anchor": "fiscal_year_end",
  "legal_source": "AWR art. 115 (Wet bewaarplicht administratie)",
  "legal_source_url": "https://wetten.overheid.nl/jci1.3:c:BWBR0001494:artikel=115",
  "destruction_action": "hard_delete",
  "override_allowed": false
},
{
  "code": "ARBEID_5Y_POST_DIENST",
  "display_name_nl": "Arbeidsrecht: 5 jaar na diensteinde",
  "years": 5,
  "months": 0,
  "anchor": "employment_end",
  "legal_source": "Burgerlijk Wetboek 7:658, arbeidsrechtelijke verjaring",
  "destruction_action": "hard_delete",
  "override_allowed": false
},
{
  "code": "ZIEKTEVERZUIM_2Y_POST_DIENST",
  "display_name_nl": "Ziektemelding: 2 jaar na diensteinde",
  "years": 2,
  "months": 0,
  "anchor": "employment_end",
  "legal_source": "Wet poortwachter, loondoorbetalingsplicht",
  "destruction_action": "hard_delete",
  "override_allowed": false
},
{
  "code": "OVERHEID_50Y",
  "display_name_nl": "Overheid: 50 jaar retentie",
  "years": 50,
  "months": 0,
  "anchor": "document_effective_from",
  "legal_source": "Archiefwet 1995, selectielijst rijksoverheid",
  "legal_source_url": "https://www.nationaalarchief.nl/archiveren/selectielijsten",
  "destruction_action": "hard_delete",
  "override_allowed": false
}
```

### `acl-grant` (Per-Document Permission)

**Schema:**
```
{
  id: uuid,
  tenant_id: uuid,
  dossier_document_id: uuid (ref dossier-document),
  principal_type: enum (employee_self, role, named_user, named_group),
  principal_id: string (role name or user/group UUID depending on type),
  permission: enum (view, download, edit, share, sign),
  granted_by: uuid,
  granted_at: timestamp,
  expires_at: timestamp (nullable; automatic revoke if set),
  grant_reason: string (e.g. "external audit Q2 2026", "disciplinary investigation"),
  legal_basis: enum (contract, legitimate_interest, legal_obligation, consent),
  override_approval_by: uuid (nullable; if set, this is a special-category override approved by a second HR-admin),
  created_at: timestamp,
  revoked_at: timestamp (nullable)
}
```

**Indexes:**
- (dossier_document_id, principal_type, principal_id)
- (principal_id, principal_type) [for "what can principal X see" queries]
- (expires_at) partial where expires_at IS NOT NULL [for nightly revocation job]

**Seed Data Example:**

```json
{
  "id": "grant-001",
  "dossier_document_id": "doc-001",
  "principal_type": "role",
  "principal_id": "HR_ROLE",
  "permission": "view",
  "granted_by": "system",
  "granted_at": "2024-01-01T00:00:00Z",
  "grant_reason": "Role default per category policy",
  "legal_basis": "contract",
  "created_at": "2024-01-01T00:00:00Z"
},
{
  "id": "grant-002",
  "dossier_document_id": "doc-001",
  "principal_type": "named_user",
  "principal_id": "auditor-ext-001",
  "permission": "download",
  "granted_by": "hr-admin-001",
  "granted_at": "2026-05-01T10:00:00Z",
  "expires_at": "2026-08-01T10:00:00Z",
  "grant_reason": "External audit Q2–Q3 2026",
  "legal_basis": "legitimate_interest",
  "created_at": "2026-05-01T10:00:00Z"
}
```

### `signature-request` (E-Sign Envelope)

**Schema:**
```
{
  id: uuid,
  tenant_id: uuid,
  dossier_document_id: uuid (ref dossier-document),
  signature_type: enum (simple, advanced, qualified),
  signatories: jsonb array of {
    "id": uuid,
    "employee_id": uuid or "external_email": string,
    "role": string (e.g. "employee", "werkgever_vertegenwoordiger", "notary"),
    "sign_order": int (1, 2, 3... execution order),
    "status": enum (pending, signed, declined, expired),
    "signed_at": timestamp (nullable),
    "evidence_hash": string (nullable, SHA256 of signed PDF)
  },
  status: enum (draft, sent, partially_signed, completed, declined, expired, cancelled),
  docudesk_envelope_id: string,
  docudesk_envelope_url: string (signing link),
  evidence_url: string (nullable, link to signed PDF in docudesk),
  eidas_audit_trail_id: string (nullable, docudesk audit trail reference),
  created_at: timestamp,
  updated_at: timestamp,
  expires_at: timestamp,
  declined_reason: string (nullable, reason from docudesk if declined)
}
```

**Seed Data Example:**

```json
{
  "id": "sig-001",
  "dossier_document_id": "doc-001",
  "signature_type": "advanced",
  "signatories": [
    {
      "id": "sig-jansen",
      "employee_id": "emp-jansen",
      "role": "employee",
      "sign_order": 1,
      "status": "signed",
      "signed_at": "2024-01-15T14:30:00Z",
      "evidence_hash": "def789..."
    },
    {
      "id": "sig-hr-001",
      "external_email": "hr@acme.nl",
      "role": "werkgever_vertegenwoordiger",
      "sign_order": 2,
      "status": "signed",
      "signed_at": "2024-01-16T10:00:00Z",
      "evidence_hash": "ghi012..."
    }
  ],
  "status": "completed",
  "docudesk_envelope_id": "docudesk-env-12345",
  "evidence_url": "https://docudesk.nl/signed/...",
  "eidas_audit_trail_id": "eidas-trail-001",
  "created_at": "2024-01-01T09:00:00Z"
}
```

### `destruction-certificate` (Proof of Vernietiging)

**Schema:**
```
{
  id: uuid,
  tenant_id: uuid,
  dossier_document_ids: uuid[] (array of document IDs destroyed in this batch),
  executed_at: timestamp,
  executed_by: uuid (system user, never a human),
  policy_codes: string[] (array of retention policy codes applied),
  destruction_method: enum (hard_delete, anonymise, archive_offsite),
  destruction_method_detail: string (integration name if archive_offsite),
  audit_chain_hash: string (SHA256 of audit-log entries for all destroyed documents),
  signed_pdf_path: string (path to the signed PDF archive; e.g. /certificates/2026-05-23-batch-001.pdf),
  signed_pdf_hash: string (SHA256 of the signed PDF for tamper-evidence),
  failure_log: jsonb (nullable; if any document failed to destroy, logged here),
  created_at: timestamp
}
```

**Seed Data Example:**

```json
{
  "id": "cert-001",
  "tenant_id": "acme-nv",
  "dossier_document_ids": ["doc-expired-001", "doc-expired-002", "doc-expired-003"],
  "executed_at": "2026-05-23T23:30:00Z",
  "executed_by": "system",
  "policy_codes": ["FISCAAL_7Y_POST_FY", "ARBEID_5Y_POST_DIENST"],
  "destruction_method": "hard_delete",
  "audit_chain_hash": "jkl345mno678...",
  "signed_pdf_path": "/certificates/2026-05-23-batch-001.pdf",
  "signed_pdf_hash": "pqr901stu234...",
  "created_at": "2026-05-24T00:00:00Z"
}
```

## Integration Touchpoints

- **employee-master:** Diensteinde event triggers `retention_end_at` recomputation for all post-dienst-anchored documents for that employee.
- **Nextcloud Files:** System-owned per-employee folder structure; ACL injection per `acl-grant` row; encryption-at-rest for special-category documents.
- **docudesk:** E-sign envelope dispatch and callback; evidence URL and eIDAS audit trail capture.
- **payroll-engine-nl, time-attendance, leave-absence, rostering-planning:** Document deposit API (service-account authenticated).
- **openregister:** All metadata queryable; retention engine uses the register as source of truth.
- **n8n:** Events (dossier.document.created, dossier.document.expiring_soon, dossier.document.destroyed, signature.completed) feed into customer automation workflows.

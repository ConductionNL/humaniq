---
status: proposed
author: Specter Intelligence
date: 2026-05-23
---

# Document Dossier & AVG Retention — Proposal

## Why

Every Dutch employer with one or more employees is legally obliged to maintain a *personeelsdossier* (personnel dossier). Today, most SMBs manage this through a tangle of Dropbox folders, Outlook attachments, vendor HR-suite vaults, or literal filing cabinets — creating compliance risk, poor audit trails, and significant operational burden. hrmq replaces this with a structured, register-backed dossier built on Nextcloud Files, featuring first-class AVG (GDPR) retention logic, role-based access control, e-signature workflow, and tamper-evident audit logging.

This capability directly addresses the Data Protection Officer's compliance obligations (AVG art. 5, 30, 32–34), the employer's retention duties (Archiefwet, AWR, arbeidsrecht), and the employee's rights of access (AVG art. 15).

## What Changes

- **New Detail Tab:** `Medewerkers › Documenten` — a tab within the existing personnel record showing all documents for that employee, classified by category, with access controls inherited from role + ACL grants.

- **Register-backed document catalogue:** Six new openregister schemas (`dossier-document`, `document-category`, `retention-policy`, `acl-grant`, `signature-request`, `destruction-certificate`) form the metadata layer on top of Nextcloud Files binaries.

- **First-class retention engine:** Every document computes its `retention_end_at` based on the category's default policy (FISCAAL_7Y_POST_FY, ARBEID_5Y_POST_DIENST, OVERHEID_50Y, etc.), with anchors on document effective date, employment end, or fiscal year. A nightly destruction job automatically hard-deletes, anonymises, or archives documents at retention end with auditable certificates.

- **Role-based document ACL:** Default permission matrix (employee: view+download own; manager: view; HR: view+download+edit; HR-admin: full control; OR-inzage: anonymised aggregate only) plus granular per-document grants for temporary or exception access (external auditor, specific manager).

- **eIDAS-compliant e-signature workflow:** Three assurance levels (simple, advanced, qualified) configured per category (e.g. CONTRACT_ARBEID = advanced, AKKOORD_HUISREGELS = simple). Docudesk integration sends signatories the eIDAS-compliant signing link; signed PDFs become a new version of the document, and the envelope status drives the document state machine.

- **Special-category (AVG art. 9) handling:** Documents marked `is_special_category=true` (medical, biometric, union membership) default to HR-only access, require explicit override reason + secondary approval for wider grants, flag in the AP-notifiable special-category-override log, and use encryption-at-rest on the Files store.

- **Audit log:** Every view, download, edit, delete, sign action is logged with actor, target, timestamp, IP, user-agent. Destruction itself is audited, and the audit entry outlives the destroyed document.

- **System-generated document deposit:** payroll-engine-nl, time-attendance, leave-absence, and rostering-planning deposit dossier documents via a service-account API, populating category, employee, effective dates, and source metadata. Deduplication prevents duplicate deposits.

- **Search with access-controls & facets:** Employees search only their own dossier; HR searches by employee/category/date/expiry with role-filtered visibility. Facets for category, date range, expiring-soon, missing-required.

## Capabilities

### New Capabilities

- `document-dossier` — Personnel document catalogue backed by openregister, with classification, version history, audit trails.
- `avg-retention-engine` — Compute and enforce bewaartermijnen per category with statutory anchors; automatic destruction.
- `document-acl` — Role-based + granular per-document permission grants with temporary expiry.
- `dossier-search` — Access-control-aware faceted search across all visible documents.
- `document-e-sign` — eIDAS-compliant signing workflow via docudesk for contracts and declarations.
- `special-category-handling` — Elevated security & approval for AVG art. 9 documents.
- `dossier-audit-log` — Tamper-evident audit trail for all document access and lifecycle events.
- `system-document-deposit` — API for payroll, time, leave, rostering systems to submit documents.

### Modified Capabilities

- `employee-master` — Integration point: employment-end event triggers re-computation of post-dienst retention dates.
- `Medewerkers` detail surface — New `Documenten` tab appears on the personnel record.

## Entities & Data Model

Six new openregister schemas in the `hrmq` register:

- **`dossier-document`** — Catalogue entry for each document: metadata (category, status, retention class), Nextcloud file reference, version history, audit fields, signature request link.

- **`document-category`** — Controlled vocabulary: code (CONTRACT_ARBEID, BEOORDELING, etc.), display names, default retention policy, signature requirement, special-category flag, legal basis.

- **`retention-policy`** — Bewaartermijn rule: code, duration (years/months), anchor point (document_effective_from, employment_end, fiscal_year_end), legal citation, destruction action.

- **`acl-grant`** — Per-document permission: principal type (employee_self, role, named_user, named_group), permission level (view, download, edit, share, sign), expiry, grant reason, legal basis.

- **`signature-request`** — E-sign envelope: type (simple, advanced, qualified), signatories list, status, docudesk envelope ID, eIDAS audit trail link.

- **`destruction-certificate`** — Proof of vernietiging: document IDs, executed timestamp/user, destruction method, signed PDF archive.

## Impact

- **Nextcloud Files** — New per-employee system-owned folder structure; ACL injection via Files API; encryption-at-rest for special-category documents.
- **openregister** — Six new schemas plus indexes for queries (employee_id, category_id, retention_end_at, principal_id).
- **docudesk** — Existing e-sign service integration; no new changes required.
- **Medewerkers menu** — `Documenten` tab added to detail view; no new top-level menu (placement: DETAIL_TAB per ADR-001).
- **employee-master** — Employment-end event triggers retention recomputation.
- **payroll-engine-nl, time-attendance, leave-absence, rostering-planning** — Optional: integration to deposit documents via service-account API.
- **n8n automation** — New events (dossier.document.created, dossier.document.expiring_soon, dossier.document.destroyed, signature.completed) available for customer workflows (SMS reminder on expiring VOG, mail to accountant on new payslip, sync to sector-specific archive).

## Stakeholders & Value

- **HR-administrateur / bedrijfsleider (SMB 5–250 medewerkers):** Structured dossier replaces scattered files; compliance automation reduces AVG & CAO-handhaving risk; search & filters save hours on "find all expiring VOGs."
- **Medewerker:** AVG art. 15 right to access own dossier is one click; no longer requires formal email request.
- **Accountant / loonadviseur:** Monthly download of salarisbrieven, jaaropgaves with audit trail and access logs.
- **OR / personeelsvertegenwoordiging (WOR art. 28):** Geanonimiseerde aggregaten, read-only audit reports.
- **Externe accountant / arbeidsinspectie:** Temporary, logged access during audit; expires automatically.

## Non-Targets

- Single-medewerker eenmanszaken (no dossier obligation).
- Large corporates under active Workday/SAP/Personio contracts (migration cost > business case; open data model eases future migration).

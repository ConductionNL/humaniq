---
status: draft
app: hrmq
spec: avg-dsr-rights-engine
target_users: [hr-admin, privacy-officer, dpo, employee, ex-employee]
estimated_effort: L
depends_on: [employee-management, hris-api-public, audit-logging]
---

# AVG / GDPR Data-Subject Rights Engine

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Aangiftes & compliance › AVG-rechten

**Rationale:** DSR-engine.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

An end-to-end workflow engine that operationalises the AVG / GDPR data-subject rights (Articles 15-22) for HRM-data: inzage (access), rectificatie (rectification), vergetelheid (erasure), beperking (restriction), and overdraagbaarheid (portability). Today most organisations handle DSR requests via email + manual export — slow, inconsistent, undocumented, and routinely missing the 30-day statutory deadline. This spec defines: a verwerkingenregister (Article 30 ROPA), a DSR request workflow with built-in 30-day timer + extension logic, evidence-collection that walks across all hrmq schemas (and integrated apps) to assemble the response, and an audit trail that satisfies AP (Autoriteit Persoonsgegevens) supervisory inspection.

This is essential for any organisation handling HRM-data at scale: the AVG enforcement track-record has hardened since 2023, and an HRIS without first-class DSR tooling is a liability.

## Data Model

**ProcessingActivity** (Article 30 register entry):
- `id`, `name` (e.g. "Salarisadministratie", "Verzuimregistratie")
- `purpose`: text (concrete purpose, not "HR")
- `legal_basis`: enum (`consent`, `contract`, `legal_obligation`, `vital_interest`, `public_task`, `legitimate_interest`)
- `data_categories`: array (e.g. `identifying`, `financial`, `health`, `biometric`)
- `data_subjects`: array (e.g. `employees`, `applicants`, `ex-employees`)
- `retention_period_months`: int
- `recipients`: array (internal teams + external processors)
- `cross_border_transfers`: array (with safeguards: SCC, adequacy decision, etc.)
- `security_measures`: text
- `dpia_required`: boolean, `dpia_completed_at`: date nullable

**Processor** (verwerker registry):
- `id`, `name`, `contact`, `data_categories_processed`, `country`, `verwerkersovereenkomst_signed_at`, `verwerkersovereenkomst_document_id`

**DsrRequest** (data-subject request):
- `id`, `requester_email`, `requester_user_id` (nullable — ex-employees may not have an account)
- `request_type`: enum (`access`, `rectification`, `erasure`, `restriction`, `portability`, `objection`)
- `submitted_at`, `verified_at` (identity verification), `deadline_at` (submitted + 30 days)
- `extended`: boolean, `extension_reason`: text, `extension_deadline_at` (max +60 days per AVG Art. 12.3)
- `status`: enum (`pending_verification`, `in_progress`, `awaiting_input`, `completed`, `rejected`, `partially_fulfilled`)
- `rejection_reason`: text nullable, `legal_basis_for_rejection`: enum

**DsrEvidence** (collected data per request):
- `request_id`, `source_app` (hrmq, shillinq, openconnector, etc.), `source_schema`, `record_ids`: array, `collected_at`, `collector_user_id`, `data_snapshot_json`

## Requirements

### REQ-001: Article 30 verwerkingenregister (ROPA)

**GIVEN** a tenant onboarding hrmq
**WHEN** they complete the privacy-setup wizard
**THEN** the system pre-fills ~15 standard ProcessingActivity records (salarisadministratie, verzuim, performance, recruitment, etc.) with legal-basis defaults per Dutch employment law; the privacy-officer reviews + customises + signs off; the resulting register is exportable as PDF for AP inspection

### REQ-002: Processor registry + verwerkersovereenkomsten

**GIVEN** a hrmq tenant integrating with external systems (payroll-provider, ATS, learning-platform)
**WHEN** each integration is enabled
**THEN** a Processor record is created, the privacy-officer is prompted to upload a signed verwerkersovereenkomst (or use a Conduction-provided template), and the integration cannot send data to the processor until the signed agreement is on file

### REQ-003: DSR request intake — Article 15 inzage

**GIVEN** a (current or former) employee
**WHEN** they submit an inzage-verzoek via the public form at `/privacy/request` (no login required for ex-employees) or via in-app menu (logged in)
**THEN** a DsrRequest record is created with `request_type=access`, identity-verification is triggered (DigiD for NL citizens / passport-copy upload + manual review for others), and the 30-day deadline timer starts at `verified_at`

### REQ-004: Evidence collection — cross-app data walk

**GIVEN** a verified DsrRequest
**WHEN** the privacy-officer triggers "Collect evidence"
**THEN** the system walks every schema in the hrmq register that has a `data_subject_field` annotation, queries records matching the requester's user_id / email, and stores results as DsrEvidence; cross-app: queries the hris-api-public webhooks for `dsr.collect` and integrated apps (shillinq, openconnector logs, opencatalogi audit-log) respond with their data within 5 days

### REQ-005: Article 16 rectificatie

**GIVEN** a verified DsrRequest of type `rectification`
**WHEN** the requester specifies the field + corrected value
**THEN** the privacy-officer reviews, can accept or reject (with documented reasoning), and on accept the field is updated, the old value is preserved in audit-log with the AVG-correction flag, all downstream systems are notified via webhook `employee.updated` with `correction_reason=avg-rectification`, and the requester receives confirmation

### REQ-006: Article 17 vergetelheid (right to erasure)

**GIVEN** a verified DsrRequest of type `erasure`
**WHEN** the privacy-officer evaluates the request
**THEN** the system computes which fields can be erased vs which must be retained under conflicting legal obligations (e.g. salarisadministratie 7-year retention, loonheffingen 5-year, verzuim 2-year post-recovery); erasable fields are deleted or anonymised; non-erasable fields are flagged with `retention_reason` + earliest erasure date; the response to the requester explicitly lists what was erased and what was retained with legal-basis citation

### REQ-007: Article 20 dataportabiliteit

**GIVEN** a verified DsrRequest of type `portability`
**WHEN** the privacy-officer triggers export
**THEN** the system generates a machine-readable export (JSON conforming to a published schema, plus CSV for human readability) containing only data the subject provided themselves on a `consent` or `contract` basis (excludes derived data, internal notes, performance-review comments from others); the export is delivered as a signed download link valid for 30 days

### REQ-008: Deadline tracking + extension

**GIVEN** an open DsrRequest with a 30-day deadline
**WHEN** the deadline approaches (T-7 days)
**THEN** the privacy-officer receives an alert; if the request is complex (multi-app evidence, large data volume) the officer can extend once by up to 60 days per AVG Art. 12.3 with a documented reason and notification to the requester; if the extended deadline passes without resolution, an escalation is triggered to the DPO + tenant admin, and the AP-inspection-ready audit log records the breach

## Standards & References

- **AVG / GDPR** — Articles 12 (transparent communication), 13-14 (information at collection), 15 (access), 16 (rectification), 17 (erasure), 18 (restriction), 20 (portability), 21 (objection), 22 (automated decisions), 30 (ROPA), 33-34 (breach notification)
- **UAVG** — Uitvoeringswet AVG (Dutch implementation, esp. health-data + employer derogations)
- **NEN 7510** — Dutch information security standard for health-data (relevant for verzuim)
- **ISO 27701** — privacy information management
- **AP Boetebeleidsregels** — supervisory authority enforcement guidance

## Cross-app Coordination

- **hris-api-public** — DSR evidence collection uses the webhook `dsr.collect` event; all integrated apps must respond with their data for the subject within 5 days
- **openregister** — schemas in any register declaring a `data_subject_field` annotation are walked automatically by the evidence collector
- **audit-logging** — every DSR action (intake, verification, evidence collection, decision, delivery) is recorded with immutable audit entries for AP inspection
- **opencatalogi** — public-form intake at `/privacy/request` is a public-facing surface, listed in the privacy-statement catalog
- **n8n** — workflow automations for the 30-day timer, T-7 escalation, processor-due-diligence renewals

## Target Users

Primary: Privacy-officers / DPOs (manage requests + ROPA), HR-admins (act on rectifications/erasures), employees + ex-employees (submit + receive responses). Secondary: AP-inspectors (read audit trail during inspection — read-only role), tenant-admins (oversight + breach escalation). Out of scope: GDPR Article 22 (automated decision-making) full implementation — flagged in register only, separate spec for the consent + human-review workflows; cross-controller breach notification orchestration (separate spec).

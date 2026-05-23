# Proposal: Audit Trail Payroll

## Executive Summary

`audit-trail-payroll` is a dedicated, append-only audit layer for payroll that immutably logs every payroll-relevant mutation with cryptographic integrity protection, separate from and complementary to the generic application audit trail. This is required for legal compliance (fiscal law, labor law, GDPR) and for reproduceability of historical payroll calculations.

**Demand score:** P0 — Legal/compliance requirement  
**Scope:** Core payroll subsystem  
**Target launch:** Q3 2026 (payroll-MVP)

---

## Problem Statement

### Current Pain Points

1. **Legal liability gap**: Fiscal authorities (Belastingdienst), social insurance (UWV), and labor courts require indisputable proof of "who changed what, when, why" in payroll records. The generic application audit trail does not provide append-only storage or cryptographic proof of non-tampering.

2. **Retention & lifecycle complexity**: Payroll has staggered retention requirements (fiscal: 7 years, pension: 10 years, labor law: up to life of employment). Manual cleanup policies risk accidental loss of legally-required records.

3. **Reproduceability at scale**: When a payroll run from 2024 is audited in 2029, the system must be able to re-execute the exact same calculation with the same rules (CAO version, tax tables, engine version) and prove the result. Current event-log granularity ("field X changed from A to B") is insufficient.

4. **GDPR/data governance**: Payroll audit data has a different legal basis (art. 6(1)(c) — legal obligation) than general application logs. Mixing them in one audit trail violates data minimization and creates confusion in AVG breach response.

5. **Audit-proof export gaps**: When a boekenonderzoek (fiscal inspection) or arbeidsgeschil (labor dispute) arrives, HR cannot produce a chain-of-custody export that proves integrity to external parties.

---

## Proposed Solution

Create an immutable payroll audit subsystem with:

1. **Append-only storage**: `PayrollAuditEvent` table with database-level, ORM-level, and service-level enforcement that events can never be updated or deleted (only new corrective events added).

2. **Hash-chain integrity**: Each event includes the hash of the previous event; any tampering breaks the chain and is immediately detected.

3. **Cryptographic anchoring**: Weekly Merkle-root anchors (optionally signed by a Time Stamping Authority) provide external proof that the entire chain has not been restored.

4. **Payroll-semantic events**: Not "field X changed from A to B," but "loonrun 2026-06 calculated with CAO v3, engine v1.4.2, 30%-status ACTIVE, beslagvrije-voet €200" — enabling audit-exact reproduction.

5. **Configurable retention**: 10-year default per legal minimum, with per-administratie and per-entity-type overrides, and legal-hold support for active disputes.

6. **Proof-package export**: One-click ZIP with all events in a period, hash-chain, anchors, verification script, and Dutch-language audit trail for Belastingdienst/UWV/accountants.

7. **AVG-isolated**: Dedicated `payroll_audit_lezer` role, separate from general admin rights; all reads are themselves logged in `PayrollAuditAccessLog` with mandatory justification.

8. **SDK for integration**: Reusable audit-logging library so `loonbeslag-admin`, `pensioen-aangifte`, and other hrmq capabilities don't each build their own audit layer.

---

## Features & Acceptance Criteria

| Feature ID | Feature Name | Demand | Description | Acceptance Criteria |
|---|---|---|---|---|
| F-001 | Append-only storage enforcement | P0 | Prevent UPDATE/DELETE on audit events at database, ORM, and service layers | Events cannot be modified; INSERT-only; violation raises `ImmutabilityViolationException` |
| F-002 | Hash-chain per administratie | P0 | Each event references the previous event's hash; chain-break detection on verification run | Weekly verificiation job detects and alerts on any corrupted event |
| F-003 | Weekly Merkle-root anchors | P0 | Cumulatieve root hash over all events since last anchor, optionally signed by TSA | Anchor created every Sunday 02:00 UTC; supports rfc3161_tsa mode |
| F-004 | Mandatory motivation for high-impact events | P1 | Certain event types (30%-regeling intrekking, beslagvrije-voet override, IBAN wijziging) require non-empty, >= 20-char `motivering` field | Validation rejects undersized motivation; error message in Dutch |
| F-005 | Reproducible payroll runs | P0 | Capture `engine_versie` and `wet_versie` on every Loonrun event | Accountant in future year can re-execute with identical version + rules and verify result |
| F-006 | Proof-package export | P1 | One-click ZIP export with filtered events, chain-hashes, anchors, verifier script for Belastingdienst/UWV/accountants | Export includes 5+ formats; all events have hash-chain; verification script validates on macOS/Linux/Windows |
| F-007 | Retention lifecycle management | P0 | Events retained minimum 10 years; cleanup by pseudonimisering or deletion with tombstone events; legal-hold support | Cleanup job runs monthly; legal-holds prevent deletion; tombstone events preserve chain integrity |
| F-008 | AVG-compliant access isolation | P0 | Dedicated `payroll_audit_lezer` role; all reads logged in `PayrollAuditAccessLog` with mandatory justification | `403 Forbidden` if user lacks role; every query audit-logged; justification required |
| F-009 | Performance at scale | P1 | Queries on 5M+ events complete in < 2s P95 for typical filters (employee, date range) | Index strategy on (administratie_id, entiteit_id, tijdstip_utc); write-throughput >= 1000 events/s |
| F-010 | Audit SDK for other capabilities | P1 | Reusable library for loonbeslag-admin, pensioen-aangifte, etc. to log events without building own audit | SDK auto-fills hashes, validates required fields, atomically inserts into chain |

---

## User Stories

### Story 1: Append-Only Guarantee
**As a** Compliance Officer  
**I want** absolute proof that no payroll event can be backdated, modified, or deleted  
**So that** I can sign off on Belastingdienst inquiries with confidence in data integrity

**Acceptance Criteria:**
- GIVEN an audit event is stored, WHEN a developer attempts `repository.update(event)` or `repository.delete(event)`, THEN an `ImmutabilityViolationException` is raised
- GIVEN an admin tries `UPDATE payroll_audit_events SET ...` via direct SQL, THEN a database trigger rejects it with an error
- GIVEN a corrective action is needed, WHEN a new "correction" event is added, THEN the original event remains in the log

### Story 2: Hash Chain Validation
**As a** DPO (Data Protection Officer)  
**I want** automated weekly verification that the event chain has not been tampered with  
**So that** I can demonstrate to the Autoriteit Persoonsgegevens that audit integrity is maintained

**Acceptance Criteria:**
- GIVEN 10 events in administratie A, WHEN the verification job runs, THEN it recalculates each hash and reports "chain valid" or "corrupted at event {id}"
- GIVEN an event is tampered with (despite REQ-001), WHEN verification runs, THEN a high-priority security alert is sent to admins
- GIVEN 0 events since last anchor, WHEN anchor job runs Sunday 02:00 UTC, THEN an anchor is still created (identical to previous root) to maintain chain continuity

### Story 3: Fiscal Proof Export
**As a** Payroll Admin  
**I want** to export a proof-package in response to a Belastingdienst boekenonderzoek  
**So that** the tax authority can independently verify payroll integrity

**Acceptance Criteria:**
- GIVEN the authority announces an inspection for 2025-01-01..2025-12-31, WHEN I request export with that date filter, THEN a ZIP is generated with (a) all events as JSON + hashes, (b) PDF summaries per period, (c) anchors + verification script, (d) Dutch-language audit guide
- GIVEN the export is created, WHEN it is downloaded, THEN a `PayrollAuditAccessLog` entry is recorded with mandatory justification ("Belastingdienst inspection 2026-Q2")
- GIVEN the ZIP is opened by the authority, WHEN the verification script runs on Linux/macOS/Windows, THEN it validates hash-chain and reports "OK" or lists corrupted events

### Story 4: Engine Version Pinning
**As a** Accountant doing external audit  
**I want** to re-run a payroll calculation from 2024 exactly as it was executed then  
**So that** I can verify the calculation was correct under the rules that applied at that time

**Acceptance Criteria:**
- GIVEN a loonrun event from 2024-06 with `engine_versie = 1.3.1` and `wet_versie = 2024-loonheffing-tabel-rev2`, WHEN I request reproduction in 2029, THEN the system retrieves and executes version 1.3.1 with the correct tax tables
- GIVEN the reproduced run finishes, WHEN I compare the output to the original event, THEN the salary amounts match to the cent (no rounding drift)

### Story 5: High-Impact Event Justification
**As a** Payroll Admin approving a 30%-regeling intrekking  
**I want** to record why this high-impact decision was made  
**So that** future audits can understand the business context

**Acceptance Criteria:**
- GIVEN I initiate a 30%-regeling intrekking without providing a motivering, WHEN I save, THEN the API returns `validation_error: motivering verplicht voor event_type beschikking30.handmatig_ingetrokken`
- GIVEN I provide a 15-character motivering ("X" repeated 15 times), WHEN I save, THEN the API returns `validation_error: motivering te kort — minstens 20 tekens vereist`
- GIVEN I provide a 50-character justification ("In accordance with employee resignation notice dated..."), WHEN I save, THEN the event is stored with the motivering included

### Story 6: Legal Hold During Dispute
**As a** HR Manager in an arbeidsgeschil  
**I want** payroll audit events to be protected from deletion during active litigation  
**So that** they remain available as evidence if needed

**Acceptance Criteria:**
- GIVEN a legal-hold is placed on administratie A due to a pending dispute, WHEN the monthly cleanup job runs, THEN no events from A are deleted
- GIVEN the dispute is resolved and the hold is lifted, WHEN the next cleanup job runs, THEN events older than retention period are eligible for deletion/pseudonimisering again

### Story 7: Audit of Auditors
**As a** DPO reviewing data access  
**I want** to see who accessed the payroll audit log, when, and why  
**So that** I can demonstrate who had access to sensitive payroll data

**Acceptance Criteria:**
- GIVEN payroll_audit_lezer queries 250 events for "Medewerker X between 2025-01-01 and 2026-06-30", WHEN the query completes, THEN one `PayrollAuditAccessLog` entry records: user_id, timestamp, query_filter, count=250, justification="Voorbereiding jaarrekeningcontrole"
- GIVEN an admin without `payroll_audit_lezer` role attempts `GET /api/payroll-audit/events`, WHEN the request is made, THEN the API returns `403 Forbidden` and a security event is logged

---

## Stakeholder Profiles

| Stakeholder | Role & Responsibility | Goals | Pain Points |
|---|---|---|---|
| **Functionaris Gegevensbescherming (DPO)** | Ensures AVG accountability for payroll data | Prove immutable logging & minimize data access footprint | Current generic audit mixes purposes & lacks tamper-proof storage |
| **Compliance Officer** | NEN 7510 / ISO 27001 compliance oversight | Maintain audit integrity evidence; pass external audits | Unclear chain of custody when Belastingdienst calls |
| **Accountant / EDP-auditor** | Jaarrekeningscontrole & specific audit procedures | Obtain signed, verifiable proof of payroll integrity | Manual reconstruction of payroll history is error-prone |
| **Belastingdienst Controleur** | Tax authority inspector conducting boekenonderzoek | Independently verify payroll data integrity | Lack of automated proof-export forces manual file assembly |
| **UWV Inspecteur** | Social insurance inspector (ziekmelding, WVP, WIA) | Verify coordination between payroll records & claims | Difficult to cross-reference events across systems |
| **Payroll Admin** | Daily payroll operations & corrections | Quick access to audit log when reproducing errors; compliance with retention rules | Accidental deletion or tampering risk; manual cleanup burden |
| **HR Manager / DGA** | Handles labor disputes & compliance | Access full financial history for employee disputes | Limited audit trail granularity for payroll-specific decisions |

---

## Placement in Information Architecture

**Placement type:** `SUB_PAGE` (beneath top-level menu entry)  
**Lives at:** Salarissen › Audit-trail  
**Rationale:** Immutable payroll audit log; logically grouped under payroll operations

---

## Success Metrics

1. **Zero tamper detections**: Verification job runs weekly; zero successful hash-chain breaks in 12 months = system integrity validated
2. **Export SLA**: Proof-package exports complete within 5 minutes for typical administraties (10K employees)
3. **Adoption**: 100% of payroll-relevant changes logged through audit SDK or direct insert by Q4 2026
4. **Authority acceptance**: Zero rejection of exported audit packages by Belastingdienst / UWV / accountants due to format/integrity issues
5. **Retention compliance**: 100% of events older than retention period are processed (deleted or pseudonimised) within 30 days of becoming eligible

---

## Risks & Mitigations

| Risk | Impact | Probability | Mitigation |
|---|---|---|---|
| Hash-chain calculation becomes performance bottleneck at scale | P95 query time exceeds 2s SLA | Medium | Pre-calculate hashes asynchronously; index on (administratie_id, tijdstip_utc) |
| Regulatory interpretation changes (e.g., new GDPR ruling on retention) | Retention policy becomes non-compliant | Low | Annual legal review; retention_policy table supports rapid override |
| Time Stamping Authority (TSA) becomes unavailable during anchor window | Weekly anchor fails; chain proof incomplete | Low | Graceful fallback to internal signature; retry on TSA recovery |
| Accidental deletion of events (despite immutability constraints) | Lost audit trail; compliance violation | Very Low | Database-level BEFORE DELETE trigger + regular backup verification |

---

## Dependencies

- **employee-master** — supplies `actor_id`, `actor_label` for user context
- **payroll-engine-nl** — emits Loonrun/Loonpost events with `engine_versie`, `wet_versie`
- **30-procent-regeling** — emits Beschikking30 events
- **loonbeslag-admin** — emits Beslag events
- **pensioen-aangifte** — emits Pensioenaanspraak events
- **loonaangifte-digipoort** — emits LoonaangifteIngediend events
- **document-vault** — stores exported audit packages
- **notification-engine** — sends chain-integrity alerts
- **observability-stack** — ingests metrics (event-volume, write-latency, chain-verify time)

---

## Out of Scope

- Historical backfill of events from the generic application audit log into `PayrollAuditEvent` (one-time migration if needed, handled separately)
- Public-sector specific audit rules (WNT publication timestamps, ABP-coordination logging) — surface in later capability ADR
- Integration with external compliance platforms (Workiva, Domo) — future data-export feature

---
kind: config
depends_on: []
title: AVG / GDPR Data-Subject Rights Engine
status: proposed
app: hrmq
target_users: [hr-admin, privacy-officer, dpo, employee, ex-employee]
estimated_effort: L
depends_on_features: [employee-management, hris-api-public, audit-logging]
---

# Proposal: AVG / GDPR Data-Subject Rights Engine

## Summary

An end-to-end workflow engine that operationalises the AVG / GDPR data-subject rights (Articles 15-22) for HRM-data: inzage (access), rectificatie (rectification), vergetelheid (erasure), beperking (restriction), and overdraagbaarheid (portability). Today most organisations handle DSR requests via email + manual export — slow, inconsistent, undocumented, and routinely missing the 30-day statutory deadline.

This spec defines:
- A verwerkingenregister (Article 30 ROPA) for cataloguing all HRM data processing activities
- A DSR request workflow with built-in 30-day timer + extension logic
- Evidence-collection that walks across all hrmq schemas (and integrated apps) to assemble responses
- An audit trail that satisfies AP (Autoriteit Persoonsgegevens) supervisory inspection

## Problem Statement

AVG enforcement track-record has hardened since 2023. An HRIS without first-class DSR tooling is a legal liability for any organisation handling HRM-data at scale. Current manual workflows (email + export) lack:
- Statutory deadline tracking and enforcement
- Consistent evidence collection across disparate data sources
- Documented decision rationale for partial fulfilment or rejection
- Audit trails for supervisory authority inspection
- Processor (verwerker) registry and agreement management

## Target Users & Pain Points

**Privacy Officer / DPO:**
- Needs to manage 30+ requests/year within 30-day statutory deadlines
- Must document ROPA (Article 30 register) and evidence collection process
- Requires audit trail for AP inspection
- Must track processor agreements (verwerkersovereenkomsten)

**HR Admin:**
- Acts on rectification requests (field updates with audit trail)
- Evaluates erasure requests (balancing GDPR Art. 17 with retention obligations)
- Coordinates evidence collection across payroll, leave, performance systems

**Employee / Ex-employee:**
- Submits access, rectification, erasure requests
- Requires transparent response timeline and reasoning for partial/rejected requests
- May not have active account (ex-employees)

**AP Inspector (read-only):**
- Reviews audit trail during supervisory inspection
- Verifies ROPA completeness and DPIA status

## Features (Demand Scores)

| Feature | Demand | Description |
|---------|--------|-------------|
| ProcessingActivity Registry (ROPA) | P0 | Article 30 register with pre-fill defaults (15 standard activities per Dutch employment law), privacy-officer review/customisation, exportable PDF |
| Processor Registry | P0 | Verwerker registry with verwerkersovereenkomst upload/template, integration-triggered creation |
| DSR Request Intake (Article 15) | P0 | Public form + logged-in submission, identity verification (DigiD / manual), 30-day deadline timer |
| Evidence Collection (cross-app) | P0 | Walk all hrmq schemas with `data_subject_field` annotation, query hris-api-public webhooks, integrated-app data responses |
| Article 16 Rectification | P0 | Requester-specified corrections, privacy-officer review/acceptance, audit-flag updates, downstream webhook notification |
| Article 17 Erasure | P0 | Compute erasable vs retained fields (balancing legal obligations), anonymisation, retention-reason flags with earliest-erasure dates |
| Article 20 Portability | P1 | Machine-readable export (JSON + CSV), consent/contract-basis filtering, signed download link (30-day validity) |
| Deadline Tracking + Extension | P0 | 30-day alert (T-7), extension up to 60 days with documented reason, DPO/admin escalation on breach, AP-inspection-ready audit |
| Processor Due Diligence | P1 | n8n workflow for renewal reminders, signed-agreement expiry tracking |

## Placement & Information Architecture

**Type:** `SUB_PAGE` — Sub-page beneath "Aangiftes & compliance" menu

**Lives at:** Aangiftes & compliance › AVG-rechten

**IA Rule Reference:** [ADR-001 Rule 5](../architecture/adr-001-information-architecture.md) — compliance output (UPA, pensioen, WNT, AVG-DSR, audit) lives under one `Aangiftes & compliance` top-level menu.

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

## Success Criteria

1. DSR requests have documented audit trail from submission through delivery
2. Deadline tracking prevents statutory breaches; extension logic is enforced
3. Privacy officer can export ROPA as PDF for AP inspection
4. Evidence collection walks all schemas; integrated apps respond within 5 days
5. Erasure/rectification preserve audit trail and notify downstream systems
6. All DSR actions are non-repudiable for AP supervisory inspection

## Out of Scope

- GDPR Article 22 (automated decision-making) full implementation — flagged in register only, separate spec for consent + human-review workflows
- Cross-controller breach notification orchestration — separate spec
- Subject-access request consent flows for historical data — scoped to ROPA + evidence collection

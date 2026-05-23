---
schema: spec-driven
change: performance-management-advanced
version: 1.0
date: 2026-05-23
status: proposed
---

# Performance Management Advanced (OKR + 9-box + Kalibratie)

## Executive Summary

Organizations are moving beyond traditional annual performance reviews toward continuous, data-driven talent management. This spec extends hrmq's base `performance-review-cycle` with three advanced practice components:

1. **OKR (Objectives & Key Results)** — Quarterly goal cascading with progress tracking and confidence scoring
2. **9-box Talent Grid** — Two-dimensional assessment of performance vs. potential for talent segmentation and succession planning
3. **Kalibratie (Calibration Sessions)** — Facilitated, documented peer review sessions to reduce manager bias and ensure fair talent distribution

The spec is deliberately modular: organizations can enable individual components independently (only OKR, or only 9-box). It emphasizes governance, privacy, and evidence-based decision-making over mechanical scoring formulas.

## Business Drivers

- **Modern talent practice**: Companies like Google, Adobe, GE, and Deloitte have shifted from once-yearly reviews to continuous feedback + quarterly goal cycles
- **Fair distribution**: Without calibration sessions, manager bias can result in 20% of a team rated as "top talent" — calibration brings data-driven consensus
- **Evidence for reward decisions**: Dutch compliance and fairness norms require documented reasoning for bonuses, promotions, and salary adjustments — not formula-driven allocations
- **GDPR compliance**: Potential-rating is employee monitoring (Art. 35 AVG DPIA required); retention limits apply to ex-employee assessments
- **Succession planning**: 9-box identifies high-potential talent for development and succession pools before departure risk materializes

## Features

### OKR Management
- **OKR Cascade**: Link team and individual OKRs to company strategic objectives; visualize hierarchy and flag orphan goals
- **Quarterly Progress Tracking**: Biweekly check-ins with confidence scoring (1-10) and key-result updates
- **OKR Scoring**: Automated calculation of completion rates (0.0-1.0) and classification (successful 0.7-1.0, learnings 0.4-0.7, overambitious <0.4)
- **Separation from compensation**: OKR scores feed evidence bundles for reward decisions, not automatic formulas

### 9-Box Talent Assessment
- **Dual-axis assessment**: Manager rates performance (last 12 months) and potential (next 2-3 years) on 3-point scales
- **Mandatory justification**: Minimum 200-character explanation per axis to prevent checkbox-based evaluation
- **Privacy controls**: 9-box data visible to manager, HR-BP, and talent-board only — never to the employee being assessed
- **Talent segmentation**: 9 cells auto-labeled (Star, Core Player, Underperformer, etc.) for succession and development planning

### Calibration Sessions
- **Peer-facilitated review**: HR-BP facilitates team/business-unit calibration with pre-populated 9-box matrix
- **Live distribution monitoring**: Heatmap showing current talent distribution against configurable target ranges (default: 10-20% stars, 40-60% core players, <10% risks)
- **Audited decisions**: Each rating change logged with underbouwing (rationale) and decision type (consensus vs. facilitator override)
- **Non-coercive**: Distribution targets are advisory — forced distribution is optional and off by default

### Continuous Feedback
- **Lightweight feedback types**: Kudos, constructive feedback, peer-input requests (vs. formal appraisals)
- **Flexible visibility**: Feedback can be employee-private, visible to manager, or team-public
- **Evidence aggregation**: Feedback bundles compiled as part of review-cycle evidence (separate from scores)
- **Cross-linked to OKRs**: Optional tagging of feedback to specific OKRs for competency/goal correlation

### Reward Integration
- **Reward recommendations**: End-of-cycle `RewardLink` records summarize OKR performance, 9-box position, calibration outcomes, and continuous feedback
- **Manager proposals**: Managers recommend bonus allocation (€/%), promotion (new role + date), or salary increase
- **HR-BP review**: HR-BP and Reward Committee validate proposals based on documented evidence
- **No mechanical formulas**: Explicit rejection of "star = 20% bonus multiplier" type rules in favor of case-by-case reasoning

## Target Users

- **Werknemer (Employee)**: Manages own OKRs, requests and gives feedback, views own performance outcomes (no 9-box visibility)
- **Manager**: Formulates team OKRs, assesses 9-box (direct reports), participates in calibration, proposes reward actions
- **HR-BP (HR Business Partner)**: Facilitates calibration sessions, monitors talent distribution, validates reward proposals, exports compliance data
- **Talent-board (ExCo/CHRO)**: Views 9-box summaries at senior level, identifies succession and risk pools
- **Reward Manager**: Coordinates annual/semi-annual bonus and salary cycles using `RewardLink` evidence
- **ExCo**: Defines company-level OKRs as cascade source for strategy alignment

## Information Architecture

**Placement type**: `DETAIL_TAB` on `Medewerkers › Functie & comp` (per ADR-001, rule 6)

This is NOT a standalone module or top-level menu — it lives as an additional tab on the existing personnel detail view, alongside contract, compensation, and other employee dossier sections.

## Compliance & Standards

- **OKR methodology**: Andy Grove (Intel) / John Doerr framework — ambitiousness, measurability, transparency, separation from pay
- **9-box grid**: McKinsey/GE talent matrix — industry-standard two-axis model
- **Calibration practice**: Deloitte/McKinsey best practice — documented, facilitated, consensus-driven
- **AVG/GDPR Art. 35**: DPIA required for potential-rating (employee monitoring); retention limits for ex-employee assessments (24-month max)
- **Dutch labor practice**: No mechanical bonus formulas; case-by-case reasoning required for material compensation changes

## Dependencies

- **employee-master**: Subject and assessor references; organogram for calibration scope
- **performance-review-cycle**: Base spec; this extends its cycle orchestration
- **compensation-management**: `RewardLink` feeds into bonus and salary-planning cycles
- **task-management**: OKR progress reminders, calibration invites, feedback requests
- **document-storage**: Kalibratie-log archival and underbouwing attachments
- **audit-log**: All 9-box data reads logged (GDPR compliance)

## Success Criteria

- OKRs cascade and can be linked from company to individual level
- 9-box data is never visible to the employee being assessed
- Calibration sessions are fully auditable (all changes logged with rationale)
- Reward proposals are evidence-backed and not formula-driven
- 24-month retention limit for ex-employee assessments is automated
- Managers can run biweekly check-ins without heavy administrative burden

## Out of Scope

- Individual performance conversations or 1-on-1 tooling (separate coaching/1-on-1 spec if needed)
- Compensation forecasting or complex workforce planning algorithms
- Integration with external talent marketplaces or succession-planning SaaS
- Learning & development recommendations (separate L&D spec)

## Timeline

- **Design & spec review**: 2 weeks
- **Backend implementation**: 4 weeks (data model, APIs, calibration logic)
- **Frontend implementation**: 3 weeks (OKR forms, 9-box matrix, calibration UI)
- **Testing & compliance audit**: 2 weeks
- **Pilot with 2-3 customer tenants**: 4 weeks
- **GA release**: Q3 2026

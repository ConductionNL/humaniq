---
status: approved
version: 1.0
date: 2026-05-22
stakeholders:
  - Specter Intelligence (requester)
  - HRMQ Product Team
  - MKB Finance/HR Community
---

# Proposal: Asset Management voor HRMQ

## Executive Summary

HRMQ introduces a unified Asset Management module that consolidates fragmented asset tracking (assets scattered across HR system, Excel sheets, and external tools like TOPdesk or Snipe-IT) into a single source of truth, with automatic fiscal propagation to payroll and seamless compliance with Dutch tax regulations (Wet inkomstenbelasting 2001, Werkkostenregeling).

**Problem**: Dutch MKB businesses today maintain assets across multiple disconnected systems—HR database for employees, separate Excel/tool for assets (laptops, phones, lease cars), and payroll for tax calculations. This fragmentation causes:
- Manual reconciliation and data duplication on every asset lifecycle event
- Fiscal errors, especially with lease car bijtelling recalculation (changes annually since 2017)
- Lost offboarding visibility into asset return status
- No automatic payroll propagation of asset-related income adjustments

**Solution**: Place asset-management within HRMQ, tightly coupled to employee records, with:
- One-to-one asset assignment model (AssetAssignment) and automatic status tracking
- Automatic bijtelling calculation for lease cars using fiscal staffel (DET-based, 60-month term tracking)
- Event-driven payroll coupling: every asset lifecycle change (assignment, return, tax-rule update) signals payroll-engine-nl
- Complete audit trail via AssetHistoryEntry; supports offboarding workflows and GDPR retention rules
- Bulk import and barcode-scan for MKB convenience
- Role-based access (asset-manager, hr-admin read/write; employees see own assets only; employees get limited view without cost/supplier details)

## Business Case

### Demand & Impact

| Feature | Demand Score | Justification |
|---------|--------------|---------------|
| Core asset registration & lifecycle | 9/10 | Solves primary fragmentation pain; required for legal/fiscal compliance |
| Lease car bijtelling automation | 9/10 | Annual regulatory change, high error rate in MKB, payroll-critical |
| Offboarding integration | 8/10 | Legally required asset return; currently manual and error-prone |
| Payroll event propagation | 8/10 | Ensures fiscal correctness in salary processing |
| Employee self-service view | 7/10 | Reduces HR admin burden; supports employee transparency requests |
| Bulk import & barcode scan | 6/10 | MKB convenience feature; reduces onboarding friction |
| GDPR data handling & retention | 7/10 | Compliance obligation; asset data contains sensitive info (kenteken) |

### Success Metrics

1. **Adoption**: ≥70% of HRMQ instances with ≥3 employees deploy asset-management within 12 months
2. **Accuracy**: Bijtelling calculation error rate <1% (vs. current manual error rate ~15%)
3. **Time-to-close**: Asset lifecycle event (assign/return) takes <2 minutes (vs. manual ~15 min + separate tool entry)
4. **Compliance**: 100% offboarding workflows include asset checklist; no asset-related fiscal corrections post-filing
5. **Support volume**: <5% support tickets related to asset-data inconsistencies (vs. baseline ~12% today)

## Stakeholder Goals

| Role | Goal | Acceptance |
|------|------|-----------|
| HR Admin / Asset Coordinator | Single interface for all asset lifecycle; automatic bijtelling without spreadsheet | Must support CSV bulk import; must notify on bijtelling changes |
| Employee | Quick visibility of own assets; simple damage-report flow | Must show in self-service portal; must accept photo upload for damage claims |
| Manager | Approve asset requests; see team asset allocation for budget planning | Must integrate with manager-approval workflow (future) |
| Accountant / Auditor | Complete asset history, booked values per period, fiscal propagation trail | Must export audit-ready report with bijtelling staffel applied per date |
| Leasemaatschappij (external) | Auto-receipt of kilometer data, contract updates, billing reconciliation | Via openconnector API integration (separate work) |

## Scope & Timeline

### Scope In
- Asset registration (CRUD) with type-driven field customization
- AssetAssignment lifecycle (assign, return, damage tracking)
- Lease car bijtelling calculation with fiscal staffel lookup and 60-month term boundary
- Automatic staffel override on DET+60mo milestone
- Payroll event emission (RabbitMQ) on lifecycle & tax-rule changes
- Asset detail pages with history timeline
- Employee tab on werknemer-detail (my-assets view + HR-admin full view)
- Offboarding wizard integration (asset-return checklist)
- Role-based access control (asset-manager, hr-admin, employee, auditor)
- GDPR anonymization batch (2-year post-employment)
- Bulk CSV import with validation preview

### Scope Out (Future)
- Barcode/QR generation & scan integration (Phase 2)
- Manager approval workflow for asset requests (links to future request-management feature)
- openconnector integrations (separate openconnector feature)
- Expense reimbursement linkage (separate expense-reimbursement feature)
- Depreciation schedule reports (Phase 2)
- Asset maintenance scheduling & repair workflow (Phase 2)
- Fixed-asset accounting integration (Phase 2)

### Timeline

| Phase | Milestone | Target Date | Artifacts |
|-------|-----------|-------------|-----------|
| Phase 1a | Design & Data Model finalized | 2026-06-15 | design.md, ADR-000 |
| Phase 1b | Backend API & DB schema | 2026-07-31 | Implemented & tested |
| Phase 1c | Frontend UI & employee self-service | 2026-08-31 | Implemented & tested |
| Phase 1d | Integration tests (payroll, employee-master, offboarding) | 2026-09-15 | Test suite |
| Phase 1e | Alpha release & pilot (5 customer instances) | 2026-09-30 | Pilot feedback loop |
| Phase 2 | GA release | 2026-10-15 | Full feature parity |

## Risks & Mitigations

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|-----------|
| Fiscal staffel data errors (incorrect bijtelling %) | High | Critical | Staffel lookup table maintained by HRMQ tax specialist; quarterly audit vs. Belastingdienst; test suite per rule |
| Payroll coupling failures (events not propagated) | Medium | High | Event bus contract tests; retry logic with manual override UI; audit logging |
| Performance on large asset counts (1000+/company) | Low | Medium | Index asset_id, employee_id, status; lazy-load history; pagination UI |
| GDPR deletion conflicts (audit trail vs. retention) | Medium | High | Pseudonymization strategy, not deletion; retention rules per entity type; legal review |
| Lease-auto edge cases (hybrid fuel, zero CO2, old vehicles) | Medium | Medium | Explicit handling per fuel type; fallback rules for edge cases; admin manual override |

## Success Criteria

✅ REQ-001 through REQ-010 implemented and tested per acceptance criteria  
✅ Bijtelling calculation matches Belastingdienst staffel for 2026 (audited)  
✅ Payroll event propagation verified end-to-end with payroll-engine-nl  
✅ Offboarding checklist blocks eind-afrekening until all assets returned or marked vermist  
✅ Employee self-service shows correct own-assets view with privacy constraints  
✅ Bulk import succeeds with 500+ assets; validation errors clearly reported  
✅ GDPR batch anonymizes old records without breaking history trail  
✅ 5-customer pilot reports ≥8/10 satisfaction on primary use cases  

## Go / No-Go Decision Gates

1. **Gate 1** (2026-06-30): Data model & schema review passed; fiscal staffel table validated
2. **Gate 2** (2026-08-31): UI & API integration tests at ≥90% coverage
3. **Gate 3** (2026-09-30): Pilot feedback incorporated; no critical/open bijtelling bugs
4. **Gate 4** (2026-10-15): Legal/compliance sign-off; GDPR audit complete; GA release approved

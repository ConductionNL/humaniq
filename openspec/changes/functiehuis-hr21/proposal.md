# Functiehuis HR21: OpenSpec Proposal

**Change ID:** functiehuis-hr21  
**Title:** Functiehuis HR21 (functiewaardering voor gemeenten)  
**Status:** proposal  
**Date:** 2026-05-23  
**Placement:** SUB_PAGE beneath Medewerkers › Functiehuis  

## Executive Summary

Implement the complete HR21 job function library within hrmq as the canonical function library for the municipal sector. The HR21 system is the VNG-adopted sectoral job evaluation system covering ~150 standard functions (Normfuncties), forming the basis for salary scale determination under the CAO Gemeenten. Without correct HR21 classification, no legally valid salary determination is possible.

This change delivers:
- **Normfunctie library** (~150 VNG-standardized municipal functions with scales, competencies, core tasks)
- **Maatwerkfunctie creation** (custom functions when no standard function fits, with explicit business case)
- **Functietoekenning workflow** (assignment of function to employee with HR advisor → manager → employee approval chain)
- **Hercategorisatie support** (promotion/reclassification with automatic salary consequence calculation per horizontaal-aansluitende-bedrag rule)
- **Auditeerbare wijzigingen** (every change tracked with full decision trail, audit-safe for 7+ year retention)
- **Inzage en bezwaarrecht** (employee access to classification decision + formalized 6-week objection procedure per Awb)

## Problem Statement

Currently, hrmq has no integrated HR21 function library. Municipalities managing job classifications must:
1. Manually maintain external HR21 function lists, creating synchronization gaps
2. Run separate workflows for classification proposals (paper-based or off-system)
3. Lack auditeerbare proof of classification decisions when disputes arise in labor proceedings or pension disputes
4. Have no systematic way to detect over-reliance on custom (maatwerkfunctie) classifications
5. Cannot calculate salary consequences automatically when reclassifying

**Consequences:**
- Salary disputes (employee claims incorrect scale applied)
- Compliance violations (OR instemmingsrecht not tracked)
- Missing historical audit trail for long-term employee records
- Manual SLA management for manager approvals (delays in classification)

## Market Demand

Scored as **critical** for the Dutch municipal sector:
- 100% of customer municipalities must comply with HR21 for CAO Gemeenten adherence
- 350+ customer municipalities (VNG-affiliated)
- Top 5 feature request across FY2026 roadmap (tied with contract-management)

## Features & Demand Scores

| Feature | Demand | Rationale |
|---------|--------|-----------|
| Normfunctie library (import & search) | **critical** | Baseline: every municipality needs access to ~150 standard HR21 functions |
| Functietoekenning workflow (HR proposal → manager approval) | **critical** | Core workflow; required for any new hire or reclassification |
| Hercategorisatie + salary consequence calculation | **critical** | ~15% of FY2026 reclassifications; calculation errors lead to payroll audits |
| Maatwerkfunctie creation with business case | **high** | ~8-12% of municipalities exceed 10% maatwerkfunctie threshold; need audit trail |
| Employee inzage + bezwaarrecht (Awb) | **high** | Mandatory legal requirement; missing = non-compliance in labor disputes |
| Functiehuis dashboard (maatwerk monitoring) | **medium** | Enables HR director to monitor over-classification; supports governance |
| Loopbaanpaden (career path insights) | **medium** | Differentiator; improves employee engagement & retention planning |
| CAO Gemeenten integration (salary scale lookup) | **critical** | Every classification must map to active CAO version for accurate salary |

## User Personas & Key Journeys

**HR Advisor** (daily):
- Workflow: Open new hire file → search HR21 library → propose Beleidsmedewerker B scale 10 → submit → await manager approval → finalize
- KPI: 5 minutes per classification, zero rework

**Manager/Leidinggevende** (weekly):
- Mobile-first approval flow: notification → view proposal + salary consequence → approve/reject
- KPI: <24h response time, <2% rejection rate

**Medewerker** (self-service):
- Access own classification in Mijn HR → view motivatie + Awb bezwaarrecht → file objection if needed
- KPI: 100% visibility; zero "I didn't know my classification changed" disputes

**Directeur HR** (monthly):
- Dashboard: maatwerk count, family distribution, trends
- Alert if maatwerk >10%
- KPI: governance confidence, OR instemmingsrecht compliance

## Dependencies & Integration Points

- **cao-gemeenten** (lookup active salary scales per HR21 classification)
- **employee-master** (bind function to employee record)
- **decidesk** (formal objection procedure per Awb)
- **docudesk** (auto-generate classification decision letters)
- **OR portal** (instemmingsrecht notification for maatwerkfunctie approvals)

## Non-Goals

- Migration of existing classifications from legacy systems (handled separately)
- Integration with FUWASYS (education sector equivalent; out of scope)
- Periodic re-evaluation automation (scheduled periodiek advancement per periodieke verhogingen is out of scope; handled by payroll-engine)

## Success Criteria

- [ ] Zero compliance violations on audit (bezwaarrecht + audit trail)
- [ ] HR advisor can classify new hire in <5 minutes
- [ ] 100% of customers using HR21 library within 3 months of release
- [ ] <3% of classifications cause rework due to scale mismatch
- [ ] Maatwerkfunctie usage stays <10% across customer base

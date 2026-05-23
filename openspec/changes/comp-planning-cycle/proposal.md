# Annual Compensation Planning Cycle — Proposal

**App:** hrmq  
**Spec:** comp-planning-cycle  
**Version:** 0.1.0  
**Owners:** hrmq-team  
**Status:** proposal  
**Demand:** high (EU compliance deadline 2026-06-07, competitive necessity)

## Executive Summary

Implement a structured, multi-stakeholder annual compensation planning workflow that centralizes salary increases, bonus allocations, and promotion decisions across manager-driven proposals, HR-BP review, Reward Committee validation, CFO approval, and payroll integration. Integrate pay-equity audits (gender/age/nationality) meeting EU Pay Transparency Directive (2023/970/EU) requirements and provide employee transparency on salary bands and wage gaps.

## Target Users & Value Propositions

| User | Pain Point | Value |
|------|-----------|-------|
| **Manager** | Excel-based compensation proposals with no budget visibility or consistency | Guided proposal UI with live budget tracking and compa-ratio validation |
| **HR Business Partner** | Manual aggregation of manager proposals; hidden pay-equity issues | Automated pay-equity checks per band/dimension; exception routing for outliers |
| **Reward Manager** | Scattered compensation records; manual letter generation with error risk | Centralized cyclus orchestration, template-driven letter generation, audit trail |
| **CFO** | No visibility into aggregate compensation spend vs. budget until post-fact | Unit-level budget monitoring; final approval gate before payroll; retrospective reporting |
| **Werknemer** | No transparency into why salary changed or what the band range is | Compensation letter with own compa-ratio; on-request anonymized gender pay gap; band-range reference |
| **Reward Committee** | No structured review of promotion or outlier exceptions | Workflow-driven review stage with pre-populated equity flags and underbouwing |

## Features & Demand

### F1: CFO Budget Allocation (Demand: Critical)
ExCo publishes annual loonsverhogings- and bonus-budgets per business unit. Reward Manager creates `CompCycle` with top-down `BudgetAllocatie` records scoped to responsible managers.  
- Trigger: new fiscal year (typically T-0, October/November)
- Budget visibility at unit level; tracking of spend vs. allocation

### F2: Manager Compensation Proposal (Demand: Critical)
Manager views team comp-planning screen with: current salary, compa-ratio, salarisband, performance input, and proposal fields (salary %, bonus €, promotion yes/no). Live budget-spend counter prevents overage at submit.  
- Compa-ratio within-band validation with flag for outliers requiring underbouwing
- Submit blocks if budget overrun; offers two paths: (a) reduce proposals, (b) request HR-BP budget-uplifting

### F3: Pay-Equity Audit (Demand: Critical / Compliance)
Before HR-BP sign-off: system auto-runs `PayEquityCheck` per band on dimensions gender/leeftijd/nationaliteit. Gaps >5% (rood) require mitigation or documented acceptance.  
- Compliance with EU Directive 2023/970/EU informatierechten
- Detection of unwarranted wage discrimination before effect

### F4: Multi-Step Workflow (Demand: High)
Structured routing: manager-submit → HR-BP-review (return-to-draft or escalate) → Reward-Committee-review (promotions + outliers only) → CFO-approval (per-unit aggregate) → letter-generatie → payroll-mutation → cyclus-closed.  
- Each step logged with actor, timestamp, notes
- Configurable review gates per organization

### F5: Compensation Letter Generation (Demand: High)
Template-driven PDF letter per employee with old/new salary, %, bonus, promotion-text (optional), effective-date. Letters track: generation, verstuurd, employee acknowledgment.  
- Batch generation with retry on failure
- Delivery via employee portal; acknowledgment gate for payroll

### F6: Payroll Integration (Demand: High)
T-7 days before effect-date: mutation batch staged to `payroll-engine-nl`. Finance/HR approves batch; post-approval, employee records + payroll queue updated for next run.  
- Two-stage approval (HR submits, Finance verifies)
- Audit trail of every mutation

### F7: Employee Transparency (Demand: High / Compliance)
Employee sees: own compa-ratio, salarisband-range (min/mid/max), and on-request anonymized gender pay-gap within own band (k-anonimiteit >5 per Directive).  
- Logged access to gap data (audit trail per employee)
- Complies with Art. 19, 20 of Richtlijn 2023/970/EU

### F8: Cyclus Retrospective Report (Demand: Medium)
Reward Manager closes cyclus post-payroll-effect: auto-generates report with budget-spent vs. budget, avg-raise-per-band, raise-distribution, promotion-count, final-pay-equity-stand, outlier-count; shares with ExCo + RvC; locks data (audit-only read).  
- Compliance tracking for WOR / OR / legal review
- Baseline for next cycle planning

## Cross-App Dependencies

| Dependency | Role | Notes |
|------------|------|-------|
| `employee-master` | Bron van werknemers, manager-hierarchie, huidig salaris, huidige rol/band | Scope manager-budget per directe rapporturen |
| `payroll-engine-nl` | Eindbestemming voor mutation-batch | Two-way sync: read huidig salary, write mutations per effect-date |
| `performance-management-advanced` | Input voor manager-voorstel evidence | Performance-cycle OKR-scores + 9-box-segment |
| `finance-export` | Budget-autoriteit, kostenplaats-allocatie | Top-down loon/bonus-budget per ExCo-besluit |
| `document-storage` | Archief voor comp-letters + eindrapport | PDF gen + long-term retention |
| `task-management` | Workflow-taken per status-overgang | Budget-uitbreiding-verzoeken, manager-herinneringen |
| `audit-log` | Compliance audit trail | Status-overgangen, salaris-reads, disclosure-reads |

## Implementation Phases

**Phase 1 (MVP):** Cyclus orchestration, manager-proposal, compa-ratio-validation, budget-tracking, basic workflow (manager → HR-BP → CFO), letter-generation, payroll-mutation  
**Phase 2:** Pay-equity-checks, Reward-Committee-gate, cyclus-retrospective-reporting, employee-transparency  
**Phase 3:** Advanced features (equity-remediation-workflows, predictive-budget-planning, cross-system pay-parity audits)

## Standards & Compliance Alignment

- **EU Pay Transparency Directive 2023/970/EU** — informatierechten (salary-band range, gender-gap), implementation deadline 2026-06-07
- **Functiehuis + salarisbanden** — Hay/Korn-Ferry/Mercer methodologie; band-mid = target compa-ratio 1.0
- **AVG** — salarisdata strict access control; k-anonimiteit voor gendergap-rapportages (>5)
- **WOR (Wet Ondernemingsraden)** — OR-instemming voor systeem-wijziging
- **Wet Gelijke Behandeling** — pay-equity-audit operationalizes verbod op loondiscriminatie

## Success Metrics

- 100% of annual comp-cycles completed before payroll effect-date
- 0 compensation-letter errors (data accuracy, template-fill)
- 100% audit-trail coverage (every status-overgang logged)
- 0 pay-equity-gaps >5% undetected (pre-effect)
- 100% manager-budget-compliance (no unvetted overages)
- <2% cyclus-duration vs. planned timeline (schedule adherence)

---

**Next:** design.md (data model, seed data, integrations)

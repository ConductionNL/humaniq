# IKB Rijk & Gemeenten — Proposal

## Executive Summary

**App**: `ikb-rijk-gemeenten`  
**Type**: `SUB_PAGE` (beneath **Salarissen › IKB**)  
**Stakeholder**: Dutch public-sector HR teams (Rijksoverheid, gemeenten)  
**Demand drivers**: Eliminate spreadsheet-based IKB tracking, reduce payroll surprises, improve employee transparency  
**Estimated effort**: ~2,200 story points (payroll integration, fiscal modeling, multi-CAO support)

## Business Case

Without dedicated tooling, HR teams track Individueel Keuzebudget (IKB) in spreadsheets, causing:
- **Miscalculated accruals** when employees move between scales or take parental leave
- **Missed fiscal opportunities** because employees don't understand WKR (Werkkostenregeling) implications
- **Year-end payroll surprises** when cash-out is larger than budgeted

The IKB app gives every medewerker a transparent, real-time dashboard, lets them simulate choices before committing, and produces deterministic payroll mutations flowing into `payroll-engine-nl`.

## Demand Scoring

| Feature | Demand | Rationale |
|---------|--------|-----------|
| **Monthly accrual engine** | 5/5 | Core IKB functionality; blocks all downstream features |
| **CAO-aware accrual (Rijk 16.37%, Gemeenten 17.05%)** | 5/5 | Two-CAO baseline; no app without this |
| **Employee self-service dashboard** | 5/5 | Transparency is the primary user value; drives adoption |
| **Uitruil catalog & simulation** | 5/5 | Allows employees to preview fiscal impact before committing |
| **Approval workflow (line manager + HR)** | 4/5 | Required for governance; some orgs may relax to auto-approve |
| **Fiscal & WKR calculation** | 4/5 | Critical for tax compliance; every uitruil must impact loonheffing correctly |
| **Verlof uitruil (purchase leave)** | 4/5 | Common request; high engagement with leave-conscious medewerkers |
| **Quarterly & annual windows** | 3/5 | Some orgs need to batch; others operate continuous |
| **Year-end residual payout** | 5/5 | December payroll integration; legal requirement |
| **Audit & 7-year retention** | 5/5 | Tax authority + AVG compliance |

## User Archetypes

1. **Medewerker (Employee)** — checks balance monthly, simulates 2-4×/year, downloads jaaroverzicht in January
2. **Lijnmanager (Line Manager)** — approves verlof/training requests, needs contextual policy hints
3. **HR-medewerker** — configures catalog, monitors WKR headroom, handles exceptions
4. **HR-controller** — runs year-end jobs, reconciles with payroll, prepares Belastingdienst exports
5. **OR-lid / Union Rep** — read-only access to anonymised dashboards for compliance verification
6. **Auditor** — accesses immutable audit logs and SBR exports
7. **CAO Negotiator** — reviews aggregated statistics to inform future agreements
8. **External Payroll Provider** — receives deterministic mutation instructions via API

## Success Criteria

- **Adoption**: >80% of medewerkers actively use IKB rather than defaulting to year-end payout
- **Accuracy**: 0 WKR overshoot incidents per quarter; audit score ≥95%
- **Performance**: Dashboard load <2s at 200k employees; accrual run completes in <5min
- **Compliance**: 7-year audit trail immutable; SBR export passes Belastingdienst validation 100%

## Constraints & Risks

### Constraints
- Must support both CAO Rijk (16.37%) and CAO Gemeenten (17.05%) out of the box
- Works for organisations from 50 to 200,000 medewerkers
- Must gracefully handle CAO-overgangsregelingen when medewerkers move between Rijk/gemeenten
- Architecture deliberately keeps IKB-administratie and payroll-uitvoering separate

### Risks
- **WKR calculation complexity**: Fiscal rules are non-linear; miscalculation can trigger tax audits
- **Payroll timing**: Accruals must be locked before payroll runs; timing windows are tight
- **Multi-tenancy**: Organisations may want custom catalogs; must prevent scope creep
- **Seasonal load**: January (jaaroverzicht downloads) and November (year-end) will spike traffic

## Open Questions

1. Should the app support custom CAO definitions per tenant, or only Rijk/Gemeenten?
2. Is the approval workflow mandatory, or should orgs be able to enable auto-approve for low-value items?
3. Should "extra salaris" uitruil be subject to income tax or treated as a benefit in kind?
4. How quickly after month-end should accruals post? (same day vs. next business day vs. 3 days?)

## Related Specs

- **payroll-engine-nl**: consumes IKB mutations (accruals, settlements, payouts)
- **employee-master**: source of truth for employment, scale, CAO assignment
- **employee-self-service-mkb**: hosts the employee dashboard widget
- **verlof-administratie** (planned): consumes "extra verlof" uitruil
- **training-opleidingen** (planned): links training uitruil to course enrolment
- **fiets-van-de-zaak** (planned): registers bike orders; WKR classification flows back

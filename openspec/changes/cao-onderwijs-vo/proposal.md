---
status: proposed
change: cao-onderwijs-vo
target_users: [vo-school-hr, vo-bestuurder, docent, schooladministratie]
demand_score: 8
business_unit: hrmq-cao
---

# Proposal: CAO Voortgezet Onderwijs

## Executive Summary

The Dutch secondary education (VO) sector employs ~110,000 teachers and ~30,000 support staff across ~650 schools. The CAO VO is one of the most arithmetically complex in the country—featuring skill-based pay scales (LB/LC/LD), annual step increments (periodieke verhogingen), lesson-hour caps (750 fte/year) with surtaxes, market-scarcity supplements (wiskunde/NASK/Duits), pension-pooling (Vervangingsfonds), ABP-OW sector pension with franchise, and DUO-funding flows tied to pupil enrollment.

Today, three incumbents (RAET/Visma, AFAS, in-house systems) dominate at EUR 4–8/teacher/month. Small school boards (<10 sites) report 1.5–2× higher HR-admin costs than large ones due to vendor lock-in and brittle CSV import chains to student-info systems (Magister, Somtoday, ParnasSys). An open-source alternative with native CAO-VO support and direct Lerarenregister/DUO integration addresses a documented market gap.

**This spec adds the VO-CAO module to hrmq:** versioned pay-scale tables, task-allocation rules, substitute-teacher pooling, and bekostiging (DUO funding) allocation. The module is loaded per school (`organisation.cao = "vo"`) and activates VO-specific employee fields, contract types, and payroll overrides.

## Business Case

### Demand drivers

1. **Regulatory complexity** — CAO VO renegotiates ~every 2 years with new tables; schools need an easy import path (XLSX/JSON) with automatic effective-date handling (no manual employee-level rewrites).
2. **Certification gate** — Promotion to LC/LD scales requires validation against the Lerarenregister (legal requirement for school inspections); today done manually via spreadsheets.
3. **Lesson-hour compliance** — CAO art. 7.1 caps classroom hours at 750/fte/year; breaches trigger surtax (pro-rata uren over cap) AND require formal teacher sign-off. Audits confirm this is the #1 compliance risk.
4. **Funding lag** — Schools bill DUO quarterly for pupil enrollment but do not receive funding for 2–3 months; cash-flow pressure for small boards. A visible tracking dashboard for DUO disputes/reconciliation is valued at ~EUR 500–1000/school/year.
5. **Pension opacity** — Teachers are less likely to sign contracts if they don't understand ABP-OW franchise/premium calculations; 30% of onboarding friction today is "clarify pension" queries.
6. **Sector cost benchmark** — PO-Raad / VO-raad publish annual cost comparisons; schools want aggregated payroll metrics (cost-per-teacher, full-time-equivalents, skill-gap hires) to justify budget to governance.

### Market opportunity

- **Addressable TAM:** ~650 VO schools, 400 of which have <10 sites (high cost-sensitivity).
- **TAM SAM:** 200 schools likely to evaluate open-source (price <50% incumbent, integration confidence >80%).
- **Unit economics:** at EUR 2/teacher/month (vs. EUR 4–8 incumbent), a 50-teacher school saves EUR 1200/year; 100-teacher school saves EUR 2400.
- **Total addressable revenue:** 200 schools × 100 avg teachers × EUR 2/month × 12 = EUR 4.8M/year. (Conservative; does not include consulting/migration fees.)

### Competitive moat

AFAS-Onderwijs and RAET both offer VO support but:
- Closed-source; pricing is yearly and opaque.
- No direct Lerarenregister/DUO/ABP integration; schools hire consultants to sync.
- No teacher self-service inzage into CAO rules / audit trail.

hrmq's open-architecture with native connectors + full teacher transparency (loonstrook, jaaropgaaf, ABP-saldo) is a credible differentiator.

## Scope

This spec implements:

| Feature | Included | Out-of-scope |
|---------|----------|--------------|
| Versioned pay-scale tables (LA/LB/LC/LD/LE, 1–20 tredes) | ✓ | |
| Automatic annual step increments | ✓ | |
| Bevoegdheid-gate for LC/LD promotion | ✓ | |
| 750-hour cap + surtax calculation | ✓ | |
| Market-scarcity supplements (NASK/Duits/etc.) | ✓ | |
| Vervangingsfonds (substitute-teacher claims) | ✓ | |
| ABP-OW pension enrolment + premium calculation | ✓ | |
| DUO quarterly bekostiging (pupil-enrollment funding) | ✓ | |
| BAPO/senior regulation (57+, 170-hour reduction) | ✓ | |
| Jaaropgaaf + IB47 (annual wage statement + tax filings) | ✓ | |
| PO (primair onderwijs) CAO | — | ✓ |
| MBO/HBO/WO CAOs | — | ✓ |

## Timeline & Milestones

- **Q3 2026 (payroll-MVP):** CAO-VO core tables, periodieke verhoging, bevoegdheid validation.
- **Q4 2026 (compliance + integrations):** DUO/ABP/Vervangingsfonds connectors, jaaropgaaf, teacher self-service.
- **Q1 2027 (optimization):** BAPO rules, cost-per-teacher analytics, VO-raad benchmark export.

## Risks & Mitigations

| Risk | Severity | Mitigation |
|------|----------|-----------|
| CAO tables change mid-year (3%+ surtax added) | Medium | Model as new table version, not employee override; audit-trail shows which table-version produced each loonstrook. |
| Lerarenregister API downtime blocks promotion | High | Cache bevoegdheid-check results with 24-hour TTL; log failed check with "retry pending" status; manager can retry or skip with explicit audit note. |
| DUO-bekostiging disputes take months to resolve | Medium | Expose discrepancy-tracking dashboard; auto-escalate >5% variance to HR-admin worklist. |
| Teacher union (AOb) wants to audit CAO compliance | Low | Build compliance-report export (anonymized, aggregated); gate behind opt-in data-share agreement. |

## Dependencies

- `payroll-engine-nl` — base loonheffingen calculation.
- `lerarenregister-koppeling` — bevoegdheid validation API.
- `openconnector` — DUO / ABP / Vervangingsfonds adapters.
- `document-template-engine` — contract addenda, jaaropgaaf, BAPO confirmations.

## Success Criteria

1. **Adoption:** ≥20 schools live within 6 months of GA; retention >90%.
2. **Compliance:** 100% CAO art. 7.1 (lesson-hour cap) enforced; no school receives audit citation for undetected overages.
3. **Teacher confidence:** ≥80% of teachers report they understand their schaal/trede/ABP on first contract signing (survey).
4. **Support load:** <2 "CAO table update" tickets per month (= easy import process).
5. **Sector adoption:** PO-Raad includes hrmq in Q1 2027 cost benchmark.

## Next Steps

1. Board approval of scope + timeline.
2. Kick-off with payroll-engine-nl owner (parallel work on loonheffingen extension points).
3. Lerarenregister API integration POC.
4. Recruit 3–5 pilot schools for beta (Q3 2026).

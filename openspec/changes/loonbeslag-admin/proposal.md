# loonbeslag-admin: Proposal

**Status:** draft  
**Created:** 2026-05-23  
**Domain:** Payroll / Legal Compliance  

## Executive Summary

Dutch employers are legally obligated to process wage garnishment orders (loonbeslagen) — court-enforced deductions from employee salaries paid directly to creditors. Current manual processes create three categories of risk: (1) **civil liability** — failure to remit or missed statutory deadlines exposes employers to full debt recovery; (2) **data protection** — garnishment orders contain sensitive financial data requiring strict confidentiality controls; (3) **social harm** — miscalculated exemptions can push employees below subsistence levels.

**loonbeslag-admin** automates the full wage-garnishment lifecycle within hrmq: registration, legal-deadline enforcement, statutory exemption calculation (Wvbvv 2021), multi-garnishment precedence, monthly remittance batching, standardized correspondence, audit trails, and role-based confidentiality. The capability sits atop `employee-master` and `payroll-engine-nl` and is essential for any serious Dutch employer — 7–8% of the working population holds a garnishment order at any given time.

## Problem Statement

### Regulatory Burden

**Statute:** Wet vereenvoudiging beslagvrije voet (Wvbvv 2021, in force 2021-01-01) + Wetboek van Burgerlijke Rechtsvordering art. 475–479g.

- Employer becomes *third-party garnishee* upon receipt of court order.
- Must file a *statutory declaration* (derdenverklaring) within **28 days**.
- Must remit correct amounts monthly and respect legal precedence (preferent vs. concurrent).
- Must calculate *exemption* (beslagvrije voet) using formula based on income, household status, rent, and care costs — not handwritten estimates.
- Multi-garnishment sums require **proportional allocation** or **chronological priority** depending on precedence class.
- Failure to remit or declare exposes employer to **full debt recovery** (art. 476a Rv).

### Data Protection Complexity

- Garnishment orders contain **special category personal data** (Art. 9 GDPR) — financial situation of individuals.
- Employee must see full details on payslips; colleagues and non-authorized managers must see **zero detail** ("Other deduction — €350").
- Access must be logged in a separate `BeslagVertrouwelijkheidsLog` (GDPR audit trail).
- Documents retained **7 years after garnishment end** or **7 years after termination** — whichever is later.

### Manual-Process Risks

1. **Deadline miss** — derdenverklaring forgotten, employer liable for full debt.
2. **Wrong exemption calculation** — standard formula unknown, HR overrides without documented reasoning, exposes employee to hardship.
3. **Precedence error** — multiple garnishments processed in registration order instead of legal priority (Belastingdienst preferent, others concurrent).
4. **Confidentiality breach** — payslip printout shown to wrong person; financial data visible in shared reports.
5. **Remittance error** — IBAN or amount wrong; payment rejected; liability falls on employer.
6. **Audit failure** — no trail of who accessed what when; accountant cannot verify compliance.

## Solution Scope

**loonbeslag-admin** delivers:

### Core Capabilities

1. **Registration & Deadline Enforcement**
   - Upload/register court order (exploot) with scan.
   - Automatic countdown timer: "Derdenverklaring due 28 days from service date."
   - Daily compliance check; escalation alerts to HR/payroll if T-2 or overdue.

2. **Statutory Exemption Calculation**
   - Implements Wvbvv 2021 formula: income, household type, dependent count, rent, care-insurance premium.
   - Pulls master data from `employee-master` (household, dependents, rent/mortgage data).
   - Recalculates on-demand when employee provides updated rent/care proof.
   - Allows HR override with mandatory free-text justification + audit log.

3. **Multi-Garnishment Precedence**
   - Ranks active garnishments by legal class (Belastingdienst → LBIO → court orders).
   - Within class, respects registration order (first-in-first-served) or pro-rata allocation per tenant config.
   - Computes available-for-garnishment = gross salary − exemption.
   - Distributes monthly according to precedence.

4. **Monthly Remittance**
   - Integrates with `payroll-engine-nl`: each payroll run triggers garnishment deduction.
   - Generates SEPA pain.001 batch file with correct IBAN/amount/reference per garnisher.
   - Records payment status (planned / executed / failed) per garnishment per month.
   - Supports exception handling: rejected payment → reconciliation queue.

5. **Standardized Correspondence**
   - Pre-filled Dutch templates: statutory declaration, monthly detail statement, release letter.
   - Supports dispatch via registered post, email, or Digipoort (where supported by creditor).
   - Document vault integration; retention tracking.

6. **Confidentiality & Audit**
   - Payslip shows "Inhouding loonbeslag — €350 — afdracht LBIO alimentatie referentie 12345" to employee.
   - Payslip shows "Overige loonheffing — €350" to non-authorized manager.
   - API returns 404 to unauthorized callers; access logged in separate `BeslagVertrouwelijkheidsLog`.
   - Full audit trail in `audit-trail-payroll`.

### Placement & UX

**Information Architecture (ADR-001):** `Salarissen › Loonbeslagen` (SUB_PAGE under top-level "Salarissen" menu).

- Payroll-admin manages garnishments (register, declare, monitor).
- HR-manager approves overrides and handles employee requests for exemption proof.
- Employee views active garnishments, exemption calculation, and remittance history in self-service.
- Accountant/auditor exports full trail for compliance verification.

## Value Proposition

### For the Employer

- **Risk reduction:** Automated deadlines + validated calculations eliminate civil liability from human error.
- **Compliance:** Statutory declarations, exemption formulas, and audit trails satisfy court, tax authority, and data-protection inspector.
- **Operational efficiency:** No manual correspondence drafting, no mental math on precedence; batch payments in one SEPA file.
- **Auditability:** Full trail for accountant and labor-inspection defense.

### For the Employee

- **Financial clarity:** See the garnishment amount, exemption applied, and remittance schedule.
- **Hardship protection:** Correct Wvbvv calculation prevents subsistence-level errors that trigger bewindvoering.
- **Privacy:** Colleagues and non-authorized managers never see the garnishment.

### For the Governance Board / Compliance Officer

- **Data governance:** Separate confidentiality log; retention policies automated; 7-year destruction scheduled.
- **GDPR defense:** Purpose limitation (legal obligation), access controls (role-based), audit trail, data minimization (hidden from non-need-to-know).

## Out of Scope (Future or Delegated)

- **Self-Service Debt-Dispute / Bewindvoering escalation** — loonbeslag-admin registers and protects the garnishment; dispute resolution is part of `employee-support-cases` or integration with external debt counselors (future).
- **Real-time BKWI/SVB exemption service** — design foresees integration with SVB's centralized exemption calculator (Wvbvv 2021 implements this); initial MVP uses local formula + manual override.
- **Beslag-specific messaging/notifications** — routed through `notification-engine`; not custom mail in this spec.
- **Multi-tenant garnishment split** — if an employee detaches mid-garnishment (intercompany transfer), the garnishment stays with the original payroll administratie; future `employee-transfer` spec will handle split/assign.

## Success Criteria

1. **Deadline enforcement:** No garnishment without a derdenverklaring within 28 days. Daily compliance alerts test to green.
2. **Correct exemption:** A random 10-garnishment audit finds 100% Wvbvv-formula compliance; manual overrides have mandatory justification.
3. **Precedence accuracy:** Multi-garnishment test cases (preferent + concurrent, proportional vs. chronological) execute to spec.
4. **Confidentiality:** Loonbeslag data visible only to authorized roles; payslip text differs per audience (employee vs. manager vs. public).
5. **Batch payment:** Monthly SEPA file generated with zero remittance errors (IBAN/amount/reference verified).
6. **Audit trail:** Accountant export includes all garnishments, afdrachten, correspondence, and compliance events for the requested period.

## Dependencies & Integration

- **employee-master:** Household type, dependents, rent/care costs.
- **payroll-engine-nl:** Monthly deduction and netto-salary calculation.
- **multi-administratie:** Garnishments scoped to administratie; payroll-admin manages per-administratie.
- **audit-trail-payroll:** All mutations logged.
- **journaalpost-export:** Garnishment deductions posted to GL account "Beslagleggers te betalen."
- **openconnector:** SEPA bank file + Digipoort correspondence dispatch.
- **document-vault:** Scan storage and retention tracking.
- **notification-engine:** Deadline alerts, escalations.
- **employee-master** (future `bewindvoering-flag`): Wsnp conflict detection and auto-suspension.

## Effort Estimate

- **Backend (data layer, API, calculations, batch jobs):** 40 points (5–6 weeks, 2 engineers).
- **Frontend (registration, monitoring, templates, employee view):** 20 points (2–3 weeks, 1 engineer).
- **Legal/Compliance review:** 5 points (documentation, statement from counsel).
- **Testing (unit, integration, scenario, audit-trail verification):** 15 points (2 weeks, 2 engineers).
- **Total:** ~80 points, 8–10 weeks, 4 FTE.

## Open Questions / Decisions Needed

1. **Exemption calculation service:** Use local Wvbvv formula (MVP) or integrate real-time BKWI/SVB (future)?  
   → **MVP: local formula.** Real-time integration deferred post-launch.

2. **Multi-garnishment precedence:** Proportional (pro-rata) or chronological (first-in-first-served)?  
   → **Per-administratie setting.** Default proportional, configurable.

3. **Employee override on Wsnp flag:** If employee is under Wsnp, auto-suspend garnishments or require HR confirmation?  
   → **Auto-suspend + escalate.** Wsnp protection is statutory.

---

**Next Steps:**
1. Design phase: entity schemas, API routes, template definitions.
2. Specification phase: detailed requirements with test scenarios.
3. Task breakdown: backend → frontend → testing.

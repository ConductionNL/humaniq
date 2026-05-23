---
status: proposed
---

# Proposal: Employee Self-Service voor MKB

**Change ID:** employee-self-service-mkb  
**Title:** Employee Self-Service voor MKB  
**Created:** 2026-05-23  
**Status:** Proposed

## Executive Summary

Het `employee-self-service-mkb` spec definieert een afzonderlijke applicatie waarmee werknemers in MKB-bedrijven hun eigen HR-gegevens kunnen inzien, hun loonstrook en jaaropgaaf downloaden, verlof aanvragen, contracten raadplegen, declaraties indienen, en opleidings-aanvragen doen — zonder dat ze daarvoor afhankelijk zijn van de HR-administrateur of een ingelogde Nextcloud-werkomgeving.

**Key motivation:** In MKB-context (10-250 werknemers) is het zelden zinvol om elke werknemer een volwaardig Nextcloud-account te geven. Veel werknemers hebben geen eigen werkmail en werken vooral mobiel. Dit portaal automatiseert volume-laag werknemer-verzoeken zodat HR zich kan richten op echte HR-zaken.

## Information Architecture

**Placement type:** `SUB_PAGE`  
**Lives at:** Mijn HR (top-level wrapper)  
**Rationale:** Self-service portal; per ADR-001 Rule 2, self-service wrappers live under `Mijn HR` as rol-gefilterde wrappers.

## Features & Demand

### Feature Set

| Feature | Demand Score | Description |
|---------|:---:|---|
| DigiD Authentication | 9/10 | Enable login via Dutch national ID (DigiD) for secure, government-standard authentication |
| Nextcloud SSO | 8/10 | Allow seamless login from Nextcloud for employees with NC accounts |
| Magic-Link Authentication | 9/10 | Fallback magic-link authentication via registered employee email for mobile/non-NC workers |
| Payslip & Tax Certificate Viewing | 10/10 | View and download monthly payslips and annual tax certificates as PDFs with inline viewer |
| Leave Request Management | 9/10 | View leave balances by category; submit leave requests with auto-calculation; manager approval flow |
| Self-Service NAW Updates | 8/10 | Employee can self-update address, phone, private email without approval |
| Approval-Gated Mutations | 9/10 | IBAN, BSN, marital status, birthdate changes require manager/HR approval to prevent fraud |
| Expense Reimbursement | 8/10 | Submit expense claims with receipt photo, OCR, category; manager approval with budget check |
| Contract & Addenda Viewing | 9/10 | View current contract, all addenda, and signature status (Decidesk integration) |
| Training Request Submission | 7/10 | Propose training; submit with cost/duration; manager approval with budget check |
| Personal Development (POP) | 7/10 | View POP goals, progress, meeting schedule; submit self-reflection updates |
| Accessibility (WCAG AA) | 10/10 | Full WCAG 2.1 AA compliance; 44×44px touch targets; mobile-first (320px+) |

## Stakeholder Profiles

### Primary User: Employee (Medewerker)

**Demographic:** Highly diverse (17-yo retail staff to 58-yo tradesperson to 32-yo consultant)  
**Primary goal:** Self-serve access to own payroll, leave, and contract data; quick approval requests (vacation, expenses)  
**Pain points:** 
- Currently relies on HR for every loonstrook question
- Manual leave requests via WhatsApp/email
- Paper-based expense submission
- No mobile-friendly access to own data

**Behaviors:**
- High volume of simple lookups (payslips, leave balance)
- Irregular submissions (vacation, training, expenses)
- Mobile-first usage patterns
- Low technical tolerance; expects intuitive interface

---

### Secondary User: Manager (Leidinggevende)

**Goal:** Approve/reject employee requests (leave, expenses, mutations) efficiently  
**Scope:** Notifications + approval actions in HRMQ admin app; receives context from self-service portal  
**Pain points:**
- Scattered approval workflows across email and NC Notifications
- No visibility into employee-initiated changes before approval
- Manual verification of IBAN changes creates audit burden

**Behaviors:**
- Desktop-first; batch processes approvals
- Requires clear notification + decision context
- Needs audit trail of all approvals/rejections

---

### Tertiary User: HR Administrator (HR-admin)

**Goal:** Ensure payroll integrity; verify critical mutations; monitor system health  
**Scope:** Final approval on fraud-sensitive changes (IBAN, BSN); audit trail; compliance reporting  
**Pain points:**
- Current: 30-40% of time spent on operational employee requests
- No dedicated form for mutation approval; manual verification
- No centralized log of who changed what and when

**Behaviors:**
- Desktop-first; high accuracy requirements
- Prefers bulk views + filter/search over individual notifications
- Requires compliance documentation + audit exports

---

### Organizational Stakeholder: Employer (DGA/Werkgever)

**Goal:** Reduce HR team operational load; provide modern employee experience  
**Scope:** Configuration of auth methods, leave types, approval workflows  
**Pain points:**
- HR team bottleneck on simple employee queries
- Competitive disadvantage vs. larger orgs with dedicated HR portals

---

## User Journeys

### Journey 1: "Ik wil mijn loonstrook van vorige maand zien"

**Trigger:** Employee realizes paycheck was deposited; wants to download payslip  
**Steps:**
1. Opens phone; navigates to company's self-service portal URL
2. Taps "Inloggen met DigiD" (or magic-link fallback)
3. Authenticates with DigiD
4. Lands on dashboard; taps "Loonstroken"
5. Sees list of last 24 months payslips; taps May 2026
6. Views payslip PDF inline; taps "download"
7. Receives PDF on phone for email to accountant

**Pain points:** Currently emails HR or calls for PDF; HR must manually find and send  
**Success criteria:** < 90 seconds from login to download completion

---

### Journey 2: "Ik wil vakantie aanvragen voor augustus"

**Trigger:** Summer holiday planning  
**Steps:**
1. Logs into portal
2. Taps "Verlof" → sees balance: "20 vakantiedagen restant"
3. Taps "Nieuwe aanvraag" → selects "Vakantie" + dates 2026-08-10 to 2026-08-21
4. System calculates 8 workdays; warns if over balance
5. Submits request
6. Manager receives Nextcloud Notification + email
7. Employee sees status "Wacht op goedkeuring"
8. Manager approves in admin app
9. Employee refreshes portal; sees "Goedgekeurd" + updated balance (20 - 8 = 12 days)
10. Manager/HR receives optional confirmation that employee has seen approval

**Pain points:** Currently via WhatsApp, email, or paper; no audit trail; no balance recalculation  
**Success criteria:** Manager gets notification within 60 sec; approval reflected in portal within 5 min

---

### Journey 3: "Ik ben verhuisd; mijn adres moet bijgewerkt"

**Trigger:** NAW change  
**Steps:**
1. Logs in
2. Taps "Mijn gegevens" → finds address section
3. Edits postcode + huisnummer
4. Taps "Opslaan" → change saved immediately
5. Receives confirmation message + audit notification to manager/HR
6. In admin app, HR sees MutationApproval with decision=auto_approved

**Pain points:** Currently requires email to HR or manual form  
**Success criteria:** Change persists in Employee record within seconds; audit trail created

---

### Journey 4: "Ik wil mijn IBAN wijzigen na echtscheiding"

**Trigger:** Financial change (marital/banking)  
**Steps:**
1. Logs in; taps "Mijn gegevens" → IBAN section
2. Enters new IBAN
3. System creates MutationApproval with status=pending; old IBAN stays active
4. Manager + HR receive high-priority Notification: "⚠️ IBAN-wijziging aangevraagd; controleer telefonisch of dit echt is"
5. HR calls employee to verify (fraud prevention)
6. HR approves in admin app
7. Employee record updated; new IBAN active from next payrun
8. Employee receives confirmation: "Je IBAN-wijziging is doorgevoerd"

**Pain points:** High fraud risk; currently manual process; no centralized verification  
**Success criteria:** Approval flow prevents IBAN-fraud; audit trail perfect; < 24 hour resolution SLA

---

### Journey 5: "Ik dien een tankbon in voor terugbetaling"

**Trigger:** Business mileage  
**Steps:**
1. Opens portal on mobile
2. Taps "Declaratie" → selects "Reiskosten zakelijk"
3. Takes photo of receipt
4. OCR extracts amount + date; pre-fills form
5. Adds description; submits
6. Goes to expense-reimbursement app with status=submitted
7. Manager notified of pending approval
8. Employee sees status + expected reimbursement in portal

**Pain points:** Paper receipts; manual data entry; no OCR; slow processing  
**Success criteria:** Photo → approval notification within 30 sec

---

## Success Metrics

- **Adoption:** 60%+ of eligible employees log in at least monthly within 6 months
- **Support reduction:** HR team time on employee payslip/leave requests drops 30% YoY
- **Error rate:** IBAN mutations with approval flow = 0 fraud incidents YoY (vs. current manual baseline)
- **Mobile:** 70%+ of logins from mobile devices (< 500px viewport)
- **Accessibility:** 0 WCAG AA violations on all core screens (via axe-core automated scan)
- **Approval SLA:** Leave requests approved within 24 hours; IBAN mutations within 48 hours
- **Mobile response time:** All pages < 3s load time on 4G; < 5s on 3G

## Standards & Compliance

- **DigiD 1.13:** Logius standard for SAML authentication
- **WCAG 2.1 AA:** Mandatory for Dutch public sector; best practice for employer portals
- **OWASP:** Magic-link best practices (single-use, short TTL, IP-binding)
- **NIST SP 800-63B AAL2:** Session management (30 min idle / 8 hour absolute timeout)
- **AVG art. 9 lid 2 sub b:** Processing BSN and health data (leave) in employer-employee relation
- **Mobile-first:** 320px+ responsive design

## Competitor Analysis

| Competitor | Strengths | Weaknesses | HRMQ Position |
|-----------|-----------|-----------|---|
| Loket.nl ESS | Government-standard | Basic features; no mobile | DigiD-first + mobile-first |
| AFAS InSite | Powerful features | Heavyweight UI; overkill for MKB | Simplified for MKB; focused subset |
| Personio | Strong UX; German market leader | No DigiD; cloud-only | Dutch + local integration-friendly |
| Visma Raet ESS | Integrated payroll | Legacy desktop-first | Modern mobile + modern UX |

**HRMQ differentiator:** DigiD-first + mobile-first + purpose-built for Dutch MKB + integrated with local HRMQ payroll stack

## Dependencies & Integration

### Inbound Dependencies
- **employee-master:** Employee records, BSN, email, nc_user_id, NAW fields
- **payslip-generation:** Payslip PDFs; metadata
- **leave-management-mvp:** Leave balances, request workflows, manager approval
- **contract-management:** Employment contracts, addenda
- **expense-reimbursement** (new): Expense submission, manager approval, budget checks
- **training-request** (new): Training budget, approval workflows

### External Integrations
- **Logius (Digid):** SAML identity broker for government-standard auth
- **Decidesk:** Contract + addendum signature status
- **Nextcloud SSO:** OAuth token passthrough for employees with NC accounts
- **SMTP:** Email delivery (magic-link tokens, notifications)
- **OCR (LLM or cloud API):** Receipt text extraction for expense claims
- **Nextcloud Notifications:** Approval requests, status updates
- **Nextcloud Talk** (optional): HR helpdesk chat integration

## Timeline & Phasing

**MVP (Target Q3 2026):**
- DigiD + magic-link authentication
- Payslip/tax certificate viewing
- Leave balance + request submission
- NAW self-service updates
- Mobile-first WCAG AA

**Phase 2 (Q4 2026):**
- Approval-gated mutations (IBAN, BSN, marital status)
- Expense reimbursement with OCR
- Contract & addenda viewing (Decidesk integration)

**Phase 3 (Q1 2027):**
- Training request submission
- Personal development (POP) goals + self-reflection
- Advanced analytics dashboard (usage, approval SLA metrics)

## Risks & Mitigation

| Risk | Probability | Impact | Mitigation |
|------|:---:|:---:|---|
| DigiD onboarding complexity | Medium | High | Early engagement with Logius; phased rollout to pilot customer first |
| Mobile device camera upload (receipt OCR) | Low | Medium | Cloud OCR fallback; manual re-entry option always available |
| Fraud via IBAN mutation | Medium | Critical | Multi-factor approval (manager + HR); phone verification required; fraud pattern detection |
| Session timeout too aggressive (users logged out) | Medium | Low | Prominent countdown warning at 5 min; extend button; 8-hour absolute cap |
| Accessibility testing scope creep | Low | Medium | Lock scope to 12 core screens; automate axe-core test per merge; manual audit only on final release |


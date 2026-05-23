# IKB Rijk & Gemeenten — Implementation Tasks

## Phase 1: Data Model & Core Infrastructure

### T1.1: Register & Schema Definition
- [ ] Create `hrmq_register.json` in `lib/Settings/` with:
  - [ ] IkbAccount schema (employeeId, cao, year, balances, percentages, status)
  - [ ] IkbAccrual schema (period, salary components, accrual amount, locked state)
  - [ ] IkbChoice schema (catalog item reference, amounts, status, fiscal impact, WKR category)
  - [ ] IkbCatalogItem schema (code, category, min/max, approval rules, WKR classification)
  - [ ] IkbPayout schema (residual, gross, net, loonheffing, mutation reference)
- [ ] Define all relations (IkbChoice → IkbAccount, → IkbCatalogItem, → supporting documents)
- [ ] Register with OpenRegister `SchemaService` during app initialization
- [ ] Add schema validation constraints (amount ranges, status state machine)

### T1.2: Database Migrations
- [ ] Create migration 001: IkbAccount, IkbAccrual, IkbChoice, IkbCatalogItem, IkbPayout tables
- [ ] Create migration 002: Indexes on (accountId, period), (accountId, status), (year, cao)
- [ ] Create migration 003: Add audit_log table for immutable entries (append-only, no DELETE)
- [ ] Create migration 004: Add constraint: accrualAmount ≥ 0, currentBalance ≥ 0
- [ ] Create migration 005: Add unique constraint on (accountId, period) for accruals
- [ ] Create migration 006: Add index on (employeeId, year) for account lookups

### T1.3: Seed Data & Configuration
- [ ] Create seed data in `hrmq_register.json`:
  - [ ] 3 IkbAccount records (Rijk active, Gemeente with verlof, Rijk with pending training)
  - [ ] 5+ IkbAccrual entries (mix of full month, proration, suppression)
  - [ ] 5 IkbCatalogItem records (salaris, verlof, fiets, training, fitness)
  - [ ] 3 IkbChoice entries (approved fiets, pending training, submitted verlof)
  - [ ] 1 IkbPayout record (previous year)
- [ ] Use realistic Dutch names (Jan Willemse, Maria Garcia, Henk Pieterse)
- [ ] Ensure all relational references are valid (accountId → account exists)
- [ ] Load seed via `ConfigurationService::importFromApp()` on app install
- [ ] Verify seed idempotency (re-import skips existing by slug)

### T1.4: Deduplication Check
- [ ] Search openspec/specs/ and openregister/lib/Service/ for overlapping:
  - [ ] ObjectService (CRUD) — use as-is, no custom implementation
  - [ ] SchemaService (validation) — use as-is
  - [ ] AuditTrailService (immutable logs) — use as-is
  - [ ] NotificationService (approvals) — use as-is
  - [ ] FileService (documents) — use as-is
- [ ] Document findings in artifact (no overlap found, leveraging platform)
- [ ] Identify custom logic needed:
  - [ ] Fiscal calculation engine (WKR rules, bijzonder tarief)
  - [ ] Monthly accrual job (batch processing)
  - [ ] Year-end payout calculation
  - [ ] Approval workflow triggers

---

## Phase 2: Backend API & Business Logic

### T2.1: IkbAccountService
- [ ] Implement `IkbAccountService`:
  - [ ] `getOrCreateAccount(empId, year, cao)` — fetch or initialize
  - [ ] `getCurrentBalance(accountId)` — query current balance with caching
  - [ ] `getProjectedYearEndBalance(accountId)` — estimate based on avg accrual + remaining months
  - [ ] `freezeAccount(accountId, reason)` — prevent submissions during payroll window
  - [ ] `closeAccount(accountId)` — mark as closed (termination or payout)
- [ ] Unit tests: edge cases (proraton, freeze, close transitions)
- [ ] Caching: memoize balance queries (5 minute TTL, invalidate on accrual/choice settlement)

### T2.2: Monthly Accrual Job
- [ ] Implement `IkbAccrualService`:
  - [ ] `runMonthlyAccrual(runId, periodYYYYMM)` — orchestrator
    - [ ] Query all active IkbAccounts for the calendar month
    - [ ] For each account:
      - [ ] Fetch pensionable salary + bovenwettelijke komponenten from payroll-engine-nl
      - [ ] Calculate accrual: (salary + components) * percentage / 12
      - [ ] Handle proration (mid-month joins) — prorated by calendar days
      - [ ] Handle suppression (unpaid leave) — zero amount with reason
    - [ ] Create IkbAccrual entry atomically
    - [ ] Increment currentBalance
    - [ ] Lock entry: lockedAt = now(), lockedBy = "sys-accrual-job"
    - [ ] Write audit trail
  - [ ] Idempotency: keyed by runId (re-run same month → skip existing accruals)
  - [ ] Error handling: retry logic for salary lookups, email alerts on failure
- [ ] Scheduled job: configured to run on 1st of each month at 06:00 UTC
- [ ] Unit tests:
  - [ ] Full-month accrual (REQ-001.1)
  - [ ] Mid-month proration (REQ-001.2)
  - [ ] Unpaid leave suppression (REQ-001.3)
  - [ ] Idempotency (running twice with same runId)
- [ ] Integration tests: with mock payroll-engine-nl

### T2.3: CAO Configuration & Percentage Management
- [ ] Implement `IkbCaoConfigService`:
  - [ ] Store CAO percentage config in OpenRegister settings (or app config)
  - [ ] Default values:
    - [ ] Rijk: 16.37% (breakdown: 8% vakantietoeslag, 6.4% eindejaarsuitkering, 1.97% levensloop)
    - [ ] Gemeenten: 17.05% (breakdown: 8%, 6.75%, 1.5% verlof, 0.8% levensloop)
  - [ ] `updateCaoPercentage(cao, newPercentage, validFrom)` — add new version, don't retroactive modify
  - [ ] `getPercentageAsOf(cao, date)` — lookup percentage for a specific date
  - [ ] Prevent retroactive changes: validate validFrom ≥ now()
- [ ] Audit: log all CAO percentage changes
- [ ] Tests:
  - [ ] Default percentages (REQ-002.1, 2.2)
  - [ ] Renegotiation without retroactive recalc (REQ-002.3)

### T2.4: Simulation Engine (Fiscal Calculation)
- [ ] Implement `IkbFiscalSimulationService`:
  - [ ] `simulate(accountId, catalogItemId, amount)` — dry-run, no persistence
  - [ ] Resolve catalog item → get wkrCategory, fiscalRule
  - [ ] Fetch employee loonheffingstabel entry → tax rate
  - [ ] For each wkrCategory:
    - [ ] `nihilwaardering`: gross deduction = amount, net impact ≈ 0, WKR delta = 0
    - [ ] `gericht_vrijgesteld`: gross deduction = amount, tax withheld = 0, WKR delta = 0
    - [ ] `vrije_ruimte`: gross deduction = amount, net delta = (amount - WKR %), WKR delta = amount / gross wage
    - [ ] `belast`: gross deduction = amount, net = amount * (1 - tax%), WKR delta = amount / gross wage
  - [ ] Return simulation result:
    ```json
    {
      "grossDeduction": 750.00,
      "netImpact": -611.00,
      "loonheffingDelta": 139.00,
      "wkrCategory": "nihilwaardering",
      "warningFlags": []
    }
    ```
  - [ ] Validations:
    - [ ] Check balance sufficiency → if insufficient, return projectedDateSufficient
    - [ ] Check required document → if missing, set canSubmit=false
    - [ ] Check WKR headroom → warn if would overshoot (non-fatal)
- [ ] Tests:
  - [ ] All WKR categories (nihil, vrijgesteld, vrije-ruimte, belast)
  - [ ] Balance insufficiency (REQ-003.2)
  - [ ] Document requirement (REQ-003.3)
  - [ ] WKR overshoot warning (REQ-005.3)

### T2.5: IkbChoiceService
- [ ] Implement `IkbChoiceService`:
  - [ ] `submitChoice(accountId, catalogItemId, amount)`:
    - [ ] Validate amount (min/max, not exceeding balance)
    - [ ] Determine if approval required (via catalogItem.requiresApproval)
    - [ ] Create IkbChoice with status = "submitted" (or "concept" if editable before approval)
    - [ ] If requiresApproval, create Task and dispatch notification
    - [ ] Write audit trail
    - [ ] Return choice with fiscal preview
  - [ ] `approveChoice(choiceId, approverId, rationale)`:
    - [ ] Validate approver role/permission
    - [ ] Update status to "approved"
    - [ ] Set approvedAt, approverId, decisionRationale
    - [ ] Notify employee
    - [ ] Schedule settlement (or settle immediately if effectiveDate is past)
    - [ ] Write audit trail
  - [ ] `rejectChoice(choiceId, approverId, rationale)`:
    - [ ] Update status to "rejected"
    - [ ] Notify employee with rationale
    - [ ] Balance remains unchanged (no settlement)
    - [ ] Write audit trail
  - [ ] `settleChoice(choiceId, payrollRunDate)`:
    - [ ] Move status from "approved" → "settled"
    - [ ] Decrement IkbAccount.currentBalance by requestedAmount
    - [ ] Send mutation to payroll-engine-nl (fiscal calculation + payment instruction)
    - [ ] Write audit trail
- [ ] State machine validation: concept → submitted → (approved | rejected) → settled (or reversed)
- [ ] Approval workflow: line manager (default), HR director for training >€2500
- [ ] Tests:
  - [ ] Submission with approval (REQ-004.1)
  - [ ] Approval + notification (REQ-004.2)
  - [ ] Escalation (REQ-004.3)

### T2.6: Approval Workflow & Escalation
- [ ] Implement `IkbApprovalWorkflowService`:
  - [ ] On choice submission:
    - [ ] Determine approver (line manager, HR director, etc.)
    - [ ] Create Task via TasksController with dueDate = now() + 14 days
    - [ ] Dispatch notification
    - [ ] Store Task metadata (choiceId)
  - [ ] Escalation job (daily):
    - [ ] Find all "submitted" choices with daysOverdue ≥ 14
    - [ ] Notify approver: "IKB Approval Overdue"
    - [ ] Find all with daysOverdue ≥ 21
    - [ ] CC HR business partner: "IKB Approval Escalation"
    - [ ] Write audit trail
  - [ ] Approval rules:
    - [ ] Training ≤ €2500 → line manager approval
    - [ ] Training > €2500 → HR director approval
    - [ ] Verlof → line manager approval
    - [ ] All others → line manager approval (configurable per org)
- [ ] Unit tests: approval routing, escalation dates

### T2.7: Fiscal Settlement & Payroll Integration
- [ ] Implement `IkbSettlementService`:
  - [ ] `settleChoice(choiceId, payrollRunDate)`:
    - [ ] Fetch choice + resolve catalogItem + fiscal rule
    - [ ] Calculate gross-up, net impact, loonheffing
    - [ ] Build payroll mutation:
      ```json
      {
        "type": "IKB_CHOICE_SETTLEMENT",
        "employeeId": "...",
        "amount": 750.00,
        "category": "EXTRA_SALARIS" | "VERLOF" | "TRAINING" | ...,
        "wkrCategory": "nihilwaardering",
        "loonheffingRate": 36.55,
        "effectiveDate": "2026-05-01",
        "source": "ikb"
      }
      ```
    - [ ] Send to payroll-engine-nl via HTTP API or message queue
    - [ ] Store payrollMutationRef
    - [ ] Write audit trail
  - [ ] Batch settlement: collect all approved choices for a payroll run, settle in bulk
  - [ ] Tests:
    - [ ] Salaris bijzonder tarief (REQ-005.1)
    - [ ] Fiets nihilwaardering (REQ-005.2)
    - [ ] WKR vrije-ruimte consumption (REQ-005.3)

### T2.8: Verlof Uitruil Integration
- [ ] Implement `IkbVerlofService`:
  - [ ] `validateVerlofPurchase(empId, cao, days)`:
    - [ ] Lookup CAO max purchase limit
    - [ ] Fetch current year's verlof purchases from this app
    - [ ] Total purchased + requested ≤ limit → pass
    - [ ] Otherwise → return error with remaining allowance
  - [ ] On choice approval (catalogue category = "verlof"):
    - [ ] Calculate hourly rate frozen at approval (from payroll-engine-nl salary data)
    - [ ] Call verlof-administratie API:
      ```
      POST /verlof/balance/{empId}/add
      {
        "days": 5,
        "source": "ikb",
        "costPerDay": 228.00,
        "frozenAt": approvalDate
      }
      ```
    - [ ] Store response (receipt)
    - [ ] Write audit trail
  - [ ] Year-end job: query unconsumed verlof from verlof-administratie
    - [ ] Convert back to IKB budget: unused_days * costPerDay
    - [ ] Credit IkbAccount.currentBalance
    - [ ] Remove from verlof-administratie
- [ ] Tests:
  - [ ] Leave purchase validation (REQ-006.1)
  - [ ] Exceed limit error (REQ-006.2)
  - [ ] Unused leave reversal (REQ-006.3)

### T2.9: Uitruil Windows & Scheduling
- [ ] Implement `IkbWindowService`:
  - [ ] Store window configuration in app settings:
    ```json
    {
      "model": "quarterly" | "always_open",
      "freezePeriods": [{ "start": "2026-12-21", "end": "2026-12-31" }],
      "quarterlyWindows": [
        { "quarter": 1, "openDate": "2026-01-01", "closeDate": "2026-03-15" },
        ...
      ]
    }
    ```
  - [ ] `canSubmitChoice(empId, date)` → boolean:
    - [ ] Check if date is in freeze period → false
    - [ ] Check window model (quarterly vs. always_open)
    - [ ] If quarterly, find next open window
    - [ ] Return boolean + nextWindowDate
  - [ ] On choice submission:
    - [ ] If can submit: status = "submitted"
    - [ ] If window closed: status = "concept", effectiveDate = nextWindowDate
    - [ ] If freeze: reject with error + nextWindowDate
  - [ ] Batch processor (runs at window open):
    - [ ] Find all concepts scheduled for this window
    - [ ] Move to "submitted" status
    - [ ] Trigger approvals
- [ ] Tests:
  - [ ] Quarterly model (REQ-007.1)
  - [ ] Always-open model (REQ-007.2)
  - [ ] Freeze period (REQ-007.3)

### T2.10: Year-End Close & Payout
- [ ] Implement `IkbYearEndService`:
  - [ ] `runYearEndClose(cutoffDate = Nov 30)`:
    - [ ] Query all IkbAccounts with status="active" and year < current year
    - [ ] For each account:
      - [ ] residualAmount = currentBalance on cutoffDate
      - [ ] Fetch loonheffingstabel for employee
      - [ ] loonheffing = residualAmount * rate (e.g., 36.55%)
      - [ ] netAmount = residualAmount * (1 - rate)
      - [ ] Create IkbPayout
      - [ ] Send mutation to payroll-engine-nl for December run
      - [ ] Set account.status = "closed"
      - [ ] Write audit trail
  - [ ] Handle mid-year terminations:
    - [ ] Subscribe to employee:terminated event
    - [ ] Trigger immediate payout (not delayed to 30 Nov)
  - [ ] Handle transfers (Rijk → Gemeente):
    - [ ] Check CAO portability rules → non-portable
    - [ ] Generate payout
    - [ ] Open new account under new CAO (openingBalance = 0)
    - [ ] Write audit trail with transfer reason
  - [ ] Idempotency: keyed by year (re-run same year → skip existing payouts)
- [ ] Tests:
  - [ ] Standard year-end payout (REQ-008.1)
  - [ ] Mid-year termination (REQ-008.2)
  - [ ] CAO transfer (REQ-008.3)

### T2.11: Audit Logging & Data Retention
- [ ] Implement `IkbAuditService`:
  - [ ] Wrap all state-changing operations (submit, approve, settle, payout) with:
    - [ ] `auditLog(entityId, action, actor, beforeSnapshot, afterSnapshot, ip, metadata)`
    - [ ] Write immutable entry (append-only, no UPDATE/DELETE)
    - [ ] Set retention policy: expiryDate = now() + 7 years
    - [ ] Use AuditTrailService from platform (no custom logging)
  - [ ] Audit scope: IkbAccount, IkbAccrual, IkbChoice, IkbPayout, IkbCatalogItem config changes
- [ ] Data retention job:
  - [ ] Auto-delete audit entries after expiryDate expires
  - [ ] Respect legal holds (if any)
- [ ] Tests:
  - [ ] Immutable entries (REQ-010.1)

### T2.12: DSR & Tax Export
- [ ] Implement `IkbExportService`:
  - [ ] `exportDataSubjectRequest(empId, format="json")`:
    - [ ] Gather all IkbAccount, IkbAccrual, IkbChoice, IkbPayout for the employee
    - [ ] Gather full audit trail
    - [ ] Return JSON bundle with metadata + data sections
    - [ ] Write audit log: action="DSR_EXPORT", actor=requesting_staff_id
    - [ ] Compliance: respond within 30 days
  - [ ] `exportBelastingdienstYear(year, empId=optional)`:
    - [ ] Generate SBR-compliant XML
    - [ ] Sections: accruals, choices (with WKR category), payout
    - [ ] Include manifest with SHA-256 hash
    - [ ] Write audit log: action="TAX_EXPORT"
- [ ] Tests:
  - [ ] DSR export completeness (REQ-010.2)
  - [ ] Belastingdienst SBR format (REQ-010.3)

---

## Phase 3: Frontend & User Interface

### T3.1: Employee Dashboard Page
- [ ] Create `views/DashboardPage.vue`:
  - [ ] Load current IkbAccount + balance
  - [ ] Render **Balance Card**:
    - [ ] Current balance with Dutch locale formatting (€1.523,75)
    - [ ] Trend arrow (▲ +€445.78 since last month)
  - [ ] Render **Projected Year-End Card**:
    - [ ] Calculate: remaining months * avg accrual
    - [ ] Display projected balance with caveat ("assumes no new choices")
  - [ ] Render **Accrual History Chart**:
    - [ ] 12-month bar chart (ApexCharts)
    - [ ] Each bar = monthly accrual amount
    - [ ] Tooltip on hover (date + amount)
  - [ ] Render **Choices List** (`CnDataTable`):
    - [ ] Columns: requestedDate, catalogItemDisplayName_nl, requestedAmount, status, actions
    - [ ] Filters: All / Pending / Approved / Rejected
    - [ ] Status badges with icons (✓ approved, ⏳ pending, ✗ rejected)
  - [ ] CTA Button: "Simuleer een nieuwe keuze"
  - [ ] Download button: "Download jaaroverzicht [year]"
  - [ ] Caching: balance queries cached 5 minutes (invalidate on edit)
  - [ ] Performance: load < 2 seconds at 200k employees
- [ ] Unit tests: balance calculations, formatting, filtering
- [ ] Accessibility tests: WCAG 2.2 AA (screen reader, keyboard nav)

### T3.2: Simulation Modal
- [ ] Create `modals/IkbSimulationModal.vue`:
  - [ ] **Catalog Picker**: dropdown with filter by category
  - [ ] **Amount Input**: min/max validation from catalogItem
  - [ ] **Real-time Preview**:
    - [ ] Display gross deduction, net impact, loonheffing
    - [ ] WKR category badge (nihil / vrijgesteld / vrije-ruimte / belast)
    - [ ] "Warning: WKR overshoot" badge if applicable
    - [ ] Document requirement indicator (red if missing & required)
  - [ ] **Document Upload** (if requiresDocument):
    - [ ] File input with acceptance rules
    - [ ] Preview uploaded document
  - [ ] **Submit Button**:
    - [ ] Disabled if balance insufficient, document missing, etc.
    - [ ] Loading spinner on submit
  - [ ] **Error Handling**:
    - [ ] Display balance shortfall with projected date
    - [ ] Display WKR warning with headroom data
  - [ ] Integration: calls POST /hrmq/ikb/simulate (dry-run) for preview
  - [ ] On final submit: POST /hrmq/ikb/choices (persistence)
- [ ] Tests: all validation scenarios from REQ-003

### T3.3: Approval Inbox (Manager)
- [ ] Create `views/ApprovalInboxPage.vue`:
  - [ ] List of pending IkbChoices assigned to the logged-in manager
  - [ ] Columns: employee name, catalogItem, amount, status, days-pending
  - [ ] Sort by daysOverdue descending (oldest first)
  - [ ] Batch actions: "Approve Selected", "Reject Selected"
  - [ ] Single choice actions: "Approve", "Reject" (opens modal)
- [ ] Create `modals/ApprovalModal.vue`:
  - [ ] Display choice details (employee, item, amount, WKR category)
  - [ ] Show fiscal preview (gross, net, tax impact)
  - [ ] Approve button
  - [ ] Reject button + textarea for rationale
  - [ ] Template rationales (quick-select) for common reasons
  - [ ] Submit: calls POST /hrmq/ikb/choices/{id}/approve or /reject
- [ ] Load time: < 1 second for 50 pending choices
- [ ] Tests: approval routing, notifications on approve/reject

### T3.4: Jaaroverzicht PDF Generation
- [ ] Implement `JaaroverzichtPdfGenerator`:
  - [ ] Call POST /hrmq/ikb/accounts/{id}/jaaroverzicht-pdf
  - [ ] Generate PDF with sections:
    1. Title page (employee name, year, IKB branding)
    2. Summary (opening balance, total accrued, total paid out)
    3. Accrual ledger (month-by-month table)
    4. Uitruil history (all choices with status + dates)
    5. Year-end payout (residual, gross, net, tax)
  - [ ] Sign PDF with PAdES-B-T (long-term validity)
  - [ ] Return to browser for download
  - [ ] File name: "IKB-Jaaroverzicht-[YEAR]-[NAME]-[DATE].pdf"
- [ ] Tests: PDF structure, data completeness, signature validity

### T3.5: Admin Dashboard (WKR Monitoring)
- [ ] Create `views/AdminDashboardPage.vue` (HR admin only):
  - [ ] **WKR Headroom Summary**:
    - [ ] Current consumption: X.XX%
    - [ ] Limit: 1.92%
    - [ ] Remaining headroom: Y.YY%
    - [ ] Color indicator (green <0.5%, yellow 0.5-1%, red >1%)
  - [ ] **Pending Approvals**:
    - [ ] Count of submitted but unapproved choices
    - [ ] Oldest pending choice (days overdue)
    - [ ] Link to ApprovalInboxPage
  - [ ] **WKR-Risk Choices**:
    - [ ] List choices that would trigger WKR overshoot if approved
    - [ ] Sortable by potential impact
    - [ ] Quick actions: "Approve with warning", "Reject"
  - [ ] **CAO Configuration Panel**:
    - [ ] Current percentages (Rijk, Gemeenten)
    - [ ] Schedule new percentage (if CAO renegotiated)
    - [ ] View audit trail of percentage changes
- [ ] Tests: WKR calculation, warning threshold, color logic

---

## Phase 4: Integration & Testing

### T4.1: Payroll Engine Integration
- [ ] Define mutation API contract with payroll-engine-nl:
  - [ ] Endpoint: `POST /payroll/mutations`
  - [ ] Payload: IkbChoice settlement (category, amount, loonheffing rule, effectiveDate)
  - [ ] Response: mutationId for tracking
  - [ ] Retry logic: exponential backoff, max 3 attempts over 24 hours
  - [ ] Notify IKB on payroll mutation failure
- [ ] Implement `PayrollIntegrationService`:
  - [ ] Send mutation on choice settlement
  - [ ] Send monthly accrual informational update (no payment, for audit)
  - [ ] Send year-end payout for December run
  - [ ] Handle async responses: store mutationRef for tracking
- [ ] Tests: mutation payload format, retry behavior, error handling

### T4.2: Employee Master Integration
- [ ] Subscribe to lifecycle events from employee-master:
  - [ ] employee:hired → initialize IkbAccount if within payroll-eligible profiles
  - [ ] employee:terminated → trigger immediate payout
  - [ ] employee:cao-changed → handle CAO transition (transfer logic REQ-008.3)
  - [ ] employee:salary-updated → cache invalidation (balance recalculation)
- [ ] Fetch salary data for accrual calculations:
  - [ ] Sync monthly: pensionable salary, bovenwettelijke componenten
  - [ ] Loonheffingstabel lookup for tax calculations
- [ ] Tests: event handling, salary sync, CAO transitions

### T4.3: Verlof Administratie Integration
- [ ] API contract with verlof-administratie:
  - [ ] `POST /verlof/balance/{empId}/add` — credit leave on approval
  - [ ] `GET /verlof/balance/{empId}` — query balance (for year-end rollback)
  - [ ] `DELETE /verlof/balance/{empId}/line/{transactionId}` — reverse on unconsumed reversal
- [ ] Implement `VerlofIntegrationService`:
  - [ ] Sync hourly rate at approval time (frozen cost per day)
  - [ ] Sync unconsumed balance at year-end (calculate reversal amount)
- [ ] Tests: balance credit, leave limit validation, year-end reversal

### T4.4: Nextcloud Notifications & Tasks
- [ ] Use NotificationService for:
  - [ ] Approval request (to manager)
  - [ ] Approval outcome (to employee)
  - [ ] Escalation reminders (to manager + HR BP)
- [ ] Use TasksController for:
  - [ ] Approval task creation (dueDate = now() + 14d)
  - [ ] Mark complete on approval/rejection
- [ ] Tests: notification dispatch, task lifecycle

### T4.5: Unit Tests (Backend)
- [ ] `IkbAccountService`: balance calculations, freeze/close transitions
- [ ] `IkbAccrualService`: accrual calculations, proration, suppression, idempotency
- [ ] `IkbCaoConfigService`: percentage defaults, no retroactive recalc
- [ ] `IkbFiscalSimulationService`: all WKR categories, validations
- [ ] `IkbChoiceService`: submission, approval, state machine
- [ ] `IkbApprovalWorkflowService`: routing, escalation, notifications
- [ ] `IkbSettlementService`: payroll mutation creation
- [ ] `IkbVerlofService`: leave purchase validation, limits, reversal
- [ ] `IkbWindowService`: quarterly/always-open, freeze periods
- [ ] `IkbYearEndService`: payout, termination, transfers
- [ ] `IkbAuditService`: immutable logging, retention
- [ ] `IkbExportService`: DSR completeness, SBR format
- [ ] Coverage goal: ≥85% for business logic, ≥70% for helpers

### T4.6: Integration Tests
- [ ] Accrual run + payroll sync:
  - [ ] Generate accrual → verify balance incremented → verify payroll mutation queued
- [ ] Choice submission + approval + settlement:
  - [ ] Submit choice → verify Task created → manager approves → verify payroll mutation → verify balance decremented
- [ ] Year-end close:
  - [ ] Run close job → verify payout created → verify payroll mutation → verify account status = closed
- [ ] CAO transition:
  - [ ] Move employee from Rijk → Gemeente → verify old account closed, new account opened, payout sent
- [ ] Verlof cycle:
  - [ ] Purchase extra leave → verify verlof balance credited → year-end → verify unconsumed reversed
- [ ] Tests should use mock payroll-engine-nl and employee-master
- [ ] Expected runtime: < 5 minutes per integration test suite

### T4.7: Browser / E2E Tests
- [ ] Dashboard page:
  - [ ] Load balance + accrual history chart
  - [ ] Filter choices by status
  - [ ] Download jaaroverzicht PDF
- [ ] Simulation + submission:
  - [ ] Open catalog picker → select fiets item → enter amount → preview fiscal impact → verify balance sufficiency → submit
  - [ ] Verify choice appears in dashboard list
- [ ] Approval workflow (role-based):
  - [ ] Manager logs in → sees pending approvals → approves/rejects one
  - [ ] Verify employee notification
  - [ ] Verify status in employee dashboard
- [ ] Year-end close (admin):
  - [ ] Admin runs year-end job → verifies payout created → downloads Belastingdienst export
- [ ] Scenarios per persona:
  - [ ] Medewerker (submit, view balance, download jaaroverzicht)
  - [ ] Manager (approve/reject)
  - [ ] HR admin (configure catalog, monitor WKR, view audit)
- [ ] Tool: Playwright or Cypress
- [ ] CI/CD: run on each PR + nightly

### T4.8: Accessibility Tests (WCAG 2.2 AA)
- [ ] Automated (axe, pa11y):
  - [ ] No color-only indicators (icons + text for status)
  - [ ] Contrast ratio ≥4.5:1
  - [ ] Form labels properly associated
- [ ] Manual (screen reader + keyboard):
  - [ ] NVDA/JAWS: all amounts aria-labeled, status badges accessible
  - [ ] Keyboard: Tab order logical, Enter activates buttons, no keyboard traps
  - [ ] Mobile: touch targets ≥44px, zoom not disabled

### T4.9: Performance Testing
- [ ] Load test:
  - [ ] Dashboard page at 200k employees, 1000 concurrent active users
  - [ ] Target: <2s p50, <5s p99 load time
  - [ ] Verify caching (balance queries, catalog)
- [ ] Accrual run:
  - [ ] Generate accruals for 100k employees
  - [ ] Target: <5 min to complete, no database locks
- [ ] Year-end close:
  - [ ] Run for 100k employees with payouts + transfers
  - [ ] Target: <30 min
- [ ] Tool: k6 or Apache JMeter

### T4.10: Security & Compliance
- [ ] Authorization:
  - [ ] Employee can only view own account + approve own manager (if manager)
  - [ ] Line manager can approve direct reports only
  - [ ] HR admin role isolation (cannot escalate privileges)
  - [ ] Test RBAC on all endpoints
- [ ] Input validation:
  - [ ] Amount must be numeric, ≥ minAmount, ≤ maxAmount
  - [ ] Dates must be valid calendar dates, not in past
  - [ ] Status transitions respect state machine
- [ ] Audit logging:
  - [ ] Every choice state change logged
  - [ ] Immutable entries (no UPDATE/DELETE after creation)
  - [ ] Retention policy enforced
- [ ] Data privacy:
  - [ ] PII (names, BSN last 2 digits) in exports only with proper access
  - [ ] DSR export completeness verified
  - [ ] Test with OWASP Top 10 scenarios (SQL injection, XSS, auth bypass)

---

## Phase 5: Documentation & Launch

### T5.1: API Documentation
- [ ] OpenAPI 3.0 spec for all endpoints (proposal.md + design.md reference)
- [ ] POST /hrmq/ikb/choices, GET /hrmq/ikb/accounts/{id}, etc.
- [ ] Include error codes, status codes, examples
- [ ] Publish in app manifest + Nextcloud app store

### T5.2: User Documentation
- [ ] Employee guide: How to check balance, simulate, submit choices, download jaaroverzicht
- [ ] Manager guide: How to approve/reject, understand WKR warnings
- [ ] HR admin guide: Configure catalog, monitor WKR, run year-end, export compliance reports
- [ ] CAO overview: Explain 16.37% vs 17.05%, accrual schedule, max purchase limits
- [ ] Translate to Dutch (primary) + English (secondary)

### T5.3: Admin Configuration
- [ ] CAO setup: Rijk 16.37%, Gemeenten 17.05% (pre-populated)
- [ ] Uitruil window configuration: quarterly vs. always-open
- [ ] Freeze periods: configure by date range
- [ ] Approval rules: map category + amount to approver role
- [ ] Catalog editor: allow per-org customization (e.g., local fitness vendors)
- [ ] WKR limit: set to 1.92% (configurable)

### T5.4: Migration & Data Cleanup
- [ ] If migrating from spreadsheet:
  - [ ] Import historical accruals (2022-2025) for audit trail
  - [ ] Map old employee IDs to employee-master records
  - [ ] Validate balance reconciliation with payroll
- [ ] Run in "pilot mode" with 1 organisation first
- [ ] Monitor for 30 days before full rollout

### T5.5: Launch Checklist
- [ ] [ ] All unit + integration tests passing (≥85% coverage)
- [ ] [ ] Accessibility audit passed (WCAG 2.2 AA)
- [ ] [ ] Performance testing passed (load times, accrual run)
- [ ] [ ] Security audit passed (OWASP, RBAC, audit logging)
- [ ] [ ] Documentation complete (API, user guides, admin config)
- [ ] [ ] Translations complete (Dutch + English)
- [ ] [ ] Pilot organisation onboarded + validated
- [ ] [ ] Backup & disaster recovery tested
- [ ] [ ] Monitoring/alerting configured (accrual failures, payroll sync errors)
- [ ] [ ] Support team trained (FAQ, troubleshooting guides)

---

## Effort Estimates (in story points)

| Phase | Task | Points |
|-------|------|--------|
| 1 | Data Model & Infrastructure | 500 |
| 2 | Backend API & Business Logic | 1200 |
| 3 | Frontend & UI | 350 |
| 4 | Integration & Testing | 400 |
| 5 | Documentation & Launch | 200 |
| **Total** | | **2650** |

(Notes: Estimate assumes access to platform services; no custom UI component building. Payroll integration complexity may add ±100 points depending on external API availability.)

# IKB Rijk & Gemeenten — Specifications

## REQ-001: Monthly Accrual Run

**Description:** The system computes monthly IKB accrual for active accounts, crediting the budget with accrued amount and writing an immutable accrual record.

### Scenario 1.1: Standard accrual (full month)

```
GIVEN
  - An IkbAccount with accountId = "acc-2026-jan-willemse-rijk"
  - Current balance = 1523.75
  - Accrual percentage = 16.37%
  - Pensionable salary on 2026-05-01 = 2850.00
  - Bovenwettelijke componenten (vakantietoeslag, etc.) = 450.00

WHEN
  - The monthly accrual job runs on 2026-06-01 at 06:00 UTC
  - runId = "payroll-run-2026-06"

THEN
  - An IkbAccrual record is created with:
    accrualAmount = (2850.00 + 450.00) * 16.37% / 12 = 445.78 (rounded to 2 decimals)
  - currentBalance incremented to 1523.75 + 445.78 = 1969.53
  - lockedAt = 2026-06-01T06:00:00Z
  - lockedBy = "sys-accrual-job"
  - payrollMutationRef populated with a deterministic reference
  - An immutable AuditLog entry is written with actor="sys-accrual-job", before, after snapshots
```

### Scenario 1.2: Mid-month join (prorated accrual)

```
GIVEN
  - An IkbAccount opened on 2026-05-15 for a new employee
  - Pensionable salary from 2026-05-15 onwards = 2850.00
  - Period 2026-05 has 17 calendar days worked (15th–31st inclusive)
  - May has 31 total days

WHEN
  - The monthly accrual job runs on 2026-06-01

THEN
  - Accrual is prorated: (2850.00 + 450.00) * 16.37% / 12 * (17/31) = 244.44
  - IkbAccrual.metadata.prorationBasis = { "daysWorked": 17, "daysInMonth": 31, "rule": "calendar-days" }
  - currentBalance is incremented by 244.44
  - Audit log notes the proration explicitly
```

### Scenario 1.3: Unpaid leave (suppressed accrual)

```
GIVEN
  - An IkbAccount for an employee on unpaid leave for the entire period 2026-05
  - HR has marked the employee's employment status as "on-unpaid-leave" in employee-master
  - accrualPercentage = 16.37%

WHEN
  - The monthly accrual job runs on 2026-06-01

THEN
  - A zero-amount IkbAccrual is created with:
    accrualAmount = 0.00
    metadata.suppressionReason = "unpaid_leave"
  - currentBalance remains unchanged
  - The gap is auditable (no missing month)
  - Audit log documents the suppression rule
```

---

## REQ-002: CAO-Aware Accrual Percentage

**Description:** The IKB accrual percentage defaults based on the employee's CAO contract and is exposed for transparency. Changes to CAO percentages do not retroactively recalculate prior accruals.

### Scenario 2.1: CAO Rijk initialization

```
GIVEN
  - A new employee hired with CAO Rijk contract
  - An IkbAccount is being opened for year 2026

WHEN
  - The account creation process selects cao="rijk"

THEN
  - accrualPercentage defaults to 16.37
  - A breakdown is stored (optional metadata):
    - vakantietoeslag: 8.0%
    - eindejaarsuitkering: 6.4%
    - levensloopbijdrage equivalent: 1.97%
  - Total = 16.37%
  - The breakdown is exposed in the UI for transparency
  - Audit log records the initial percentage
```

### Scenario 2.2: CAO Gemeenten initialization

```
GIVEN
  - A new employee hired with CAO Gemeenten contract
  - An IkbAccount is being opened for year 2026

WHEN
  - The account creation process selects cao="gemeenten"

THEN
  - accrualPercentage defaults to 17.05
  - Breakdown:
    - vakantietoeslag: 8.0%
    - eindejaarsuitkering: 6.75%
    - bovenwettelijk verlof: 1.5%
    - levensloop: 0.8%
  - Total = 17.05%
  - Matches LOGA-publicatie for 2026
  - Exposed in UI for transparency
```

### Scenario 2.3: CAO renegotiation (no retroactive recalc)

```
GIVEN
  - An IkbAccount with 12 months of accruals at percentage 16.37
  - CAO Rijk renegotiates on 2026-09-01 to new percentage 16.50

WHEN
  - HR admin updates IkbCaoConfig:
    - validFrom: "2026-09-01"
    - newPercentage: 16.50
  - The monthly accrual job runs on 2026-10-01 (after renegotiation)

THEN
  - Accruals for 2026-01 through 2026-08 remain at 16.37 (no retroactive change)
  - Accrual for 2026-10 onwards uses 16.50
  - Audit log records the percentage change and validFrom date
  - Historical accruals are immutable (lockedAt cannot be changed)
```

---

## REQ-003: Uitruil Catalog & Simulation

**Description:** Employees can browse the catalog, select an item + amount, and see a real-time simulation of fiscal impact before persisting.

### Scenario 3.1: Simulation with WKR impact (fiets nihilwaardering)

```
GIVEN
  - A catalog item: code="FIETS-NIHIL", maxAmount=2500.00, wkrCategory="nihilwaardering"
  - An employee requests amount=749.00
  - Their current balance=1523.75
  - Tax rate lookup for their salary band returns loonheffing%=36.55%

WHEN
  - The employee clicks "Simuleer" with amount=749.00

THEN
  - A POST /hrmq/ikb/simulate is made (no persistence)
  - Response includes:
    - grossDeduction: 749.00
    - netImpact: -611.00 (749 * (1 - 36.55%) ≈ 474.75, net cost to employee ≈ 749 - 474.75... clarify gross vs. net)
    - loonheffingDelta: 138.00
    - wkrCategory: "nihilwaardering" (no WKR impact)
    - warningFlags: []
  - No IkbChoice is created; no balance is modified
  - UI displays gross amount, net employee benefit, and WKR classification
```

### Scenario 3.2: Simulation exceeds balance

```
GIVEN
  - An employee's current balance=500.00
  - They request a "extra salaris" uitruil for amount=1000.00
  - Next month's projected accrual=445.78

WHEN
  - They click "Simuleer" with amount=1000.00

THEN
  - POST /hrmq/ikb/simulate returns:
    - error: "INSUFFICIENT_BALANCE"
    - availableBalance: 500.00
    - projectedBalanceNextMonth: 945.78
    - earliestDateSufficient: "2026-06-01"
    - message: "Your balance is insufficient. Try again on 2026-06-01 when projected accrual posts."
  - UI blocks submit button and displays the error with the projected date
```

### Scenario 3.3: Simulation with required document (training)

```
GIVEN
  - A catalog item: code="TRAINING-ERKEND", requiresDocument=true
  - An employee requests amount=2500.00
  - No supporting documents are attached yet

WHEN
  - They click "Simuleer" with amount=2500.00

THEN
  - POST /hrmq/ikb/simulate returns:
    - warning: "DOCUMENT_REQUIRED"
    - documentType: "Factuurdocument of cursusbewijzing"
    - canSubmit: false
  - UI highlights the document upload field in red
  - Submit button is disabled until a document is attached
```

---

## REQ-004: Approval Workflow

**Description:** Uitruil choices requiring approval trigger a task for the configured approver, with notifications and escalation.

### Scenario 4.1: Submission with approval requirement

```
GIVEN
  - A catalog item with requiresApproval=true (e.g., training >€2500)
  - An employee submits an IkbChoice with amount=3000.00, catalogItemId="cat-training-erkend"
  - Their line manager ID = "mgr-284903"
  - HR rule: training > €2500 requires HR Director approval, not line manager

WHEN
  - POST /hrmq/ikb/choices is called with status="submitted"
  - HR's configuration maps "training" + amount>2500 to approverId="hr-director-001"

THEN
  - An IkbChoice is persisted with status="submitted"
  - A Task is created in the Tasks app:
    - title: "Approval: IKB Training Uitruil—€3000"
    - assignedTo: "hr-director-001"
    - dueDate: now() + 14 days
    - metadata.choiceId: <uuid>
  - NotificationService dispatches a "IKB Approval Needed" notification to hr-director-001
  - Audit log records the submission with actor=employee
  - Accrual is NOT decremented yet (pending approval)
```

### Scenario 4.2: Approval by HR admin

```
GIVEN
  - An IkbChoice with id="choice-2026-05-henk-training", status="submitted"
  - Task is assigned to hr-director-001, dueDate=2026-06-03

WHEN
  - HR director calls POST /hrmq/ikb/choices/{id}/approve with approvalRationale="Approved per CAO art. 4.7"

THEN
  - IkbChoice.status = "approved"
  - approverId = "hr-director-001"
  - decisionRationale = "Approved per CAO art. 4.7"
  - approvedAt = 2026-05-23T10:15:00Z
  - The associated Task is marked complete
  - NotificationService sends "IKB Uitruil Approved" to the employee
  - Audit log records approval with actor="hr-director-001"
  - Accrual is frozen until the effectiveDate (not decremented immediately)
```

### Scenario 4.3: Rejection with escalation

```
GIVEN
  - An IkbChoice with id="choice-2026-04-jan-fiets", status="submitted"
  - Submitted 2026-04-18
  - Now 2026-05-09 (21 days later, no action taken)
  - Approver: mgr-284903
  - HR business partner: "hr-bp-001"

WHEN
  - The daily escalation job runs at 2026-05-09T08:00:00Z

THEN
  - Check: daysOverdue = 21 (submitted 2026-04-18, job runs 2026-05-09)
  - daysOverdue > 21 triggers escalation
  - NotificationService sends:
    - To: mgr-284903 — "IKB Approval Overdue: Jan Willemse requested fiets-van-de-zaak on 2026-04-18"
    - To: hr-bp-001 — "CC: IKB Approval Escalation for Jan Willemse (manager: mgr-284903)"
  - Audit log records escalation event
```

---

## REQ-005: Fiscal & WKR Calculation

**Description:** Uitruil settlement applies correct fiscal treatment based on WKR category and employee salary scale.

### Scenario 5.1: Extra salaris (bijzonder tarief)

```
GIVEN
  - An approved IkbChoice: catalogItemId="cat-salaris-extra", amount=500.00
  - wkrCategory="belast"
  - Employee's Loonheffingstabel entry: rate=36.55%
  - EffectiveDate = 2026-06-01 (next payroll run)

WHEN
  - The choice settlement processor executes (on payroll run day)
  - A mutation is sent to payroll-engine-nl

THEN
  - payroll-engine-nl receives a mutation:
    - type: "IKB_CHOICE_SETTLEMENT"
    - amount: 500.00
    - category: "BIJZONDER_TARIEF"
    - loonheffingRate: 36.55%
    - effectiveDate: "2026-06-01"
  - Payroll applies bijzonder tarief (special rate) to calculate take-home
  - Gross-up is added to salary in that payroll period
  - Audit log records settlement
```

### Scenario 5.2: Fiets nihilwaardering (no WKR impact)

```
GIVEN
  - An approved IkbChoice: amount=749.00, wkrCategory="nihilwaardering"
  - CAO Rijk nihlwaardering threshold for fiets = €750

WHEN
  - Settlement processor executes

THEN
  - WKR vrije-ruimte consumption = 0% (nihil classification)
  - No warning to HR controller
  - Audit log documents the classification
  - No fiscal impact (WKR-wise)
  - Employer cost = 0 (employee cost = allocation from IKB budget)
```

### Scenario 5.3: WKR vrije-ruimte overshoot warning

```
GIVEN
  - Year-to-date WKR vrije-ruimte consumption for 2026 = 1.85%
  - Employee's gross wage sum for 2026 YTD = €150,000
  - WKR limit = 1.92%
  - A new IkbChoice is submitted: amount=1200.00, wkrCategory="vrije_ruimte" (fitness)
  - Estimated WKR impact = 1200 / 150000 ≈ 0.8%

WHEN
  - HR admin views GET /hrmq/ikb/admin/wkr-report for 2026

THEN
  - Dashboard shows:
    - Current consumption: 1.85%
    - Headroom remaining: 0.07%
    - Proposed choice impact: +0.8%
    - Total if approved: 2.65% (OVERSHOOT by 0.73%)
  - A warning badge appears on the choice in the approval queue
  - HR controller can still approve, but must acknowledge the overshoot
  - Audit log documents the warning and the decision
```

---

## REQ-006: Verlof Uitruil (Purchase of Leave)

**Description:** Employees can buy additional leave with IKB budget; the leave balance is credited and the cost is frozen at approval time.

### Scenario 6.1: Purchase extra leave (Rijk, max 22 days)

```
GIVEN
  - An employee's current leave balance = 20 days
  - CAO Rijk annual leave entitlement = 20 days
  - CAO Rijk maximum purchase limit = 22 days additional
  - Their hourly rate (frozen at submission) = €28.50/hour
  - Requested amount = 5 additional days (assume 8 hours/day = €1,140.00)

WHEN
  - They submit an IkbChoice:
    - catalogItemId="cat-verlof-extra-dag"
    - amount=1140.00
    - status="submitted"

THEN
  - Validation passes (5 days < 22 day limit)
  - Choice status = "submitted"
  - requires approver = line manager
  - Upon approval (status="approved"):
    - verlof-administratie API is called:
      POST /verlof/balance/{empId}/add
      { "days": 5, "source": "ikb-purchase", "costPerDay": 228.00, "frozenAt": approvalDate }
    - Employee's leave balance in verlof-administratie increments to 25 days
    - IkbChoice.effectiveDate is set to the next payroll run
    - Audit log documents the leave credit
```

### Scenario 6.2: Exceeds leave purchase limit

```
GIVEN
  - CAO Gemeenten maximum purchase = 10 additional days
  - Employee already purchased 8 days in 2026
  - They attempt to purchase 5 more days (total = 13 days)

WHEN
  - POST /hrmq/ikb/choices with amount for 5 days, catalogItemId="cat-verlof-extra-dag"

THEN
  - Validation fails:
    - currentPurchased = 8 days
    - limit = 10 days
    - remaining = 2 days
    - Error: "LEAVE_PURCHASE_LIMIT_EXCEEDED. You have purchased 8 of 10 allowed days. Maximum additional: 2 days."
    - Reference to CAO article provided
  - Choice is NOT persisted
```

### Scenario 6.3: Unused leave conversion at year-end

```
GIVEN
  - An employee purchased 3 days of extra leave via IKB in 2026
  - They consumed 1 day
  - 2 days remain unconsumed as of 2026-12-31

WHEN
  - The year-end close job runs on 2026-11-30 (cut-off)

THEN
  - verlof-administratie is queried for unconsumed days: 2
  - These 2 days are converted back to IKB budget:
    - costPerDay (frozen at approval) = €228.00
    - IKB credit = 2 * €228.00 = €456.00
  - An IkbAccrual-like adjustment is made:
    - type: "LEAVE_CONVERSION_REVERSAL"
    - amount: €456.00
    - currentBalance is incremented
  - The unused leave is removed from verlof-administratie
  - Audit log documents the conversion
```

---

## REQ-007: Quarterly and Annual Uitruil Windows

**Description:** Organisations can configure uitruil submission windows; out-of-window submissions are queued for the next window.

### Scenario 7.1: Quarterly model (four windows)

```
GIVEN
  - Organisation has configured quarterly windows:
    - Q1: 2026-01-01 to 2026-03-15
    - Q2: 2026-04-01 to 2026-06-15
    - Q3: 2026-07-01 to 2026-09-15
    - Q4: 2026-10-01 to 2026-12-15
  - Current date = 2026-03-20 (outside any window)
  - Next window opens 2026-04-01

WHEN
  - An employee submits an IkbChoice on 2026-03-20

THEN
  - Choice is created with status="concept"
  - effectiveDate is automatically set to "2026-04-01" (next window open date)
  - UI shows: "Your choice will be processed on 2026-04-01 when the Q2 window opens."
  - Employee can edit until 2026-03-31 (last day before window)
  - On 2026-04-01, a batch processor moves all queued choices to "submitted" status
  - Audit log documents the queuing
```

### Scenario 7.2: Always-open model (continuous)

```
GIVEN
  - Organisation has configured uitruil model = "always_open"
  - No fixed windows; choices are processed in the next payroll run

WHEN
  - An employee submits an IkbChoice on any date

THEN
  - Choice is created with status="submitted" immediately
  - effectiveDate = next_payroll_run_date (e.g., end of month)
  - No queueing delay
  - No UI message about windows
```

### Scenario 7.3: Freeze period (December)

```
GIVEN
  - Organisation has configured freeze period:
    - Start: 2026-12-21
    - End: 2026-12-31
  - Current date = 2026-12-22

WHEN
  - An employee attempts to submit an IkbChoice

THEN
  - Validation rejects with error:
    - "FREEZE_PERIOD_ACTIVE"
    - "Uitruil window is frozen for year-end close. Next window opens 2027-01-01."
  - Choice is NOT created
  - UI displays a banner with the freeze period dates
```

---

## REQ-008: Year-End Residual Payout

**Description:** On a configurable cut-off date (default 30 November), remaining balance is paid out in the December payroll run as cash.

### Scenario 8.1: Standard year-end payout (active employee)

```
GIVEN
  - An IkbAccount with:
    - accountId = "acc-2025-jan-willemse-rijk"
    - year = 2025
    - currentBalance = 487.50 on 2025-11-30
    - status = "active"

WHEN
  - Year-end close job runs on 2025-11-30T02:00:00Z

THEN
  - An IkbPayout is created:
    - residualAmount = 487.50
    - grossAmount = 487.50
    - A Loonheffingstabel lookup retrieves the employee's rate: 36.55%
    - netAmount = 487.50 * (1 - 0.3655) = 309.57 (rounded)
    - loonheffing = 487.50 - 309.57 = 177.93
    - payrollMutationRef created
  - A mutation is sent to payroll-engine-nl:
    - type: "IKB_PAYOUT"
    - grossAmount: 487.50
    - loonheffing: 177.93
    - effectiveDate: "2025-12-01" (December payroll)
  - IkbAccount.status = "closed" (no more accruals or submissions)
  - Audit log documents the payout calculation
```

### Scenario 8.2: Mid-year offboarding (employee leaves)

```
GIVEN
  - An employee is terminated effective 2026-06-30
  - Their IkbAccount has balance = 234.50 on 2026-06-30
  - offboarding in employee-master is marked "FINALISED" on 2026-07-05

WHEN
  - Termination event is published by employee-master
  - IKB service subscribes to "employee:terminated" event

THEN
  - An immediate IkbPayout is generated:
    - residualAmount = 234.50 (no further accrual after 2026-06-30)
    - Payout is included in the final payroll run (e.g., June or July depending on payroll cadence)
  - IkbAccount.status = "closed"
  - No more IkbChoice submissions are allowed
  - Audit log documents the early payout reason: "employee_termination"
```

### Scenario 8.3: Mid-year transfer (Rijk → Gemeente)

```
GIVEN
  - An employee transfers from a Rijks organisation to a gemeente on 2026-07-01
  - They have an IkbAccount (cao="rijk") with balance = 1234.50 on 2026-06-30
  - Both organisations use the same hrmq instance (multi-tenancy)

WHEN
  - Employee transfer is confirmed in employee-master

THEN
  - Check CAO portability rules:
    - CAO Rijk and CAO Gemeenten do NOT allow balance carry-over (different schemes)
  - Action:
    - Residual IkbPayout is generated: 1234.50
    - Included in final Rijks payroll (June)
    - Old account (cao="rijk") status = "closed"
    - A new IkbAccount is opened for the employee under gemeente CAO with openingBalance = 0.00
    - First accrual under nieuwe CAO begins 2026-07-01
  - Audit log documents the transfer and non-portability reason
```

---

## REQ-009: Employee Self-Service Dashboard

**Description:** Medewerkers have a clear, accessible IKB dashboard showing balance, history, and choices.

### Scenario 9.1: Dashboard loads and displays balance

```
GIVEN
  - An employee with an IkbAccount (current balance = 1523.75)
  - 6 months of accrual history
  - 2 submitted choices (1 approved, 1 pending)

WHEN
  - They navigate to Salarissen › IKB

THEN
  - Dashboard renders with sections:
    1. **Current Balance Card**:
       - "€1.523,75" (formatted Dutch locale)
       - Trend indicator (▲ +€445.78 since last month)
    2. **Projected End-of-Year Balance**:
       - Calculates remaining months * average monthly accrual
       - Assumes no new choices submitted
       - "€2.187,14 projected for 31 December 2026"
    3. **Accrual History Chart**:
       - 12-month bar chart, each bar = monthly accrual amount
       - Current year highlighted
       - Tooltip on hover shows date + amount
    4. **Submitted Choices List**:
       - Table with columns: Date, Item, Amount, Status, Action
       - Filters: All / Pending / Approved / Rejected
    5. **CTA Button**:
       - "Simuleer een nieuwe keuze" → opens IkbSimulationModal
  - All text in Dutch (displayName_nl from catalog)
  - Load time < 2 seconds (cached balance data)
```

### Scenario 9.2: Screen reader accessibility (WCAG 2.2 AA)

```
GIVEN
  - An employee using NVDA / JAWS screen reader
  - Dashboard with balance card, history chart, and choices list

WHEN
  - They navigate the IKB dashboard

THEN
  - All amounts have aria-labels:
    - "Current balance: one thousand five hundred twenty-three euros seventy-five cents"
  - Chart bars have aria-label + tabindex=0, navigable with arrow keys
  - Form labels associated with inputs:
    - <label for="amount-input">How much would you like to allocate?</label>
  - Status badges have aria-label:
    - <span aria-label="Approved on 18 April 2026">✓ Approved</span>
  - All interactive elements keyboard-accessible (Tab, Enter)
  - Focus order logical (top to bottom)
  - Color not sole indicator (status includes icon + text)
  - Contrast ratio ≥ 4.5:1 (AA)
```

### Scenario 9.3: jaaroverzicht PDF download

```
GIVEN
  - An employee with 12 months of accruals and 3 submitted choices in 2025

WHEN
  - They click "Download jaaroverzicht 2025" button on the dashboard

THEN
  - A POST /hrmq/ikb/accounts/{id}/jaaroverzicht-pdf is called
  - PDF is generated with sections:
    1. Title page: "IKB Jaaroverzicht 2025 — [Employee Name]"
    2. Summary: opening balance, total accrued, total paid out
    3. Accrual ledger: month-by-month table (period, salary, accrual amount, balance)
    4. Uitruil history: all choices with request date, amount, status, approval date
    5. Year-end payout: residual amount, gross, net, tax withheld
    6. Footer: generated date, employee BSN (last 2 digits visible), org name
  - PDF is signed with PAdES-B-T (long-term validity, timestamped)
  - File name: "IKB-Jaaroverzicht-2025-[Name]-[Date].pdf"
  - Downloaded to browser cache
```

---

## REQ-010: Audit & 7-Year Retention

**Description:** Every state change is logged immutably and retained for 7 years per AVG/AWR compliance.

### Scenario 10.1: Immutable audit trail on choice approval

```
GIVEN
  - An IkbChoice with id="choice-2026-05-henk-training" is approved by hr-director-001

WHEN
  - POST /hrmq/ikb/choices/{id}/approve is called on 2026-05-23T10:15:00Z
  - Request IP = 203.0.113.45

THEN
  - AuditTrailService.log() is called with:
    - entityId: "choice-2026-05-henk-training"
    - action: "APPROVED"
    - actor: "hr-director-001"
    - beforeSnapshot: { status: "submitted", approverId: null, approvedAt: null, ... }
    - afterSnapshot: { status: "approved", approverId: "hr-director-001", approvedAt: "2026-05-23T10:15:00Z", ... }
    - ip: "203.0.113.45"
    - timestamp: "2026-05-23T10:15:00Z"
  - The log entry is written to an immutable append-only log (DB constraint: no UPDATE/DELETE)
  - A retention policy is attached: expiryDate = 2033-05-23 (7 years)
  - The entry cannot be modified or deleted until expiryDate
```

### Scenario 10.2: AVG data subject access request

```
GIVEN
  - An employee requests access to their personal IKB data (AVG art. 15)
  - Request ID: "avga-2026-05-emp-284932"
  - Requestor: emp-284932 (verified identity)

WHEN
  - HR receives the request and calls:
    POST /hrmq/ikb/export-dsr?empId=emp-284932&format=json

THEN
  - API retrieves all related entities:
    - IkbAccount(s) for the employee (2025, 2026)
    - All IkbAccrual entries
    - All IkbChoice entries
    - IkbPayout entries
    - Full audit trail for the above
  - JSON bundle structure:
    {
      "metadata": {
        "requestId": "avga-2026-05-emp-284932",
        "generatedAt": "2026-05-23T11:30:00Z",
        "employeeId": "emp-284932",
        "dataIncluded": ["accounts", "accruals", "choices", "payouts", "auditLog"]
      },
      "accounts": [...],
      "accruals": [...],
      "choices": [...],
      "payouts": [...],
      "auditLog": [
        {
          "timestamp": "2026-05-23T10:15:00Z",
          "action": "APPROVED",
          "actor": "hr-director-001",
          "entity": "IkbChoice",
          "entityId": "choice-2026-05-henk-training",
          "before": {...},
          "after": {...}
        },
        ...
      ]
    }
  - Audit log records the DSR export:
    - action: "DSR_EXPORT"
    - actor: "hr-staff-001" (person who processed request)
    - exportedData: "full_ikb_history"
  - Response is delivered within 30 days (GDPR compliance)
```

### Scenario 10.3: Tax authority export (Belastingdienst)

```
GIVEN
  - An auditor requests IKB administratie for year 2025 for a specific employee
  - Year = 2025
  - Employee = emp-284932

WHEN
  - HR admin calls:
    POST /hrmq/ikb/export-belastingdienst?year=2025&empId=emp-284932

THEN
  - API generates export in SBR format:
    - Envelope: metadata (org KVK, year, employee BSN, export date)
    - Ledger: monthly accruals with tax base, grossing-up factors, WKR impact
    - Choices: all uitruil-keuzes with fiscal category + withholding impact
    - Payout: year-end residual with loonheffing calculation
    - Manifest: SHA-256 hash of all data (tamper-evidence)
  - XML structure:
    ```xml
    <IKBAdministratie year="2025" empBSN="..." exportDate="...">
      <Accruals>
        <Accrual period="2025-01" amount="445.78" basis="..."/>
        ...
      </Accruals>
      <Choices>
        <Choice date="2025-04-15" item="FIETS" amount="749.00" wkr="nihil"/>
        ...
      </Choices>
      <Payout residual="487.50" loonheffing="177.93"/>
      <Manifest hash="sha256:abc123..."/>
    </IKBAdministratie>
    ```
  - Audit log records the export:
    - action: "TAX_EXPORT"
    - recipient: "Belastingdienst"
    - year: 2025
    - manifestHash included
  - File is retained indefinitely for audit trail
```

---

## Data Validation Rules

### IkbAccount
- `accrualPercentage` must be > 0 and ≤ 50 (sanity check)
- `pensionableBase` must be ≥ 0
- `currentBalance` must be ≥ 0 (no negative budgets)
- `year` must be current or future year (no backdating)

### IkbChoice
- `requestedAmount` must be ≥ catalogItem.minAmount and ≤ catalogItem.maxAmount
- `requestedAmount` must not exceed account.currentBalance (checked in simulate, enforced on submit)
- Status transitions must follow state machine: concept → submitted → (approved|rejected) → settled (or reversed)
- Once `lockedAt` is set, no modification allowed

### IkbAccrual
- `accrualAmount` must be ≥ 0
- Once `lockedAt` is set, immutable (no updates or deletes)
- `period` must be unique per account (no duplicate months)

### IkbCatalogItem
- `code` must be unique per cao + category
- `minAmount` must be ≤ `maxAmount`
- `validFrom` ≤ `validUntil` (or `validUntil` is null for open-ended)

---

## Error Codes

| Code | HTTP | Message | Scenario |
|------|------|---------|----------|
| `INSUFFICIENT_BALANCE` | 400 | Balance insufficient for requested amount | REQ-003.2 |
| `DOCUMENT_REQUIRED` | 400 | Required document not attached | REQ-003.3 |
| `LEAVE_PURCHASE_LIMIT_EXCEEDED` | 400 | Exceeds CAO leave purchase limit | REQ-006.2 |
| `FREEZE_PERIOD_ACTIVE` | 400 | Uitruil window frozen | REQ-007.3 |
| `INVALID_STATUS_TRANSITION` | 400 | Choice status transition not allowed | State machine violation |
| `CHOICE_LOCKED` | 409 | Choice cannot be edited (already settled/reversed) | Temporal constraint |
| `ACCRUAL_ALREADY_LOCKED` | 409 | Accrual entry is immutable | REQ-010 |
| `UNAUTHORIZED_APPROVAL` | 403 | User not authorized to approve this choice | RBAC violation |
| `CONFIGURATION_MISSING` | 500 | CAO or fiscal rule not configured | REQ-002 |
| `WKR_LIMIT_EXCEEDED` | 400 | WKR vrije-ruimte exceeded (warning, not fatal) | REQ-005.3 |

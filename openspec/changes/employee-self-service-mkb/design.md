---
status: draft
---

# Design: Employee Self-Service voor MKB

**Change ID:** employee-self-service-mkb  
**Status:** Design Phase  
**Created:** 2026-05-23

## Architecture Overview

The employee self-service portal is a **read-mostly** application with scoped write access. It does not introduce new domain entities in the core HR model; instead, it provides a constrained UI and API gateway on top of existing `Employee`, `Payslip`, `LeaveRequest`, `Contract`, `Expense`, and `TrainingRequest` entities.

The portal introduces three **supporting entities** for session management, magic-link flows, and mutation approval workflows.

### Technology Stack

- **Frontend:** Vue 3 + @conduction/nextcloud-vue components (CnDetailPage, CnFormDialog, CnPagination, etc.)
- **Backend:** Nextcloud PHP + OpenRegister for data persistence
- **Authentication:** DigiD (Logius SAML), Nextcloud SSO (OAuth), Magic-link tokens (email-based)
- **PDF Viewing:** PDFjs or similar mobile-friendly viewer (inline)
- **Notifications:** Nextcloud Notifications + email
- **OCR:** Local qwen3.5 LLM or cloud API (e.g., Google Vision)

### Deployment Model

- **Separate app** with separate URL/domain from main HRMQ admin app
- **Shared backend** for data access (OpenRegister, employee-master, payroll-engine)
- **Independent brand context** to avoid UI confusion between admin + self-service

## Data Model

### Entities

The portal operates on six **existing domain entities**:
1. `Employee` — Core HR record (read-mostly; scoped writes to NAW and approval-gated fields)
2. `Payslip` — Monthly payroll summary + PDF (read-only)
3. `LeaveRequest` — Vacation/sick/WVP requests (read + submit)
4. `Contract` — Employment agreement (read-only via PDF)
5. `Expense` — Reimbursement claim (read + submit via expense-reimbursement app)
6. `TrainingRequest` — Requested training (read + submit via training-request app)
7. `PersonalDevelopmentObjective` (POP) — Performance goal (read + update progress)

### Portal-Specific Entities

#### 1. SelfServiceSession

Represents an authenticated session within the portal. Created on successful authentication; expires on inactivity or age.

**Schema:**
```json
{
  "register": "SelfServiceSession",
  "schema": "SelfServiceSession",
  "properties": {
    "employee_id": {
      "type": "string",
      "description": "Foreign key to Employee register/schema",
      "required": true
    },
    "auth_method": {
      "type": "string",
      "enum": ["digid", "nc_sso", "magic_link"],
      "description": "Authentication method used to create this session",
      "required": true
    },
    "auth_subject": {
      "type": "string",
      "description": "Subject identifier: BSN-hash (DigiD), NC uid (SSO), email (magic-link)",
      "required": true
    },
    "session_token": {
      "type": "string",
      "description": "Opaque session token (256-bit random); sent as HTTP-only cookie",
      "required": true
    },
    "started_at": {
      "type": "string",
      "format": "date-time",
      "description": "Session creation timestamp",
      "required": true
    },
    "last_activity_at": {
      "type": "string",
      "format": "date-time",
      "description": "Last API call or page interaction timestamp",
      "required": true
    },
    "device_fingerprint": {
      "type": "object",
      "description": "Device metadata for fraud detection: user_agent, ip_address (anonymized), screen dimensions",
      "properties": {
        "user_agent": { "type": "string" },
        "ip_address_hash": { "type": "string" },
        "screen_width": { "type": "integer" },
        "screen_height": { "type": "integer" }
      }
    },
    "expires_at": {
      "type": "string",
      "format": "date-time",
      "description": "Idle timeout (30 min) or absolute timeout (8 hours), whichever comes first",
      "required": true
    }
  }
}
```

**Constraints:**
- Session expires if `expires_at` < now
- Renewed on activity (PATCH `last_activity_at`)
- Can only be created via auth endpoints (not direct POST)

---

#### 2. MagicLinkToken

Represents a single-use magic-link authentication token sent via email. Consumed on successful use; prevents replay.

**Schema:**
```json
{
  "register": "MagicLinkToken",
  "schema": "MagicLinkToken",
  "properties": {
    "token": {
      "type": "string",
      "description": "Opaque 256-bit random token; embedded in email link; single-use",
      "required": true
    },
    "employee_id": {
      "type": "string",
      "description": "Employee for which this token was generated",
      "required": true
    },
    "email_sent_to": {
      "type": "string",
      "format": "email",
      "description": "Email address that received the link (for audit)",
      "required": true
    },
    "created_at": {
      "type": "string",
      "format": "date-time",
      "description": "Token creation timestamp",
      "required": true
    },
    "expires_at": {
      "type": "string",
      "format": "date-time",
      "description": "Token expiry (15 minutes after creation)",
      "required": true
    },
    "consumed_at": {
      "type": ["string", "null"],
      "format": "date-time",
      "description": "Timestamp when token was redeemed for a session; null if unconsumed",
      "required": false
    },
    "ip_address_hash": {
      "type": "string",
      "description": "Hash of IP address that created token request; used to detect phishing",
      "required": true
    },
    "user_agent": {
      "type": "string",
      "description": "User-Agent header of token creation request; for fraud detection",
      "required": true
    }
  }
}
```

**Constraints:**
- Can only be consumed once (`consumed_at` set on first use)
- Expires after 15 minutes
- Phishing detection: reject if IP or User-Agent diverges on consumption

---

#### 3. MutationApproval

Tracks approval workflows for sensitive employee data mutations (IBAN, BSN, marital status, birthdate). Until approved, old value remains active.

**Schema:**
```json
{
  "register": "MutationApproval",
  "schema": "MutationApproval",
  "properties": {
    "employee_id": {
      "type": "string",
      "description": "Employee whose data is being mutated",
      "required": true
    },
    "field_name": {
      "type": "string",
      "enum": ["iban", "bsn", "marital_status", "birthdate", "gender"],
      "description": "Which sensitive field is being changed",
      "required": true
    },
    "old_value": {
      "type": "string",
      "description": "Current value before mutation (for audit)",
      "required": true
    },
    "new_value": {
      "type": "string",
      "description": "Proposed new value",
      "required": true
    },
    "requested_at": {
      "type": "string",
      "format": "date-time",
      "description": "When mutation was requested",
      "required": true
    },
    "requested_by": {
      "type": "string",
      "description": "Employee ID (the employee themself, not a manager)",
      "required": true
    },
    "approver_id": {
      "type": ["string", "null"],
      "description": "Manager or HR admin who approved/rejected; null if pending",
      "required": false
    },
    "decision": {
      "type": "string",
      "enum": ["pending", "approved", "rejected", "auto_approved"],
      "description": "Approval status; auto_approved for non-sensitive NAW changes",
      "required": true
    },
    "decided_at": {
      "type": ["string", "null"],
      "format": "date-time",
      "description": "When decision was made; null if pending",
      "required": false
    },
    "decision_note": {
      "type": ["string", "null"],
      "description": "HR/manager notes on rejection (e.g. 'telefonisch geverifieerd: niet door medewerker')",
      "required": false
    }
  }
}
```

**Constraints:**
- `decision` is immutable once set to approved/rejected
- Only managers or HR-role users can set `approver_id` and `decision`
- If `decision=approved`, a background job updates the corresponding `Employee` field
- If `decision=rejected`, employee is notified with `decision_note`
- NAW changes (address, phone, private_email) auto-approve with `decision=auto_approved`

---

## Entity Relationships

```
Employee (from employee-master)
  ├─ has many → SelfServiceSession
  ├─ has many → MagicLinkToken
  ├─ has many → MutationApproval
  ├─ has many → Payslip (from payslip-generation)
  ├─ has many → LeaveRequest (from leave-management-mvp)
  ├─ has many → Expense (from expense-reimbursement)
  ├─ has many → TrainingRequest (from training-request)
  └─ has many → PersonalDevelopmentObjective (from performance-management)

Payslip
  └─ belongs to → Employee

LeaveRequest
  ├─ belongs to → Employee (requester)
  └─ belongs to → Employee (manager, via manager_id reference)

Expense
  ├─ belongs to → Employee (submitter)
  └─ belongs to → Employee (approver)

TrainingRequest
  ├─ belongs to → Employee (requester)
  └─ belongs to → Employee (approver)

PersonalDevelopmentObjective
  ├─ belongs to → Employee (owner)
  └─ may have → GoalProgress (audit trail of updates)
```

## Seed Data

### 1. Employee (from employee-master; example only — actual data lives in employee-master)

```json
{
  "@self": {
    "register": "employee-master",
    "schema": "Employee",
    "slug": "anna-bakker-123456789"
  },
  "id": "emp-anna-001",
  "first_name": "Anna",
  "last_name": "Bakker",
  "email": "anna.bakker@bakkerij-bv.nl",
  "private_email": "anna@gmail.com",
  "phone": "+31612345678",
  "bsn": "123456789",
  "bsn_hash": "sha256:...",
  "nc_user_id": "anna.bakker",
  "address_street": "Molenstraat 42",
  "address_city": "Amsterdam",
  "address_zipcode": "1012AB",
  "address_country": "NL",
  "iban": "NL12RABO0123456789",
  "birthdate": "1985-06-15",
  "marital_status": "married",
  "gender": "female",
  "employment_contract_id": "contract-001",
  "leave_balance_vacation_hours": 160,
  "manager_id": "emp-jan-001"
}
```

### 2. SelfServiceSession

```json
[
  {
    "@self": {
      "register": "SelfServiceSession",
      "schema": "SelfServiceSession",
      "slug": "session-anna-digid-20260523"
    },
    "employee_id": "emp-anna-001",
    "auth_method": "digid",
    "auth_subject": "sha256:123456789",
    "session_token": "session_7f3e2a1b5c9d8f4e6a2c1b9e7f3a5d8c",
    "started_at": "2026-05-23T09:30:00Z",
    "last_activity_at": "2026-05-23T09:45:30Z",
    "device_fingerprint": {
      "user_agent": "Mozilla/5.0 (iPhone; CPU iPhone OS 15_5 like Mac OS X) AppleWebKit/605.1.15",
      "ip_address_hash": "sha256:...",
      "screen_width": 375,
      "screen_height": 812
    },
    "expires_at": "2026-05-23T17:30:00Z"
  }
]
```

### 3. MagicLinkToken

```json
[
  {
    "@self": {
      "register": "MagicLinkToken",
      "schema": "MagicLinkToken",
      "slug": "token-anna-20260523-001"
    },
    "token": "mlt_8f5e2d1c9a7b4f3e6d2c1a9f7e5b3a1d",
    "employee_id": "emp-anna-001",
    "email_sent_to": "anna@gmail.com",
    "created_at": "2026-05-23T14:15:00Z",
    "expires_at": "2026-05-23T14:30:00Z",
    "consumed_at": "2026-05-23T14:18:30Z",
    "ip_address_hash": "sha256:202.91.123.45_20260523",
    "user_agent": "Mozilla/5.0 (iPhone; CPU iPhone OS 15_5)"
  },
  {
    "@self": {
      "register": "MagicLinkToken",
      "schema": "MagicLinkToken",
      "slug": "token-anna-20260523-002"
    },
    "token": "mlt_9g6f3e2d0b8c5a1f7e4d3c2b0a9e8f7c",
    "employee_id": "emp-anna-001",
    "email_sent_to": "anna@gmail.com",
    "created_at": "2026-05-23T15:00:00Z",
    "expires_at": "2026-05-23T15:15:00Z",
    "consumed_at": null,
    "ip_address_hash": "sha256:202.91.123.45_20260523",
    "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
  }
]
```

### 4. MutationApproval

```json
[
  {
    "@self": {
      "register": "MutationApproval",
      "schema": "MutationApproval",
      "slug": "mutation-anna-iban-20260520"
    },
    "employee_id": "emp-anna-001",
    "field_name": "iban",
    "old_value": "NL12RABO0123456789",
    "new_value": "NL45ABNA0987654321",
    "requested_at": "2026-05-20T16:45:00Z",
    "requested_by": "emp-anna-001",
    "approver_id": "emp-hr-001",
    "decision": "approved",
    "decided_at": "2026-05-21T09:30:00Z",
    "decision_note": "Telefonisch geverifieerd 2026-05-21 10:15; echtscheiding bevestigd."
  },
  {
    "@self": {
      "register": "MutationApproval",
      "schema": "MutationApproval",
      "slug": "mutation-anna-address-20260523"
    },
    "employee_id": "emp-anna-001",
    "field_name": "address_street",
    "old_value": "Molenstraat 42",
    "new_value": "Kerkplein 8",
    "requested_at": "2026-05-23T10:00:00Z",
    "requested_by": "emp-anna-001",
    "approver_id": null,
    "decision": "auto_approved",
    "decided_at": "2026-05-23T10:00:15Z",
    "decision_note": null
  }
]
```

### 5. Payslip (from payslip-generation; example structure)

```json
[
  {
    "@self": {
      "register": "payslip",
      "schema": "Payslip",
      "slug": "payslip-anna-202405"
    },
    "employee_id": "emp-anna-001",
    "period": "2024-05",
    "period_start": "2024-05-01",
    "period_end": "2024-05-31",
    "gross_salary": 3500.00,
    "net_salary": 2347.12,
    "tax_withheld": 752.38,
    "social_security": 413.62,
    "pension": 87.88,
    "deductions": [
      { "type": "health_insurance", "amount": 150.00 },
      { "type": "pension_contribution", "amount": 87.88 }
    ],
    "pdf_filename": "Loonstrook_Anna_Bakker_mei_2024.pdf",
    "pdf_url": "https://hrmq.example.com/api/payslips/ps-001/pdf",
    "created_at": "2024-06-05T10:00:00Z"
  }
]
```

### 6. LeaveRequest (from leave-management-mvp; example structure)

```json
[
  {
    "@self": {
      "register": "leave-requests",
      "schema": "LeaveRequest",
      "slug": "leave-anna-summer-2026"
    },
    "employee_id": "emp-anna-001",
    "manager_id": "emp-jan-001",
    "leave_type": "vacation",
    "date_from": "2026-08-10",
    "date_to": "2026-08-21",
    "duration_hours": 64,
    "status": "approved",
    "submitted_at": "2026-05-23T14:30:00Z",
    "decided_at": "2026-05-23T16:00:00Z",
    "approver_id": "emp-jan-001",
    "approval_note": "Goedgekeurd"
  }
]
```

## API / Backend Layer

### Authentication Endpoints

#### POST /api/auth/digid/initiate
Initiates DigiD SAML authentication flow. Returns a SAML AuthnRequest URL.

#### POST /api/auth/digid/callback
Handles DigiD SAML response. Validates signature; matches BSN to Employee; creates SelfServiceSession.

#### POST /api/auth/sso/callback
Handles Nextcloud SSO OAuth callback. Exchanges code for NC user info; validates nc_user_id in Employee; creates SelfServiceSession.

#### POST /api/auth/magic-link/request
Accepts email address; checks if it exists in Employee.private_email or Employee.email; generates MagicLinkToken; sends email with link.

#### POST /api/auth/magic-link/consume
Accepts token + device fingerprint; validates token (not expired, not consumed, IP/UA match); marks as consumed; creates SelfServiceSession.

#### POST /api/auth/logout
Invalidates SelfServiceSession; clears HTTP-only cookie.

### Data Endpoints (Read)

#### GET /api/employee/me
Returns logged-in employee's public fields (name, email, leave balance, manager info). Auth: SelfServiceSession token.

#### GET /api/payslips
Lists payslips (last 24 months). Auth: SelfServiceSession, scoped to self.

#### GET /api/payslips/{id}/pdf
Streams payslip PDF. Auth: SelfServiceSession, scoped to self.

#### GET /api/leave-requests
Lists leave requests for logged-in employee. Auth: SelfServiceSession, scoped to self.

#### GET /api/contracts
Lists contracts for logged-in employee. Auth: SelfServiceSession, scoped to self.

#### GET /api/expenses
Lists expenses for logged-in employee. Auth: SelfServiceSession, scoped to self.

#### GET /api/training-requests
Lists training requests for logged-in employee. Auth: SelfServiceSession, scoped to self.

#### GET /api/personal-development/goals
Lists POP goals for logged-in employee. Auth: SelfServiceSession, scoped to self.

### Data Endpoints (Write)

#### PATCH /api/employee/me
Allows scoped mutations (NAW fields: address, phone, private_email). Auto-creates MutationApproval with auto_approved decision. Auth: SelfServiceSession, scoped to self.

#### PATCH /api/employee/me/sensitive-fields
Allows submission of sensitive mutations (iban, bsn, marital_status, birthdate). Creates MutationApproval with pending decision. Notifies manager/HR. Auth: SelfServiceSession, scoped to self.

#### POST /api/leave-requests
Submits new leave request. Delegates to leave-management-mvp app. Auth: SelfServiceSession, scoped to self.

#### POST /api/expenses
Submits new expense claim with optional receipt photo. Delegates to expense-reimbursement app. Auth: SelfServiceSession, scoped to self.

#### POST /api/training-requests
Submits new training request. Delegates to training-request app. Auth: SelfServiceSession, scoped to self.

#### PATCH /api/personal-development/goals/{id}/progress
Adds progress update to POP goal. Auth: SelfServiceSession, scoped to self.

## Frontend Screens & Components

### Auth Screens

1. **Login Page**
   - Options: "Inloggen met DigiD" (button) | "Inloggen met Nextcloud" (button) | "Inloggen met link" (email input)
   - Mobile-first: stacked buttons, 44×44px tap targets
   - Accessibility: form labels, error messages, skip-link to main

2. **Magic-Link Email Entry**
   - Email input with label "Je werknemers-e-mailadres"
   - Submit button "Stuur link"
   - Confirmation message: "Als dit e-mailadres bekend is, ontvang je binnen enkele minuten een link"
   - Prevents email enumeration

3. **Magic-Link Consumed / Expired**
   - Error: "Deze link is al gebruikt" or "Link verlopen"
   - Option to request new link

### Dashboard

- Quick-access cards: Payslips, Leave balance, Expenses, Contracts, Training
- Manager approval queue (if logged-in user is a manager; low priority for MVP)
- Notification banner for pending approvals or rejections

### Payslip Screens

1. **Payslip List**
   - Table: Date | Gross | Net | Download
   - Sorted newest-first
   - CnDataTable component (Nextcloud Vue)
   - Pagination: 10 per page

2. **Payslip Detail**
   - PDFjs inline viewer (mobile-friendly, zoom, rotate)
   - Download button
   - Email button (pre-filled to employee private_email)
   - Back button

3. **Tax Certificate List**
   - Year | Gross | Net | Download
   - CnDataTable component
   - Limited to last 7 years

### Leave Screens

1. **Leave Balance**
   - Grid: Leave Type | Balance (Hours) | Used | Remaining
   - Example: Vacation: 160 hours | 48 used | 112 remaining
   - New Request button

2. **Leave Request Submission**
   - Form: Type (dropdown) | Date From | Date To | Reason (textarea)
   - On date change: auto-calculate workdays, warn if over balance
   - Submit button; clear button
   - On success: redirect to Leave Requests list

3. **Leave Requests List**
   - Table: Date From | Date To | Type | Status | Approver
   - Statuses: Wacht op goedkeuring | Goedgekeurd | Afgewezen
   - Clickable rows → detail view

### NAW Screens

1. **My Data**
   - Sections: Contact | Address | Bank
   - Contact: Name (read-only) | Private Email | Phone
   - Address: Street | City | Zipcode | Country
   - Bank: IBAN (approval-gated, shows notice)
   - Editable fields have pencil icon; inline edit or modal
   - Save button; changes auto-create MutationApproval

### Mutation Approval Screens

1. **Pending Approval Badge**
   - Appears on affected field when MutationApproval.decision=pending
   - Shows old value, new value, "waiting for approval"

2. **Approval Rejected Banner**
   - Appears on screen if recent rejection
   - Shows reason (decision_note)
   - Allows user to re-request with new value

### Expense Screens

1. **Expense List**
   - Table: Date | Category | Amount | Receipt | Status
   - Statuses: Ingediend | Goedgekeurd | Betaald | Afgewezen
   - New Expense button

2. **Expense Submission**
   - Form: Date | Category (dropdown) | Amount | Reason | Receipt (file upload or camera)
   - Camera button (mobile) → photo → OCR extraction
   - Pre-fills Amount, Date from OCR if available
   - Submit button; validation on amount format

### Contract Screens

1. **Contract List**
   - Table: Type | Date From | Date To | Status | Download
   - Types: Contract | Addendum (salary increase, hours change, function change)
   - Newest first
   - Download PDF button

2. **Contract Detail**
   - Summary: Function | Salary Scale | Hours | End Date
   - PDFjs inline viewer for contract PDF
   - Addenda sublist
   - Signature status (via Decidesk integration)

### Training Screens

1. **Training Requests List**
   - Table: Title | Dates | Cost | Status
   - Statuses: Ingediend | Goedgekeurd | Geweigerd | Ingeschreven
   - New Request button

2. **Training Request Submission**
   - Form: Title | Provider | Cost | Start Date | End Date | Link | Motivation (textarea)
   - Submit button
   - On success: confirmation; notification to manager

### POP Screens

1. **Goals Overview**
   - Table: Goal | KPI | Deadline | Status | Progress
   - Statuses: Op koers | Aandacht | Behaald
   - Goal cards show latest update

2. **Goal Detail**
   - Description | KPI | Deadline | Latest update
   - Add Progress button → textarea form → submit
   - Progress history (read-only)

## Performance & Accessibility

### Performance Targets

- **First Contentful Paint:** < 2s on 4G, < 4s on 3G
- **Largest Contentful Paint:** < 3s on 4G, < 5s on 3G
- **Time to Interactive:** < 4s on 4G, < 7s on 3G
- **Cumulative Layout Shift:** < 0.1

### Accessibility Compliance

- **WCAG 2.1 AA** across all core screens
- **Color contrast:** 4.5:1 for body text, 3:1 for UI components
- **Touch targets:** 44×44 CSS pixels minimum
- **Keyboard navigation:** All interactive elements reachable via Tab; focus visible
- **Screen reader:** Semantic HTML; ARIA roles/labels where needed; form labels; list semantics
- **Language:** Dutch (nl-NL) as primary; ARIA lang attributes
- **Responsive:** 320px+ (mobile-first); tested on iPhone SE, Android Galaxy S10

### Testing Scope

- Automated: axe-core scans on all core screens; CI/CD gates on AA violations
- Manual: Screen reader testing (VoiceOver iOS, TalkBack Android); keyboard-only nav
- Mobile: iOS Safari 14+; Chrome Mobile 100+; Firefox Mobile 100+; Samsung Internet 17+

## Reuse Analysis

### Existing OpenRegister Services Leveraged

- **ObjectService:** CRUD operations for SelfServiceSession, MagicLinkToken, MutationApproval
- **SearchService:** Find payslips, leave requests, expenses by date range / status
- **AuthorizationService:** Field-level access control (e.g., only manager/HR can view approver_id on MutationApproval)
- **NotificationService:** Nextcloud notifications for approvals, rejections, status updates
- **FileService:** PDF storage + retrieval for payslips and contracts
- **AuditTrailService:** Automatic change tracking on Employee mutations and MutationApprovals

### Existing Frontend Components Reused

- **CnDataTable:** Lists (payslips, leave requests, expenses)
- **CnDetailPage:** Detail views (payslip, contract, training request)
- **CnFormDialog:** Create/edit forms (leave request, expense, training request)
- **CnPagination:** Pagination controls
- **CnActionBar:** Search, filter, add buttons
- **CnDetailGrid:** Summary sections (employee info, leave balance)

### No Overlap with Existing Functionality

- SelfServiceSession management (session lifecycle, timeouts) is unique to self-service; no overlap with main app
- MagicLinkToken + magic-link auth flow is new; Nextcloud has OAuth but not magic-link
- MutationApproval workflow is new; main app does not have approval-gated mutations on Employee

## Security Considerations

### Authentication Security

- **DigiD:** SAML 2.0 over HTTPS; signature validation; assertion expiry < 5 min
- **SSO:** OAuth 2.0 Authorization Code flow; token refresh every 60 min; PKCE for mobile
- **Magic-link:** 256-bit random token; single-use; 15-min expiry; IP + User-Agent binding
- **Session timeout:** 30 min idle + 8 hour absolute (NIST SP 800-63B AAL2)
- **HTTP-only cookies:** SelfServiceSession token never exposed to JavaScript

### Authorization & Data Access

- **Field-level RBAC:** Only Employee can view/edit own records
- **Manager scope:** Can approve leave requests, expenses, training for direct reports only
- **HR scope:** Can approve mutations, view audit trail, bulk operations
- **Scope validation:** Every endpoint validates `session.employee_id` matches URL param or query filter

### Fraud Prevention

- **IBAN mutation workflow:** Requires manager + HR approval; phone verification recommended
- **Phishing detection:** Magic-link IP/UA binding; email sent only to registered address
- **Mutation audit trail:** Full before/after history in MutationApproval + AuditTrail
- **Rate limiting:** 5 auth attempts per email per 15 min; 10 mutations per employee per day

### Data Minimization

- **Session fingerprinting:** Hash IP + anonymize; do not store full IP
- **PDF viewer:** Client-side rendering; no transcript of payslip data sent to third parties (unless user explicitly shares)
- **Magic-link:** Email content does not contain sensitive data; link only

## Deduplication Check

**Task:** Verify no duplication with existing OpenRegister / HRMQ services.

**Findings:**
- ✓ **SelfServiceSession:** New; no existing session management in HRMQ for self-service portal
- ✓ **MagicLinkToken:** New; Nextcloud has OAuth but not email-based magic-link
- ✓ **MutationApproval:** New; main HRMQ app does not have approval workflows for Employee mutations
- ✓ **Payslip viewing:** Uses existing payslip-generation app; no duplication
- ✓ **Leave request submission:** Uses existing leave-management-mvp app; no duplication
- ✓ **Expense submission:** Uses existing expense-reimbursement app; no duplication
- ✓ **Contract viewing:** Uses existing contract-management app; no duplication
- ✓ **Training request:** Uses existing training-request app; no duplication

**Conclusion:** No overlap found. All portal-specific logic (session, magic-link, mutations) is new; all domain logic (leave, payroll, expenses) is delegated to existing apps.


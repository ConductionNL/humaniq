---
status: draft
---

# Implementation Tasks: Employee Self-Service voor MKB

**Change ID:** employee-self-service-mkb  
**Status:** Task Planning  
**Created:** 2026-05-23

---

## Phase 1: Foundation & Core Infrastructure (MVP)

### Task 1.1: Set Up Project Structure

- [ ] Create new Vue 3 + PHP app skeleton under `apps/employee-self-service`
- [ ] Configure Nextcloud app.php manifest with correct app ID, version, license
- [ ] Set up router with core routes: `/login`, `/dashboard`, `/payslips`, `/leave`, `/my-data`, etc.
- [ ] Configure OpenRegister integration for SelfServiceSession, MagicLinkToken, MutationApproval entities
- [ ] Set up TypeScript/Vue configuration, bundler (Vite), linting (ESLint, Prettier)
- [ ] Create CI/CD pipeline (GitHub Actions) for testing, linting, accessibility scans

### Task 1.2: Implement Session Management

- [ ] Create `SelfServiceSession` schema in OpenRegister
- [ ] Implement SelfServiceSession CRUD service (`SessionService`)
- [ ] Create session middleware that validates token on every request
- [ ] Implement session timeout logic: 30 min idle + 8 hour absolute (background job to expire old sessions)
- [ ] Set HTTP-only cookie handler (SessionCookieHandler)
- [ ] Implement "idle timeout warning" component (shows at 25-minute mark)
- [ ] Add session refresh endpoint (extends timeout on activity)
- [ ] Write unit tests for SessionService (create, validate, expire)

### Task 1.3: Implement DigiD Authentication Flow

- [ ] Register app with Logius DigiD broker (production and test environments)
- [ ] Create DigiD SAML controller:
  - `POST /auth/digid/initiate` — returns SAML AuthnRequest URL
  - `POST /auth/digid/callback` — processes SAML response
- [ ] Implement SAML signature validation (use SimpleSAML PHP library or similar)
- [ ] Implement BSN hashing (sha256) for secure storage
- [ ] Implement Employee BSN matching logic (query employee-master app)
- [ ] Handle error cases: no matching Employee, assertion expired, invalid signature
- [ ] Add integration test: successful DigiD login → session created
- [ ] Add integration test: DigiD with non-existent BSN → error message

### Task 1.4: Implement Nextcloud SSO Flow

- [ ] Create OAuth callback handler for Nextcloud single sign-on
- [ ] Implement token exchange (code → access token)
- [ ] Implement Nextcloud user info retrieval (uid → employee lookup)
- [ ] Create SelfServiceSession from NC user info
- [ ] Handle error cases: non-existent nc_user_id, token invalid
- [ ] Test: Nextcloud tile → automatic portal login (no password)

### Task 1.5: Implement Magic-Link Authentication Flow

- [ ] Create `MagicLinkToken` schema in OpenRegister
- [ ] Implement MagicLinkToken service:
  - Generate token (256-bit random)
  - Set expires_at (15 min from now)
  - Store ip_address_hash and user_agent
  - Mark consumed when redeemed
- [ ] Create magic-link endpoints:
  - `POST /auth/magic-link/request` — accept email, generate token, send email
  - `GET /auth/magic-link/consume` — validate token, create session
- [ ] Implement email template (plain text + HTML) with magic-link URL
- [ ] Implement email sending (via SMTP relay or openconnector)
- [ ] Prevent email enumeration: same success message whether email exists or not
- [ ] Implement token consumption: mark consumed_at, validate single-use
- [ ] Implement IP/UA binding: reject if IP or UA diverges too much (log as fraud attempt)
- [ ] Write tests:
  - Valid token → session created
  - Non-existent email → no email sent (but success message shown)
  - Already-consumed token → error
  - Expired token → error

### Task 1.6: Implement Dashboard & Navigation

- [ ] Create Dashboard component (home screen after login)
- [ ] Implement top navigation bar with app logo, logout button, user menu
- [ ] Create main menu: Loonstroken, Verlof, Mijn gegevens, Declaratie, Contracten, Opleidingen, Mijn ontwikkeling
- [ ] Implement dashboard cards as quick access to main sections
- [ ] Implement responsive sidebar (collapsed on mobile, expanded on tablet/desktop)
- [ ] Add session timeout warning component (appears at 25 min)
- [ ] Add notification banner for pending approvals/rejections
- [ ] Verify WCAG AA on navigation (keyboard nav, screen reader, contrast)

---

## Phase 1b: Payslip & Tax Certificate Features

### Task 1.7: Implement Payslip Viewing

- [ ] Create integration with payslip-generation app (API or direct OpenRegister access)
- [ ] Implement Payslip list screen:
  - Query payslips for logged-in employee (last 24 months)
  - Display table: Date, Gross, Net, Download button
  - Sort newest-first
  - Pagination: 10 per page
  - Mobile-responsive (CnDataTable from Nextcloud Vue)
- [ ] Implement Payslip detail screen:
  - Show metadata: period, gross, net, tax, social security, pension
  - Inline PDF viewer (PDFjs or similar; mobile-optimized)
  - Download button (native browser download)
  - Email button (send PDF to private_email)
- [ ] Implement PDF download endpoint (stream PDF from payslip-generation app)
- [ ] Implement email PDF feature:
  - Pre-fill employee's private_email
  - Send via SMTP with PDF attachment
  - Confirmation message to user
- [ ] Write integration tests:
  - List shows correct payslips
  - Download works on mobile
  - Email sends successfully
- [ ] Performance test: PDF loads < 3s on 4G, < 5s on 3G

### Task 1.8: Implement Tax Certificate Viewing

- [ ] Query jaaropgaaves from payslip-generation app (one per fiscal year)
- [ ] Implement tax certificate list screen:
  - Display table: Year, Gross, Withheld Tax, Download
  - Sort newest-first (last 7 years)
- [ ] Implement download endpoint (stream jaaropgaaf PDF)
- [ ] Verify tax certificate totals match sum of monthly payslips (integration test)

---

## Phase 1c: Leave Management Integration

### Task 1.9: Implement Leave Balance Display

- [ ] Integrate with leave-management-mvp app (query employee leave balances)
- [ ] Create Leave Balance screen:
  - Query all leave types for employee (vacation, sick, parental, care, etc.)
  - Display table: Type, Total Balance (hours), Used, Remaining
  - Format: hours in Dutch "uren"
- [ ] Implement leave balance calculation (may delegate to leave-management-mvp)
- [ ] Set up real-time refresh: balance updates within 5 minutes of manager approval
- [ ] Integration test: balance reflects manager-approved requests

### Task 1.10: Implement Leave Request Submission

- [ ] Create Leave Request form:
  - Dropdown: Leave Type (Vacation, Sick, Parental, Care)
  - Date picker: From (DD-MM-YYYY or native mobile picker)
  - Date picker: To
  - Textarea: Reason (optional, max 500 chars)
  - Submit, Clear buttons
- [ ] Implement workday calculation:
  - Query employee's work schedule (5-day week default)
  - Calculate workdays between From and To dates
  - Convert to hours (8 hours per workday default)
  - Warn if request exceeds available balance
- [ ] Implement form submission:
  - Create LeaveRequest record in leave-management-mvp
  - Set status = pending_approval
  - Notify manager via Nextcloud Notification + email
- [ ] Implement Leave Request list screen:
  - Query employee's leave requests (all time)
  - Display table: From, To, Type, Status, Approver
  - Click to view details (reason, approval note if available)
- [ ] Implement feedback mechanism:
  - Show confirmation "Aanvraag ingediend!"
  - Redirect to leave list
- [ ] Write tests:
  - Workday calculation correctness (including holidays if applicable)
  - Balance check prevents over-request (or warns)
  - Manager notification sent within 1 minute

### Task 1.11: Implement Leave Request Status Display & Sync

- [ ] Set up polling or webhook to sync LeaveRequest status updates from leave-management-mvp
- [ ] When manager approves request:
  - Set LeaveRequest.status = approved
  - Decrement employee balance automatically
  - Send notification to employee
  - Reflect in portal within 5 minutes
- [ ] When manager rejects request:
  - Set LeaveRequest.status = rejected
  - Keep balance unchanged
  - Send notification with rejection reason
- [ ] Test: approval/rejection reflected in portal within 5 min

---

## Phase 2: NAW Mutations & Employee Data

### Task 2.1: Create MutationApproval Schema

- [ ] Define MutationApproval schema in OpenRegister
- [ ] Implement MutationApproval CRUD service
- [ ] Add auto-approval logic for non-sensitive fields (NAW: address, phone, private_email)

### Task 2.2: Implement "My Data" Screen (NAW Display)

- [ ] Create "Mijn gegevens" screen with sections:
  - Contact: Name (read-only), Private Email, Phone
  - Address: Street, City, Zipcode, Country
  - Bank: IBAN (approval-gated)
  - Other read-only fields: Gender, Birthdate, Marital Status
- [ ] Display current values from Employee record
- [ ] Implement edit mode: click pencil icon → edit fields
- [ ] Display pending mutations:
  - If MutationApproval.decision = pending, show "Waiting for approval" with old/new values
  - If MutationApproval.decision = rejected, show warning: "Change was rejected. Reason: ..."
- [ ] Responsive: mobile-friendly form, modal or inline edit

### Task 2.3: Implement Self-Service NAW Updates (Auto-Approved)

- [ ] Create PATCH endpoint: `PATCH /api/employee/me`
  - Accept updates to: address_street, address_city, address_zipcode, phone, private_email
  - Validate input (zipcode format, phone format, email format)
- [ ] Implement auto-approval workflow:
  - Update Employee record immediately
  - Create MutationApproval with decision=auto_approved
  - Send informational notification to manager/HR
- [ ] Implement form submission handler:
  - Optimistic UI update (show new value immediately)
  - API call to PATCH endpoint
  - Show confirmation: "Adres bijgewerkt"
- [ ] Write tests:
  - Address update creates MutationApproval with auto_approved
  - Notification sent to manager
  - Employee record updated within 1 minute

### Task 2.4: Implement Approval-Gated Mutation Requests

- [ ] Create separate PATCH endpoint: `PATCH /api/employee/me/sensitive-fields`
  - Accept updates to: iban, bsn, marital_status, birthdate, gender
  - Validate input (IBAN format with mod-97 check)
- [ ] Implement approval workflow:
  - Create MutationApproval with decision=pending
  - Do NOT update Employee record
  - Send high-priority notification to manager and HR-admin
  - Notification body includes warning: "⚠️ Verify by phone call before approving"
- [ ] Implement form submission handler:
  - Show confirmation: "IBAN-wijziging aangevraagd. Goedkeuring vereist."
  - Display old and new values (pending)
- [ ] Write tests:
  - IBAN mutation creates MutationApproval with pending decision
  - Employee record NOT updated until approval
  - Notifications sent to manager and HR

### Task 2.5: Implement Mutation Approval Display & Status Updates

- [ ] Create mutation approval status display:
  - Pending approval badge (yellow)
  - Rejected banner with reason (red)
- [ ] Set up webhook or polling from admin app:
  - When MutationApproval.decision changes (approved/rejected)
  - Sync to portal and notify employee
- [ ] When approved: update Employee record + notify employee
- [ ] When rejected: notify employee with reason; allow re-request
- [ ] Test: approval/rejection reflected in portal within 5 min

---

## Phase 2b: Expense Reimbursement

### Task 2.6: Integrate with Expense Reimbursement App

- [ ] Establish API contract with expense-reimbursement app
- [ ] Implement Expense list screen:
  - Query expenses for logged-in employee
  - Display table: Date, Category, Amount, Receipt (Y/N), Status
  - Status: Ingediend, Goedgekeurd, Betaald, Afgewezen
  - Sort newest-first
- [ ] Implement Expense detail screen:
  - Show: Date, Category, Amount, Description, Receipt (if available)
  - Display receipt image (if uploaded)
  - Show approval status and dates

### Task 2.7: Implement Expense Submission

- [ ] Create Expense Submission form:
  - Date picker (default: today)
  - Category dropdown (Transport, Accommodation, Meals, Office, Other)
  - Amount input (currency, € format, positive validation)
  - Description textarea (max 500 chars)
  - Receipt upload button / camera button (mobile)
- [ ] Implement mobile camera integration:
  - Camera button opens native camera (mobile)
  - Photo upload to form as file input
- [ ] Implement OCR integration:
  - Send receipt photo to qwen3.5 LLM or cloud OCR API
  - Extract: amount, date, merchant
  - Pre-fill form with extracted values
  - Allow user to verify/correct before submit
- [ ] Implement form submission:
  - Create Expense record in expense-reimbursement app
  - Attach receipt photo
  - Set status = submitted
  - Notify manager
- [ ] Implement feedback: "Declaratie ingediend! Je manager ontvangt een bericht."
- [ ] Write tests:
  - OCR accuracy > 95% for common Dutch receipts
  - User can correct OCR results
  - Manager notified within 1 minute of submission
  - Receipt photo is attached and retrievable

### Task 2.8: Implement Expense Status & Manager Approval Sync

- [ ] Set up polling/webhook to sync Expense status from expense-reimbursement app
- [ ] When manager approves:
  - Update Expense.status = approved
  - Send notification to employee with expected payment date
  - Reflect in portal within 2 minutes
- [ ] When manager rejects:
  - Update status = rejected
  - Send notification with rejection reason
  - Allow re-submission

---

## Phase 2c: Contracts & Addenda

### Task 2.9: Integrate with Contract Management App

- [ ] Establish API contract with contract-management app
- [ ] Implement Contract list screen:
  - Query contracts + addenda for logged-in employee
  - Display table: Type (Contract / Addendum), Date From, Date To, Status, Download
  - Sort by date (newest first)
  - Include types: Contract, Salary Increase Addendum, Hours Change, Function Change, etc.

### Task 2.10: Implement Contract Viewing

- [ ] Create Contract detail screen:
  - Summary section: Function, Salary Scale, Hours/week, End Date (if any)
  - PDFjs inline viewer for contract PDF
  - Download button (native)
- [ ] Implement Addenda sublist:
  - Click addendum → detail screen
  - Display PDF inline
- [ ] Implement Decidesk integration (if enabled):
  - Query Decidesk for signature status on contracts/addenda
  - Display status: Pending your signature / Signed on [date]
  - Link to Decidesk signing flow if pending
- [ ] Test:
  - PDF loads and displays correctly on mobile
  - Signature status syncs with Decidesk

---

## Phase 3: Training Requests

### Task 3.1: Integrate with Training Request App

- [ ] Establish API contract with training-request app
- [ ] Implement Training Request list screen:
  - Query training requests for logged-in employee
  - Display table: Title, Dates, Cost, Status
  - Status: Ingediend, Goedgekeurd, Geweigerd, Ingeschreven
  - Sort newest-first

### Task 3.2: Implement Training Request Submission

- [ ] Create Training Request form:
  - Title input (text, required)
  - Provider input (text, required)
  - Cost input (currency €, required)
  - Start Date picker (required)
  - End Date picker (optional)
  - Link to course (URL, optional)
  - Motivation textarea (required, max 1000 chars)
  - Submit, Cancel buttons
- [ ] Implement form submission:
  - Create TrainingRequest in training-request app
  - Set status = submitted
  - Trigger budget check (async, may take seconds)
  - Notify manager
- [ ] Implement feedback: "Trainingaanvraag ingediend! Je manager ontvangt een bericht."
- [ ] Write tests:
  - Form validation (all required fields)
  - Cost format validation (positive number)
  - Manager notification sent

### Task 3.3: Implement Training Request Status & Approval Sync

- [ ] Set up polling/webhook for TrainingRequest status updates
- [ ] When manager + budget system approves:
  - Update status = approved
  - Send notification with enrollment instructions
  - Reflect in portal within 5 minutes
- [ ] When budget insufficient:
  - Update status = rejected
  - Send notification with reason
- [ ] Allow re-submission after budget refresh

---

## Phase 4: Personal Development (POP)

### Task 4.1: Integrate with Performance Management App

- [ ] Establish API contract with performance-management app (or POP app)
- [ ] Implement POP Goals list screen:
  - Query goals for logged-in employee (current fiscal year)
  - Display table: Goal, KPI, Deadline, Status (On track / Attention / Completed), Progress %
  - Sort by deadline (nearest first)

### Task 4.2: Implement POP Goal Detail & Progress Updates

- [ ] Create Goal detail screen:
  - Goal description
  - KPI, Deadline
  - Progress % and latest update
  - "Voeg update toe" button
- [ ] Create Progress Update form:
  - Textarea for update (max 1000 chars)
  - Submit, Cancel buttons
- [ ] Implement form submission:
  - Create GoalProgress record (or append to goal history)
  - Set timestamp and employee ID
  - Show confirmation: "Update opgeslagen"
- [ ] Implement progress history (read-only):
  - Display all updates for goal in chronological order
- [ ] Test:
  - Update created with correct timestamp
  - Visible immediately on refresh
  - Manager can view updates in admin app

---

## Phase 5: Accessibility & Performance

### Task 5.1: WCAG 2.1 AA Compliance Audit

- [ ] Run axe-core automated scan on all 12 core screens:
  1. Login
  2. Payslip list
  3. Payslip detail
  4. Leave balance
  5. Leave request form
  6. Leave request list
  7. My data (NAW)
  8. Expense list
  9. Expense submission
  10. Contract list
  11. Contract detail
  12. Training request form
- [ ] Fix all AA violations found by axe-core
- [ ] Manual testing on actual devices:
  - iOS: VoiceOver on iPhone SE, iPhone 14
  - Android: TalkBack on Galaxy S10, Galaxy S23
  - Keyboard-only navigation (Tab, Enter, Escape)
- [ ] Verify:
  - All form labels associated with inputs
  - Color contrast 4.5:1 for body text, 3:1 for UI
  - Touch targets 44×44px minimum
  - Focus indicator visible
  - Tab order matches visual order
  - No keyboard traps
- [ ] Add CI/CD gate: axe-core must pass before merge

### Task 5.2: Mobile-First Performance Optimization

- [ ] Measure performance on 4G and 3G throttle:
  - Target: FCP < 2s (4G), < 4s (3G)
  - Target: LCP < 3s (4G), < 5s (3G)
  - Target: TTI < 4s (4G), < 7s (3G)
  - Target: CLS < 0.1
- [ ] Optimize bundle size:
  - Tree-shake unused Nextcloud Vue components
  - Lazy-load route components
  - Inline critical CSS
  - Target: < 500KB gzipped for core routes
- [ ] Optimize images:
  - Convert to WebP where supported (with fallback)
  - Optimize PDF viewer (lazy-load PDFjs)
- [ ] Implement service worker for offline caching:
  - Cache core app shell (HTML, CSS, JS)
  - Cache recent API responses (5 min TTL)
  - Show offline indicator when no network
  - Sync pending mutations on reconnect
- [ ] Test on real devices:
  - iPhone SE (slower hardware)
  - Samsung Galaxy S10 (2019 model)
  - Slow 3G network profile
- [ ] Add Lighthouse CI: LCP target 3s, CLS < 0.1

### Task 5.3: Responsive Design Testing

- [ ] Test on multiple breakpoints:
  - 320px (iPhone SE)
  - 375px (iPhone 12 mini)
  - 768px (iPad)
  - 1024px (iPad Pro / desktop)
- [ ] Verify:
  - No horizontal scrolling at 320px
  - Text readable (not zoomed out)
  - Buttons/inputs usable on small screen
  - Images scale appropriately
  - Tables (if used) have scrollable horizontal overflow on small screens
- [ ] Use responsive design testing tools (Chrome DevTools, Responsively App)
- [ ] Test on actual devices before release

### Task 5.4: Internationalization (Dutch Language)

- [ ] Mark all UI strings for translation:
  - Use Vue i18n or Nextcloud translation system
  - Wrap all user-facing text in translation function (e.g., `t('label')`)
- [ ] Provide Dutch translations for all strings
- [ ] Set html lang="nl-NL" for screen reader
- [ ] Test date/time formatting (Dutch locale):
  - Dates: DD-MM-YYYY format (European standard)
  - Times: HH:MM (24-hour)
  - Currency: €x.xx with thousand separators
  - Numbers: use comma for decimal separator (European)
- [ ] Verify translations in automated tests

---

## Phase 6: Security Hardening

### Task 6.1: CSRF Protection

- [ ] Implement CSRF token generation:
  - Token per session (or per request, depending on framework)
  - Include in form as hidden field
  - Include in request header (X-CSRF-Token)
- [ ] Implement CSRF token validation on all POST/PATCH/DELETE endpoints
- [ ] Test:
  - Valid token → request accepted
  - Missing token → 403 Forbidden
  - Invalid token → 403 Forbidden
- [ ] Verify: form submissions include token, API calls include header

### Task 6.2: Rate Limiting

- [ ] Implement login rate limit:
  - Max 5 failed attempts per email per 15 minutes
  - IP-based rate limit: max 20 failed attempts per IP per 15 minutes
  - Progressive backoff (1s, 2s, 4s delays)
- [ ] Implement mutation rate limit:
  - Max 10 sensitive mutations per employee per 24 hours
- [ ] Log all rate limit violations (potential attack indicator)
- [ ] Test:
  - 6th failed login → rate limited
  - After 15 minutes → limit reset

### Task 6.3: Input Validation & Output Encoding

- [ ] Validate all form inputs:
  - Date formats (DD-MM-YYYY)
  - Email format (RFC 5322)
  - Phone format (international or Dutch +31)
  - IBAN format (mod-97 check)
  - Currency amounts (non-negative, reasonable range)
  - Text lengths (max char limits)
- [ ] Encode all output to prevent XSS:
  - HTML context: use Vue auto-escaping
  - Attribute context: escape quotes
  - JavaScript context: escape quotes/slashes
  - URL context: URL-encode
- [ ] Sanitize file uploads (receipt photos):
  - Accept only image/jpeg, image/png
  - Max file size 10MB
  - Scan for malicious content (optional: ClamAV or similar)
  - Store outside web root
- [ ] Test:
  - Injection payloads rejected (or escaped if user-submitted)
  - File upload only accepts images

### Task 6.4: Secrets Management

- [ ] Move all secrets to environment variables:
  - DigiD broker credentials
  - OAuth app secret
  - SMTP credentials
  - API keys for OCR service
  - PDF signing key (if applicable)
- [ ] Never commit secrets to git
- [ ] Use .env.example with placeholders
- [ ] Implement secret rotation procedure (document in README)
- [ ] Verify: .env is in .gitignore

### Task 6.5: Security Testing

- [ ] Run OWASP Dependency-Check on npm/composer dependencies
  - Fix high/critical vulnerabilities
  - Acceptable: medium/low (with justification)
- [ ] Perform manual security audit:
  - SQL injection (not applicable; using ORM/OpenRegister)
  - XSS: test form inputs, display fields
  - CSRF: test form submissions without token
  - Authentication bypass: test session validation
  - Authorization: test access control (employee can only view own data)
  - Rate limiting: test brute force scenarios
- [ ] Document findings and fixes
- [ ] Obtain security review approval before production

---

## Phase 7: Testing & QA

### Task 7.1: Unit Testing

- [ ] Write unit tests for:
  - SessionService (create, validate, expire)
  - AuthService (DigiD, SSO, magic-link logic)
  - Workday calculation (LeaveRequest)
  - Input validation (IBAN, date, email formats)
  - MutationApproval (auto-approval logic)
- [ ] Minimum coverage: 80% of business logic
- [ ] Use Jest + @vue/test-utils for Vue components
- [ ] Use PHPUnit for PHP services
- [ ] Run tests on every commit (pre-commit hook or CI)

### Task 7.2: Integration Testing

- [ ] Write end-to-end tests for core flows:
  1. DigiD login → dashboard
  2. Magic-link login → dashboard
  3. Nextcloud SSO → dashboard
  4. Leave request submission → manager approval → balance update
  5. NAW update → MutationApproval created
  6. IBAN update → approval required → rejection workflow
  7. Expense submission → OCR pre-fill → manager approval
  8. Session timeout after 30 min idle
- [ ] Use Playwright or Cypress for browser automation
- [ ] Test on both desktop and mobile viewports
- [ ] Test on slow networks (3G throttle)
- [ ] Run tests nightly or on every release

### Task 7.3: Regression Testing

- [ ] Create regression test suite covering:
  - All REQ-ESS requirements (GIVEN/WHEN/THEN scenarios)
  - Critical user journeys (login, leave request, expense, etc.)
  - Accessibility (axe-core scan)
  - Performance (Lighthouse)
- [ ] Run before each release
- [ ] Document test results in release notes

### Task 7.4: Manual QA & User Acceptance Testing

- [ ] Test on actual mobile devices (iOS + Android):
  - iPhone SE, iPhone 14 (various iOS versions)
  - Samsung Galaxy S10, S23 (various Android versions)
  - Slow 3G and 4G networks
- [ ] Test with real DigiD account (if available) or DigiD test environment
- [ ] Test with screen readers: VoiceOver (iOS), TalkBack (Android)
- [ ] Test keyboard-only navigation on desktop
- [ ] Gather feedback from pilot customers (small MKB businesses)
- [ ] Fix issues found during UAT
- [ ] Document final test report before production launch

### Task 7.5: Deduplication Check (Backend Services)

- [ ] Search openspec/specs/ for existing OpenRegister services:
  - ObjectService — yes, use for CRUD
  - SearchService — yes, use for queries
  - AuthorizationService — yes, use for RBAC
  - NotificationService — yes, use for Nextcloud notifications
  - FileService — yes, use for PDF/receipt storage
  - AuditTrailService — yes, use for change tracking
- [ ] Search existing Vue components:
  - CnDataTable, CnDetailPage, CnFormDialog, CnPagination, CnActionBar — yes, use these
  - No custom list/form/detail components needed
- [ ] Verify no duplication with existing leave, payroll, or expense apps
- [ ] Document findings: "No overlap found. All portal-specific logic is new; all domain logic is delegated."
- [ ] Add deduplication check task to CI (optional; may be manual)

### Task 7.6: Seed Data Generation

- [ ] Create seed data file with realistic test objects:
  - 5 Employee records (diverse names, roles, balances)
  - 10 SelfServiceSession records (various auth methods)
  - 5 MagicLinkToken records (some consumed, some expired)
  - 3 MutationApproval records (pending, approved, rejected)
  - 20 Payslip records (various periods, amounts)
  - 5 LeaveRequest records (various statuses)
  - 3 Expense records (various categories, statuses)
  - 2 Contract records (main contract + addendum)
  - 2 TrainingRequest records (approved, rejected)
- [ ] Use Dutch names and realistic values (valid postcodes, IBANs, etc.)
- [ ] Load seed data on dev/test environment install
- [ ] Verify seed data is idempotent (re-import doesn't create duplicates)
- [ ] Document how to load seed data (README)

---

## Phase 8: Documentation & Release

### Task 8.1: User Documentation

- [ ] Write user guide (Dutch):
  - How to log in (DigiD, SSO, magic-link)
  - How to view payslips and tax certificates
  - How to request leave and check balance
  - How to update NAW information
  - How to request IBAN change (with fraud prevention warning)
  - How to submit expense claims (with camera instructions for mobile)
  - How to view contracts and request training
  - How to update POP goals
  - Troubleshooting: "I can't log in", "Session expired", etc.
- [ ] Create video tutorials (optional) for main flows on mobile
- [ ] Publish on company wiki or help portal

### Task 8.2: Administrator Documentation

- [ ] Write admin setup guide:
  - How to configure DigiD integration
  - How to enable/disable auth methods
  - How to configure leave types and balances
  - How to set up OCR service (local or cloud)
  - How to manage user sessions (logout, reset)
  - How to review audit logs (mutations, approvals)
  - How to troubleshoot common issues
- [ ] Document API endpoints (OpenAPI/Swagger)
- [ ] Document database schema (SelfServiceSession, MagicLinkToken, MutationApproval)
- [ ] Create runbook for incident response (security, performance, bugs)

### Task 8.3: Developer Documentation

- [ ] Write developer setup guide:
  - Clone repo, install dependencies, run tests
  - How to add new screens/features
  - Architecture overview (routing, services, components)
  - How to extend authentication methods
  - How to add new approval workflows
  - Code style guide (ESLint, Prettier config)
- [ ] Document API contract with dependency apps (employee-master, payslip, leave, etc.)
- [ ] Document OpenRegister schema extensions
- [ ] Create troubleshooting guide for common dev issues

### Task 8.4: Release Planning

- [ ] Create release checklist:
  - [ ] All tests pass (unit, integration, regression)
  - [ ] WCAG AA compliance verified (axe-core + manual)
  - [ ] Performance benchmarks met (Lighthouse)
  - [ ] Security review completed (no high/critical issues)
  - [ ] Documentation complete and reviewed
  - [ ] Release notes written
  - [ ] Deployment procedure tested on staging
  - [ ] Rollback procedure documented
  - [ ] Customer communication plan (email, announcement)
- [ ] Set release date (target Q3 2026 for MVP)
- [ ] Plan soft launch to 1-2 pilot customers for feedback
- [ ] Plan general availability based on pilot feedback

### Task 8.5: Post-Release Support

- [ ] Monitor production logs for errors and performance issues
- [ ] Respond to customer support tickets
- [ ] Gather feedback and prioritize fixes/enhancements
- [ ] Release patch versions for critical bugs
- [ ] Plan Phase 2 features (advanced approval workflows, analytics, etc.)

---

## Deduplication Verification Summary

**Task:** Verify no duplication with existing HRMQ services and OpenRegister components.

**Checked services:**
- ✓ ObjectService — use for CRUD on SelfServiceSession, MagicLinkToken, MutationApproval
- ✓ SearchService — use for finding payslips, leave requests, expenses
- ✓ AuthorizationService — use for field-level access control
- ✓ NotificationService — use for Nextcloud notifications
- ✓ FileService — use for PDF and receipt photo storage
- ✓ AuditTrailService — use for automatic change tracking

**Checked components:**
- ✓ CnDataTable — use for list screens
- ✓ CnDetailPage — use for detail screens
- ✓ CnFormDialog — use for form modals
- ✓ CnPagination — use for pagination
- ✓ CnActionBar — use for search/filter/add actions

**Checked dependency apps:**
- ✓ employee-master — source of Employee records; no duplication
- ✓ payslip-generation — source of Payslip PDFs; no duplication
- ✓ leave-management-mvp — source of LeaveRequest; no duplication
- ✓ expense-reimbursement — source of Expense; no duplication
- ✓ training-request — source of TrainingRequest; no duplication
- ✓ contract-management — source of Contract; no duplication
- ✓ performance-management — source of POP goals; no duplication

**Conclusion:** No overlap found. All portal-specific entities (SelfServiceSession, MagicLinkToken, MutationApproval) are new. All domain logic is delegated to existing apps. Full deduplication analysis documented above.

---

## Summary

**Total implementation effort:** ~450-550 hours (6-7 person-months)

**Phase timeline:**
- Phase 1 (Foundation + Payslips + Leave): 6 weeks
- Phase 2 (NAW + Expenses + Contracts): 4 weeks
- Phase 3 (Training): 2 weeks
- Phase 4 (POP): 2 weeks
- Phase 5-7 (Accessibility + Security + Testing): 4 weeks
- Phase 8 (Documentation + Release): 2 weeks

**Team composition:**
- 1-2 Backend developers (PHP, OpenRegister integration)
- 1-2 Frontend developers (Vue 3, Nextcloud Vue)
- 1 QA engineer (testing, accessibility)
- 1 DevOps engineer (CI/CD, deployment)
- 1 Product/Project manager

**Blockers & risks:**
- DigiD broker integration timing (external dependency)
- Mobile testing device access (iPhone + Android)
- Screen reader testing expertise (accessibility)
- Pilot customer availability (UAT feedback)


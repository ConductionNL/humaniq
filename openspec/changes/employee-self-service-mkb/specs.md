---
status: draft
---

# Specifications: Employee Self-Service voor MKB

**Change ID:** employee-self-service-mkb  
**Status:** Specifications Phase  
**Created:** 2026-05-23

## Overview

This document defines detailed requirements for the Employee Self-Service portal. Each requirement follows a GIVEN/WHEN/THEN scenario format and includes acceptance criteria.

Requirements are numbered as `REQ-ESS-NNN` where ESS = Employee Self Service.

---

## Authentication Requirements

### REQ-ESS-001: DigiD Authentication via SAML

Employees can authenticate using their Dutch national ID (DigiD) when the employer has enabled DigiD integration.

**Scenario 1.1: Successful DigiD login with matching BSN**

GIVEN an employer has DigiD integration enabled  
AND an employee "Anna Bakker" has BSN `123456789` in her Employee record  
AND Anna has a valid DigiD account registered with the same BSN  
WHEN Anna opens the portal login page  
AND clicks "Inloggen met DigiD"  
AND is redirected to the Logius DigiD broker  
AND authenticates with her DigiD credentials (BSN `123456789`)  
AND Logius returns a SAML assertion with the matching BSN  
THEN a SelfServiceSession is created with `auth_method=digid` and `auth_subject=sha256(123456789)`  
AND Anna is logged in to the portal  
AND she sees her dashboard with personalized greeting ("Hallo Anna")  

**Acceptance Criteria:**
- SAML assertion signature is validated
- Assertion is not expired (< 5 minutes old)
- BSN from assertion matches exactly one Employee record
- HTTP-only cookie contains `session_token`; token not visible to JavaScript
- Session expires after 8 hours absolute OR 30 minutes of inactivity, whichever comes first

---

**Scenario 1.2: DigiD login with no matching Employee record**

GIVEN a DigiD authentication returns BSN `999999999`  
AND no Employee record has `bsn = 999999999`  
WHEN the portal processes the DigiD callback  
THEN no SelfServiceSession is created  
AND the user is shown an error message: "Geen actief dienstverband gevonden voor dit BSN; neem contact op met je werkgever"  
AND no further action is possible (user must use alternative auth method)

**Acceptance Criteria:**
- Error message is in Dutch and user-friendly
- No session created; no cookies set
- Error is logged for support investigation
- User can retry with alternative auth method

---

**Scenario 1.3: DigiD authentication with expired assertion**

GIVEN an employee initiates DigiD login  
WHEN the DigiD broker returns a SAML assertion that is > 5 minutes old  
THEN the assertion is rejected  
AND the user sees error: "Authenticatie verlopen; probeer opnieuw"  
AND they are prompted to restart the login flow

**Acceptance Criteria:**
- Assertion timestamp validation is strict (not clock-skewed)
- User can immediately retry without manual intervention

---

### REQ-ESS-002: Nextcloud SSO Authentication

Employees who have Nextcloud accounts can log in via OAuth without re-entering credentials.

**Scenario 2.1: Successful SSO login from Nextcloud dashboard**

GIVEN an employee "Jan de Vries" is logged into Nextcloud with uid `jan.devries`  
AND his Employee record has `nc_user_id = jan.devries`  
AND the self-service portal is accessible as a tile on the Nextcloud dashboard  
WHEN Jan clicks the "Self-Service Portaal" tile  
THEN he is redirected to the self-service portal  
AND an OAuth callback handler exchanges the Nextcloud session for portal access  
AND a SelfServiceSession is created with `auth_method=nc_sso` and `auth_subject=jan.devries`  
AND Jan is immediately logged in without seeing a login screen

**Acceptance Criteria:**
- No password entry required (session passthrough)
- Session is created within < 1 second
- If Jan logs out of Nextcloud, portal session remains valid (independent cookie)
- Portal session can be revoked independently of Nextcloud session

---

**Scenario 2.2: SSO login fails due to missing nc_user_id in Employee record**

GIVEN an employee is logged into Nextcloud with uid `alice.smith`  
AND her Employee record does NOT have a matching `nc_user_id`  
WHEN she clicks the portal tile  
THEN the OAuth callback is processed  
AND no SelfServiceSession is created (no matching Employee)  
AND she is redirected to the standard login page: "Je Nextcloud-account is niet gekoppeld aan je personeelsgegevens; probeer DigiD of magic-link"

**Acceptance Criteria:**
- Graceful fallback to alternative auth methods
- No error logs marked as security incident (this is expected behavior)

---

### REQ-ESS-003: Magic-Link Authentication

Employees without DigiD or Nextcloud accounts can log in via a time-limited, single-use email link.

**Scenario 3.1: Successful magic-link flow**

GIVEN an employee "Maria" knows her work email is `maria@empresa.es`  
AND this email exists in her Employee record as `private_email`  
WHEN Maria opens the login page  
AND selects "Inloggen met link"  
AND enters her email address  
AND clicks "Stuur link"  
THEN a MagicLinkToken is generated with:
  - 256-bit random `token`
  - `expires_at = now + 15 minutes`
  - `ip_address_hash = hash(request.ip)` (anonymized)
  - `user_agent = request.user_agent`  
AND an email is sent to `maria@empresa.es` within 30 seconds with:
  - Subject: "Jouw inloglink voor het HR-portaal"
  - Body: Plain text + HTML; includes one link: `https://portal.example.com/auth/magic-link/consume?token=...`
  - No sensitive data in email (no name, no employee ID, no balance info)  
AND Maria sees confirmation: "Als dit e-mailadres bekend is, ontvang je binnen enkele minuten een link"

WHEN Maria clicks the link in her email  
AND the token is not yet consumed  
AND the request IP and User-Agent match the token request (within tolerance)  
THEN MagicLinkToken.consumed_at is set to now  
AND a SelfServiceSession is created with `auth_method=magic_link` and `auth_subject=maria@empresa.es`  
AND Maria is logged in

**Acceptance Criteria:**
- Email sent within 30 seconds of request
- Token is 256-bit random (cryptographically secure)
- Token expires exactly 15 minutes after creation
- Email sent only to registered private_email or work email (prevent enumeration)
- Link valid only once; second click shows "Link expired or already used"
- IP/UA matching prevents trivial replay from different device (not strict; user agent spoofing is assumed low-risk for email-based auth)

---

**Scenario 3.2: Magic-link request for non-existent email**

GIVEN someone requests a magic-link for email `unknown@example.com`  
AND this email does not exist in any Employee record  
WHEN the system processes the request  
THEN no MagicLinkToken is created  
AND no email is sent  
BUT the user still sees: "Als dit e-mailadres bekend is, ontvang je binnen enkele minuten een link"  
(identical message to successful case; prevents email enumeration)

**Acceptance Criteria:**
- Attacker cannot enumerate valid employee emails via the login form
- System logs the non-existent email request (for fraud detection)
- No error message to user

---

**Scenario 3.3: Magic-link already consumed**

GIVEN a MagicLinkToken was generated at 14:00 and consumed at 14:05 by Maria  
WHEN someone (attacker or Maria) tries to use the same token again at 14:10  
THEN the system checks `consumed_at` is not null  
AND the request is rejected  
AND the user sees: "Deze link is al gebruikt; vraag een nieuwe link aan"  
WITH a button "Stuur mij een nieuwe link"

**Acceptance Criteria:**
- Consumed tokens cannot be reused under any circumstances
- User understands they need to request a fresh token
- System logs replay attempts (potential security incident)

---

**Scenario 3.4: Magic-link after expiry**

GIVEN a MagicLinkToken expires at 14:15  
WHEN someone clicks the link at 14:20  
THEN the system checks `expires_at < now`  
AND the token is rejected  
AND the user sees: "Deze link is verlopen (geldig voor 15 minuten); vraag een nieuwe link aan"

**Acceptance Criteria:**
- Expiry is checked server-side; token cannot be extended
- User-friendly error explains why and how to fix

---

---

## Payslip & Tax Certificate Requirements

### REQ-ESS-004: View and Download Payslips

Employees can view a chronological list of payslips and download each as a PDF.

**Scenario 4.1: View payslip list**

GIVEN an employee is logged in  
AND the payslip-generation app has generated payslips for May 2026, April 2026, March 2026 (last 24 months)  
WHEN the employee taps "Loonstroken" in the main menu  
THEN a list is displayed:
  - Sorted newest-first (May 2026 at top)
  - Each row shows: Date, Gross Amount, Net Amount, Download button
  - Pagination: 10 items per page
  - Mobile-friendly: no horizontal scroll; buttons 44×44px minimum

**Acceptance Criteria:**
- List refreshes on each page load (no stale cache > 5 minutes)
- Only payslips for the logged-in employee are shown
- Pagination works on mobile (tap, not scroll)
- Gross and net amounts are formatted with thousand separators and currency symbol (€)

---

**Scenario 4.2: View payslip detail and download PDF**

GIVEN the employee is viewing the payslip list  
WHEN they tap a payslip (e.g., "May 2026 — €2,347.12 net")  
THEN a detail screen opens with:
  - PDF inline viewer (PDFjs, mobile-optimized; zoom, rotate, download buttons)
  - Metadata: Period (May 2026), Gross (€3,500.00), Net (€2,347.12), Tax (€752.38), etc.
  - Download button → file download as `Loonstrook_May2026.pdf`
  - Email button → pre-fills employee's private_email; sends PDF as attachment
  - Back button

WHEN the employee taps "Download"  
THEN the PDF is downloaded to their device (native browser download dialog)

WHEN the employee taps "Stuur naar mijn email"  
THEN:
  - An email is sent to their registered private_email
  - Email includes the PDF as attachment
  - Subject: "Jouw loonstrook mei 2026"
  - User sees confirmation: "E-mail verstuurd naar maria@empresa.es"

**Acceptance Criteria:**
- PDF viewer works on iOS Safari, Chrome Mobile, Firefox Mobile, Samsung Internet
- PDF loads within 3 seconds on 4G, 5 seconds on 3G
- Zoom, rotate, fullscreen work smoothly on mobile
- Email sends within 5 seconds
- Email includes receipt; user informed immediately

---

**Scenario 4.3: Access payslip list on mobile with slow connection**

GIVEN an employee on 3G connection opens the portal  
WHEN they tap "Loonstroken"  
THEN:
  - List begins rendering within 4 seconds (LCP < 4s)
  - List items appear progressively (infinite scroll or pagination)
  - Download button is available and responsive

**Acceptance Criteria:**
- Progressive loading; not blocked by single slow API call
- Performance budget: LCP < 4s on 3G

---

### REQ-ESS-005: View and Download Tax Certificates (Jaaropgaaves)

Employees can view annual tax certificates (jaaropgaaves) and download PDFs.

**Scenario 5.1: View tax certificate list**

GIVEN an employee is logged in  
WHEN they tap "Jaaropgaaves"  
THEN a list is displayed:
  - One row per year (last 7 years)
  - Each row shows: Year, Gross Income, Withheld Tax, Download button
  - Sorted newest-first (most recent year first)

**Acceptance Criteria:**
- Only years with completed payslips are shown
- Amounts match sum of monthly payslips for that year (consistency check in tests)
- Download button downloads as `Jaaropgaaf_2024.pdf` (example)

---

---

## Leave Management Requirements

### REQ-ESS-006: View Leave Balance

Employees can see their available leave by category.

**Scenario 6.1: View leave balance breakdown**

GIVEN an employee is logged in  
WHEN they tap "Verlof"  
THEN a balance screen is displayed:
  - Table with columns: Leave Type, Balance (hours), Used (hours), Remaining (hours)
  - Example rows:
    - Vacation: 160 hours total, 48 hours used, 112 remaining
    - Sick Leave: 40 hours, 8 used, 32 remaining
    - Parental Leave: 120 hours, 0 used, 120 remaining
    - Care Leave: 64 hours, 0 used, 64 remaining
  - All balances shown in hours (standardized across app)
  - Button: "Nieuwe aanvraag"

**Acceptance Criteria:**
- Balances sync with leave-management-mvp app
- Balances update within 5 minutes of a manager approval in admin app
- Displayed in clear Dutch labels

---

### REQ-ESS-007: Submit Leave Request

Employees can request leave with automatic workday calculation.

**Scenario 7.1: Submit vacation request with auto-calculation**

GIVEN an employee is viewing the leave balance  
WHEN they tap "Nieuwe aanvraag"  
THEN a form is displayed:
  - Dropdown: Leave Type (Vacation / Sick Leave / Parental / Care)
  - Date picker: "From" (date input, format DD-MM-YYYY or native mobile picker)
  - Date picker: "To" (same format)
  - Text area: Reason (optional, max 500 chars)
  - Buttons: Submit, Clear

WHEN the employee:
  - Selects "Vacation"
  - Chooses "From: 10-08-2026" (Monday)
  - Chooses "To: 21-08-2026" (Friday)  
THEN the form immediately calculates:
  - Workdays (based on employee's work pattern, default 5-day week): 8 workdays = 64 hours
  - Remaining balance after request: 112 - 64 = 48 hours
  - Display: "This request uses 64 hours. Your remaining balance will be 48 hours."

IF the request would exceed available balance (e.g., 120 hours requested, 112 available)  
THEN a warning is displayed: "⚠️ Requested hours (120) exceed available balance (112). Approval required."

WHEN the employee taps "Submit"  
THEN:
  - LeaveRequest is created with status=pending_approval
  - A Nextcloud Notification is sent to the employee's manager with summary
  - An email is sent to the manager
  - Employee sees confirmation: "Aanvraag ingediend! Je manager ontvangt een bericht."
  - Redirect to leave request list

**Acceptance Criteria:**
- Workday calculation respects employee's work schedule (e.g., part-time, shift patterns)
- Balance calculation is accurate (no off-by-one errors)
- Manager notification arrives within 1 minute
- Employee can cancel draft before submitting
- Form validation requires valid date range (To >= From, dates in future)

---

**Scenario 7.2: Manager approves leave request**

GIVEN a leave request is pending with status=pending_approval  
AND the request was submitted by employee "Maria" to manager "Jan"  
WHEN Jan opens the admin app  
AND navigates to his approval queue  
AND approves Maria's vacation request  
THEN:
  - LeaveRequest.status = approved
  - LeaveRequest.approved_by = jan_user_id
  - LeaveRequest.approved_at = now
  - Maria's leave balance is decremented: vacation 112 - 64 = 48 hours remaining
  - Maria receives a Nextcloud Notification: "Je verlofaanvraag is goedgekeurd! Je vakantiedagensaldo is verlaagd."
  - Maria's portal, when refreshed, shows status "Goedgekeurd" and updated balance

**Acceptance Criteria:**
- Approval is atomic (balance update and status update happen together, or neither)
- Balance in portal reflects approval within 5 minutes
- Employee notification is reliable (logged if failed)

---

**Scenario 7.3: Manager rejects leave request**

GIVEN a pending leave request from Maria  
WHEN Jan rejects it with reason "Approval withheld due to business needs"  
THEN:
  - LeaveRequest.status = rejected
  - LeaveRequest.rejection_reason = "Approval withheld..."
  - Maria's balance is NOT decremented
  - Maria receives notification: "Je verlofaanvraag is afgewezen. Reden: Approval withheld..."
  - Portal shows status "Afgewezen" with rejection reason

**Acceptance Criteria:**
- Rejection reason is sent to employee
- Balance remains unchanged
- Employee can resubmit with different dates

---

---

## NAW (Name, Address, Contact) Mutation Requirements

### REQ-ESS-008: Self-Service NAW Updates (Auto-Approved)

Employees can update their address, phone, and private email without requiring approval.

**Scenario 8.1: Update address**

GIVEN an employee is viewing "Mijn gegevens"  
AND their current address is: Molenstraat 42, 1012 AB, Amsterdam  
WHEN they tap "Edit" next to the address  
AND update to: Kerkplein 8, 1012 AB, Amsterdam  
AND tap "Save"  
THEN:
  - The Employee record is immediately updated (optimistic update)
  - A MutationApproval is created with:
    - `field_name = address_street`
    - `old_value = Molenstraat 42`
    - `new_value = Kerkplein 8`
    - `decision = auto_approved`
    - `decided_at = now`
  - Employee sees confirmation: "Adres bijgewerkt"
  - Manager and HR-admin receive informational notification (not action-required): "Employee updated address"

**Acceptance Criteria:**
- Change persists immediately (no approval wait)
- Full audit trail created in MutationApproval
- Notification to manager/HR is informational only (no approval step)
- Change is reflected in employee-master within 1 minute

---

**Scenario 8.2: Update phone number**

GIVEN an employee is updating their phone number from `+31612345678` to `+31687654321`  
WHEN they save  
THEN:
  - Same process as Scenario 8.1 (auto-approved)
  - MutationApproval created with `field_name = phone`
  - Confirmation message: "Telefoonnummer bijgewerkt"

**Acceptance Criteria:**
- Phone number format validated (international format or Dutch format accepted)
- Change immediately persists

---

**Scenario 8.3: Update private email**

GIVEN an employee updates private_email from `maria@empresa.es` to `maria.newdomain@example.com`  
WHEN they save  
THEN:
  - Auto-approval process; MutationApproval created with `field_name = private_email`
  - Note: For future magic-link auth, both old and new emails are valid for 30 days (grace period)

**Acceptance Criteria:**
- Email format validated (RFC 5322)
- Change effective immediately
- Magic-link can be sent to new email within 1 minute

---

---

## Approval-Gated Mutation Requirements

### REQ-ESS-009: Request IBAN Change (Manager + HR Approval)

Employees can request to change their IBAN, but the change requires manager and HR approval to prevent salary fraud.

**Scenario 9.1: Submit IBAN change request**

GIVEN an employee (Maria) is updating "Mijn gegevens"  
AND her current IBAN is `NL12RABO0123456789`  
WHEN she taps "Edit" next to IBAN  
AND enters new IBAN `NL45ABNA0987654321`  
AND taps "Save"  
THEN:
  - Employee record is NOT changed (old IBAN remains active)
  - A MutationApproval is created with:
    - `field_name = iban`
    - `old_value = NL12RABO0123456789`
    - `new_value = NL45ABNA0987654321`
    - `decision = pending`
  - Maria sees message: "IBAN-wijziging aangevraagd. Dit vereist goedkeuring van je manager en HR. Je ontvangt bericht wanneer dit is afgehandeld."
  - Maria's manager and HR-admin receive HIGH-PRIORITY notifications:
    - Subject: "🔴 IBAN-wijziging aangevraagd - controleer onmiddellijk"
    - Body: "Employee Maria (ID: emp-123) has requested IBAN change. **Verify by phone call before approving.** Old: NL12... New: NL45..."

**Acceptance Criteria:**
- No change to active IBAN until explicitly approved
- Notifications marked high-priority / urgent
- IBAN format validated (must be valid IBAN per mod-97 check)
- Manager and HR both notified; either can approve

---

**Scenario 9.2: HR approves IBAN change after phone verification**

GIVEN a pending MutationApproval for IBAN change  
WHEN HR-admin calls Maria at her registered phone number  
AND verbally confirms the IBAN change is legitimate  
THEN HR-admin, in the admin app, approves the MutationApproval with note: "Telefonisch geverifieerd 2026-05-21 10:15; echtscheiding bevestigd."  
THEN:
  - MutationApproval.decision = approved
  - MutationApproval.approver_id = hr_admin_id
  - MutationApproval.decided_at = now
  - Employee record is updated: IBAN = `NL45ABNA0987654321`
  - Background job schedules salary payment to new IBAN on next payrun
  - Maria receives notification: "Je IBAN-wijziging is goedgekeurd. Vanaf de eerstvolgende loonbetaling staat je salaris op de nieuwe rekening."

**Acceptance Criteria:**
- IBAN change applied atomically (record updated + background job queued together)
- Employee notified within 1 minute of approval
- Change effective on next payrun

---

**Scenario 9.3: HR rejects IBAN change (fraud suspected)**

GIVEN a pending MutationApproval  
WHEN HR-admin attempts phone verification but cannot reach Maria  
AND marks the request as rejected with note: "Niet bereikbaar; fraude-controle vereist"  
THEN:
  - MutationApproval.decision = rejected
  - MutationApproval.approver_id = hr_admin_id
  - MutationApproval.decided_at = now
  - Employee record IBAN is NOT changed
  - Maria receives notification with warning banner: "⚠️ Je IBAN-wijziging is geweigerd. Reden: Niet bereikbaar; fraude-controle vereist. Neem direct contact op met HR."
  - Maria can resubmit with new IBAN

**Acceptance Criteria:**
- Original IBAN remains in effect
- Employee understands the reason and can take corrective action
- System flags the rejection for compliance audit

---

### REQ-ESS-010: Request Other Sensitive Mutations (BSN, Marital Status, Birthdate)

Similar approval-gated workflow for other identity-critical fields.

**Scenario 10.1: Submit marital status change**

GIVEN Maria is getting married  
WHEN she updates marital_status from "married" to "unmarried" (post-echtscheiding)  
THEN:
  - Same approval-gated workflow as IBAN (Scenario 9.1, 9.2, 9.3)
  - MutationApproval created with `field_name = marital_status`
  - Manager + HR notified; HR approves after verbal confirmation
  - On approval, Employee.marital_status is updated

**Acceptance Criteria:**
- Approval workflow identical to IBAN mutations
- HR can request supporting documentation (copy of echtscheidingsbesluit)

---

---

## Expense Reimbursement Requirements

### REQ-ESS-011: Submit Expense Claim

Employees can submit expense claims with receipt photos and OCR-assisted amount extraction.

**Scenario 11.1: Submit business travel expense on mobile**

GIVEN an employee (Maria) is traveling for work  
AND has incurred a taxi expense  
WHEN she opens the portal on mobile  
AND taps "Declaratie"  
THEN a form appears:
  - Date (date picker; default: today)
  - Category (dropdown: Transport, Accommodation, Meals, Office Supplies, Other)
  - Amount (currency input, € format)
  - Description (textarea; max 500 chars)
  - Receipt (camera icon + upload button)

WHEN Maria:
  - Selects Category: "Transport"
  - Enters Amount: €42.50
  - Adds Description: "Taxi Amsterdam Central to hotel"
  - Taps "Foto van bon"

THEN:
  - Camera app opens (mobile native camera)
  - Maria takes a photo of her taxi receipt
  - Photo is uploaded to the form as attachment
  - OCR (qwen3.5 LLM or cloud API) extracts:
    - Amount: €42.50
    - Date: 2026-05-23
  - Form is pre-filled with extracted values (user can verify/correct)

WHEN Maria taps "Indienen"  
THEN:
  - Expense is created in expense-reimbursement app with status=submitted
  - Manager is notified: "Maria has submitted an expense claim for €42.50 (Transport) — awaiting approval"
  - Maria sees confirmation: "Declaratie ingediend. Je manager ontvangt een bericht."
  - Portal shows expense with status "Ingediend"

**Acceptance Criteria:**
- Camera works on iOS and Android
- OCR accuracy > 95% for common receipts (Dutch retailers)
- User can manually correct OCR results before submitting
- Submission includes receipt photo (stored as file)
- Manager notification includes expense summary

---

**Scenario 11.2: Manager approves expense**

GIVEN a submitted expense from Maria  
WHEN manager Jan approves it in the admin app  
THEN:
  - Expense status = approved
  - Maria is notified: "Je declaratie van €42.50 is goedgekeurd. Uitbetaling volgt binnen 5 werkdagen."
  - Portal shows status "Goedgekeurd" with expected payment date

**Acceptance Criteria:**
- Notification includes expected reimbursement date
- Status updates in portal within 2 minutes

---

**Scenario 11.3: Manager rejects expense**

GIVEN a submitted expense  
WHEN Jan rejects with reason "Receipt not legible; please resubmit with clearer photo"  
THEN:
  - Expense status = rejected
  - Maria is notified with rejection reason
  - Portal allows resubmission

**Acceptance Criteria:**
- Rejection reason is clear and actionable
- User can edit and resubmit easily

---

---

## Contract & Addendum Viewing Requirements

### REQ-ESS-012: View Contracts and Addenda

Employees can view their employment contract and all related addenda as PDFs.

**Scenario 12.1: View contract list**

GIVEN an employee is logged in  
WHEN they tap "Contracten"  
THEN a list appears:
  - Table: Type, Date From, Date To, Status, Download
  - Example rows:
    - Contract | 2022-01-01 | (empty) | Active | Download
    - Addendum (Salary Increase) | 2024-01-01 | (empty) | Signed | Download
    - Addendum (Hours Change) | 2023-06-01 | (empty) | Signed | Download

**Acceptance Criteria:**
- Sorted by date (most recent first)
- Only contracts for logged-in employee shown
- Archived contracts included (with end date)

---

**Scenario 12.2: View contract detail**

GIVEN an employee taps on the main contract (2022-01-01)  
THEN a detail screen appears with:
  - Summary: Function (Senior Developer), Salary Scale (Scale 10), Hours (40 hrs/week), End Date (None — indefinite)
  - PDFjs inline viewer with contract PDF
  - Tab: "Addenda" showing all related addenda
  - Signature status (if Decidesk integration enabled): "Pending your signature" or "Signed on 2022-01-05"

**Acceptance Criteria:**
- PDF loads and displays correctly on mobile
- Summary matches contract content (consistency check)
- Addenda sublist is clickable to view each

---

**Scenario 12.3: View addendum (salary increase)**

GIVEN employee taps on an addendum  
THEN a detail screen similar to Scenario 12.2 appears  
WITH PDF inline viewer and signature status

**Acceptance Criteria:**
- Addendum PDFs display correctly
- Signature status syncs with Decidesk

---

---

## Training Request Requirements

### REQ-ESS-013: Submit Training Request

Employees can propose training opportunities and submit requests for manager approval with budget check.

**Scenario 13.1: Submit new training request**

GIVEN an employee (Maria) is interested in a React advanced course  
WHEN she taps "Opleidingen" → "Nieuwe aanvraag"  
THEN a form appears:
  - Title (text input; required)
  - Provider (text input; e.g., "Udemy")
  - Cost (currency input, € format; required)
  - Start Date (date picker; required)
  - End Date (date picker; optional)
  - Link to Course (URL; optional)
  - Motivation (textarea; max 1000 chars; required)
  - Buttons: Submit, Cancel

WHEN Maria fills in:
  - Title: "React Advanced Patterns"
  - Provider: "Frontend Masters"
  - Cost: €599.00
  - Start Date: 2026-06-15
  - End Date: 2026-06-30
  - Link: "https://frontendmasters.com/courses/react-patterns"
  - Motivation: "This course will improve my ability to design scalable React applications, directly applicable to our current projects."

AND taps "Submit"  
THEN:
  - TrainingRequest is created in training-request app with status=submitted
  - Manager is notified: "Maria has requested training: React Advanced Patterns (€599). Awaiting your approval and budget verification."
  - Training-request app performs budget check against manager's training budget
  - Maria sees confirmation and expected decision date (e.g., "typically decided within 5 working days")

**Acceptance Criteria:**
- Form validation: all required fields filled
- Cost is validated (positive number, reasonable range)
- Notification includes training summary and cost
- Budget check is asynchronous; user sees status "Budget under review"

---

**Scenario 13.2: Manager approves training request**

GIVEN a submitted training request  
WHEN manager Jan approves in the admin app  
AND the budget system confirms budget availability  
THEN:
  - TrainingRequest.status = approved
  - TrainingRequest.approved_by = jan_user_id
  - Maria receives notification: "Je trainingsaanvraag 'React Advanced Patterns' is goedgekeurd! Kosten (€599) zijn gereserveerd. Volg je inschrijving op de aangegeven link."
  - Portal shows status "Goedgekeurd" with instructions

**Acceptance Criteria:**
- Budget is reserved (deducted from manager's YTD training budget)
- Employee gets clear enrollment instructions
- Status updates within 5 minutes of approval

---

**Scenario 13.3: Training request rejected (budget not available)**

GIVEN a submitted training request for €599  
WHEN the budget check fails (insufficient remaining budget for the year)  
THEN:
  - TrainingRequest.status = rejected
  - TrainingRequest.rejection_reason = "Insufficient training budget for this year (€500 remaining vs. €599 requested)"
  - Manager is notified (not required to approve; auto-rejected by budget system)
  - Maria receives notification with rejection reason and can resubmit next fiscal year

**Acceptance Criteria:**
- Budget check is transparent to employee
- Rejection reason explains why
- Employee can retry after budget refreshes

---

---

## Personal Development (POP) Requirements

### REQ-ESS-014: View and Update POP Goals

Employees can view their personal development objectives and submit progress updates.

**Scenario 14.1: View POP goals**

GIVEN an employee is logged in  
WHEN they tap "Mijn ontwikkeling"  
THEN a goal list appears:
  - Table: Goal, KPI, Deadline, Status, Progress
  - Example rows:
    - Improve code review skills | 2 code reviews per week | 2026-12-31 | On track | 30% (6 reviews completed)
    - Learn Kubernetes | Deploy 1 application | 2026-09-30 | Attention | 50% (drafted deployment plan)

**Acceptance Criteria:**
- Goals for current fiscal year shown
- Status indicators: On track / Attention / Completed / Not started
- Progress percentage shown

---

**Scenario 14.2: View goal detail**

GIVEN employee taps on a goal (e.g., "Improve code review skills")  
THEN a detail screen appears:
  - Goal description: "Master the ability to provide constructive code reviews, mentor junior developers, and identify architectural patterns in pull requests."
  - KPI: "2 code reviews per week"
  - Deadline: 2026-12-31
  - Progress: 30% (6 reviews completed, target 50 by mid-year)
  - Latest update (by employee): "2026-05-20 — Completed reviews on 4 PRs this week; focused on architecture feedback."
  - Button: "Voeg update toe"

**Acceptance Criteria:**
- Goal details match data in performance-management app
- Progress history is read-only

---

**Scenario 14.3: Submit goal progress update**

GIVEN an employee is viewing goal detail  
WHEN they tap "Voeg update toe"  
THEN a form appears:
  - Textarea: Progress update (max 1000 chars)
  - Buttons: Submit, Cancel

WHEN they enter: "Completed 3 code reviews this week. Mentored Junior Dev on async patterns."  
AND tap "Submit"  
THEN:
  - Progress update is recorded with timestamp
  - Goal detail is refreshed with the new update
  - Confirmation: "Update opgeslagen"

**Acceptance Criteria:**
- Update saved with employee ID and timestamp
- Visible immediately on refresh
- Manager can view progress updates in admin app

---

---

## Session Management Requirements

### REQ-ESS-015: Session Timeout & Idle Logout

Sessions expire based on idle time and absolute age, per NIST SP 800-63B AAL2.

**Scenario 15.1: Session expires due to inactivity (30 minutes)**

GIVEN an employee logs in at 14:00  
AND is inactive for 30 minutes (no API calls, no page navigation)  
WHEN they attempt to access a page at 14:31  
THEN:
  - The request is rejected (no valid SelfServiceSession)
  - User is redirected to login with message: "Sessie verlopen door inactiviteit. Log alsjeblieft opnieuw in."
  - User must re-authenticate

**Acceptance Criteria:**
- Activity is tracked per API call (not just page load)
- Idle timeout is exactly 30 minutes from last activity
- User sees friendly message explaining why

---

**Scenario 15.2: Session expires due to absolute age (8 hours)**

GIVEN an employee logs in at 08:00  
WHEN they attempt to access the app at 16:01 (> 8 hours later)  
THEN:
  - Session is forcibly expired
  - User is logged out
  - User sees message: "Sessie verlopen (maximale duur bereikt). Log opnieuw in."

**Acceptance Criteria:**
- Absolute timeout is strictly enforced (no extension beyond 8 hours)
- User must fully re-authenticate (no refresh token extension)

---

**Scenario 15.3: Idle timeout warning**

GIVEN a session is valid and approaching idle timeout (5 minutes remaining)  
WHEN the user is active on a page  
THEN:
  - A non-intrusive banner appears: "Je sessie verloopt over 5 minuten door inactiviteit. Klik hier om in te blijven loggen."
  - Button: "In loggen" (extends session by re-activating)

WHEN user clicks the button  
THEN:
  - Session idle timeout is reset to now + 30 minutes
  - Warning banner disappears

**Acceptance Criteria:**
- Warning appears > 5 minutes before actual timeout
- Does not interrupt user work
- Extension is seamless (no re-authentication)

---

---

## Accessibility Requirements

### REQ-ESS-016: WCAG 2.1 AA Compliance

The portal meets WCAG 2.1 Level AA accessibility standards across all core screens.

**Scenario 16.1: Screen reader navigation (VoiceOver iOS, TalkBack Android)**

GIVEN an employee using VoiceOver on iPhone  
WHEN they navigate through the payslip list screen  
THEN the screen reader announces:
  - Page heading: "Loonstroken"
  - List: "List with 10 items"
  - First item: "May 2026, gross €3,500, net €2,347.12, download button"
  - Interactive elements are announced with role and state (button, link, etc.)

**Acceptance Criteria:**
- All content is accessible via screen reader
- Form labels associated with inputs (implicit or explicit)
- Buttons have descriptive text (not generic "Click Here")
- List structure is semantic (`<ul>`, `<li>` or `<table>`)
- Navigation is logical (Tab order matches visual order)

---

**Scenario 16.2: Keyboard-only navigation**

GIVEN an employee using keyboard only (no mouse/touch)  
WHEN they open the login page  
AND press Tab repeatedly  
THEN:
  - Focus moves through all interactive elements in logical order
  - Focus is always visible (outline or highlight)
  - All buttons, links, form fields are reachable via Tab
  - No keyboard trap (user can always Tab forward and backward to escape)

**Acceptance Criteria:**
- Focus indicator has sufficient contrast (3:1 minimum, 4.5:1 preferred)
- Tab order matches visual reading order
- Enter/Space activates buttons correctly
- Escape closes modals/dialogs

---

**Scenario 16.3: Touch target size**

GIVEN an employee on a mobile phone  
WHEN they interact with any button, link, or form field  
THEN:
  - All touch targets are at least 44×44 CSS pixels
  - Touch targets have adequate spacing (not cramped)

**Acceptance Criteria:**
- Verified via browser DevTools or automated axe-core scan
- Links in text (e.g., "click here") are not used; instead, buttons or explicit links

---

**Scenario 16.4: Color contrast**

GIVEN automated WCAG scan via axe-core  
WHEN running on all core screens (login, payslips, leave, etc.)  
THEN:
  - Body text: contrast ratio 4.5:1 (dark text on light, or vice versa)
  - UI components (buttons, form inputs): contrast ratio 3:1 minimum
  - Links: underlined or sufficiently different color (not just color alone)

**Acceptance Criteria:**
- axe-core automated scan: 0 AA violations
- Manual verification of edge cases (disabled buttons, hover states)

---

**Scenario 16.5: Responsive design (mobile-first 320px+)**

GIVEN an employee on iPhone SE (375px viewport width)  
WHEN they navigate through all core screens  
THEN:
  - All content fits within viewport without horizontal scrolling
  - Text is readable (not zoomed out too small)
  - Buttons and form fields are usable on small screen

**Acceptance Criteria:**
- Tested on 320px, 375px, 768px, 1024px breakpoints
- No horizontal scrolling at 320px
- Font sizes: body 14px+ (scaled for mobile); headings 20px+
- Tested on actual devices (iPhone SE, Samsung Galaxy S10) or via responsive testing tools

---

### REQ-ESS-017: Mobile-First Performance

The portal is optimized for mobile networks (3G / 4G) and devices.

**Scenario 17.1: Page load performance on 4G**

GIVEN an employee on a 4G mobile connection  
WHEN they open a page (e.g., payslip list)  
THEN:
  - First Contentful Paint (FCP): < 2 seconds
  - Largest Contentful Paint (LCP): < 3 seconds
  - Time to Interactive (TTI): < 4 seconds
  - Cumulative Layout Shift (CLS): < 0.1

**Acceptance Criteria:**
- Measured via Lighthouse or WebPageTest
- Average across 3 runs on 4G throttle
- Bundle size < 500KB for core routes

---

**Scenario 17.2: Page load performance on 3G**

GIVEN an employee on a 3G connection  
WHEN they open a page  
THEN:
  - FCP: < 4 seconds
  - LCP: < 5 seconds
  - TTI: < 7 seconds

**Acceptance Criteria:**
- Graceful degradation: pages usable before LCP (skeleton loading, progressive rendering)
- No blocking JavaScript; critical CSS inlined

---

**Scenario 17.3: Offline handling**

GIVEN an employee loses connection (goes into tunnel, airplane mode)  
WHEN they are viewing a cached page (e.g., leave balance)  
THEN:
  - Content remains visible
  - Actionable elements (Submit, Edit) show "Offline" indication
  - On reconnection, app syncs pending changes

**Acceptance Criteria:**
- Service worker caches core screens + API responses
- User is informed of offline status
- No data loss when reconnecting

---

---

## Security Requirements

### REQ-ESS-018: Data Protection & HTTPS

All communication is encrypted via TLS 1.3.

**Scenario 18.1: HTTPS enforcement**

GIVEN any request to the portal (login, API calls, PDF downloads)  
WHEN the request is made over HTTP  
THEN:
  - Request is redirected to HTTPS
  - No HTTP endpoints are publicly accessible

**Acceptance Criteria:**
- HSTS header set (max-age >= 31536000 seconds)
- All cookies flagged Secure and HttpOnly
- No mixed content (HTTP resources on HTTPS page)

---

### REQ-ESS-019: CSRF Protection

Cross-site request forgery attacks are prevented.

**Scenario 19.1: CSRF token validation on mutations**

GIVEN an employee submits a form (leave request, expense, etc.)  
WHEN the request is made  
THEN:
  - Request includes CSRF token (from session or form)
  - Server validates token matches session
  - Invalid/missing token: request rejected with 403 Forbidden

**Acceptance Criteria:**
- Token generated per session
- Token regenerated after login
- Token validated on all POST/PATCH/DELETE requests
- Validation logged for security audits

---

### REQ-ESS-020: Rate Limiting

Brute force and abuse attacks are mitigated.

**Scenario 20.1: Login attempt rate limit**

GIVEN an attacker attempts 10 failed logins for email `target@example.com` within 15 minutes  
WHEN the 6th attempt is made  
THEN:
  - Subsequent requests are rate-limited: "Too many login attempts. Try again in 15 minutes."
  - IP address is logged for investigation

**Acceptance Criteria:**
- Max 5 failed auth attempts per email per 15 minutes
- User can reset by requesting a new magic-link (after waiting)

---

**Scenario 20.2: Mutation rate limit**

GIVEN an employee attempts to submit 15 mutations within 1 hour  
WHEN the 11th is attempted  
THEN:
  - Request is rejected: "Too many changes requested. Max 10 per day. Try again tomorrow."

**Acceptance Criteria:**
- Max 10 sensitive mutations per employee per 24 hours
- Prevents accidental/malicious data churn

---

---

## Summary of Acceptance Criteria Verification

All scenarios above MUST be verified during testing:

1. **Automated Tests:** Unit tests for auth logic, calculations (workdays, balances), validation
2. **Integration Tests:** End-to-end flows (login → leave request → approval)
3. **Manual Testing:** Mobile devices, screen readers, keyboard navigation
4. **Performance Testing:** Lighthouse, WebPageTest on 4G and 3G
5. **Accessibility Audit:** axe-core scan + manual WCAG review on 12 core screens
6. **Security Testing:** OWASP Top 10 checklist; penetration testing on auth flows


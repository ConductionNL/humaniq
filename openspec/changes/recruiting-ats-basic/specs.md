---
status: draft
---

# Specifications: Recruiting ATS Basic voor HRMQ

## REQ-001: Vacancy Creation & Publication

A recruiter creates a vacancy, selects publication channels, and publishes to externe platforms (werk.nl, LinkedIn, company career page) with a single click.

### Scenario 1.1: Create and publish to multiple channels

- **WHEN** a user with role `recruiter` or `hiring_manager` clicks "Nieuwe vacature"
- **THEN** the form displays all Vacancy fields: titel, functie_schaal, locatie, contract_type, uren_per_week_min/max, salaris_indicatie_min/max, salaris_zichtbaar (toggle), beschrijving (markdown editor), eisen (markdown editor), aangeboden (markdown editor), sluitingsdatum, gewenste_startdatum, hiring_manager_id, and publicatie_kanalen (multi-select: werknl, linkedin, eigen_site, indeed_zelf)
- **AND** the form has sections for each group (Job Info, Salary & Hours, Description, Channels)

### Scenario 1.2: Publish triggers external integrations

- **WHEN** a recruiter fills the form and clicks "Publiceer"
- **THEN** the Vacancy status changes from `concept` to `open`
- **AND** a background job publishes the vacancy to each selected channel:
  - werk.nl: POST to werk.nl API with titel, locatie (postcode), contract_type, uren_per_week_min/max, salaris_indicatie_min/max, and a callback URL pointing to the HRMQ career page
  - LinkedIn: POST to LinkedIn Talent Hub API to create a job posting under the company page
  - eigen_site: Vacancy appears immediately on the public career page with full description
- **AND** an ApplicationEvent of type `vacancy_created` is logged
- **AND** a notification is sent to hiring_manager_id

### Scenario 1.3: Update published vacancy syncs to all channels

- **WHEN** a vacancy is in `open` status and the recruiter edits beschrijving, salaris, or locatie and clicks "Update gepubliceerd"
- **THEN** the changes are synced to all active channels (werk.nl, LinkedIn, eigen_site)
- **AND** an ApplicationEvent of type `vacancy_updated` is logged with a diff of what changed
- **AND** hiring_manager receives a notification of the update

### Scenario 1.4: Close vacancy withdraws from external channels

- **WHEN** a recruiter changes Vacancy status from `open` to `gesloten`, `vervuld`, or `ingetrokken`
- **THEN** background jobs post withdrawal/close signals to werk.nl and LinkedIn
- **AND** the vacancy no longer appears in new job feeds (maar stays searchable in history for archived applications)
- **AND** an ApplicationEvent of type `vacancy_closed` is logged

---

## REQ-002: Application Ingestion

Applications arrive from multiple sources (public career page, werk.nl, LinkedIn Easy Apply) and land in the pipeline at `nieuwe_sollicitatie` stage.

### Scenario 2.1: Application via public career page

- **WHEN** a candidate visits the public career page, clicks on a vacancy, and sees the "Solliciteer" button
- **THEN** a form appears with fields: naam, email, telefoon, CV (file upload, required), motivatie (file upload, optional), motivatie_inline_text (textarea, optional)
- **AND** form validation requires: naam (non-empty), email (valid format), telefoon (non-empty), CV (PDF or image, <10 MB)

- **WHEN** the candidate submits the form
- **THEN** an Application is created with:
  - `kandidaat_naam` = form.naam
  - `kandidaat_email` = form.email
  - `kandidaat_telefoon` = form.telefoon
  - `cv_file_id` = uploaded file ID
  - `motivatie_file_id` or `motivatie_inline_text` = form content
  - `ingediend_op` = current timestamp
  - `bron` = "eigen_site"
  - `huidige_pipeline_stage` = "nieuwe_sollicitatie"
  - `talent_pool_consent` = false
  - `delete_after_date` = now() + 28 days
- **AND** an ApplicationEvent of type `created` is logged
- **AND** the hiring_manager_id for the vacancy receives a Nextcloud Notification: "Nieuwe sollicitatie: [kandidaat_naam] voor [vacancy.titel]"
- **AND** the candidate receives a confirmation email with their application status page link

### Scenario 2.2: Application via LinkedIn Easy Apply webhook

- **WHEN** a LinkedIn candidate clicks "Easy Apply" and submits via LinkedIn's interface
- **THEN** a webhook POST arrives at `/api/webhooks/linkedin/applications` with LinkedIn candidate data (name, email, LinkedIn profile URL)
- **AND** a background job fetches the candidate's LinkedIn profile as JSON
- **AND** from the profile, extracts: name, email, work history, education, skills
- **AND** generates a PDF-format CV from the LinkedIn profile data
- **AND** creates an Application with:
  - `kandidaat_naam` = LinkedIn name
  - `kandidaat_email` = LinkedIn email
  - `cv_file_id` = generated PDF
  - `motivatie_inline_text` = LinkedIn "message to recruiter" (if provided)
  - `bron` = "linkedin"
  - `huidige_pipeline_stage` = "nieuwe_sollicitatie"
  - `delete_after_date` = now() + 28 days
- **AND** an ApplicationEvent of type `created` is logged
- **AND** hiring_manager receives the same notification

### Scenario 2.3: Validation: CV is required

- **WHEN** a candidate submits the application form on the public career page without a CV
- **THEN** the form displays a validation error: "CV is verplicht"
- **AND** no Application is created
- **AND** if submitted via API, returns HTTP 422 with error message

### Scenario 2.4: Application via werk.nl redirect (future)

- **WHEN** a candidate applies via werk.nl and is redirected to HRMQ with a werk.nl session token and vacancy_id
- **THEN** an Application is created with `bron = "werknl"` and the candidate can complete the form (or auto-fill from werk.nl data)
- **AND** the Application links back to the werk.nl posting for tracking

---

## REQ-003: Pipeline Management & Kanban Board

Recruiters move applications through pipeline stages via a visual kanban board.

### Scenario 3.1: View pipeline board

- **WHEN** a recruiter opens the Vacatures+Kandidaten module and selects a vacancy
- **THEN** a kanban board displays with one column per enabled PipelineStage for that vacancy
- **AND** each column shows Application cards sorted by ingediend_op (newest first)
- **AND** each card shows: kandidaat_naam, ingediend_op (relative date, e.g., "2 days ago"), bron (icon), and a quick-view toggle
- **AND** column headers show stage name and count (e.g., "Screening (12)")
- **AND** disabled stages are hidden from the board

### Scenario 3.2: Drag & drop application between stages

- **WHEN** a recruiter drags an Application card from the `screening` column to the `eerste_gesprek` column
- **THEN** the card animates to the new column
- **AND** Application.huidige_pipeline_stage is updated to `eerste_gesprek`
- **AND** an ApplicationEvent of type `stage_change` is logged with:
  - `from_stage = "screening"`
  - `to_stage = "eerste_gesprek"`
  - `actor_id = current_user_id`
  - `event_at = now()`
- **AND** a dialog pops up: "Plan je al een gesprek met [kandidaat_naam]?" with buttons "Ja, plan nu" and "Later"
- **AND** if "Ja, plan nu" is clicked, the interview-planning dialog opens immediately

### Scenario 3.3: Bulk rejection

- **WHEN** a recruiter selects multiple Application cards in the `screening` column (via checkboxes) and clicks "Afwijzen"
- **THEN** a dialog shows: "Reject [N] candidates" with a rejection email template (pre-filled markdown)
- **AND** the recruiter can edit the template text
- **AND** the template includes a link: "[Blijf in onze talent pool](talent-pool-opt-in-link)"

- **WHEN** the recruiter clicks "Bevestig afwijzing"
- **THEN** all selected Applications move to `afgewezen` stage
- **AND** for each, an ApplicationEvent of type `stage_change` (to `afgewezen`) and `email_sent` (rejection) are logged
- **AND** the rejection email is sent to each kandidaat_email with the template text and talent-pool opt-in link
- **AND** delete_after_date is set to now() + 28 days for each
- **AND** a notification is sent to recruiter: "Rejection emails sent to [N] candidates"

### Scenario 3.4: Click to see application details

- **WHEN** a recruiter clicks on an Application card in the kanban board
- **THEN** a side panel or modal opens showing:
  - Candidate info: naam, email, telefoon, CV (link to view), motivatie (link to view)
  - Current stage: huidige_pipeline_stage
  - Timeline: all ApplicationEvents (stage changes, emails sent, interviews scheduled, notes)
  - Quick actions: "Plan gesprek", "Verzenden e-mail", "Notitie toevoegen", "Afwijzen", "Terugtrekken"

---

## REQ-004: Interview Planning & Nextcloud Calendar Integration

Recruiters schedule interviews; Calendar events are auto-created for all interviewers and candidates.

### Scenario 4.1: Plan interview

- **WHEN** a recruiter clicks "Plan gesprek" for an Application in `eerste_gesprek` or `tweede_gesprek` stage
- **THEN** a form opens with fields:
  - Interviewers: multi-select (searchable, showing employee list)
  - Interview type: radio buttons (telefonisch, video, in_person)
  - Date & time: date picker + time picker, showing availability of selected interviewers
  - Kandidaat mag kalender zien? (toggle, default true)

- **WHEN** the recruiter fills the form and clicks "Plan"
- **THEN** an Interview record is created with:
  - `application_id` = current Application
  - `interview_type` = selected type
  - `start_at` = selected time
  - `end_at` = start_at + 45 minutes (default; can be adjusted)
  - `interviewers` = [employee IDs]
  - `kandidaat_zichtbaar` = toggle value
- **AND** a Nextcloud Calendar event is created with:
  - Title: `Sollicitatiegesprek [kandidaat_naam] – [vacancy.titel]`
  - Description: Interview type (video/phone/in-person), candidate email, CV link, and if video: a Nextcloud Talk link
  - Start/End: as specified
  - Attendees: all interviewers + (optionally) candidate email
- **AND** if `kandidaat_zichtbaar = true`, the candidate receives an iCal invite (`.ics` file) at kandidaat_email with:
  - Event details
  - Talk link (if video)
  - Calendar invite (add to calendar via email client)
- **AND** an ApplicationEvent of type `interview_scheduled` is logged with interview details in payload_json
- **AND** all interviewers receive a Nextcloud Notification: "Sollicitatiegesprek gepland met [kandidaat_naam]"

### Scenario 4.2: Post-interview feedback

- **WHEN** an interview has passed (start_at + duration elapsed)
- **THEN** the recruiter opens the Application and clicks "Feedback geven"
- **AND** a form appears with fields:
  - Notities: textarea (pre-interview notes are shown for reference)
  - Score: 1-5 slider or NPS-style
  - Aanbeveling: radio buttons (doorschuiven naar volgende ronde, afwijzen, hold)

- **WHEN** the recruiter submits the feedback
- **THEN** the Interview record is updated with `notities_post` and `score`
- **AND** if "doorschuiven naar volgende ronde" is selected, the Application stage is auto-advanced (e.g., `eerste_gesprek` → `tweede_gesprek`)
- **AND** an ApplicationEvent of type `interview_completed` is logged with the feedback in payload_json
- **AND** a notification is sent to hiring_manager: "Interview feedback ready for [kandidaat_naam]"

---

## REQ-005: Offer Letter Generation & Digital Signature

Recruiters generate offer letters from templates, fill in salary/terms, and send via Decidesk for e-signature.

### Scenario 5.1: Generate offer letter

- **WHEN** a recruiter clicks "Uitbrengen aanbod" for an Application in `tweede_gesprek` or later stage
- **THEN** a form opens with fields:
  - Template: dropdown (e.g., "Vast contract standaard", "Tijdelijk 1 jaar", "Freelance")
  - Salary details: salaris_bruto_per_maand (calculated from annual if provided), salaris_bruto_jaar
  - Contract terms: startdatum, proeftijd_maanden, contract_duur_maanden (optional; null = indefinite)
  - Benefits: vakantiedagen_per_jaar, extra_voorwaarden_markdown (free text for bonuses, allowances, etc.)
  - Preview: live preview of the offer letter as HTML/PDF

- **WHEN** the recruiter clicks "Genereer PDF"
- **THEN** the selected template is rendered with all filled-in values
- **AND** the PDF is generated and saved as a file-attachment (file_id stored in OfferLetter.generated_pdf_file_id)
- **AND** an OfferLetter record is created with all fields populated
- **AND** the Application stage does NOT yet change (remains in same stage until offer is accepted or rejected)

### Scenario 5.2: Send offer via Decidesk for e-signature

- **WHEN** the offer PDF is generated and the recruiter clicks "Stuur ter ondertekening"
- **THEN** a Decidesk envelope is created with:
  - Document: the generated PDF
  - Signers: [candidate email] (signer), [hiring_manager or authorized HR user] (counter-signer)
  - Notification: send email to candidate with signing link
- **AND** the Decidesk envelope ID is saved to OfferLetter.decidesk_envelope_id
- **AND** an ApplicationEvent of type `offer_sent` is logged
- **AND** the Application stage is updated to `aanbieding_uitgebracht`
- **AND** a notification is sent to the hiring_manager: "Offer letter sent to [kandidaat_naam] for e-signature"

### Scenario 5.3: Offer accepted via Decidesk webhook

- **WHEN** both the candidate and counter-signer have signed the Decidesk envelope
- **THEN** a Decidesk webhook POST arrives at `/api/webhooks/decidesk/envelopes/signed`
- **AND** the backend matches the envelope_id to the OfferLetter
- **AND** updates the OfferLetter with `geaccepteerd_op = now()`
- **AND** updates the Application stage to `geaccepteerd`
- **AND** an ApplicationEvent of type `offer_accepted` is logged
- **AND** notifications are sent to hiring_manager and hr_admin: "Offer accepted by [kandidaat_naam]; ready to start onboarding"

### Scenario 5.4: Offer expires if not signed

- **WHEN** an OfferLetter is created with verlopen_op = now() + 14 days
- **THEN** if the candidate does not sign within 14 days, the offer is considered expired
- **AND** a daily job sends a reminder email to the candidate after 10 days: "Your offer expires in 4 days. Click here to sign."
- **AND** after expiry, the Application can be moved back to `tweede_gesprek` or `afgewezen` by the recruiter

---

## REQ-006: Hand-Off to Onboarding & Employee Creation

When an Application reaches `aangenomen` status, an Employee record is created and onboarding-wizard is launched with pre-filled data.

### Scenario 6.1: Trigger onboarding on hire decision

- **WHEN** an Application is in stage `geaccepteerd` and the recruiter (or hiring_manager) clicks "Start onboarding"
- **OR** a background job automatically transitions to `aangenomen` if all required checks are passed (offer signed + referentie_check completed, or referentie_check is disabled)
- **THEN** the following sequence happens:

  1. Application stage changes to `aangenomen`
  2. An Employee record is created in employee-master with:
     - `voornaam` = candidate.kandidaat_naam (split on first space)
     - `achternaam` = remaining part of kandidaat_naam
     - `email` = kandidaat_email
     - `telefoon` = kandidaat_telefoon
     - `functie` = vacancy.titel
     - `schaal` = vacancy.functie_schaal (if applicable)
     - `startdatum` = offer.startdatum
     - `contract_type` = vacancy.contract_type
     - `uren_per_week` = (vacancy.uren_per_week_min + vacancy.uren_per_week_max) / 2 (average)
     - `salaris_bruto_per_maand` = offer.salaris_bruto_per_maand
     - `vakantiedagen` = offer.vakantiedagen_per_jaar
  3. The onboarding-wizard is launched with context: `application_id = app.id`, pre-fill enabled
  4. An ApplicationEvent of type `hand_off_to_onboarding` is logged
  5. Notifications are sent to: hr_admin (Employee created), hiring_manager (onboarding started), onboarding_coordinator (new onboarding task)

### Scenario 6.2: Archive Application after onboarding completion

- **WHEN** the onboarding-wizard completes all steps (paperwork, asset checklist, payroll setup)
- **THEN** the Application is archived (read-only flag set)
- **AND** a link from Application.archived_employee_id points to the newly created Employee
- **AND** the CV and motivatie files remain accessible but are now tagged as "archived"
- **AND** the Application UI shows a "Hired — see Employee [link]" badge

---

## REQ-007: GDPR-Compliant Retention & Talent Pool Consent

Rejected applications are auto-deleted after 28 days unless the candidate opts into the talent pool (then 1 year).

### Scenario 7.1: Set deletion timer on rejection

- **WHEN** an Application moves to stage `afgewezen`
- **THEN** the system sets `delete_after_date = now() + 28 days`
- **AND** the rejection email to the candidate includes:
  - Friendly rejection message (customizable template)
  - A link: "[Blijf in onze talent pool voor toekomstige kansen](talent-pool-opt-in-link)" with a unique token
  - Notice: "We'll keep your data for 4 weeks. Click the link above to extend to 1 year."

### Scenario 7.2: Candidate opts into talent pool

- **WHEN** the candidate clicks the talent-pool opt-in link within 28 days
- **THEN** a form appears (no login required) confirming: "Do you want to stay in our talent pool? We'll keep your CV for 1 year and may contact you for future roles matching your profile."
- **AND** checkbox: "Yes, add me to the talent pool" and a submit button

- **WHEN** the candidate checks the box and clicks "Confirm"
- **THEN** the Application is updated with:
  - `talent_pool_consent = true`
  - `talent_pool_consent_at = now()`
  - `delete_after_date = now() + 365 days` (extended)
- **AND** an ApplicationEvent of type `talent_pool_consent_given` is logged
- **AND** a confirmation email is sent: "You're now in our talent pool. [Opt-out link]"

### Scenario 7.3: Automatic anonymization batch

- **WHEN** the daily GDPR retention job runs at 2 AM
- **THEN** it finds all Applications where `delete_after_date < now()` and `talent_pool_consent = false` (or expired)
- **AND** for each Application:
  - Sets `kandidaat_naam = NULL`
  - Sets `kandidaat_email = NULL`
  - Sets `kandidaat_telefoon = NULL`
  - Deletes or overwrites CV and motivatie files
  - Deletes or masks any PII from ApplicationEvent notes
  - Retains: `vacancy_id` (for statistics), `ingediend_op` (for trend analysis), `bron` (for ROI analysis), `huidige_pipeline_stage = "afgewezen"`, anonymized rejection reason
  - Logs an ApplicationEvent of type `gdpr_anonymized` with timestamp and actor = "system"
- **AND** a report is generated for the HR-admin showing X applications anonymized, Y files deleted

### Scenario 7.4: Candidate can request data export (AVG DSR)

- **WHEN** a candidate sends a Data Subject Request (DSR) to the HR-admin
- **THEN** the HR-admin opens the candidate's Application and clicks "Export all data"
- **AND** a JSON/PDF export is generated containing:
  - All Application fields (before anonymization)
  - All ApplicationEvent log entries
  - All Interview records and notes
  - All OfferLetter details
  - CV and motivatie files attached
- **AND** the export is sent to the candidate's email within 30 days (per GDPR art. 15)

---

## REQ-008: Talent Pool Search & Proactive Outreach

Recruiters search the talent pool and reach out to qualified candidates for new vacancies.

### Scenario 8.1: Search talent pool

- **WHEN** a recruiter opens the "Talent pool" view
- **THEN** a search box appears with filters:
  - Free-text search: matches on candidate name, keywords from CV, skills mentioned in motivatie
  - Years of experience: slider (0-20 years)
  - Last active: dropdown (last month, last quarter, last year)
  - Source: checkboxes (werknl, linkedin, eigen_site, doorverwijzing)
  - Contract preference: checkboxes (vast, tijdelijk, freelance, stage)

- **WHEN** the recruiter types "Vue developer met 3+ jaren" and hits search
- **THEN** results show Applications where:
  - `talent_pool_consent = true` AND `delete_after_date > now()`
  - CV/motivatie mentions "Vue" and "3 years" or similar phrasing
  - Sorted by relevance (keyword match score) and recency
- **AND** each result shows: candidate naam, brief CV preview (education, last 2 roles), skills extracted from CV, and date added to pool

### Scenario 8.2: Reach out to talent pool candidate

- **WHEN** a recruiter clicks on a talent-pool candidate and sees a "Benader voor nieuwe Vacancy" button
- **THEN** a dialog opens: "Invite [kandidaat_naam] to apply for: [list of open vacancies]"
- **AND** the recruiter selects one or more vacancies
- **AND** optionally edits a personalized message

- **WHEN** the recruiter clicks "Verzend uitnodiging"
- **THEN** a new Application is created for that candidate on the selected Vacancy with:
  - CV pre-filled from talent-pool Application
  - Motivatie pre-filled from talent-pool Application
  - Stage auto-set to `screening` (skipping `nieuwe_sollicitatie` since candidate is known)
  - `bron = "talent_pool_outreach"`
  - `huidige_pipeline_stage = "screening"`
- **AND** an email is sent to the candidate: "Hi [name], We'd like to consider you for: [vacancy title]. Your CV is attached. If interested, click [link to apply]."
- **AND** an ApplicationEvent of type `talent_pool_outreach` is logged on the new Application

---

## REQ-009: External Publication via OpenConnector

Vacancies are published to werk.nl, LinkedIn, and the company career page via openconnector integrations.

### Scenario 9.1: Publish to werk.nl via UWV API

- **WHEN** a Vacancy is published with `publicatie_kanalen` including "werknl"
- **THEN** a background job prepares the vacancy data (title, location, contract type, salary range, URL)
- **AND** POSTs to the werk.nl API (UWV) with:
  - Vacancy title, description (in Nederlands)
  - Location (postcode + municipality)
  - Contract type (mapped from HRMQ enum to UWV enum)
  - Salary range (if salaris_zichtbaar = true)
  - Link to apply: points back to HRMQ public career page with vacancy_id
- **AND** saves the returned `werk_nl_vacancy_id` in Vacancy metadata for future updates/withdrawals
- **AND** an ApplicationEvent of type `vacancy_published_to_werknl` is logged
- **AND** candidate applications via werk.nl include `bron = "werknl"` and optional `werk_nl_ref_id`

### Scenario 9.2: Publish to LinkedIn

- **WHEN** a Vacancy is published with `publicatie_kanalen` including "linkedin"
- **THEN** a background job POSTs to the LinkedIn Talent Hub API with:
  - Job title, description, location (company HQ + remote flexibility)
  - Application URL: points back to HRMQ public career page
- **AND** saves the returned LinkedIn job_id for tracking
- **AND** monitors the LinkedIn Recruiter API for "Easy Apply" submissions (via webhook)
- **AND** each Easy Apply submission triggers Scenario 2.2 (LinkedIn webhook)

### Scenario 9.3: Maintain vacancy on company career page

- **WHEN** a Vacancy status = `open`
- **THEN** the vacancy is automatically published to `/careers` public page:
  - URL: `/careers/[vacancy_id]-[slug]`
  - Title, description, salary, benefits, link to "Solliciteer"
  - Mobile-responsive, WCAG AA compliant
- **WHEN** vacancy status changes to `gesloten`, `vervuld`, or `ingetrokken`
- **THEN** the page shows "Position filled" or "No longer accepting applications" and hides the application form
- **AND** archived vacancies remain searchable in past-postings with read-only status

### Scenario 9.4: Sync updates across channels

- **WHEN** a recruiter edits a published Vacancy (beschrijving, salaris, sluitingsdatum)
- **AND** clicks "Update gepubliceerd"
- **THEN** background jobs push the changes to each active channel:
  - werk.nl: PUT to `/vacancies/[werk_nl_vacancy_id]` with updated fields
  - LinkedIn: PUT to `/jobs/[linkedin_job_id]` with updated fields
  - eigen_site: HTML is re-generated and cached
- **AND** each update attempt is logged; failures trigger a retry queue with exponential backoff
- **AND** if a channel is down, the recruiter is notified but can still update HRMQ locally; sync will retry automatically

### Scenario 9.5: Withdraw vacancy from channels

- **WHEN** a recruiter changes Vacancy status to `gesloten`, `vervuld`, or `ingetrokken`
- **THEN** background jobs send withdrawal signals:
  - werk.nl: PUT to `/vacancies/[werk_nl_vacancy_id]` with status = "closed"
  - LinkedIn: PUT to `/jobs/[linkedin_job_id]` with status = "closed"
  - eigen_site: vacancy shows "Position closed" or is hidden
- **AND** the vacancy no longer appears in new-posting feeds on external sites
- **AND** existing Applications (already submitted) remain in the pipeline; no cascading deletes

---

## REQ-010: WCAG AA Accessibility & Mobile-First Public Career Page

The public career page is fully accessible (WCAG 2.1 AA) and mobile-first.

### Scenario 10.1: Career page structure & navigation

- **WHEN** a candidate visits `/careers` on desktop or mobile
- **THEN** the page displays:
  - Hero section: "Work with us" + company info, mobile-responsive
  - Job search: text input, filters (location, contract type, department)
  - Job listings: cards or list view, sorted by posted_date (newest first)
  - Mobile: single-column layout; desktop: 2-column (filters + listings) or full-width listings
- **AND** all elements are reachable via keyboard (Tab, Enter, Esc)
- **AND** screen-reader announces: page title, section headings (h1, h2, h3), link text, form labels, error messages

### Scenario 10.2: Job detail page accessibility

- **WHEN** a candidate clicks on a job card
- **THEN** the detail page (`/careers/[vacancy_id]-[slug]`) displays:
  - Job title (h1)
  - Company info, location, contract type (prominent text)
  - Job description (formatted with headings, lists, bold/italic for emphasis)
  - "Solliciteer" button (large, clearly focusable)
- **AND** form fields have:
  - `<label>` tags paired with `<input>` via `for` / `id`
  - `required` indicator (text, not just visual asterisk)
  - Error messages in `role="alert"` containers
- **AND** contrast ratio is ≥ 4.5:1 (text vs. background)
- **AND** interactive elements have ≥ 44px touch target size

### Scenario 10.3: Application form validation & feedback

- **WHEN** a candidate fills the application form and submits without all required fields
- **THEN** form validation runs and displays:
  - Inline error messages next to each field
  - A summary error box at the top with jumps to invalid fields
  - Focus moves to the first invalid field
- **AND** error messages are in `role="alert"` so screen-readers announce them immediately
- **AND** if submission is successful, the candidate sees a success message: "Your application was received. We'll be in touch within [X] days."

### Scenario 10.4: Mobile responsive layout

- **WHEN** candidate views the career page on:
  - Mobile (375px viewport): single column, stacked layout
  - Tablet (768px viewport): 2 columns or adjusted spacing
  - Desktop (1200px+ viewport): full layout with sidebars
- **THEN** all content is readable without horizontal scrolling
- **AND** buttons and form fields scale to touch-friendly sizes (≥ 44px)
- **AND** text size is ≥ 14px (16px on mobile for body text)
- **AND** line height is ≥ 1.5 for readability

### Scenario 10.5: Accessibility testing

- **WHEN** the site is audited with WAVE, Axe, or Lighthouse
- **THEN** no WCAG 2.1 Level AA violations are found:
  - ✅ All images have `alt` text
  - ✅ All form inputs have labels
  - ✅ All links have descriptive text (not "click here")
  - ✅ Color is not the only differentiator (e.g., red = error + text/icon)
  - ✅ Videos have captions (if applicable)
  - ✅ Focus indicator is visible (outline or border)
  - ✅ No content is stuck off-screen via CSS
  - ✅ Skip-to-main-content link is present

### Scenario 10.6: Keyboard-only navigation

- **WHEN** a user navigates the career page using only Tab, Shift+Tab, Enter, Space, Esc
- **THEN** they can:
  - Tab through all focusable elements (links, buttons, form fields) in logical order
  - Press Enter to follow links or submit forms
  - Press Space to toggle checkboxes/switches
  - Press Esc to close modals or dropdowns
- **AND** focus is always visible (outline or highlight box)
- **AND** no keyboard trap occurs (user cannot get stuck in a component)

---

## Non-Functional Requirements

### Performance (REQ-NFR-001)
- Pipeline board (kanban) loads in < 2 seconds for 500+ applications with pagination.
- Talent pool search returns results < 1 second (with indexed search on CV keywords).
- Public career page loads in < 3 seconds (including images and styles).
- API endpoints for publishing to external systems timeout after 30 seconds with retry logic.

### Availability (REQ-NFR-002)
- Background jobs (publication sync, GDPR retention, Calendar event creation) are idempotent and can be safely retried.
- Failures in external integrations (werk.nl down, LinkedIn API rate-limited) do not block the UI; they are logged and retried asynchronously.
- Public career page remains available even if internal recruiting app is under maintenance.

### Security (REQ-NFR-003)
- CV and motivatie files are scoped: only recruiter and hiring_manager for that vacancy can view them.
- Reference check links are one-time tokens; no persistent access granted.
- Decidesk envelope signing requires candidate email verification.
- GDPR anonymization is permanent and logged; no rollback without DB restore.

### Testability (REQ-NFR-004)
- All integrations (openconnector, Decidesk, LinkedIn, werk.nl, Nextcloud Calendar) have mock implementations for CI/CD and local dev.
- Interview scheduling uses a mock Nextcloud Calendar for unit tests.
- Application ingestion APIs have unit tests covering: valid/invalid payloads, file upload limits, duplicate detection.

### Maintainability (REQ-NFR-005)
- Vacancy publication logic is abstracted into a `PublicationService` to allow adding/removing channels (Indeed, Glassdoor, etc.) without modifying core pipeline logic.
- Integration secrets (API keys, OAuth tokens) are stored in app config (openconnector vault), not in the code.
- ApplicationEvent payload is versioned (e.g., `payload_version: 1`) to support schema evolution.

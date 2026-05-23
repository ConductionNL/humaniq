---
status: draft
---

# Implementation Tasks: Recruiting ATS Basic voor HRMQ

## 1. Database Schema & Migrations

- [ ] 1.1 Create migration: `vacancies` table with columns: id, titel, functie_schaal, locatie, contract_type, uren_per_week_min/max, salaris_indicatie_min/max, salaris_zichtbaar, beschrijving_markdown, eisen_markdown, aangeboden_markdown, sluitingsdatum, gewenste_startdatum, status (enum: concept, open, gesloten, vervuld, ingetrokken), aangemaakt_door (user_id), hiring_manager_id, publicatie_kanalen (json), created_at, updated_at. Add indexes on status, hiring_manager_id, created_at.

- [ ] 1.2 Create migration: `applications` table with columns: id, vacancy_id (fk), kandidaat_naam, kandidaat_email, kandidaat_telefoon, cv_file_id (fk), motivatie_file_id (fk, nullable), motivatie_inline_text (text, nullable), ingediend_op, bron (enum), huidige_pipeline_stage (enum), talent_pool_consent (bool, default false), talent_pool_consent_at (nullable), delete_after_date, created_at, updated_at. Add indexes on vacancy_id, huidige_pipeline_stage, delete_after_date, talent_pool_consent.

- [ ] 1.3 Create migration: `application_events` table (append-only) with columns: id, application_id (fk), event_type (enum: stage_change, note_added, email_sent, interview_scheduled, interview_completed, offer_sent, rejected, withdrawn, created, gdpr_anonymized, talent_pool_consent_given, talent_pool_outreach, hand_off_to_onboarding, vacancy_created, vacancy_updated, vacancy_closed, vacancy_published_to_werknl, vacancy_published_to_linkedin), event_at, actor_id (user_id, nullable for system events), from_stage (nullable), to_stage (nullable), payload_json (nullable), created_at (immutable). Add index on application_id, event_at.

- [ ] 1.4 Create migration: `interviews` table with columns: id, application_id (fk), interview_type (enum: telefonisch, video, in_person), nextcloud_calendar_event_id (string, nullable), start_at, end_at, interviewers (json array of user_ids), kandidaat_zichtbaar (bool, default true), notities_pre (text, nullable), notities_post (text, nullable), score (int 1-5, nullable), created_at, updated_at. Add index on application_id, start_at.

- [ ] 1.5 Create migration: `offer_letters` table with columns: id, application_id (fk), template_id (uuid), salaris_bruto_per_maand (decimal), salaris_bruto_jaar (decimal), startdatum (date), proeftijd_maanden (int), contract_duur_maanden (int, nullable), vakantiedagen_per_jaar (int), extra_voorwaarden_markdown (text, nullable), generated_pdf_file_id (fk, nullable), verzonden_op (timestamp, nullable), geaccepteerd_op (timestamp, nullable), verlopen_op (timestamp, nullable), decidesk_envelope_id (string, nullable), created_at, updated_at. Add index on application_id, geaccepteerd_op.

- [ ] 1.6 Create migration: `offer_letter_templates` table (lookup/config) with columns: id, template_name (e.g., "Vast contract standaard"), template_html (markdown or HTML template with placeholders), contract_type (enum), is_active (bool, default true), created_at, updated_at.

- [ ] 1.7 Add post-migration seed data: 2-3 standard offer templates (permanent contract, fixed-term contract, freelance).

---

## 2. Backend Services & API Endpoints

### 2.1 Vacancy Service

- [ ] 2.1.1 Create `VacancyService` class with methods: create(), update(), getById(), listByStatus(), listByHiringManager(), publish(), unpublish(), closeVacancy(). Business logic: status validation, hiring_manager authorization, publicatie_kanalen validation.

- [ ] 2.1.2 Create `VacancyController` endpoints:
  - `POST /api/vacancies` — Create new vacancy (recruiter/hiring_manager role)
  - `GET /api/vacancies/{id}` — Fetch vacancy details
  - `PUT /api/vacancies/{id}` — Update vacancy (recruiter/hiring_manager role)
  - `GET /api/vacancies?status=open&hiring_manager_id={id}` — List vacancies (with filters)
  - `POST /api/vacancies/{id}/publish` — Publish to channels
  - `POST /api/vacancies/{id}/close` — Close/withdraw vacancy

### 2.2 Application Ingestion & Pipeline

- [ ] 2.2.1 Create `ApplicationService` class with methods: create(), updateStage(), getById(), listByVacancy(), listByStage(), bulkReject(), getDeletionCandidates(), anonymizeApplication().

- [ ] 2.2.2 Create `ApplicationController` endpoints:
  - `POST /api/applications` — Create application (public or internal)
  - `GET /api/applications/{id}` — Fetch application details (with audit trail)
  - `PUT /api/applications/{id}/stage` — Update pipeline stage
  - `GET /api/vacancies/{vacancy_id}/applications?stage=screening` — List applications (with filters)
  - `POST /api/applications/{id}/bulk-reject` — Bulk reject with template
  - `DELETE /api/applications/{id}` — Archive/soft-delete (for GDPR)

- [ ] 2.2.3 Create validation: CV file is required, file size < 10 MB, accepted formats (PDF, JPG, PNG), email valid, phone non-empty.

- [ ] 2.2.4 Create public career page form handler: POST to `/api/public/applications` without authentication; return 422 on validation error.

### 2.3 Interview Service

- [ ] 2.3.1 Create `InterviewService` class with methods: create(), update(), getById(), listByApplication(), sendCandidateInvite(), logFeedback().

- [ ] 2.3.2 Create `InterviewController` endpoints:
  - `POST /api/applications/{id}/interviews` — Schedule interview (calls Calendar integration)
  - `GET /api/applications/{id}/interviews` — List interviews
  - `PUT /api/interviews/{id}` — Update interview details (post-interview feedback)
  - `POST /api/interviews/{id}/send-invite` — Send iCal to candidate

- [ ] 2.3.3 Create Calendar integration (see section 5.1 below).

### 2.4 Offer Letter Service

- [ ] 2.4.1 Create `OfferLetterService` class with methods: create(), generatePdf(), sendViaDecidesk(), acceptOffer(), expireOffer().

- [ ] 2.4.2 Create `OfferLetterController` endpoints:
  - `POST /api/applications/{id}/offer-letters` — Create offer from template with salary/terms
  - `GET /api/offer-letters/{id}` — Fetch offer details
  - `POST /api/offer-letters/{id}/generate-pdf` — Render template to PDF (using Snappy/wkhtmltopdf or similar)
  - `POST /api/offer-letters/{id}/send-decidesk` — Send to Decidesk for e-signature

- [ ] 2.4.3 Create offer template rendering: load template HTML/Markdown, inject salary, startdatum, proeftijd, contract_duur, vakantiedagen, extra_voorwaarden. Validate placeholders match data.

- [ ] 2.4.4 Create POST endpoint for Decidesk webhook: `/api/webhooks/decidesk/envelopes/signed` — match envelope_id, update geaccepteerd_op, transition Application to `geaccepteerd`, send notifications.

### 2.5 Talent Pool Service

- [ ] 2.5.1 Create `TalentPoolService` class with methods: search(), reachOut(), getConsents(). Logic: filter Applications by talent_pool_consent=true AND delete_after_date > now(). Full-text search on CV/motivatie keywords.

- [ ] 2.5.2 Create `TalentPoolController` endpoints:
  - `GET /api/talent-pool/search?q=vue+developer&years=3&source=linkedin` — Search with filters
  - `POST /api/talent-pool/{app_id}/reach-out` — Create new Application on vacancy with CV from pool
  - `GET /api/talent-pool/stats` — Return consent counts, expiring-soon counts (for dashboards)

### 2.6 Onboarding Hand-Off Service

- [ ] 2.6.1 Create `OnboardingHandoffService` class with methods: createEmployeeFromApplication(), triggerOnboardingWizard(). Calls employee-master API to create Employee, then onboarding-wizard API to start workflow.

- [ ] 2.6.2 Create background job: when Application moves to `aangenomen`, call OnboardingHandoffService. Log result in ApplicationEvent. Retry on failure (3 attempts, exponential backoff).

---

## 3. GDPR & Data Retention

- [ ] 3.1 Create `GdprRetentionService` class with methods: calculateDeleteDate(), anonymizeApplication(), scheduleRetentionJob(), generateRetentionReport().

- [ ] 3.2 Create background job (scheduled daily at 2 AM) that:
  - Queries Applications where delete_after_date < now() AND talent_pool_consent = false OR (consent = true AND delete_after_date < now())
  - For each, calls anonymizeApplication(): NULL out kandidaat_naam, email, phone; anonymize CV/motivatie files; delete/mask PII from ApplicationEvent notes
  - Logs an ApplicationEvent of type `gdpr_anonymized`
  - Generates a report (number of apps anonymized, files deleted) sent to HR-admin

- [ ] 3.3 Create talent pool opt-in flow:
  - Generate unique token on Application rejection: token = hash(app_id + random)
  - Embed link in rejection email: `/talent-pool/opt-in?token={token}`
  - GET endpoint renders form (no auth): "Stay in our talent pool for 1 year?"
  - POST submits: update talent_pool_consent=true, talent_pool_consent_at=now(), delete_after_date=now()+365 days
  - Log ApplicationEvent of type `talent_pool_consent_given`
  - Send confirmation email with opt-out link

- [ ] 3.4 Create POST endpoint for Data Subject Request (GDPR art. 15):
  - HR-admin selects an Application
  - POST `/api/applications/{id}/export-dsr` generates JSON/PDF with all candidate data, events, interviews, offer
  - Return file for download or email to candidate within 30 days

---

## 4. External Integrations (openconnector, LinkedIn, werk.nl, Decidesk)

### 4.1 Nextcloud Calendar Integration

- [ ] 4.1.1 Create `CalendarService` class (wrapper around Nextcloud Calendar API) with methods: createEvent(), updateEvent(), deleteEvent(), listAttendeeAvailability().

- [ ] 4.1.2 When Interview is created:
  - Build Calendar event: title "Sollicitatiegesprek [kandidaat_naam] – [vacancy_titel]", description with interview_type, CV link, Talk link (if video)
  - POST to Nextcloud Calendar API (authenticated as the recruiter)
  - Save returned calendar_event_id in Interview.nextcloud_calendar_event_id
  - If kandidaat_zichtbaar=true, send iCal invite to kandidaat_email (includes Talk link)

- [ ] 4.1.3 Add availability lookup: when planning an interview, query Calendar API for time slots when all selected interviewers are free. Display in UI.

### 4.2 Decidesk Integration

- [ ] 4.2.1 Create `DecideskService` class (wrapper around Decidesk e-signature API) with methods: createEnvelope(), getEnvelopeStatus(), webhook handler.

- [ ] 4.2.2 When OfferLetter.sendViaDecidesk() is called:
  - Load generated PDF from storage
  - Create Decidesk envelope: signers = [candidate_email (signer), hiring_manager_email (counter-signer)]
  - Attach PDF to envelope
  - Send envelope; get back decidesk_envelope_id
  - Save envelope_id in OfferLetter.decidesk_envelope_id
  - Log ApplicationEvent of type `offer_sent`

- [ ] 4.2.3 Implement webhook handler for Decidesk at `/api/webhooks/decidesk/envelopes/signed`:
  - Verify webhook signature (Decidesk uses HMAC)
  - Match envelope_id to OfferLetter
  - Update geaccepteerd_op = now(), Application stage = `geaccepteerd`
  - Log ApplicationEvent
  - Send notifications to hiring_manager, hr_admin

### 4.3 openconnector: Werk.nl Publication

- [ ] 4.3.1 Create `PublicationService` with channel adapters: `WerkNlPublisher`, `LinkedInPublisher`, `CareerPagePublisher`.

- [ ] 4.3.2 Implement `WerkNlPublisher`:
  - On Vacancy.publish() with werknl channel: POST to UWV werk.nl API (https://www.werk.nl/api/v1/vacancies)
  - Payload: title, description (Dutch), location (postcode), contract_type, salary_range (if visible), apply_url (link back to HRMQ career page with vacancy_id)
  - Handle 200/201 response: save werk_nl_vacancy_id in Vacancy metadata
  - On update: PUT to werk.nl API with updated fields
  - On close: PUT status = "closed"
  - Retry on failure (3x, exponential backoff); log errors

- [ ] 4.3.3 Implement `LinkedInPublisher`:
  - On Vacancy.publish() with linkedin channel: POST to LinkedIn Talent Hub API (https://api.linkedin.com/v2/jobs)
  - Payload: title, description, company_id (from config), location, apply_url
  - Save linkedin_job_id in Vacancy metadata
  - On update/close: PUT to linkedin_job_id with status
  - Handle OAuth token refresh (LinkedIn tokens expire)

- [ ] 4.3.4 Implement `CareerPagePublisher`:
  - Auto-publish open Vacancies to public `/careers` page
  - Generate HTML from Vacancy fields (title, description, salary, benefits, apply button)
  - Cache HTML; invalidate on update
  - Show "Position closed" for non-open status

- [ ] 4.3.5 Create LinkedIn webhook handler at `/api/webhooks/linkedin/applications`:
  - Receive Easy Apply submission (candidate name, email, LinkedIn profile URL)
  - Fetch LinkedIn profile; generate CV PDF from profile data
  - Create Application with bron=linkedin, auto-set CV from generated PDF
  - Log event

---

## 5. Frontend Components (Vue/Nextcloud UI)

### 5.1 Vacancy Management

- [ ] 5.1.1 Create `VacancyForm.vue` component:
  - Form sections: Job Info, Salary & Hours, Description, Benefits, Channels
  - Fields: titel (text), functie_schaal (dropdown), locatie (text + map picker), contract_type (radio), uren_per_week_min/max (number), salaris (number with toggle for visibility), beschrijving/eisen/aangeboden (markdown editors with preview), sluitingsdatum, gewenste_startdatum (date pickers), hiring_manager_id (dropdown), publicatie_kanalen (multi-select).
  - Validation: all required fields filled, salary ranges sensible (min < max), dates in future
  - Action buttons: "Opslaan als concept", "Publiceer", "Update gepubliceerd"

- [ ] 5.1.2 Create `VacancyList.vue` component:
  - Table/card view of vacancies with columns: titel, status, hiring_manager, created_at, application_count
  - Filters: status (dropdown), hiring_manager (dropdown), date range
  - Actions: edit, publish, close, view applications
  - Bulk actions: close multiple vacancies

### 5.2 Pipeline & Kanban Board

- [ ] 5.2.1 Create `PipelineBoard.vue` component:
  - Kanban board with columns per pipeline stage (filtered per vacancy)
  - Application cards: kandidaat_naam, ingediend_op (relative time), bron (icon), click for detail
  - Drag & drop between stages (using Vue Draggable or similar)
  - On drop: call updateStage() API, show dialog for planning interview if moving to `eerste_gesprek`
  - Infinite scroll or pagination (load 50 cards, load more on scroll)

- [ ] 5.2.2 Create `ApplicationDetail.vue` component (side panel or modal):
  - Candidate info: naam, email, telefoon, CV (link/preview), motivatie (link/preview)
  - Current stage, timeline of ApplicationEvents (chronological list)
  - Quick actions: Plan gesprek, Verzend e-mail, Notitie toevoegen, Afwijzen, Terugtrekken
  - Show all interviews + feedback
  - Show offer letter details (if exists)

- [ ] 5.2.3 Create `BulkRejectDialog.vue` component:
  - Show count of selected applications
  - Text area for rejection email template (pre-filled default)
  - Markdown editor/preview toggle
  - Preview: "Reject [N] candidates" button
  - Executes bulk rejection API call

### 5.3 Interview Planning

- [ ] 5.3.1 Create `InterviewPlanningForm.vue` component:
  - Fields: interviewers (searchable multi-select), interview_type (radio: telefonisch/video/in_person), date picker, time picker (with availability overlay), kandidaat_zichtbaar (toggle)
  - Validate: at least 1 interviewer selected, start_at is in future
  - Action: "Plan" button calls API, returns calendar_event_id
  - Success: show "Interview scheduled" + event details + "View in calendar" link

- [ ] 5.3.2 Create `InterviewFeedback.vue` component:
  - Show pre-interview notes (read-only reference)
  - Fields: notities_post (textarea), score (1-5 slider), aanbeveling (radio: doorschuiven/afwijzen/hold)
  - Action: "Opslaan" calls updateInterview() API, optionally triggers stage change

### 5.4 Offer Letter Generation

- [ ] 5.4.1 Create `OfferLetterForm.vue` component:
  - Fields: template (dropdown), salaris_bruto_per_maand (auto-calc from yearly or manual), salaris_bruto_jaar, startdatum, proeftijd_maanden, contract_duur_maanden (nullable for indefinite), vakantiedagen, extra_voorwaarden (markdown editor)
  - Preview: live HTML render of selected template with filled-in data
  - Actions: "Genereer PDF" (calls API, returns PDF URL), "Stuur ter ondertekening" (calls Decidesk API)
  - On success: show "Offer sent to [email] for e-signature" + status link

- [ ] 5.4.2 Create `OfferLetterStatus.vue` component:
  - Display offer details, status (verzonden/geaccepteerd/verlopen), signing status from Decidesk
  - Show "Signed at [date]" once accepted
  - Retry button if expired

### 5.5 Talent Pool

- [ ] 5.5.1 Create `TalentPoolSearch.vue` component:
  - Search input (free-text: name, skills, education)
  - Filters: years_of_experience (slider), last_active, source, contract_preference
  - Results: candidate cards with CV preview, skills highlighted, "Benader voor nieuwe Vacancy" button
  - On "Benader": dialog to select vacancy + optional custom message, submit calls API

### 5.6 Public Career Page

- [ ] 5.6.1 Create `CareerPage.vue` (public route `/careers`, no auth):
  - Hero section: "Work with us" + company tagline
  - Search/filter: text search, location filter, contract type filter
  - Job listings: cards (title, location, contract type, salary if visible, posted_date)
  - Click card → detail page
  - Mobile-responsive, WCAG AA compliant

- [ ] 5.6.2 Create `JobDetail.vue` (public route `/careers/[vacancy_id]-[slug]`, no auth):
  - Job title (h1), company info, full description (formatted markdown)
  - Salary, benefits, requirements (all markdown, formatted)
  - "Solliciteer" button → opens ApplicationForm
  - Social share buttons

- [ ] 5.6.3 Create `ApplicationForm.vue` (public, no auth):
  - Fields: naam, email, telefoon, CV (file upload, required), motivatie (file upload or text)
  - Validation: all required, email format, CV size/type
  - Submit button → POST to `/api/public/applications`
  - Success: show "Application received" message with link to check status (via email)
  - WCAG AA: labels, error messages in role="alert", keyboard accessible, mobile touch targets ≥ 44px

---

## 6. Testing

### 6.1 Unit Tests

- [ ] 6.1.1 Test VacancyService: create, update, publish, close, edge cases (null hiring_manager, invalid status transitions)
- [ ] 6.1.2 Test ApplicationService: create, updateStage, validation, delete_after_date calculation, anonymizeApplication
- [ ] 6.1.3 Test InterviewService: create, send invite, feedback, edge cases (conflicting times, missing interviewers)
- [ ] 6.1.4 Test OfferLetterService: create, generatePdf (mock PDF rendering), sendViaDecidesk (mock), acceptOffer
- [ ] 6.1.5 Test GdprRetentionService: calculate dates, anonymize, edge cases (consent expiry, already anonymized)
- [ ] 6.1.6 Test PublicationService adapters (WerkNl, LinkedIn, CareerPage) with mocks
- [ ] 6.1.7 Test validation: CV required, email format, file size, phone non-empty
- [ ] 6.1.8 Test talent pool search and reachOut logic

### 6.2 Integration Tests

- [ ] 6.2.1 Test full application flow: create vacancy → publish to channels → receive application → move through pipeline → offer → Decidesk signing → onboarding hand-off
- [ ] 6.2.2 Test Calendar integration: create Interview → POST to mock Nextcloud Calendar → verify event created with attendees and Talk link
- [ ] 6.2.3 Test Decidesk webhook: send envelope → simulate signing → webhook POST → verify Application stage updated
- [ ] 6.2.4 Test LinkedIn Easy Apply webhook: submit via webhook → verify Application created with CV
- [ ] 6.2.5 Test werk.nl publication: publish Vacancy → verify POST to mock werk.nl API with correct payload
- [ ] 6.2.6 Test talent pool opt-in: reject application → send email with token → candidate clicks link → update consent → verify delete_after_date extended
- [ ] 6.2.7 Test GDPR retention batch job: create rejected applications with past delete_after_date → run job → verify anonymized

### 6.3 E2E Tests

- [ ] 6.3.1 Test public career page: visit `/careers` → search job → click job → see details → click "Solliciteer" → fill form → submit → success message
- [ ] 6.3.2 Test recruiter workflow: login → create vacancy → fill form → publish → view pipeline → drag application → plan interview → send offer → approve in Decidesk
- [ ] 6.3.3 Test hiring manager workflow: login → view own vacancies → see new application notification → view application detail → plan interview → approve offer hand-off
- [ ] 6.3.4 Test candidate opt-in flow: receive rejection email → click talent pool link → confirm opt-in → see confirmation
- [ ] 6.3.5 Test accessibility: career page with WAVE/Axe, keyboard navigation, screen reader announcement

### 6.4 Performance Tests

- [ ] 6.4.1 Load test: pipeline board with 500+ applications, verify load time < 2s, response time < 500ms
- [ ] 6.4.2 Search test: talent pool search with 1000+ consented applications, verify < 1s response
- [ ] 6.4.3 Career page: render with 100 vacancies, verify load time < 3s

---

## 7. Documentation & Deployment

- [ ] 7.1 Write API documentation: OpenAPI/Swagger spec for all endpoints, example requests/responses
- [ ] 7.2 Write admin guide: how to set up integrations (werk.nl API keys, LinkedIn OAuth, Decidesk webhooks, Nextcloud Calendar), configure offer templates, manage retention policy
- [ ] 7.3 Write user guide: recruiter workflows (create vacancy, manage pipeline, plan interviews, send offers), hiring manager workflows (view applications, approve offers), candidate guidance (apply via career page, check status)
- [ ] 7.4 Create database backup/restore procedure for GDPR anonymization rollback (if needed for incident response)
- [ ] 7.5 Deploy to staging, run smoke tests, get sign-off from recruiter + hiring manager test users
- [ ] 7.6 Deploy to production in phases: feature flag `recruiting-ats-basic` (default off), gradual rollout to select tenants, monitoring (error rate, API latency, webhook success rate)
- [ ] 7.7 Monitor integrations: werk.nl/LinkedIn publication success, Decidesk signing completion time, Calendar event creation latency

---

## 8. Verification

- [ ] 8.1 Verify REQ-001: Create vacancy, select 2+ channels (werk.nl, LinkedIn), publish, confirm external postings appear
- [ ] 8.2 Verify REQ-002: Apply via career page (with CV), via LinkedIn webhook, verify applications in pipeline with correct bron and CV
- [ ] 8.3 Verify REQ-003: Load pipeline board, drag application between stages, verify stage change event logged, dialog for interview planning appears
- [ ] 8.4 Verify REQ-004: Plan interview, confirm Calendar event created, candidate receives iCal, interviewer can see event in their calendar
- [ ] 8.5 Verify REQ-005: Generate offer PDF, send via Decidesk, candidate signs, webhook triggers stage change to `geaccepteerd`
- [ ] 8.6 Verify REQ-006: Move application to `aangenomen`, confirm Employee created in employee-master with pre-filled data, onboarding-wizard launches
- [ ] 8.7 Verify REQ-007: Reject application, confirm delete_after_date = 28 days, candidate opts in, delete_after_date extends to 365 days, daily job anonymizes after expiry
- [ ] 8.8 Verify REQ-008: Search talent pool by skills, reach out to candidate for new vacancy, new Application created in `screening` stage
- [ ] 8.9 Verify REQ-009: Publish vacancy to werk.nl, LinkedIn, verify postings appear, external applications arrive back into pipeline
- [ ] 8.10 Verify REQ-010: Load public career page on mobile, verify WCAG AA (run Axe), navigate with keyboard only, submit application via screen reader

---

## 9. Post-MVP Roadmap (Not in Scope)

- [ ] AI-powered CV screening (short-list top N candidates by skills match)
- [ ] Video interview integration (HireVue or Hireable)
- [ ] Psychometric testing integration
- [ ] Background check integration (ONL, Citima)
- [ ] Indeed, Glassdoor, Joblift publication channels
- [ ] Multi-language job postings (EN, FR, DE, etc.)
- [ ] Sector-specific compliance (public sector AOR, expat tax rules)
- [ ] Advanced analytics dashboard (hiring funnel, source ROI, time-to-hire by role)
- [ ] Interview feedback scoring & analytics

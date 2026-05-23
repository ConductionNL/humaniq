---
status: draft
---

# Design: Recruiting ATS Basic voor HRMQ

## Context

The HRMQ suite already has:
- `employee-master` — foundational personnel registry with role-based access.
- `onboarding-wizard` — automated workflow for new-hire paperwork and training.
- `openconnector` — pluggable integrations to external systems (payroll, tax, leave).
- Nextcloud Calendar and Talk built in for shared scheduling.
- Decidesk integration for digital document signature.

This spec builds the recruiting (vacancies → hire) layer that feeds into onboarding and payroll. Placement: `Onboarding & ATS › Vacatures+Kandidaten` (SUB_PAGE under ADR-001 rule).

## Goals

- **One vacancy, many channels**: Draft once, publish to werk.nl, LinkedIn, and company website with automatic sync.
- **Unified application pipeline**: All incoming applications (career page, werk.nl, LinkedIn) land in a single kanban board.
- **GDPR-first retention**: Automatic anonymization of rejected applications within 28 days; talent pool consent extends to 1 year.
- **Calendar-native interview planning**: No double-entry; Calendar events auto-generate with candidate iCal invites and Talk links.
- **Seamless onboarding hand-off**: Hired candidates auto-populate Employee creation and onboarding-wizard context; no manual copy/paste.
- **Accessible public presence**: WCAG AA career page; mobile-first; keyboard and screen-reader friendly.

## Non-Goals

- **AI screening**: No automated CV scoring, background-check integration, or psychometric testing. Can be added post-MVP.
- **Video interview platform**: Talk-based video is available for interviews, but dedicated video-interview infrastructure (e.g., HireVue) is out of scope.
- **Multi-language job posting**: MVP posts in Dutch only; multi-language variants can be added later.
- **International tax/compliance**: MVP assumes Dutch employment law (NVP code, AVG). Sector-specific variants (public sector AOR, expat rules) are future phases.
- **Indeed XML feed**: LinkedIn and werk.nl are priorities; Indeed support can be added as a secondary integration.

## Data Model

### Core Entities

#### `Vacancy`
Represents an open position. Draft → Open → Closed|Filled|Withdrawn.

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `titel` | string | Job title (e.g., "Senior Vue Developer") |
| `functie_schaal` | string | CAO grade or internal level (e.g., "Schaal 6 DUO-medewerker") |
| `locatie` | string | Office location, hybrid/remote indicator, postcode (e.g., "Amsterdam, hybride, 1012AB") |
| `contract_type` | enum | vast, tijdelijk_jaar, tijdelijk_7maanden, oproep, stage, freelance |
| `uren_per_week_min` | int | Min hours/week |
| `uren_per_week_max` | int | Max hours/week |
| `salaris_indicatie_min` | int | Bruto annual min (€) |
| `salaris_indicatie_max` | int | Bruto annual max (€) |
| `salaris_zichtbaar` | bool | Show salary range to candidates? |
| `beschrijving_markdown` | text | Job description in Markdown |
| `eisen_markdown` | text | Requirements in Markdown |
| `aangeboden_markdown` | text | Benefits/perks in Markdown |
| `sluitingsdatum` | date | Application deadline |
| `gewenste_startdatum` | date | Desired start date |
| `status` | enum | concept, open, gesloten, vervuld, ingetrokken |
| `aangemaakt_door` | user_id | Creator (recruiter) |
| `hiring_manager_id` | user_id | Responsible manager |
| `publicatie_kanalen` | json | ["werknl", "linkedin", "eigen_site", "indeed_zelf"] |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

#### `Application`
A submission from a candidate for a Vacancy.

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `vacancy_id` | UUID | FK to Vacancy |
| `kandidaat_naam` | string | Full name |
| `kandidaat_email` | string | Contact email |
| `kandidaat_telefoon` | string | Contact phone |
| `cv_file_id` | UUID | FK to Files; required |
| `motivatie_file_id` | UUID | FK to Files; optional (if letter uploaded) |
| `motivatie_inline_text` | text | Free-text motivation; used if no letter file |
| `ingediend_op` | timestamp | Submission time |
| `bron` | enum | werknl, linkedin, eigen_site, doorverwijzing, anders |
| `huidige_pipeline_stage` | enum | nieuwe_sollicitatie, screening, eerste_gesprek, tweede_gesprek, referentie_check, aanbieding_uitgebracht, geaccepteerd, aangenomen, afgewezen, teruggetrokken_door_kandidaat |
| `talent_pool_consent` | bool | Candidate opts into talent pool (default false) |
| `talent_pool_consent_at` | timestamp | When consent was given |
| `delete_after_date` | date | Calculated: ingediend_op + 28 days (or consent + 365 days) |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

#### `ApplicationEvent`
Append-only log of all actions on an Application.

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `application_id` | UUID | FK to Application |
| `event_type` | enum | stage_change, note_added, email_sent, interview_scheduled, interview_completed, offer_sent, rejected, withdrawn, created |
| `event_at` | timestamp | When the event occurred |
| `actor_id` | user_id | Who triggered the event |
| `from_stage` | enum | Nullable; used for stage_change |
| `to_stage` | enum | Nullable; used for stage_change |
| `payload_json` | json | Interview details, email content, notes, etc. |
| `created_at` | timestamp | Immutable |

#### `Interview`
A scheduled conversation with a candidate.

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `application_id` | UUID | FK to Application |
| `interview_type` | enum | telefonisch, video, in_person |
| `nextcloud_calendar_event_id` | string | Calendar event UUID |
| `start_at` | timestamp | Interview start |
| `end_at` | timestamp | Interview end |
| `interviewers` | json | [employee_id, ...] |
| `kandidaat_zichtbaar` | bool | Share calendar event with candidate? |
| `notities_pre` | text | Pre-interview notes |
| `notities_post` | text | Post-interview notes |
| `score` | int | 1-5 rating or NPS |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

#### `OfferLetter`
A formal job offer for a hired candidate.

| Field | Type | Notes |
|-------|------|-------|
| `id` | UUID | Primary key |
| `application_id` | UUID | FK to Application |
| `template_id` | UUID | FK to offer-letter template (e.g., "Vast contract standaard") |
| `salaris_bruto_per_maand` | decimal | Monthly gross salary (€) |
| `salaris_bruto_jaar` | decimal | Annual gross salary (€) |
| `startdatum` | date | Start date |
| `proeftijd_maanden` | int | Probation period (months) |
| `contract_duur_maanden` | int | Contract duration (null = indefinite) |
| `vakantiedagen_per_jaar` | int | Annual leave days |
| `extra_voorwaarden_markdown` | text | Additional terms (markdown) |
| `generated_pdf_file_id` | UUID | FK to Files |
| `verzonden_op` | timestamp | When sent to candidate |
| `geaccepteerd_op` | timestamp | When candidate accepted (via Decidesk) |
| `verlopen_op` | timestamp | Offer expiry time |
| `decidesk_envelope_id` | string | Decidesk envelope UUID for e-signature |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

#### `TalentPool` (View)
Not a table; a logical view over Applications where:
- `talent_pool_consent = true`
- `delete_after_date > now()`

Recruiters search here by keywords (skills, education, previous roles). Selecting a candidate creates a new Application on a different Vacancy with `bron = talent_pool_outreach` and auto-moves to `screening` stage.

#### `PipelineStage` (Enum, Immutable Sequence)
Vaste volgorde (per vacancy, hiring_manager can disable some, but order is fixed):
1. `nieuwe_sollicitatie` — Just submitted
2. `screening` — Initial review
3. `eerste_gesprek` — First conversation
4. `tweede_gesprek` — Second/final conversation
5. `referentie_check` — Optional reference verification
6. `aanbieding_uitgebracht` — Offer sent
7. `geaccepteerd` — Offer signed
8. `aangenomen` — Hired; hand-off to onboarding
9. `afgewezen` — Rejected; retention clock starts
10. `teruggetrokken_door_kandidaat` — Candidate withdrew

## Key Architectural Decisions

### Decision 1: Append-Only ApplicationEvent Log
**Why**: Full audit trail for compliance, debugging, and analytics. Never update Application status directly; always log the transition.
**How**: Every stage change, email, interview creation triggers an ApplicationEvent with actor_id and payload. ApplicationEvent is immutable (no deletes). Application.huidige_pipeline_stage is denormalized for fast querying, but source-of-truth is the latest event.

### Decision 2: GDPR Retention via delete_after_date Calculation
**Why**: Dutch law (NVP Sollicitatiecode 2023) mandates max 28 days without consent, max 1 year with consent.
**How**: At rejection, set `delete_after_date = now() + 28 days`. If candidate opts into talent pool within 28 days, extend to `now() + 365 days`. Daily batch job scans for `delete_after_date < now()` and anonymizes: replaces name, email, phone, CV/letter content with pseudo-IDs. Vacancy stats (total applications, source, rejection reasons) are preserved for analytics.

### Decision 3: Calendar as Source of Truth for Interviews
**Why**: Interviews are coordination events; Nextcloud Calendar is the HRMQ standard scheduling layer. Avoid data duplication.
**How**: When Interview is created, immediately POST to Nextcloud Calendar. Interview record stores the calendar_event_id. If candidate has shared their calendar, they see it automatically and can RSVP. Talk link is added to the calendar description if video interview.

### Decision 4: Offer Letter PDF Generation + Decidesk E-Signature
**Why**: Offers need to be formal, signed, and traceable. Decidesk is already integrated for other HRMQ workflows.
**How**: OfferLetter template (HTML/Markdown) is filled with salary, start date, proeftijd, contract duration. Rendered to PDF. PDF is attached to an outgoing email OR posted to Decidesk envelope for e-signature. Decidesk webhook updates `geaccepteerd_op` when both parties sign. Application transitions to `geaccepteerd`.

### Decision 5: One-Click Hand-Off to Onboarding
**Why**: Hired candidates should not require manual re-entry. All context (CV, name, email, phone, offer salary, start date) is already in the system.
**How**: When Application moves to stage `aangenomen`, a background job:
1. Creates Employee record in employee-master with name, email, phone from Application.
2. Populates initial salary/contract info from OfferLetter (schaal, contract_type, hours, salary).
3. Launches onboarding-wizard with Application-id context; wizard can pre-fill personal data and contract terms.
4. Archive the Application (read-only reference to the hired Employee).

### Decision 6: Configurable Pipeline Stages per Vacancy
**Why**: Some hiring managers skip reference checks; some add extra validation stages.
**How**: When creating a Vacancy, hiring_manager can disable stages (e.g., skip `referentie_check`). The pipeline board still respects the order (phases must go in sequence; can't jump from screening to hired without intermediate stages). Disabled stages are hidden from the UI but remain in the schema (for audit trail queries).

### Decision 7: Multi-Channel Publication with Sync
**Why**: Vacancies must reach kandidates via multiple channels; updates must propagate consistently.
**How**: Vacancy.publicatie_kanalen is a JSON array. On publish, a background job POSTs to each enabled channel (werk.nl REST API, LinkedIn Talent Hub API, and serves HTML on the public career page). On update, the same channels are updated. Each channel maintains a foreign key (e.g., werk.nl vacancy_id, LinkedIn job_id) for reference. If a channel sync fails, an ApplicationEvent is logged for retry/investigation.

## Integration Points

### Incoming
- **Public career page** (new in this spec): Candidates apply via WCAG AA job board. Submissions POST to `/api/applications` endpoint.
- **Werk.nl webhook**: Redirect link includes `source=werknl` and optional vacancy_id. Webhook (future) auto-fills source.
- **LinkedIn Easy Apply webhook**: Candidate data arrives as JSON; CV is generated from LinkedIn profile. Bron set to `linkedin`.

### Outgoing
- **employee-master**: At `aangenomen`, create Employee with pre-filled data from Application + OfferLetter.
- **onboarding-wizard**: Pass Application-id and context; wizard loads pre-fill from offer, CV, and motivatie.
- **openconnector** (werk.nl, LinkedIn publishers): Vacancy POST/PUT/DELETE calls; webhook listeners for inbound applications.
- **Decidesk**: OfferLetter PDF sent as envelope for e-signature; webhook triggers `geaccepteerd_op` update.
- **Nextcloud Calendar**: Interview POST creates event; candidate invited if `kandidaat_zichtbaar=true`.
- **Nextcloud Notifications**: Alerts to hiring_manager (new application), recruiter (interview feedback due), HR-admin (offer accepted).

## Seed Data

### Vacancy Examples

**Example 1: Senior Vue Developer (Full-time, Amsterdam)**
```json
{
  "titel": "Senior Vue Developer",
  "functie_schaal": "Schaal 6 (DUO-medewerker)",
  "locatie": "Amsterdam, hybride, 1012AB",
  "contract_type": "vast",
  "uren_per_week_min": 36,
  "uren_per_week_max": 40,
  "salaris_indicatie_min": 60000,
  "salaris_indicatie_max": 75000,
  "salaris_zichtbaar": true,
  "beschrijving_markdown": "Wij zoeken een Senior Vue Developer voor ons team...",
  "eisen_markdown": "- 5+ jaren Vue.js ervaring\n- TypeScript fluency\n- SQL-basis kennis",
  "aangeboden_markdown": "- 30 vakantiedagen\n- Studiebegroting € 2.000/jaar\n- Home-office budget",
  "sluitingsdatum": "2026-06-30",
  "gewenste_startdatum": "2026-07-15",
  "status": "open",
  "hiring_manager_id": "user-100",
  "publicatie_kanalen": ["werknl", "linkedin", "eigen_site"]
}
```

**Example 2: HR-medewerker (Part-time, Rotterdam)**
```json
{
  "titel": "HR-medewerker (0.5 fte)",
  "functie_schaal": "Schaal 4 (VNG)",
  "locatie": "Rotterdam, op kantoor, 3011AB",
  "contract_type": "tijdelijk_jaar",
  "uren_per_week_min": 18,
  "uren_per_week_max": 20,
  "salaris_indicatie_min": 28000,
  "salaris_indicatie_max": 32000,
  "salaris_zichtbaar": false,
  "beschrijving_markdown": "Versterking van het HR-team voor personeelszaken...",
  "eisen_markdown": "- HBO HR-relevant\n- Werkervaring met OR-zaken",
  "aangeboden_markdown": "- Betaalmiddelen creditcard\n- Pensioen: ABP",
  "sluitingsdatum": "2026-06-15",
  "gewenste_startdatum": "2026-07-01",
  "status": "open",
  "hiring_manager_id": "user-101",
  "publicatie_kanalen": ["werknl"]
}
```

### Application Examples

**Example 1: Application from Public Career Page**
```json
{
  "vacancy_id": "vac-001",
  "kandidaat_naam": "Jan Janssen",
  "kandidaat_email": "jan@example.com",
  "kandidaat_telefoon": "+31 6 12345678",
  "cv_file_id": "file-cv-001",
  "motivatie_file_id": "file-letter-001",
  "ingediend_op": "2026-05-20T10:30:00Z",
  "bron": "eigen_site",
  "huidige_pipeline_stage": "nieuwe_sollicitatie",
  "talent_pool_consent": false
}
```

**Example 2: Application from LinkedIn Easy Apply**
```json
{
  "vacancy_id": "vac-001",
  "kandidaat_naam": "Maria Martínez",
  "kandidaat_email": "maria.martinez@linkedin.com",
  "kandidaat_telefoon": "+34 6 87654321",
  "cv_file_id": "file-cv-generated-linkedin",
  "motivatie_inline_text": "Very excited about this Vue role...",
  "ingediend_op": "2026-05-21T14:00:00Z",
  "bron": "linkedin",
  "huidige_pipeline_stage": "nieuwe_sollicitatie",
  "talent_pool_consent": false
}
```

### Interview Example

```json
{
  "application_id": "app-001",
  "interview_type": "video",
  "start_at": "2026-05-28T14:00:00Z",
  "end_at": "2026-05-28T14:45:00Z",
  "interviewers": ["user-100", "user-102"],
  "kandidaat_zichtbaar": true,
  "notities_pre": "Discussed strong TypeScript background",
  "nextcloud_calendar_event_id": "cal-event-uuid-123",
  "score": 4
}
```

### OfferLetter Example

```json
{
  "application_id": "app-001",
  "template_id": "template-vast-standaard",
  "salaris_bruto_per_maand": 5500,
  "salaris_bruto_jaar": 66000,
  "startdatum": "2026-07-15",
  "proeftijd_maanden": 2,
  "contract_duur_maanden": null,
  "vakantiedagen_per_jaar": 30,
  "extra_voorwaarden_markdown": "- Home-office budget: € 500/jaar\n- Studiebegroting: € 2.000/jaar",
  "generated_pdf_file_id": "file-offer-pdf-001",
  "verzonden_op": "2026-05-25T09:00:00Z",
  "decidesk_envelope_id": "decidesk-env-xyz"
}
```

## GDPR & Compliance Strategy

**Data Retention**:
- **Default (no consent)**: 28 days after rejection. Then: anonymize CV, name, email, phone. Keep only: vacancy_id (for statistics), ingediend_op (for trend analysis), bron (for recruitment channel ROI), rejection reason (pipeline analytics).
- **With talent pool consent**: 365 days. Then: same anonymization.
- **Hired candidates**: Application transitions to read-only after `aangenomen`; associated Employee record is the new source of truth.

**Anonymization Process**:
- Run daily batch job at 2 AM.
- Find Applications where `delete_after_date < now()`.
- For each: replace `kandidaat_naam`, `kandidaat_email`, `kandidaat_telefoon` with NULL or pseudo-ID (e.g., "cand_1234").
- Anonymize associated files (CV, motivation letter): delete or overwrite content.
- Delete any notes from ApplicationEvents that contain PII (salaries, reference details, etc.).
- Keep: vacancy_id, ingediend_op, bron, rejection ApplicationEvent (with anonymized payload).
- Log the anonymization action in ApplicationEvent with type `gdpr_anonymized`.

**Talent Pool Opt-In**:
- Rejection email includes link: `[talent-pool-opt-in-token]`.
- Candidate clicks → form confirms: "We'll keep your CV for 1 year and may contact you for future roles."
- On confirmation: set `talent_pool_consent = true`, `talent_pool_consent_at = now()`, `delete_after_date = now() + 365 days`.
- Send confirmation email with an "opt-out" link.

**Role-Based Access**:
- `recruiter`: Full read/write on all Vacancies and Applications.
- `hiring_manager`: Read-only on own Vacancies; write permission for pipeline stage changes, interview notes, offer approval.
- `hr_admin`: Read-only on all; write on offer generation and hand-off to onboarding.
- `candidate`: Can see only their own Application status via public link (no auth required initially; can be gated to email link).
- `reference_contact`: One-time link to view CV + motivation for a specific Application (no broader access).

## Non-Functional Requirements

- **Performance**: Pipeline board must load <2s for 500+ applications. Indexed queries on vacancy_id, huidige_pipeline_stage, ingediend_op.
- **Availability**: Background jobs (publication sync, GDPR retention, Calendar event creation) are resilient; failures are logged and retried.
- **Testability**: All integrations (openconnector, Decidesk, LinkedIn, werk.nl) have mock implementations for CI/CD.
- **Mobile**: Public career page is mobile-first; all forms responsive.
- **Accessibility**: WCAG 2.1 AA on public career page and internal UI.

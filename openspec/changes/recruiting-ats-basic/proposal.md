---
status: draft
---

# Proposal: Recruiting ATS Basic voor HRMQ

## Why

MKB-werkgevers (5-50 vacatures/jaar) face three acute recruiting pain points today:

1. **Versnippering van vacature-publicatie** — HR-teams copy/paste the same vacancy text across werk.nl, LinkedIn, Indeed, and the company website, creating inconsistency and delaying updates. Each channel requires manual re-entry.

2. **E-mail-gebaseerd pipeline-management** — Applications arrive in a shared inbox; status updates live in a recruiter's head or an Excel spreadsheet. Communication with candidates is ad-hoc, leading to perceived "ghosting" and damage to employer brand.

3. **GDPR-compliance burden** — Rejected CVs and motivation letters are retained for years without explicit consent, exposing employers to regulatory risk. No automated data-retention process exists.

HRMQ Recruiting ATS Basic solves this within the existing HR-suite infrastructure, with direct hand-off to onboarding-wizard and payroll, without the cost and complexity of enterprise ATS platforms (Workday, SuccessFactors).

## What Changes

- **Single-source vacancy creation**: Draft once, publish to werk.nl, LinkedIn, and company website with one click. Updates sync automatically.
- **Pipeline as kanban board**: All applications move through a visual pipeline (new → screening → interview → offer → hired/rejected) with automatic status notification.
- **GDPR-compliant retention**: Rejected applications auto-delete after 28 days unless candidate opts into a talent pool (then 1 year).
- **Calendar-integrated interviews**: Interview scheduling creates Nextcloud Calendar events automatically; candidates receive iCal invites.
- **Offer-letter generation & e-signature**: Generate PDF offers from templates; digitally sign via Decidesk.
- **Hand-off to onboarding**: Accepted offers trigger automatic Employee creation and onboarding-wizard flow.
- **Talent pool**: Searchable pool of candidates who opt-in, ready for proactive outreach on future vacancies.
- **WCAG AA public career page**: Fully accessible job board where candidates apply with CV and motivation.

## Capabilities

### New Capabilities

- `vacancy-management`: Create, edit, publish, and update vacancies across multiple external channels (werk.nl, LinkedIn, company career page).
- `application-pipeline`: Receive applications from multiple sources; move through configurable pipeline stages with automatic status tracking.
- `interview-planning`: Schedule interviews with automatic Nextcloud Calendar event creation and candidate iCal invites.
- `offer-generation`: Generate PDF offer letters from templates; send via Decidesk for digital signature.
- `talent-pool`: Retain and search candidates who consent to future outreach.
- `gdpr-retention`: Automatic anonymization of rejected applications after 28 days (or 1 year with consent).
- `onboarding-integration`: Hand off hired candidates to onboarding-wizard with pre-filled context.
- `public-career-page`: WCAG AA-compliant job board for candidate self-service applications.

## Impact

**New artifacts:**
- `openspec/changes/recruiting-ats-basic/design.md` — Architectural decisions and data model.
- `openspec/changes/recruiting-ats-basic/specs.md` — Testable requirements (REQ-001 through REQ-010).
- `openspec/changes/recruiting-ats-basic/tasks.md` — Implementation task breakdown.

**Modified files (implementation phase):**
- Frontend: `src/modules/recruiting/` (new directory with Vacancy, Application, Pipeline, Interview, Offer components).
- Backend: `lib/Service/Recruiting/` (new service classes for Vacancy, Application, Pipeline, Interview, OfferLetter, TalentPool, GdprRetention).
- Database: Migration to create `vacancies`, `applications`, `pipeline_stages`, `application_events`, `interviews`, `offer_letters` tables.
- Integration: openconnector-based publishers for werk.nl and LinkedIn; Decidesk e-signature hooks; Nextcloud Calendar event creation.

**Integration points:**
- `employee-master` (downstream): Employee creation from hired Application.
- `onboarding-wizard` (downstream): Onboarding context from Application and OfferLetter.
- `openconnector` (peer): Vacancy publication to werk.nl, LinkedIn, Indeed (future).
- `decidesk` (peer): Offer-letter digital signature.
- `Nextcloud Calendar` (peer): Interview event creation.
- `payroll-engine-nl` (downstream via employee-master): Salary computation from OfferLetter.

## Success Criteria

- ✅ Single vacancy publishes to 2+ external channels (werk.nl, LinkedIn) simultaneously.
- ✅ Applications arrive from public career page, werk.nl, and LinkedIn Easy Apply into a single pipeline.
- ✅ Interview scheduling creates Calendar events with iCal invites to candidates.
- ✅ Rejected applications auto-delete after 28 days (or 1 year if talent pool consent given).
- ✅ Hand-off to onboarding-wizard is automatic on hire; Employee is created with OfferLetter data pre-filled.
- ✅ Public career page passes WCAG 2.1 AA and is mobile-first.

# Design: score candidates, match them to vacancies, and take referrals

## Context

Read at `development` af702f78.

- `Interview` (`lib/Settings/register.d/hr-ats.json`, 0.1.0): `applicationId`,
  `scheduledStart`, `scheduledEnd`, `interviewers` (free text, "never as an ATTENDEE"),
  `mode`, `location`, `status`, `calendarEventUid`. No score.
- `job-application` (0.3.0): candidate data, `status`, `talentPoolOptIn`,
  `retentionExpiryDate`; no score, profile, source or referrer.
- `Vacancy` (0.2.1): `title`, `description`, `department`, `status`, dates,
  `administrationId`; no criteria or requirements.
- `EmployeeCompetence` (`lib/Settings/register.d/hr-agenda.json:21`): `employeeId`,
  `competenceCode`, `label`, `issuedOn`, `validUntil`. A competence past `validUntil` stops
  counting on that date (REQ-ROST-C01).
- `Normfunctie` (`lib/Settings/register.d/hr-hr21.json`): `functiecode`, `naam`,
  `functiegroep`, `caoSchaal`.
- `ApplicationDetail` (`src/manifest.d/hr-ats.json:4`) lists interviews in an
  `object-list`; `VacancyDetail` (`hr-ats.json:252`) resolves incoming applications in its
  related widget.
- The Mijn HR menu group (`src/manifest.d/05-menu.json:4`) holds the employee's own pages.
- `lib/Listener/TimeEntryStampListener.php` and `TimesheetProcessStampListener.php` stamp
  the acting user server-side, because the renderer has no create-form token defaults.
- `lib/Service/ReceiptExtractionService.php` is the duck-typed filinq extraction precedent.

## Goals / Non-Goals

**Goals**

- Every interviewer's score is a record on the application, per criterion.
- A vacancy shows who fits it and why, with every point explained.
- An employee refers a candidate in one form and follows only its status.

**Non-Goals**

- Blind review (hiding other interviewers' scores). A later change can add it.
- Any decision taken by the score (AVG article 22).

## Decisions

### D1. `CandidateEvaluation` is one record per evaluator and application

Fields: `applicationId` (`$ref` `job-application`), `interviewId` (`$ref` `Interview`,
optional), `evaluatorUserId` (stamped server-side on create by a listener, the
`TimeEntryStampListener` pattern), `scores` (array of `{criterion, score 1 to 5, note}`),
`recommendation` (`sterk-ja`, `ja`, `neutraal`, `nee`, `sterk-nee`), `note`,
`submittedAt`. An `x-openregister-aggregations` entry averages `scores.score` per
criterion for one application; `ApplicationDetail` shows the evaluations in an
`object-list` and the averages in a stats block. An `x-openregister-relations` cascade on
`applicationId` deletes the evaluations with their application, so the candidate's data
keeps one retention clock.

Alternative considered: score fields on `Interview`. Rejected: an interview has several
interviewers, and one set of fields would hold only the last opinion.

### D2. The match score is plain arithmetic with its breakdown

`VacancyMatchService::matchesFor(string $vacancyId): array` scores each candidate against
`Vacancy.requirements`:

- education level at or above the minimum: 25 points;
- years of experience at or above the minimum: 25 points, pro rata below it;
- required competences: 40 points, shared equally, each held one earns its share;
- preferred competences: 10 points, shared equally.

Applicants are read from `job-application.profile` (status not `afgewezen`, plus rejected
applications with `talentPoolOptIn` whose retention date has not passed). Employees are read
from active `EmployeeCompetence` rows on today's date; education and years are unknown for
employees and score zero, which the breakdown says. Each result carries `total`, the points
per requirement and what was missing. Nothing is stored: the list is computed on read.

Alternative considered: a hermiq or other AI ranking. Rejected for this change: a ranking a
recruiter cannot explain to a rejected candidate is a liability under AVG article 22, and the
row asks for a score based on stated education, level and competences.

### D3. The CV profile is filled by HR or by filinq, never guessed

`job-application.profile` is `{educationLevel, experienceYears, competenceCodes[],
source: hand|extraction}`. `Read CV` on `ApplicationDetail` asks filinq to extract it,
duck-typed, the receipt OCR way, and fills only an empty profile. Without filinq HR types
it.

### D4. A referral is created by the server for the employee

`POST /api/referrals` (`#[NoAdminRequired]`) takes `{vacancyId, candidateName, email,
phone, motivation, candidateConsented}`. It refuses when `candidateConsented` is not true or
the vacancy is not `gepubliceerd`. It creates the application at `nieuw` with
`source: referral` and `referredByUserId` set to the caller, so an employee needs no write
access to `job-application`. `GET /api/referrals/mine` returns the caller's referrals with
only `candidateName`, the vacancy title and `status`.

Alternative considered: a manifest create form on `job-application` for everyone.
Rejected: it would give every employee create and read rights on candidate data.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| average score per criterion | declarative `x-openregister-aggregations` | a grouped average over one schema |
| delete evaluations with the application | declarative `x-openregister-relations` cascade | one retention clock |
| evaluator stamp | imperative listener | no create-form token defaults in the renderer |
| match score | imperative `VacancyMatchService`, computed on read | cross-schema scoring with a breakdown |
| referral create and own-status read | imperative `ReferralService` | server stamp and field projection for non-HR callers |
| pages | declarative manifest | existing widgets |

## Seed data

- The seeded published vacancy gains three criteria and requirements with two required
  and one preferred competence.
- Two evaluations on one seeded application, from two evaluators.
- One seeded application with a profile, and one seeded employee holding one of the
  required competences, so the match list shows an applicant and an employee.
- One referral application with `source: referral`.

## Risks / Trade-offs

- [Scores feel exact] → the page shows the breakdown next to the number, and the number
  never changes a status.
- [Referrals of people who did not agree] → the consent box is required and stored on the
  application as part of the create.

## Open Questions

- Should the referrer be told when their candidate is hired, through the notification
  dialect from `platform-notifications`?

---
kind: code
---

# Score candidates, match them to vacancies, and take referrals

## Why

Three recruiting habits have no home in humaniq today.

- **Interview scores.** After an interview, two interviewers each have an opinion. humaniq's
  `Interview` holds the time, the mode and the interviewers' names; `job-application` holds
  the candidate's data. Neither has a score or a note, so the verdicts live in e-mails and
  the adviser compares them from memory.
- **Matching.** When a vacancy opens, nobody sees which earlier applicants in the talent
  pool, or which colleagues with the right competences, already fit it. Each vacancy starts
  from zero.
- **Referrals.** An employee who knows a good candidate has no way to put them forward.
  They forward a CV to HR by mail, and nobody knows afterwards who referred whom.

This change adds evaluation criteria per vacancy and one scored evaluation per interviewer,
an explainable match score between a vacancy and applicants or employees, and a referral
action on the employee's own HR pages.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `hir-candidate-evaluation` | Record interviewers' scores and notes on a candidate. | `no`: `Interview` and `job-application` carry no score, rating or feedback field |
| `dm-vacancy-matching` | Have applicants and existing employees matched to open vacancies with a score based on education, level and competences from their CV. | `no`: no match score, no CV profile |
| `hir-referrals` | Let employees refer candidates and track the referral. | `no`: nothing named referral exists |

### Demand

- `dm-vacancy-matching`, changelog: https://klant.afas.nl/update/profit-8/hrm

### Competitors rated yes

- `hir-candidate-evaluation`, AFAS Profit: "applicants are assessed per vacancy against
  self-defined assessment criteria and a general assessment through the workflow Beoordelen
  sollicitant" (https://help.afas.nl/help/NL/SE/133959.htm).
- `hir-candidate-evaluation`, HR2day: "selection committee members score applicants" and
  "score per reviewer and structured feedback"
  (https://data.maglr.com/1697/issues/66612/785292/index.html,
  https://www.hr2day.com/hire2day/screening/).
- `hir-candidate-evaluation`, Personio: "configurable evaluation forms submitted via the
  candidate's evaluation tab or a feedback request"
  (https://support.personio.de/hc/en-us/articles/360000880057-Use-candidate-evaluation-forms).
- `dm-vacancy-matching`, AFAS Profit: "vacancy matching compares vacancies with applicants
  and employees ... and shows a matching score"
  (https://klant.afas.nl/update/profit-8/hrm, https://help.afas.nl/help/NL/SE/140214.htm).
- `dm-vacancy-matching`, HR2day: "Skills-based zoeken, Automatische kandidaat scoring,
  Semantic CV matching" (https://www.hr2day.com/hire2day/sourcing/).
- `hir-referrals`, Personio: "employees can refer candidates for vacancies once the feature
  is activated"
  (https://support.personio.de/hc/en-us/articles/24725370605213-Set-up-employee-referrals).

### Recorded follow-ups this change picks up

- `2026-07-13-recruiting-ats-basic` dropped the round-1 draft's interview and event entities
  as out of MVP scope; `2026-07-15-interview-scheduling` added `Interview` for the calendar
  only. Neither refuses scoring. No recorded non-goal names matching or referrals.

## What Changes

- **Criteria per vacancy.** `Vacancy` gains `evaluationCriteria`, a short list of what the
  interviewers score (for example "payroll knowledge", "communication").
- **One evaluation per interviewer.** A new `CandidateEvaluation` schema holds, for one
  application and optionally one interview, the evaluator, a 1 to 5 score and a note per
  criterion, an overall recommendation and a free note. `ApplicationDetail` lists the
  evaluations with the average per criterion. Evaluations are deleted with their
  application.
- **A candidate profile and vacancy requirements.** `job-application` gains a `profile`
  (education level, years of experience, competence codes), filled by HR or read from the CV
  by filinq. `Vacancy` gains `requirements` (minimum education level, minimum years,
  required and preferred competence codes, normfunctie).
- **An explainable match score.** `GET /api/vacancies/{id}/matches` scores open and
  talent-pool applicants and active employees against the vacancy: 0 to 100 with the points
  per requirement. Employees are matched on their `EmployeeCompetence` records.
  `VacancyDetail` shows the ranked list. The score ranks; it never rejects anyone.
- **Referrals from employees.** A `Vacatures` page under Mijn HR lists published vacancies
  with `Refer someone`. The referral creates an application at `nieuw` with
  `source: referral` and the referrer stamped by the server, and the referrer sees the
  status of their own referrals, and nothing else about the candidate.

## Capabilities

### New Capabilities

- `candidate-assessment`: evaluation criteria and scored evaluations, an explainable
  vacancy match score, and employee referrals.

## Impact

- `lib/Settings/register.d/hr-ats.json`: `Vacancy` gains `evaluationCriteria` and
  `requirements`; `job-application` gains `profile`, `source` and `referredByUserId`; new
  `CandidateEvaluation` schema with an average-per-criterion aggregation.
- `lib/Service/VacancyMatchService.php`, `lib/Service/ReferralService.php` (new).
- `lib/Controller/RecruitingController.php` (new) and `appinfo/routes.php`:
  `GET /api/vacancies/{id}/matches`, `POST /api/referrals`, `GET /api/referrals/mine`.
- `src/manifest.d/hr-ats.json`: evaluations on `ApplicationDetail`, matches on
  `VacancyDetail`, a `MijnVacatures` page and a `MijnAanbevelingen` page, and menu entries.

## Cross-app dependencies

- **filinq**: reading a CV into the `profile` shape (education level, years of experience,
  competence codes), the same duck-typed extraction path as receipt OCR. Without it HR fills
  the profile by hand and matching still works.

## Out of scope

- Any automated rejection. A low score never moves an application.
- Semantic or AI ranking. The score is arithmetic over stated requirements.
- Referral bonuses and their payout.

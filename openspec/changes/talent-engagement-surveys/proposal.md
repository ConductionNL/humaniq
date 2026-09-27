---
kind: code
depends_on: [platform-notifications]
---

# Anonymous engagement surveys

## Why

An HR adviser who wants to know how people feel about their work, their manager or the
workload cannot ask them through humaniq. There is no survey, no question list and no
result. The adviser uses a separate forms tool, pastes in a list of e-mail addresses, and
then cannot break the results down by team, because the forms tool does not know the teams,
or breaks them down so finely that a team of three can be read person by person.

humaniq knows who works where. This change lets HR build a survey, send it to a group of
employees, collect answers without storing who gave them, and report per team only when a
team has enough answers to stay anonymous.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `tal-surveys` | Run an employee engagement survey. | `no`: no survey schema or page |

### Competitors rated yes

- `tal-surveys`, AFAS Profit: "surveys built from a survey model are filled in by employees
  or others through InSite and the results can be viewed directly"
  (https://help.afas.nl/help/NL/SE/Crm_Survey.htm).
- `tal-surveys`, Visma Raet Youforce: "pulse surveys, eNPS and scientifically based question
  sets" (https://youforce.nl/product/engagement).
- `tal-surveys`, HR2day: "anonymous surveys for engagement, culture and exit with response
  dashboards" (https://www.hr2day.com/features/surveys/).
- `tal-surveys`, Personio: "confidential employee surveys with analytics"
  (https://support.personio.de/hc/en-us/articles/13444341561501-Overview-of-Personio-Surveys).

### Recorded follow-ups this change picks up

- `2026-07-13-performance-reviews-mvp` keeps 360 and peer feedback out; a survey is not a
  review of a person and does not touch it.
- `dm-survey-benchmark` (deferred in this pass) depends on this change existing.
- No recorded non-goal names engagement surveys.

## What Changes

- **A survey with its own questions.** A new `Survey` schema holds a title, an introduction,
  a question list in the manifest `fields[]` shape (scale, choice, free text, and a 0 to 10
  "would you recommend working here" question for eNPS), who it goes to (everyone, org
  units, or a contract type), an open and a close date, and a minimum group size for results
  (default 5). HR builds the questions with the library's `CnFormBuilder`.
- **Invitations track who answered, not what.** Opening a survey creates one
  `SurveyInvitation` per employee in scope, notified through the canonical dialect, with a
  reminder three days before it closes. The invitation records only whether the employee
  answered.
- **Answers carry no name.** An employee answers on `Mijn enquetes`. The server marks their
  invitation done and stores the `SurveyResponse` with the survey, the answers and the
  employee's org unit at that moment, and without the employee, their account or the
  invitation.
- **Results that stay anonymous.** A results page shows per question the distribution, the
  average and the eNPS, overall and per org unit. A unit with fewer answers than the minimum
  group size is folded into "other" and never shown on its own. Free-text answers are shown
  only overall.

## Capabilities

### New Capabilities

- `engagement-surveys`: surveys with their own questions, invitations that record
  participation only, responses without identity, and results with a minimum group size.

## Impact

- `lib/Settings/register.d/hr-survey.json` (new fragment): `Survey` (with an
  `x-openregister-lifecycle` `concept`, `open`, `gesloten`), `SurveyInvitation` (with the
  invitation and reminder notification rules), `SurveyResponse`.
- `lib/Service/SurveyService.php` (new): opening a survey, accepting a response, results
  with the group-size rule.
- `lib/Controller/SurveyController.php` (new) and `appinfo/routes.php`:
  `POST /api/surveys/{id}/open`, `POST /api/surveys/{id}/responses`,
  `GET /api/surveys/{id}/results`.
- `src/manifest.d/hr-survey.json` (new): `Enquetes`, `SurveyDetail` with the question builder
  and results, `MijnEnquetes` under Mijn HR.

## Cross-app dependencies

- **openregister**: the canonical notification dialect for invitations and reminders,
  specified app-wide in `platform-notifications`.

## Out of scope

- Benchmarking against other employers (`dm-survey-benchmark`).
- Surveys for people outside the organisation, and exit surveys sent to leavers.
- AI summaries of free-text answers.

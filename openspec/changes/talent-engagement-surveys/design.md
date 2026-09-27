# Design: anonymous engagement surveys

## Context

Read at `development` af702f78.

- No survey exists: `grep -rliF survey lib src openspec` finds only salary-band wording in
  `comp-cycles` and the parity file.
- `Employee` (`hr-objects.json:5`) holds `nextcloudUserId`; `OrgAssignment` (`hr-org.json`)
  places an employee in an `OrgUnit` over dates; `EmploymentContract.type` gives the contract
  kind. `lib/Service/OrgResolutionService.php:71` answers whether a placement is active on a
  date.
- The Mijn HR group (`src/manifest.d/05-menu.json:4`) holds the employee's own pages, which
  filter on `userId` `@me`.
- The library's `CnFormBuilder` edits a `fields[]` list of `{key, type, label, required,
  options}`; `CnChartWidget` and `CnStatsBlock` render distributions and figures.
- humaniq has no notification rule today; `platform-notifications` adopts the canonical
  dialect app-wide.

## Goals / Non-Goals

**Goals**

- HR runs a survey for a group without a second tool.
- No stored record links an answer to a person.
- No result can be read for a group smaller than the survey's minimum.

**Non-Goals**

- Benchmarks, external respondents, AI summaries.

## Decisions

### D1. Participation and content are separate records

`SurveyInvitation`: `surveyId`, `employeeId`, `userId`, `status` (`open`, `beantwoord`),
`answeredAt`. `SurveyResponse`: `surveyId`, `orgUnitId` (the respondent's placement on the
day they answered), `contractType`, `answers` (object keyed by question key),
`submittedOn` (date only, no time). A response holds no employee, account, invitation id or
timestamp finer than a day, so it cannot be joined back through the audit trail's times.

`POST /api/surveys/{id}/responses` (`#[NoAdminRequired]`) finds the caller's open
invitation, refuses when there is none or it is already `beantwoord`, marks it
`beantwoord`, and creates the response under an internal write so the response's audit
entry names humaniq and not the respondent.

Alternative considered: one response record with an `anonymous` flag. Rejected: a flag is a
promise; separate records without a link are a fact.

### D2. Opening a survey creates the invitations

`POST /api/surveys/{id}/open` (admin or HR) moves the survey to `open` through its
lifecycle and creates one invitation per active employee in scope with a Nextcloud account.
Two notification rules on `SurveyInvitation`: `created` to the invitation's `userId`, and
`scheduled` daily with filter `status: open` for the three days before `closesOn`, to the
same recipient. Employees without an account are counted and reported, not invited.

### D3. Results fold small groups

`GET /api/surveys/{id}/results` (admin or HR) answers per question: count, distribution,
average for scales, and for the recommend question the eNPS (share of 9 and 10 minus share
of 0 to 6). Per org unit it answers the same only for units with at least `minGroupSize`
responses; smaller units are summed into `other`, and when `other` itself is below the
minimum it is dropped with a note. Free-text answers are returned only overall, in random
order. Results are computed on read.

Alternative considered: `x-openregister-aggregations` per question. Rejected for the unit
breakdown: the minimum group size rule folds groups, which an aggregation does not do; the
overall counts could use it, and the service keeps one path for both.

### D4. The response rate is public to HR, the respondents are not

`SurveyDetail` shows invitations sent, answered and the rate, from `SurveyInvitation`, and
never lists who answered what. Who has not answered yet is visible to HR, so a reminder can
be sent, which is what the invitation record is for.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| survey status | declarative `x-openregister-lifecycle` | a plain state machine |
| invitation and reminder | declarative `x-openregister-notifications` | the canonical dialect |
| unlinking the answer | imperative endpoint with an internal write | the separation must happen server-side |
| results with folding | imperative, on read | a group-size rule over a breakdown |
| question builder and charts | library `CnFormBuilder`, `CnChartWidget` | Vue logic stays in nextcloud-vue |

## Seed data

- One closed survey with five questions including the recommend question, twelve responses
  across two units (seven and five), and one unit with two responses, so the folding shows.
- One open survey with an open invitation for the seed employee linked to `admin`.

## Risks / Trade-offs

- [A unit of five can still be guessed by elimination across questions] → the minimum is
  configurable upward, and free text is never shown per unit.
- [An employee answers twice with two accounts] → one invitation per employee record, and
  the response needs that employee's open invitation.

## Open Questions

- Should HR be able to see the open invitations only as a count, to keep reminders from
  becoming pressure on named people?

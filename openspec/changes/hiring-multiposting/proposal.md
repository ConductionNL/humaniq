---
kind: config
---

# Post a vacancy to several job boards at once

## Why

When an HR adviser publishes a vacancy in humaniq today, it goes nowhere. The `publiceren`
transition marks it `gepubliceerd` inside humaniq and stamps `publishedDate`; to reach
werk.nl, LinkedIn or Indeed the adviser logs in to each board, pastes the same advert three
times and has to remember to take all three down when the vacancy closes. The schema
description says this plainly: "no external multiposting (werk.nl/LinkedIn is an
OpenConnector follow-up)".

This change lets HR tick the boards a vacancy goes to, and posts and withdraws it on each
board through integriq, the fleet's connector app. humaniq keeps one posting record per
board, so the adviser sees where the advert is live and where it failed.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `hir-multiposting` | Post a vacancy to several job boards in one go. | `no`: publishing is internal; the `Vacancy` description names multiposting as an integriq follow-up |

### Competitors rated yes

- `hir-multiposting`, Visma Raet Youforce: "publish vacancies from one platform to social
  media, jobboards and the werken-bij site" (https://youforce.nl/product/werving-en-selectie).
- `hir-multiposting`, HR2day: "post a vacancy on many job boards with one click" and
  "connects with Indeed, LinkedIn and more" (https://www.hr2day.com/hire2day/sourcing/,
  https://www.hr2day.com/features/recruitment-ats/).
- `hir-multiposting`, Personio: "post a job on many paid and free job boards at once from
  within Personio, through the partner GoHiring"
  (https://support.personio.de/hc/en-us/articles/115005416109-Promote-jobs-on-external-job-boards-via-multiposting).

### Recorded follow-ups this change picks up

- `2026-07-13-recruiting-ats-basic` proposal, Non-goals: "No werk.nl/LinkedIn multiposting.
  External channel publication is an OpenConnector integration follow-up; the MVP
  `publiceren` transition marks the vacancy published inside hrmq only."

## What Changes

- **Boards per vacancy.** `Vacancy` gains `channels`, a list of board codes the adviser
  ticks on `VacancyDetail`. The codes match the integriq source each board is reached
  through.
- **One posting record per board.** A new `VacancyPosting` schema records, for one vacancy
  and one board, whether the advert is `te-plaatsen`, `geplaatst`, `mislukt` or
  `ingetrokken`, with the board's own id and link, when it was posted and withdrawn, and the
  last error.
- **Two shipped flows.** `Vacature plaatsen` runs on the `publiceren` transition: for each
  ticked board it maps the vacancy to the board's format with integriq's mapping node, calls
  the board through integriq's source-call node, and writes the outcome to that board's
  posting. `Vacature intrekken` runs on `sluiten` and withdraws every live posting. Both
  arrive disabled, as the engine's adoption contract requires; an admin enables them once the
  boards are configured in integriq.
- **Where the advert is live.** `VacancyDetail` lists the vacancy's postings with status and
  link.
- **The description follows the code.** The `Vacancy` description drops "no external
  multiposting".

## Capabilities

### New Capabilities

- `vacancy-multiposting`: choosing job boards per vacancy, posting and withdrawing through
  integriq, and one posting record per board.

## Impact

- `lib/Settings/register.d/hr-ats.json`: `Vacancy` gains `channels` and two
  `x-openregister-flows` entries (0.3.0); new `VacancyPosting` schema (0.1.0).
- `lib/Settings/humaniq_register.json`: `VacancyPosting` added to the register's schema list.
- `src/manifest.d/hr-ats.json`: `VacancyDetail` gains the channel choice and a postings
  list.
- `lib/Settings/register.d/hr-seed.json`: one posting per outcome on the seeded vacancy.
- No PHP. No new controller or service.

## Cross-app dependencies

- **integriq**: one source per job board (werk.nl, LinkedIn, Indeed to start), the
  credentials for each, and a mapping from a humaniq vacancy to each board's format. The
  flows call `openconnector.apply-mapping` and `openconnector.source-call`; humaniq holds no
  board URL, token or HTTP client.
- **openregister**: the flow engine, `openregister.trigger-object`,
  `openregister.iterate` and `openregister.object-write`.

## Out of scope

- A public careers page. That is `hiring-portal-audiences`.
- Pulling applications back from the boards. Candidates who apply on a board still reach
  humaniq by the board's own route until a board-specific import is specified.
- Paid-posting budgets and campaign reporting.

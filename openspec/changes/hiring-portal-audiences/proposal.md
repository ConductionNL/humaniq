---
kind: code
---

# Candidates, new hires and leavers on the portal

## Why

A job seeker cannot apply to a humaniq employer today. Vacancies are published inside
humaniq only (`Vacancy.status` `gepubliceerd`), so HR copies the advert to a website by
hand, collects CVs by e-mail and types every application into `Applications` themselves.
The vacancy schema says so in its own description: "no public career page (portaliq per
ADR-046)".

The same gap shows twice more. A new hire who signed last week has no way to send their ID,
bank details and diplomas before day one: HR chases them by mail and ticks the onboarding
checklist when the papers arrive. And a leaver who needs a payslip or their annual statement
in March has to ask HR, because humaniq's portal contribution serves only current external
employees, clients and external managers.

humaniq already ships a portal contribution (`lib/Portal/PortalContributionProvider.php`),
and portaliq already serves anonymous forms and collections. This change adds three
audiences to that contribution: a candidate, a new hire and a former employee. It also lets
HR add their own questions to a vacancy's application form.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `hir-career-page` | Publish vacancies on a public careers page where candidates can apply. | `no`: `getAudiences()` returns `external-employee`, `client` and `manager`; nothing reaches a candidate |
| `dm-applicant-questions` | Add your own questions to the public job application form. | `no`: `job-application` has fixed properties and no per-vacancy question list |
| `hir-preboarding` | Give a new hire access to information and forms before their first day. | `no`: nothing named preboarding exists; `Onboarding` records outcomes HR ticks |
| `ess-external-portal` | Offer an external portal for applicants or former employees. | `partial`, built: the portal serves payroll externals and clients, not applicants or former employees |

### Demand

- `dm-applicant-questions`, feature request: https://github.com/orangehrm/orangehrm/issues/484

### Competitors rated yes

- `hir-career-page`, AFAS Profit: "you add a vacancy and publish it as a document on InSite
  and OutSite so the applicant can apply" (https://help.afas.nl/help/NL/SE/133958.htm).
- `hir-career-page`, Visma Raet Youforce: "builds a werken-bij-site where candidates can
  apply easily" (https://youforce.nl/product/werving-en-selectie).
- `hir-career-page`, HR2day: "manage your werken-bij career site and add vacancies directly
  from Hire2day" (https://www.hr2day.com/hire2day/sourcing/).
- `hir-career-page`, Personio: "the career page is a separate website showing published jobs
  where candidates apply through the application form"
  (https://support.personio.de/hc/en-us/articles/8758636857629-Set-up-the-Personio-career-page).
- `hir-career-page`, OrangeHRM: "public /recruitmentApply/jobs.html list and applyVacancy
  form" (orangehrm v5.9 `src/plugins/orangehrmRecruitmentPlugin/config/routes.yaml:284`).
- `dm-applicant-questions`, AFAS Profit: "free fields can be added to the online application
  form in OutSite" (https://help.afas.nl/help/NL/SE/134856.htm).
- `dm-applicant-questions`, Personio: "add application form fields per job" and "screening
  questions that candidates must answer when applying"
  (https://support.personio.de/hc/en-us/articles/115003421629-Create-a-job).
- `hir-preboarding`, AFAS Profit: "the action Uitvragen aanvullende gegevens lets the
  applicant supply their own documents (ID, VOG), education and family data before starting"
  (https://help.afas.nl/help/NL/SE/139736.htm).
- `hir-preboarding`, Visma Raet Youforce: "preboarding offers information before the first
  working day" (https://youforce.nl/product/onboarding).
- `hir-preboarding`, HR2day: "preboarding gives candidates and new employees a good
  experience before day one" (https://www.hr2day.com/features/on-offboarding/).
- `hir-preboarding`, Loket.nl: "new employees fill in their data and upload documents in one
  secure online environment before the first working day"
  (https://loket.nl/functionaliteiten/preboarding/).
- `ess-external-portal`, AFAS Profit: "OutSite has a fixed authorisation role Sollicitant for
  applicants" and a leaver can "keep portal access for a set number of days after leaving"
  (https://help.afas.nl/help/NL/SE/Out_Config_Auth_Site_Role_Add.htm,
  https://help.afas.nl/help/NL/SE/133370.htm).
- `ess-external-portal`, HR2day: "mobile-friendly portal for recruiters and candidates"
  (https://www.hr2day.com/features/recruitment-ats/).

### Recorded follow-ups this change picks up

- `2026-07-13-recruiting-ats-basic` proposal, Non-goals: "No public career page. An
  external-audience surface for people without Nextcloud accounts belongs to portaliq per
  ADR-046 (the exact precedent of the shipped `portal-contribution` capability); noted as a
  portal-contribution follow-up. Applications are entered by HR in the MVP."
- The `hir-preboarding` and former-employee halves are not named in any recorded non-goal;
  nothing refuses them.

## What Changes

- **A candidate audience with an anonymous careers surface.** The contribution gains a
  `candidate` audience. Its published-vacancies collection and its apply action are flagged
  `anonymous: true`, so portaliq serves them to a visitor who is not signed in. The
  collection is narrowed to `status` `gepubliceerd` with a collection `filter`. The apply
  action creates a `job-application` with a strict field whitelist; status, retention and
  offer fields stay out of it, so the declared lifecycle starts every portal application at
  `nieuw`.
- **Questions per vacancy.** `Vacancy` gains a `questions` list in the manifest `fields[]`
  shape (`key`, `type`, `label`, `required`, `options`). HR edits it on `VacancyDetail` with
  the library's `CnFormBuilder`. `job-application` gains an `answers` object keyed by question
  key, whitelisted on the apply action and shown on `ApplicationDetail`.
- **A new-hire audience for preboarding.** A `new-hire` audience, scoped by the
  `employeeId` claim, reads the hire's own `Employee` record and `Onboarding` case, updates a
  short list of their own fields (bank account, account holder name, BSN) and uploads files to
  their onboarding case. HR still ticks the checklist; the portal only delivers the papers.
- **A former-employee audience.** A `former-employee` audience, scoped by the `employeeId`
  claim, reads the leaver's own payslips, annual statements and generated documents
  (werkgeversverklaring, getuigschrift). It is read-only.
- **The schema descriptions stop saying it does not exist.** The `Vacancy` description's
  "no public career page" clause and the `afwijzen` note about the absent candidate actor are
  rewritten.

## Capabilities

### New Capabilities

- `portal-audiences`: the candidate, new-hire and former-employee audiences on humaniq's
  portal contribution, and the per-vacancy application questions.

## Impact

- `lib/Portal/PortalContributionProvider.php`: `getAudiences()` gains three audiences;
  `getContribution()` gains three manifests.
- `lib/Settings/register.d/hr-ats.json`: `Vacancy` gains `questions` (0.3.0);
  `job-application` gains `answers` (0.4.0); description rewrites.
- `src/manifest.d/hr-ats.json`: `VacancyDetail` gains a questions section; `ApplicationDetail`
  shows the answers.
- `src/registry.js`: one host section that hands `Vacancy.questions` to `CnFormBuilder`.
- `lib/Settings/register.d/hr-seed.json`: one published vacancy with two questions and one
  portal-created application.
- `tests/Unit/Portal/PortalContributionProviderTest.php`: the new audiences.

## Cross-app dependencies

- **portaliq**: renders the careers page and the apply form from the anonymous aggregate,
  including a per-vacancy question list read from the vacancy's `questions`; throttles
  anonymous submissions (ADR-082); issues and ends new-hire and former-employee accounts and
  maps their `employeeId` claim; hosts the welcome content a new hire reads (ADR-086 CMS
  pages). humaniq holds no portal UI, account or login.

## Out of scope

- Candidate signing of the offer letter in the portal. It stays the follow-up recorded in
  `2026-07-15-offer-esign` ("candidate-facing signing portal (portaliq/ADR-046)").
- Publishing to external job boards. That is `hiring-multiposting`.
- Creating the employee from a hired application. That is `hiring-hire-to-employee`.
- Automatic checklist ticks from uploaded documents. HR still reviews what arrives.

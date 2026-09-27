---
kind: code
---

# From hired applicant to employee without retyping

## Why

When an HR adviser presses `Hire` on an application in humaniq today, the application's
status moves to `aangenomen` and that is all. The adviser then opens `Employees`, creates a
new employee and types the candidate's name again, opens `Onboardings` and creates the case
by hand. The application's own description says so: "creating the Employee from this data is
a manual follow-up action in the MVP".

Two things go wrong along the way. A former employee who comes back gets a second employee
record, because nothing looks for the first one, so their history and their five-year
ID-copy retention sit on a record nobody opens. And the signed contract or passport the new
hire sends in is read by a person, who types its dates and hours into the record.

This change adds a `Create employee` action to the hired application that carries its data
over, offers the existing record when the person worked here before, and lets filinq read a
supplied contract or ID into the new employee's empty fields for HR to check.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `hir-hire-to-employee` | Turn a hired applicant into an employee without retyping their data. | `no`, built.state `built`: `aannemen` flips the status; HR creates the employee by hand |
| `dm-rehire` | Rehire a former employee by attaching the new employment to their existing record instead of creating a duplicate. | `partial`, built: HR can add a contract to an old record by hand; nothing checks for a duplicate |
| `dm-document-to-employee` | Have supplied documents such as a signed contract read automatically into a draft new employee for HR to check. | `no`: the only extraction in humaniq is receipt OCR on expenses |

### Demand

- `dm-rehire`, changelog: https://data.maglr.com/1697/issues/66612/785293/index.html
- `dm-document-to-employee`, roadmap: https://loket.nl/roadmap/

### Competitors rated yes

- `hir-hire-to-employee`, AFAS Profit: "when the organisation hires an applicant you can
  onboard them without recording the data again" (https://help.afas.nl/help/NL/SE/133938.htm).
- `hir-hire-to-employee`, Visma Raet Youforce: "candidate data from the ATS is exchanged with
  Youforce so you do not re-enter data"
  (https://vr-api-integration.github.io/youforce-api-documentation/recruitment_api_intro.html).
- `hir-hire-to-employee`, HR2day: "candidate becomes employee without double entry"
  (https://www.hr2day.com/hire2day/).
- `hir-hire-to-employee`, Loket.nl: "convert a concept employee to an employee"
  (https://developer.loket.nl/ApiDocs#tag/Concept-employee).
- `hir-hire-to-employee`, Personio: "when the candidate accepts, Personio automatically
  creates an employee profile"
  (https://support.personio.de/hc/en-us/articles/5150116626461-Manage-an-offer-and-its-stages).
- `hir-hire-to-employee`, OrangeHRM: "the hire action creates a new Employee from the
  candidate's name and e-mail" (orangehrm v5.9
  `src/plugins/orangehrmRecruitmentPlugin/Api/AbstractCandidateActionAPI.php:126`).
- `dm-rehire`, AFAS Profit: "Je hoeft een herintredende medewerker dus niet opnieuw toe te
  voegen, je meldt deze alleen in dienst"
  (https://help.afas.nl/help/NL/SE/Hrm_Employ_Add_ReEntr.htm).
- `dm-rehire`, HR2day: "when the BSN already exists and the person has left, the hiring
  process can be moved onto the known employee"
  (https://data.maglr.com/1697/issues/66612/785293/index.html).
- `dm-rehire`, Loket.nl: "Create an additional employment for an already existing employee"
  (https://developer.loket.nl/ApiDocs).
- `dm-document-to-employee`: no competitor is rated yes; the row is built on the Loket.nl
  roadmap demand.

### Recorded follow-ups this change picks up

- `2026-07-13-recruiting-ats-basic` proposal, Non-goals: "No automatic Employee creation on
  hire. The onboarding hand-off is documented as a manual action on the `aannemen`
  transition in the MVP (cross-object write hooks are the same follow-up class as
  leave-balance auto-posting)."
- `2026-07-15-offer-esign` keeps auto-hire out: a completed signature never moves the
  application. This change keeps that: creating the employee is HR's action after `Hire`.

## What Changes

- **A `Create employee` action on a hired application.** On `ApplicationDetail` at
  `aangenomen`, HR confirms the start date and the proposed first and last name, and may add
  a BSN and date of birth. humaniq creates the `Employee`, copies the e-mail and phone into
  two new contact fields, creates the `Onboarding` case at `aangenomen` with the start date,
  and links the application to the employee. Pressing it again opens the same employee.
- **A former employee is found, not duplicated.** Before creating, humaniq looks for an
  existing employee with the same BSN, the same last name and date of birth, or the same
  private e-mail, and shows the matches. HR attaches the hire to one of them or creates a new
  record on purpose. Attaching clears the old `endDate`, starts the new onboarding case on
  that record, and keeps the earlier contracts as they are.
- **A supplied document fills the empty fields.** On `OnboardingDetail`, `Read document`
  sends a file attached to the case to filinq's extraction as an employment contract or an
  identity document. humaniq fills only the employee's empty fields, records what was read,
  with what confidence and which fields it wrote, and offers `Create contract from
  document` for the contract values. HR checks every value before the checklist moves on.

## Capabilities

### New Capabilities

- `hire-to-employee`: creating or reattaching the employee from a hired application, and
  reading supplied documents into the new employee's empty fields.

## Impact

- `lib/Settings/register.d/hr-ats.json`: `job-application` gains `employeeId` (0.4.0); the
  `aannemen` description is rewritten.
- `lib/Settings/register.d/hr-objects.json`: `Employee` gains `privateEmail` and `phone`
  (0.9.0).
- `lib/Settings/register.d/hr-onboarding.json`: new `EmployeeDocumentExtraction` schema.
- `lib/Service/HireService.php`, `lib/Service/HireMatchService.php`,
  `lib/Service/EmployeeDocumentExtractionService.php` (new).
- `lib/Controller/HireController.php` (new) and `appinfo/routes.php`:
  `GET /api/applications/{id}/hire-matches`, `POST /api/applications/{id}/hire`,
  `POST /api/onboarding/{id}/read-document`,
  `POST /api/onboarding/{id}/contract-from-document`.
- `src/dialogs/HireApplicationDialog.vue` (new) wiring the library's `CnFormDialog` and
  `CnDataTable`; actions on `ApplicationDetail` and `OnboardingDetail`.

## Cross-app dependencies

- **filinq**: extraction of an employment contract (names, start and end date, hours per
  week, hourly or monthly wage, contract type) and of an identity document (names, date of
  birth, document number, expiry date, personal number), with a confidence per field. The
  receipt extraction `FinancialExtractionService::extractFinancial()` is the precedent;
  these two document types are filinq's to add. Without them humaniq records
  `skipped-no-docudesk` or `failed` and fills nothing.

## Out of scope

- Moving the CV to the personnel file. The application keeps its own retention clock.
- Checking an identity document for authenticity. That is `people-dossier-completeness`.
- Hiring on a completed offer signature. It stays HR's decision, as `offer-esign` recorded.

# portal-audiences Specification

## Purpose
The candidate, new-hire and former-employee audiences on humaniq's portal contribution, and the per-vacancy application questions: a job seeker applies without an account, a new hire hands in their details and papers before day one, and a leaver reads their own paperwork. Built by hiring-portal-audiences (archived 2026-09-30).

## Requirements

### Requirement: Published vacancies SHALL be readable and open to applications without an account (REQ-PAU-001)

humaniq's portal contribution SHALL declare a `candidate` audience whose vacancy collection
and apply action are flagged `anonymous: true`. The collection SHALL return only vacancies
with `status` `gepubliceerd` and SHALL NOT expose `administrationId`. The apply action SHALL
create a `job-application` from a whitelist that excludes `status`, `rejectedDate`,
`retentionExpiryDate`, `administrationId` and every offer field, so every portal application
starts at `nieuw` under the existing lifecycle.

Rows: `hir-career-page`, `ess-external-portal` (humaniq matrix).

#### Scenario: A job seeker applies from the careers page
- **GIVEN** a vacancy "Payroll adviser" with status `gepubliceerd` and a vacancy "Controller"
  with status `concept`
- **WHEN** an applicant who is not signed in opens the careers page and applies to "Payroll
  adviser" with their name, e-mail and CV
- **THEN** only "Payroll adviser" was listed, and HR finds a new application for it in
  `Applications` with status `nieuw` and the CV on `ApplicationDetail`

@e2e exclude the careers page and apply form are rendered by portaliq; humaniq's half is the contribution descriptor, covered by PortalAudiencesTest::testTheCareersSurfaceIsAnonymousAndNarrow (only status gepubliceerd, anonymous, projected) and PortalAudiencesTest::testAPortalApplicationWithAnswersIsValid; the live round is task 4.2

#### Scenario: A crafted submission cannot skip the pipeline
- **GIVEN** the anonymous apply action
- **WHEN** a submission carries `status: aangenomen` and a `retentionExpiryDate`
- **THEN** both fields are dropped and the application is created at `nieuw` with no
  retention date

@e2e exclude portaliq applies the action's field whitelist before any write; covered by PortalAudiencesTest::testTheCareersSurfaceIsAnonymousAndNarrow (status, retentionExpiryDate, rejectedDate, administrationId and the offer fields are not whitelisted) and the job-application lifecycle initial state nieuw

### Requirement: HR SHALL be able to add questions to a vacancy's application form (REQ-PAU-002)

`Vacancy` SHALL carry a `questions` list in the manifest `fields[]` shape, edited on
`VacancyDetail`. `job-application` SHALL carry an `answers` object keyed by question key,
accepted by the apply action and shown on `ApplicationDetail`. Answers SHALL be deleted
with the application that holds them.

Rows: `dm-applicant-questions` (humaniq matrix).

#### Scenario: A driving licence question reaches the recruiter
- **GIVEN** an HR adviser who adds a required yes or no question "Do you hold a driving
  licence?" to a published vacancy
- **WHEN** an applicant answers yes on the careers page
- **THEN** the HR adviser reads "Do you hold a driving licence?: yes" on that
  application's `ApplicationDetail`

@e2e exclude the question list and answers are schema data rendered by host widgets; covered by PortalAudiencesTest::testAVacancyCarriesItsQuestions and PortalAudiencesTest::testAPortalApplicationWithAnswersIsValid, seeded as vacancy-vue-developer and application-portal-nieuw

#### Scenario: Answers leave with the application
- **GIVEN** a rejected application with answers whose retention date has passed
- **WHEN** HR deletes the application
- **THEN** no answer of that applicant remains in the register

@e2e exclude answers are a property of the application object, so deleting the object deletes them; covered by the schema (job-application.answers) asserted in PortalAudiencesTest::testTheCareersSurfaceIsAnonymousAndNarrow

### Requirement: A new hire SHALL hand in their own details and papers before the first day (REQ-PAU-003)

humaniq's portal contribution SHALL declare a `new-hire` audience scoped by the
`employeeId` claim that reads the hire's own `Employee` record and `Onboarding` case,
updates only `iban`, `tenaamstelling` and `bsn` on their own record at `minTrust`
`substantial`, and uploads files onto their own onboarding case. It SHALL NOT tick any
onboarding checklist field.

Rows: `hir-preboarding` (humaniq matrix).

#### Scenario: A hire uploads their ID a week before starting
- **GIVEN** an onboarding case with `startDate` next Monday and `widCheckDone` false
- **WHEN** the new hire signs in to the portal with DigiD, enters their bank account and
  uploads a copy of their passport
- **THEN** the HR adviser sees the new IBAN on the employee and the passport in the files of
  `OnboardingDetail`, and `widCheckDone` is still false until the adviser ticks it

@e2e exclude the upload is portaliq's file endpoint on a collection that opts in; covered by PortalAudiencesTest::testPreboardingIsScopedAndNarrow (myOnboarding scoped on employeeId with filesUpload)

#### Scenario: A hire cannot read a colleague's case
- **GIVEN** two new hires with their own onboarding cases
- **WHEN** the first requests the second's case by id through the portal
- **THEN** the answer is not found and no field of the second case is returned

@e2e exclude portaliq enforces the scopeField and scopeClaim on every read; covered by PortalAudiencesTest::testPreboardingIsScopedAndNarrow (both collections scoped on the employeeId claim, nothing anonymous)

### Requirement: A former employee SHALL read their own payslips and letters after leaving (REQ-PAU-004)

humaniq's portal contribution SHALL declare a read-only `former-employee` audience scoped
by the `employeeId` claim, with collections over the leaver's own `Payslip`, `Jaaropgaaf`
and generated `HrGeneratedDocument` records, and no action.

Rows: `ess-external-portal` (humaniq matrix).

#### Scenario: A leaver downloads last year's annual statement
- **GIVEN** an employee whose `endDate` was last December and a `Jaaropgaaf` for that year
- **WHEN** the former employee signs in to the portal in March
- **THEN** they see their annual statement and payslips and no create or update action

@e2e exclude the leaver's pages are portaliq's; covered by PortalAudiencesTest::testALeaverReadsTheirOwnPaperworkOnly (three collections scoped on employeeId, no actions)


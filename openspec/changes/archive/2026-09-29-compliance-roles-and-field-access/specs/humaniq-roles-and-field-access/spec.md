# humaniq-roles-and-field-access

## ADDED Requirements

### Requirement: humaniq SHALL distinguish HR, payroll, managers and employees (REQ-RFA-001)

humaniq SHALL use a Nextcloud HR group (`humaniq-hr`) and a payroll group
(`humaniq-payroll`), created on install and upgrade, and SHALL decide every sensitive action on its own endpoints through one role
check: HR actions for the HR group, payroll actions for the payroll group, and every action
for a Nextcloud administrator. Managers SHALL be recognised through the org chart and
employees through their own account.

Rows: `cmp-roles` (humaniq matrix).

#### Scenario: An HR adviser registers a wage garnishment without being an administrator
- **GIVEN** a user in `humaniq-hr` who is not a Nextcloud administrator
- **WHEN** they activate a wage garnishment on `LoonbeslagDetail`
- **THEN** the transition runs, and the same action by a user in neither group is refused

@e2e exclude the check runs server-side in the controller before any register read; covered by LoonbeslagControllerTest::testAnHrAdviserWhoIsNotAnAdministratorActivates and LoonbeslagControllerTest::testNonAdminCallerIsRefusedBeforeAnyResolve

#### Scenario: Payroll runs payroll, HR does not
- **GIVEN** a user in `humaniq-payroll` and a user only in `humaniq-hr`
- **WHEN** each calculates a payroll run
- **THEN** the payroll user's run is calculated and the HR user is refused

@e2e exclude the check runs server-side in the controller before any register read; covered by PayrollControllerContractTest::testCalculateLetsAPayrollMemberReachTheRun and PayrollControllerContractTest::testCalculateRefusesAnHrAdviserOutsidePayroll

### Requirement: Sensitive fields SHALL be readable only by those who need them (REQ-RFA-002)

The BSN, bank account, salary, date of birth and identity document fields of an employee, the
hourly wage on a contract and the amounts on a payslip SHALL be readable only by the HR group,
the payroll group and the employee the record is about, on every page and API, enforced by
OpenRegister property authorization.

Rows: `cmp-field-rbac` (humaniq matrix).

#### Scenario: A manager opens a team member
- **GIVEN** a manager who may open an employee in their team
- **WHEN** they open `EmployeeDetail` or read the employee through the objects API
- **THEN** name, function and contract dates are shown and the BSN, IBAN and salary are not

@e2e exclude OpenRegister strips the fields on every read; the declaration is pinned by FieldAuthorizationDeclarationTest::testEmployeeFieldsAreReadByHrPayrollAndTheEmployee and was evaluated with OpenRegister's PropertyRbacHandler (design, Verification against OpenRegister); FieldAccessListenerTest::testAManagerSavingAnEmployeeKeepsWhatTheyWereNotShown covers the manager's save

#### Scenario: An employee sees their own salary
- **GIVEN** an employee signed in to Nextcloud
- **WHEN** they read their own employee record
- **THEN** their salary and IBAN are returned

@e2e exclude OpenRegister evaluates the subject match on every read; pinned by FieldAuthorizationDeclarationTest::testEmployeeFieldsAreReadByHrPayrollAndTheEmployee and FieldAccessListenerTest::testANewContractIsStampedWithTheEmployeesAccount

### Requirement: Review content SHALL stay between employee and reviewer (REQ-RFA-003)

The content of a performance review (strengths, points to develop, agreements, goals and
rating) SHALL be readable only by the reviewed employee and the reviewer. HR SHALL read the
status, the conversation date and who finalised it, so it can track progress.

Rows: `td-review-progress-private` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415705.

#### Scenario: HR follows a cycle without reading it
- **GIVEN** a review cycle with ten reviews, four of them discussed
- **WHEN** an HR adviser opens `ReviewCycleDetail`
- **THEN** they see four reviews discussed and six open, and opening a review shows its status
  and date but not its agreements or rating

@e2e exclude OpenRegister strips the content on every read; pinned by FieldAuthorizationDeclarationTest::testReviewContentStaysBetweenEmployeeAndReviewer, with FieldAccessListenerTest::testAReviewCarriesItsReviewersAccount and FieldAccessListenerTest::testHrSavingAReviewKeepsItsContent

# hr-workflows

## ADDED Requirements

### Requirement: humaniq SHALL ship adoptable onboarding and offboarding flows (REQ-HWF-001)

humaniq SHALL declare an onboarding flow that starts when an onboarding case is created and an
offboarding flow that starts when an offboarding case is created, each giving the right group
or the manager a task per checklist step and ticking the case's checklist field when the task
is done. Both SHALL arrive disabled, with their adoption steps in the description.

Rows: `plt-workflows`, `hir-onboarding-checklist` (humaniq matrix).

#### Scenario: IT gets a task when a hire starts onboarding

@e2e exclude The flow ships disabled and runs inside OpenRegister's flow engine, so no e2e journey reaches it until an administrator adopts it; its declaration, the IT task's address and the itProvisioned tick are verified by HrWorkflowsDeclarationTest::testTasksAreAddressedAndGatesReadTheirOutcome and ::testTheChecklistFieldsAreTicked, and the live run is task 2.1's second half
- **GIVEN** the enabled onboarding flow
- **WHEN** an HR adviser creates an onboarding case for a new employee
- **THEN** the IT contact group has a task to provide the account, and when it is marked done
  `itProvisioned` is ticked on `OnboardingDetail`

#### Scenario: The manager gets their own onboarding steps

@e2e exclude The manager's tasks resolve their performer from the employee's managerUserId, which OpenRegister does not support yet (ConductionNL/openregister#10565), and the flow ships disabled; the steps and their assignee are verified by HrWorkflowsDeclarationTest::testTheManagerStepsGoToTheEmployeesManager
- **GIVEN** the enabled onboarding flow and a new hire whose unit is led by Hanneke de Wit
- **WHEN** an HR adviser creates the onboarding case
- **THEN** Hanneke de Wit has tasks to arrange the workplace and a buddy, share the first month's
  programme and plan the probation talk, and `OnboardingDetail` lists them under the manager
  next to the HR and IT steps

#### Scenario: A shipped flow does nothing until adopted

@e2e exclude The shipped-disabled state is a property of the imported flow definition, not of a screen; verified by HrWorkflowsDeclarationTest::testTheFlowStartsOnItsSchemaAndArrivesInert for each of the three flows
- **GIVEN** a fresh install
- **WHEN** an offboarding case is created
- **THEN** no task is created, and `Flows` lists "Uitdiensttreding" as disabled

### Requirement: An undecided leave request SHALL escalate to HR (REQ-HWF-002)

humaniq SHALL declare a flow that, once enabled, waits a set number of days after a leave
request is submitted and, when the request is still undecided, notifies the HR group and gives
it a follow-up task.

Rows: `plt-workflows` (humaniq matrix).

#### Scenario: A forgotten request reaches HR

@e2e exclude The escalation waits days before it rechecks, which no e2e run can sit through, and the flow ships disabled; the wait, the recheck and the HR task are verified by HrWorkflowsDeclarationTest::testTheEscalationWaitsAndRechecks
- **GIVEN** the enabled escalation flow with a wait of five days
- **WHEN** a leave request stays submitted for five days
- **THEN** the HR group is notified and has a task naming the request, and a request decided
  on day three produces nothing

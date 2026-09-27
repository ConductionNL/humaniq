# hr-workflows

## ADDED Requirements

### Requirement: humaniq SHALL ship adoptable onboarding and offboarding flows (REQ-HWF-001)

humaniq SHALL declare an onboarding flow that starts when an onboarding case is created and an
offboarding flow that starts when an offboarding case is created, each giving the right group
or the manager a task per checklist step and ticking the case's checklist field when the task
is done. Both SHALL arrive disabled, with their adoption steps in the description.

Rows: `plt-workflows` (humaniq matrix).

#### Scenario: IT gets a task when a hire starts onboarding
- **GIVEN** the enabled onboarding flow
- **WHEN** an HR adviser creates an onboarding case for a new employee
- **THEN** the IT contact group has a task to provide the account, and when it is marked done
  `itProvisioned` is ticked on `OnboardingDetail`

#### Scenario: A shipped flow does nothing until adopted
- **GIVEN** a fresh install
- **WHEN** an offboarding case is created
- **THEN** no task is created, and `Flows` lists "Uitdiensttreding" as disabled

### Requirement: An undecided leave request SHALL escalate to HR (REQ-HWF-002)

humaniq SHALL declare a flow that, once enabled, waits a set number of days after a leave
request is submitted and, when the request is still undecided, notifies the HR group and gives
it a follow-up task.

Rows: `plt-workflows` (humaniq matrix).

#### Scenario: A forgotten request reaches HR
- **GIVEN** the enabled escalation flow with a wait of five days
- **WHEN** a leave request stays submitted for five days
- **THEN** the HR group is notified and has a task naming the request, and a request decided
  on day three produces nothing

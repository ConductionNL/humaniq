## ADDED Requirements

### Requirement: The page carries the setup status for an administrator (REQ-FTS-008)

The app page SHALL carry, for an administrator, the same document `GET /api/setup/status` answers,
as initial state `setup_status`, so the shell reads its setup status without a request of its own
before it loads the page's data (hydra ADR-124 page budget). For anyone else the page SHALL carry
no setup status; the shell then asks the endpoint, whose own access check answers. When the status
cannot be built (OpenRegister missing or broken), the page SHALL carry nothing and still render
(ADR-083 rule 3).

#### Scenario: An administrator's page carries the setup status
- **GIVEN** an administrator
- **WHEN** they open any humaniq page
- **THEN** the page carries `setup_status`, equal to what `GET /api/setup/status` answers them
- @e2e exclude initial state of the page render; covered by tests/Unit/Controller/PageControllerTest.php

#### Scenario: Nobody else gets the setup status in the page
- **GIVEN** a signed-in user who is not an administrator
- **WHEN** they open any humaniq page
- **THEN** the page carries no `setup_status` and the setup endpoint answers them as before
- @e2e exclude initial state of the page render; covered by tests/Unit/Controller/PageControllerTest.php

#### Scenario: A status that cannot be built carries nothing
- **GIVEN** an administrator on an instance where the setup status cannot be built
- **WHEN** they open a humaniq page
- **THEN** the page renders and carries no `setup_status`
- @e2e exclude needs a broken OpenRegister on a live instance; covered by tests/Unit/Controller/PageControllerTest.php

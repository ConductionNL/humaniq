---
capability: first-time-setup
status: done
built_by: none (the setup wizard and demo data predate a humaniq spec; written after the fact on 2026-10-07)
---

# first-time-setup Specification

**Status**: done
**Scope**: humaniq

**OpenSpec changes**: [wizard-dataset-card-load](../../changes/wizard-dataset-card-load/)
_(merged in #642, not yet archived)_ adds two requirements to this capability when it is
archived: each example data card loads itself, and the setup status reports every manifest
step. They are not repeated here.

## Purpose

An administrator who installs humaniq gets a short guided first run: a welcome, a choice to load
example data or start empty, and a closing step. The wizard shell is nextcloud-vue's
`CnSetupWizard` (ADR-042); humaniq owns the declaration in `src/manifest.json:7-36`, the three
setup endpoints in `lib/Controller/SetupController.php` and the example dataset served by
`lib/Service/DemoDataService.php`.

## Requirements

### Requirement: humaniq SHALL declare a three-step setup wizard that never gates the app (REQ-FTS-001)

`src/manifest.json` SHALL declare a `setup` block, version 1, with the steps `welcome` (info),
`demo-data` (a cards choice, optional, options from the server's `datasets`) and `done`
(summary with a health check). `GET /api/setup/status` (`appinfo/routes.php:17`) SHALL report
`completed: true`, because no step is required, so the wizard SHALL never block access to the
app. The `demo-data` step SHALL read done once the administrator has answered it, either by
loading a dataset or by declining.

Rows: `plt-setup-wizard` (humaniq matrix).

#### Scenario: The wizard offers the example data step
@e2e exclude spec written after the fact and this round changes no test; the behaviour is exercised by tests/e2e/spec-coverage/demo-data-setup-step.spec.ts, which does not yet carry this scenario's tag
- **GIVEN** a fresh install on which the example data question was never answered
- **WHEN** an administrator reads `/api/setup/status`
- **THEN** the response carries `completed: true`, the `demo-data` step reads not done, and
  `datasets` lists the cards to offer

#### Scenario: An answered step stays closed
@e2e exclude app-config state across requests; covered by SetupControllerTest::testStatusReportsTheStepDoneOnceDecided
- **GIVEN** an administrator who declined or loaded example data earlier
- **WHEN** the wizard reads the status again
- **THEN** the `demo-data` step reads done and the wizard does not reopen over the app

### Requirement: Declining example data SHALL be an answer the server records (REQ-FTS-002)

The server SHALL always offer a `none` dataset ("None, I will set this up myself") as the first
card. Choosing it, or calling `POST /api/setup/action/skip-demo-data`, SHALL import nothing and
SHALL record the step as decided in app config (`demo_data_decided`), so the question is not
asked again.

Rows: `plt-setup-wizard`, `plt-demo-data` (humaniq matrix).

#### Scenario: The administrator starts empty
@e2e exclude covered by SetupControllerTest::testChoosingNoneClosesTheStepWithoutImporting and ::testSkippingIsAnAnswerAndIsRecorded
- **GIVEN** the example data step
- **WHEN** the administrator picks None
- **THEN** no object is imported and the step reads done on the next status call

### Requirement: The example dataset SHALL be generated from the app's own schemas and imported through OpenRegister (REQ-FTS-003)

When `lib/Settings/humaniq_mock_register.json` ships, the server SHALL offer an "Example data"
card whose `objectCount` is counted from that file, and SHALL keep the count out of the
translatable description. Loading it SHALL import the file through OpenRegister's
`ConfigurationService::importFromApp` under its own configuration id (`humaniq.demo`), so the
demo import never shares a version gate with the app's real configuration import. Loading SHALL
be safe to repeat. The reply SHALL name how many objects were asked for. When the file is
missing or malformed, only the None card SHALL be offered.

Rows: `plt-demo-data` (humaniq matrix).

#### Scenario: Example data is loaded and counted
@e2e exclude spec written after the fact and this round changes no test; the behaviour is exercised by tests/e2e/spec-coverage/demo-data-setup-step.spec.ts, which does not yet carry this scenario's tag
- **GIVEN** the shipped dataset
- **WHEN** an administrator loads it from the setup wizard
- **THEN** the reply reads `success: true` and names the number of imported objects

#### Scenario: Loading twice is safe
@e2e exclude spec written after the fact and this round changes no test; the behaviour is exercised by tests/e2e/spec-coverage/demo-data-setup-step.spec.ts, which does not yet carry this scenario's tag
- **GIVEN** example data already loaded
- **WHEN** the administrator loads it again
- **THEN** the import succeeds again

### Requirement: A failed load SHALL be reported, never shown as success (REQ-FTS-004)

When the dataset cannot be read or OpenRegister is not installed, `DemoDataService::install()`
SHALL throw, naming the cause (the missing file, the invalid JSON, or the missing app), and the
setup action SHALL answer 500 with `success: false` and that message. It SHALL then record
nothing, so the step stays open for an administrator who asked for data and got none.

Rows: `plt-demo-data` (humaniq matrix).

#### Scenario: OpenRegister is missing
@e2e exclude needs an instance without OpenRegister; covered by DemoDataServiceTest::testInstallNamesTheMissingAppWhenOpenRegisterIsAbsent and SetupControllerTest::testAFailedInstallIsReportedAndLeavesTheStepUNDECIDED
- **GIVEN** an instance on which OpenRegister is not installed
- **WHEN** the administrator loads the example data
- **THEN** the reply carries `success: false` and says that OpenRegister is missing, and the
  step stays open

### Requirement: Only an administrator SHALL use the setup endpoints (REQ-FTS-005)

`status`, `saveConfig` and `runAction` on `SetupController` SHALL carry
`#[AuthorizedAdminSetting(HumaniqAdmin::class)]`. An unknown action id SHALL answer 404, and a
dataset id no card offers SHALL be refused with 400 before it is stored.

Rows: `plt-setup-wizard` (humaniq matrix).

#### Scenario: An unknown action is refused
@e2e exclude covered by SetupControllerTest::testUnknownActionIs404
- **GIVEN** an administrator
- **WHEN** they post to `/api/setup/action/format-disk`
- **THEN** the reply is 404 with `success: false`

#### Scenario: An unknown dataset is not stored
@e2e exclude covered by SetupControllerTest::testAnUnknownDatasetIsRefusedRatherThanStored
- **GIVEN** an administrator
- **WHEN** they post `demo_dataset: other` to `/api/setup/config`
- **THEN** the reply is 400 and the stored pick is unchanged

### Requirement: Each example data card loads itself

The `demo-data` setup step MUST be a cards choice step with `loadAction: load-demo-data`. The setup wizard MUST NOT carry a separate run-action step that loads the picked dataset.

#### Scenario: The operator loads a dataset from its card

- GIVEN the setup wizard shows the example data cards
- WHEN the operator presses Load on a card
- THEN the wizard posts `{ "dataset": <card value> }` to `/api/setup/action/load-demo-data`
- AND the server loads that dataset
- AND the server records the dataset as the pick only after the load succeeds
- @e2e exclude the card and its spinner are CnSetupWizard UI, tested in nextcloud-vue; the posted body is covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: An unknown dataset is refused

- GIVEN a dataset id that no card offers
- WHEN it is posted to `/api/setup/action/load-demo-data`
- THEN the server answers 400 with `success: false`
- AND nothing is loaded or stored
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts

#### Scenario: A call without a body keeps working

- GIVEN a dataset was stored through `/api/setup/config`
- WHEN `/api/setup/action/load-demo-data` is called without a body
- THEN the stored dataset is loaded
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts

#### Scenario: A failed load leaves the step open

- GIVEN the load of the posted dataset fails
- WHEN the server answers
- THEN the answer carries `success: false`
- AND no pick or decision is stored
- @e2e exclude needs a load that fails on a live instance; covered by tests/Unit/Controller/SetupControllerTest.php

### Requirement: Setup status reports every manifest step

`GET /api/setup/status` MUST report a `done` state for every step id in `manifest.setup.steps`.

#### Scenario: The status ids match the manifest

- GIVEN the Humaniq manifest
- WHEN an administrator reads `/api/setup/status`
- THEN `steps` holds an entry for every manifest step id
- AND the retired load step is not reported
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts

### Requirement: The Store page SHALL install published configuration sets through OpenRegister (REQ-FTS-006)

The humaniq Marketplace page SHALL list the HR configuration sets published to the marketplace registries
the instance trusts, such as a leave policy or an onboarding flow, and SHALL install a chosen
set through OpenRegister's store. humaniq SHALL hold no store code of its own. With no registry
configured, the page SHALL list humaniq's own items only and make no network call.

Rows: `plt-template-store` (humaniq matrix). Board: `humaniq/HuInrichten`.

#### Scenario: An administrator installs a published leave policy
- **GIVEN** a trusted registry that publishes a leave policy set for humaniq
- **WHEN** an administrator opens the Marketplace page and installs it
- **THEN** OpenRegister records the set as installed and its leave types appear in humaniq

#### Scenario: No registry means no outside call
- **GIVEN** no marketplace registry configured
- **WHEN** an administrator opens the Marketplace page
- **THEN** the page lists humaniq's own items and the browser makes no request outside the instance

### Requirement: The setup wizard SHALL offer a Sjablonen step that installs chosen sets (REQ-FTS-007)

The setup wizard SHALL offer an optional Sjablonen step listing the same configuration sets as
the Marketplace page. Sets the administrator picks SHALL be installed through OpenRegister's store,
and the closing step SHALL name them. Skipping the step SHALL be recorded as an answer, and the
step SHALL never block access to the app.

Rows: `plt-template-store` (humaniq matrix). Board: `humaniq/HuInrichten`, step Sjablonen.

#### Scenario: Picked templates show in the summary
- **GIVEN** an administrator in the setup wizard
- **WHEN** they pick a leave policy and a review cycle on the Sjablonen step and continue
- **THEN** both sets are installed and the closing step names them

#### Scenario: A skipped step stays closed
- **GIVEN** an administrator who skipped the Sjablonen step
- **WHEN** the wizard reads the setup status again
- **THEN** the step reads done and the wizard does not reopen over the app

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

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

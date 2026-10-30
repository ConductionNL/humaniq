# first-time-setup

## ADDED Requirements

### Requirement: The Store page SHALL install published configuration sets through OpenRegister (REQ-FTS-006)

The humaniq Store page SHALL list the HR configuration sets published to the store registries
the instance trusts, such as a leave policy or an onboarding flow, and SHALL install a chosen
set through OpenRegister's store. humaniq SHALL hold no store code of its own. With no registry
configured, the page SHALL list humaniq's own items only and make no network call.

Rows: `plt-template-store` (humaniq matrix). Board: `humaniq/HuInrichten`.

#### Scenario: An administrator installs a published leave policy
- **GIVEN** a trusted registry that publishes a leave policy set for humaniq
- **WHEN** an administrator opens the Store page and installs it
- **THEN** OpenRegister records the set as installed and its leave types appear in humaniq

#### Scenario: No registry means no outside call
- **GIVEN** no store registry configured
- **WHEN** an administrator opens the Store page
- **THEN** the page lists humaniq's own items and the browser makes no request outside the instance

### Requirement: The setup wizard SHALL offer a Sjablonen step that installs chosen sets (REQ-FTS-007)

The setup wizard SHALL offer an optional Sjablonen step listing the same configuration sets as
the Store page. Sets the administrator picks SHALL be installed through OpenRegister's store,
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

# first-time-setup

## RENAMED Requirements

- FROM: `### Requirement: humaniq SHALL declare a three-step setup wizard that never gates the app (REQ-FTS-001)`
- TO: `### Requirement: humaniq SHALL declare a six-step setup wizard that never gates the app (REQ-FTS-001)`

## MODIFIED Requirements

### Requirement: humaniq SHALL declare a six-step setup wizard that never gates the app (REQ-FTS-001)

`src/manifest.json` SHALL declare a `setup` block, version 1, with exactly these steps in this
order, as drawn on board `humaniq/HuInrichten`: `administrations` (Administraties),
`working-mode` (Werkwijze), `language` (Taal), `demo-data` (Voorbeelddata, a cards choice,
options from the server's `datasets`), `templates` (Sjablonen, REQ-FTS-007) and `done` (Klaar,
summary with a health check). The setup block SHALL NOT declare a separate `welcome` step. No
step SHALL be required, so `GET /api/setup/status` (`appinfo/routes.php:17`) SHALL report
`completed: true` and the wizard SHALL never block access to the app. Each step SHALL read done
once the administrator has answered or skipped it, and the status SHALL report exactly the ids of
the manifest's steps.

Rows: `plt-setup-wizard` (humaniq matrix). Board: `humaniq/HuInrichten`.

#### Scenario: The wizard declares the six drawn steps
@e2e exclude manifest shape, covered by a unit test that reads src/manifest.json and compares the step ids and their order
- **GIVEN** `src/manifest.json`
- **WHEN** its `setup.steps` are read
- **THEN** the ids are `administrations`, `working-mode`, `language`, `demo-data`, `templates`
  and `done`, in that order, and no step carries `required: true`

#### Scenario: The status names every step
@e2e exclude endpoint shape, covered by SetupControllerTest
- **GIVEN** a fresh install on which no step was answered
- **WHEN** an administrator reads `/api/setup/status`
- **THEN** the response carries `completed: true`, all six step ids, each step except `done`
  reads not done, and `datasets` lists the cards to offer

#### Scenario: The wizard offers the example data step
@e2e exclude spec written after the fact; exercised by tests/e2e/spec-coverage/demo-data-setup-step.spec.ts, which does not yet carry this scenario's tag
- **GIVEN** a fresh install on which the example data question was never answered
- **WHEN** an administrator reads `/api/setup/status`
- **THEN** the `demo-data` step reads not done and `datasets` lists the cards to offer

#### Scenario: An answered step stays closed
@e2e exclude app-config state across requests; covered by SetupControllerTest
- **GIVEN** an administrator who answered or skipped a step earlier
- **WHEN** the wizard reads the status again
- **THEN** that step reads done and the wizard does not reopen over the app

## ADDED Requirements

### Requirement: The Administraties step SHALL create the administrations humaniq starts with (REQ-FTS-008)

The `administrations` step SHALL let the administrator enter one or more administrations, each
with a name, a loonheffingennummer and an optional KvK number. On continue, the wizard SHALL post
the list to `POST /api/setup/action/create-administrations`. The server SHALL create one
`hrAdministration` per entry through OpenRegister (REQ-MULTI-002) and an `AdministrationAccess`
row of role `hr` for the posting administrator, and SHALL make the first one the administrator's
active administration (REQ-MULTI-003). An entry whose loonheffingennummer matches an existing
`hrAdministration` SHALL be reused, never duplicated. An entry without a name or
loonheffingennummer SHALL be refused with 400 before anything is created. Skipping the step
SHALL be recorded as an answer and SHALL create nothing.

Rows: `plt-setup-wizard`, `plt-multi-administration` (humaniq matrix). Board: `humaniq/HuInrichten`,
step Administraties.

#### Scenario: Two administrations are created side by side
@e2e exclude object creation through OpenRegister; covered by SetupControllerTest
- **GIVEN** an administrator on the Administraties step
- **WHEN** they enter Gemeente Zuiddrecht and Werkbedrijf Drechtsteden BV, each with its own
  loonheffingennummer, and continue
- **THEN** two `hrAdministration` objects exist, the administrator has access to both, and the
  first is their active administration

#### Scenario: Running the step twice creates no duplicates
@e2e exclude covered by SetupControllerTest
- **GIVEN** an administration with loonheffingennummer 123456789L01 created by the wizard
- **WHEN** the administrator reopens the wizard and posts the same entry again
- **THEN** no second `hrAdministration` with that number is created

#### Scenario: An entry without a loonheffingennummer is refused
@e2e exclude covered by SetupControllerTest
- **GIVEN** an administrator on the Administraties step
- **WHEN** they post an entry with a name and no loonheffingennummer
- **THEN** the reply is 400 with `success: false` and no object is created

### Requirement: The Werkwijze step SHALL set the mode of the wizard's administrations (REQ-FTS-009)

The `working-mode` step SHALL offer three cards: Organisatie met personeel, Eenmanszaak and
Directeur-grootaandeelhouder zonder HR-structuur. The server SHALL store the pick as
`working_mode` and SHALL write it as `hrAdministration.mode` (`standard`,
`eenmanszaak_no_payroll` and `dga_single_person` respectively, REQ-SPM-001) on every
administration the Administraties step created or reused. An unknown value SHALL be refused with
400 before it is stored. Skipping the step SHALL leave every mode at `standard`.

Rows: `plt-setup-wizard` (humaniq matrix). Board: `humaniq/HuInrichten`, step Werkwijze.

#### Scenario: A DGA sets up humaniq for themselves
@e2e exclude covered by SetupControllerTest
- **GIVEN** one administration created on the Administraties step
- **WHEN** the administrator picks Directeur-grootaandeelhouder zonder HR-structuur
- **THEN** that administration's `mode` reads `dga_single_person` and the team-only menu
  surfaces hide (REQ-SPM-003)

#### Scenario: An unknown mode is not stored
@e2e exclude covered by SetupControllerTest
- **GIVEN** an administrator
- **WHEN** they post `working_mode: franchise` to `/api/setup/config`
- **THEN** the reply is 400 and no administration's mode changes

### Requirement: The Taal step SHALL set humaniq's fallback language (REQ-FTS-010)

The `language` step SHALL offer Nederlands and English and SHALL say that employees choose their
own language in their personal settings. The server SHALL store the pick as `default_language`
(`nl` or `en`). humaniq SHALL use it as the language of the documents and notifications it
generates for an employee who chose no language in Nextcloud; an employee's own Nextcloud
language SHALL always win. An unknown value SHALL be refused with 400. Skipping the step SHALL
store `nl`.

Rows: `plt-setup-wizard`, `plt-multilingual` (humaniq matrix). Board: `humaniq/HuInrichten`, step
Taal.

#### Scenario: An employee without a language gets the fallback
@e2e exclude language resolution in PHP; covered by a unit test of the language resolver
- **GIVEN** `default_language` is `en` and an employee who chose no language in Nextcloud
- **WHEN** humaniq sends that employee a notification
- **THEN** the notification is in English

#### Scenario: The employee's own language wins
@e2e exclude covered by a unit test of the language resolver
- **GIVEN** `default_language` is `en` and an employee whose Nextcloud language is Nederlands
- **WHEN** humaniq sends that employee a notification
- **THEN** the notification is in Dutch

### Requirement: The Klaar step SHALL recap every answer with a way back to change it (REQ-FTS-011)

The `done` step SHALL show one row per earlier step with the step's label, the answer, one line
of explanation and a Wijzigen link that opens that step. Below the rows it SHALL show the health
check result, naming the imported schema count, whether background jobs run and the loaded
payroll tables year. It SHALL say that every choice can be changed later and that the setup can
be reopened from the app menu. Inrichting afronden SHALL store `setup_completed_version`; Terug
SHALL open the previous step and Annuleren SHALL close the wizard without storing it.

Rows: `plt-setup-wizard` (humaniq matrix). Board: `humaniq/HuInrichten`, step Klaar.

#### Scenario: The administrator changes the language from the recap
@e2e tests/e2e/spec-coverage/setup-wizard-six-steps.spec.ts
- **GIVEN** the Klaar step after the administrator picked Nederlands on the Taal step
- **WHEN** they press Wijzigen on the Taal row, pick English and return to Klaar
- **THEN** the Taal row reads English

#### Scenario: A failed health check is shown as failed
@e2e exclude needs an instance with a broken import; covered by SetupControllerTest
- **GIVEN** a register import that failed
- **WHEN** the administrator reaches the Klaar step
- **THEN** the health check line names the failure and does not say the check passed

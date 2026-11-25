## 1. nextcloud-vue: generic wizard growth (ConductionNL/nextcloud-vue, its own PR)

- [x] 1.1 `CnSetupWizard` summary rows: render an optional per-step `summaryHint` line and a
      Wijzigen button that opens that step, behind an optional `summaryEditable: true` on the
      summary step. Verify: unit test for a row with and without hint, and that Wijzigen emits
      `step-change` to the right index.
- [x] 1.2 `CnSetupWizard` config-fields step: support a repeatable group (`repeatable: true` on an
      array-of-objects field) with add and remove row buttons, posting the list as one value.
      Verify: unit test that two rows post as a two-item array.
- [x] 1.3 Release nextcloud-vue and bump humaniq's dependency to that version.
      Done upstream as nextcloud-vue's `setup-wizard-recap-and-repeatable` (its own tests):
      `summaryHint`, `summaryEditable` with a Change button per recap row, and a repeatable
      group (`repeatable: true` on an array of objects) in a config-fields step, which posts to
      the step's `action`. humaniq's lock resolves @conduction/nextcloud-vue 2.193.1, which
      carries all three, and `npm run check:manifest` validates them against its schema.

## 2. Manifest (src/manifest.json)

- [x] 2.1 Replace the setup steps with `administrations`, `working-mode`, `language`,
      `demo-data`, `templates` and `done`, in that order; drop `welcome` and move its
      orientation sentence into the `administrations` body. Copy from the board, sentence case.
      Verify: `npm run check:manifest` exits 0.
      Open on a conflict: hydra gate 100 (`check_setup_demo_first.py`, ADR-111 rule 4) requires
      `steps[0]` to be `welcome` and `steps[1]` the example-data offer, so dropping `welcome`
      and opening with `administrations` fails the gate. Until that is settled the order is
      `welcome`, `demo-data`, `administrations`, `working-mode`, `language`, `done`, and
      `templates` waits on `configuration-store`.
      Decision (design D2, 2026-11-24): hydra ADR-111 rule 4 (gate 100) outranks the board, so the order is `welcome`, `demo-data`, `administrations`, `working-mode`, `language`, `templates`, `done`, and `welcome` keeps the orientation sentence; REQ-FTS-001 and the proposal now say so. The manifests already declare it; pinned by `SetupControllerTest::testTheWizardDeclaresItsSevenStepsInOrder`. `npm run check:manifest` exits 0.
- [x] 2.2 `administrations`: config-fields with a repeatable group (name, loonheffingennummer,
      KvK number), `runAction: create-administrations`.
      Done: the step posts its `administrations` group to `create-administrations` (the
      library key is `action`), is skippable, and sits after `demo-data` and before
      `working-mode`, which writes the mode on the administrations it created. Verified by
      `SetupControllerTest::testTheManifestDeclaresTheAdministrationsStep` on both manifests.
- [x] 2.3 `working-mode`: cards choice, `configKey: working_mode`, values `standard`,
      `eenmanszaak_no_payroll`, `dga_single_person` with the board's labels and hints.
      Done: declared after `demo-data` (ADR-111 rule 4 keeps the example data directly after
      `welcome`; gate-100 refuses anything between them). The setup options schema allows
      only `value` and `label`, so the hints are one sentence in the step body; the per-step
      recap hint is 2.5's `summaryHint`. Verified by
      `SetupControllerTest::testTheManifestDeclaresTheWorkingModeAndLanguageChoices`, which
      compares the option values with `SetupAdministrations::MODES`.
- [x] 2.4 `language`: choice, `configKey: default_language`, values `nl` and `en`.
      Done: a dropdown choice after `working-mode`, same test.
- [x] 2.5 `done`: `summaryEditable: true`, a `summaryHint` per step from the board, health check
      on. Keep `templates` exactly as `configuration-store` declares it.
      Done: `done` carries `summaryEditable: true` and `healthCheck: true`; every answered step
      (`demo-data`, `administrations`, `working-mode`, `language`, `templates`) carries a one-line
      `summaryHint`, `welcome` (info) none. The board's own hint lines are not in the repository,
      so each hint restates its step's body in one sentence; 5.2's live pass compares them with
      the board. `templates` is unchanged apart from its hint. Verified by
      `SetupControllerTest::testTheDoneStepRecapsEveryAnswerWithAHintAndAChangeButton` on both
      manifests.
- [x] 2.6 Add `nl` and `en` catalogue entries for every new string.
      Progress: the `working-mode` and `language` strings and the updated `welcome` body are in
      `l10n/` (`npm run check:l10n` passes); the `administrations`, `templates` and `done`
      strings follow with 2.1, 2.2 and 2.5.
      The `administrations` strings (body, "Add administration") and the `welcome` body naming
      the step are in `l10n/`; the `templates` and `done` strings follow with 2.1 and 2.5.
      The `templates` and `done` strings and the five recap hints of 2.5 are in `l10n/`
      (`npm run check:l10n` passes). Left open on 2.1: moving the orientation sentence into the
      `administrations` body adds a string once the step order is settled.
      Done: with `welcome` kept (2.1), no orientation string moves; every step string is in `l10n/` (`npm run check:l10n` passes).

## 3. Server (lib/Controller/SetupController.php)

- [x] 3.1 `status()`: report the six step ids, each done once answered or skipped (app-config
      keys `administrations_decided`, `working_mode`, `default_language`, existing demo and
      templates keys). Verify: unit tests for fresh, skipped and answered per step.
      Progress: `working-mode` and `language` are reported, done once `working_mode` or
      `default_language` holds an answer (`SetupControllerTest`). `administrations` and
      `templates` join when 2.1 declares them, because the status must report exactly the
      manifest's step ids (`testStatusReportsEveryStepTheManifestDeclares`).
      `administrations` is now reported, done once `administrations_decided` holds `created` or
      `skipped` (`testTheAdministrationsStepReadsDoneOnceCreatedOrSkipped`); `templates` joins
      with `configuration-store`.
      Done with `configuration-store` (#1235): `templates` is reported, done once a set was
      installed or "No templates" was picked (`SetupTemplatesTest`,
      `SetupControllerTest::testStatusReportsTheTemplatesStepNotAnswered` and
      `...DoneOnceAnswered`). `welcome` stays reported while 2.1's step order waits on gate 100.
- [x] 3.2 `runAction('create-administrations')`: validate every entry first (400 on a missing
      name or loonheffingennummer, nothing created), then create or reuse `hrAdministration` by
      loonheffingennummer through ObjectService, add an `AdministrationAccess` row of role `hr`
      for the admin, make the first the admin's active administration, and remember the ids.
      Verify: unit tests for create two, rerun without duplicates, refused entry.
- [x] 3.3 `saveConfig()`: refuse an unknown `working_mode` or `default_language` with 400 before
      storing; on a valid `working_mode`, write `mode` on the remembered administrations.
      Verify: unit tests for both refusals and for the mode write.
- [x] 3.4 The health check names the imported schema count, the background job state and the
      loaded payroll tables year, and reports a failure as failed. Verify: unit test with a
      failed import.

## 4. Language fallback

- [x] 4.1 A language resolver used by humaniq's notifications and generated documents: the
      employee's Nextcloud language, else `default_language`, else `nl`. Verify: unit tests for
      both REQ-FTS-010 scenarios.

## 5. Verification

- [x] 5.1 `tests/e2e/spec-coverage/setup-wizard-six-steps.spec.ts` for the Wijzigen scenario;
      every other scenario keeps its reason-bearing `@e2e exclude` (gate 19); `@spec` tags on
      changed methods (gate 16).
      Written, not run: `tests/e2e/spec-coverage/setup-wizard-six-steps.spec.ts`, ahead of the
      recap (1.1) and the six-step setup block (2.1 to 2.5) it walks; it fails until those land.
      Written, runs in nightly: the recap (1.1) and the steps (2.1 to 2.5) it walks have landed; `setup-wizard-six-steps.spec.ts` is anchored for the Wijzigen scenario (gate 19), every other scenario keeps its reason-bearing exclude.
- [ ] 5.2 One live pass of the wizard against board `humaniq/HuInrichten`, step by step, with a
      screenshot of the Klaar step next to the board.
      Pass made 2026-11-25 on a sandbox Nextcloud 34.0.6 (MariaDB 10.11, OpenRegister main
      7997381d, humaniq development 89e5e1bf, nextcloud-vue 2.204.0), reader language Dutch,
      next to the board (identity.conduction.nl, HuInrichten, step 6 of 6). Left open, because the
      Klaar step does not match the board yet:
      - Fixed here: the example-data cards (`DemoDataService::listChoices()`) read English in a
        Dutch wizard; their four strings are now in `l10n/` (`demoDataChoicesTranslated.test.mjs`).
      - No health-check line under the recap. REQ-FTS-011 and the board ("Controle geslaagd:
        register geïmporteerd met 54 schema's, achtergrondtaken draaien, loonpakket 2026
        geladen.") want one; `healthCheck: true` on `done` is accepted by the manifest schema but
        `CnSetupWizard` renders nothing for it, in 2.204.0 and in 2.225.1. Needs nextcloud-vue.
      - The recap's Wijzigen column is too narrow: it renders "Wi…", "Wij…", "Wijz…"; the board
        shows a full "Wijzigen" link per row. nextcloud-vue (`CnSetupWizard` summary rows).
        Built 2026-11-25: nextcloud-vue#2379 (merged) draws the health line from the setup status
        `health` block (`{ ok, checks: [{ label, ok }] }`) and keeps the Change button at full
        width. `SetupHealthCheck` now reports `ok` and one translated row per check (nl "register
        geïmporteerd met 54 schema's, achtergrondtaken draaien, loonpakket 2026 geladen"), and the
        Klaar step sets `submitLabel` "Finish setup" (nl "Inrichting afronden"). Still to do: raise
        `@conduction/nextcloud-vue` to the release that carries #2379, then the live re-check.
      - Dialog title "Humaniq instellen" (nextcloud-vue's "Set up {appName}") where the board says
        "Humaniq inrichten, stap 6 van 6" over the heading "Controleer en rond af"; the finish
        button reads "Afronden" where REQ-FTS-011 and the board say "Inrichting afronden".
      - Seven steps with Welkom first and the example data second, the board's six without
        Welkom: decided in design D2 (hydra ADR-111 rule 4 outranks the board), not a defect.
      Re-check 2026-11-25 on a sandbox Nextcloud 34.0.6 (MariaDB, OpenRegister main 0b78bd2c,
      humaniq #1353 head 27002ac0, nextcloud-vue 2.232.1), reader language Dutch, every step walked
      with an answer picked (screenshot `docs/static/img/screenshots/setup-wizard-klaar-nl.png`):
      - Now matches the board: the health line "Controle geslaagd: register geïmporteerd met 109
        schema's, achtergrondtaken draaien, loonpakket 2026 geladen." under the recap (the board's
        54 is the schema count of its day), a full "Wijzigen" per row, and the finish button
        "Inrichting afronden" between Annuleren and Terug.
      - Still off on 2.232.1: the recap rows are not columns. Every row sizes its own answer cell,
        so the answers do not line up and a short cell breaks inside a word ("Niet ingestel d");
        the board draws label, answer, explanation and Wijzigen as aligned columns. Built in
        nextcloud-vue (branch `cloud/b-humaniq-dep-recap-columns`: one grid through subgrid, in
        the board's order; a narrow dialog stacks the answer and the explanation under the step)
        and checked live against a local build of it: `setup-wizard-klaar-nl-columns.png` (1280
        wide) and `setup-wizard-klaar-nl-phone.png` (390 wide) in docs/static/img/screenshots.
        Left: raise `@conduction/nextcloud-vue` to the release that carries it, then tick 5.2.
      - The Wijzigen spec (5.1) now passes live (1 passed; mutation-checked: a wrong expected
        language fails on the recap row). Three spec fixes: `send: 'always'` on the admin request
        context (Nextcloud's OCS 401 carries no Basic challenge, so the credentials were never
        sent); steps are found by the rail's `aria-current="step"` + `data-step-id`, since step
        titles are not headings (it used to press Next on Klaar, i.e. "Inrichting afronden",
        and finish the wizard); the recap row is read from the step body's list, not the rail;
        after each Next the walk waits for the rail to move on (a second click before the next
        step showed skipped it). 3 runs in a row green, plus one from an answered language.

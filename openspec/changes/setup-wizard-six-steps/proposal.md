# Setup wizard in six steps, as drawn

## Why

Board `humaniq/HuInrichten` (design-system `main`) draws the humaniq setup wizard in six steps:
Administraties, Werkwijze, Taal, Voorbeelddata, Sjablonen and Klaar. The spec
`first-time-setup` (REQ-FTS-001) still declares three: `welcome`, `demo-data` and `done`. The
boards are the UI canon (Ruben, 9 Oct), so the spec moves to the board.

Change `configuration-store` (humaniq #724, open) already adds the Sjablonen step
(REQ-FTS-007). This change builds on it and does not repeat it.

## What changes

- REQ-FTS-001 is modified: the wizard declares six steps in the board's order, and the separate
  `welcome` step goes, because the board draws none.
- The Administraties step creates the administrations the app starts with (REQ-FTS-008).
- The Werkwijze step sets the mode of those administrations through the existing
  `hrAdministration.mode` (single-person-modes, REQ-SPM-001) (REQ-FTS-009).
- The Taal step stores the language humaniq falls back to for an employee who chose none in
  Nextcloud (REQ-FTS-010).
- The Klaar step recaps every answer with a line of explanation and a Wijzigen link back to its
  step, runs the health check and closes with Inrichting afronden (REQ-FTS-011).

Every step acts on its answer: no step asks a question the app ignores.

## Impact

- `src/manifest.json` setup block, `lib/Controller/SetupController.php`.
- Reuses `hrAdministration` and `AdministrationAccess` (multi-administratie, REQ-MULTI-002) and
  `hrAdministration.mode` (single-person-modes). No new schema; objects live in OpenRegister
  (ADR-022).
- nextcloud-vue `CnSetupWizard`: the summary rows grow a hint line and a Wijzigen link, and the
  config-fields step needs a repeatable group for the administration list. Both are generic and
  belong in nextcloud-vue, not in humaniq.
- Depends on `configuration-store` (#724) for the `templates` step.

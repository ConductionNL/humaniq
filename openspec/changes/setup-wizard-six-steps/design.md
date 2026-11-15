# Design: setup wizard in six steps

## Source

Board `humaniq/HuInrichten` draws step 6 of 6, "Controleer en rond af". Its step rail lists
Administraties, Werkwijze, Taal, Voorbeelddata, Sjablonen and Klaar. The recap shows one row per
step: a label, the answer, one line of explanation and a Wijzigen link. Below the rows sits the
health check line ("Controle geslaagd: register geïmporteerd met 54 schema's, achtergrondtaken
draaien, loonpakket 2026 geladen."), then Annuleren, Terug and Inrichting afronden.

## Decisions

### D1. The six steps and their manifest ids

| Board step | id | type | Stores | Acts on |
|---|---|---|---|---|
| Administraties | `administrations` | config-fields, repeatable group | action `create-administrations` | creates `hrAdministration` + `AdministrationAccess` |
| Werkwijze | `working-mode` | choice, cards | `working_mode` | sets `hrAdministration.mode` on the wizard's administrations |
| Taal | `language` | choice | `default_language` | fallback language of humaniq's documents and notifications |
| Voorbeelddata | `demo-data` | choice, cards (unchanged) | `demo_dataset` | REQ-FTS-002 to 004 |
| Sjablonen | `templates` | choice, cards | from `configuration-store` | REQ-FTS-007 |
| Klaar | `done` | summary, health check | `setup_completed_version` | REQ-FTS-011 |

No step is required, so `GET /api/setup/status` keeps `completed: true` and the wizard never
gates the app.

### D2. No welcome step

The board draws no welcome step. The orientation sentence of the old `welcome` step moves into
the body of the Administraties step.

### D3. Werkwijze values map to the existing mode enum

| Board card | `hrAdministration.mode` |
|---|---|
| Organisatie met personeel | `standard` |
| Eenmanszaak | `eenmanszaak_no_payroll` |
| Directeur-grootaandeelhouder zonder HR-structuur | `dga_single_person` |

Mode stays a field of the administration (REQ-SPM-001), so it remains changeable per
administration under Configuratie › Administraties after setup.

### D4. Writes go through the setup contract

`CnSetupWizard` never writes OpenRegister objects from the browser. The administration list is
posted to `POST /api/setup/action/create-administrations`, and `SetupController` creates the
objects through OpenRegister's ObjectService, as the admin, with an `AdministrationAccess` row of
role `hr` for that admin.

### D5. Generic widget growth lives in nextcloud-vue

The recap's hint line and Wijzigen link, and the repeatable group in a config-fields step, are
generic wizard features. They go into `CnSetupWizard` (nextcloud-vue) behind optional manifest
keys, so other apps' wizards get them too and keep working without them.

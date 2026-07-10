# source-licensing Specification

## Purpose
TBD - created by archiving change add-license-file-and-spdx-headers. Update Purpose after archive.
## Requirements
### Requirement: The repository has a LICENSE file matching its declared licence

The repository MUST contain a root `LICENSE` file with the verbatim text of the licence it
is distributed under (EUPL-1.2, matching `composer.json` `"license": "EUPL-1.2"` and the
`lib/` SPDX headers). The app MUST NOT ship without a `LICENSE` file.

#### Scenario: A LICENSE file is present and is EUPL-1.2

- **WHEN** the repository root is inspected
- **THEN** a `LICENSE` file MUST exist containing the EUPL-1.2 text
- **AND** it MUST agree with `composer.json` and the `lib/` SPDX headers (all EUPL-1.2)

@e2e exclude presence-of-file/REUSE check, not a runtime UI flow.

### Requirement: Every lib PHP file carries the EUPL-1.2 SPDX header

Every PHP file under `lib/` MUST carry the EUPL-1.2 licence/copyright header in its top
docblock (`@copyright` Conduction B.V., `@license EUPL-1.2`, `SPDX-License-Identifier:
EUPL-1.2`, `SPDX-FileCopyrightText`). No `lib/` PHP file may ship without it — all 18 files
MUST be headered (17 are currently missing it).

#### Scenario: All lib files declare their licence

- **WHEN** the `spdx-headers` gate scans `lib/`
- **THEN** the count of `lib/**/*.php` files with `SPDX-License-Identifier` MUST equal the total (18/18)
- **AND** each declared value MUST be `EUPL-1.2`, matching `composer.json` and the new `LICENSE` file

@e2e exclude static REUSE/gate check, not a runtime UI flow.


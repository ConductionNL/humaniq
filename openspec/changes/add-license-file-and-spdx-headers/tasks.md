# Tasks: add-license-file-and-spdx-headers

- [ ] 1.1 Add a root `LICENSE` file containing the verbatim EUPL-1.2 licence text (matching `composer.json` and the existing `lib/` SPDX header). Copy the canonical EUPL-1.2 text (e.g. from a sibling fleet app's `LICENSE`).
  - **spec_ref**: `specs/source-licensing/spec.md#requirement-the-repository-has-a-license-file-matching-its-declared-licence`
  - **acceptance_criteria**:
    - `LICENSE` exists at repo root, EUPL-1.2, agrees with composer.json + lib headers
- [ ] 1.2 Add the EUPL-1.2 licence/copyright header docblock (`@copyright` Conduction B.V., `@license EUPL-1.2`, `SPDX-License-Identifier: EUPL-1.2`, `SPDX-FileCopyrightText`) to the 17 `lib/**/*.php` files currently missing it (Edit/Write tools, preserve existing docblocks; no logic change). Match the format of the one already-headered file.
  - **spec_ref**: `specs/source-licensing/spec.md#requirement-every-lib-php-file-carries-the-eupl-12-spdx-header`
  - **acceptance_criteria**:
    - All 18 `lib/**/*.php` contain `@license EUPL-1.2` + `@copyright` + `SPDX-License-Identifier: EUPL-1.2`; header-only diff
- [ ] 1.3 Verify: `grep -rL 'SPDX-License-Identifier' lib --include='*.php'` returns nothing; spdx-headers gate green (18/18); `openspec validate add-license-file-and-spdx-headers --strict` clean.
  - **spec_ref**: `specs/source-licensing/spec.md#requirement-every-lib-php-file-carries-the-eupl-12-spdx-header`
  - **acceptance_criteria**:
    - Zero lib PHP files missing the SPDX header; LICENSE present

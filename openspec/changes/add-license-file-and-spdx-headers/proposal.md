---
kind: config
---

# Proposal: add-license-file-and-spdx-headers

## Why

hrmq's licence provenance is incomplete and inconsistent — the standard readiness gap for a
young app, and the clearest audit finding for it (its substantive HR capabilities —
employee master, employee self-service, payroll compliance — already exist as proposals on
the `spec/employee-master`, `spec/employee-self-service-mkb`, and
`feature/payroll-compliance-engine` branches, so this audit does not re-propose them):

- There is **no `LICENSE` file** at the repository root at all — a REUSE/compliance and
  App-Store defect.
- Of 18 `lib/**/*.php` files, only **1** carries an `SPDX-License-Identifier` header (the
  other 17 have none), so the `spdx-headers` quality gate fails.
- The canonical licence is **EUPL-1.2** — `composer.json` declares `"license": "EUPL-1.2"`
  and the single headered file declares `SPDX-License-Identifier: EUPL-1.2` — but
  `appinfo/info.xml` declares `<licence>agpl</licence>` (the App-Store workaround, since the
  app targets `min-version="28"`, below the NC 31 baseline where `EUPL-1.2` became a valid
  `<licence>` xsd value).

## What Changes

- Add a root `LICENSE` file containing the verbatim EUPL-1.2 text (matching `composer.json`
  and the existing SPDX header).
- Add the EUPL-1.2 licence/copyright header docblock (`@copyright` Conduction B.V.,
  `@license EUPL-1.2`, `SPDX-License-Identifier: EUPL-1.2`, `SPDX-FileCopyrightText`) to the
  17 `lib/**/*.php` files currently missing it (bringing all 18 to headered). No code logic
  change.
- Leave `appinfo/info.xml <licence>agpl</licence>` as-is (the store-compatibility workaround
  at `min-version="28"`); the source-of-truth licence is `composer.json` + the new `LICENSE`
  file + these headers (all EUPL-1.2). A follow-up may set the manifest to `EUPL-1.2` once
  the NC baseline moves to ≥ 31.

## Impact

- Affected: new root `LICENSE` file; 17 `lib/**/*.php` header docblocks. No behavioural change.
- Establishes complete, consistent EUPL-1.2 licence provenance and greens the `spdx-headers`
  gate.

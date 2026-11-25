# Tasks: the hours leaf loads on a consuming page

## 1. The bundle (REQ-HLD-001)

- [x] 1.1 Add a `leaves` entry to `webpack.config.js` building
      `js/humaniq-leaves.js` from a new `src/leaves.js`, copying planninq's
      entry and its comment.
- [x] 1.2 Write `src/leaves.js` to import only the leaf registration and its
      widget: no router, no store, no app shell.
- [x] 1.3 Add a build test asserting `js/humaniq-leaves.js` exists after a
      production build and stays under a stated size ceiling.
      Done: `tests/js/leavesBundleBuild.test.mjs` builds only the `leaves` entry in production
      mode into a temporary directory (`tests/js/support/buildLeavesBundle.cjs`), asserts the
      `<app id>-leaves.js` name, a 512 KB ceiling (232 KB alone, 320 KB in the full build, main
      16.6 MB) and no router, store, manifest or app shell among its modules.
- [x] 1.4 Watch the test fail on the current tree before adding the entry.
      Done: with the entry removed it fails "webpack.config.js declares no leaves entry"; renamed
      to `-leaf.js` it fails "expected humaniq-leaves.js, the build wrote humaniq-leaf.js".

## 2. Both halves, always (REQ-HLD-002)

- [x] 2.1 Extend `scripts/check-integration-parity.js`, or add a unit test
      beside it, so every leaf registered server-side must have a client
      registration reachable from the leaves bundle.
- [x] 2.2 Mutation-check it: register a second leaf server-side only, confirm
      the test names that leaf id, then remove it.
      Done: a third pass in `scripts/check-integration-parity.js` follows relative imports from
      `src/leaves.js`; `tests/js/integrationParityLeavesBundle.test.mjs` covers the repo and four
      fixture trees. Mutation-checked on the real tree with a server-only `humaniq-second` and with
      `registerHoursLeaf` dropped from `src/leaves.js`; both fail naming the leaf.

## 3. The host contract (REQ-HLD-003)

- [x] 3.1 Document the three host props the leaf needs in
      `docs/features/`, with the OpenRegister mount contract it relies on.
- [x] 3.2 Render a named unresolvable-host state in `CnHoursWidget.vue` when
      register, schema or object id is missing, never a total of zero.
- [x] 3.3 Add Dutch and English strings for that state.
- [x] 3.4 Unit test the complete host and each incomplete one.
      Done: `src/integrations/hoursHost.mjs` holds the rule; `CnHoursWidget.vue` shows
      `hq-hours-no-host` instead of figures, controls and the booking dialog;
      `tests/js/hoursHost.test.mjs`; doc in `docs/features/hours-leaf.md`.

## 4. Demo data (REQ-HLD-004)

- [x] 4.1 Replace the placeholder `domainObjectType` and `domainObjectRef` in
      `lib/Settings/humaniq_mock_register.json` with a real `register:schema`
      pair and the uuid of a seeded object, with non-zero hours.
      Done: the three seeded rows are booked on seeded `humaniq:EmployeeRelationsCase` cases
      (2.0 and 1.5 hours on the first, 1.0 on the second), referenced with the
      `@ref:EmployeeRelationsCase:<slug>` token the importer resolves to the case's uuid.
- [x] 4.2 Unit test that no seeded `TimeEntry` carries a placeholder
      reference.
      Done: `tests/Unit/Settings/DemoTimeEntryHostTest.php`; seen failing on the old rows.

## 5. The journey (REQ-HLD-005)

- [x] 5.1 Add `tests/e2e/hours-leaf-on-a-host-page.spec.ts`: mount the leaf on
      a seeded host, read the total, book an hour, read the total again.
- [x] 5.2 Assert the new entry carries the host's `domainObjectType` and
      `domainObjectRef`.
- [x] 5.3 Mutation-check: disable the booking call and confirm the spec fails
      rather than passing on the seeded total.
      Done 2026-11-25 on a sandbox Nextcloud 34.0.6 (MariaDB 10.11, OpenRegister main 7997381d, humaniq development 89e5e1bf, dossiq development 8d9de5d9 with `occ dossiq:demo:seed`): with `bookHours()` returning a faked success
      instead of posting (served bundle only, rebuilt, not committed) the spec fails on
      "the week total must grow by the hour booked" (expected 3, received 2); with the
      call restored it passes again.

## 6. Verification

- [x] 6.1 Run PHPUnit in the container and read the exit code, not the
      summary line.
      Done 2026-11-22 on development 58e3582f: `./vendor/bin/phpunit` exits 0
      (4,656 tests, 1 skipped); the hours and leaf tests alone (`--filter
      'Hours|Leaf|Leaves'`) are 269 tests, all passing.
- [x] 6.2 Run vitest and the Playwright spec, and count the tests that ran.
      Done 2026-11-25 on the same sandbox. The JS unit suite here is node:test, not vitest
      (`npm run test:js`): 252 tests, 250 pass, 2 fail, the same two as development
      (`every schema-bound index page allows the export`, `integrationsPage.test.mjs`); the
      twelve hours and leaf files alone are 70 tests, all passing. Playwright
      `tests/e2e/hours-leaf-on-a-host-page.spec.ts`: 1 test ran, 1 passed, after three spec
      fixes the live run showed: the API context now sends its credentials always (OCS answered
      401 without a challenge, so the journey skipped as if dossiq were absent), the host is a
      case whose type places the hours card (dossiq's Overview tab shows it only when the type's
      `overviewCards` is empty or lists it, and a special-categories type asks the reader's
      purpose first), and the page is opened under `/index.php` (the CI server is `php -S`).
- [x] 6.3 Run `npx openspec validate hours-leaf-loads-on-a-consuming-page --strict`.
      Done 2026-11-21: valid, after dropping the `status: proposed` front matter above the
      delta spec's first section, which strict mode reported as text archive does not carry.
- [x] 6.4 Run `composer check:strict`.
      Measured 2026-11-22 on development 58e3582f, left open: lint, phpcs (warnings
      only), phpstan and PHPUnit pass; phpmd exits 2 on findings outside the baseline
      in files this change did not touch (`Application.php` length, `FilingDates`
      short names, static access in the DSR commands), and psalm does not start on
      PHP 8.3.6 (it needs 8.3.16 or later). Tick once both run green.
      Done 2026-11-25 on PHP 8.4.28 (PR #1327): `composer check:strict` exits 0,
      ALL CHECKS PASSED (lint, phpcs, phpmd, psalm 7, phpstan, PHPUnit 4,855 tests,
      1 skipped). phpmd and psalm inherited findings baselined; the PHPUnit red was
      a PHP 8.4 rounding drift in the personnel budget, fixed.
- [x] 6.5 On the dev instance, open a host detail page in another app and
      confirm the leaf renders. Confirm `js/humaniq-leaves.js` is requested by
      that page.
      Done 2026-11-25 on the same sandbox: a dossiq Omgevingsvergunning case page requests
      `apps/humaniq/js/humaniq-leaves.js` and renders the leaf on its Overview tab
      (`cn-leaf-mount-host` > `hq-hours-widget` with the week line, form, timer and source
      note). On the seeded Wmo-melding case the bundle loads too but the card is not placed,
      by that case type's `overviewCards`, as dossiq intends.

## 7. Counterparts, raised elsewhere

- [x] 7.1 Raise the dossiq change: mount `humaniq-hours` on the case detail
      page and retire the `case-kpis-hours` stats block and the `log-hours`
      header action, which hard-code humaniq's register slug and schema name.
      Done in dossiq already: change `hours-onto-humaniq-leaf` (archived
      2026-09-18, spec `case-hours-via-humaniq-leaf` REQ-HRS-001). On dossiq
      development fc6d4f97 `case-kpis-hours` in `src/manifest.json` is
      `{"type": "integration", "integrationId": "humaniq-hours"}`, the
      `log-hours` action is gone, and no part of the manifest names the
      humaniq register. 7.2 waits on the live check of 6.5.
- [x] 7.2 Tell the owners of `time-entry-domain-object-ref` and shillinq's
      `hours-to-humaniq` that the leaf now renders, because both were written
      against a leaf nobody could see.
      Done 2026-11-25: told in the lanes' shared log (builders-idle.md on the pipeline state
      branch), naming the live run of 6.5 and the passing journey spec.

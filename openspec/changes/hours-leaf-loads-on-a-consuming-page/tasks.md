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

- [ ] 5.1 Add `tests/e2e/hours-leaf-on-a-host-page.spec.ts`: mount the leaf on
      a seeded host, read the total, book an hour, read the total again.
- [ ] 5.2 Assert the new entry carries the host's `domainObjectType` and
      `domainObjectRef`.
- [ ] 5.3 Mutation-check: disable the booking call and confirm the spec fails
      rather than passing on the seeded total.

## 6. Verification

- [ ] 6.1 Run PHPUnit in the container and read the exit code, not the
      summary line.
- [ ] 6.2 Run vitest and the Playwright spec, and count the tests that ran.
- [ ] 6.3 Run `npx openspec validate hours-leaf-loads-on-a-consuming-page --strict`.
- [ ] 6.4 Run `composer check:strict`.
- [ ] 6.5 On the dev instance, open a host detail page in another app and
      confirm the leaf renders. Confirm `js/humaniq-leaves.js` is requested by
      that page.

## 7. Counterparts, raised elsewhere

- [ ] 7.1 Raise the dossiq change: mount `humaniq-hours` on the case detail
      page and retire the `case-kpis-hours` stats block and the `log-hours`
      header action, which hard-code humaniq's register slug and schema name.
- [ ] 7.2 Tell the owners of `time-entry-domain-object-ref` and shillinq's
      `hours-to-humaniq` that the leaf now renders, because both were written
      against a leaf nobody could see.

# Tasks — ncvue-beta212-live-updates

## 1. Dependency bump

- [x] 1.1 Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212` and reinstall

## 2. Wire subscriptions

- [x] 2.1 Audit `src/` for `createObjectStore` / `useObjectStore` consumers — none exist; all list/detail pages are library-rendered manifest pages and `ProformaPayslip.vue` is a stateless calculator. Skips documented in the proposal.

## 3. Lint tooling repair (pre-existing)

- [x] 3.1 Add `.eslintrc.js` (`@nextcloud`) — `npm run lint` previously failed with "no configuration found"
- [x] 3.2 Add `stylelint.config.js` (`@nextcloud/stylelint-config`) — same failure mode
- [x] 3.3 Apply the two `vue/max-attributes-per-line` auto-fixes in `ProformaPayslip.vue`

## 4. Verify

- [x] 4.1 `npm run lint` clean
- [x] 4.2 `npm run stylelint` clean
- [x] 4.3 `npm run check:manifest` PASS (schema 2.19.0, 0 errors)
- [x] 4.4 `npm run build` green (no JS unit-test lane in this repo)

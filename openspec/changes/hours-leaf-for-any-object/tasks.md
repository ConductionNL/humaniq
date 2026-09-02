# Tasks

- [x] Add `src/integrations/CnHoursWidget.vue` — total, recent bookings, log-hours and timer actions; a dash rather than a zero when the read fails.
- [x] Add `src/integrations/registerHoursLeaf.js` — the `humaniq-hours` descriptor with the mount/unmount DOM hand-off, and a load-order-safe registry stub.
- [x] Add `lib/Listener/RegisterHoursLeafListener.php` — the server half, behind a `class_exists` check so an install without OpenRegister skips it.
- [x] Wire both halves in (`src/main.js`, `lib/AppInfo/Application.php`).
- [x] Add `scripts/check-integration-parity.{sh,js}` — gate-24's entry point, correlating leaf ids and their bound fields across both halves. Proven to fail against planted drift.
- [x] Add the eight l10n keys with Dutch.
- [ ] Move dossiq's `case-kpis-hours` onto this leaf, retiring the last cross-app register query on the case detail page.
- [ ] Add an e2e journey covering log-hours and the timer against a seeded host object.

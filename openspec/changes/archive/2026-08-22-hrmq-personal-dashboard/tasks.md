# Tasks — hrmq personal dashboard

Ordering is load-bearing: V (verify) gates the widget configs; this change lands AFTER
`hrmq-hours-process-redesign` (widgets bind to `TimeEntry` and `Timesheet.entryCount`) and
BEFORE `hrmq-i18n-locale-completeness` (labels stay Dutch literals here).

## V. Verify the design's data-binding assumptions (BLOCKING — before finalizing widget configs)

- [x] V1 Operator-filter proof through the SHIPPING path: place a temporary `stat` widget with
      `source: { schema: "TimeEntry", metric: "sum", field: "hours",
      filter: { "userId": "@me", "startedAt": { "gte": "@monthStart" } } }` on a dev dashboard
      page and assert the rendered value equals the sum of this month's seeded Jansen entries.
      **Must-fail control**: change `gte` to a nonsense operator (or `@monthStart` to a future
      date) and assert the value changes/empties — a probe that cannot fail proves nothing.
      Probe the rendered widget, not a hand-built curl (a probe must reach its subject the way
      shipping code does).
      **PASS (2026-08-22, localhost:8080, rendered `stat` on a temporary `/verification-probe`
      dashboard page, since removed).** Wire:
      `…/aggregations/hrmq/<TimeEntry>/value?metric=sum&field=hours&filter[userId]=admin&filter[startedAt][gte]=2026-08-01`
      → rendered **8.00** (the one current-month booking). Same widget with the `gte` clause
      removed → **168.00**, so the clause demonstrably narrows. **Must-fail control**
      (`gte: "@today+300d"` → `…[gte]=2027-06-18`) → rendered **—**. `@monthStart` and `@me`
      both resolved on the wire.
      **Finding (does not change this change, flagged to the orchestrator):** the same probe in
      its SHIPPING spelling (`schema: "TimeEntry"`) rendered **0.00**. The aggregation endpoint
      resolves a schema slug GLOBALLY (`SchemaMapper::find`, `LOWER(slug)` across every
      register; the `{register}` path segment is not used to disambiguate), and on this shared
      instance `TimeEntry` resolves to planix's #161 rather than hrmq's #9466 — and `Expense` to
      pipelinq's #507 rather than hrmq's #5026, i.e. widget 4 counts another app's rows. The
      probe therefore pinned the operator-filter question with the hrmq schema id so the two
      questions stayed separable. No manifest spelling can fix it (a slug is the only portable
      form); the fix belongs in OpenRegister or in `occ openregister:schemas:dedup` on the dev
      box. Single-app instances and CI are unaffected. Recorded in design.md Risks.
- [x] V2 `@currentFiscalYear` (string `"2026"`) vs `LeaveBalance.year` (integer) through the
      object-table's `source.filter`: assert the Jansen balance row renders with
      `year: "@currentFiscalYear"`, and (control) that a literal `"year": 1999` renders the
      empty state. If the string/integer comparison fails, apply the design fallback: drop the
      year filter, add `order: { year: "desc" }`, and update design.md D3 + the spec delta.
      **PASS (2026-08-22, same probe page).** Wire:
      `…/objects/hrmq/LeaveBalance?_limit=25&_order[leaveType]=asc&year=2026` → the seeded 2026
      balances rendered (2 rows visible + "+2 more"). **Must-fail control** (`"year": 1999`,
      identical widget) → the empty state. The string year matches the integer column, so **the
      documented fallback was NOT needed and was NOT applied** — the widget keeps its year
      filter. Caveat recorded in design.md: `@currentFiscalYear` is a DEPRECATED sentinel
      (replacement `@config.fiscalYear`, removal 2026-10-01) — it validates today via the
      schema's deprecated-during-window overlay, but this adds a new use ~6 weeks before
      removal. Flagged.
- [x] V3 Confirm `mergeMenuItems` fills the group route from the fragment: with
      `personal-dashboard.json` declaring `{ "id": "MijnHrGroup", "route": "MijnHr" }`, the
      rendered "Mijn HR" title is a router link to `/mijn` (inspect
      `[data-cn-route="MijnHr"]` / click it) AND the chevron still expands the nine children.
      Control: remove the fragment's `route` key and confirm the title click only toggles again
      (route-less-group branch, `CnAppNav.vue:1266`).
      **PASS (2026-08-22).** With the fragment's `route` key present, the rendered entry is
      `<li data-testid="cn-nav-entry-MijnHrGroup" data-cn-route="MijnHr">` whose title is
      `<a href="/apps/hrmq/mijn">`, its nine children render beneath it, and clicking the title
      navigated to `/apps/hrmq/mijn` (`[data-page-id="MijnHr"]`, six widget wrappers).
      **Must-fail control**: the same fragment rebuilt with the `route` key removed rendered
      `href="#"`, no `data-cn-route` attribute, and clicking the title left the URL on
      `/apps/hrmq/timesheets` — no navigation. In-nav control in the SAME render: the route-less
      `EmployeesGroup` ("Personeel") also renders `href="#"`.

## 1. Manifest fragment + base route normalization

- [x] 1.1 New `src/manifest.d/personal-dashboard.json`: the `MijnHr` page (`type: "dashboard"`,
      route `/mijn`, title "Mijn HR", Dutch description) with EXACTLY the six widgets and the
      layout table from design.md D3 — ids, filters (including the optional
      `@workspace.activeAdministrationId?` token on every widget), `format`/`caption`/
      `emptyText` values, `route`/`rowRoute` click-throughs, and a page-level `_note` naming
      this change and the ADR-097 D3 exemption it implements. Plus the `menu` block
      re-declaring `{ "id": "MijnHrGroup", "route": "MijnHr" }` and nothing else.
      Three spellings differ from D3's table and are documented in design.md's D3 addendum and
      in the page `_note`, each measured in the browser rather than inferred: `stat`
      click-through uses the route OBJECT form `{ "name": "…" }` (a bare string is read as a
      PATH by `<router-link :to>`); the three `object-table` widgets use the FLAT content
      contract (`register`/`schema`/`filter`/`sort{field,dir}`/`limit`/`columns`/`rowRoute`/
      `viewAllRoute`/`emptyText`) because `object-table` canonicalises to `table` →
      `CnObjectListWidget` on a dashboard page, which reads no `source` key and fired ZERO
      fetches with the nested shape; and all three carry `allowCreate: false`, that widget
      rendering a create affordance by default.
- [x] 1.2 Grep-check the fragment for `deepLinks`/`runtime`/`dependencies` keys — must find
      none (`buildManifest()` silently drops them). `grep -nE '"(deepLinks|runtime|dependencies)"'`
      → no matches (exit 1).
- [x] 1.3 `src/manifest.json`: change `MijnGebruikelijkLoon.route` to
      `/mijn/gebruikelijk-loon` (base-manifest page — the one edit that cannot live in a
      fragment; design.md D2). No other base change.
- [x] 1.4 `src/main.js`: add
      `routes.push({ path: '/mijn-hr/gebruikelijk-loon', redirect: '/mijn/gebruikelijk-loon' })`
      next to the `/vehicles/:id` precedent (before the catch-all), with a one-line comment
      naming this change.
- [x] 1.5 `node tests/validate-manifest.js` passes and prints the page count (113 effective
      pages: 112 + `MijnHr`); Ajv PASS, 0 errors. `npm run check:manifest` is that same script.
      `node scripts/check-manifest-sentinels.mjs` PASS; `node tests/validate-widget-keys.js`
      byte-identical to `tests/fixtures/manifest-baseline/validate-widget-keys.baseline.txt`
      (same 9 keys, same resolution summary, same exit=1 as the recorded baseline).
      `tests/verify-manifest-parity.js` re-baselined — see the note under 5.1.
- [x] 1.6 Routable AND reachable in the running app: click the "Mijn HR" group title → lands on
      `/mijn` with six widgets; every existing child entry still navigates; open
      `/mijn-hr/gebruikelijk-loon` by URL → redirected to `/mijn/gebruikelijk-loon` and the
      page renders (never assert from the manifest alone). Verified live 2026-08-22: title click
      → `/apps/hrmq/mijn`, `[data-page-id="MijnHr"]`, 6 `.cn-widget-wrapper`s; all six widgets
      fired correctly-scoped requests (`filter[userId]=admin` / `filter[managerUserId]=admin` on
      the stats, `userId=admin` on the three lists); the three stat tiles rendered as
      `<a href="/apps/hrmq/mijn/uren">`, `…/mijn/declaraties`, `…/timesheets/team-approval`.
      The `@workspace.activeAdministrationId?` token was DROPPED on every widget (never sent as
      a literal) — `CnDashboardPage`'s workspace context does not publish that key, the
      documented optional-token degradation. The redirect path is covered by the e2e journey
      (4.1e); the SPA's own catch-all makes a manual browser check of it indistinguishable from
      a fallback, which is why it is pinned by a test rather than by an eyeball.

## 2. LeaveBalance userId (schema + job + tests)

- [x] 2.1 `lib/Settings/register.d/hr-leave.json`: add `userId` (string, nullable) to
      `LeaveBalance` per the REQ-MHS-002 convention — user-oriented description, rationale
      (never a `$ref`; mirrors `approvedBy`) in `x-notes`; bump LeaveBalance 0.2.0 → 0.3.0 and
      the register `info.version` in `lib/Settings/hrmq_register.json` (0.16.0 → 0.17.0, both
      occurrences). `required` unchanged.
- [x] 2.2 `lib/BackgroundJob/LeaveAccrualJob.php`: stamp `userId` from the resolved Employee's
      `nextcloudUserId` on BOTH the create path and the update path; null when the employee has
      no linked account (fail-closed). The value is resolved once per employee in `runAccrual()`
      from the SAME Employee row that iteration already selected, through a `nullableTrim()`
      helper mirroring `TimeEntryStampListener` / `TimesheetProcessStampListener`, and passed
      into `provision()` / `accrueExisting()`. `@spec` tags on all four members point at this
      change's `leave-accrual-job` delta (REQ-ACCR-006).
- [x] 2.3 Unit tests: (a) create stamps `userId` for a linked employee; (b) update re-stamps a
      pre-existing null (the self-heal path); (c) an unlinked employee's balance keeps
      `userId: null` (both the absent and the whitespace-only shape); (d) buy/sell settlement
      writes do NOT need or add stamping (regression guard on custody, in
      `LeaveBuySellSettlementServiceTest` — asserts it neither invents nor drops a link). Plus a
      fifth guard: stamping must never turn REQ-ACCR-004's idempotency no-op into a write.
      `vendor/bin/phpunit --filter 'LeaveAccrualJobTest|LeaveBuySellSettlementServiceTest'` →
      OK (22 tests, 96 assertions).
- [ ] 2.4 `composer check:strict` green; fix any pre-existing findings in touched files.
      **BLOCKED locally, not by this change.** `vendor/` in this checkout is root-owned and
      unwritable, and `conduction/hydra-gates` + `conduction/coding-standard` were never
      installed into it, so `phpcs`, `phpmd`, `phpstan` and `php-cs-fixer` all abort on a
      missing `vendor/conduction/…/quality-config/*` before reading a single source file, and
      `composer install` fails with `Permission denied`. What DOES run is green: `composer lint`
      (php -l, all files), `composer psalm` (0 errors; 274 info-level notes, its normal output)
      and `composer test:all` (**1274 tests, 4935 assertions, 1 skipped, OK**). The four blocked
      linters must be re-run in CI or on a checkout with a writable `vendor/`.

## 3. Seeds

- [x] 3.1 `lib/Settings/register.d/hr-seed.json`: add `userId: "admin"` to
      `leavebalance-jansen-2026-holiday` ONLY — devries/bakker balances stay unstamped so the
      `@me` exclusion is demonstrable (REQ-MHS-006 pattern), with a `_note` recording why.
      Verified the seeded submitted timesheet rows still carry `managerUserId: "admin"` (3
      occurrences, unchanged); no new objects added.
- [ ] 3.2 Verify on a clean env (`/clean-env`): `admin` lands on `/mijn` via the group title
      and sees — hours sum > 0 (this only holds while the seeded TimeEntry `startedAt` values
      fall in the current month; if the hours-redesign seeds are static-dated, assert against
      the value the filter actually selects, and note it), the Jansen timesheet rows, exactly
      one leave-balance row (Jansen), the expense count, payslip rows, and a nonzero pending
      approvals count. Repair import run twice stays idempotent.
      **NOT DONE — deliberately deferred, and the value-level facts are NOT claimed.** This ran
      against the SHARED dev instance (the orchestrator's constraint), where a clean-env reset
      is not mine to perform and the register re-import needed for the new `LeaveBalance.userId`
      cannot be triggered without an app-version bump or a disable/enable cycle. Consequences,
      measured rather than assumed: the verlofsaldo widget renders its fail-closed empty state
      (no live balance carries `userId` yet — exactly the documented pre-accrual behaviour), and
      the hours/declaraties/te-beoordelen tiles show 0 for the two reasons above (the schema-slug
      collision for the first two; the live seeded timesheets carry no `managerUserId` for the
      third). What IS proven live is the mechanism: every widget emitted the right
      caller-scoped request, and the caller-scoped Timesheet list correctly showed the one row
      that does carry `userId: "admin"`. The value-level assertions belong to 4.3 on a
      CI-seeded instance.
      **Also worth noting on the seeded-hours caveat this task anticipated: the hours-redesign
      TimeEntry seeds ARE static-dated (2026-05), so on a CI run in any other month the
      "Mijn uren deze maand" tile is legitimately 0. That is why the value assertion is
      `@e2e exclude`d in the spec and pinned by V1 instead.**

## 4. e2e (Playwright — `tests/e2e/spec-coverage/`, CI seeds via `tests/e2e/ci-seed.sh`, which
      refuses :8080 by design; resolve the router base per `core-journeys.spec.ts`)

- [x] 4.1 New `tests/e2e/spec-coverage/personal-dashboard.spec.ts` referencing, by verbatim
      scenario name in comments, every non-excluded scenario of this change's spec deltas
      (gate-19 traceability) — 7 scenarios across the two UI-bearing deltas, plus machine-
      readable `@e2e <spec>::<slug>` anchors on the test declarations (the short form gate-19's
      `_E2E_SHORT_RE` reads; hrmq's existing e2e files carry prose references only, which
      gate-19 does not count). 5 journeys: (a) group TITLE click → `/mijn` + six widgets, with
      the ADR-001 menu order and the routed-vs-bare-parent contrast in the same test;
      (b) chevron expands the children without navigating, then a child still navigates;
      (c) caller scoping — the leave-balance table lists only the caller-linked row, asserted as
      "1 of N" against a register read so an empty fixture cannot pass it; (d) the
      pending-approvals stat renders a numeric value (zero allowed by grammar, D4) and clicking
      it lands on `TeamUrengoedkeuring`; (e) `page.goto` the OLD `/mijn-hr/gebruikelijk-loon`
      → final URL is `/mijn/gebruikelijk-loon` AND the page renders.
      `PLAYWRIGHT_BASE_URL=http://localhost:9999 npx playwright test --list` parses: **55 tests
      in 5 files** (5 new here; `manifest-pages.spec.ts` also picked up `MijnHr mounts at /mijn`
      and the moved `MijnGebruikelijkLoon mounts at /mijn/gebruikelijk-loon` automatically).
- [x] 4.2 Assert on `data-testid`/`data-cn-route`/manifest ids, not Dutch display strings,
      wherever the harness allows (the instance renders Dutch; i18n conversion lands next).
      Three measured hooks carry it, all documented in the spec's header: the nav entry's
      `li[data-testid="cn-nav-entry-<menu id>"]` and its `data-cn-route` attribute (present ONLY
      when the entry carries a route — so its presence IS REQ-PDB-003's claim, and the
      route-less `EmployeesGroup` is the in-test control), `[data-testid-page-id="<page id>"]`,
      and `[role="group"][aria-label="<widget id>"]` on each dashboard grid cell (CnDashboardGrid
      labels every cell with the manifest widget id — the only per-widget identity the grid
      exposes). No Dutch literal is asserted anywhere in the file. The spec is READ-ONLY: it
      creates and mutates nothing, so it needs no RUN_ID and no cleanup.
- [ ] 4.3 Run the suite against a fresh CI-style instance (not :8080) and confirm the new spec
      passes cold — a spec that passes warm and fails cold is reading ambient state.
      **NOT RUN — no CI-style instance available from this session, and `ci-seed.sh` refuses
      :8080 by design (correctly).** `--list` parses and the selectors were each read off the
      live DOM rather than guessed, but that is not the same as a green cold run and is not
      claimed as one. Hand back to the orchestrator/CI.

## 5. Close-out

- [ ] 5.1 `composer check:strict` + PHPUnit + `npm run test` green; run
      `scripts/run-hydra-gates.sh` — gate-16 (`@spec` on changed methods), gate-19 (scenario
      traceability), manifest gates clean. If gate-65 (`navigation-budget`) has landed by then,
      confirm its emitted count for hrmq is 5 (exemption verified); if it has not, record the
      before/after count (6 → 5) in the PR body against ADR-097's census table.
      **PARTIAL.** Green: PHPUnit (`composer test:all` — 1274 tests, 4935 assertions, 1 skipped,
      OK), `npm run lint` (eslint src — clean), `npm run build` (exit 0), `composer psalm`
      (0 errors), `composer lint`. gate-16 `check_spec_coverage.py .` → `# count=0` (clean).
      There is no `npm run test` script in this app (unit tests are PHP only). `check:strict`
      is blocked by the vendor/ breakage recorded in 2.4. gate-19's checker reads
      `openspec/specs/`, so this change's deltas are outside its scope until `opsx-sync`; the
      `@e2e` anchors are written to match once they land. gate-65 has not landed — **record in
      the PR body: hrmq's counted `main` top-level entries go 6 → 5, because `MijnHrGroup` now
      satisfies both ADR-097 Decision 3 conditions (routes to a `type:"dashboard"` caller-scoped
      page AND carries children) instead of claiming an exemption it could not demonstrate.**
      Live top-level order, unchanged apart from the new route: Dashboard, MijnHrGroup,
      EmployeesGroup, VerlofVerzuimGroup, PlanningGroup, ExpensesGroup, PayrollGroup
      (+ ConfiguratieGroup in `section: "settings"`).
      **`tests/verify-manifest-parity.js` was re-baselined.** That harness asserts the effective
      manifest is byte-identical to a recorded fixture, so a change that intentionally alters
      pages MUST fail it once and then move the fixture — otherwise the harness is protecting
      nothing. Before re-baselining it reported exactly the four intended discrepancies and no
      others: `route CHANGED for MijnGebruikelijkLoon`, `page INVENTED: MijnHr (/mijn)`, the
      menu tree (the added `route: "MijnHr"` on `MijnHrGroup`), and `page CONTENT differs for
      MijnGebruikelijkLoon`. `pages.json`, `menu.json` and `manifest-canonical.json` were
      regenerated from the new effective manifest in the same canonical form; `deeplinks.json`
      is untouched (this change adds no deep links). Post-baseline: PASS, 113 pages, 65
      navigable menu entries.
- [x] 5.2 Update `docs/` feature docs if they cover the Mijn HR surface (check first — do not
      create docs that don't exist). `docs/hr/self-service.md` covers it and is updated:
      Mijn HR described as a routed group landing on `/mijn` under the `/mijn/...` prefix (with
      the legacy redirect), `LeaveBalance` added to the denormalized-`userId` set with the
      accrual job named as its custodian and the self-heal explained, and the stale
      "Dashboard KPIs" section — which described the `@me` widgets
      `hrmq-dashboard-steering-indicators` REMOVED from `/dashboard` — replaced by a
      "The personal dashboard" section describing the six real widgets. The manager section's
      claim about re-scoped Dashboard approver widgets was corrected the same way.
- [x] 5.3 Hand design.md's two open questions (catch-all default landing;
      MijnGebruikelijkLoon-as-widgets) back to the orchestrator before archiving — both are
      unchanged and still open; `main.js`'s catch-all still falls back to `/timesheets`.
      Four further items for the orchestrator, all measured during V (details in design.md
      Risks and in V1 above): (1) the aggregation endpoint's global schema-slug resolution,
      which makes widget 1 read nothing and widget 4 read pipelinq's rows on a multi-app
      instance — an OpenRegister fix, not a manifest one; (2) `@currentFiscalYear` is deprecated
      with a 2026-10-01 removal and this change adds a new use; (3) the steering Dashboard's
      `Open obligations` widget binds `endpointSource` on an `object-table`, a key
      `CnObjectListWidget` — the component a dashboard `object-table` actually resolves to —
      does not read, so it cannot be rendering rows either; (4)
      `scripts/check-manifest-sentinels.mjs` collects clauses from `config.filter` and
      `config.quickFilters[].filter` only, so the six optional-token clauses this change adds
      inside dashboard widget content are outside what that gate inspects.

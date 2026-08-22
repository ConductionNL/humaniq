## Context

See proposal.md for motivation. Measured directly against this checkout (hrmq 0.2.0, branch
`spec/hrmq-refactor-wave-1`, 2026-08-19):

- `src/manifest.json`: 252,991 bytes (brief said 252,561 — corrected). 113 pages (60 index, 49
  detail, 2 dashboard, 2 custom), 260 widgets (brief said 259 — corrected by one), 39 `deepLinks`,
  64 navigable menu entries across 11 top-level groups, 73 menu nodes total including groups.
- `_note` content is **22.6%** of the file's bytes (57,110 of 252,991, counted as the literal
  `"_note": "..."` span including key/quotes — the brief's 28.4% does not reproduce and is corrected
  here). 61 `_note` occurrences, all page-level; zero are on `menu[]` entries (there is no
  `menu-layout.json` today, so there has been no venue for navigation-level rationale).
- `src/main.js` imports `bundledManifest` from `./manifest.json` and builds vue-router routes from
  `bundledManifest.pages` directly — no `buildManifest()` call, no `manifest.d/`, no
  `menu-layout.json`.
- `node tests/validate-manifest.js` passes at clean HEAD: `Ajv validation: PASS (0 errors)`, 113
  pages, against schema v2.19.0 resolved from `node_modules/@conduction/nextcloud-vue`.
  `node tests/validate-widget-keys.js` fails at clean HEAD on an unrelated drifted-`node_modules`
  webpack-config error (brief §7) — reproduced, baselined, not this change's concern.
- `lib/Settings/register.d/` (the backend precedent this change mirrors) has **32** fragment files
  (brief said 33 — corrected), deep-merged by `SettingsService::loadRegisterConfigData()`
  (`lib/Service/SettingsService.php:459-527`) via `glob($fragmentDir . '/*.json')`, sorted, folding a
  content hash into the imported version so OpenRegister's version-gated import re-runs on any
  fragment change.
- `@conduction/nextcloud-vue`'s `buildManifest()` (`nextcloud-vue/src/utils/buildManifest.js`) reads
  exactly four keys from each fragment object: `pages`, `menu`, `pageTemplates`/`pageInstances`, and
  `sets`. Nothing else. `openconnector/src/manifest.d/environments-and-promotion.json`'s own
  `$comment` confirms this in production: *"a fragment cannot carry [a header action]: buildManifest
  reads only pages/menu/pageTemplates/pageInstances/sets from a fragment, so any other key is
  silently ignored."*
- No app in the fleet has yet shipped `pageTemplates`/`pageInstances` in a live `manifest.json` or
  `manifest.d/` fragment (checked all 21 apps under `apps-extra/`) — only
  `nextcloud-vue/tests/utils/expandPageTemplates.spec.js` exercises the mechanism, with fixture data.
  hrmq would be the first real adopter.

## Goals / Non-Goals

**Goals:**
- Zero observable change: every `(id, route)` pair, every menu entry, every widget, every rendered
  string is identical before and after.
- A retroactive, one-time decomposition of the existing 113-page monolith into fragments + templates
  that a *later* change can safely restructure (relocate menu entries, collapse role-lens
  duplicates, move `Configuratie` out of the main menu) without touching page/widget content again.
- Keep the manifest validator's coverage honest across the split (see proposal.md Impact).

**Non-Goals:**
- Deciding the target menu structure (ADR-097 ceiling, role-lens collapse, `Configuratie`
  relocation) — a later change's job, using the `relocations`/`settingsSection` mechanism this
  change installs but leaves empty.
- Retrofitting every future hrmq change onto per-change fragments (ADR-037's literal convention).
  This change performs a one-time domain-based decomposition of pre-existing content; see Decision 1
  for why that diverges from ADR-037's per-change fragment naming, and why it doesn't have to
  converge — new changes add their own fragment on top of this baseline exactly as ADR-037 already
  specifies.
- Fixing `tests/validate-widget-keys.js`'s drifted-`node_modules` failure, or the P0
  `@workspace.activeAdministrationId?` unsubstituted-sentinel defect. Both pre-exist this change and
  are unrelated to manifest structure.

## Decisions

### 1. Fragment split unit: domain, mirroring `register.d`, not literally "one fragment per change"

ADR-037's stated convention is one fragment per OpenSpec change (`manifest.d/<change>.json`),
chosen so concurrent same-app builds never collide on a shared file. That convention fits *new*
work — pipelinq's `45-prospects.json`, `50-forecast.json`, etc. are each a past change's own
fragment, still sitting where that change's build left them. It does not fit a *retroactive* split
of 113 already-merged, already-interdependent pages: there is no single change boundary to split
along (many of these pages predate the fragment pipeline's introduction into the fleet), and forcing
one would produce fragments named after archived changes that no longer describe their contents.

Instead, this change mirrors `lib/Settings/register.d/`'s domain grouping — a split hrmq's own
backend already uses successfully at a comparable scale (32 files, 55 schemas) for the identical
problem (a monolithic OpenAPI register document that needed to stop growing as one file). Domain
fragments read as a stable map a maintainer already knows (`hr-verzuim.json` holds the verzuim
pages, exactly as it holds the verzuim schema), and nothing about `buildManifest()`'s merge
semantics (union-by-id for pages, union-by-id-with-recursive-children for menu) cares what a
fragment is named or grouped by — the mechanism is agnostic. **Future new changes still add their
own per-change fragment on top of this baseline**, per ADR-037's standing convention; this decision
only concerns how the *pre-existing* 113 pages are organized once, not how change N+1 adds page 114.

**Alternative considered**: one fragment per page (113 files). Rejected — turns a 253KB file
into 113 tiny ones, defeats the "a maintainer opens one domain file" goal the register.d precedent
demonstrates works, and gives `require.context` 113 entries to glob for no benefit (fragments exist
to make concurrent editing and readability better, not to atomize).

**Alternative considered**: one fragment per top-level menu group (11 files). Rejected — several
groups (`PayrollGroup`, 14 children; `EmployeesGroup`, 11 children) span multiple register.d domains
and would recreate a smaller version of the same monolith problem inside each file; `hr-objects.json`
alone (17 pages once schema-mapped) is already the largest fragment under the finer domain split.

### 2. The page → domain fragment mapping, and how the 3 cross-domain schemas were resolved

Mapping each page's `config.schema` to the register.d file that declares it produces a clean,
complete 113-page assignment with one wrinkle: three schemas are declared in **two** register.d
files each, because `hr-cost-rate.json` uses the documented deep-merge *extension* pattern
(`SettingsService::deepMergeConfig()`) to add cost-rate-specific properties onto `Employee`,
`EmploymentContract`, and `Timesheet` rather than declaring them fresh. For these three, the page
goes to the file that **originates** the schema, not the one that only extends it:

| Schema | Origin (page's fragment) | Extension-only (not used for page placement) |
|---|---|---|
| `Employee` | `hr-objects.json` | `hr-cost-rate.json` |
| `EmploymentContract` | `hr-objects.json` | `hr-cost-rate.json` |
| `Timesheet` | `hr-timesheet.json` | `hr-cost-rate.json` |

Verified this has no correctness cost for the manifest side (unlike the OpenAPI/register side, a
page's `related` widget does not `$ref` another page's JSON — it declares a register+schema string
resolved at runtime by `CnRelatedObjectsWidget` against the OpenRegister API — so there is no
structural reason a `related` reference must live in the same fragment file as the domain it points
at; splitting by owning-page-domain costs nothing beyond a maintainer occasionally opening a second
file to see what a `related` widget resolves to, which is unchanged from the fleet's existing
`hr-cost-rate.json`/`hr-objects.json` split on the backend today).

Full page → fragment counts (schema-derived, `config.schema` → register.d origin file, applied to
all 113 pages):

| Fragment | Pages | Fragment | Pages |
|---|---:|---|---:|
| `hr-objects.json` | 17 | `hr-attendance.json` | 3 |
| `hr-leave.json` | 8 | `hr-verzuim.json` | 3 |
| `hr-ats.json` | 6 | `hr-okr.json` | 3 |
| `hr-roster.json` | 6 | `hr-retro.json`, `hr-pension.json`, `hr-wnt.json`, `hr-loonbeslag.json`, `hr-glpost.json`, `hr-paybatch.json`, `hr-cao.json`, `hr-hr21.json`, `hr-bhv.json`, `hr-stagiair.json`, `hr-integrations.json`, `hr-dsr.json` | 2 each |
| `hr-timesheet.json` | 5 | `hr-administratie.json` | 1 (`Administraties`, a `type: custom` page with no `config.schema` — assigned here on content, not schema, since it is genuinely administration-domain UI) |
| `hr-expense.json` | 5 | | |
| `hr-comp.json` | 5 | | |
| `hr-documents.json`, `hr-onboarding.json`, `hr-org.json`, `hr-assets.json`, `hr-fleet.json`, `hr-performance.json` | 4 each | | |

That totals **110** fragment-placed pages: 109 schema-mapped across the 28 domains in the table,
plus the 1 content-mapped `Administraties` in `hr-administratie.json`. The remaining **3 pages have
no `config.schema` and no obvious domain**: `Dashboard` (the app landing page), `ProformaPayslip` (a
standalone payroll-simulation tool), and `MijnGebruikelijkLoon` (a DGA usual-wage dashboard) stay in
the base `src/manifest.json` — they are app-shell/cross-cutting surfaces, not one domain's content,
matching how `Dashboard` isn't a register.d schema either. 110 + 3 = **113**, the full page count.
This table counts pages by *fragment placement* (which file's `pages[]`/`pageInstances[]` a page
lives in); Decision 3 below separately decides, for each placed page, whether it is authored as a
concrete page or as a `pageInstance` referencing a template — every one of the 113 pages gets exactly
one placement and exactly one of those two authoring forms. `hr-cost-rate.json`, `hr-packs.json`, and
`hr-seed.json` (JurisdictionPack config data / dev fixtures / the two extension-only schemas) end up
with **zero** hrmq pages and get no `manifest.d` counterpart — a fragment is created only where there
is page content to hold, exactly as `openconnector`'s `manifest.d/` has real fragments only for the
one feature that needed one, plus `_placeholder.json` for the always-non-empty glob.

**Net**: 29 populated `manifest.d/hr-*.json` fragments (28 schema-domain + `hr-administratie.json`
for the one content-mapped custom page) + `_placeholder.json` + `README.md`.

### 3. `pageTemplates`: which shapes get one, and which stay hand-written

**Index tier — one template, all 60 pages, zero `override` deltas needed.** All 60 index pages carry
the same required shape (`register`, `schema`, `columns`, `filters`, `sort`); the only variance
measured is the *presence* of three optional keys (`allowCreate` on 3 pages, `actionToggles` on 3,
`defaultFilters` on 6 — 11 of 60 pages carry at least one). `expandPageTemplates()`'s existing
optional-parameter semantics (an absent optional param drops its exact-match placeholder key
entirely, per `nextcloud-vue/src/utils/expandPageTemplates.js`'s `DROP` sentinel) cover this exactly
— no page needs an `override`. This was verified by comparing the *set of config keys* across all
60 pages (10 distinct keysets, all explained by these three optional keys plus `description`), not
by assuming uniformity; verifying the *value shapes* of `columns`/`filters`/`sort` match across all
60 (not just key presence) is listed as an Open Question below, since a template built on a false
uniformity assumption fails at the instantiation that breaks the pattern, not at design time.

Six of the 60 index-page instantiations (`TimesheetApproval`, `TeamUrengoedkeuring`,
`ExpenseApproval`, `TeamVerlofgoedkeuring`, `LeaveApproval`, `TeamDeclaratiegoedkeuring`) are the
role-lens duplicates ADR-097 §5 names — they get instantiated here exactly as they exist today
(non-goal: collapsing them is menu/authorization work, not manifest-shape work).

**Detail tier — four templates covering 19 of 49 pages; the brief's "29 signatures / top-4 cover
20" figure does not reproduce.** Measured 30 distinct widget-key-sequence signatures (not 29); the
four most common cover 19 pages (not 20) — corrected here. The four:

| Template | Widget-key sequence | Pages | Grid shape (verified identical across instances except `gridHeight`) |
|---|---|---:|---|
| A `simpleDetailScaffold` | `data`, `related`, `audit-trail` | 9 | data 8-wide + related 4-wide (body), audit-trail sidebar tab |
| B `simpleDetailScaffoldWithLifecycle` | `lifecycle-actions`, `data`, `related`, `audit-trail` | 4 | as A, plus a full-width `lifecycle-actions` header-actions row |
| C `singleDataScaffold` | `data`, `audit-trail` | 3 | data 12-wide (full width, body), audit-trail sidebar tab |
| D `actionedDetailScaffold` | `lifecycle-actions`, `actions`, `data`, `related`, `audit-trail` | 3 | data 8-wide + related 4-wide (body), split header-actions row (`lifecycle-actions` 8-wide + `actions` 4-wide), audit-trail sidebar tab |

Each was spot-checked for grid-coordinate identity beyond the widget-key sequence (not just "same
widget types present") — e.g. template A's 9 instances all place `data` at `gridX:0, gridWidth:8`
and `related` at `gridX:8, gridWidth:4`, varying only `gridHeight` (5–7, driven by field count),
confirming these are genuinely one shape, not a coincidental key-sequence match masking different
layouts.

**Templates B and D were kept separate rather than unified into one template with an optional
`lifecycle-actions` widget slot.** `expandPageTemplates()`'s DROP semantics can drop an entire array
element if it is itself an exact-match placeholder (`substitute()` recurses into arrays and omits
any element whose substitution is `DROP`), so a single template with a
`"widgets": [..., "{{lifecycleActionsWidget}}", ...]` slot is technically possible. Rejected: the
value that would need to flow through that placeholder is not a scalar but each schema's own
transitions list (`from`/`to`/`label` per action), which varies enough per schema that the
instantiation would carry almost as much structure as just writing the widget inline — the
"parameterise it" indirection would cost more to read than two small, obviously-related templates.

**The remaining 30 detail pages, plus the 2 dashboard and 2 custom pages, stay hand-written.** This
includes `EmployeeDetail` (17 widgets: 1 data + 4 `stats-block` + 1 data + 9 `object-list` +
integration + audit-trail — the single most complex page in the app) and every one of the 22
singleton widget-signatures. Forcing any of these through template A–D would need an `override` that
adds or removes most of the template's own widget array — the brief's explicit warning ("forcing an
80%-fit page through a template with a large `override` is worse than leaving it explicit") applies
directly, and none of the 30 is closer than that to any of the four shapes.

### 4. `_note` relocation: travels with the page; template-level note for genuinely shared rationale

Checked before deciding: are the near-duplicate-sounding notes on templates A–D's 9+4+3+3 = 19 pages
actually duplicates (safe to collapse into one template note) or independently valuable (would lose
information if collapsed)? Sampled all 9 of template A's notes — each cites its own originating
OpenSpec change name and a schema-specific reason (e.g. `BhvCertificeringDetail`: "plain dated-fact
record, no `x-openregister-lifecycle`"; `ShiftDetail`: "a reusable shift definition... authored once
and reused"), while several also explicitly reference each other as precedent ("a simple two-panel
leaf like OrgAssignmentDetail"). They are not duplicates — collapsing them into one note would drop
real content.

Decision: the `pageTemplate`'s own `_note` field (the schema explicitly supports one, "ignored by
the expander" — i.e. free-form and safe) carries the rationale that is genuinely shared ("two-panel
leaf: one hop-out reference, no lifecycle, no child collections — see `related` for the resolved
reference"), and each `pageInstance` optionally carries its own note for whatever remains
schema-specific. This is a real compression (the repeated "a simple two-panel leaf like X"
preamble collapses to one sentence on the template) without deleting the schema-specific remainder.
The exact split of which sentence goes where is left to implementation (tasks.md), since it is an
editorial judgment call on already-written prose, not a structural decision.

For the 94 non-templated pages, `_note` content is unchanged and simply travels into the domain
fragment that now owns the page — this alone is most of the fix for "one note 60,000 bytes from the
note it references," since each fragment now holds a handful of related pages instead of 113
unrelated ones.

`menu-layout.json` gets an empty `_navigationRationale` block (the pipelinq/openconnector
convention: menu-structure decisions belong there, not on individual manifest pages, because
`additionalProperties: false` on `menuItem` means an entry can't carry its own rationale). This
change makes no menu-structure decisions, so the block starts empty rather than pre-filled with
speculative reasoning for choices a later change will actually make.

### 5. `deepLinks`, `runtime`, `dependencies` stay in the base — not a choice, a constraint

`buildManifest()`'s source (`nextcloud-vue/src/utils/buildManifest.js:34-70`) reads exactly
`frag.pages`, `frag.menu`, `frag.pageTemplates`, `frag.pageInstances`, `frag.sets` from each fragment
object — nothing else. A fragment declaring `deepLinks` would have that key silently dropped, not
merged and not erroring; `openconnector`'s own fragment carries a code comment confirming this
(quoted in Context). This is stated as a design constraint, not a decision with alternatives,
because there is no way to make it a choice without changing `buildManifest()` itself, which is out
of this change's write-scope (shared library, not hrmq).

### 6. Fixing `tests/validate-manifest.js`'s coverage, not inheriting the fleet's gap

`pipelinq/tests/validate-manifest.js` and `openconnector/tests/validate-manifest.js` were checked as
this change's nearest precedents and both read `src/manifest.json` directly with no fragment
handling — a Node `fs.readFileSync` on the base file, then Ajv, full stop. That is silently correct
today for those apps only because their bases still hold the bulk of `pages[]`; it is the same
class of defect the brief's "watch for the silent-empty failure mode" section describes: a check
that returns 200/pass while covering a shrinking fraction of what it once covered.

hrmq's copy of this script is updated (task in tasks.md) to require the fragment files and call
`buildManifest()` (or an equivalent minimal merge — `pages`/`menu` concatenation is the only part
Ajv-relevant) before validating, and to print the page count it validated, so a future regression
here is visible in the script's own output rather than requiring a reader to notice the file got
smaller. Fixing `pipelinq`'s and `openconnector`'s copies of the same script is out of this change's
scope (different repos, different write-authorization) but is worth flagging to the orchestrator as
a candidate fleet-wide follow-up.

## Risks / Trade-offs

- **[Risk] A fragment silently carrying `deepLinks`/`runtime`/`dependencies` would lose that content
  with no error at build time** → Mitigation: Decision 5 keeps those keys exclusively in the base;
  tasks.md includes an explicit "grep manifest.d/ for these keys, must find none" check, and the
  spec's silently-ignored-key requirement names this directly.
- **[Risk] Route-order sensitivity**: six route pairs share a static/dynamic prefix
  (`/timesheets/approval` vs `/timesheets/:id`, and five more — see spec.md). Reordering `pages[]`
  across fragment-load order could, in principle, change which one a router resolves first. Verified
  this is not actually order-sensitive for hrmq's router: vue-router 4 (`^4.6.4`, same version hrmq
  and pipelinq both pin) ranks routes by a static-vs-dynamic-segment score at `addRoute` time, not by
  registration order — the current unsorted `routesFromManifest()` in `src/main.js` already resolves
  these six pairs correctly today with no explicit sort, which would not be reliably true if
  registration order were what mattered. Mitigation: tasks.md still includes an explicit
  route-resolution check for all six pairs post-split (belt-and-braces; the spec's dedicated
  scenario names it), rather than relying on this reasoning alone.
- **[Risk] The index-page template assumes `columns`/`filters`/`sort` are structurally uniform
  across all 60 pages** (only key-presence was verified, not value-shape) → see Open Questions.
- **[Trade-off] 29 fragment files is more files to `git blame`/`grep` across than one** — accepted;
  this is the entire point of ADR-037, and the backend's own 32-file `register.d/` precedent shows
  it holds up at this scale in this codebase already.
- **[Trade-off] Two closely-related detail templates (B, D) instead of one clever conditional-slot
  template** — accepted per Decision 3; simplicity over cleverness for a first adoption of a
  mechanism nothing else in the fleet uses yet.

## Migration Plan

1. Create `src/manifest.d/` (29 domain fragments + `_placeholder.json` + `README.md`) and
   `src/menu-layout.json` (empty `relocations`/`removals`, `settingsSection: []`, empty
   `_navigationRationale`) — additive, does not yet touch `src/manifest.json` or `src/main.js`.
2. Shrink `src/manifest.json` to the shell (base keys + the 4 schema-less pages + top-level menu
   group shells with no `children`).
3. Switch `src/main.js` to `buildManifest()`.
4. Update `tests/validate-manifest.js` to validate the effective manifest.
5. Run the before/after (id, route) and menu-tree comparison (new script, tasks.md) against the git
   revision immediately before step 1 — this is the actual proof of the no-functionality-loss
   invariant, not an assertion.
6. No deploy/rollback complexity beyond a normal frontend build — this ships in the same asset
   bundle as any other hrmq frontend change; there is no data migration, no PHP change, and no
   OpenRegister interaction.

## Open Questions

- Do `columns`/`filters`/`sort` on the 60 index pages actually share a uniform *value* shape (e.g.
  is `filter` always a DSL string, never sometimes an object), or does the current keyset-only check
  hide a page whose `filters` array elements have a different internal shape? Answering this changes
  nothing about the template *decision* (one index template either way) but could add one or two
  more `{{param}}` placeholders or a per-page `override` if a page turns out to need one — resolvable
  during implementation without revisiting this design.
- Exact wording split of which sentence from each of the 19 templated pages' original `_note` moves
  to the template vs. stays per-instance (Decision 4) — an editorial judgment call on already-written
  prose, left to the implementer, reviewable in the PR diff.

## Errata (recorded at apply time, 2026-08-21)

The counts in this document were measured on `spec/hrmq-refactor-wave-1`. By the time the change
was applied, the asset/fleet merge and menu work had landed: the manifest at apply-HEAD had
**109 pages** (not 113), **8 top-level menu nodes / 62 navigable entries** (not 11/64), **37
deepLinks** (not 39), and the split produced **28 domain fragments + `00-templates.json` +
`05-menu.json`** (not 29 domain fragments). The four detail templates that shipped are
`simpleDetailScaffold` ×17, `dualDataScaffold` ×5, `singleDataScaffold` ×3 (plus `indexScaffold`
×58) — the B/D signatures this document described no longer existed at HEAD. Menu children live in
the single `05-menu.json` because `mergeMenuItems` append semantics cannot reproduce the canonical
interleaved order from per-domain contributions. Instance notes are carried via the template `note`
param (the `pageInstance` schema forbids `_note`), preserving every baseline note verbatim.

## MODIFIED Requirements

### Requirement: Every page is implicitly scoped to the active administratie (REQ-MULTI-004)

Every list and detail page SHALL be implicitly scoped to the active administratie via a base
`administrationId` filter: every administration-scoped index/detail page's `filter` carries
`administrationId: "@workspace.activeAdministrationId?"`; `App.vue` SHALL provide a reactive
`cnWorkspaceContext` at the SPA root (seeded from `IInitialState`/`PageController::index()`,
`loadState()` client-side) so the token resolves from first paint, and
`AdministrationSwitcher.vue` SHALL write into that SAME context on a successful switch so every
page re-scopes without a reload. When a selection IS active, the clause SHALL be sent resolved to
that administratie id. When no selection is active, the `?`-optional grammar SHALL drop the clause
rather than send it. Under no circumstance SHALL the literal, unresolved token string reach the
API.

**Correction (2026-08-19, `humaniq-boot-integrity`):** this requirement previously read
"**Delivered**" unconditionally. That status was **live-false as measured today**: a session whose
active administratie IS set (initial state `activeAdministrationId` = `"ADM-001"`, corroborated by
`AdministrationController::context`) hitting `/employees` sent
`administrationId=%40workspace.activeAdministrationId%3F` — the literal, unresolved token — to
`GET /api/objects/humaniq/Employee`, got `total: 0`, and rendered "No items found" for data that
exists (`total: 10` for that administratie; `total: 16` unfiltered). Root cause, verified: not this
requirement's design, and not a defect in the code as currently written in either `App.vue` or
`@conduction/nextcloud-vue`'s resolver — the deployed frontend bundle was never rebuilt after
either changed. This requirement's status is therefore no longer "Delivered" on the strength of the
source having once been written correctly; it is delivered only for as long as the `boot-integrity`
capability's bundle-freshness and manifest-sentinel-resolution checks both pass.

**The drop branch is a known exposure, and this requirement does not bless it.** "Drop the clause"
means the request is sent with no tenant predicate at all. Today that returns every administratie's
rows to any authenticated caller, because no humaniq schema declares an `authorization` block and
OpenRegister's `PermissionHandler` treats an empty block as default-OPEN — so "all accessible rows"
currently evaluates to "all rows". That is acceptable only for the single-administratie install
this grammar was designed for, and it is NOT a property to rely on once RBAC lands. Closing it is
owned by the access-control change later in this programme; naming it here stops a future reader
citing this requirement as evidence that unscoped listing was intended.

#### Scenario: An active administratie resolves into the request and scopes the result
@e2e exclude no e2e suite exists yet (tracked by humaniq-test-coverage-baseline); asserted at apply time against the rebuilt bundle as the positive control in tasks.md, since a negative-only assertion ("not the literal token") is also satisfied by the clause being dropped entirely — which is a different, unscoped outcome
- **GIVEN** a session whose active administratie is `ADM-001` (initial state seeded, as measured 2026-08-19)
- **WHEN** `/employees` fetches its list against the rebuilt bundle
- **THEN** the request carries `administrationId=ADM-001` and the result is that administratie's
  rows only — `total: 10` against the current seed, not `0` (the current defect) and not `16` (the
  unscoped drop branch)

#### Scenario: An administratie-scoped index page never sends its filter token unresolved
@e2e exclude no e2e suite exists yet (tracked by humaniq-test-coverage-baseline); the defect this scenario guards is a pure filter-string-resolution bug with zero DOM/rendering involvement, so the `boot-integrity` capability's Node-level manifest-sentinel-resolution and bundle-freshness checks are the layer that actually catches it — see openspec/specs/boot-integrity/spec.md
- **GIVEN** a session with no active administratie selected
- **WHEN** `/employees` (or any other administratie-scoped index page) fetches its list
- **THEN** the request sent to OpenRegister does not contain the literal string
  `@workspace.activeAdministrationId?` as a filter value — either the key is resolved to a real
  administratie id, or it is absent from the request entirely

#### Scenario: A live-verified regression on this requirement is possible again without warning, absent the guard
@e2e exclude documents the failure mode this correction responds to, not a new behaviour to test directly; superseded by the scenario above once the boot-integrity guards are wired in
- **GIVEN** no automated check asserts that a shipped bundle matches the source implementing this
  requirement
- **WHEN** the bundle drifts out of date again (a future build, a future dependency bump left
  unbuilt)
- **THEN** this requirement can silently return to being false in production while its own spec
  text, and the source tree, both say it is true — which is exactly what happened on 2026-08-19

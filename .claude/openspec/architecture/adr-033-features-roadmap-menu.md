# ADR-033: Features & Roadmap Menu

## Status
Proposed

## Date
2026-04-22

## Context

Conduction apps ship functionality at a growing pace. Users have no consistent, in-product surface to discover what an app does today, see what is planned next, or submit feature requests. Current answers are scattered across:

- per-app READMEs and Docusaurus sites (what exists today)
- GitHub issue lists (what's planned), accessible only by leaving the app
- ad-hoc channels (email, Slack) for user-to-maintainer feature requests, which creates no visible demand signal

The "specs are gold" framing — where each user-submitted feature request eventually becomes a spec consumed by Specter and built by Hydra — is undermined when users cannot submit feature requests from inside the product.

A cross-repo contract for a **Features & Roadmap** menu item solves all three: discoverability of shipped capabilities, visibility of the backlog, and a first-class submission path that feeds the spec-driven pipeline.

The detailed contract (component API, manifest shape, backend endpoints, submission flow, widget/page `specRef` convention) is specified at [ConductionNL/openregister#1306](https://github.com/ConductionNL/openregister/pull/1306) (`specs/features-roadmap-menu` and `specs/github-issue-proxy` capabilities under change `add-features-roadmap-menu`).

This ADR mandates adoption across every Conduction app once the underlying infrastructure is merged.

## Decision

### Every Conduction app SHALL mount the Features & Roadmap menu entry

In `src/navigation/MainMenu.vue`, every Conduction app MUST render the `<CnFeaturesAndRoadmapLink />` component inside the `<NcAppNavigationSettings>` slot **above** the existing Settings gear entry.

```vue
<template #footer>
  <NcAppNavigationSettings>
    <CnFeaturesAndRoadmapLink :repo="'ConductionNL/<appname>'" />
    <NcAppNavigationItem
      :name="t('<appname>', 'Settings')"
      :to="{ name: 'Settings' }">
      <template #icon><Cog :size="20" /></template>
    </NcAppNavigationItem>
  </NcAppNavigationSettings>
</template>
```

### Every Conduction app SHALL register the `/features-roadmap` route

The route MUST render `<FeaturesAndRoadmapView />` from `@conduction/nextcloud-vue`. No per-app customization of the view itself; configuration flows exclusively through props on the link component.

### Every Conduction app SHALL generate `docs/features.json` at build time

Apps MUST add `@conduction/openspec-manifest` as a dev dependency and wire it as a `prebuild` script in `package.json`:

```json
{
  "scripts": {
    "prebuild": "openspec-manifest"
  }
}
```

The generated `docs/features.json` MUST be committed to git (not gitignored) — it powers both the in-app Features tab and the public Docusaurus features page.

### Every Conduction app SHALL publish a public features page via Docusaurus

Apps that ship a Docusaurus site MUST install `@conduction/docusaurus-features` and expose `/features` backed by the committed `docs/features.json`. Apps without Docusaurus are exempt from this clause only until they acquire one.

### Widgets and pages SHOULD declare a `specRef`

Vue widgets and pages SHOULD declare the capability slug they relate to, so user feature requests launched from that surface inherit the correct `specRef`:

```vue
<!-- Widget (Composition API) -->
<script setup>
defineOptions({ specRef: 'catalog-management' })
</script>
```

```js
// Page (Vue Router)
{ path: '/catalogs', meta: { specRef: 'catalog-management' } }
```

Widgets and pages declaring a `specRef` automatically receive a "Suggest feature" item in their `NcActions` menu (no opt-in code required — the shared component injects it).

### OpenRegister owns the backend contract

The GitHub proxy endpoints (`GET /api/github/issues`, `POST /api/github/issues`) and the user/admin PAT storage (`openregister::github_token`, `openregister::github_api_token`) live in OpenRegister and are reused by every app, consistent with ADR-001 (OpenRegister as the data/framework layer).

### Submission destination: GitHub Issues

Submissions land on the target app's GitHub Issues — not Discussions — so they surface immediately on the Roadmap tab and are orderable by reactions. Curation happens via labels and triage, not via a separate proposal channel.

### Authorship fallback

The submission endpoint MUST prefer the authenticated user's per-user GitHub PAT (`openregister::github_token`) and MUST fall back to the app-level PAT (`openregister::github_api_token`) with an attribution prefix:

> Submitted by **\<display\_name\>** via \<instance\_url\>

No app may submit anonymously.

### Label blocklist

The Roadmap tab MUST filter out Hydra pipeline labels (`build:*`, `code-review:*`, `security-review:*`, `applier:*`, `retry:*`, `rebuild:*`, `fix:*`, `fix-iteration:*`, `build-retry:*`, `ready-*`, `needs-input`, `yolo`, `openspec`, `agent-maxed-out`, `pipeline-active`, `done`, `*:queued`, `*:running`, `*:pass`, `*:fail`). Domain labels pass through with native colors.

## Preconditions for adoption

This ADR moves from **Proposed** to **Accepted** only after **all** of the following are merged and released:

1. `ConductionNL/openregister` PR [#1306](https://github.com/ConductionNL/openregister/pull/1306) (spec) → followed by its implementation PR
2. `@conduction/nextcloud-vue` release exporting `CnFeaturesAndRoadmapLink`, `FeaturesAndRoadmapView`, `SuggestFeatureModal`, `useSpecRef`
3. `@conduction/openspec-manifest` 1.0 published to the npm registry
4. `@conduction/docusaurus-features` 1.0 published to the npm registry
5. OpenRegister itself has served as the adoption pilot and passed smoke tests

Until then this ADR is informational only. Hydra's review gates MUST NOT fail apps for missing the menu entry while the ADR is `Proposed`.

## Rollout plan (post-acceptance)

Adoption is delivered via per-app PRs, one at a time, in this suggested order:

1. **openregister** (pilot — done as part of the originating change)
2. **opencatalogi, docudesk** (high-use apps, largest user feedback surface)
3. **openconnector, procest, pipelinq** (middle tier)
4. **larpingapp, mydash, softwarecatalog, zaakafhandelapp** (tail)
5. **openklant, opentalk, openzaak, valtimo, n8n-nextcloud** (ExApp sidecars — evaluate separately, these may not have a standard MainMenu.vue)

Each adoption PR should be mechanical: add dev dep, wire `prebuild`, mount the component, register the route, ensure `docs/features.json` is committed. Estimated ~15 LOC per app excluding the generated manifest.

## Consequences

### Positive

- First-class, in-product discoverability of shipped capabilities
- Users can submit feature requests without leaving the app, closing the "specs are gold" loop
- Specs become the authoritative user-facing documentation source (reducing README drift)
- Uniform UX across every Conduction app
- `specRef` convention creates a machine-readable link between UI surfaces and capability specs — useful for Specter enrichment and Hydra coverage scans

### Negative

- Every app gains a dependency on four new packages (one framework dep via OpenRegister is already present; three npm deps are new)
- `docs/features.json` being committed means every spec change produces a manifest diff — more PR noise, but visible and reviewable
- Submission spam risk requires rate limiting and admin PAT hygiene

### Mitigations

- Rate limit (1 submission per user per 60s) is part of the underlying spec
- Admin PAT rotation is documented in OpenRegister's existing admin settings
- Manifest generator failures fail the build loudly rather than producing empty/stale manifests

## Alternatives considered

| Alternative | Rejected because |
|---|---|
| Per-app bespoke Features pages | Unmaintainable, no consistent UX, high drift |
| Surface specs only on Docusaurus (no in-app route) | Misses the discovery moment and the submission opportunity |
| Submit via GitHub Discussions | Adds a triage step, popular ideas invisible on roadmap until promoted, user preferred direct Issues |
| Central Conduction-wide roadmap | Loses per-app context; user goal is to see the roadmap *for the app I'm in* |
| Server-only submission (no user PAT) | Weak attribution, all issues appear authored by a bot, poor UX |

## Open questions

- Should `Proposed` → `Accepted` flip be an automatic Specter/Hydra action once the five preconditions are detected, or a manual human review? (Current answer: manual — architectural decisions remain human-gated.)
- Should `specRef` annotations be enforced by a hydra gate once the ADR is Accepted, similar to ADR-008 `@spec` tags? (Deferred — decide after pilot adoption.)

## References

- [ConductionNL/openregister#1306](https://github.com/ConductionNL/openregister/pull/1306) — originating spec
- ADR-001 — OpenRegister as the data/framework layer
- ADR-008 — `@spec` annotations (runtime `specRef` is the same concept applied to UI surfaces)
- ADR-010 — NL Design System (the component must honor theming)

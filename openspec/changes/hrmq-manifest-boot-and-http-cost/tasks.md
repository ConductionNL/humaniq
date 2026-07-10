## 1. Cache the `/api/manifest` response (code)

- [x] 1.1 In `lib/Controller/PageController.php::manifest()`, compute an ETag (e.g. from
      `Application::APP_ID` + app version, or an `md5`/`filemtime` of `src/manifest.json`) and set
      it on the `JSONResponse` alongside a `Cache-Control` header appropriate for a versioned,
      build-time-immutable asset (e.g. `public, max-age=3600`, revalidated via ETag).
- [x] 1.2 Confirm Nextcloud's HTTP layer honours the ETag for conditional `If-None-Match` requests
      and returns `304` (framework-provided behaviour; verify rather than hand-roll 304 handling if
      `JSONResponse`/`Http\Response` already supports it via `setETag()`).
- [x] 1.3 Avoid re-reading the file from disk unnecessarily within the same request lifecycle (a
      single `file_get_contents()` + `json_decode()` call is already the minimum per-request; do
      not introduce a broader process-level cache that could go stale across a deploy without a
      cache-bust key tied to app version).

## 2. Stop deep-reactive-converting the static bundled manifest (code)

- [x] 2.1 In `src/main.js`, before passing `bundledManifest` into the root `Vue` instance's props
      (`main.js:104`), mark it non-reactive (Vue 2 `Object.freeze()` on the top-level object is
      Vue's documented escape hatch from `observe()`'s walk, or use a project-standard equivalent
      if `@conduction/nextcloud-vue` already exports one — check `nextcloud-vue/src/` for an
      existing helper before adding a bespoke one).
- [x] 2.2 Apply the same treatment to `pageTypesProp`/`registryProp` (`main.js:95-96`), which are
      already shallow-cloned for extensibility but not yet marked non-reactive.
- [x] 2.3 Confirm this does not break `CnAppRoot`/`CnPageRenderer`'s internal reactive reads of
      manifest data that legitimately need to react to prop *changes* (e.g. if a future
      runtime-manifest fetch ever replaces the whole object) — freezing the object's own properties
      does not prevent Vue from reacting to the *prop reference itself* changing, only from
      per-property reactive conversion of its static contents.

## 3. Verify

- [ ] 3.1 Call `GET /apps/hrmq/api/manifest` twice with `curl -H "If-None-Match: <etag-from-first-call>"`
      on the second call — confirm a `304 Not Modified` response.
- [ ] 3.2 Load the app in a browser with Vue devtools (or a temporary `console.log` on
      `Vue.util.defineReactive` invocation count, removed before commit) — confirm the manifest
      object's nested properties are no longer individually converted to reactive getters/setters
      at boot.
- [ ] 3.3 Manually exercise all six manifest pages (`Timesheets`, `TimesheetApproval`,
      `TimesheetDetail`, `Expenses`, `ExpenseApproval`, `ExpenseDetail`) — confirm no regression in
      rendering, navigation, or the widgets/sidebar tabs that read manifest `config`.

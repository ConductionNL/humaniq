# Design: My HR on the phone

## Context

Read at `development` af702f78.

- humaniq's routes (`appinfo/routes.php`): `page#index` at `/` (line 20), `page#manifest` at
  `/api/manifest` (line 22, the frontend manifest, not a web manifest), and `page#catchAll`
  at `/{path}` (line 145), which must stay last. `lib/Controller/PageController.php` serves
  the SPA shell (`index()` line 128, `catchAll()` line 180).
- No web manifest, service worker or phone layout exists: `grep -rniF 'pwa|progressive
  web|service-worker' lib src/manifest.d src/manifest.json` finds nothing.
- `MijnHr` (`src/manifest.d/personal-dashboard.json:4`, route `/mijn`) is the employee's
  landing dashboard with stat tiles and object tables filtered on `@me`.
- dossiq ships an installable web app with `serviceWorker()` and `webManifest()` on its SPA
  controller (`dossiq lib/Controller/DashboardController.php:342`). ADR-108 lists "PWA
  assets" among public surfaces that stay in the owning app.
- openregister adds `worker-src 'self'` to Nextcloud's default content security policy so a
  same-origin service worker can register (`openregister lib/AppInfo/Application.php`,
  `relaxCspForWebPushWorker()`), and ADR-031 defines the `web-push` notification channel
  (VAPID, a service worker renders `showNotification`, opt-in by a user gesture only).
- `lib/Service/ReceiptExtractionService.php` reads `Expense.receiptFile` for OCR after it is
  attached.

## Goals / Non-Goals

**Goals**

- An employee installs `Mijn HR` on a phone and uses it with their normal Nextcloud login.
- The three common tasks are one tap from the start page.
- Nothing personal is stored on the phone by humaniq.

**Non-Goals**

- A native app, offline data, location tracking.
- Any page logic in humaniq; the phone layout is the library's.

## Decisions

### D1. Two static assets served by humaniq

`PwaController::manifest()` answers `application/manifest+json`: `name` "Mijn HR",
`short_name` "Mijn HR", `start_url` `/apps/humaniq/mijn`, `scope` `/apps/humaniq/`,
`display` `standalone`, icons at 192 and 512 pixels, theme colours from the Nextcloud theme.
`PwaController::serviceWorker()` answers the built `service-worker.js` as
`application/javascript` with `Service-Worker-Allowed: /apps/humaniq/`. Both are
`#[PublicPage]` and `#[NoCSRFRequired]`: they carry no data and a browser fetches them before
any session exists. Both routes are declared before `page#catchAll`.

Alternative considered: a native app with store listings. Rejected: a second client, store
review and a separate login, for a leaf app that already runs on the user's Nextcloud.

### D2. The service worker caches the shell and never data

Install caches the compiled bundle, styles and icons (cache-first, versioned by build hash,
old caches deleted on activate). Every request under `/apps/humaniq/api/`,
`/apps/openregister/api/` and `/ocs/` goes to the network with no cache. When the network
fails, the page shows an offline notice.

### D3. Quick actions are manifest widgets

`MijnHr` gains a `link-button` row at the top with three actions: request leave (opens the
leave request form), submit an expense (opens the expense form), book hours (opens the hours
booking dialog). On a desktop they render as a normal row; the library's grid stacks them at
phone width.

### D4. The camera is a hint on the existing field

The expense form's `receiptFile` field gains `accept: image/*` and `capture: environment`,
passed through to the library's file input. The photo is attached like any upload, so
receipt OCR runs as it does today.

### D5. Web push is opt-in and owned by openregister

A setting on `Mijn HR` ("Meldingen op dit apparaat") calls the browser's Push API on a user
gesture and registers the subscription with openregister's `web-push` endpoint. The service
worker only renders what openregister sends and routes clicks to the deeplink. humaniq
declares which notifications go to `web-push` in its notification rules (`platform-notifications`).

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| web manifest and service worker | imperative static assets from humaniq | ADR-108: PWA assets stay in the owning app |
| quick actions | declarative `MijnHr` widgets | existing widget type |
| camera capture | declarative field hint | the library's file input |
| background notifications | declarative `web-push` channel on notification rules | openregister owns delivery |

## Seed data

No schema or seed change.

## Risks / Trade-offs

- [Safari delivers web push only to an installed app] → the setting explains that on iPhone
  the app must be added to the home screen first, the degradation ADR-031 describes.
- [A stale shell after an update] → the cache is keyed by build hash and replaced on the next
  start.

## Open Questions

- Should managers get their approval queue as a fourth quick action when they manage
  anyone, once the dashboard grammar has a visibility condition for widgets?

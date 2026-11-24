---
kind: code
---

# My HR on the phone

## Why

An employee who wants to request a day off, send in a parking receipt or check their
payslip does it at a desk. humaniq ships as a Nextcloud web app and nothing more. Nextcloud's
own mobile apps are built around files and do not open humaniq's pages, and in a phone
browser humaniq is a desktop page squeezed small: no icon on the home screen, no quick way
to the three things people actually do on the go, and a receipt photo has to be taken first
and uploaded later.

Every HR suite in the comparison has a mobile app. For humaniq the fleet already has the
answer: dossiq ships its field inspection as an installable web app, with a web manifest and
a service worker served by the app, and openregister already allows a same-origin service
worker and delivers background web push.

This change makes `Mijn HR` installable on a phone's home screen as a web app, gives it a
phone start page with the everyday actions, lets a receipt be photographed straight into an
expense, and lets notifications reach the phone when the app is closed.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ess-mobile` | Use HR self-service from a mobile app. | `no`: a Nextcloud web app only; no manifest, service worker or phone layout |

### Competitors rated yes

- `ess-mobile`, AFAS Profit: "the AFAS Pocket app is used to book hours and manage absence
  reports" (https://help.afas.nl/help/NL/SE/140636.htm).
- `ess-mobile`, Visma Raet Youforce: "Youforce app for iPhone and Android with leave, claims,
  documents and manager functions" (https://apps.apple.com/nl/app/youforce/id1541134359).
- `ess-mobile`, HR2day: "full app for iOS and Android with the same functionality as the
  desktop" (https://www.hr2day.com/features/complete-app/).
- `ess-mobile`, Loket.nl: "MijnLoket published as a mobile app in the App Store and Google
  Play, fully in line with the web version" (https://loket.nl/roadmap/).
- `ess-mobile`, Personio: "iOS and Android app to manage and request time off and track
  working hours"
  (https://support.personio.de/hc/en-us/articles/22089774701853-Overview-of-the-Personio-mobile-app).
- `ess-mobile`, OrangeHRM: "serves the menu for the OrangeHRM mobile app (punch in and out,
  attendance, apply leave, leave usage)" (orangehrm v5.9
  `src/plugins/orangehrmMobilePlugin/Api/MenuItemAPI.php:56`).

### Recorded follow-ups this change picks up

- `2026-07-12-mijn-hr-self-service` proposal, Non-goals: "No mobile app, no notifications
  (x-openregister-notifications adoption is deliberately deferred app-wide, same stance as
  `loonaangifte-filing-lifecycle`)."
- `2026-07-17-bhv-organisatie` proposal, Non-goals: "no standalone mobile app. None of these
  exist anywhere in hrmq today ... Named as separate future scope, not silently dropped."
- This change ships no native store app. An installable web app on the existing Nextcloud
  session is the fleet's shape for mobile (the dossiq precedent), and a native app would be a
  second client to maintain for one leaf app.

## What Changes

- **Installable.** humaniq serves a web manifest (name, icons, start URL `/apps/humaniq/mijn`,
  scope `/apps/humaniq/`, standalone display) and a service worker, so a phone browser offers
  "add to home screen" and opens `Mijn HR` like an app.
- **The app shell only is cached.** The service worker caches the compiled scripts, styles
  and icons for a fast start. It never caches an API answer, so no payslip, balance or
  personal record is left on the phone.
- **A phone start page.** `MijnHr` gains, at phone width, three large actions at the top:
  request leave, submit an expense, book hours. The existing tiles and lists follow.
- **A receipt from the camera.** The expense form's receipt field asks the phone for the
  camera, so the photo goes straight onto the expense and receipt OCR can read it.
- **Notifications on the phone.** The service worker registers for openregister's `web-push`
  channel after the employee turns it on, so a notification rule declared with that channel
  reaches the installed app with the browser closed. humaniq never asks for permission on
  page load.

## Capabilities

### New Capabilities

- `self-service-mobile`: `Mijn HR` as an installable web app with a cached shell, a phone
  start page, camera capture for receipts and opt-in background notifications.

## Impact

- `lib/Controller/PwaController.php` (new) and `appinfo/routes.php`:
  `GET /manifest.webmanifest`, `GET /service-worker.js`, both `#[PublicPage]` and
  `#[NoCSRFRequired]` as static assets (ADR-108 names PWA assets as a surface that stays in
  the owning app).
- `src/service-worker.js` (new) and the webpack entry that builds it; `img/` gains the home
  screen icons.
- `src/manifest.d/personal-dashboard.json`: the three quick actions on `MijnHr`.
- `src/manifest.d/hr-expense.json`: the receipt field's capture hint.
- `lib/AppInfo/Application.php`: the manifest link in the page head.

## Cross-app dependencies

- **openregister**: the `worker-src 'self'` policy it already adds, and the `web-push` channel
  (subscription endpoint and VAPID keys). humaniq sends no push of its own.
- **nextcloud-vue**: the phone-width layout of `CnDashboardPage`, `CnIndexPage` and the form
  dialog, and camera capture on the file input of the form field. humaniq only passes the
  `capture` hint.

## Out of scope

- A native iOS or Android app, and app store listings.
- Offline use. Without a connection the app says so; nothing personal is stored to show.
- Clock-in with location (`tim-geofence` stays deferred with its own privacy spec).

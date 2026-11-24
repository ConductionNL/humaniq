# self-service-mobile Specification

## Purpose
An employee who wants to request a day off, send in a parking receipt or check their payslip does it at a desk. humaniq ships as a Nextcloud web app and nothing more. Nextcloud's own mobile apps are built around files and do not open humaniq's pages, and in a phone browser humaniq is a desktop page squeezed small: no icon on the home screen, no quick way to the three things people actually do on the go, and a receipt photo has to be taken first and uploaded later.

## Requirements

### Requirement: Mijn HR SHALL be installable on a phone's home screen (REQ-MOB-001)

humaniq SHALL serve a web manifest with start URL `/apps/humaniq/mijn`, scope
`/apps/humaniq/`, standalone display and home screen icons, and a service worker scoped to
`/apps/humaniq/`, so a phone browser offers to install `Mijn HR` and opens it without browser
chrome, on the user's normal Nextcloud session.

Rows: `ess-mobile` (humaniq matrix).

#### Scenario: An employee adds Mijn HR to their home screen

@e2e exclude Installing to a phone's home screen is a browser and OS gesture a headless run cannot make; the web manifest, its start URL, scope and icons and the worker's scope header are verified by PwaControllerTest, the install itself by the live check of task 3.2
- **GIVEN** an employee signed in to Nextcloud in a phone browser on `/apps/humaniq/mijn`
- **WHEN** they choose "add to home screen"
- **THEN** a "Mijn HR" icon appears, and tapping it opens `Mijn HR` full-screen

### Requirement: The app SHALL keep no personal data on the phone (REQ-MOB-002)

The service worker SHALL cache only the app's scripts, styles and icons, and SHALL send every
API request to the network without caching its answer. Without a network the app SHALL say
it is offline.

Rows: `ess-mobile` (humaniq matrix).

#### Scenario: A payslip is not left behind

@e2e exclude Needs the installed app taken offline; the worker's rule that no API answer or page is ever served from cache and that an unreachable page shows the offline notice is verified by selfServiceMobile.test.mjs (handleFetch, classifyRequest)
- **GIVEN** an employee who opened a payslip in the installed app
- **WHEN** the phone is offline and the app is reopened
- **THEN** the app shows it is offline and the payslip is not shown from any cache

### Requirement: The everyday tasks SHALL be one tap from the start page (REQ-MOB-003)

`MijnHr` SHALL show request leave, submit an expense and book hours as the first actions. The
expense receipt field SHALL ask the phone for its camera, and the photo SHALL be attached to
the expense as any upload is.

Rows: `ess-mobile` (humaniq matrix).

#### Scenario: A parking receipt from the car park

@e2e exclude Needs a phone camera; the quick action to submit an expense is manifest configuration checked by check:manifest, the camera capture is the open task 2.2 and is read in the live check of task 3.2
- **GIVEN** an employee in the installed app
- **WHEN** they tap submit an expense and photograph the receipt
- **THEN** the expense is saved with the photo as its receipt, ready for receipt reading

### Requirement: Notifications SHALL reach the installed app only after the employee turns them on (REQ-MOB-004)

The app SHALL register for openregister's `web-push` channel only after the employee turns
notifications on with a tap, and SHALL never ask for permission on page load. The service
worker SHALL show what openregister sends and open its link when tapped.

Rows: `ess-mobile` (humaniq matrix).

#### Scenario: A leave decision arrives with the app closed

@e2e exclude Needs a push service and a closed app on a device; the opt-in that never prompts on load, the subscription sent to openregister's web-push endpoint and its removal are verified by pushOptIn.test.mjs, the rendering and click of a delivered payload by selfServiceMobile.test.mjs (notificationFromPayload); openregister owns delivery
- **GIVEN** an employee who turned on notifications on their phone and a notification rule on
  leave decisions that includes the `web-push` channel
- **WHEN** their manager approves their leave while the app is closed
- **THEN** the phone shows the notification, and tapping it opens the leave request

# self-service-mobile

## ADDED Requirements

### Requirement: Mijn HR SHALL be installable on a phone's home screen (REQ-MOB-001)

humaniq SHALL serve a web manifest with start URL `/apps/humaniq/mijn`, scope
`/apps/humaniq/`, standalone display and home screen icons, and a service worker scoped to
`/apps/humaniq/`, so a phone browser offers to install `Mijn HR` and opens it without browser
chrome, on the user's normal Nextcloud session.

Rows: `ess-mobile` (humaniq matrix).

#### Scenario: An employee adds Mijn HR to their home screen
- **GIVEN** an employee signed in to Nextcloud in a phone browser on `/apps/humaniq/mijn`
- **WHEN** they choose "add to home screen"
- **THEN** a "Mijn HR" icon appears, and tapping it opens `Mijn HR` full-screen

### Requirement: The app SHALL keep no personal data on the phone (REQ-MOB-002)

The service worker SHALL cache only the app's scripts, styles and icons, and SHALL send every
API request to the network without caching its answer. Without a network the app SHALL say
it is offline.

Rows: `ess-mobile` (humaniq matrix).

#### Scenario: A payslip is not left behind
- **GIVEN** an employee who opened a payslip in the installed app
- **WHEN** the phone is offline and the app is reopened
- **THEN** the app shows it is offline and the payslip is not shown from any cache

### Requirement: The everyday tasks SHALL be one tap from the start page (REQ-MOB-003)

`MijnHr` SHALL show request leave, submit an expense and book hours as the first actions. The
expense receipt field SHALL ask the phone for its camera, and the photo SHALL be attached to
the expense as any upload is.

Rows: `ess-mobile` (humaniq matrix).

#### Scenario: A parking receipt from the car park
- **GIVEN** an employee in the installed app
- **WHEN** they tap submit an expense and photograph the receipt
- **THEN** the expense is saved with the photo as its receipt, ready for receipt reading

### Requirement: Notifications SHALL reach the installed app only after the employee turns them on (REQ-MOB-004)

The app SHALL register for openregister's `web-push` channel only after the employee turns
notifications on with a tap, and SHALL never ask for permission on page load. The service
worker SHALL show what openregister sends and open its link when tapped.

Rows: `ess-mobile` (humaniq matrix).

#### Scenario: A leave decision arrives with the app closed
- **GIVEN** an employee who turned on notifications on their phone and a notification rule on
  leave decisions that includes the `web-push` channel
- **WHEN** their manager approves their leave while the app is closed
- **THEN** the phone shows the notification, and tapping it opens the leave request

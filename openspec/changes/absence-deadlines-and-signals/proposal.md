---
kind: code
depends_on: [platform-notifications]
---

# Poortwachter reminders, the 42-week notification, and frequent absence

## Why

humaniq computes every Wet verbetering poortwachter deadline for a sickness case: the problem
analysis in week 6, the action plan in week 8, the UWV notification in week 42 and the
first-year evaluation in week 52. It shows them on `SickLeaveCaseDetail` and checks them in
`occ humaniq:rules:audit`, which flags a milestone within 14 days of its date. Nobody is told.
An HR adviser who does not run the audit or open the case misses week 42, and a late
notification to UWV extends the employer's wage payment by as long as it was late.

When the adviser does remember, the notification itself is made outside humaniq: they copy
the first sick day, the employee's details and the work-resumption percentages from the case
into the UWV form by hand. The schema says so: "Recorded here only: this app does not send
it."

And a manager whose employee calls in sick for the fourth time in a year gets no signal at
all. Two municipal tenders ask for one, with a threshold HR sets.

This change sends reminders before each Poortwachter deadline, produces the 42-week
notification from the case, and signals frequent absence at a threshold per administration.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `abs-gatekeeper-reminders` | Get reminded before a Gatekeeper deadline such as the week 42 notification is due. | `no`: dates are stored and shown; the only alert is an audit rule nobody is sent |
| `abs-uwv-reporting` | Produce the notifications the benefits agency needs for long-term sickness. | `partial`, built: deadline tracking works; the notification is made outside humaniq |
| `td-frequent-absence` | Get a signal when an employee reports sick often, at a threshold you set. | `no`: no frequency signal or threshold |

### Demand

- `td-frequent-absence`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415705
  (Delft Support usability E14, "melding bij frequent verzuim, drempel instelbaar"; also
  Sudwest-Fryslan E8.12).

### Competitors rated yes

- `abs-gatekeeper-reminders`, AFAS Profit: "the standard Poortwachter signals create an
  InSite task for a new sickness case and again after set numbers of days"
  (https://help.afas.nl/help/NL/SE/Hrm_Sick_PW.htm).
- `abs-gatekeeper-reminders`, Visma Raet Youforce: "automated processes and slimme
  herinneringen make everyone take the right steps on time"
  (https://youforce.nl/product/verzuim).
- `abs-gatekeeper-reminders`, HR2day: "tasks and reminders for HR and managers so no step is
  forgotten" (https://www.hr2day.com/features/verzuim/).
- `abs-gatekeeper-reminders`, Loket.nl: "Loket reminds you automatically of important moments
  in the Poortwachter process" (https://loket.nl/functionaliteiten/verzuim/).
- `abs-uwv-reporting`, AFAS Profit: "supports all Digi-ZSM message flows with UWV, including
  Melding langdurig arbeidsongeschikt" (https://help.afas.nl/help/NL/SE/Hrm_Config_Sick_UWV.htm).
- `abs-uwv-reporting`, Visma Raet Youforce: "data exchange with the arbodienst and the UWV
  via SIVI for sick reports" (https://youforce.nl/product/verzuim).
- `abs-uwv-reporting`, HR2day: "direct creation of UWV forms"
  (https://www.hr2day.com/features/verzuim/).
- `td-frequent-absence`: no competitor is rated yes; the row is built on the tender demand.

### Recorded follow-ups and decisions this change follows

- `2026-07-12-leave-verzuim-mvp` proposal, Non-goals: "No UWV wire submission. The 42-weken
  melding is recorded (`uwv42WeekMeldingDone`), not transmitted." This change produces the
  notification's content and still transmits nothing.
- `2026-07-13-onboarding-wizard-mvp` and `2026-07-13-performance-reviews-mvp` defer
  reminders to the app-wide adoption of the notification dialect, which is
  `platform-notifications`.
- `2026-07-13-verzuim-analytics-widgets` named frequency trends a non-goal because absence
  could only be counted in whole days. `lib/Service/AbsenceRateService.php` (lines 9-20)
  records that this reason no longer holds since `absenceProgression`; the non-goal is
  superseded and does not refuse this row.
- No medical data, ever (REQ-VWP-002). Neither the reminders, the notification nor the
  signal carry a diagnosis or cause.

## What Changes

- **Reminders before each deadline.** Four calculated fields on `SickLeaveCase` give the
  days left to each open milestone. Four notification rules in the canonical dialect notify
  the HR group and the employee's manager when a milestone comes within 14 days, and again
  when it is overdue, until its done date is filled.
- **The 42-week notification is generated.** `Generate 42-week notification` on
  `SickLeaveCaseDetail` renders the notification through filinq from the case, the employee
  and the administration: employer and employee identifiers, first sick day, contract hours,
  and the work-resumption steps. It is filed as an `HrGeneratedDocument` on the case. HR
  submits it to UWV and records the date as today.
- **Frequent absence is signalled.** Each administration sets a threshold (for example three
  sickness cases in twelve months). When a new or reopened case brings an employee to the
  threshold, the case is marked, HR and the manager are notified, and `EmployeeDetail` shows
  the count. A relapse within four weeks stays one case, as the law treats it.

## Capabilities

### New Capabilities

- `absence-deadlines-and-signals`: reminders before Poortwachter deadlines, the generated
  42-week notification, and the frequent-absence signal at a set threshold.

## Impact

- `lib/Settings/register.d/hr-verzuim.json`: `SickLeaveCase` 0.5.0 gains four days-left
  calculations, `episodesInWindow`, `frequentAbsence`, and the notification rules.
- `lib/Settings/register.d/hr-administratie.json`: `hrAdministration` gains
  `frequentAbsenceThreshold` and `frequentAbsenceWindowMonths`.
- `lib/Settings/register.d/hr-documents.json`: `documentType` gains
  `uwv-melding-42-weken`, and a `sickLeaveCaseId` reference.
- `lib/Service/FrequentAbsenceService.php`, `lib/Listener/FrequentAbsenceListener.php`,
  `lib/Notification/CaseManagerResolver.php` (new).
- `lib/Service/HrDocumentService.php`: the new document type and its data.
- `lib/Controller/DocumentController.php` and `appinfo/routes.php`:
  `POST /api/sick-leave/{id}/uwv-notification`.
- `src/manifest.d/hr-verzuim.json`, `src/manifest.d/hr-objects.json`: the action, the days
  left, the frequency count.

## Cross-app dependencies

- **openregister**: the canonical notification dialect (`calculatedChange`, `updated` with a
  condition, `groups` and `expression` recipients), specified app-wide in
  `platform-notifications`.
- **filinq**: a template for the 42-week notification in namespace `humaniq`, rendered
  through the same `DocumentService` path the other HR documents use.

## Out of scope

- Sending the notification to UWV (Digi-ZSM or any wire). It stays recorded, not transmitted.
- The arbodienst exchange and the WIA application in week 93.
- A frequent-absence conversation workflow. The signal prompts it; the conversation is HR's.

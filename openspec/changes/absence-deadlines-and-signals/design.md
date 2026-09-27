# Design: Poortwachter reminders, the 42-week notification, and frequent absence

## Context

Read at `development` af702f78.

- `SickLeaveCase` (`lib/Settings/register.d/hr-verzuim.json`, 0.4.0): `employeeId`,
  `firstSickDay`, `recoveredDate`, `status` (`gemeld`, `hersteld`; `herstellen` and
  `heropenen`, a relapse within four weeks reopening the same case), the four
  `...Due`/`...Done` milestone pairs, `absenceProgression`, `currentAbsencePercentage`,
  `loondoorbetalingPercentage`, `administrationId`. `uwv42WeekMeldingDone` says "Recorded
  here only: this app does not send it." It declares no medical field (REQ-VWP-002).
- `lib/Standards/Checks/NlAbsenceChecks.php:73-95`: `ALERT_WINDOW_DAYS = 14` and the four
  milestone pairs; `nl-wvp-milestone-overdue` flags an undone milestone within 14 days or
  past due, in the audit only.
- `appinfo/info.xml` registers `LeaveAccrualJob`, `PollCalendarSubscriptionsJob` and
  `CompleteHoursMigrationJob`; none reads a sickness case. No
  `x-openregister-notifications` rule exists in humaniq.
- `lib/Service/AbsenceRateService.php:9-20` records that `verzuim-analytics-widgets` named
  frequency trends a non-goal "for a reason that was correct at the time", superseded by
  `absenceProgression`. No frequency count exists (`grep -rn "frequen\|sickCount" lib`).
- `lib/Service/HrDocumentService.php` renders HR documents through filinq's
  `DocumentService` into `HrGeneratedDocument` records; its types are
  `arbeidsovereenkomst`, `aanbiedingsbrief`, `werkgeversverklaring`, `getuigschrift` (line 388)
  plus `loonstrook` and `jaaropgaaf`. `DocumentController::generate()` is the guarded
  trigger (`appinfo/routes.php:25`).
- `lib/Service/OrgResolutionService.php:110` `resolveManagerUserIds()` resolves an
  employee's managers from their org placement.
- `lib/Service/InternalWriteMarker.php:77` `runInternal()` marks humaniq's own writes so
  listeners do not loop.
- `SickLeaveCaseDetail` (`src/manifest.d/hr-verzuim.json:4`) shows the case, the eight
  milestone dates, the resumption steps and the files, with `herstellen` and `heropenen`.

## Goals / Non-Goals

**Goals**

- Nobody has to remember a Poortwachter date; the right people are told in time.
- The 42-week notification is produced from data humaniq already holds.
- Frequent absence is visible at a threshold each administration chooses.

**Non-Goals**

- Transmitting anything to UWV or an arbodienst.
- Any medical detail in a reminder, document or signal.

## Decisions

### D1. Reminders are declared, over calculated days-left fields

Four `x-openregister-calculations` fields, `probleemanalyseDaysLeft`,
`planVanAanpakDaysLeft`, `uwv42WeekMeldingDaysLeft`, `eerstejaarsevaluatieDaysLeft`: the
days from today to the due date, null when the milestone is done or the case is `hersteld`.
Four `x-openregister-notifications` rules with trigger `calculatedChange` fire when a field
crosses to 14 or below and again when it crosses below zero. Recipients: the HR group and
`{kind: expression, resolver: CaseManagerResolver}`. The subject names the milestone and the
date and never the cause of absence. The 14 days match `ALERT_WINDOW_DAYS`, so the audit and
the reminder agree.

Alternative considered: a humaniq background job that sends notifications. Rejected by
ADR-031: object-event notifications are declared, never dispatched by hand.

### D2. `CaseManagerResolver` implements OpenRegister's recipient interface

It reads the case's `employeeId` and returns `OrgResolutionService::resolveManagerUserIds()`
for today, or nothing. It holds no other logic.

### D3. The 42-week notification is one more HR document

`HrGeneratedDocument.documentType` gains `uwv-melding-42-weken` and a nullable
`sickLeaveCaseId` (`$ref` `SickLeaveCase`). `HrDocumentService` assembles its data: from
`hrAdministration` the name, `loonheffingennummer` and `kvkNumber`; from `Employee` the
name, `bsn` and `dateOfBirth`; from the case `firstSickDay`, `absenceProgression` and
`currentAbsencePercentage`; from the covering `EmploymentContract` `hoursPerWeek`, `type`
and `endDate`. The template lives in filinq under namespace `humaniq`, as every humaniq
template does. `POST /api/sick-leave/{id}/uwv-notification` is guarded like
`DocumentController::generate()`: resolve first, then admin or HR. It refuses a case in
`hersteld` or one where `uwv42WeekMeldingDone` is already filled.

Alternative considered: a structured Digi-ZSM message. Rejected for this change: the
recorded non-goal keeps transmission out, and a document HR submits through the UWV employer
portal is what the missing half asks for.

### D4. Frequency is counted per employee and stamped on the triggering case

`FrequentAbsenceService::episodesFor(string $employeeId, string $date, int $months): int`
counts the employee's cases whose `firstSickDay` falls in the window; a reopened case counts
once. `FrequentAbsenceListener` runs after a case is created or reopened, and under
`InternalWriteMarker::runInternal()` writes `episodesInWindow` and `frequentAbsence`
(true when the count reaches `hrAdministration.frequentAbsenceThreshold`, default 3, over
`frequentAbsenceWindowMonths`, default 12). A rule with trigger `updated` and condition
`{field: frequentAbsence, operator: equals, value: true, from: false}` notifies the HR group
and the manager once. `EmployeeDetail` shows the latest count.

Alternative considered: an aggregation with a `threshold` trigger. Rejected: a threshold
fires on a schema-wide aggregate, and the signal is per employee.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| days left per milestone | declarative `x-openregister-calculations` | a function of the record and today |
| reminders and the frequency notification | declarative `x-openregister-notifications` | the canonical dialect |
| who the manager is | `expression` resolver | the dialect's seam for computed recipients |
| episode count per employee | imperative service and post-save listener | a cross-record count per employee |
| the 42-week document | imperative, through filinq | document generation, ADR-031 exception |

## Seed data

- One open seeded case whose week-42 date is ten days away and not done, so the reminder and
  the page have content.
- One seeded employee with three cases in the last twelve months, the third marked
  `frequentAbsence`.
- The seeded administration gains `frequentAbsenceThreshold: 3` and
  `frequentAbsenceWindowMonths: 12`.

## Risks / Trade-offs

- [A reminder with a name in it] → the subject carries the employee's name and the milestone
  only, which the AP guidance permits; no cause, no percentage.
- [The engine's calculated fields may not do date arithmetic against today] → task 1.2
  verifies it on a dev instance; if not, the days-left fields become stored and the daily
  `LeaveAccrualJob` pattern refreshes them.

## Open Questions

- Should the manager also be reminded, or only HR, where the employer's policy keeps
  Poortwachter with HR and the casemanager?
